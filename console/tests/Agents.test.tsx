/**
 * Console → Agents (agents/): the list; building an agent and landing on its page; the backend's refused fields shown
 * at their inputs; changing one (only an existing agent has "takes calls"); retrying a refused one; deleting after
 * being asked twice; and the test call — joined with exactly the session the backend answered (@telenow/client is
 * replaced by a recorder here: no microphone, no network), its transcript shown as it comes, hung up by the button or
 * by leaving the page, and never joined when the backend refuses it.
 */
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { TelenowCallOptions } from "@telenow/client";
import type { Agent, ConsoleHome, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { agentSettings, settingsForm } from "./agentSettings";

const calls = vi.hoisted(() => [] as Array<{ opts: TelenowCallOptions; start: () => Promise<void>; stop: () => void }>);

vi.mock("@telenow/client", () => ({
  TelenowCall: class {
    readonly opts: TelenowCallOptions;
    readonly start = vi.fn(async () => {
      this.opts.onState?.("connecting");
      this.opts.onState?.("live");
    });
    readonly stop = vi.fn(() => this.opts.onState?.("ended"));
    constructor(opts: TelenowCallOptions) {
      this.opts = opts;
      calls.push(this);
    }
  },
}));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  calls.length = 0;
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const nothingOffered = { llm: [], stt: [], tts: [], defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null } };
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/catalog": nothingOffered,
  "GET /api/kit/console/agents/settings-form": settingsForm,
  "GET /api/kit/console/agents/a1/webhooks": { webhooks: [], events: ["call.completed"] },
  "GET /api/kit/console/team": { members: [], invites: [] },
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
};

function agent(over: Partial<Agent> = {}): Agent {
  return {
    id: "a1",
    name: "Front desk",
    description: "Books appointments",
    systemPrompt: "You are the front desk.",
    isActive: true,
    stack: { llmProvider: "openai", llmModel: "gpt-5.4-mini", sttProvider: "deepgram", ttsProvider: "openai", ttsVoice: "alloy" },
    settings: agentSettings,
    stackNames: { llmProvider: "OpenAI", llmModel: "GPT-5.4 mini", sttProvider: "Deepgram", ttsProvider: "Studio voices", ttsVoice: "Alloy" },
    stackCheck: null, knowledgeBases: [],
    syncState: "synced",
    syncError: null,
    createdAt: "2026-10-30T09:00:00Z",
    updatedAt: "2026-10-30T09:00:00Z",
    ...over,
  };
}

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function open(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

describe("the agents list", () => {
  it("sends a new agent's opening line, and says on its page that settings saved after the form comes back", async () => {
    const made = agent({ id: "a2", name: "Night desk", settings: { ...agentSettings, greetingInbound: "Hi." } });
    const later = { ...made, updatedAt: "2026-11-03T12:00:00Z", settings: { ...made.settings, recording: false } };
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents?page=1": { agents: [], total: 0, page: 1, perPage: 50 },
      "POST /api/kit/console/agents": () => Response.json(made, { status: 201 }),
      "GET /api/kit/console/agents/a2": made,
      "PATCH /api/kit/console/agents/a2": () => Response.json(later),
    });
    open("/console/agents/new");
    fireEvent.change(await screen.findByLabelText("Name"), { target: { value: "Night desk" } });
    fireEvent.change(screen.getByLabelText("Opening line"), { target: { value: "  Hi.  " } });
    fireEvent.click(screen.getByRole("button", { name: "Create agent" }));
    fireEvent.click(await screen.findByLabelText("Record calls"));
    fireEvent.click(screen.getByRole("button", { name: "Save these settings" }));
    expect((await screen.findByText("Saved.")).getAttribute("role")).toBe("status");
    expect((screen.getByLabelText("Record calls") as HTMLInputElement).checked).toBe(false);
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ name: "Night desk", description: null, systemPrompt: null, settings: { greetingInbound: "Hi." } });
  });

  it("lists the account's agents and builds a new one, then opens its page", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents?page=1": { agents: [agent({ isActive: false, syncState: "pending", syncError: "Not saved yet." })], total: 1, page: 1, perPage: 50 },
      "POST /api/kit/console/agents": () => Response.json(agent({ id: "a2", name: "Night desk" }), { status: 201 }),
      "GET /api/kit/console/agents/a2": agent({ id: "a2", name: "Night desk" }),
    });
    open("/console");
    fireEvent.click(await screen.findByRole("link", { name: "Agents" }));

    const row = (await screen.findByRole("link", { name: "Front desk" })).closest("tr")!;
    expect(within(row).getByText("Paused")).toBeTruthy();
    expect(within(row).getByText("Saving…")).toBeTruthy();
    expect(within(row).getByText("Not saved yet.")).toBeTruthy();

    fireEvent.click(screen.getByRole("link", { name: "New agent" }));
    expect(await screen.findByRole("heading", { name: "New agent" })).toBeTruthy();
    expect(screen.queryByLabelText("Takes calls")).toBeNull();
    fireEvent.change(screen.getByLabelText("Name"), { target: { value: "Night desk" } });
    fireEvent.change(screen.getByLabelText("Instructions"), { target: { value: "You answer at night." } });
    fireEvent.click(screen.getByRole("button", { name: "Create agent" }));

    expect(await screen.findByRole("heading", { name: "Night desk" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/agents/a2");
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ name: "Night desk", description: null, systemPrompt: "You answer at night.", settings: { greetingInbound: null } });
  });

  it("says when there are none", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/agents?page=1": { agents: [], total: 0, page: 1, perPage: 50 } });
    open("/console/agents");
    expect(await screen.findByText("No agents yet. Build your first one.")).toBeTruthy();
  });

  it("shows the fields the backend refused, at their inputs", async () => {
    fakeKit({
      ...signedIn,
      "POST /api/kit/console/agents": () =>
        Response.json({ message: "x", errors: { name: ["The Name field is required."] } }, { status: 422 }),
    });
    open("/console/agents/new");
    fireEvent.click(await screen.findByRole("button", { name: "Create agent" }));
    expect(await screen.findByText("The Name field is required.")).toBeTruthy();
    expect(screen.getByLabelText("Name").getAttribute("aria-invalid")).toBe("true");
  });
});

