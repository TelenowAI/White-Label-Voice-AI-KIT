/**
 * The recorded Telenow (telenow-mock/) answers only what Telenow promises: every recording is an operation of
 * the released document, recorded at a path its template matches, with a documented status, a valid body and no
 * undeclared field, and no two recordings answer the same request. And the mock serves them as Telenow would: the
 * recording made with the request's key, 401 with no key, 404 (never a made-up answer) for anything not recorded.
 */
import type { AddressInfo } from "node:net";
import { join } from "node:path";
import { afterAll, beforeAll, describe, expect, it } from "vitest";
import { Spec } from "../lib/openapi";
import { createMock, loadCassettes, matchOperation } from "../telenow-mock/server";

const ROOT = join(__dirname, "..");
const telenow = new Spec(join(ROOT, "telenow", "openapi.yaml"));
const cassettes = loadCassettes(join(ROOT, "telenow-mock", "cassettes"));

describe("the Telenow recordings", () => {
  it("exist", () => expect(cassettes.length).toBeGreaterThan(0));

  it("never answer the same request twice", () => {
    const seen = cassettes.filter((c) => c.serve !== false)
      .map((c) => `${c.request.method} ${c.request.path} ${c.request.apiKey} ${c.request.idempotencyKey ?? "*"} ${(c.request.queryNames ?? []).join(",")}`);
    expect(seen.filter((s, i) => seen.indexOf(s) !== i)).toEqual([]);
  });

  it.each(cassettes.map((c, i) => [`${c.operationId} (${c.request.apiKey}${c.serve === false ? `, unserved #${i}` : ""})`, c] as const))("%s is what the released document promises", (_id, c) => {
    expect(c.request.apiKey, "the placeholder key it answers").toMatch(/^vai_test_[a-z_]+$/);
    const op = telenow.byOperationId(c.operationId);
    expect(op, "operation in the released document").toBeDefined();
    expect(matchOperation(telenow, c.request.method, c.request.path)?.operationId, "recorded path matches the template").toBe(c.operationId);
    if (c.response.bodyBase64 !== undefined) {
      // A binary answer: its type is one the document gives for this status (`audio/*` covers `audio/wav`).
      const documented = telenow.mediaTypes(op!, c.response.status);
      expect(documented.some((m) => m === c.response.contentType || (m.endsWith("/*") && c.response.contentType!.startsWith(m.slice(0, -1)))),
        `${c.response.contentType} in ${documented}`).toBe(true);
      expect(Buffer.from(c.response.bodyBase64, "base64").length).toBeGreaterThan(0);
      return;
    }
    const schema = telenow.responseSchema(op!, c.response.status);
    expect(schema.error).toBeUndefined();
    expect(telenow.validate(schema.pointer!, c.response.body)).toEqual([]);
    expect(telenow.undeclared(schema.pointer!, c.response.body)).toEqual([]);
  });
});

describe("the recorded Telenow", () => {
  let base = "";
  const server = createMock(telenow, cassettes);
  beforeAll(() => new Promise<void>((done) => server.listen(0, "127.0.0.1", () => {
    base = `http://127.0.0.1:${(server.address() as AddressInfo).port}`;
    done();
  })));
  afterAll(() => new Promise<void>((done) => server.close(() => done())));

  it.each(cassettes.filter((c) => c.serve !== false).map((c) => [`${c.operationId} (${c.request.apiKey}${c.request.idempotencyKey ? `, ${c.request.idempotencyKey}` : ""})`, c] as const))("answers %s with its recording", async (_id, c) => {
    const headers: Record<string, string> = { "x-api-key": c.request.apiKey };
    if (c.request.idempotencyKey) headers["idempotency-key"] = c.request.idempotencyKey;
    const r = await fetch(`${base}${c.request.path}${c.request.query ? `?${c.request.query}` : ""}`, { method: c.request.method, headers });
    expect(r.status).toBe(c.response.status);
    if (c.response.bodyBase64 !== undefined) {
      expect(r.headers.get("content-type")).toBe(c.response.contentType);
      expect(Buffer.from(await r.arrayBuffer()).equals(Buffer.from(c.response.bodyBase64, "base64"))).toBe(true);
    } else {
      expect(await r.json()).toEqual(c.response.body);
    }
  });

  it("answers a feed page by the parameters it names, whatever their values", async () => {
    const feed = (q: string) => fetch(`${base}/api/orgs/3e1cd17a-d25e-40c4-b0b0-0694f052f6da/calls?${q}`, { headers: { "x-api-key": "vai_test_customer_acme" } })
      .then((r) => r.json() as Promise<{ data: { calls: unknown[] } }>);
    expect((await feed("updatedAfter=2030-01-01T00:00:00Z")).data.calls.length).toBeGreaterThan(0);
    expect((await feed("cursor=anything")).data.calls).toEqual([]);
  });

  it("answers one operation differently per key, as Telenow does", async () => {
    const roles = new Set<string>();
    for (const c of cassettes.filter((x) => x.operationId === "whoAmI" && x.response.status === 200)) {
      const r = await fetch(`${base}/api/v1/me`, { headers: { "x-api-key": c.request.apiKey } });
      roles.add(`${(await r.json()).key_role}`);
    }
    expect(roles.size).toBeGreaterThan(1);
  });

  it("answers a write with another Idempotency-Key from the recording that names none", async () => {
    const r = await fetch(`${base}/api/v1/sub-accounts`, { method: "POST", headers: { "x-api-key": "vai_test_operator_admin", "idempotency-key": "any-other" } });
    expect(r.status).toBe(201);
    const named = await fetch(`${base}/api/v1/sub-accounts`, { method: "POST", headers: { "x-api-key": "vai_test_operator_admin", "idempotency-key": "external-id-taken" } });
    expect(named.status).toBe(409);
  });

  it("never serves a recording marked serve: false", async () => {
    // A mock holding only unserved recordings: each of their requests must find nothing.
    const unserved = cassettes.filter((c) => c.serve === false);
    expect(unserved.length).toBeGreaterThan(0);
    const only = createMock(telenow, unserved);
    await new Promise<void>((done) => only.listen(0, "127.0.0.1", () => done()));
    try {
      const port = (only.address() as AddressInfo).port;
      for (const c of unserved) {
        const r = await fetch(`http://127.0.0.1:${port}${c.request.path}`, { method: c.request.method, headers: { "x-api-key": c.request.apiKey } });
        expect(r.status, `${c.operationId} ${c.request.path}`).toBe(404);
      }
    } finally {
      await new Promise<void>((done) => only.close(() => done()));
    }
  });

  it("answers 404, never another key's recording, for a key it has no recording of", async () => {
    const r = await fetch(`${base}/api/v1/me`, { headers: { "x-api-key": "vai_test_never_recorded" } });
    expect(r.status).toBe(404);
    expect((await r.json()).error).toMatch(/no recording for whoAmI with this key/);
  });

  it("refuses a call with no key, as Telenow does", async () => {
    const r = await fetch(`${base}${cassettes[0].request.path}`);
    expect(r.status).toBe(401);
  });

  it("answers 404, never a made-up answer, for an operation it has no recording of", async () => {
    const r = await fetch(`${base}/api/agents/00000000-0000-4000-8000-000000000000`, { headers: { "x-api-key": "vai_test_viewer" } });
    expect(r.status).toBe(404);
    expect((await r.json()).error).toMatch(/no recording for getAgent/);
  });

  it("answers 404 for a path Telenow does not have", async () => {
    const r = await fetch(`${base}/api/no-such-thing`, { headers: { "x-api-key": "vai_test_viewer" } });
    expect(r.status).toBe(404);
  });
});
