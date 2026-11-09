/**
 * Starter agents, against a fake backend:
 * - Admin → Starter agents (admin/StarterAgentsPage.tsx, admin/StarterPublish.tsx, its place in admin/AdminArea.tsx): each
 *   starter's name, description, call steps, where it was made from, who published it and when, and whether it is
 *   offered; renaming or redescribing one in place (a 422 by its field); stopping and offering it again (a refusal an
 *   alert on that starter); the accounts to publish from, and the words when there are none; the preview's every text in
 *   full, grouped by where it is, and its left-out lines; the pickers held while it is read; Publish off until the box is
 *   ticked (another agent unticks it);
 *   the POST of the agent previewed with `reviewed: true`; a 422's reasons by their fields, a 404 as an alert; the list
 *   read again once published; the page and its nav link only with the `starters` ability.
 * - Console → new agent (agents/AgentFromStarter.tsx on agents/AgentPage.tsx): the starters offered above the blank form,
 *   none shown when none is offered or they cannot be read; "Use this" sends a POST with no body, opens the new agent's
 *   page, which says once which starter it was made from and what is left to do; a refusal is an alert by the button.
 *
 * Not here: what a starter carries and leaves out (laravel tests), the api calls against the contract (api.test.ts).
 */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation, type Location } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AgentCopyToDo, ConsoleHome, StarterAgent, StarterAgentPreview, StarterSource, StarterTextWhere, WorkspaceList } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { StarterAgentsPage } from "../src/admin/StarterAgentsPage";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { agentSettings, settingsForm } from "./agentSettings";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

/** Where the router is now, as a reload of the browser would find it again (its path and its history state). */
let now: Location | null = null;

function Where() {
  now = useLocation();
  return <span data-testid="where">{now.pathname}</span>;
}

const LIST = "/api/kit/admin/starters";
const PREVIEW = "/api/kit/admin/starters/preview?agentId=a5";

const desk: StarterAgent = {
  id: "s1", name: "Dental front desk", description: "Books and moves visits.\nAnswers opening hours.", offered: true, steps: 3,
  from: { agent: "Front desk", account: "Demo Dental" }, publishedBy: "Olu", publishedAt: "2026-11-07T10:00:00Z",
};
const orders: StarterAgent = {
  id: "s2", name: "Order line", description: null, offered: false, steps: 0, from: { agent: null, account: null }, publishedBy: null, publishedAt: "2026-11-06T09:30:00Z",
};
const sources: StarterSource[] = [
  { id: "w-dental", name: "Demo Dental", agents: [{ id: "a1", name: "Front desk" }, { id: "a3", name: "Night desk" }] },
  { id: "w-shop", name: "Demo Shop", agents: [{ id: "a5", name: "Order line" }, { id: "a6", name: "Returns" }] },
];

const longPrompt = `You are the order line of Demo Shop.\n\n${"Read every item back to the caller before you take the next one. ".repeat(60)}\nNever take card numbers.`;
const preview: StarterAgentPreview = {
  agent: { id: "a5", name: "Order line", account: "Demo Shop" },
  // As a backend might send them, not grouped: the page groups them by where they are.
  texts: [
    { where: "step", key: "name", of: "Take the order", text: "Take the order" },
    { where: "description", key: null, of: null, text: "Takes orders by phone." },
    { where: "setting", key: "greetingInbound", of: null, text: "Hello, Demo Shop orders." },
    { where: "prompt", key: null, of: null, text: longPrompt },
    { where: "step", key: "instructions", of: "Take the order", text: "Ask for each item\nand its size." },
    { where: "setting", key: "voicemailMessage", of: null, text: "Please call us back." },
    { where: "analysis", key: "order_number", of: null, text: "Order number The order the caller asks about" },
    { where: "step", key: "line", of: "Take the order", text: "What would you like?" },
    { where: "exit", key: "label", of: "Take the order → Bye", text: "Done" },
    { where: "exit", key: "when", of: "Take the order → Bye", text: "The caller wants nothing more" },
  ],
  leftOut: [
    { kind: "ownVoice", count: 1, names: [] },
    { kind: "tools", count: 2, names: ["book_visit", "get_order"] },
    { kind: "lookups", count: 1, names: ["find_customer"] },
    { kind: "handoff", count: 1, names: [] },
    { kind: "transfer", count: 1, names: [] },
    { kind: "emailAfterCall", count: 1, names: [] },
    { kind: "knowledge", count: 1, names: ["Prices"] },
    { kind: "webhooks", count: 1, names: [] },
    { kind: "steps", count: 2, names: ["Transfer", "Lookup"] },
  ],
  steps: 4,
};

