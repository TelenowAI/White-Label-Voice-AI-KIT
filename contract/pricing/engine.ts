/**
 * The reference pricing engine: README.md's steps, word for word. What a customer pays for a call or a number fee
 * is Telenow's charge run through the operator's price list (SELF_HOSTED_KIT_PLAN.md §4.6). Every stack implements
 * the same steps and must reproduce fixtures/*.json exactly; tests/pricing.test.ts holds this one to them. The Node
 * stack (K3) and the console's price previews can use it as it is.
 *
 * - callItem / feeItem: Telenow's answers (CallCharge + the call, a LedgerEntry) → the engine's item.
 * - price: an item, a plan's price list and the rate → the lines and total, or a PricingRefused.
 *
 * Exact throughout (lib/rational.ts): money is a decimal string in and out, and rounding happens once per line.
 * Not here: where price lists are stored, the rate source, writing the wallet entry (each stack's own).
 */
import { formatScaled, Rational, type Rounding } from "../lib/rational";

export type MatchValue = string | string[];

export interface PriceRule {
  id: string;
  match: { item: "call" | "component" | "fee" } & Record<string, MatchValue>;
  price: { perMinute: string } | { markupPercent: string } | { fixed: string };
}

export interface PriceList {
  id: string;
  extends?: string;
  currency: string;
  decimals: number;
  rounding: Rounding;
  billingIncrementSeconds: number;
  rules: PriceRule[];
}

type Attributes = Record<string, string | null>;

export interface CallItem {
  kind: "call";
  attributes: Attributes;
  durationSec?: number;
  components: Record<string, string>;
  totalUsd: string;
}

export interface FeeItem {
  kind: "fee";
  attributes: Attributes;
  costUsd: string;
}

export type Item = CallItem | FeeItem;

export interface PriceLine {
  subject: string;
  rule: string;
  method: "perMinute" | "markupPercent" | "fixed";
  costUsd: string;
  billedSeconds: number | null;
  amount: string;
}

export interface PriceAnswer {
  currency: string;
  usdRate: string;
  lines: PriceLine[];
  total: string;
}

export type RefusalCode =
  | "not_rated"
  | "not_a_charge"
  | "unknown_price_list"
  | "price_list_cycle"
  | "currency_mismatch"
  | "unpriced_component"
  | "components_exceed_total"
  | "unpriced_fee";

export class PricingRefused extends Error {
  constructor(
    readonly code: RefusalCode,
    readonly subject: string,
  ) {
    super(`${code}: ${subject}`);
  }
}

/** A call from Telenow's answers: its charge (`CallCharge`, null until rated) and the call (`CallV1`). */
export function callItem(
  charge: Record<string, unknown> | null,
  call: { direction: string | null; channel: string | null; duration_sec: number | null },
): CallItem {
  if (charge === null) throw new PricingRefused("not_rated", "call");
  const components: Record<string, string> = {};
  for (const [key, value] of Object.entries(charge)) {
    if (key.endsWith("Usd") && key !== "totalChargeUsd") components[key.slice(0, -"Usd".length)] = String(value);
  }
  return {
    kind: "call",
    attributes: { callType: charge.callType as string, direction: call.direction, channel: call.channel },
    durationSec: call.duration_sec ?? 0,
    components,
    totalUsd: String(charge.totalChargeUsd),
  };
}

/** A fee from a Telenow `LedgerEntry`: a charge's cost is its amount negated. */
export function feeItem(entry: { entryType: string; amountUsd: string; refKind: string | null }): FeeItem {
  if (entry.entryType !== "charge") throw new PricingRefused("not_a_charge", "fee");
  return { kind: "fee", attributes: { refKind: entry.refKind }, costUsd: Rational.parse(entry.amountUsd).times(Rational.of(-1n)).toShortDecimal() };
}

