/**
 * A fake kit backend for the console's tests: `fetch` stubbed with answers by "METHOD path", recording every
 * request (method, path, headers, parsed body). A request it has no answer for gets 404, so a test never gets a
 * made-up answer. Also: the steps of a setup state, for the wizard's tests.
 */
import { vi } from "vitest";
import type { Brand, SetupState, SetupStep } from "../src/api";

export interface Sent {
  method: string;
  path: string;
  headers: Record<string, string>;
  body: unknown;
  /** The request body as sent (a FormData for an upload). */
  raw?: unknown;
}

type Answer = (body: unknown) => Response;

export function fakeKit(answers: Record<string, Answer | unknown>): Sent[] {
  const sent: Sent[] = [];
  vi.stubGlobal(
    "fetch",
    vi.fn(async (path: string, init: RequestInit = {}) => {
      const method = (init.method ?? "GET").toUpperCase();
      const body = typeof init.body === "string" ? JSON.parse(init.body) : undefined;
      sent.push({ method, path, headers: (init.headers ?? {}) as Record<string, string>, body, raw: init.body });
      const answer = answers[`${method} ${path}`];
      if (answer === undefined) return Response.json({ message: "not faked" }, { status: 404 });
      return typeof answer === "function" ? (answer as Answer)(body) : Response.json(answer);
    }),
  );
  return sent;
}

export const brand: Brand = { name: "Acme Voice", logoUrl: null, supportEmail: null, colors: { primary: "#112233" }, passwordReset: false, signUp: false, signInPassword: true, signInLink: false, signInEmailCode: false, signInCode: false, signInCodeChannel: null, signInWith: [], captcha: null };

export function step(over: Partial<SetupStep> & { id: string }): SetupStep {
  return {
    title: over.id,
    description: `${over.id} step`,
    status: "todo",
    access: "owner",
    submittable: true,
    fields: [],
    checks: [],
    facts: [],
    ...over,
  };
}

export function setupState(steps: SetupStep[], over: Partial<SetupState> = {}): SetupState {
  return { installed: false, ownerExists: false, steps, ...over };
}
