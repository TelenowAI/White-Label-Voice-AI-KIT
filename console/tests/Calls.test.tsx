/**
 * Console → Calls (calls/): the account's calls with when, agent, the other party's number, length and outcome in
 * the console's words; one call with its recording (played through the kit's URL), transcript and analysis; one
 * agent's calls, reached from the agent's page; a call still being completed, a browser call, and an outcome with no
 * words yet, each said plainly; a web chat (channel `chat`) read as one, its user's lines the visitor's. The list narrowed by days (sent
 * with the browser's time zone), outcome and channel, kept in the address and opened from it, the backend's word on a day beside it,
 * and the same calls downloaded as a CSV file (not offered for the member's own calls, which the file cannot narrow to).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Agent, CallDetail, CallSummary, ConsoleHome, WorkspaceList } from "../src/api";
import { durationText } from "../src/calls/callText";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { agentSettings } from "./agentSettings";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
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
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
};
const frontDesk = { id: "a1", name: "Front desk" };

function summary(over: Partial<CallSummary> = {}): CallSummary {
  return {
    id: "c1",
    agent: frontDesk,
    placedBy: null,
    requestedBy: null,
    wrapup: null,
    status: "ended",
    outcome: "completed",
    direction: "inbound",
    channel: "telephony",
    from: "+14155550123",
    to: "+14155550100",
    startedAt: "2026-10-30T11:35:57Z",
    endedAt: "2026-10-30T11:36:47Z",
    durationSec: 50,
    complete: true,
    charge: null,
    ...over,
  };
}

function detail(over: Partial<CallDetail> = {}): CallDetail {
  return {
    ...summary(),
    canListen: false,
    listens: [],
    canTellAgent: false,
    agentNotes: [],
    answeredBy: "human",
    transcript: [
      { role: "assistant", text: "Acme Dental, how can I help?", at: "2026-10-30T11:36:07Z" },
      { role: "user", text: "I would like a cleaning next Tuesday.", at: "2026-10-30T11:36:17Z" },
    ],
    recordings: [{ index: 0, durationSec: null, createdAt: "2026-10-30T11:38:57Z" }],
    analysis: { summary: "Booked a cleaning for Tuesday 10 am.", sentiment: "positive", disposition: "booked", actionItems: ["Send a reminder"],
      customData: { appointment_date: "Tuesday", party_size: 2 }, qa: [{ key: "greeted_the_caller", met: true, evidence: "Hi, thanks for calling." }, { key: "confirmed_the_time", met: false }] },
    ...over,
  };
}

function Where() {
  const l = useLocation();
  return <span data-testid="where">{l.pathname + l.search}</span>;
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

describe("the calls list", () => {
  it("lists the account's calls in the console's words and opens one", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/calls?page=1": {
        calls: [summary({ charge: { amount: "3.42", currency: "INR" } }), summary({ id: "c2", outcome: "no_answer", direction: "outbound", from: null, to: "+14155550177", durationSec: 0 })],
        total: 2, page: 1, perPage: 50,
      },
      "GET /api/kit/console/calls/c1": detail(),
    });
    open("/console");
    fireEvent.click(await screen.findByRole("link", { name: "Calls" }));

    const rows = (await screen.findAllByRole("row")).slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(1).map((c) => c.textContent))).toEqual([
      ["Front desk", "Incoming +14155550123", "0:50", "Answered", "₹3.42"],
      ["Front desk", "Outgoing +14155550177", "0:00", "No answer", ""],
    ]);

    fireEvent.click(within(rows[0]).getByRole("link"));
    expect(await screen.findByRole("heading", { name: "Call with +14155550123" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/calls/c1");
    expect(document.querySelector("audio")?.getAttribute("src")).toBe("/api/kit/console/calls/c1/recordings/0");
    const transcript = screen.getByRole("list", { name: "Transcript" });
    expect(within(transcript).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Agent: Acme Dental, how can I help?",
      "Caller: I would like a cleaning next Tuesday.",
    ]);
    expect(screen.getByText("Booked a cleaning for Tuesday 10 am.")).toBeTruthy();
    expect(screen.getByText("Send a reminder")).toBeTruthy();
    expect(screen.getByText("Appointment date").nextSibling?.textContent).toBe("Tuesday");
    expect(screen.getByText("Party size").nextSibling?.textContent).toBe("2");
    expect(screen.getByText(/Greeted the caller: Met/).textContent).toContain("Hi, thanks for calling.");
    expect(screen.getByText(/Confirmed the time: Not met/)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Front desk" }).getAttribute("href")).toBe("/console/agents/a1");
    expect(screen.getByText("Direction").nextElementSibling?.textContent).toBe("Incoming");
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("says when there are none", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1": { calls: [], total: 0, page: 1, perPage: 50 } });
    open("/console/calls");
    expect(await screen.findByText("No calls yet.")).toBeTruthy();
  });

  it("shows one agent's calls, reached from the agent's page", async () => {
    const agent: Agent = {
      id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true, syncState: "synced", syncError: null,
      stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
      settings: agentSettings,
      stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
      stackCheck: null, knowledgeBases: [], logoUrl: null,
      createdAt: "2026-10-30T09:00:00Z", updatedAt: "2026-10-30T09:00:00Z",
    };
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent,
      "GET /api/kit/console/calls?page=1&agentId=a1": { calls: [summary()], total: 1, page: 1, perPage: 50 },
      "GET /api/kit/console/calls?page=1": { calls: [summary(), summary({ id: "c3", agent: null })], total: 2, page: 1, perPage: 50 },
    });
    open("/console/agents/a1");
    fireEvent.click(await screen.findByRole("link", { name: "See its calls" }));
    expect(await screen.findByRole("heading", { name: "Calls with Front desk" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/calls?agent=a1");
    expect(sent.map((s) => s.path)).toContain("/api/kit/console/calls?page=1&agentId=a1");

    fireEvent.click(screen.getByRole("link", { name: "All calls" }));
    expect(await screen.findByRole("heading", { name: "Calls" })).toBeTruthy();
    expect(await screen.findByText("An agent no longer here")).toBeTruthy();
  });
});

describe("narrowing the calls list", () => {
  /** The browser's time zone in these tests, whatever the machine's. */
  const zone = "Asia/Kolkata";
  const tz = encodeURIComponent(zone);
  // The account's own outcomes, most calls first: the voice platform's words, and an agent's own ("booked").
  const page = (calls: CallSummary[] = [summary()]) => ({ calls, total: calls.length, page: 1, perPage: 50, outcomes: ["completed", "no_answer", "booked"] });
  const download = () => screen.getByRole("link", { name: "Download CSV" });

  beforeEach(() => {
    const real = Intl.DateTimeFormat.prototype.resolvedOptions;
    vi.spyOn(Intl.DateTimeFormat.prototype, "resolvedOptions").mockImplementation(function (this: Intl.DateTimeFormat) {
      return { ...real.call(this), timeZone: zone };
    });
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it("offers the account's own outcomes and the known channels, sends days with the browser's time zone, keeps each filter in the address, and downloads the same calls", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/calls?page=1": page(),
      "GET /api/kit/console/calls?page=1&outcome=no_answer": page(),
      [`GET /api/kit/console/calls?page=1&from=2026-11-01&to=2026-11-07&timeZone=${tz}&outcome=no_answer`]: page(),
      [`GET /api/kit/console/calls?page=1&from=2026-11-01&to=2026-11-07&timeZone=${tz}&outcome=no_answer&channel=chat`]: page(),
    });
    open("/console/calls");
    await screen.findAllByRole("row");
    expect(download().getAttribute("href")).toBe(`/api/kit/console/calls/export?timeZone=${tz}`);
    expect(download().hasAttribute("download")).toBe(true);
    const options = (label: string) => within(screen.getByLabelText(label)).getAllByRole("option").map((o) => o.textContent);
    expect(options("Outcome")).toEqual(["Any", "Answered", "No answer", "booked"]);
    expect(options("Channel")).toEqual(["Any", "Phone call", "Browser call", "Placed by a member", "Web chat"]);

    fireEvent.change(screen.getByLabelText("Outcome"), { target: { value: "no_answer" } });
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/calls?outcome=no_answer"));
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2026-11-01" } });
    fireEvent.change(screen.getByLabelText("To"), { target: { value: "2026-11-07" } });
    fireEvent.click(screen.getByRole("button", { name: "Find" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/calls?outcome=no_answer&from=2026-11-01&to=2026-11-07"));
    fireEvent.change(screen.getByLabelText("Channel"), { target: { value: "chat" } });
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/calls?outcome=no_answer&from=2026-11-01&to=2026-11-07&channel=chat"));

    await waitFor(() => expect(sent.map((s) => s.path).filter((p) => p.startsWith("/api/kit/console/calls?"))).toEqual([
      "/api/kit/console/calls?page=1",
      "/api/kit/console/calls?page=1&outcome=no_answer",
      `/api/kit/console/calls?page=1&from=2026-11-01&to=2026-11-07&timeZone=${tz}&outcome=no_answer`,
      `/api/kit/console/calls?page=1&from=2026-11-01&to=2026-11-07&timeZone=${tz}&outcome=no_answer&channel=chat`,
    ]));
    expect(download().getAttribute("href")).toBe(`/api/kit/console/calls/export?from=2026-11-01&to=2026-11-07&timeZone=${tz}&outcome=no_answer&channel=chat`);
  });

  it("opens with the filters in the address, and All calls clears them", async () => {
    const filtered = `/api/kit/console/calls?page=1&agentId=a1&from=2026-11-01&to=2026-11-07&timeZone=${tz}&outcome=busy&channel=telephony`;
    const sent = fakeKit({ ...signedIn, [`GET ${filtered}`]: page(), "GET /api/kit/console/calls?page=1": page() });
    open("/console/calls?agent=a1&from=2026-11-01&to=2026-11-07&outcome=busy&channel=telephony");
    await screen.findAllByRole("row");
    expect(sent.map((s) => s.path)).toContain(filtered);
    expect((screen.getByLabelText("From") as HTMLInputElement).value).toBe("2026-11-01");
    expect((screen.getByLabelText("To") as HTMLInputElement).value).toBe("2026-11-07");
    expect((screen.getByLabelText("Outcome") as HTMLSelectElement).value).toBe("busy");
    expect((screen.getByLabelText("Channel") as HTMLSelectElement).value).toBe("telephony");
    expect(download().getAttribute("href")).toBe(`/api/kit/console/calls/export?agentId=a1&from=2026-11-01&to=2026-11-07&timeZone=${tz}&outcome=busy&channel=telephony`);

    fireEvent.click(screen.getByRole("link", { name: "All calls" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/calls"));
    expect((screen.getByLabelText("From") as HTMLInputElement).value).toBe("");
    expect((screen.getByLabelText("To") as HTMLInputElement).value).toBe("");
    expect((screen.getByLabelText("Outcome") as HTMLSelectElement).value).toBe("");
    await waitFor(() => expect(sent.map((s) => s.path)).toContain("/api/kit/console/calls?page=1"));
    expect(download().getAttribute("href")).toBe(`/api/kit/console/calls/export?timeZone=${tz}`);
  });

  it("shows the backend's word on a day beside it", async () => {
    fakeKit({
      ...signedIn,
      [`GET /api/kit/console/calls?page=1&from=2026-11-07&to=2026-11-01&timeZone=${tz}`]: () =>
        Response.json({ message: "The given data was invalid.", errors: { to: ["The last day is before the first."] } }, { status: 422 }),
    });
    open("/console/calls?from=2026-11-07&to=2026-11-01");
    expect(await screen.findByText("The last day is before the first.")).toBeTruthy();
    expect(screen.getByLabelText("To").parentElement?.textContent).toContain("The last day is before the first.");
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("offers no file while only the member's own calls are shown: the file cannot be narrowed to them", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1&placedBy=me": page() });
    open("/console/calls?placedBy=me");
    await screen.findAllByRole("row");
    expect(screen.queryByRole("link", { name: "Download CSV" })).toBeNull();
  });
});

describe("a web chat in the call history", () => {
  const chat = { direction: "web", channel: "chat", from: null, to: null, outcome: "completed", charge: { amount: "0.40", currency: "INR" } };

  it("reads as a web chat in the list and on its page, with the visitor's lines and no phone number", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/calls?page=1": { calls: [summary({ id: "c7", ...chat }), summary({ id: "c8", direction: "web", channel: "web_call", from: null, to: null })], total: 2, page: 1, perPage: 50 },
      "GET /api/kit/console/calls/c7": detail({ id: "c7", ...chat, recordings: [], answeredBy: null, analysis: null, transcript: [
        { role: "user", text: "Do you open on Sundays?", at: "2026-11-08T10:00:00Z" },
        { role: "assistant", text: "We open ten to two on Sundays.", at: "2026-11-08T10:00:03Z" },
      ] }),
    });
    open("/console/calls");
    const rows = (await screen.findAllByRole("row")).slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(1, 3).map((c) => c.textContent?.trim()))).toEqual([
      ["Front desk", "Web chat"],
      ["Front desk", "Browser"],
    ]);

    fireEvent.click(within(rows[0]).getByRole("link"));
    expect(await screen.findByRole("heading", { name: "Web chat" })).toBeTruthy();
    expect(screen.getByText("Direction").nextElementSibling?.textContent).toBe("Web chat");
    expect(within(screen.getByRole("list", { name: "Transcript" })).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Visitor: Do you open on Sundays?",
      "Agent: We open ten to two on Sundays.",
    ]);
    expect(screen.queryByText("From")).toBeNull();
    expect(screen.queryByText("To")).toBeNull();
    expect(screen.queryByRole("link", { name: "Every call with this number" })).toBeNull();
    expect(screen.getByText("Charged").nextElementSibling?.textContent).toBe("₹0.40");
  });
});

