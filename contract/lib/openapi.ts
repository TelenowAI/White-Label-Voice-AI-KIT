/**
 * An OpenAPI 3.0 document as the contract tests use it: load it, find an operation (by method and path template,
 * or by operationId), and hold a body to one of its response schemas.
 *
 * The rules are Telenow's own contract checker's, so the kit is held to
 * the same standard Telenow is:
 *   - OpenAPI 3.0's `nullable: true` becomes a JSON-Schema `null` type (an `anyOf` around a `$ref`);
 *   - every `$ref` resolves against the whole document;
 *   - a body must validate, AND carry no key the schema does not declare — unless the schema says
 *     `additionalProperties` (true or a schema) at that point. A field the document misses is a finding.
 *
 * Not here: sending requests (`tests/`), the Telenow mock (`telenow-mock/`).
 */
import Ajv, { type ErrorObject } from "ajv";
import addFormats from "ajv-formats";
import { readFileSync } from "node:fs";
import { parse } from "yaml";

type Json = null | boolean | number | string | Json[] | { [k: string]: Json };
type Obj = { [k: string]: Json };

export const METHODS = ["get", "put", "post", "delete", "patch", "options", "head", "trace"] as const;

/** OpenAPI 3.0 `nullable` → JSON Schema. Everything else is shared between the two. */
function toJsonSchema(node: Json): Json {
  if (Array.isArray(node)) return node.map(toJsonSchema);
  if (node === null || typeof node !== "object") return node;
  const out: Obj = {};
  for (const [k, v] of Object.entries(node)) out[k] = toJsonSchema(v);
  if (out.nullable === true) {
    delete out.nullable;
    if (typeof out.type === "string" && !("$ref" in out)) {
      out.type = [out.type, "null"];
      if (Array.isArray(out.enum) && !out.enum.includes(null)) out.enum = [...out.enum, null];
      return out;
    }
    return { anyOf: [out, { type: "null" }] };
  }
  if (out.nullable === false) delete out.nullable;
  return out;
}

/** `#/a/b~1c` → the node, or undefined. */
function pointer(doc: Json, ref: string): Json | undefined {
  if (!ref.startsWith("#")) return undefined;
  let node: Json | undefined = doc;
  for (const raw of ref.slice(1).split("/").filter(Boolean)) {
    const key = raw.replace(/~1/g, "/").replace(/~0/g, "~");
    if (node === null || typeof node !== "object" || Array.isArray(node)) return undefined;
    node = (node as Obj)[key];
  }
  return node;
}

