/**
 * Console → one campaign, what can be done after it is made (campaigns/CampaignSettingsForm.tsx, CampaignPeople.tsx, CampaignProgress.tsx): changing its settings with the backend's word beside a field, looking at one group of
 * its people, downloading what is showing, calling people again only after asking and being told how many, and how it went only when asked for. Not here: what the backend does (CampaignExtrasTest.php).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Campaign, CampaignLimits, CampaignProgress, CampaignTarget, PhoneNumber } from "../src/api";
import { CampaignPage } from "../src/campaigns/CampaignPage";
import { CampaignsPage } from "../src/campaigns/CampaignsPage";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const root = "/api/kit/console/campaigns/c1";
const limits: CampaignLimits = { nameMaxLength: 120, concurrency: { default: 2, max: 50 }, maxAttempts: { default: 3, max: 10 }, retryGapMinutes: { default: 5, min: 1, max: 60 }, machineDetection: ["off", "voicemail", "hangup"], phoneColumns: ["phone_number"], importMaxBytes: 1024, importMaxRows: 10, linksAllowed: false, linkEveryMinutes: 15 };
const campaign = (over: Partial<Campaign> = {}): Campaign => ({
  id: "c1", name: "Recall", agent: { id: "a1", name: "Front desk" }, callerId: { id: "n1", number: "+14155550100" },
  settings: { concurrency: 2, windowStart: "09:00", windowEnd: "18:00", timezone: "UTC", machineDetection: "voicemail", maxAttempts: 2, retryGapMinutes: 5, retryOnNoAnswer: true },
  status: "running", waiting: null, counts: { people: 3, answered: 1, notReached: 2 }, pushed: { added: 3, duplicates: 0, suppressed: 0, skipped: 0 }, syncState: "synced", syncError: null, source: null, startedAt: null, completedAt: null, createdAt: "2026-11-04T09:00:00Z", ...over,
});
const person = (over: Partial<CampaignTarget> = {}): CampaignTarget => ({ id: "t1", phoneNumber: "+14155550101", variables: {}, state: "done", outcome: "no-answer", attempts: 1, completedAt: "2026-11-04T09:00:00Z", tryingAgain: false, ...over });
const numbers: PhoneNumber[] = [
  { id: "n1", number: "+14155550100", country: "US", region: null, type: "local", agent: null, monthlyPrice: null, nextRenewalAt: null, releasing: false, routed: false, agentCallerId: false, unanswered: "message", syncState: "synced", syncError: null, createdAt: "2026-10-30T14:00:00Z" },
  { id: "n2", number: "+14155550111", country: "US", region: null, type: "local", agent: null, monthlyPrice: null, nextRenewalAt: null, releasing: false, routed: false, agentCallerId: false, unanswered: "message", syncState: "synced", syncError: null, createdAt: "2026-10-30T14:00:00Z" },
  { id: "n3", number: "+14155550122", country: "US", region: null, type: "local", agent: null, monthlyPrice: null, nextRenewalAt: null, releasing: true, routed: false, agentCallerId: false, unanswered: "message", syncState: "synced", syncError: null, createdAt: "2026-10-30T14:00:00Z" },
];
const counts = { waiting: 1, answered: 2, unanswered: 3, failed: 4 };
const progress: CampaignProgress = {
  people: 8, called: 7, reached: 2, notReached: 5, waiting: 1, onDoNotCall: 3, skipped: 0, whyNotReached: { noAnswer: 11, busy: 12, voicemail: 13, couldNotConnect: 14 },
  talk: { totalSeconds: 82, averageSeconds: 41, nobodySpoke: 21, under15: 22, under30: 23, under60: 24, under120: 25, over120: 26 }, reachedOnAttempt: { first: 31, second: 32, thirdOrLater: 33 }, calledLastHour: 9,
  byHour: { timezone: "Europe/London", called: [0, 0, 0, 0, 0, 0, 0, 0, 0, 7, ...Array(14).fill(0)], reached: [0, 0, 0, 0, 0, 0, 0, 0, 0, 2, ...Array(14).fill(0)] },
};

type Route_ = unknown | ((body: never, url: string) => unknown);

function fake(routes: Record<string, Route_>) {
  const sent: { method: string; path: string; body: Record<string, unknown> }[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? "GET";
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    sent.push({ method, path: String(url), body });
    const all: Record<string, Route_> = {
      [`GET ${root}`]: campaign(), "GET /api/kit/console/campaigns": { campaigns: [], limits }, "GET /api/kit/console/numbers": { numbers }, [`GET ${root}/reach`]: { pending: 0, dialable: 0, onDoNotCall: 0, withoutConsent: 0, blocked: false }, [`GET ${root}/variables`]: { variables: [] },
      [`GET ${root}/targets?page=1`]: { targets: [person()], total: 10, page: 1, perPage: 50, counts }, ...routes,
    };
    const route = all[`${method} ${url}`];
    if (route === undefined) return new Response(JSON.stringify({ message: `no ${method} ${url}` }), { status: 404 });
    const answer = typeof route === "function" ? (route as (b: unknown, u: string) => unknown)(body, String(url)) : route;
    return answer instanceof Response ? answer : Response.json(answer);
  }));
  return sent;
}

const show = async () => {
  render(<MemoryRouter initialEntries={["/console/campaigns/c1"]}><Routes><Route path="/console/campaigns/:id" element={<CampaignPage />} /></Routes></MemoryRouter>);
  await screen.findByRole("heading", { name: "Recall" });
};

const refused = (status: number, message: string, errors?: Record<string, string[]>) => new Response(JSON.stringify({ message, errors }), { status });

describe("changing a campaign's settings", () => {
  it("opens with what it has now, offers only numbers being kept, and sends what was changed with the rest", async () => {
    const sent = fake({ [`PATCH ${root}`]: () => campaign({ settings: { ...campaign().settings, maxAttempts: 4, retryGapMinutes: 10 }, callerId: { id: "n2", number: "+14155550111" } }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Change settings" }));
    const form = screen.getByRole("form", { name: "Settings" });
    await waitFor(() => expect(within(form).getByRole("option", { name: "+14155550111" })).toBeTruthy());
    expect(within(form).queryByRole("option", { name: "+14155550122" })).toBeNull();
    expect((within(form).getByLabelText("Attempts per person") as HTMLInputElement).value).toBe("2");
    expect((within(form).getByLabelText("Calling from") as HTMLInputElement).value).toBe("09:00");
    expect((within(form).getByLabelText("Minutes before trying again") as HTMLInputElement).value).toBe("5");
    expect((within(form).getByLabelText("Calls at once") as HTMLInputElement).value).toBe("2");
    fireEvent.change(within(form).getByLabelText("Attempts per person"), { target: { value: "4" } });
    fireEvent.change(within(form).getByLabelText("Minutes before trying again"), { target: { value: "10" } });
    fireEvent.change(within(form).getByLabelText("Calls at once"), { target: { value: "7" } });
    fireEvent.change(within(form).getByLabelText("Caller ID"), { target: { value: "n2" } });
    fireEvent.change(within(form).getByLabelText("Calling from"), { target: { value: "" } });
    fireEvent.change(within(form).getByLabelText("Calling until"), { target: { value: "" } });
    fireEvent.click(within(form).getByRole("checkbox", { name: "Try again when nobody answers" }));
    fireEvent.click(within(form).getByRole("button", { name: "Save settings" }));
    expect(await screen.findByText("Saved. It applies to the next calls.")).toBeTruthy();
    expect(sent.find((x) => x.method === "PATCH")?.body).toEqual({ callerId: "n2", windowStart: null, windowEnd: null, timezone: "UTC", machineDetection: "voicemail", maxAttempts: 4, retryGapMinutes: 10, concurrency: 7, retryOnNoAnswer: false });
    expect(screen.queryByRole("form", { name: "Settings" })).toBeNull();
    expect(screen.getByText("+14155550111")).toBeTruthy();
    expect(screen.getByText(/10 min/)).toBeTruthy();
  });

  it("clears a caller ID when none is chosen", async () => {
    const sent = fake({ [`PATCH ${root}`]: () => campaign({ callerId: null }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Change settings" }));
    fireEvent.change(screen.getByLabelText("Caller ID"), { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "Save settings" }));
    await screen.findByText("Saved. It applies to the next calls.");
    expect(sent.find((x) => x.method === "PATCH")?.body.callerId).toBeNull();
  });

  it("puts the backend's word beside its field and keeps what was typed", async () => {
    fake({ [`PATCH ${root}`]: () => refused(422, "x", { maxAttempts: ["At most 10."], windowStart: ["Both or neither."] }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Change settings" }));
    const form = screen.getByRole("form", { name: "Settings" });
    fireEvent.change(within(form).getByLabelText("Attempts per person"), { target: { value: "99" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save settings" }));
    expect(await within(form).findByText("At most 10.")).toBeTruthy();
    expect(within(form).getByText("Both or neither.")).toBeTruthy();
    expect((within(form).getByLabelText("Attempts per person") as HTMLInputElement).value).toBe("99");
  });

  it("says when it is refused whole, and goes back without saving when asked", async () => {
    const sent = fake({ [`PATCH ${root}`]: () => refused(409, "Those settings could not be saved. Nothing was changed.") });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Change settings" }));
    const form = screen.getByRole("form", { name: "Settings" });
    fireEvent.click(within(form).getByRole("button", { name: "Save settings" }));
    expect((await within(form).findByRole("alert")).textContent).toContain("could not be saved");
    fireEvent.click(within(form).getByRole("button", { name: "Cancel" }));
    expect(screen.queryByRole("form", { name: "Settings" })).toBeNull();
    expect(sent.filter((x) => x.method === "PATCH")).toHaveLength(1);
  });

  it("says when it has not gone through yet", async () => {
    fake({ [`PATCH ${root}`]: () => campaign({ syncState: "pending" }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Change settings" }));
    fireEvent.click(screen.getByRole("button", { name: "Save settings" }));
    expect(await screen.findByText("Not saved yet. It will be tried again shortly.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Change settings" })).toBeNull();
  });

  it("offers it on a finished campaign but not a cancelled one, nor one still being made", async () => {
    fake({ [`GET ${root}`]: campaign({ status: "completed" }) });
    await show();
    expect(await screen.findByRole("button", { name: "Change settings" })).toBeTruthy();
    cleanup();
    fake({ [`GET ${root}`]: campaign({ status: "cancelled" }) });
    await show();
    await screen.findByText(/9:00|09:00/);
    expect(screen.queryByRole("button", { name: "Change settings" })).toBeNull();
    cleanup();
    fake({ [`GET ${root}`]: campaign({ status: "saving" }) });
    await show();
    await screen.findByText(/09:00/);
    expect(screen.queryByRole("button", { name: "Change settings" })).toBeNull();
  });

  it("reads each setting on the page in words", async () => {
    fake({ [`GET ${root}`]: campaign({ settings: { ...campaign().settings, retryOnNoAnswer: false, concurrency: 9, retryGapMinutes: 15 } }) });
    await show();
    expect(await screen.findByText(/15 min · Does not try again when nobody answers/)).toBeTruthy();
    expect(screen.getByText("9")).toBeTruthy();
  });

  it("makes a new campaign with these too, starting from the backend's defaults", async () => {
    const sent = fake({ "GET /api/kit/console/agents?page=1": { agents: [{ id: "a1", name: "Front desk" }], total: 1, page: 1, perPage: 50 }, "POST /api/kit/console/campaigns": () => campaign() });
    render(<MemoryRouter initialEntries={["/console/campaigns"]}><Routes><Route path="/console/campaigns" element={<CampaignsPage />} /><Route path="/console/campaigns/:id" element={<p>Opened</p>} /></Routes></MemoryRouter>);
    const form = await screen.findByRole("form", { name: "New campaign" });
    await within(form).findByRole("option", { name: "Front desk" });
    expect((within(form).getByLabelText("Minutes before trying again") as HTMLInputElement).value).toBe("5");
    expect((within(form).getByLabelText("Calls at once") as HTMLInputElement).value).toBe("2");
    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: " Recall " } });
    fireEvent.change(within(form).getByLabelText("Agent"), { target: { value: "a1" } });
    fireEvent.change(within(form).getByLabelText("Minutes before trying again"), { target: { value: "20" } });
    fireEvent.click(within(form).getByRole("button", { name: "Make campaign" }));
    await screen.findByText("Opened");
    const body = sent.find((x) => x.method === "POST")?.body;
    expect([body?.name, body?.agentId, body?.retryGapMinutes, body?.concurrency, body?.maxAttempts]).toEqual(["Recall", "a1", 20, 2, 3]);
  });
});

describe("its people", () => {
  it("counts each group, shows one when asked and goes back to its first page", async () => {
    const sent = fake({
      [`GET ${root}/targets?page=1`]: { targets: [person({ id: "a" })], total: 60, page: 1, perPage: 50, counts },
      [`GET ${root}/targets?page=2`]: { targets: [person({ id: "b", phoneNumber: "+14155550202" })], total: 60, page: 2, perPage: 50, counts },
      [`GET ${root}/targets?page=1&show=unanswered`]: { targets: [person({ id: "c", phoneNumber: "+14155550303" })], total: 3, page: 1, perPage: 50, counts },
    });
    await show();
    const groups = await screen.findByRole("group", { name: "Show" });
    expect(within(groups).getAllByRole("button").map((b) => b.textContent)).toEqual(["All (10)", "Waiting (1)", "Answered (2)", "Not answered (3)", "Failed (4)"]);
    const pressed = () => within(groups).getAllByRole("button").filter((b) => b.getAttribute("aria-pressed") === "true").map((b) => b.textContent);
    expect(pressed()).toEqual(["All (10)"]);
    fireEvent.click(screen.getByRole("button", { name: "Next" }));
    await screen.findByText("+14155550202");
    fireEvent.click(within(groups).getByRole("button", { name: "Not answered (3)" }));
    await screen.findByText("+14155550303");
    expect(pressed()).toEqual(["Not answered (3)"]);
    expect(sent.map((x) => x.path)).toContain(`${root}/targets?page=1&show=unanswered`);
    expect(sent.map((x) => x.path)).not.toContain(`${root}/targets?page=2&show=unanswered`);
  });

  it("says when a group is empty and when there is nobody at all", async () => {
    fake({ [`GET ${root}/targets?page=1`]: { targets: [person()], total: 1, page: 1, perPage: 50, counts: { waiting: 0, answered: 0, unanswered: 1, failed: 0 } }, [`GET ${root}/targets?page=1&show=failed`]: { targets: [], total: 0, page: 1, perPage: 50, counts: { waiting: 0, answered: 0, unanswered: 1, failed: 0 } } });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Failed (0)" }));
    expect(await screen.findByText("Nobody here.")).toBeTruthy();
    cleanup();
    fake({ [`GET ${root}/targets?page=1`]: { targets: [], total: 0, page: 1, perPage: 50, counts: { waiting: 0, answered: 0, unanswered: 0, failed: 0 } } });
    await show();
    expect(await screen.findByText(/Nobody to call yet/)).toBeTruthy();
    expect(screen.queryByRole("group", { name: "Show" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Download this list" })).toBeNull();
  });

  it("reads each person's call in words, and someone being called again as waiting", async () => {
    fake({ [`GET ${root}/targets?page=1`]: { total: 4, page: 1, perPage: 50, counts, targets: [
      person({ id: "a", phoneNumber: "+1", outcome: "answered", attempts: 2 }), person({ id: "b", phoneNumber: "+2", outcome: "failed" }), person({ id: "c", phoneNumber: "+3", state: "waiting", outcome: null, attempts: null, tryingAgain: true }), person({ id: "d", phoneNumber: "+4", state: "saving", outcome: null, attempts: null }),
    ] } });
    await show();
    const row = async (phone: string) => (await screen.findByText(phone)).closest("tr")?.textContent;
    expect(await row("+1")).toContain("Answered · 2 attempts");
    expect(await row("+2")).toContain("Failed");
    expect(await row("+3")).toContain("Waiting · calling again");
    expect(await row("+4")).toContain("Saving");
    expect(await row("+4")).not.toContain("calling again");
  });

  it("downloads what is showing", async () => {
    fake({ [`GET ${root}/targets?page=1&show=answered`]: { targets: [person()], total: 2, page: 1, perPage: 50, counts } });
    await show();
    expect((await screen.findByRole("link", { name: "Download this list" })).getAttribute("href")).toBe(`${root}/targets/export`);
    fireEvent.click(screen.getByRole("button", { name: "Answered (2)" }));
    await waitFor(() => expect(screen.getByRole("link", { name: "Download this list" }).getAttribute("href")).toBe(`${root}/targets/export?show=answered`));
    expect(screen.getByRole("link", { name: "Download this list" }).hasAttribute("download")).toBe(true);
  });
});

describe("calling people again", () => {
  it("asks first, names how many and that it is charged, then sends and says how many it really was", async () => {
    const sent = fake({ [`POST ${root}/retry`]: () => ({ retried: 2, campaign: campaign({ status: "running" }) }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Call the 3 not answered again" }));
    expect(sent.filter((x) => x.method === "POST")).toHaveLength(0);
    expect(screen.getByText(/Call 3 people again\? They did not answer.*Each call is charged\./)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, call them" }));
    expect(await screen.findByText("2 will be called again.")).toBeTruthy();
    expect(sent.filter((x) => x.method === "POST")[0].body).toEqual({ scope: "unanswered" });
    expect(screen.queryByRole("button", { name: "Yes, call them" })).toBeNull();
    await waitFor(() => expect(sent.filter((x) => x.path === `${root}/targets?page=1`).length).toBeGreaterThan(1));
  });

  it("offers everyone who failed as its own choice, counting both groups", async () => {
    const sent = fake({ [`POST ${root}/retry`]: () => ({ retried: 7, campaign: campaign() }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Call all 7 who failed again" }));
    expect(screen.getByText(/Call 7 people again\? This includes numbers that may not work\./)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, call them" }));
    await screen.findByText("7 will be called again.");
    expect(sent.filter((x) => x.method === "POST")[0].body).toEqual({ scope: "all" });
  });

  it("can be called off, and sends nothing", async () => {
    const sent = fake({});
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Call the 3 not answered again" }));
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(screen.getByRole("button", { name: "Call the 3 not answered again" })).toBeTruthy();
    expect(sent.filter((x) => x.method === "POST")).toHaveLength(0);
  });

  it("says when they wait for a paused campaign, and when there was nobody", async () => {
    fake({ [`POST ${root}/retry`]: () => ({ retried: 3, campaign: campaign({ status: "paused" }) }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Call the 3 not answered again" }));
    fireEvent.click(screen.getByRole("button", { name: "Yes, call them" }));
    expect(await screen.findByText("3 will be called again when you resume this campaign.")).toBeTruthy();
    cleanup();
    fake({ [`POST ${root}/retry`]: () => ({ retried: 0, campaign: campaign() }) });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Call the 3 not answered again" }));
    fireEvent.click(screen.getByRole("button", { name: "Yes, call them" }));
    expect(await screen.findByText("Nobody to call again.")).toBeTruthy();
  });

  it("says what the backend said when it could not be done, and stays asked", async () => {
    fake({ [`POST ${root}/retry`]: () => refused(503, "This could not be done just now. Check who is waiting before trying again.") });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "Call the 3 not answered again" }));
    fireEvent.click(screen.getByRole("button", { name: "Yes, call them" }));
    expect((await screen.findByRole("alert")).textContent).toContain("Check who is waiting");
    expect(screen.getByRole("button", { name: "Yes, call them" })).toBeTruthy();
  });

  it("offers nothing when there is nobody, on a cancelled one, while saving, or one still being made", async () => {
    fake({ [`GET ${root}/targets?page=1`]: { targets: [person()], total: 1, page: 1, perPage: 50, counts: { waiting: 0, answered: 1, unanswered: 0, failed: 0 } } });
    await show();
    await screen.findByRole("group", { name: "Show" });
    expect(screen.queryByRole("button", { name: /again/ })).toBeNull();
    for (const over of [{ status: "cancelled" }, { syncState: "pending" }, { status: "saving" }, { status: "failed" }] as Partial<Campaign>[]) {
      cleanup();
      fake({ [`GET ${root}`]: campaign(over) });
      await show();
      await screen.findByRole("group", { name: "Show" });
      expect(screen.queryByRole("button", { name: /again$/ })).toBeNull();
    }
  });

  it("offers only the one that has anybody", async () => {
    fake({ [`GET ${root}/targets?page=1`]: { targets: [person()], total: 1, page: 1, perPage: 50, counts: { waiting: 0, answered: 0, unanswered: 2, failed: 0 } } });
    await show();
    expect(await screen.findByRole("button", { name: "Call the 2 not answered again" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: /who failed again/ })).toBeNull();
    cleanup();
    fake({ [`GET ${root}/targets?page=1`]: { targets: [person()], total: 1, page: 1, perPage: 50, counts: { waiting: 0, answered: 0, unanswered: 0, failed: 2 } } });
    await show();
    expect(await screen.findByRole("button", { name: "Call all 2 who failed again" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: /not answered again/ })).toBeNull();
  });
});

describe("how it went", () => {
  it("is read only when asked for, again when asked again, and says what it found", async () => {
    const sent = fake({ [`GET ${root}/progress`]: progress });
    await show();
    expect(sent.filter((x) => x.path === `${root}/progress`)).toHaveLength(0);
    fireEvent.click(await screen.findByRole("button", { name: "See how it went" }));
    await screen.findByText("Called in the last hour");
    for (const [word, value] of [["People", "8"], ["Called", "7"], ["Not reached", "5"], ["On the do-not-call list", "3"], ["Called in the last hour", "9"], ["No answer", "11"], ["Busy", "12"], ["Could not connect", "14"], ["Time on calls", "1 min 22 s"], ["Average call", "0 min 41 s"], ["Answered, nobody spoke", "21"], ["Under 15 seconds", "22"], ["Over 2 minutes", "26"], ["The first attempt", "31"], ["The third or later", "33"]]) {
      const dt = (await screen.findAllByText(word)).find((x) => x.tagName === "DT");
      expect([word, dt?.nextElementSibling?.textContent]).toEqual([word, value]);
    }
    const hours = screen.getByRole("table", { name: "By hour (Europe/London)" });
    expect(within(hours).getAllByRole("row").slice(1).map((r) => r.textContent)).toEqual(["09:0072"]);
    fireEvent.click(screen.getByRole("button", { name: "Read again" }));
    await waitFor(() => expect(sent.filter((x) => x.path === `${root}/progress`)).toHaveLength(2));
  });

  it("leaves out what has nothing to say", async () => {
    fake({ [`GET ${root}/progress`]: { ...progress, called: 0, reached: 0, notReached: 0, talk: { ...progress.talk, averageSeconds: null }, byHour: { timezone: "UTC", called: Array(24).fill(0), reached: Array(24).fill(0) } } });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "See how it went" }));
    expect(await screen.findByText("No calls have finished yet.")).toBeTruthy();
    expect(screen.queryByText("Why people were not reached")).toBeNull();
    expect(screen.queryByText("Talking")).toBeNull();
    expect(screen.queryByRole("table", { name: /By hour/ })).toBeNull();
  });

  it("leaves out an average it does not have", async () => {
    fake({ [`GET ${root}/progress`]: { ...progress, talk: { ...progress.talk, averageSeconds: null } } });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "See how it went" }));
    await screen.findByText("Time on calls");
    expect(screen.queryByText("Average call")).toBeNull();
  });

  it("says when it cannot be read, and is not offered before the campaign is made", async () => {
    fake({ [`GET ${root}/progress`]: () => refused(503, "This could not be read just now. Try again shortly.") });
    await show();
    fireEvent.click(await screen.findByRole("button", { name: "See how it went" }));
    expect((await screen.findByRole("alert")).textContent).toContain("could not be read");
    cleanup();
    fake({ [`GET ${root}`]: campaign({ status: "saving" }) });
    await show();
    expect(screen.queryByRole("button", { name: "See how it went" })).toBeNull();
  });
});