function adminPage(answers: Record<string, unknown> = {}) {
  const sent = fakeKit({ [`GET ${LIST}`]: { starters: [desk, orders], sources }, [`GET ${PREVIEW}`]: preview, ...answers });
  render(<MemoryRouter><StarterAgentsPage /></MemoryRouter>);
  return sent;
}

async function rowOf(id: string): Promise<HTMLElement> {
  await screen.findByRole("list", { name: "Starter agents" });
  return document.querySelector(`[data-starter="${id}"]`) as HTMLElement;
}

/** Picks Demo Shop's Order line and reads its preview; the publish form that comes with it. */
async function previewOrderLine(): Promise<HTMLElement> {
  fireEvent.change(await screen.findByLabelText("Account"), { target: { value: "w-shop" } });
  fireEvent.click(screen.getByRole("button", { name: "Preview" }));
  return screen.findByRole("form", { name: "Publish a starter" });
}

describe("Admin → Starter agents: the list", () => {
  it("shows each starter's name, description, call steps, where it was made from, who published it and when, and whether it is offered", async () => {
    adminPage();
    const one = await rowOf("s1");
    expect(within(one).getByText("Dental front desk").tagName).toBe("STRONG");
    expect(within(one).getByText("Offered to every account")).toBeTruthy();
    expect(one.querySelector(".note-body")?.textContent).toBe("Books and moves visits.\nAnswers opening hours.");
    expect(within(one).getByText(`Call steps: 3 · Made from Front desk in Demo Dental · Published by Olu, ${new Date(desk.publishedAt).toLocaleString()}`)).toBeTruthy();
    expect(within(one).getByRole("button", { name: "Stop offering" })).toBeTruthy();

    const two = await rowOf("s2");
    expect(within(two).getByText("Not offered")).toBeTruthy();
    expect(within(two).getByText("No description")).toBeTruthy();
    expect(within(two).getByText(`Call steps: 0 · Made from an agent in an account · Published ${new Date(orders.publishedAt).toLocaleString()}`)).toBeTruthy();
    expect(within(two).getByRole("button", { name: "Offer again" })).toBeTruthy();
  });

  it("says when there are none yet", async () => {
    adminPage({ [`GET ${LIST}`]: { starters: [], sources } });
    expect(await screen.findByText("No starter agents yet.")).toBeTruthy();
    expect(screen.queryByRole("list", { name: "Starter agents" })).toBeNull();
  });

  it("renames and redescribes one in place, and shows a 422's reason by its field", async () => {
    const sent = adminPage({
      "PATCH /api/kit/admin/starters/s1": (body: unknown) => (body as { name: string }).name === ""
        ? Response.json({ message: "The name is required.", errors: { name: ["The name is required."] } }, { status: 422 })
        : Response.json({ ...desk, ...(body as object) }),
    });
    const row = await rowOf("s1");
    fireEvent.click(within(row).getByRole("button", { name: "Change name or description" }));
    const form = within(row).getByRole("form", { name: "Change Dental front desk" });
    expect((within(form).getByLabelText("Name") as HTMLInputElement).value).toBe("Dental front desk");
    expect((within(form).getByLabelText("Description") as HTMLTextAreaElement).value).toBe("Books and moves visits.\nAnswers opening hours.");

    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: "" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(form).findByText("The name is required.")).toBeTruthy();
    expect(within(form).getByLabelText("Name").getAttribute("aria-invalid")).toBe("true");
    expect(within(form).queryByRole("alert")).toBeNull();

    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: "  Dental desk  " } });
    fireEvent.change(within(form).getByLabelText("Description"), { target: { value: "   " } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(row).findByText("Dental desk")).toBeTruthy();
    expect(within(row).queryByRole("form")).toBeNull();
    expect(within(row).getByText("No description")).toBeTruthy();
    expect(sent.filter((s) => s.method === "PATCH").map((s) => s.body)).toEqual([
      { name: "", description: "Books and moves visits.\nAnswers opening hours." },
      { name: "Dental desk", description: null },
    ]);
  });

  it("stops offering one and offers it again; a refusal is an alert on that starter", async () => {
    const sent = adminPage({
      "PATCH /api/kit/admin/starters/s1": (body: unknown) => Response.json({ ...desk, ...(body as object) }),
      "PATCH /api/kit/admin/starters/s2": () => Response.json({ message: "There is no such starter agent." }, { status: 404 }),
    });
    const one = await rowOf("s1");
    fireEvent.click(within(one).getByRole("button", { name: "Stop offering" }));
    expect(await within(one).findByText("Not offered")).toBeTruthy();
    fireEvent.click(within(one).getByRole("button", { name: "Offer again" }));
    expect(await within(one).findByText("Offered to every account")).toBeTruthy();

    const two = await rowOf("s2");
    fireEvent.click(within(two).getByRole("button", { name: "Offer again" }));
    expect((await within(two).findByRole("alert")).textContent).toBe("There is no such starter agent.");
    expect(within(two).getByText("Not offered")).toBeTruthy();
    expect(within(one).queryByRole("alert")).toBeNull();
    expect(sent.filter((s) => s.method === "PATCH").map((s) => [s.path, s.body])).toEqual([
      ["/api/kit/admin/starters/s1", { offered: false }],
      ["/api/kit/admin/starters/s1", { offered: true }],
      ["/api/kit/admin/starters/s2", { offered: true }],
    ]);
  });
});

