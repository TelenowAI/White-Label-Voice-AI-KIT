/**
 * Console → Caller memory (memory/): reached from the nav and from the agent page's "remember callers" setting; each remembered caller
 * in the console's words (a phone caller by number, a web chat visitor with the website when there is one, any other as the backend
 * names them), its summary and facts, from how many calls with a link to the last, when updated and until when it is used (or that it
 * no longer is); searching the list in the page; forgetting a listed caller only after asking (its key encoded in the DELETE, its row
 * then gone) and any number typed in (both answers, the list read again); the backend's word on the number beside it, any other
 * refusal as an alert; a member who does not manage the account told so, with no button; a full list said to be the most recent only;
 * empty.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AgentSettingsForm, CallerMemory, ConsoleHome, RememberedCaller, SetupField, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { agentSettings, settingsForm } from "./agentSettings";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "admin", canManageTeam: true, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "admin", current: true }] };
const signedIn = { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one };
const LIST = "/api/kit/console/memory";

function remembered(over: Partial<RememberedCaller> & { key: string }): RememberedCaller {
  return {
    kind: "phone", caller: `+${over.key}`, website: null, summary: null, facts: [], calls: 1, updatedAt: "2026-11-07T10:00:00Z",
    expiresAt: "2027-02-05T10:00:00Z", expired: false, lastCallId: null, ...over,
  };
}

const chatKey = "kit-6f1c0a52-8d3e-4b7a-9c11-2f5e8d0a4b13";
const oldChatKey = "kit-0b7e3d1f-2a4c-4e8b-8f00-9d6c5b4a3e21";
const memory: CallerMemory = {
  callers: [
    remembered({
      key: "14155550123", summary: "Booked a cleaning for Tuesday; prefers mornings.",
      facts: [{ name: "name", value: "Priya" }, { name: "dentist", value: "Dr. Rao" }], calls: 3, lastCallId: "c7",
    }),
    remembered({ key: chatKey, kind: "chat", caller: chatKey, website: "https://www.acme-dental.example", summary: "Asked what whitening costs.", expiresAt: null }),
    remembered({ key: oldChatKey, kind: "chat", caller: oldChatKey, summary: "Wanted the opening hours.", expiresAt: "2026-10-01T00:00:00Z", expired: true }),
    remembered({ key: "crm:778 & co", kind: "other", caller: "crm:778 & co", facts: [{ name: "plan", value: "Gold" }], calls: 2 }),
  ],
  limit: 200,
  canForget: true,
};

function open(path = "/console/memory") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

const bodyRows = async () => (await screen.findAllByRole("row")).slice(1);
const who = () => screen.getAllByRole("row").slice(1).map((r) => within(r).getAllByRole("cell")[0].textContent);
const asked = (sent: { method: string; path: string }[], method: string) => sent.filter((s) => s.method === method && s.path.startsWith(LIST)).map((s) => s.path);

describe("caller memory", () => {
  it("is reached from the console's nav", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}`]: memory });
    open("/console");
    const nav = await screen.findByRole("navigation", { name: "Console" });
    fireEvent.click(within(nav).getByRole("link", { name: "Caller memory" }));
    expect(await screen.findByRole("heading", { name: "Caller memory" })).toBeTruthy();
    expect(await bodyRows()).toHaveLength(4);
  });

  it("says who each caller is, what is remembered, from how many calls, when it was updated and until when it is used", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}`]: memory });
    open();
    const rows = await bodyRows();
    expect(rows.map((r) => within(r).getAllByRole("cell").map((c) => c.textContent)).map((cells) => [cells[0], cells[2], cells[3], cells[4]])).toEqual([
      ["+14155550123", "3 Last call", new Date("2026-11-07T10:00:00Z").toLocaleString(), `Until ${new Date("2027-02-05T10:00:00Z").toLocaleDateString()}`],
      ["Web chat visitor on https://www.acme-dental.example", "1", new Date("2026-11-07T10:00:00Z").toLocaleString(), "Until it is forgotten"],
      ["Web chat visitor", "1", new Date("2026-11-07T10:00:00Z").toLocaleString(), "No longer used on calls"],
      ["crm:778 & co", "2", new Date("2026-11-07T10:00:00Z").toLocaleString(), `Until ${new Date("2027-02-05T10:00:00Z").toLocaleDateString()}`],
    ]);
    const facts = (row: HTMLElement) => [...row.querySelectorAll("dl div")].map((d) => [d.querySelector("dt")?.textContent, d.querySelector("dd")?.textContent]);
    expect(within(rows[0]).getByText("Booked a cleaning for Tuesday; prefers mornings.")).toBeTruthy();
    expect(facts(rows[0])).toEqual([["name", "Priya"], ["dentist", "Dr. Rao"]]);
    expect(within(rows[1]).getByText("Asked what whitening costs.")).toBeTruthy();
    expect(facts(rows[1])).toEqual([]);
    expect(facts(rows[3])).toEqual([["plan", "Gold"]]);
    expect(within(rows[0]).getByRole("link", { name: "Last call" }).getAttribute("href")).toBe("/console/calls/c7");
    expect(rows.slice(1).map((r) => within(r).queryByRole("link"))).toEqual([null, null, null]);
    expect(screen.queryByText(/most recent are shown/)).toBeNull();
  });

  it("searches the list in the page: by who, the summary, a fact's name or value, and a number however it is written", async () => {
    const sent = fakeKit({ ...signedIn, [`GET ${LIST}`]: memory });
    open();
    await bodyRows();
    const search = (text: string) => fireEvent.change(screen.getByLabelText("Search callers, summaries or facts"), { target: { value: text } });
    search("WHITENING");
    expect(who()).toEqual(["Web chat visitor on https://www.acme-dental.example"]);
    search("dentist");
    expect(who()).toEqual(["+14155550123"]);
    search(" gold ");
    expect(who()).toEqual(["crm:778 & co"]);
    search("acme-dental");
    expect(who()).toEqual(["Web chat visitor on https://www.acme-dental.example"]);
    search("web chat");
    expect(who()).toEqual(["Web chat visitor on https://www.acme-dental.example", "Web chat visitor"]);
    search("(415) 555-0123");
    expect(who()).toEqual(["+14155550123"]);
    search("nobody");
    expect(screen.queryByRole("table")).toBeNull();
    expect(screen.getByText("No remembered caller matches.")).toBeTruthy();
    search("");
    expect(who()).toHaveLength(4);
    expect(asked(sent, "GET")).toEqual([LIST]);
  });

  it("forgets a listed caller only after asking, by its key encoded, and its row goes", async () => {
    const sent = fakeKit({ ...signedIn, [`GET ${LIST}`]: memory, [`DELETE ${LIST}?caller=crm%3A778+%26+co`]: { removed: true } });
    open();
    const row = (await bodyRows())[3];
    fireEvent.click(within(row).getByRole("button", { name: "Forget" }));
    expect(within(row).getByText("Forget everything remembered about crm:778 & co? This cannot be undone.")).toBeTruthy();
    fireEvent.click(within(row).getByRole("button", { name: "Cancel" }));
    expect(within(row).queryByText(/Forget everything/)).toBeNull();
    expect(asked(sent, "DELETE")).toEqual([]);
    fireEvent.click(within(row).getByRole("button", { name: "Forget" }));
    fireEvent.click(within(row).getByRole("button", { name: "Yes, forget" }));
    await waitFor(() => expect(who()).toEqual(["+14155550123", "Web chat visitor on https://www.acme-dental.example", "Web chat visitor"]));
    expect(asked(sent, "DELETE")).toEqual([`${LIST}?caller=crm%3A778+%26+co`]);
    expect(screen.getByText("crm:778 & co is forgotten.").getAttribute("role")).toBe("status");
    expect(asked(sent, "GET")).toEqual([LIST]);
  });

  it("forgets a web chat visitor by its key, named as the page names it", async () => {
    const sent = fakeKit({ ...signedIn, [`GET ${LIST}`]: memory, [`DELETE ${LIST}?caller=${chatKey}`]: { removed: true } });
    open();
    const row = (await bodyRows())[1];
    fireEvent.click(within(row).getByRole("button", { name: "Forget" }));
    expect(within(row).getByText("Forget everything remembered about Web chat visitor on https://www.acme-dental.example? This cannot be undone.")).toBeTruthy();
    fireEvent.click(within(row).getByRole("button", { name: "Yes, forget" }));
    await waitFor(() => expect(who()).toHaveLength(3));
    expect(asked(sent, "DELETE")).toEqual([`${LIST}?caller=${chatKey}`]);
  });

  it("keeps the row and says why when forgetting it is refused", async () => {
    const said = "Only someone who manages this account can forget a caller.";
    fakeKit({ ...signedIn, [`GET ${LIST}`]: memory, [`DELETE ${LIST}?caller=14155550123`]: () => Response.json({ message: said }, { status: 403 }) });
    open();
    const row = (await bodyRows())[0];
    fireEvent.click(within(row).getByRole("button", { name: "Forget" }));
    fireEvent.click(within(row).getByRole("button", { name: "Yes, forget" }));
    expect((await screen.findByRole("alert")).textContent).toBe(said);
    expect(who()).toHaveLength(4);
  });

  it("forgets any number typed in, says whether anything was remembered about it, and reads the list again", async () => {
    let forgotten = false;
    const sent = fakeKit({
      ...signedIn,
      [`GET ${LIST}`]: () => Response.json(forgotten ? { ...memory, callers: memory.callers.slice(1) } : memory),
      [`DELETE ${LIST}?caller=%2B1+415+555+0199`]: { removed: false },
      [`DELETE ${LIST}?caller=%2B1+%28415%29+555-0123`]: () => {
        forgotten = true;
        return Response.json({ removed: true });
      },
    });
    open();
    await bodyRows();
    const form = screen.getByRole("form", { name: "Forget a number" });
    fireEvent.change(within(form).getByLabelText("Phone number"), { target: { value: "+1 415 555 0199" } });
    fireEvent.click(within(form).getByRole("button", { name: "Forget" }));
    expect((await screen.findByRole("status")).textContent).toBe("Nothing was remembered about this number.");
    await waitFor(() => expect(asked(sent, "GET")).toEqual([LIST, LIST]));
    expect(who()).toHaveLength(4);

    fireEvent.change(within(form).getByLabelText("Phone number"), { target: { value: "+1 (415) 555-0123" } });
    fireEvent.click(within(form).getByRole("button", { name: "Forget" }));
    await waitFor(() => expect(screen.getByRole("status").textContent).toBe("Forgotten."));
    await waitFor(() => expect(who()).toEqual(["Web chat visitor on https://www.acme-dental.example", "Web chat visitor", "crm:778 & co"]));
    expect(asked(sent, "DELETE")).toEqual([`${LIST}?caller=%2B1+415+555+0199`, `${LIST}?caller=%2B1+%28415%29+555-0123`]);
    expect(asked(sent, "GET")).toEqual([LIST, LIST, LIST]);
  });

  it("shows the backend's word on the number beside it, and any other refusal as an alert", async () => {
    const unavailable = "What your agents remember cannot be read right now. Try again shortly.";
    fakeKit({
      ...signedIn,
      [`GET ${LIST}`]: memory,
      [`DELETE ${LIST}?caller=`]: () => Response.json({ message: "The caller field is required.", errors: { caller: ["The caller field is required."] } }, { status: 422 }),
      [`DELETE ${LIST}?caller=%2B14155550123`]: () => Response.json({ message: unavailable }, { status: 503 }),
    });
    open();
    await bodyRows();
    const form = screen.getByRole("form", { name: "Forget a number" });
    const input = within(form).getByLabelText("Phone number");
    fireEvent.click(within(form).getByRole("button", { name: "Forget" }));
    expect(await within(form).findByText("The caller field is required.")).toBeTruthy();
    expect(input.getAttribute("aria-invalid")).toBe("true");
    expect(screen.queryByRole("alert")).toBeNull();

    fireEvent.change(input, { target: { value: "+14155550123" } });
    fireEvent.click(within(form).getByRole("button", { name: "Forget" }));
    expect((await screen.findByRole("alert")).textContent).toBe(unavailable);
    expect(within(form).queryByText("The caller field is required.")).toBeNull();
    expect(input.getAttribute("aria-invalid")).toBeNull();
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("says so as an alert when the list cannot be read", async () => {
    const unavailable = "What your agents remember cannot be read right now. Try again shortly.";
    fakeKit({ ...signedIn, [`GET ${LIST}`]: () => Response.json({ message: unavailable }, { status: 503 }) });
    open();
    expect((await screen.findByRole("alert")).textContent).toBe(unavailable);
    expect(screen.queryByRole("table")).toBeNull();
    expect(screen.queryByRole("form", { name: "Forget a number" })).toBeNull();
  });

  it("tells a member who does not manage the account that only a manager can forget, and offers no button", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}`]: { ...memory, canForget: false } });
    open();
    await bodyRows();
    expect(screen.getByText("Only someone who manages this account can forget a caller.")).toBeTruthy();
    expect(screen.queryAllByRole("button", { name: /Forget/ })).toEqual([]);
    expect(screen.queryByRole("form", { name: "Forget a number" })).toBeNull();
    expect(screen.queryByRole("columnheader", { name: "Actions" })).toBeNull();
  });

  it("says only the most recent are shown when the list is as long as it gets, and that any number can still be forgotten", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}`]: { ...memory, limit: 4 } });
    open();
    expect(await screen.findByText("Only the 4 most recent are shown. Any number can still be forgotten below.")).toBeTruthy();
    cleanup();
    fakeKit({ ...signedIn, [`GET ${LIST}`]: { ...memory, limit: 4, canForget: false } });
    open();
    expect(await screen.findByText("Only the 4 most recent are shown.")).toBeTruthy();
    expect(screen.queryByText(/can still be forgotten/)).toBeNull();
    cleanup();
    fakeKit({ ...signedIn, [`GET ${LIST}`]: { ...memory, limit: 5 } });
    open();
    await bodyRows();
    expect(screen.queryByText(/most recent are shown/)).toBeNull();
  });

  it("says no one is remembered yet, and still lets a number be forgotten", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}`]: { ...memory, callers: [] } });
    open();
    expect(await screen.findByText("Your agents remember no one yet.")).toBeTruthy();
    expect(screen.queryByRole("table")).toBeNull();
    expect(screen.queryByLabelText("Search callers, summaries or facts")).toBeNull();
    expect(screen.getByRole("form", { name: "Forget a number" })).toBeTruthy();
  });
});

describe("the agent page's remember-callers setting", () => {
  const field = (name: string, label: string, type: SetupField["type"], over: Partial<SetupField> = {}): SetupField => ({
    name, label, type, required: false, value: null, help: null, options: [], showWhen: null, secretSet: false, ...over,
  });
  const form: AgentSettingsForm = {
    ...settingsForm,
    groups: [
      ...settingsForm.groups,
      {
        id: "memory", title: "What it remembers", description: "Whether it remembers callers between calls.",
        fields: [
          field("callerMemory", "Remember callers between calls", "checkbox"),
          field("callerMemoryDays", "Forget a caller after (days)", "number", { showWhen: { field: "callerMemory", values: ["1"] } }),
        ],
      },
    ],
  };
  const frontDesk: Agent = {
    id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true, syncState: "synced", syncError: null,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: agentSettings,
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [], logoUrl: null,
    createdAt: "2026-10-30T09:00:00Z", updatedAt: "2026-10-30T09:00:00Z",
  };

  it("links to what the agents remember, beside that setting only", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": frontDesk,
      "GET /api/kit/console/agents/settings-form": form,
      [`GET ${LIST}`]: memory,
    });
    open("/console/agents/a1");
    const link = await screen.findByRole("link", { name: "See what agents remember" });
    expect(screen.getAllByRole("link", { name: "See what agents remember" })).toHaveLength(1);
    expect(link.getAttribute("href")).toBe("/console/memory");
    expect(link.closest("p")?.previousElementSibling?.querySelector("input")?.getAttribute("name")).toBe("callerMemory");
    fireEvent.click(link);
    expect(await screen.findByRole("heading", { name: "Caller memory" })).toBeTruthy();
    expect(await bodyRows()).toHaveLength(4);
  });
});
