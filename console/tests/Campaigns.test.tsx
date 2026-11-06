/**
 * Console → Campaigns (campaigns/): the list in the console's words; making one from the backend's limits (its
 * voicemail choices, default and cap on attempts), every agent page by page and only numbers not being released, the
 * backend's word on a field; one campaign — where it stands (and why a running one is not calling), its settings, who
 * can be called now and the columns its agent uses, adding people from a CSV (too big refused before it is sent),
 * start / pause, cancel and delete only after asking, the people with their outcomes a page at a time; a finished one
 * offers nothing more to do.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, Campaign, CampaignLimits, CampaignTarget, ConsoleHome, PhoneNumber, WorkspaceList } from "../src/api";
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
const limits: CampaignLimits = {
  nameMaxLength: 120, concurrency: { default: 2, max: 50 }, maxAttempts: { default: 3, max: 10 }, retryGapMinutes: { default: 5, min: 1, max: 60 }, machineDetection: ["voicemail", "off", "beep-wait"],
  phoneColumns: ["phone_number", "mobile"], importMaxBytes: 1024 * 1024, importMaxRows: 500,
};
const signedIn = { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one };
const none = { waiting: 0, answered: 0, unanswered: 0, failed: 0 };
const six = { waiting: 1, answered: 2, unanswered: 2, failed: 1 };
const stack = { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null };

function agent(id: string, name: string): Agent {
  return { id, name, description: null, systemPrompt: null, isActive: true, stack, stackNames: stack, settings: agentSettings, stackCheck: null, knowledgeBases: [], syncState: "synced",
    syncError: null, createdAt: "2026-10-30T14:00:00Z", updatedAt: "2026-10-30T14:00:00Z" };
}

function number(id: string, value: string, releasing = false): PhoneNumber {
  return { id, number: value, country: "US", region: null, type: "local", agent: null, monthlyPrice: null, nextRenewalAt: null, releasing,
    syncState: "synced", syncError: null, createdAt: "2026-10-30T14:00:00Z" };
}

function campaign(over: Partial<Campaign> & { id: string }): Campaign {
  return {
    name: "October reminders", agent: { id: "a1", name: "Front desk" }, callerId: { id: "n1", number: "+14155550100" },
    settings: { concurrency: 2, windowStart: "09:00", windowEnd: "18:00", timezone: "Asia/Kolkata", machineDetection: "voicemail", maxAttempts: 2, retryGapMinutes: 5, retryOnNoAnswer: true },
    status: "draft", waiting: null, counts: { people: 0, answered: 0, notReached: 0 }, pushed: { added: 0, duplicates: 0, suppressed: 0, skipped: 0 },
    syncState: "synced", syncError: null, startedAt: null, completedAt: null, createdAt: "2026-10-30T14:00:00Z", ...over,
  };
}

function target(over: Partial<CampaignTarget> & { id: string; phoneNumber: string }): CampaignTarget {
  return { variables: {}, state: "done", outcome: null, attempts: null, completedAt: null, tryingAgain: false, ...over };
}

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function open(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<><ConsoleArea /><Where /></>} />
      </Routes>
    </MemoryRouter>,
  );
}

const rowsOf = async (name: string) => within(await screen.findByRole("table", { name })).getAllByRole("row").slice(1);
const cells = (rows: HTMLElement[]) => rows.map((r) => within(r).getAllByRole("cell").map((c) => c.textContent));

describe("the campaigns", () => {
  it("lists each campaign in the console's words", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/campaigns": { limits, campaigns: [
        campaign({ id: "c1", status: "running", counts: { people: 40, answered: 12, notReached: 5 } }),
        campaign({ id: "c2", name: "Recall", agent: null, status: "saving", syncState: "pending" }),
      ] },
      "GET /api/kit/console/agents?page=1": { agents: [], total: 0, page: 1, perPage: 25 },
      "GET /api/kit/console/numbers": { numbers: [] },
    });
    open("/console/campaigns");
    expect(cells(await rowsOf("Campaigns"))).toEqual([
      ["October reminders", "Front desk", "Calling", "40 people: 12 answered, 5 not reached"],
      ["Recall", "", "Saving…", "0 people: 0 answered, 0 not reached"],
    ]);
    expect(screen.getByRole("link", { name: "October reminders" }).getAttribute("href")).toBe("/console/campaigns/c1");
  });

  it("makes one from the backend's limits, with every agent and only numbers being kept, and the backend's word on a field", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/campaigns": { campaigns: [], limits },
      "GET /api/kit/console/agents?page=1": { agents: [agent("a1", "Front desk")], total: 2, page: 1, perPage: 1 },
      "GET /api/kit/console/agents?page=2": { agents: [agent("a2", "Billing")], total: 2, page: 2, perPage: 1 },
      "GET /api/kit/console/numbers": { numbers: [number("n1", "+14155550100"), number("n2", "+14155550199", true)] },
      "POST /api/kit/console/campaigns": (body: unknown) => (body as { agentId: string }).agentId
        ? Response.json(campaign({ id: "c9" }), { status: 201 })
        : Response.json({ message: "x", errors: { agentId: ["Choose one of your agents."] } }, { status: 422 }),
      "GET /api/kit/console/campaigns/c9": campaign({ id: "c9" }),
      "GET /api/kit/console/campaigns/c9/targets?page=1": { targets: [], total: 0, page: 1, perPage: 50, counts: none },
      "GET /api/kit/console/campaigns/c9/reach": { pending: 0, dialable: 0, onDoNotCall: 0, withoutConsent: 0, blocked: false },
      "GET /api/kit/console/campaigns/c9/variables": { variables: [] },
    });
    open("/console/campaigns");
    expect(await screen.findByText("No campaigns yet. Make one below.")).toBeTruthy();
    const form = await screen.findByRole("form", { name: "New campaign" });
    await waitFor(() => expect(within(form).getByRole("option", { name: "Billing" })).toBeTruthy());
    const caller = within(form).getByLabelText("Caller ID") as HTMLSelectElement;
    await waitFor(() => expect([...caller.options].map((o) => o.textContent)).toEqual(["None", "+14155550100"]));
    const voicemail = within(form).getByLabelText("Voicemail") as HTMLSelectElement;
    expect([...voicemail.options].map((o) => [o.value, o.textContent])).toEqual([["voicemail", "Detect it"], ["off", "Treat as answered"], ["beep-wait", "beep wait"]]);
    const attempts = within(form).getByLabelText("Attempts per person") as HTMLInputElement;
    expect([attempts.value, attempts.max]).toEqual(["3", "10"]);

    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: " Recall " } });
    fireEvent.click(within(form).getByRole("button", { name: "Make campaign" }));
    expect(await within(form).findByText("Choose one of your agents.")).toBeTruthy();
    fireEvent.change(within(form).getByLabelText("Agent"), { target: { value: "a2" } });
    fireEvent.change(caller, { target: { value: "n1" } });
    fireEvent.change(within(form).getByLabelText("Calling from"), { target: { value: "09:30" } });
    fireEvent.change(within(form).getByLabelText("Calling until"), { target: { value: "17:00" } });
    fireEvent.change(within(form).getByLabelText("Time zone"), { target: { value: " Asia/Kolkata " } });
    fireEvent.change(voicemail, { target: { value: "off" } });
    fireEvent.change(attempts, { target: { value: "4" } });
    fireEvent.click(within(form).getByLabelText("Try again when nobody answers"));
    fireEvent.click(within(form).getByRole("button", { name: "Make campaign" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/campaigns/c9"));
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({
      name: "Recall", agentId: "a2", callerId: "n1", windowStart: "09:30", windowEnd: "17:00", timezone: "Asia/Kolkata",
      machineDetection: "off", maxAttempts: 4, retryGapMinutes: 5, concurrency: 2, retryOnNoAnswer: false,
    });
  });
});

describe("a campaign", () => {
  const answers = (c: Campaign, more: Record<string, unknown> = {}) => ({
    ...signedIn,
    "GET /api/kit/console/campaigns": { campaigns: [c], limits },
    [`GET /api/kit/console/campaigns/${c.id}`]: c,
    [`GET /api/kit/console/campaigns/${c.id}/targets?page=1`]: { targets: [], total: 0, page: 1, perPage: 50, counts: none },
    [`GET /api/kit/console/campaigns/${c.id}/reach`]: { pending: 0, dialable: 0, onDoNotCall: 0, withoutConsent: 0, blocked: false },
    [`GET /api/kit/console/campaigns/${c.id}/variables`]: { variables: [] },
    ...more,
  });

  it("shows a draft's settings and live reads, adds people from a CSV (too big never sent) and starts it", async () => {
    const draft = campaign({ id: "c1" });
    const sent = fakeKit(answers(draft, {
      "GET /api/kit/console/campaigns/c1/variables": { variables: [{ name: "first_name", required: true }, { name: "due_date", required: false }] },
      "POST /api/kit/console/campaigns/c1/targets": (body: unknown) => (body as { csv: string }).csv.startsWith("phone_number")
        ? Response.json({ added: 2, skipped: 1 })
        : Response.json({ message: "x", errors: { csv: ["The file needs a column named phone_number or mobile."] } }, { status: 422 }),
      "POST /api/kit/console/campaigns/c1/start": campaign({ id: "c1", status: "queued" }),
    }));
    open("/console/campaigns/c1");
    expect(await screen.findByRole("heading", { name: "October reminders" })).toBeTruthy();
    expect(screen.getByRole("status").textContent).toBe("Draft");
    const facts = screen.getByRole("link", { name: "Front desk" }).closest("dl")!;
    expect([...facts.querySelectorAll("dd")].map((d) => d.textContent)).toEqual(["Front desk", "+14155550100", "09:00 to 18:00 (Asia/Kolkata)", "Detect it", "2", "5 min · Tries again when nobody answers", "2"]);
    expect(await screen.findByText("Columns its agent uses: first_name, due_date.")).toBeTruthy();
    expect(screen.getByText("Nobody to call yet. Add people from a CSV file.")).toBeTruthy();
    expect(screen.getByText(/in a column named phone_number or mobile;.* Up to 500 people and 1 MB a file\./)).toBeTruthy();

    const file = screen.getByLabelText("Call list (CSV file)") as HTMLInputElement;
    fireEvent.change(file, { target: { files: [new File([new Uint8Array(limits.importMaxBytes + 1)], "big.csv")] } });
    expect((await screen.findByRole("alert")).textContent).toBe("That file is over 1 MB.");
    expect(sent.some((s) => s.method === "POST")).toBe(false);
    fireEvent.change(file, { target: { files: [new File(["tel\n+14155550101\n"], "wrong.csv")] } });
    expect((await screen.findByRole("alert")).textContent).toBe("The file needs a column named phone_number or mobile.");
    const csv = "phone_number,first_name\n+14155550101,Ana\n+14155550102,Ben\n,Nobody\n";
    fireEvent.change(file, { target: { files: [new File([csv], "people.csv", { type: "text/csv" })] } });
    expect(await screen.findByText("2 added, 1 skipped (no number).")).toBeTruthy();
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({ csv });

    expect(screen.queryByRole("button", { name: "Pause" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Start" }));
    await waitFor(() => expect(screen.getAllByRole("status")[0].textContent).toBe("Queued"));
    expect(sent.filter((s) => s.method === "POST").map((s) => s.path)).toContain("/api/kit/console/campaigns/c1/start");
  });

  it("says why a running one is not calling, shows each person's outcome a page at a time, and pauses it", async () => {
    const opensAt = "2026-10-31T03:30:00Z";
    const running = campaign({ id: "c1", status: "running", waiting: { reason: "window", opensAt }, counts: { people: 3, answered: 1, notReached: 1 },
      pushed: { added: 3, duplicates: 1, suppressed: 1, skipped: 0 } });
    const sent = fakeKit(answers(running, {
      "GET /api/kit/console/campaigns/c1/targets?page=1": { total: 6, counts: six, page: 1, perPage: 5, targets: [
        target({ id: "t1", phoneNumber: "+14155550101", variables: { first_name: "Ana" }, outcome: "answered", attempts: 1 }),
        target({ id: "t2", phoneNumber: "+14155550102", outcome: "no-answer", attempts: 2 }),
        target({ id: "t3", phoneNumber: "+14155550103", outcome: "machine", attempts: 1 }),
        target({ id: "t4", phoneNumber: "+14155550104", outcome: "left-early", attempts: 1 }),
        target({ id: "t5", phoneNumber: "+14155550105", state: "waiting" }),
      ] },
      "GET /api/kit/console/campaigns/c1/targets?page=2": { total: 6, counts: six, page: 2, perPage: 5, targets: [target({ id: "t6", phoneNumber: "+14155550106", state: "saving" })] },
      "GET /api/kit/console/campaigns/c1/reach": { pending: 4, dialable: 2, onDoNotCall: 1, withoutConsent: 1, blocked: false },
      "POST /api/kit/console/campaigns/c1/pause": { ...running, status: "paused", waiting: null },
    }));
    open("/console/campaigns/c1");
    expect((await screen.findByRole("status")).textContent).toBe(`CallingWaiting for its calling window, which opens ${new Date(opensAt).toLocaleString()}.`);
    expect(screen.getByText("3 people: 1 answered, 1 not reached")).toBeTruthy();
    expect(screen.getByText("3 added, 1 on the do-not-call list, 0 without a number, 1 already on the list.")).toBeTruthy();
    expect(await screen.findByText("2 of 4 can be called now: 1 on the do-not-call list, 1 without consent.")).toBeTruthy();
    expect(cells(await rowsOf("People"))).toEqual([
      ["+14155550101", "Answered", "first_name: Ana"],
      ["+14155550102", "No answer · 2 attempts", ""],
      ["+14155550103", "Voicemail", ""],
      ["+14155550104", "left early", ""],
      ["+14155550105", "Waiting", ""],
    ]);
    fireEvent.click(screen.getByRole("button", { name: "Next" }));
    await waitFor(async () => expect(cells(await rowsOf("People"))).toEqual([["+14155550106", "Saving…", ""]]));
    expect(screen.getByText("Page 2 of 2")).toBeTruthy();

    expect(screen.queryByRole("button", { name: "Start" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Pause" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Resume" })).toBeTruthy());
    expect(sent.filter((s) => s.method === "POST").map((s) => s.path)).toEqual(["/api/kit/console/campaigns/c1/pause"]);
  });

  it("cancels and deletes only after asking", async () => {
    const paused = campaign({ id: "c1", status: "paused" });
    const sent = fakeKit(answers(paused, {
      "POST /api/kit/console/campaigns/c1/cancel": campaign({ id: "c1", status: "cancelled" }),
      "DELETE /api/kit/console/campaigns/c1": () => new Response(null, { status: 204 }),
    }));
    open("/console/campaigns/c1");
    fireEvent.click(await screen.findByRole("button", { name: "Cancel campaign" }));
    expect(screen.getByText("Cancel October reminders? People not called yet will not be called.")).toBeTruthy();
    expect(sent.some((s) => s.method === "POST")).toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Yes, cancel it" }));
    await waitFor(() => expect(screen.getByRole("status").textContent).toBe("Cancelled"));
    expect(screen.queryByRole("button", { name: "Resume" })).toBeNull();
    expect(screen.queryByLabelText("Call list (CSV file)")).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "Delete campaign" }));
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    const ask = screen.getByText("Delete October reminders? A running campaign stops first.").parentElement!;
    fireEvent.click(within(ask).getByRole("button", { name: "Yes, delete" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/campaigns"));
    expect(sent.filter((s) => s.method !== "GET").map((s) => `${s.method} ${s.path}`)).toEqual([
      "POST /api/kit/console/campaigns/c1/cancel", "DELETE /api/kit/console/campaigns/c1",
    ]);
  });

  it("offers nothing more on a finished one, and reads nothing live for it", async () => {
    const sent = fakeKit(answers(campaign({ id: "c1", status: "completed", settings: { ...campaign({ id: "x" }).settings, windowStart: null, windowEnd: null } })));
    open("/console/campaigns/c1");
    expect((await screen.findByRole("status")).textContent).toBe("Finished");
    expect(screen.getByText("Any time")).toBeTruthy();
    for (const name of ["Start", "Resume", "Pause", "Cancel campaign"]) expect(screen.queryByRole("button", { name })).toBeNull();
    expect(screen.queryByLabelText("Call list (CSV file)")).toBeNull();
    expect(screen.getByRole("button", { name: "Delete campaign" })).toBeTruthy();
    expect(sent.some((s) => s.path.endsWith("/reach") || s.path.endsWith("/variables"))).toBe(false);
  });

  it("says a campaign the voice platform refused failed, and why", async () => {
    fakeKit(answers(campaign({ id: "c1", status: "failed", syncState: "failed", syncError: "This campaign could not be saved. Ask support." })));
    open("/console/campaigns/c1");
    expect((await screen.findByRole("status")).textContent).toBe("FailedThis campaign could not be saved. Ask support.");
    expect(screen.queryByRole("button", { name: "Cancel campaign" })).toBeNull();
  });
});