describe("Admin → Starter agents: publishing one", () => {
  it("offers the accounts the staff member owns and their agents, and says how to get one when there are none", async () => {
    adminPage();
    const account = (await screen.findByLabelText("Account")) as HTMLSelectElement;
    expect([...account.options].map((o) => o.textContent)).toEqual(["Demo Dental", "Demo Shop"]);
    const agent = () => screen.getByLabelText("Agent") as HTMLSelectElement;
    expect([...agent().options].map((o) => o.textContent)).toEqual(["Front desk", "Night desk"]);
    fireEvent.change(account, { target: { value: "w-shop" } });
    expect([...agent().options].map((o) => o.textContent)).toEqual(["Order line", "Returns"]);
    expect(agent().value).toBe("a5");
    cleanup();

    const sent = adminPage({ [`GET ${LIST}`]: { starters: [desk], sources: [] } });
    expect(await screen.findByText("Starters come from accounts you own. Make an account for yourself, build and test the agent there, then publish it here.")).toBeTruthy();
    expect(screen.queryByLabelText("Account")).toBeNull();
    expect(screen.queryByRole("button", { name: "Preview" })).toBeNull();
    expect(sent.some((s) => s.path.startsWith("/api/kit/admin/starters/preview"))).toBe(false);
  });

  it("previews every text in full, grouped by where it is, and one line for each kind left out", async () => {
    const sent = adminPage();
    await previewOrderLine();
    expect(sent.filter((s) => s.path.startsWith("/api/kit/admin/starters/preview")).map((s) => `${s.method} ${s.path}`)).toEqual([`GET ${PREVIEW}`]);
    expect(screen.getByRole("heading", { name: "What a starter made from Order line in Demo Shop carries" })).toBeTruthy();

    const texts = screen.getByRole("region", { name: "Every text it carries" });
    expect(within(texts).getAllByRole("region").map((g) => g.querySelector("h5")?.textContent)).toEqual(["Description", "Instructions", "Lines", "Analysis", "Call steps", "Exits"]);
    const shown = (where: string) => [...within(texts).getByRole("region", { name: where }).querySelectorAll(".starter-text")]
      .map((d) => [d.querySelector("small")?.textContent ?? null, d.querySelector("pre")?.textContent]);
    expect(shown("Description")).toEqual([[null, "Takes orders by phone."]]);
    expect(shown("Instructions")).toEqual([[null, longPrompt]]);
    expect(shown("Lines")).toEqual([["greetingInbound", "Hello, Demo Shop orders."], ["voicemailMessage", "Please call us back."]]);
    expect(shown("Analysis")).toEqual([["order_number", "Order number The order the caller asks about"]]);
    expect(shown("Call steps")).toEqual([["Take the order · Name", "Take the order"], ["Take the order · Instructions", "Ask for each item\nand its size."], ["Take the order · Line", "What would you like?"]]);
    expect(shown("Exits")).toEqual([["Take the order → Bye · Label", "Done"], ["Take the order → Bye · When", "The caller wants nothing more"]]);
    expect(texts.querySelectorAll("pre")).toHaveLength(preview.texts.length);

    expect(screen.getByText("Call steps it carries: 4")).toBeTruthy();
    const left = screen.getByRole("list", { name: "Left out" });
    expect(within(left).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Its own cloned voice: the starter uses the default voice setup",
      "Tools: book_visit, get_order (2)",
      "Look-ups: find_customer (1)",
      "Hand-overs (1)",
      "Transfers to a person",
      "After-call email recipients (1)",
      "Knowledge: Prices (1)",
      "Webhooks (1)",
      "Steps made endings: Transfer, Lookup (2)",
    ]);
  });

  it("shows a text from a place the console does not know yet under that place's name, and says when nothing is left out", async () => {
    adminPage({ [`GET ${PREVIEW}`]: { ...preview, texts: [{ where: "transcriber" as StarterTextWhere, key: "keywords", of: null, text: "Demo Shop, SKU" }], leftOut: [] } });
    await previewOrderLine();
    const texts = screen.getByRole("region", { name: "Every text it carries" });
    const group = within(texts).getByRole("region", { name: "transcriber" });
    expect(group.querySelector("small")?.textContent).toBe("keywords");
    expect(group.querySelector("pre")?.textContent).toBe("Demo Shop, SKU");
    expect(screen.getByText("Nothing of this agent is left out.")).toBeTruthy();
    expect(screen.queryByRole("list", { name: "Left out" })).toBeNull();
  });

  it("keeps Publish off until the box is ticked, then POSTs the agent previewed with reviewed: true and reads the list again", async () => {
    const made: StarterAgent = { ...orders, id: "s3", name: "Phone orders", description: "Takes orders.", offered: true, steps: 4, from: { agent: "Order line", account: "Demo Shop" }, publishedBy: "Olu" };
    let reads = 0;
    const sent = adminPage({
      [`GET ${LIST}`]: () => Response.json({ starters: ++reads === 1 ? [desk] : [desk, made], sources }),
      [`POST ${LIST}`]: () => Response.json(made, { status: 201 }),
    });
    const form = await previewOrderLine();
    expect((within(form).getByLabelText("Name") as HTMLInputElement).value).toBe("Order line");
    expect((within(form).getByLabelText("Description") as HTMLTextAreaElement).value).toBe("");
    const publish = within(form).getByRole("button", { name: "Publish" }) as HTMLButtonElement;
    const box = within(form).getByLabelText("I read every text above: it carries nothing of that account that every customer should not see.") as HTMLInputElement;
    expect(box.checked).toBe(false);
    expect(publish.disabled).toBe(true);
    fireEvent.submit(form);
    await act(async () => undefined);
    expect(sent.some((s) => s.method === "POST"), "not without the box ticked, even by Enter").toBe(false);

    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: "  Phone orders " } });
    fireEvent.change(within(form).getByLabelText("Description"), { target: { value: " Takes orders. " } });
    fireEvent.click(box);
    expect(publish.disabled).toBe(false);
    fireEvent.click(publish);

    expect((await screen.findByRole("status")).textContent).toBe("Published: every account can start from Phone orders now.");
    expect(sent.filter((s) => s.method === "POST").map((s) => [s.path, s.body])).toEqual([
      [LIST, { agentId: "a5", name: "Phone orders", description: "Takes orders.", reviewed: true }],
    ]);
    expect(await within(await screen.findByRole("list", { name: "Starter agents" })).findByText("Phone orders")).toBeTruthy();
    expect(sent.filter((s) => s.method === "GET" && s.path === LIST)).toHaveLength(2);
    expect(screen.queryByRole("form", { name: "Publish a starter" })).toBeNull();
  });

  it("unticks the box for another agent or a new preview", async () => {
    adminPage({ "GET /api/kit/admin/starters/preview?agentId=a6": { ...preview, agent: { id: "a6", name: "Returns", account: "Demo Shop" } } });
    const form = await previewOrderLine();
    fireEvent.click(within(form).getByRole("checkbox"));
    expect((within(form).getByRole("button", { name: "Publish" }) as HTMLButtonElement).disabled).toBe(false);

    fireEvent.change(screen.getByLabelText("Agent"), { target: { value: "a6" } });
    expect(screen.queryByRole("form", { name: "Publish a starter" }), "what was read was the other agent").toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Preview" }));
    const again = await screen.findByRole("form", { name: "Publish a starter" });
    expect((within(again).getByLabelText("Name") as HTMLInputElement).value).toBe("Returns");
    expect((within(again).getByRole("checkbox") as HTMLInputElement).checked).toBe(false);
    expect((within(again).getByRole("button", { name: "Publish" }) as HTMLButtonElement).disabled).toBe(true);

    fireEvent.click(within(again).getByRole("checkbox"));
    fireEvent.click(screen.getByRole("button", { name: "Preview" }));
    await waitFor(() => expect((within(screen.getByRole("form", { name: "Publish a starter" })).getByRole("checkbox") as HTMLInputElement).checked).toBe(false));
  });

  it("keeps the account and agent picked while the preview is read", async () => {
    let answer: (r: Response) => void = () => undefined;
    adminPage({ [`GET ${PREVIEW}`]: () => new Promise<Response>((resolve) => (answer = resolve)) });
    fireEvent.change(await screen.findByLabelText("Account"), { target: { value: "w-shop" } });
    fireEvent.click(screen.getByRole("button", { name: "Preview" }));
    expect(((await screen.findByRole("button", { name: "Reading the agent…" })) as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByLabelText("Account") as HTMLSelectElement).disabled).toBe(true);
    expect((screen.getByLabelText("Agent") as HTMLSelectElement).disabled).toBe(true);
    answer(Response.json(preview));
    expect(await screen.findByRole("form", { name: "Publish a starter" })).toBeTruthy();
    expect((screen.getByLabelText("Agent") as HTMLSelectElement).disabled).toBe(false);
    expect((screen.getByLabelText("Agent") as HTMLSelectElement).value).toBe("a5");
  });

  it("shows a 422's reasons by their fields and a 404 as an alert, and keeps the list as it was", async () => {
    let status = 422;
    const sent = adminPage({
      [`POST ${LIST}`]: () => status === 422
        ? Response.json({ message: "The given data was invalid.", errors: { name: ["The name is taken."], reviewed: ["Read every text first."] } }, { status })
        : Response.json({ message: "Choose an agent in an account you own: a starter is offered to every account." }, { status }),
    });
    const form = await previewOrderLine();
    fireEvent.click(within(form).getByRole("checkbox"));
    fireEvent.click(within(form).getByRole("button", { name: "Publish" }));
    expect(await within(form).findByText("The name is taken.")).toBeTruthy();
    expect(within(form).getByLabelText("Name").getAttribute("aria-invalid")).toBe("true");
    expect(within(form).getByText("Read every text first.")).toBeTruthy();
    expect(within(form).getByRole("checkbox").getAttribute("aria-invalid")).toBe("true");
    expect(within(form).queryByRole("alert")).toBeNull();

    status = 404;
    fireEvent.click(within(form).getByRole("button", { name: "Publish" }));
    expect((await within(form).findByRole("alert")).textContent).toBe("Choose an agent in an account you own: a starter is offered to every account.");
    expect(within(form).queryByText("The name is taken.")).toBeNull();
    expect(sent.filter((s) => s.method === "POST")).toHaveLength(2);
    expect(sent.filter((s) => s.method === "GET" && s.path === LIST)).toHaveLength(1);
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("shows a refused preview as an alert", async () => {
    adminPage({ [`GET ${PREVIEW}`]: () => Response.json({ message: "Choose an agent in an account you own: a starter is offered to every account." }, { status: 404 }) });
    fireEvent.change(await screen.findByLabelText("Account"), { target: { value: "w-shop" } });
    fireEvent.click(screen.getByRole("button", { name: "Preview" }));
    expect((await screen.findByRole("alert")).textContent).toBe("Choose an agent in an account you own: a starter is offered to every account.");
    expect(screen.queryByRole("form", { name: "Publish a starter" })).toBeNull();
  });
});

