/**
 * Console → Insights (insights/): reached from the nav; asks with the browser's time zone and shows the period the backend answered
 * back in the form; the figures (calls, answered with its share, minutes, an answered call's average length, what was charged in each
 * currency); outcomes, sentiments and channels with counts and shares in the console's words (an unknown one made readable, none as
 * "None"); each day as a bar that says its day and figures, and as a table; each agent, whose name opens its calls of those days;
 * another period and agent asked for; the backend's word on a field beside it, any other refusal as an alert; loading and empty.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Agent, ConsoleHome, Insights, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { InsightsPage } from "../src/insights/InsightsPage";
import { agentSettings } from "./agentSettings";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

/** The browser's time zone in these tests, whatever the machine's. */
const zone = "Asia/Kolkata";

beforeEach(() => {
  const real = Intl.DateTimeFormat.prototype.resolvedOptions;
  vi.spyOn(Intl.DateTimeFormat.prototype, "resolvedOptions").mockImplementation(function (this: Intl.DateTimeFormat) {
    return { ...real.call(this), timeZone: zone };
  });
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const frontDesk: Agent = {
  id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true, syncState: "synced", syncError: null,
  stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
  settings: agentSettings,
  stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
  stackCheck: null, knowledgeBases: [], logoUrl: null,
  createdAt: "2026-10-30T09:00:00Z", updatedAt: "2026-10-30T09:00:00Z",
};
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/agents?page=1": { agents: [frontDesk], total: 1, page: 1, perPage: 50 },
};
const asked = `/api/kit/console/insights?timeZone=${encodeURIComponent(zone)}`;

const period: Insights = {
  from: "2026-11-01", to: "2026-11-03", timeZone: zone,
  calls: 8, answered: 6, seconds: 1530, averageSeconds: 215,
  outcomes: [{ key: "answered", count: 6 }, { key: "no-answer", count: 1 }, { key: null, count: 1 }],
  sentiments: [{ key: "positive", count: 3 }, { key: "negative", count: 1 }],
  channels: [{ key: "telephony", count: 5 }, { key: "chat", count: 2 }, { key: "fax_line", count: 1 }],
  spend: [{ currency: "INR", amount: "42.50" }, { currency: "USD", amount: "1.20" }],
  days: [
    { day: "2026-11-01", calls: 2, answered: 1, seconds: 300 },
    { day: "2026-11-02", calls: 0, answered: 0, seconds: 0 },
    { day: "2026-11-03", calls: 6, answered: 5, seconds: 1230 },
  ],
  agents: [
    { agentId: "a1", name: "Front desk", calls: 6, answered: 5, seconds: 1230, spend: [{ currency: "INR", amount: "42.50" }] },
    { agentId: null, name: null, calls: 1, answered: 1, seconds: 240, spend: [{ currency: "USD", amount: "1.20" }] },
    { agentId: "a9", name: null, calls: 1, answered: 0, seconds: 60, spend: [] },
  ],
};

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

/** A table's body rows, each as its cells' text (the row's heading first). */
function rows(name: string): string[][] {
  const table = screen.getByRole("table", { name });
  return within(table).getAllByRole("row").slice(1).map((r) => [...r.querySelectorAll("th, td")].map((c) => c.textContent ?? ""));
}

describe("insights", () => {
  it("opens from the nav, asks with the browser's time zone, and shows the figures of the period it answered", async () => {
    const sent = fakeKit({ ...signedIn, [`GET ${asked}`]: period });
    open("/console");
    fireEvent.click(await screen.findByRole("link", { name: "Insights" }));
    expect(await screen.findByText("Average answered call")).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/insights");
    expect(sent.filter((s) => s.path.startsWith("/api/kit/console/insights")).map((s) => s.path)).toEqual([asked]);

    expect((screen.getByLabelText("From") as HTMLInputElement).value).toBe("2026-11-01");
    expect((screen.getByLabelText("To") as HTMLInputElement).value).toBe("2026-11-03");
    expect(screen.getByText(`How your calls went, over whole days in your time zone (${zone}).`)).toBeTruthy();
    const figures = Object.fromEntries([...document.querySelectorAll("dl.facts > div")].map((d) => [d.querySelector("dt")?.textContent, d.querySelector("dd")?.textContent]));
    expect(figures).toEqual({
      Calls: "8",
      Answered: "6 (75%)",
      Minutes: "26",
      "Average answered call": "3:35",
      Charged: "₹42.50 · $1.20",
    });
  });

  it("counts outcomes, sentiments and channels with their shares in the console's words", async () => {
    fakeKit({ ...signedIn, [`GET ${asked}`]: period });
    open("/console/insights");
    await screen.findByRole("table", { name: "Outcomes" });
    expect(rows("Outcomes")).toEqual([["Answered", "6", "75%"], ["No answer", "1", "12.5%"], ["None", "1", "12.5%"]]);
    // A share of the calls with an analysis, not of every call.
    expect(rows("Sentiment")).toEqual([["Positive", "3", "75%"], ["Negative", "1", "25%"]]);
    expect(screen.getByText("Of the calls with an analysis.")).toBeTruthy();
    expect(rows("Channels")).toEqual([["Phone call", "5", "62.5%"], ["Web chat", "2", "25%"], ["fax line", "1", "12.5%"]]);
  });

  it("draws each day as a bar that says its day and figures, the busiest the tallest, and lists the days as a table", async () => {
    // A day comes already in the viewer's time zone: it reads as that same day west of UTC too.
    const machineZone = process.env.TZ;
    process.env.TZ = "America/Los_Angeles";
    try {
      fakeKit({ ...signedIn, [`GET ${asked}`]: period });
      open("/console/insights");
      const bars = within(await screen.findByRole("list", { name: "Each day" })).getAllByRole("listitem");
      const labels = ["Nov 1, 2026: calls 2, answered 1", "Nov 2, 2026: calls 0, answered 0", "Nov 3, 2026: calls 6, answered 5"];
      expect(bars.map((b) => b.textContent)).toEqual(labels);
      expect(bars.map((b) => b.getAttribute("title"))).toEqual(labels);
      const heights = bars.map((b) => (b.querySelector(".day-bar") as HTMLElement).style.height);
      expect(heights[2]).toBe("100%");
      expect(heights[1]).toBe("0%");
      expect(parseFloat(heights[0])).toBeCloseTo(100 / 3);
      expect((bars[0].querySelector(".day-bar-answered") as HTMLElement).style.height).toBe("50%");
      expect(rows("Each day")).toEqual([["Nov 1, 2026", "2", "1", "5"], ["Nov 2, 2026", "0", "0", "0"], ["Nov 3, 2026", "6", "5", "21"]]);
    } finally {
      if (machineZone === undefined) delete process.env.TZ;
      else process.env.TZ = machineZone;
    }
  });

  it("lists each agent, and an agent's name opens its calls of those days", async () => {
    const sent = fakeKit({
      ...signedIn,
      [`GET ${asked}`]: period,
      [`GET /api/kit/console/calls?page=1&agentId=a1&from=2026-11-01&to=2026-11-03&timeZone=${encodeURIComponent(zone)}`]: { calls: [], total: 0, page: 1, perPage: 50 },
    });
    open("/console/insights");
    await screen.findByRole("table", { name: "Agents" });
    expect(rows("Agents")).toEqual([
      ["Front desk", "6", "5", "21", "₹42.50"],
      ["No agent", "1", "1", "4", "$1.20"],
      ["An agent no longer here", "1", "0", "1", ""],
    ]);
    const agents = screen.getByRole("table", { name: "Agents" });
    expect(within(agents).getAllByRole("link").map((a) => a.getAttribute("href"))).toEqual([
      "/console/calls?agent=a1&from=2026-11-01&to=2026-11-03",
      "/console/calls?agent=a9&from=2026-11-01&to=2026-11-03",
    ]);
    fireEvent.click(within(agents).getByRole("link", { name: "Front desk" }));
    expect(await screen.findByText("No calls yet.")).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/calls?agent=a1&from=2026-11-01&to=2026-11-03");
    expect(sent.map((s) => s.path)).toContain(`/api/kit/console/calls?page=1&agentId=a1&from=2026-11-01&to=2026-11-03&timeZone=${encodeURIComponent(zone)}`);
    expect((screen.getByLabelText("From") as HTMLInputElement).value).toBe("2026-11-01");
    expect((screen.getByLabelText("To") as HTMLInputElement).value).toBe("2026-11-03");
  });

  it("asks for other days and one agent, with the time zone", async () => {
    const other = `/api/kit/console/insights?from=2026-10-01&to=2026-10-31&timeZone=${encodeURIComponent(zone)}&agentId=a1`;
    const sent = fakeKit({
      ...signedIn,
      [`GET ${asked}`]: period,
      [`GET ${other}`]: { ...period, from: "2026-10-01", to: "2026-10-31", calls: 0, answered: 0, seconds: 0, averageSeconds: 0, outcomes: [], sentiments: [], channels: [], spend: [], days: [], agents: [] },
    });
    open("/console/insights");
    await screen.findByRole("table", { name: "Agents" });
    await screen.findByRole("option", { name: "Front desk" });
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2026-10-01" } });
    fireEvent.change(screen.getByLabelText("To"), { target: { value: "2026-10-31" } });
    fireEvent.change(screen.getByLabelText("Agent"), { target: { value: "a1" } });
    fireEvent.click(screen.getByRole("button", { name: "Show" }));
    expect(await screen.findByText("No calls in these days.")).toBeTruthy();
    expect(sent.map((s) => s.path)).toContain(other);
    expect(screen.queryByRole("table")).toBeNull();
    expect(screen.queryByText("Average answered call")).toBeNull();
  });

  it("shows the backend's word on a day beside it, and on the time zone as an alert", async () => {
    const tooLong = `/api/kit/console/insights?from=2020-01-01&to=2026-11-03&timeZone=${encodeURIComponent(zone)}`;
    fakeKit({
      ...signedIn,
      [`GET ${asked}`]: period,
      [`GET ${tooLong}`]: () => Response.json({ message: "The given data was invalid.", errors: { to: ["At most 366 days."], timeZone: ["The time zone is not one."] } }, { status: 422 }),
    });
    open("/console/insights");
    await screen.findByRole("table", { name: "Agents" });
    fireEvent.change(screen.getByLabelText("From"), { target: { value: "2020-01-01" } });
    fireEvent.click(screen.getByRole("button", { name: "Show" }));
    expect((await screen.findByRole("alert")).textContent).toBe("The time zone is not one.");
    expect(screen.getByLabelText("To").parentElement?.textContent).toContain("At most 366 days.");
    expect(screen.getByLabelText("From").parentElement?.textContent).not.toContain("At most 366 days.");
  });

  it("shows any other refusal as an alert", async () => {
    fakeKit({ ...signedIn, [`GET ${asked}`]: () => Response.json({ message: "The platform is not reachable just now." }, { status: 503 }) });
    open("/console/insights");
    expect((await screen.findByRole("alert")).textContent).toBe("The platform is not reachable just now.");
    expect(screen.queryByRole("table")).toBeNull();
  });

  it("says it is loading until the period arrives", async () => {
    const pending: { answer?: (r: Response) => void } = {};
    fakeKit({ "GET /api/kit/console/agents?page=1": { agents: [], total: 0, page: 1, perPage: 50 }, [`GET ${asked}`]: () => new Promise<Response>((resolve) => { pending.answer = resolve; }) });
    render(<MemoryRouter><InsightsPage /></MemoryRouter>);
    expect(screen.getByText("Loading…")).toBeTruthy();
    expect((screen.getByRole("button", { name: "Show" }) as HTMLButtonElement).disabled).toBe(true);
    await waitFor(() => expect(pending.answer).toBeTypeOf("function"));
    pending.answer?.(Response.json(period));
    expect(await screen.findByText("Average answered call")).toBeTruthy();
    expect(screen.queryByText("Loading…")).toBeNull();
    expect((screen.getByRole("button", { name: "Show" }) as HTMLButtonElement).disabled).toBe(false);
  });
});
