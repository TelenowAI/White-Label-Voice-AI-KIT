/**
 * Console → one agent → where its finished calls are sent (agents/AgentWebhooks.tsx): adding and removing an address, its secret the once, a test, the latest tries, refusals beside their fields. Not here: what the backend does (AgentWebhooksTest.php).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AgentWebhook, AgentWebhookDelivery } from "../src/api";
import { AgentWebhooks } from "../src/agents/AgentWebhooks";
import { agentSettings } from "./agentSettings";

const agent = { id: "a1", name: "Front desk", settings: agentSettings } as unknown as Agent;
const hook = (over: Partial<AgentWebhook> = {}): AgentWebhook => ({ id: "w1", url: "https://example.com/hooks", events: ["call.completed"], includeTranscript: false, syncState: "synced", syncError: null, signingSecret: null, lastOkAt: null, failing: false, stopped: false, ...over });
const delivery = (over: Partial<AgentWebhookDelivery> = {}): AgentWebhookDelivery => ({ id: "d1", event: "call.completed", status: "delivered", attempt: 1, responseStatus: 200, problem: null, kind: null, at: "2026-11-04T05:39:00Z", canSendAgain: true, ...over });
const events = ["call.completed", "call.analyzed"];

/** The kit API by method and path, with what was sent kept. */
function fake(routes: Record<string, (body: unknown) => Response>) {
  const sent: { method: string; path: string; body: unknown }[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? "GET";
    const path = String(url);
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    sent.push({ method, path, body });
    const route = routes[`${method} ${path}`];
    return route ? route(body) : new Response(null, { status: 204 });
  }));
  return sent;
}
const root = "/api/kit/console/agents/a1/webhooks";
const listing = (webhooks: AgentWebhook[]) => ({ [`GET ${root}`]: () => Response.json({ webhooks, events }) });

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("where an agent's finished calls are sent", () => {
  it("adds an address with what was chosen, and shows its secret the once", async () => {
    const sent = fake({ ...listing([]), [`POST ${root}`]: () => Response.json(hook({ signingSecret: "wh_secret_x", includeTranscript: true }), { status: 201 }) });
    render(<AgentWebhooks agent={agent} />);
    const form = await screen.findByRole("form", { name: "Add this address" });
    fireEvent.change(within(form).getByLabelText("Web address"), { target: { value: " https://example.com/hooks " } });
    fireEvent.click(within(form).getByLabelText("When a call's analysis is ready"));
    fireEvent.click(within(form).getByLabelText("Include the transcript"));
    fireEvent.click(within(form).getByRole("button", { name: "Add this address" }));
    expect((await screen.findByRole("status")).textContent).toContain("wh_secret_x");
    expect(screen.getByText(/only shown this once/)).toBeTruthy();
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ url: "https://example.com/hooks", events: ["call.completed"], includeTranscript: true });
    expect((within(form).getByLabelText("Web address") as HTMLInputElement).value).toBe("");
  });

  it("offers every event the backend says, all ticked to begin with", async () => {
    fake(listing([]));
    render(<AgentWebhooks agent={agent} />);
    expect((await screen.findByLabelText("When a call finishes") as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText("When a call's analysis is ready") as HTMLInputElement).checked).toBe(true);
  });

  it("shows a secret still waiting from a list too", async () => {
    fake(listing([hook({ signingSecret: "wh_secret_late" })]));
    render(<AgentWebhooks agent={agent} />);
    expect((await screen.findByRole("status")).textContent).toContain("wh_secret_late");
  });

  it("puts a refusal beside its field and keeps what was typed", async () => {
    fake({ ...listing([]), [`POST ${root}`]: () => Response.json({ message: "x", errors: { url: ["Use https."], events: ["Choose one."] } }, { status: 422 }) });
    render(<AgentWebhooks agent={agent} />);
    const form = await screen.findByRole("form", { name: "Add this address" });
    fireEvent.change(within(form).getByLabelText("Web address"), { target: { value: "http://x" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add this address" }));
    expect(await within(form).findByText("Use https.")).toBeTruthy();
    expect(within(form).getByText("Choose one.")).toBeTruthy();
    expect(within(form).getByLabelText("Web address").getAttribute("aria-invalid")).toBe("true");
    expect((within(form).getByLabelText("Web address") as HTMLInputElement).value).toBe("http://x");
  });

  it("says when the list cannot be read, and offers no form", async () => {
    fake({ [`GET ${root}`]: () => Response.json({ message: "Try again shortly." }, { status: 500 }) });
    render(<AgentWebhooks agent={agent} />);
    expect((await screen.findByRole("alert")).textContent).toBe("Try again shortly.");
    expect(screen.queryByRole("form", { name: "Add this address" })).toBeNull();
  });

  it("removes an address", async () => {
    const sent = fake(listing([hook()]));
    render(<AgentWebhooks agent={agent} />);
    fireEvent.click(await screen.findByRole("button", { name: "Remove this address" }));
    await waitFor(() => expect(screen.queryByText("https://example.com/hooks")).toBeNull());
    expect(sent.some((s) => s.method === "DELETE" && s.path === `${root}/w1`)).toBe(true);
  });

  it("says how an address is doing", async () => {
    fake(listing([hook({ id: "a", url: "https://a.example", stopped: true, failing: true }), hook({ id: "b", url: "https://b.example", failing: true }), hook({ id: "c", url: "https://c.example", syncState: "failed", syncError: "It could not be used." }), hook({ id: "d", url: "https://d.example", syncState: "pending" }), hook({ id: "e", url: "https://e.example", lastOkAt: "2026-11-04T05:39:00Z" })]));
    render(<AgentWebhooks agent={agent} />);
    const row = async (url: string) => within((await screen.findByText(url)).closest("fieldset") as HTMLElement);
    expect((await row("https://a.example")).getByText(/Sending to this address has stopped/)).toBeTruthy();
    expect((await row("https://a.example")).queryByText(/The last try/)).toBeNull();
    expect((await row("https://b.example")).getByText(/The last try to this address failed/)).toBeTruthy();
    expect((await row("https://c.example")).getByText("It could not be used.")).toBeTruthy();
    expect((await row("https://c.example")).queryByRole("button", { name: "Send a test" })).toBeNull();
    expect((await row("https://c.example")).getByRole("button", { name: "Remove this address" })).toBeTruthy();
    expect((await row("https://d.example")).queryByRole("button", { name: "Send a test" })).toBeNull();
    expect((await row("https://e.example")).getByText(/Last delivered/)).toBeTruthy();
  });

  it("sends a test of its first event and shows the latest tries", async () => {
    const sent = fake({ ...listing([hook({ events: ["call.analyzed", "call.completed"] })]), [`POST ${root}/w1/test`]: () => Response.json({ deliveries: [delivery({ kind: "test", status: "pending", canSendAgain: false, responseStatus: null })] }, { status: 202 }) });
    render(<AgentWebhooks agent={agent} />);
    fireEvent.click(await screen.findByRole("button", { name: "Send a test" }));
    expect(await screen.findByText(/\(test\): waiting to be sent/)).toBeTruthy();
    expect(sent.find((s) => s.path === `${root}/w1/test`)?.body).toEqual({ event: "call.analyzed" });
    expect(screen.queryByRole("button", { name: "Send again" })).toBeNull();
  });

  it("says why a test was refused", async () => {
    fake({ ...listing([hook()]), [`POST ${root}/w1/test`]: () => Response.json({ message: "Test sends are not available." }, { status: 409 }) });
    render(<AgentWebhooks agent={agent} />);
    fireEvent.click(await screen.findByRole("button", { name: "Send a test" }));
    expect((await screen.findByRole("alert")).textContent).toBe("Test sends are not available.");
  });

  it("shows how each try went, and sends a finished one again", async () => {
    const sent = fake({ ...listing([hook()]), [`GET ${root}/w1/deliveries`]: () => Response.json({ deliveries: [delivery(), delivery({ id: "d2", status: "dead", problem: "The address took too long to answer.", responseStatus: null })] }), [`POST ${root}/w1/deliveries/d2/replay`]: () => Response.json({ deliveries: [delivery({ id: "d3", kind: "replay" })] }, { status: 202 }) });
    render(<AgentWebhooks agent={agent} />);
    fireEvent.click(await screen.findByRole("button", { name: "See the latest tries" }));
    expect(await screen.findByText(/: delivered$/)).toBeTruthy();
    const dead = screen.getByText(/took too long/).closest("li") as HTMLElement;
    fireEvent.click(within(dead).getByRole("button", { name: "Send again" }));
    await waitFor(() => expect(screen.queryByText(/took too long/)).toBeNull());
    expect(sent.some((s) => s.method === "POST" && s.path === `${root}/w1/deliveries/d2/replay`)).toBe(true);
  });

  it("says when nothing has been sent yet", async () => {
    fake({ ...listing([hook()]), [`GET ${root}/w1/deliveries`]: () => Response.json({ deliveries: [] }) });
    render(<AgentWebhooks agent={agent} />);
    fireEvent.click(await screen.findByRole("button", { name: "See the latest tries" }));
    expect(await screen.findByText("Nothing has been sent yet.")).toBeTruthy();
  });
});
