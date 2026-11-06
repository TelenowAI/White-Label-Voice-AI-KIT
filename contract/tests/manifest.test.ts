/**
 * The resource manifest (resources.yaml) says only what its format allows (resources.schema.json), and names only
 * what Telenow's released API document (telenow/openapi.yaml) has: every operation, every field (where the
 * resource's object sits in that operation's answer), every merged blob, every event, every back-fill feed. A
 * manifest entry naming something Telenow lacks fails here, before any backend runs it.
 */
import Ajv from "ajv";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { parse } from "yaml";
import { Spec } from "../lib/openapi";
import { manifestJson, manifestJsonPath } from "../scripts/manifest-json";

const ROOT = join(__dirname, "..");
const manifest = parse(readFileSync(join(ROOT, "resources.yaml"), "utf8"));
const formatSchema = JSON.parse(readFileSync(join(ROOT, "resources.schema.json"), "utf8"));
const telenow = new Spec(join(ROOT, "telenow", "openapi.yaml"));

/** The events Telenow publishes: the values its event-sample route accepts. */
function telenowEvents(): string[] {
  const op = telenow.byOperationId("sampleEvent");
  const param = ((op?.node.parameters ?? []) as Array<Record<string, any>>).find((p) => p.name === "type");
  return (param?.schema?.enum ?? []) as string[];
}

/** The schema of the resource's object inside an operation's 2xx answer. */
function objectSchema(operationId: string, objectAt?: string) {
  const op = telenow.byOperationId(operationId);
  if (!op) return undefined;
  let schema = telenow.answerSchema(op);
  for (const part of (objectAt ?? "").split(".").filter(Boolean)) {
    if (!telenow.hasField(schema, part)) return undefined;
    schema = fieldSchema(schema, part);
    // A list (Telenow's DNC has no read of one entry, only its list): the object is one of its items.
    const resolved = telenow.resolve(schema) as Record<string, any> | undefined;
    if (resolved?.type === "array") schema = resolved.items;
  }
  return schema;
}

/** The schema of one property under `schema` (allOf merged, alternatives followed). */
function fieldSchema(schema: any, name: string): any {
  const seen: any[] = [];
  const visit = (s: any) => {
    s = telenow.resolve(s);
    if (!s || typeof s !== "object") return;
    if (s.properties?.[name]) seen.push(s.properties[name]);
    for (const k of ["allOf", "anyOf", "oneOf"]) for (const x of s[k] ?? []) visit(x);
  };
  visit(schema);
  return seen[0];
}

const resources = Object.entries(manifest.resources ?? {}) as Array<[string, any]>;

describe("the resource manifest", () => {
  it("has at least one resource, in its own format", () => {
    const ajv = new Ajv({ strict: true, allErrors: true });
    const check = ajv.compile(formatSchema);
    const ok = check(manifest);
    expect(check.errors ?? []).toEqual([]);
    expect(ok).toBe(true);
    expect(resources.length).toBeGreaterThan(0);
  });

  it("reads Telenow's event list from the released document", () => {
    expect(telenowEvents().length).toBeGreaterThan(0);
  });

  describe.each(resources)("%s", (_name, r) => {
    const ops = Object.entries(r.telenow) as Array<[string, any]>;

    it.each(ops)("its %s operation is one Telenow has", (_kind, op) => {
      expect(telenow.byOperationId(op.operationId), op.operationId).toBeDefined();
    });

    it("finds its object in every operation that answers with one", () => {
      for (const [kind, op] of ops) {
        if (kind === "delete") continue;
        expect(objectSchema(op.operationId, op.objectAt), `${kind} ${op.operationId} at '${op.objectAt ?? ""}'`).toBeDefined();
      }
    });

    it("maps only fields Telenow's read answer has", () => {
      const schema = objectSchema(r.telenow.read.operationId, r.telenow.read.objectAt);
      const missing = r.fields.filter((f: any) => !telenow.hasField(schema, f.telenow)).map((f: any) => f.telenow);
      expect(missing).toEqual([]);
    });

    it("merges only blobs Telenow's answer has", () => {
      const schema = objectSchema(r.telenow.read.operationId, r.telenow.read.objectAt);
      const blobs: string[] = r.telenow.update?.mergeBlobs ?? [];
      expect(blobs.filter((b) => !telenow.hasField(schema, b))).toEqual([]);
    });

    it("never stores fields under a top-level Telenow field that exists", () => {
      const schema = objectSchema(r.telenow.read.operationId, r.telenow.read.objectAt);
      const tops: string[] = r.neverStored.map((p: string) => p.split(".")[0]);
      expect(tops.filter((t) => !telenow.hasField(schema, t))).toEqual([]);
    });

    it("listens only to events Telenow publishes", () => {
      const known = new Set(telenowEvents());
      expect((r.events as string[]).filter((e) => !known.has(e))).toEqual([]);
    });

    it("back-fills from an operation Telenow has, with the parameters it names", () => {
      expect(telenow.byOperationId(r.backfill.operationId), r.backfill.operationId).toBeDefined();
      const params = ((telenow.byOperationId(r.backfill.operationId)?.node.parameters ?? []) as Array<Record<string, any>>)
        .map((p) => (telenow.resolve(p) as Record<string, any>)?.name);
      for (const name of [r.backfill.startParameter, r.backfill.cursorParameter].filter(Boolean)) expect(params, name).toContain(name);
    });

    it("reads each extra part from an operation Telenow has, while the read answer says it is present", () => {
      const read = objectSchema(r.telenow.read.operationId, r.telenow.read.objectAt);
      const completeness = telenow.resolve(read && telenow.fieldSchema(read, "completeness")) as Record<string, any> | undefined;
      for (const x of r.extraReads ?? []) {
        expect(objectSchema(x.operationId, x.objectAt), `${x.operationId} at '${x.objectAt ?? ""}'`).toBeDefined();
        expect(Object.keys(completeness?.properties ?? {}), x.whenPresent).toContain(x.whenPresent);
      }
    });

    it("writes only fields Telenow's write request names, or takes beside the ones it names", () => {
      const refused: string[] = [];
      for (const kind of ["create", "update"] as const) {
        const op = r.telenow[kind];
        if (!op) continue;
        const request = requestSchema(op.operationId);
        const names = [
          ...r.fields.filter((f: any) => f.write && (!f.on || f.on.includes(kind))).map((f: any) => f.write),
          ...Object.keys(op.body ?? {}),
        ];
        for (const name of names) {
          const declared = name in (request?.properties ?? {});
          if (!declared && request?.additionalProperties === false) refused.push(`${kind}.${name}`);
        }
      }
      expect(refused).toEqual([]);
    });
  });
});

describe("the manifest's JSON copy (for stacks with no YAML reader)", () => {
  it("is resources.yaml as it is now (run `pnpm --filter contract manifest` after changing it)", () => {
    expect(readFileSync(manifestJsonPath, "utf8")).toBe(manifestJson());
  });
});

/** The JSON schema of an operation's request body, resolved. */
function requestSchema(operationId: string): Record<string, any> | undefined {
  const op = telenow.byOperationId(operationId);
  const body = telenow.resolve(op?.node.requestBody) as Record<string, any> | undefined;
  return telenow.resolve(body?.content?.["application/json"]?.schema) as Record<string, any> | undefined;
}