/** Step 1: the plan's list, then each list it extends. */
function chain(lists: Map<string, PriceList>, plan: string): PriceList[] {
  const out: PriceList[] = [];
  const seen = new Set<string>();
  for (let id: string | undefined = plan; id !== undefined; id = lists.get(id)?.extends) {
    const list = lists.get(id);
    if (!list) throw new PricingRefused("unknown_price_list", id);
    if (seen.has(id)) throw new PricingRefused("price_list_cycle", id);
    seen.add(id);
    if (out.length && list.currency !== out[0].currency) throw new PricingRefused("currency_mismatch", id);
    out.push(list);
  }
  return out;
}

function matches(rule: PriceRule, item: PriceRule["match"]["item"], attributes: Attributes): boolean {
  if (rule.match.item !== item) return false;
  return Object.entries(rule.match).every(([key, want]) => {
    if (key === "item") return true;
    const have = attributes[key];
    if (have === null || have === undefined) return false;
    return Array.isArray(want) ? want.includes(have) : have === want;
  });
}

const HUNDRED = Rational.of(100n);
const SIXTY = Rational.of(60n);

export function price(lists: PriceList[], plan: string, usdRate: string, item: Item): PriceAnswer {
  const byId = new Map(lists.map((l) => [l.id, l] as const));
  const ch = chain(byId, plan);
  const head = ch[0];
  const rules = ch.flatMap((l) => l.rules.map((rule) => ({ list: l.id, rule })));
  const rate = Rational.parse(usdRate);
  const first = (kind: PriceRule["match"]["item"], attributes: Attributes) => rules.find((r) => matches(r.rule, kind, attributes));

  const lines: { line: Omit<PriceLine, "amount">; amount: bigint }[] = [];
  const add = (subject: string, hit: { list: string; rule: PriceRule }, cost: Rational) => {
    const p = hit.rule.price;
    let amount: Rational;
    let billedSeconds: number | null = null;
    let method: PriceLine["method"];
    if ("perMinute" in p) {
      const inc = head.billingIncrementSeconds;
      const duration = item.kind === "call" ? (item.durationSec ?? 0) : 0;
      billedSeconds = Math.ceil(duration / inc) * inc;
      amount = Rational.parse(p.perMinute).times(Rational.of(BigInt(billedSeconds))).div(SIXTY);
      method = "perMinute";
    } else if ("markupPercent" in p) {
      amount = cost.times(HUNDRED.plus(Rational.parse(p.markupPercent))).div(HUNDRED).times(rate);
      method = "markupPercent";
    } else {
      amount = Rational.parse(p.fixed);
      method = "fixed";
    }
    lines.push({
      line: { subject, rule: `${hit.list}/${hit.rule.id}`, method, costUsd: cost.toShortDecimal(), billedSeconds },
      amount: amount.round(head.decimals, head.rounding),
    });
  };

  if (item.kind === "call") {
    const total = Rational.parse(item.totalUsd);
    const whole = first("call", item.attributes);
    if (whole) {
      add("call", whole, total);
    } else {
      const parts = new Map(Object.entries(item.components).map(([k, v]) => [k, Rational.parse(v)] as const));
      const rest = [...parts.values()].reduce((left, v) => left.minus(v), total);
      if (rest.sign() < 0) throw new PricingRefused("components_exceed_total", "call");
      if (rest.sign() > 0) parts.set("unlisted", rest);
      for (const name of [...parts.keys()].sort()) {
        const cost = parts.get(name)!;
        if (cost.sign() === 0) continue;
        const hit = first("component", { ...item.attributes, component: name });
        if (!hit) throw new PricingRefused("unpriced_component", `component:${name}`);
        add(`component:${name}`, hit, cost);
      }
    }
  } else {
    const refKind = item.attributes.refKind ?? "";
    const hit = first("fee", item.attributes);
    if (!hit) throw new PricingRefused("unpriced_fee", `fee:${refKind}`);
    add(`fee:${refKind}`, hit, Rational.parse(item.costUsd));
  }

  return {
    currency: head.currency,
    usdRate: rate.toShortDecimal(),
    lines: lines.map(({ line, amount }) => ({ ...line, amount: formatScaled(amount, head.decimals) })),
    total: formatScaled(lines.reduce((sum, l) => sum + l.amount, 0n), head.decimals),
  };
}