describe("a call's page", () => {
  it("says when the call is still being completed, a browser call, and an outcome with no words yet", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/calls/c9": detail({
        id: "c9", direction: "web", channel: "web_call", from: null, to: null, outcome: "voicemail_drop", complete: false,
        transcript: [], recordings: [], analysis: null, answeredBy: null,
      }),
    });
    open("/console/calls/c9");
    expect(await screen.findByRole("heading", { name: "Browser call" })).toBeTruthy();
    expect(screen.getByRole("status").textContent).toBe("Still being completed. Recording, transcript and analysis may follow.");
    expect(screen.getByText("voicemail drop")).toBeTruthy();
    expect(screen.getByText("No transcript.")).toBeTruthy();
    expect(document.querySelector("audio")).toBeNull();
    expect(screen.queryByRole("heading", { name: "Analysis" })).toBeNull();
  });

  it("shows the backend's refusal for a call it does not have", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/calls/nope": () => Response.json({ message: "There is no such call." }, { status: 404 }) });
    open("/console/calls/nope");
    expect((await screen.findByRole("alert")).textContent).toBe("There is no such call.");
  });
});

describe("a call's length", () => {
  it.each([
    [0, "0:00"],
    [50, "0:50"],
    [605, "10:05"],
    [3725, "1:02:05"],
    [null, ""],
  ] as const)("%s seconds reads %s", (s, text) => {
    expect(durationText(s)).toBe(text);
  });
});
