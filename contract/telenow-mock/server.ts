/**
 * A recorded Telenow: an HTTP server that answers the kit's Telenow calls from recordings (`cassettes/*.json`),
 * so the contract tests run every backend against the same Telenow answers without a Telenow account.
 *
 * A request is matched to an operation of Telenow's released document (telenow/openapi.yaml) by method and path
 * template, then to a recording of that operation made with the request's `X-API-Key` (each recording names the
 * placeholder key it answers, `request.apiKey`; the real key is never kept): the one recorded at exactly this path,
 * else that key's first. So one operation can answer differently per key, as Telenow does — an operator's admin key,
 * a sub-account's key, a revoked key (its recorded 401). A recording that names an `idempotencyKey` (a placeholder too)
 * answers only a request carrying that `Idempotency-Key`; the others answer any, so a write's refusal (a 409) can sit
 * beside its success. A recording that names `queryNames` answers only a request carrying those query parameters, with
 * any values (a feed page asked for `updatedAfter` or for a `cursor`: the instant differs every run); the one naming
 * the most wins. A binary answer (a recording's audio) is kept as `bodyBase64` with its `contentType`. A recording marked `serve: false` (a later state of the same request — an agent read after it
 * was deleted) is never served; tests read it by name. Like Telenow, it refuses a request with no `X-API-Key` (401).
 * A request no recording answers gets 404 with a JSON error naming the operation — never a made-up answer.
 *
 * Every recording is held to the released document by tests/telenow-mock.test.ts, so the mock cannot drift from
 * what Telenow promises. Not here: recording new answers from a real Telenow (later), the kit's Telenow client.
 *
 * Run: `pnpm --filter contract mock` (PORT, default an ephemeral one, printed).
 */
import { createServer, type Server } from "node:http";
import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { Spec, type Operation } from "../lib/openapi";

export interface Cassette {
  operationId: string;
  recorded: string;
  /**
   * `apiKey`: the placeholder key this recording answers (the real key it was made with is not kept).
   * `idempotencyKey`: when set, it answers only a request with this Idempotency-Key.
   * `queryNames`: when set, it answers only a request carrying these query parameters (any values).
   */
  request: { method: string; path: string; query: string; apiKey: string; idempotencyKey?: string; queryNames?: string[] };
  /** A JSON answer is `body`; a binary one is `bodyBase64` with its `contentType`. */
  response: { status: number; body?: unknown; bodyBase64?: string; contentType?: string };
  /** False: kept and checked against the document, but never served (tests read it by name). */
  serve?: boolean;
}

export function loadCassettes(dir: string): Cassette[] {
  return readdirSync(dir)
    .filter((f) => f.endsWith(".json"))
    .sort()
    .map((f) => JSON.parse(readFileSync(join(dir, f), "utf8")) as Cassette);
}

/** The path template `concrete` matches (`/api/agents/{id}` for `/api/agents/abc`), most literal segments first. */
export function matchOperation(spec: Spec, method: string, concrete: string): Operation | undefined {
  const parts = concrete.split("/");
  const candidates = spec.operations().filter((op) => {
    if (op.method !== method.toUpperCase()) return false;
    const tpl = op.path.split("/");
    return tpl.length === parts.length && tpl.every((t, i) => (t.startsWith("{") && t.endsWith("}")) || t === parts[i]);
  });
  const literal = (op: Operation) => op.path.split("/").filter((t) => !t.startsWith("{")).length;
  return candidates.sort((a, b) => literal(b) - literal(a))[0];
}

export function createMock(spec: Spec, cassettes: Cassette[]): Server {
  return createServer((req, res) => {
    const url = new URL(req.url ?? "/", "http://mock");
    const send = (status: number, body: unknown) => {
      res.writeHead(status, { "content-type": "application/json" });
      res.end(JSON.stringify(body));
    };
    const key = req.headers["x-api-key"];
    if (!key) return send(401, { success: false, error: "API key is required" });
    const op = matchOperation(spec, req.method ?? "GET", url.pathname);
    if (!op) return send(404, { success: false, error: `no Telenow operation for ${req.method} ${url.pathname}` });
    const idem = req.headers["idempotency-key"];
    const mine = cassettes
      .filter((c) => c.serve !== false && c.operationId === op.operationId && c.request.apiKey === key)
      .filter((c) => c.request.idempotencyKey === undefined || c.request.idempotencyKey === idem)
      .filter((c) => (c.request.queryNames ?? []).every((n) => url.searchParams.has(n)))
      .sort((a, b) => Number(b.request.idempotencyKey !== undefined) - Number(a.request.idempotencyKey !== undefined)
        || (b.request.queryNames?.length ?? 0) - (a.request.queryNames?.length ?? 0));
    const hit = mine.find((c) => c.request.path === url.pathname) ?? mine[0];
    if (!hit) return send(404, { success: false, error: `no recording for ${op.operationId} with this key` });
    if (hit.response.bodyBase64 !== undefined) {
      res.writeHead(hit.response.status, { "content-type": hit.response.contentType ?? "application/octet-stream" });
      return res.end(Buffer.from(hit.response.bodyBase64, "base64"));
    }
    send(hit.response.status, hit.response.body);
  });
}

// Run directly: serve the recordings.
if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
  const root = join(import.meta.dirname, "..");
  const server = createMock(new Spec(join(root, "telenow", "openapi.yaml")), loadCassettes(join(import.meta.dirname, "cassettes")));
  server.listen(Number(process.env.PORT ?? 0), "127.0.0.1", () => {
    const addr = server.address();
    console.log(`recorded Telenow on http://127.0.0.1:${typeof addr === "object" && addr ? addr.port : "?"}`);
  });
}
