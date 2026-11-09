/**
 * Console → one agent → duplicating it (agents/AgentDuplicate.tsx on agents/AgentPage.tsx): the button sends a POST with no body
 * to that agent's own duplicate path, says "Duplicating…" and is off while it runs, then opens the copy's page; that page says
 * once whose copy it is and lists what is left to do, one line for each of the backend's `toRedo` (a key by its tool's name,
 * the call steps with a link to the copy's steps page, the voice setup, the webhooks, tools, look-ups and hand-overs), with no
 * list when nothing is left (the copy read a moment later, as over a network, so the page being left cannot take the notice); a reload of that page does not say it again; a refusal (404, 409, 422 or any other) is an alert by
 * the button, and the page stays. Not here: what the backend copies (laravel tests), the call steps page (AgentFlow.test.tsx).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation, type Location } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AgentCopyToDo, ConsoleHome, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { agentSettings, settingsForm } from "./agentSettings";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const nothingOffered = { llm: [], stt: [], tts: [], defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null } };
const noWebChat = { allowed: false, priced: true, enabled: false, websites: [], title: null, greeting: null, snippet: null };
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/catalog": nothingOffered,
  "GET /api/kit/console/agents/settings-form": settingsForm,
  "GET /api/kit/console/team": { members: [], invites: [] },
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
  "GET /api/kit/console/agents/a1/webhooks": { webhooks: [], events: ["call.completed"] },
  "GET /api/kit/console/agents/a2/webhooks": { webhooks: [], events: ["call.completed"] },
  "GET /api/kit/console/agents/a1/web-chat": noWebChat,
  "GET /api/kit/console/agents/a2/web-chat": noWebChat,
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
    stackCheck: null, knowledgeBases: [], logoUrl: null,
    syncState: "synced",
    syncError: null,
    createdAt: "2026-11-08T09:00:00Z",
    updatedAt: "2026-11-08T09:00:00Z",
    ...over,
  };
}

const source = agent();
const copy = agent({ id: "a2", name: "Front desk (copy)", syncState: "pending" });
const DUPLICATE = "/api/kit/console/agents/a1/duplicate";

/** Where the router is now, as a reload of the browser would find it again (its path and its history state). */
let now: Location | null = null;

function Where() {
  now = useLocation();
  return <span data-testid="where">{now.pathname}</span>;
}