describe("an agent's page", () => {
  it("changes the agent, retries a refused change, and deletes it after asking", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent({ syncState: "failed", syncError: "No voice setup is chosen." }),
      "POST /api/kit/console/agents/a1/retry": agent(),
      "PATCH /api/kit/console/agents/a1": (body: unknown) => Response.json(agent({ ...(body as Partial<Agent>), updatedAt: "2026-10-30T10:00:00Z" })),
      "DELETE /api/kit/console/agents/a1": () => new Response(null, { status: 204 }),
      "GET /api/kit/console/agents?page=1": { agents: [], total: 0, page: 1, perPage: 50 },
    });
    open("/console/agents/a1");

    expect(await screen.findByText("No voice setup is chosen.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Retry" }));
    expect(await screen.findByText("Saved")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Retry" })).toBeNull();

    expect(screen.getByText("OpenAI · GPT-5.4 mini")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Name"), { target: { value: "Front desk (evenings)" } });
    fireEvent.click(screen.getByLabelText("Takes calls"));
    fireEvent.click(screen.getByRole("button", { name: "Save changes" }));
    expect(await screen.findByRole("heading", { name: "Front desk (evenings)" })).toBeTruthy();
    expect(sent.find((s) => s.method === "PATCH")?.body).toEqual({
      name: "Front desk (evenings)", description: "Books appointments", systemPrompt: "You are the front desk.", isActive: false,
    });

    fireEvent.click(screen.getByRole("button", { name: "Delete agent" }));
    expect(screen.getByText("Delete Front desk (evenings)? Calls to it stop working.")).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Delete agent" }));
    expect(await screen.findByRole("heading", { name: "Agents" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/agents");
    expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/console/agents/a1"]);
  });

  it("says when a part of the voice setup is not chosen yet", async () => {
    const none = { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null };
    fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent({ stack: none, stackNames: none }) });
    open("/console/agents/a1");
    expect(await screen.findAllByText("Not chosen yet")).toHaveLength(3);
  });

  it("names a part the operator does not offer only as set, never by the provider's key", async () => {
    const none = { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null };
    fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent({ stackNames: none }) });
    open("/console/agents/a1");
    const facts = await screen.findByText("Language model");
    expect(facts.parentElement?.textContent).toContain("Set · gpt-5.4-mini");
    expect(document.body.textContent).not.toContain("openai ·");
  });
});

