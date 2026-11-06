/**
 * The pricing spec (pricing/README.md) and its reference engine (pricing/engine.ts): every price list and fixture
 * is in its own format, every fixture's recorded inputs exist, and the engine answers every fixture exactly — the
 * same lines in the same order, to the last decimal, or the same refusal. The fixtures' expected answers were
 * computed by an implementation of the spec apart from this engine; every stack's engine is held to them.
 * Also the exact arithmetic under it (lib/rational.ts).
 */
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import Ajv from "ajv";
import { describe, expect, it } from "vitest";
import { Rational, formatScaled, type Rounding } from "../lib/rational";
import { callItem, feeItem, price, PricingRefused, type Item, type PriceList } from "../pricing/engine";

const ROOT = join(__dirname, "..");
const PRICING = join(ROOT, "pricing");
const read = (path: string) => JSON.parse(readFileSync(path, "utf8"));
const jsonFiles = (dir: string) => readdirSync(dir).filter((f) => f.endsWith(".json")).sort();

const ajv = new Ajv({ allErrors: true, strict: false });
const listSchema = ajv.compile(read(join(PRICING, "price-list.schema.json")));
const fixtureSchema = ajv.compile(read(join(PRICING, "fixture.schema.json")));

const shared = jsonFiles(join(PRICING, "price-lists")).map((f) => [f, read(join(PRICING, "price-lists", f)) as PriceList] as const);
const fixtures = jsonFiles(join(PRICING, "fixtures")).map((f) => [f, read(join(PRICING, "fixtures", f))] as const);
const cassette = (name: string) => read(join(ROOT, "telenow-mock", "cassettes", `${name}.json`)).response.body;

/** A fixture's item, from its recorded Telenow answers or as written. */
function itemOf(spec: Record<string, any>): Item {
  if (spec.call) return callItem(cassette(spec.call.cost).data, cassette(spec.call.call));
  if (spec.fee) {
    const entries = cassette(spec.fee.ledger).data.entries.filter((e: any) => e.refKind === spec.fee.refKind);
    expect(entries, `one ${spec.fee.refKind} entry in ${spec.fee.ledger}`).toHaveLength(1);
    return feeItem(entries[0]);
  }
  if (spec.ledgerEntry) return feeItem(spec.ledgerEntry);
  return spec.inline as Item;
}

function answer(fixture: Record<string, any>): unknown {
  try {
    const item = itemOf(fixture.item);
    return price([...shared.map(([, l]) => l), ...(fixture.priceLists ?? [])], fixture.plan, fixture.usdRate, item);
  } catch (e) {
    if (e instanceof PricingRefused) return { error: e.code, subject: e.subject };
    throw e;
  }
}

describe("the price lists", () => {
  it.each(shared)("%s is a price list named after its file", (file, list) => {
    expect(listSchema(list) ? [] : listSchema.errors).toEqual([]);
    expect(`${list.id}.json`).toBe(file);
  });

  it("refuses a price a rule's item cannot take", () => {
    const rule = (item: string, price: object) => ({ id: "x", currency: "INR", decimals: 2, rounding: "half-up", billingIncrementSeconds: 60, rules: [{ id: "r", match: { item }, price }] });
    expect(listSchema(rule("fee", { perMinute: "1" }))).toBe(false);
    expect(listSchema(rule("component", { fixed: "1" }))).toBe(false);
    expect(listSchema(rule("call", { markupPercent: "-100.5" }))).toBe(false);
    expect(listSchema(rule("call", { markupPercent: "-100" }))).toBe(true);
  });
});

describe("the price-list format cases (list-cases.json, which every stack's own check must agree with)", () => {
  const { cases } = read(join(PRICING, "list-cases.json")) as { cases: Array<{ name: string; list: unknown; valid: boolean }> };

  it("hold both valid and invalid lists", () => {
    expect(new Set(cases.map((c) => c.valid))).toEqual(new Set([true, false]));
  });

  it.each(cases.map((c) => [c.name, c] as const))("%s: the schema agrees", (_name, c) => {
    expect(listSchema(c.list), JSON.stringify(listSchema.errors)).toBe(c.valid);
  });
});

describe("the fixtures", () => {
  it("exist", () => expect(fixtures.length).toBeGreaterThan(0));

  it.each(fixtures)("%s is a fixture with valid lists of its own", (_file, fixture) => {
    expect(fixtureSchema(fixture) ? [] : fixtureSchema.errors).toEqual([]);
    for (const list of fixture.priceLists ?? []) {
      expect(listSchema(list) ? [] : listSchema.errors, list.id).toEqual([]);
      expect(shared.map(([, l]) => l.id), `${list.id} repeats a shared list`).not.toContain(list.id);
    }
  });

  it.each(fixtures)("%s is answered exactly", (_file, fixture) => {
    expect(answer(fixture)).toEqual(fixture.expect);
  });

  it("reach every refusal and every price method", () => {
    const seen = new Set(fixtures.flatMap(([, f]) => (f.expect.error ? [f.expect.error] : f.expect.lines.map((l: any) => l.method))));
    const codes = read(join(PRICING, "fixture.schema.json")).properties.expect.oneOf[1].properties.error.enum;
    expect([...seen].sort()).toEqual([...codes, "fixed", "markupPercent", "perMinute"].sort());
  });
});

describe("exact arithmetic", () => {
  const r = (s: string) => Rational.parse(s);

  it("reads only plain decimal strings", () => {
    for (const bad of ["1e3", "0.1.2", ".5", "5.", "+1", " 1", "01", "NaN", ""]) expect(() => r(bad), bad).toThrow();
    expect(r("-0.0041250000").toShortDecimal()).toBe("-0.004125");
  });

  it("is exact where floats are not", () => {
    expect(r("0.1").plus(r("0.2")).toShortDecimal()).toBe("0.3");
    expect(r("95").times(r("3.50")).div(r("60")).round(2, "half-up")).toBe(554n);
  });

  it.each<[string, Rounding, string]>([
    ["0.125", "half-up", "0.13"], ["0.125", "half-even", "0.12"], ["0.135", "half-even", "0.14"],
    ["-0.125", "half-up", "-0.13"], ["-0.125", "half-even", "-0.12"], ["0.121", "up", "0.13"], ["-0.121", "up", "-0.13"],
    ["0.129", "down", "0.12"], ["-0.129", "down", "-0.12"], ["0.004", "half-up", "0.00"], ["-0.004", "half-up", "0.00"],
  ])("rounds %s by %s to %s", (value, mode, want) => {
    expect(formatScaled(r(value).round(2, mode), 2)).toBe(want);
  });

  it("writes a whole number with no point", () => {
    expect(formatScaled(14n, 0)).toBe("14");
    expect(formatScaled(5n, 3)).toBe("0.005");
  });
});