function open(entry: string | Location) {
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

/** An answer that comes a moment later, as over a network: the router has settled the move by then, as in a browser. */
function later(answer: () => Response) {
  return () => new Promise<Response>((resolve) => setTimeout(() => resolve(answer()), 20));
}

function pages(toRedo: AgentCopyToDo[], answers: Record<string, unknown> = {}) {
  return fakeKit({
    ...signedIn,
    "GET /api/kit/console/agents/a1": source,
    "GET /api/kit/console/agents/a2": later(() => Response.json(copy)),
    [`POST ${DUPLICATE}`]: () => Response.json({ agent: copy, toRedo }, { status: 201 }),
    ...answers,
  });
}

async function duplicate() {
  fireEvent.click(await screen.findByRole("button", { name: "Duplicate" }));
  expect(await screen.findByRole("heading", { name: "Front desk (copy)" })).toBeTruthy();
}

describe("duplicating an agent", () => {
  it("sends a POST with no body to that agent's duplicate path, says Duplicating… with the button off while it runs, then opens the copy", async () => {
    let answer: (r: Response) => void = () => undefined;
    const sent = pages([], { [`POST ${DUPLICATE}`]: () => new Promise<Response>((resolve) => (answer = resolve)) });
    open("/console/agents/a1");
    expect(await screen.findByText("Makes a copy of this agent in this account. Phone numbers and the web chat box stay here.")).toBeTruthy();
    fireEvent.click(await screen.findByRole("button", { name: "Duplicate" }));

    const busy = await screen.findByRole("button", { name: "Duplicating…" });
    expect((busy as HTMLButtonElement).disabled).toBe(true);
    const posts = sent.filter((s) => s.method === "POST");
    expect(posts.map((s) => s.path)).toEqual([DUPLICATE]);
    expect(posts[0].raw).toBeUndefined();

    answer(Response.json({ agent: copy, toRedo: [] }, { status: 201 }));
    expect(await screen.findByRole("heading", { name: "Front desk (copy)" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/agents/a2");
    expect(sent.filter((s) => s.method === "GET" && s.path === "/api/kit/console/agents/a2")).toHaveLength(1);
  });

  it("says on the copy's page whose copy it is, with one line for each thing left to do", async () => {
    pages([
      { kind: "key", name: "Book a table" },
      { kind: "key", name: "Find the order" },
      { kind: "flow", name: null },
      { kind: "voiceSetup", name: null },
      { kind: "webhooks", name: null },
      { kind: "tools", name: null },
      { kind: "lookups", name: null },
      { kind: "handoff", name: null },
    ]);
    open("/console/agents/a1");
    await duplicate();

    const notice = await screen.findByText("This is a copy of Front desk.");
    expect(notice.closest("[role=status]")).toBeTruthy();
    const list = within(notice.closest("[role=status]") as HTMLElement).getByRole("list", { name: "Left to do" });
    expect(within(list).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Type the key for Book a table again: keys are not copied.",
      "Type the key for Find the order again: keys are not copied.",
      "The call steps are a draft: finish them on the Call steps page and publish.",
      "The voice setup is no longer offered, so the copy has the default one. Check it below.",
      "Each webhook address has its own new signing secret: see Send calls to your own system below.",
      "Tools could not be copied as they are: set them again.",
      "Look-ups could not be copied as they are: set them again.",
      "Hand-overs could not be copied as they are: set them again.",
    ]);
    expect(within(list).getByRole("link", { name: "Call steps page" }).getAttribute("href")).toBe("/console/agents/a2/flow");
  });

  it("says only whose copy it is when nothing is left to do", async () => {
    pages([]);
    open("/console/agents/a1");
    await duplicate();
    const notice = (await screen.findByText("This is a copy of Front desk.")).closest("[role=status]") as HTMLElement;
    expect(notice.textContent).toBe("This is a copy of Front desk.");
    expect(screen.queryByRole("list", { name: "Left to do" })).toBeNull();
  });

  it("does not say it again when the copy's page is reloaded", async () => {
    pages([{ kind: "webhooks", name: null }]);
    open("/console/agents/a1");
    await duplicate();
    expect(await screen.findByText("This is a copy of Front desk.")).toBeTruthy();
    await waitFor(() => expect(now?.state).toBeNull());
    const reloaded = now!;
    expect(reloaded.pathname).toBe("/console/agents/a2");

    cleanup();
    open(reloaded);
    expect(await screen.findByRole("heading", { name: "Front desk (copy)" })).toBeTruthy();
    expect(await screen.findByRole("button", { name: "Duplicate" })).toBeTruthy();
    expect(screen.queryByText("This is a copy of Front desk.")).toBeNull();
    expect(screen.queryByRole("list", { name: "Left to do" })).toBeNull();
  });

  it.each([
    [404, { message: "No such agent." }],
    [409, { message: "Finish setting up this account first." }],
    [422, { message: "This agent cannot be copied as it is.", errors: { name: ["The name is taken."] } }],
    [500, { message: "Try again shortly." }],
  ])("shows a %i refusal's message by the button, and stays on the agent's page", async (status, body) => {
    const sent = pages([], { [`POST ${DUPLICATE}`]: () => Response.json(body, { status }) });
    open("/console/agents/a1");
    fireEvent.click(await screen.findByRole("button", { name: "Duplicate" }));
    const button = await screen.findByRole("button", { name: "Duplicate" });
    const alert = await within(button.parentElement as HTMLElement).findByRole("alert");
    expect(alert.textContent).toBe(body.message);
    expect((button as HTMLButtonElement).disabled).toBe(false);
    expect(screen.getByRole("heading", { name: "Front desk" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/agents/a1");
    expect(sent.some((s) => s.path === "/api/kit/console/agents/a2")).toBe(false);
    expect(screen.queryByText("This is a copy of Front desk.")).toBeNull();
  });
});