describe("Admin → Starter agents: who sees it", () => {
  function admin(path: string) {
    render(
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/admin/*" element={<AdminArea />} />
        </Routes>
        <Where />
      </MemoryRouter>,
    );
  }
  const staff = { id: 1, name: "Olu", email: "olu@op.example", role: "admin" };

  it("is in the nav and opens with the starters ability, and is neither without it", async () => {
    fakeKit({ "GET /api/kit/session": { user: { ...staff, abilities: ["starters"] } }, [`GET ${LIST}`]: { starters: [desk], sources } });
    admin("/admin");
    const nav = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(nav).getAllByRole("link").map((a) => [a.textContent, a.getAttribute("href")])).toEqual([["Starter agents", "/admin/starters"]]);
    expect(await screen.findByRole("heading", { level: 1, name: "Starter agents" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/admin/starters");
    cleanup();

    const sent = fakeKit({ "GET /api/kit/session": { user: { ...staff, abilities: ["audit"] } }, [`GET ${LIST}`]: { starters: [desk], sources } });
    admin("/admin/starters");
    const other = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(other).queryByRole("link", { name: "Starter agents" })).toBeNull();
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/admin/audit"));
    expect(screen.queryByRole("heading", { level: 1, name: "Starter agents" })).toBeNull();
    expect(sent.some((s) => s.path === LIST)).toBe(false);
  });
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
  "GET /api/kit/console/team": { members: [], invites: [] },
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
  "GET /api/kit/console/agents/a2/webhooks": { webhooks: [], events: ["call.completed"] },
  "GET /api/kit/console/agents/a2/web-chat": { allowed: false, priced: true, enabled: false, websites: [], title: null, greeting: null, snippet: null },
};

const made: Agent = {
  id: "a2", name: "Dental front desk", description: "Books appointments", systemPrompt: "You are the front desk.", isActive: true,
  stack: { llmProvider: "openai", llmModel: "gpt-5.4-mini", sttProvider: "deepgram", ttsProvider: "openai", ttsVoice: "alloy" },
  settings: agentSettings,
  stackNames: { llmProvider: "OpenAI", llmModel: "GPT-5.4 mini", sttProvider: "Deepgram", ttsProvider: "Studio voices", ttsVoice: "Alloy" },
  stackCheck: null, knowledgeBases: [], logoUrl: null, syncState: "pending", syncError: null, createdAt: "2026-11-08T09:00:00Z", updatedAt: "2026-11-08T09:00:00Z",
};

const offered = { starters: [{ id: "s1", name: "Dental front desk", description: "Books and moves visits.\nAnswers opening hours." }, { id: "s2", name: "Order line", description: null }] };
const USE = "/api/kit/console/starters/s1/agents";

function consolePage(toRedo: AgentCopyToDo[], answers: Record<string, unknown> = {}, entry: string | Location = "/console/agents/new") {
  const sent = fakeKit({
    ...signedIn,
    "GET /api/kit/console/starters": offered,
    [`POST ${USE}`]: () => Response.json({ agent: made, toRedo }, { status: 201 }),
    "GET /api/kit/console/agents/a2": made,
    ...answers,
  });
  render(
    <MemoryRouter initialEntries={[entry]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
  return sent;
}

async function starterRow(id: string): Promise<HTMLElement> {
  await screen.findByRole("list", { name: "Start from a ready-made agent" });
  return document.querySelector(`[data-starter="${id}"]`) as HTMLElement;
}

describe("Console → new agent from a starter", () => {
  it("lists the starters offered, each with its name, description and a Use this button, above the blank form", async () => {
    consolePage([]);
    const section = (await screen.findByRole("heading", { name: "Start from a ready-made agent" })).closest("section") as HTMLElement;
    const first = await starterRow("s1");
    expect(within(first).getByText("Dental front desk")).toBeTruthy();
    expect(first.querySelector(".note-body")?.textContent).toBe("Books and moves visits.\nAnswers opening hours.");
    expect(within(first).getByRole("button", { name: "Use this" })).toBeTruthy();
    const second = await starterRow("s2");
    expect(within(second).getByText("Order line")).toBeTruthy();
    expect(second.querySelector(".note-body")).toBeNull();
    expect(within(section).getAllByRole("button", { name: "Use this" })).toHaveLength(2);
    expect(section.compareDocumentPosition(screen.getByLabelText("Name")) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it.each([
    ["none is offered", () => Response.json({ starters: [] })],
    ["they cannot be read", () => Response.json({ message: "Try again shortly." }, { status: 500 })],
  ])("shows only the blank form when %s", async (_, answer) => {
    const sent = consolePage([], { "GET /api/kit/console/starters": answer });
    expect(await screen.findByRole("heading", { name: "New agent" })).toBeTruthy();
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/console/starters")).toBe(true));
    await act(async () => new Promise((resolve) => setTimeout(resolve, 20)));
    expect(screen.queryByRole("heading", { name: "Start from a ready-made agent" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Use this" })).toBeNull();
    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.getByRole("button", { name: "Create agent" })).toBeTruthy();
  });

  it("sends a POST with no body for the starter used, says so with every button off while it runs, then opens the new agent", async () => {
    let answer: (r: Response) => void = () => undefined;
    const sent = consolePage([], { [`POST ${USE}`]: () => new Promise<Response>((resolve) => (answer = resolve)) });
    fireEvent.click(within(await starterRow("s1")).getByRole("button", { name: "Use this" }));
    const busy = await screen.findByRole("button", { name: "Making your agent…" });
    expect((busy as HTMLButtonElement).disabled).toBe(true);
    expect((within(await starterRow("s2")).getByRole("button", { name: "Use this" }) as HTMLButtonElement).disabled).toBe(true);
    const posts = sent.filter((s) => s.method === "POST");
    expect(posts.map((s) => s.path)).toEqual([USE]);
    expect(posts[0].raw).toBeUndefined();

    answer(Response.json({ agent: made, toRedo: [] }, { status: 201 }));
    expect(await screen.findByRole("heading", { level: 1, name: "Dental front desk" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/agents/a2");
    const notice = (await screen.findByText("Made from the ready-made agent Dental front desk.")).closest("[role=status]") as HTMLElement;
    expect(notice.textContent).toBe("Made from the ready-made agent Dental front desk.");
    expect(screen.queryByRole("list", { name: "Left to do" })).toBeNull();
  });

  it("says on the new agent's page which starter it was made from and what is left to do, and not again on a reload", async () => {
    consolePage([{ kind: "voiceSetup", name: null }, { kind: "flow", name: null }]);
    fireEvent.click(within(await starterRow("s1")).getByRole("button", { name: "Use this" }));
    const notice = (await screen.findByText("Made from the ready-made agent Dental front desk.")).closest("[role=status]") as HTMLElement;
    const list = within(notice).getByRole("list", { name: "Left to do" });
    expect(within(list).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Its voice setup is not offered here, so this agent has the default one. Check it below.",
      "The call steps are a draft: finish them on the Call steps page and publish.",
    ]);
    expect(within(list).getByRole("link", { name: "Call steps page" }).getAttribute("href")).toBe("/console/agents/a2/flow");

    await waitFor(() => expect(now?.state).toBeNull());
    const reloaded = now!;
    expect(reloaded.pathname).toBe("/console/agents/a2");
    cleanup();
    consolePage([], {}, reloaded);
    expect(await screen.findByRole("heading", { level: 1, name: "Dental front desk" })).toBeTruthy();
    expect(await screen.findByRole("button", { name: "Duplicate" })).toBeTruthy();
    expect(screen.queryByText("Made from the ready-made agent Dental front desk.")).toBeNull();
  });

  it.each([
    [404, { message: "There is no such starter agent." }],
    [409, { message: "Finish setting up this account first." }],
    [422, { message: "This starter cannot be made here as it is.", errors: { name: ["The name is taken."] } }],
    [500, { message: "Try again shortly." }],
  ])("shows a %i refusal's message by the button used, and stays on the new-agent page", async (status, body) => {
    const sent = consolePage([], { [`POST ${USE}`]: () => Response.json(body, { status }) });
    const row = await starterRow("s1");
    fireEvent.click(within(row).getByRole("button", { name: "Use this" }));
    expect((await within(row).findByRole("alert")).textContent).toBe(body.message);
    expect((within(row).getByRole("button", { name: "Use this" }) as HTMLButtonElement).disabled).toBe(false);
    expect(within(await starterRow("s2")).queryByRole("alert")).toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: "New agent" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/agents/new");
    expect(sent.some((s) => s.path === "/api/kit/console/agents/a2")).toBe(false);
  });
});