/** A JSON pointer segment. */
export const seg = (s: string) => s.replace(/~/g, "~0").replace(/\//g, "~1");

export interface Operation {
  method: string;
  path: string;
  operationId?: string;
  node: Obj;
  pointer: string;
}

export class Spec {
  readonly raw: Obj;
  readonly doc: Obj;
  private readonly ajv: Ajv;
  private readonly id: string;

  constructor(file: string) {
    this.raw = parse(readFileSync(file, "utf8")) as Obj;
    this.doc = toJsonSchema(this.raw) as Obj;
    this.id = `urn:spec:${file}`;
    this.ajv = new Ajv({ strict: false, allErrors: true, validateFormats: true });
    addFormats(this.ajv);
    this.ajv.addSchema({ ...this.doc, $id: this.id });
  }

  /** Every operation in the document. */
  operations(): Operation[] {
    const ops: Operation[] = [];
    for (const [path, item] of Object.entries((this.doc.paths ?? {}) as Obj)) {
      for (const m of METHODS) {
        const node = (item as Obj)?.[m];
        if (node && typeof node === "object") {
          ops.push({
            method: m.toUpperCase(),
            path,
            operationId: (node as Obj).operationId as string | undefined,
            node: node as Obj,
            pointer: `#/paths/${seg(path)}/${m}`,
          });
        }
      }
    }
    return ops;
  }

  operation(method: string, path: string): Operation | undefined {
    return this.operations().find((o) => o.method === method.toUpperCase() && o.path === path);
  }

  byOperationId(id: string): Operation | undefined {
    return this.operations().find((o) => o.operationId === id);
  }

  resolve(node: Json | undefined): Json | undefined {
    for (let i = 0; i < 32 && node && typeof node === "object" && !Array.isArray(node) && "$ref" in node; i++) {
      node = pointer(this.doc, (node as Obj).$ref as string);
    }
    return node;
  }

  /** The pointer of an operation's response schema for `status` and media type, or why there is none. */
  responseSchema(op: Operation, status: number, media = "application/json"): { pointer?: string; error?: string; content: boolean } {
    const responses = (op.node.responses ?? {}) as Obj;
    const key = String(status) in responses ? String(status) : "default" in responses ? "default" : undefined;
    if (!key) return { error: `status ${status} is not documented (documented: ${Object.keys(responses).sort().join(", ")})`, content: false };
    let respPointer = `${op.pointer}/responses/${seg(key)}`;
    let resp = responses[key];
    if (resp && typeof resp === "object" && "$ref" in (resp as Obj)) {
      respPointer = (resp as Obj).$ref as string;
      resp = this.resolve(resp) as Json;
    }
    const content = (resp as Obj | undefined)?.content as Obj | undefined;
    if (!content || Object.keys(content).length === 0) return { content: false };
    if (!(media in content)) return { error: `media ${media} is not documented (documented: ${Object.keys(content).join(", ")})`, content: true };
    if (!(content[media] as Obj)?.schema) return { content: true };
    return { pointer: `${respPointer}/content/${seg(media)}/schema`, content: true };
  }

  /** The media types an operation documents for `status` (none for an undocumented status or a bodiless answer). */
  mediaTypes(op: Operation, status: number): string[] {
    const responses = (op.node.responses ?? {}) as Obj;
    const key = String(status) in responses ? String(status) : "default" in responses ? "default" : undefined;
    if (!key) return [];
    const content = (this.resolve(responses[key]) as Obj | undefined)?.content as Obj | undefined;
    return Object.keys(content ?? {});
  }

  /** Validation errors of `body` against the schema at `ptr` (a pointer into this document). */
  validate(ptr: string, body: unknown): string[] {
    const check = this.ajv.compile({ $ref: `${this.id}${ptr}` });
    if (check(body)) return [];
    return (check.errors ?? []).slice(0, 8).map((e: ErrorObject) => `${e.instancePath || "(root)"} ${e.message ?? ""}`.trim());
  }

  private branches(schema: Json | undefined): Obj[] {
    const s = this.resolve(schema);
    if (!s || typeof s !== "object" || Array.isArray(s)) return [];
    const parts = Array.isArray((s as Obj).allOf) ? ((s as Obj).allOf as Json[]) : [];
    return [s as Obj, ...parts.flatMap((p) => this.branches(p))];
  }

  /** The anyOf/oneOf branch `value` validates against (the first), else the schema itself. */
  private pick(schema: Json | undefined, value: unknown): Json | undefined {
    const s = this.resolve(schema);
    if (!s || typeof s !== "object" || Array.isArray(s)) return s;
    for (const k of ["anyOf", "oneOf"]) {
      const list = (s as Obj)[k];
      if (Array.isArray(list)) {
        for (const b of list) if (this.validates(b, value)) return this.pick(b, value);
      }
    }
    return s;
  }

  /** Whether `value` validates against `schema`, a node of this document (its refs made absolute). */
  private validates(schema: Json, value: unknown): boolean {
    return this.ajv.compile(this.inline(schema) as Obj)(value) as boolean;
  }

  /** `schema` with its refs pointing into this document by absolute id. */
  private inline(schema: Json): Json {
    if (Array.isArray(schema)) return schema.map((x) => this.inline(x));
    if (schema === null || typeof schema !== "object") return schema;
    const out: Obj = {};
    for (const [k, v] of Object.entries(schema)) {
      out[k] = k === "$ref" && typeof v === "string" && v.startsWith("#") ? `${this.id}${v}` : this.inline(v);
    }
    return out;
  }

  /** Keys `value` carries that the schema at `ptr` does not declare, as dotted paths. */
  undeclared(ptr: string, value: unknown): string[] {
    const found = new Set<string>();
    const walk = (schema: Json | undefined, v: unknown, at: string, depth: number) => {
      if (depth > 32) return;
      const s = this.pick(schema, v);
      if (v && typeof v === "object" && !Array.isArray(v)) {
        const branches = this.branches(s);
        const props: Obj = {};
        let open: Json | undefined;
        for (const b of branches) {
          Object.assign(props, (b.properties ?? {}) as Obj);
          const ap = b.additionalProperties;
          if (ap === true || (ap && typeof ap === "object")) open = ap;
        }
        const typed = branches.some((b) => "type" in b);
        if (branches.length === 0 || (Object.keys(props).length === 0 && open === undefined && !typed)) return;
        for (const [k, x] of Object.entries(v as Record<string, unknown>)) {
          if (k in props) walk(props[k], x, `${at}.${k}`, depth + 1);
          else if (open && typeof open === "object") walk(open, x, `${at}.${k}`, depth + 1);
          else if (open === undefined) found.add(`${at}.${k}`);
        }
      } else if (Array.isArray(v)) {
        let items: Json | undefined;
        for (const b of this.branches(s)) items = (b.items as Json) ?? items;
        if (items !== undefined) v.slice(0, 50).forEach((x, i) => walk(items, x, `${at}[${i}]`, depth + 1));
      }
    };
    walk(pointer(this.doc, ptr), value, "(root)", 0);
    return [...found].sort();
  }

  /** The schema an operation's 200-class JSON answer has, resolved: for checking that a field exists. */
  answerSchema(op: Operation): Json | undefined {
    for (const status of ["200", "201"]) {
      const r = this.responseSchema(op, Number(status));
      if (r.pointer) return pointer(this.doc, r.pointer);
    }
    return undefined;
  }

  /** Every object branch `schema` may take: allOf parts merged, and each anyOf/oneOf alternative (a nullable
   *  object is an anyOf of the object and null). */
  private alternatives(schema: Json | undefined): Obj[] {
    const out: Obj[] = [];
    for (const b of this.branches(schema)) {
      out.push(b);
      for (const k of ["anyOf", "oneOf"]) {
        const list = b[k];
        if (Array.isArray(list)) for (const alt of list) out.push(...this.alternatives(alt));
      }
    }
    return out;
  }

  /** Whether `dotted` (a.b.c) names a declared property path under `schema` (allOf merged, anyOf/oneOf
   *  alternatives followed, refs resolved). */
  hasField(schema: Json | undefined, dotted: string): boolean {
    return this.fieldSchema(schema, dotted) !== undefined;
  }

  /** The schema of the property `dotted` (a.b.c) names under `schema`, resolved the same way; undefined when
   *  it is not declared. */
  fieldSchema(schema: Json | undefined, dotted: string): Json | undefined {
    let current: Json | undefined = schema;
    for (const part of dotted.split(".")) {
      const props: Obj = {};
      for (const b of this.alternatives(current)) Object.assign(props, (b.properties ?? {}) as Obj);
      if (!(part in props)) return undefined;
      current = props[part];
    }
    return this.resolve(current);
  }
}