describe("the test call", () => {
  const session = { sessionId: "s1", transport: "websocket" as const, websocketUrl: "wss://voice.example/ws/web-agent" };

  it("joins with exactly the session the backend answered, shows the transcript, and hangs up", async () => {
    const sent = fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent(), "POST /api/kit/console/agents/a1/test-call": { session } });
    open("/console/agents/a1");
    expect(await screen.findByText("Not in a call")).toBeTruthy();

    fireEvent.click(await screen.findByRole("button", { name: "Start test call" }));
    expect(await screen.findByText("In the call")).toBeTruthy();
    expect(calls).toHaveLength(1);
    expect(calls[0].opts.session).toEqual(session);
    expect(calls[0].opts.token).toBeUndefined();
    expect(calls[0].start).toHaveBeenCalledOnce();
    expect(sent.filter((s) => s.method === "POST").map((s) => s.path)).toEqual(["/api/kit/console/agents/a1/test-call"]);

    act(() => {
      calls[0].opts.onTranscript?.({ role: "user", text: "Hel", isFinal: false });
      calls[0].opts.onTranscript?.({ role: "user", text: "Hello", isFinal: true });
      calls[0].opts.onTranscript?.({ role: "assistant", text: "Hi, this is Acme", isFinal: false });
    });
    const transcript = screen.getByRole("list", { name: "Test call" });
    expect(within(transcript).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["You: Hello", "Agent: Hi, this is Acme"]);

    act(() => calls[0].opts.onError?.("Microphone blocked"));
    expect(screen.getByRole("alert").textContent).toBe("Microphone blocked");

    fireEvent.click(screen.getByRole("button", { name: "Hang up" }));
    expect(calls[0].stop).toHaveBeenCalledOnce();
    expect(await screen.findByText("Call ended")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Start test call" })).toBeTruthy();
  });

  it("hangs up when the page is left", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      "POST /api/kit/console/agents/a1/test-call": { session },
      "GET /api/kit/console/agents?page=1": { agents: [agent()], total: 1, page: 1, perPage: 50 },
    });
    open("/console/agents/a1");
    fireEvent.click(await screen.findByRole("button", { name: "Start test call" }));
    expect(await screen.findByText("In the call")).toBeTruthy();
    fireEvent.click(screen.getByRole("link", { name: "All agents" }));
    expect(await screen.findByRole("heading", { name: "Agents" })).toBeTruthy();
    expect(calls[0].stop).toHaveBeenCalledOnce();
  });

  it("shows the backend's refusal and joins nothing", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      "POST /api/kit/console/agents/a1/test-call": () => Response.json({ message: "The service is paused for now." }, { status: 409 }),
    });
    open("/console/agents/a1");
    fireEvent.click(await screen.findByRole("button", { name: "Start test call" }));
    expect((await screen.findByRole("alert")).textContent).toBe("The service is paused for now.");
    expect(calls).toHaveLength(0);
    expect(screen.getByText("Not in a call")).toBeTruthy();
  });
});

describe("the agent form's settings", () => {
  it("come from what the backend declares, and a failure to read them is said", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent(), "GET /api/kit/console/stack-choices": nothingOffered });
    open("/console/agents/a1");
    expect(await screen.findByText("How it speaks")).toBeTruthy();
    cleanup();
    fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent(), "GET /api/kit/console/agents/settings-form": () => Response.json({ message: "Try again shortly." }, { status: 500 }) });
    open("/console/agents/a1");
    expect((await screen.findByText("Try again shortly.")).getAttribute("role")).toBe("alert");
    expect(screen.queryByText("How it speaks")).toBeNull();
  });
});
