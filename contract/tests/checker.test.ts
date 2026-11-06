/**
 * The contract checker itself (lib/openapi.ts) must catch what it is for before anything is held to it: a wrong
 * type, a missing required field, a null where none is allowed, an undeclared key (also inside an array item and
 * under a nullable allOf), and an undocumented status — and must let through a nullable null, an open map and a
 * documented answer.
 */
import { mkdtempSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { Spec } from "../lib/openapi";

const DOC = `
openapi: 3.0.3
info: { title: t, version: '0' }
paths:
  /things/{id}:
    get:
      operationId: getThing
      responses:
        '200':
          description: OK
          content: { application/json: { schema: { $ref: '#/components/schemas/Thing' } } }
        '404': { description: gone }
components:
  schemas:
    Thing:
      type: object
      required: [id, name, note]
      properties:
        id: { type: string, format: uuid }
        name: { type: string }
        note: { type: string, nullable: true }
        meta: { type: object, additionalProperties: true }
        tags: { type: array, items: { $ref: '#/components/schemas/Tag' } }
        ref: { allOf: [{ $ref: '#/components/schemas/Tag' }], nullable: true }
    Tag:
      type: object
      required: [label]
      properties: { label: { type: string } }
`;

const dir = mkdtempSync(join(tmpdir(), "voice-kit-checker-"));
writeFileSync(join(dir, "doc.yaml"), DOC);
const spec = new Spec(join(dir, "doc.yaml"));
const op = spec.operation("GET", "/things/{id}")!;
const ptr = spec.responseSchema(op, 200).pointer!;
const good = { id: "6f1c2a1e-1111-4a4a-9b9b-222222222222", name: "n", note: null, meta: { any: [1] }, tags: [{ label: "x" }], ref: null };

describe("the contract checker", () => {
  it("passes a good answer", () => {
    expect(spec.validate(ptr, good)).toEqual([]);
    expect(spec.undeclared(ptr, good)).toEqual([]);
  });

  it.each([
    ["a missing required field", { ...good, name: undefined }],
    ["a wrong type", { ...good, name: 3 }],
    ["null where it is not nullable", { ...good, name: null }],
    ["a bad format", { ...good, id: "not-a-uuid" }],
    ["a wrong item type", { ...good, tags: [{ label: 3 }] }],
  ])("fails %s", (_name, body) => {
    expect(spec.validate(ptr, JSON.parse(JSON.stringify(body)))).not.toEqual([]);
  });

  it.each([
    ["at the top", { ...good, surprise: 1 }, "(root).surprise"],
    ["in an array item", { ...good, tags: [{ label: "x", oops: 2 }] }, "(root).tags[0].oops"],
    ["under a nullable allOf", { ...good, ref: { label: "y", z: 1 } }, "(root).ref.z"],
  ])("names an undeclared key %s", (_name, body, path) => {
    expect(spec.undeclared(ptr, body)).toEqual([path]);
  });

  it("lets keys through under additionalProperties: true", () => {
    expect(spec.undeclared(ptr, { ...good, meta: { deep: { x: 1 } } })).toEqual([]);
  });

  it("names an undocumented status, and a documented one without a body", () => {
    expect(spec.responseSchema(op, 500).error).toMatch(/status 500 is not documented/);
    expect(spec.responseSchema(op, 404)).toEqual({ content: false });
  });

  it("finds declared field paths and not others", () => {
    const schema = spec.answerSchema(op);
    expect(spec.hasField(schema, "name")).toBe(true);
    expect(spec.hasField(schema, "ref.label")).toBe(true);
    expect(spec.hasField(schema, "nope")).toBe(false);
  });
});
