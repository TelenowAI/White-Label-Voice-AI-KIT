/**
 * People on the phone (phone/, calls/CallWork, FollowUpsPage, LiveNow): a call placed from the browser joins with exactly the session the backend answered,
 * mutes, hangs up here and at the voice platform (leaving the page too), and transfers when allowed; nothing is offered while the service says no; a refused
 * number says why beside its field. On a call: a wrap-up with the account's own suggestions, notes and follow-ups, each form's refusal beside its own field.
 * A member's follow-ups, to do and done, ticked off. The calls list: calls on now, one person's calls by number, the member's own browser calls.
 */
import type { TelenowCallOptions } from "@telenow/client";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { CallSummary, CallWork, ConsoleHome, FollowUp, PhonePage, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";

const calls = vi.hoisted(() => [] as Array<{ opts: TelenowCallOptions; start: () => Promise<void>; stop: () => void; setMuted: (m: boolean) => void }>);

vi.mock("@telenow/client", () => ({
  TelenowCall: class {
    readonly opts: TelenowCallOptions;
    readonly start = vi.fn(async () => {
      this.opts.onState?.("connecting");
      this.opts.onState?.("live");
    });
    readonly stop = vi.fn(() => this.opts.onState?.("ended"));
    readonly setMuted = vi.fn();
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
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
};
const session = { sessionId: "s1", websocketUrl: "wss://voice.example/ws/web-agent" };
const noCalls = { calls: [], total: 0, page: 1, perPage: 50 };
const limits = { noteMaxLength: 2000, wrapupMaxLength: 64, followUpMaxLength: 500 };
const emptyWork: CallWork = { wrapup: null, notes: [], followUps: [], suggestions: ["Booked", "No answer"], limits };

function phone(over: Partial<PhonePage> = {}): PhonePage {
  return { allowed: true, transferAllowed: false, paused: null, priced: true, consentRequired: false, callerIds: [{ id: "n1", number: "+918045671001" }, { id: "n2", number: "+918045671002" }], ...over };
}

function summary(over: Partial<CallSummary> = {}): CallSummary {
  return {
    id: "c1", agent: null, placedBy: { name: "Ana", me: true }, requestedBy: null, wrapup: null, status: "ended", outcome: "answered", direction: "outbound", channel: "softphone",
    from: "918045671001", to: "+14155550101", startedAt: "2026-11-04T10:00:00Z", endedAt: "2026-11-04T10:02:00Z", durationSec: 120, complete: true, charge: null, ...over,
  };
}

function followUp(over: Partial<FollowUp> = {}): FollowUp {
  return { id: "f1", body: "Send the form", dueAt: "2030-01-01T09:00:00Z", by: "Ana", mine: true, doneAt: null, doneBy: null, createdAt: "2026-11-04T10:03:00Z", ...over };
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
        <Route path="/elsewhere" element={<p>Elsewhere</p>} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

describe("the phone", () => {
  const placing = (over: Record<string, unknown> = {}) => ({
    ...signedIn,
    "GET /api/kit/console/phone": phone(),
    "GET /api/kit/console/calls?page=1&placedBy=me": noCalls,
    "POST /api/kit/console/phone/calls": { callId: "c9", session },
    "GET /api/kit/console/calls/c9/work": emptyWork,
    "DELETE /api/kit/console/phone/calls/c9": () => new Response(null, { status: 204 }),
    ...over,
  });

  it("calls from the number chosen, joins with exactly the session answered, mutes, and hangs up here and at the voice platform", async () => {
    const sent = fakeKit(placing());
    open("/console/phone");
    expect(await screen.findByText("Calls placed here may be recorded.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Call from"), { target: { value: "n2" } });
    fireEvent.change(screen.getByLabelText("Number to call"), { target: { value: "+1 415 555 0101" } });
    fireEvent.click(screen.getByRole("button", { name: "Call" }));
    expect(await screen.findByText("In the call")).toBeTruthy();
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ callerId: "n2", number: "+1 415 555 0101" });
    expect(calls).toHaveLength(1);
    expect(calls[0].opts.session).toEqual(session);
    expect((screen.getByLabelText("Number to call") as HTMLInputElement).disabled).toBe(true);
    expect(screen.queryByLabelText("Transfer to")).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: "Mute" }));
    expect(calls[0].setMuted).toHaveBeenCalledWith(true);
    expect(screen.getByRole("button", { name: "Unmute" }).getAttribute("aria-pressed")).toBe("true");

    expect(await screen.findByRole("heading", { name: "Wrap-up and notes" })).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Hang up" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/console/phone/calls/c9"]));
    expect(calls[0].stop).toHaveBeenCalledOnce();
    expect(await screen.findByText("Call ended")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Call" })).toBeTruthy();
  });

  it("ends the call at the voice platform when the page is left, and when the browser's side fails", async () => {
    const sent = fakeKit({ ...placing(), "GET /api/kit/console/calls": noCalls, "GET /api/kit/console/calls/live": { total: 0, agents: [], others: 0, calls: [], canListen: false, refreshSeconds: 15 } });
    open("/console/phone");
    fireEvent.change(await screen.findByLabelText("Number to call"), { target: { value: "+14155550101" } });
    fireEvent.click(screen.getByRole("button", { name: "Call" }));
    expect(await screen.findByText("In the call")).toBeTruthy();
    act(() => calls[0].opts.onState?.("error"));
    await waitFor(() => expect(sent.filter((s) => s.method === "DELETE")).toHaveLength(1));

    fireEvent.click(screen.getByRole("button", { name: "Call" }));
    await waitFor(() => expect(calls).toHaveLength(2));
    fireEvent.click(screen.getByRole("link", { name: "Calls" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "DELETE")).toHaveLength(2));
    expect(calls[1].stop).toHaveBeenCalledOnce();
  });

  it("transfers when allowed, and the browser leaves the call", async () => {
    const sent = fakeKit(placing({
      "GET /api/kit/console/phone": phone({ transferAllowed: true }),
      "POST /api/kit/console/phone/calls/c9/transfer": (body: unknown) => (body as { to: string }).to === "+14155550199"
        ? Response.json({ message: "The given data was invalid.", errors: { to: ["This number is on your do-not-call list."] } }, { status: 422 })
        : new Response(null, { status: 204 }),
    }));
    open("/console/phone");
    fireEvent.change(await screen.findByLabelText("Number to call"), { target: { value: "+14155550101" } });
    fireEvent.click(screen.getByRole("button", { name: "Call" }));
    const to = await screen.findByLabelText("Transfer to");
    fireEvent.change(to, { target: { value: "+14155550199" } });
    fireEvent.click(screen.getByRole("button", { name: "Transfer" }));
    expect(await screen.findByText("This number is on your do-not-call list.")).toBeTruthy();
    expect(to.getAttribute("aria-invalid")).toBe("true");
    expect(screen.getByText("In the call")).toBeTruthy();

    fireEvent.change(to, { target: { value: "+14155550102" } });
    fireEvent.click(screen.getByRole("button", { name: "Transfer" }));
    expect(await screen.findByText("Transferred to +14155550102.")).toBeTruthy();
    expect(calls[0].stop).toHaveBeenCalledOnce();
    expect(sent.filter((s) => s.method === "DELETE")).toHaveLength(0);
  });

  it("says why a number is refused beside it, and places nothing while the service says no", async () => {
    fakeKit(placing({
      "POST /api/kit/console/phone/calls": () => Response.json({ message: "The given data was invalid.", errors: { number: ["Write the number with + and its country code, like +14155550100."] } }, { status: 422 }),
    }));
    open("/console/phone");
    const number = await screen.findByLabelText("Number to call");
    fireEvent.change(number, { target: { value: "call me" } });
    fireEvent.click(screen.getByRole("button", { name: "Call" }));
    expect(await screen.findByText("Write the number with + and its country code, like +14155550100.")).toBeTruthy();
    expect(number.getAttribute("aria-invalid")).toBe("true");
    expect(calls).toHaveLength(0);
    cleanup();

    for (const [over, words] of [
      [{ allowed: false }, "Calls from the browser are not available on this service."],
      [{ paused: "Your balance is used up. Calls are on hold until credit is added." }, "Your balance is used up. Calls are on hold until credit is added."],
      [{ priced: false }, "Calls from the browser are not priced on your plan yet, so they cannot be placed."],
    ] as const) {
      fakeKit(placing({ "GET /api/kit/console/phone": phone(over) }));
      open("/console/phone");
      expect(await screen.findByText(words)).toBeTruthy();
      expect(screen.queryByRole("button", { name: "Call" })).toBeNull();
      cleanup();
    }
    fakeKit(placing({ "GET /api/kit/console/phone": phone({ callerIds: [] }) }));
    open("/console/phone");
    const none = await screen.findByText(/this account has none yet/);
    expect(within(none).getByRole("link", { name: "Numbers" }).getAttribute("href")).toBe("/console/numbers");
  });

  it("lists the member's own calls, with how each went", async () => {
    fakeKit(placing({ "GET /api/kit/console/calls?page=1&placedBy=me": { calls: [summary({ wrapup: "Booked" })], total: 7, page: 1, perPage: 1 } }));
    open("/console/phone");
    const mine = await screen.findByRole("link", { name: "+14155550101" });
    expect(mine.getAttribute("href")).toBe("/console/calls/c1");
    expect(mine.parentElement?.textContent).toContain("Booked");
    expect(screen.getByRole("link", { name: "All your calls" }).getAttribute("href")).toBe("/console/calls?placedBy=me");
  });
});

describe("what the team writes on a call", () => {
  const onCall = (over: Record<string, unknown> = {}) => ({
    ...signedIn,
    "GET /api/kit/console/calls/c1": { ...summary(), canListen: false, listens: [], answeredBy: null, transcript: [], recordings: [], analysis: null },
    "GET /api/kit/console/calls/c1/work": emptyWork,
    ...over,
  });

  it("saves a wrap-up from the account's own suggestions, adds a note and a follow-up, each refusal beside its own field", async () => {
    const sent = fakeKit(onCall({
      "PUT /api/kit/console/calls/c1/wrapup": (body: unknown) => Response.json({ ...emptyWork, wrapup: { label: (body as { wrapup: string }).wrapup, by: "Ana", at: "2026-11-04T10:05:00Z" } }),
      "POST /api/kit/console/calls/c1/notes": (body: unknown) => Response.json({ ...emptyWork, notes: [{ id: "n1", body: (body as { body: string }).body, by: "Ana", mine: true, at: "2026-11-04T10:06:00Z" }] }, { status: 201 }),
      "POST /api/kit/console/calls/c1/followups": () => Response.json({ message: "The given data was invalid.", errors: { body: ["The follow-up field is required."] } }, { status: 422 }),
    }));
    open("/console/calls/c1");
    expect(await screen.findByText("Placed by")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Every call with this number" }).getAttribute("href")).toBe("/console/calls?number=%2B14155550101");
    const wrapup = await screen.findByLabelText("How it went");
    const offered = document.getElementById(wrapup.getAttribute("list") ?? "");
    expect([...(offered?.querySelectorAll("option") ?? [])].map((o) => o.value)).toEqual(["Booked", "No answer"]);
    expect(wrapup.getAttribute("maxlength")).toBe("64");
    fireEvent.change(wrapup, { target: { value: "Booked" } });
    fireEvent.click(screen.getAllByRole("button", { name: "Save" })[0]);
    expect(await screen.findByText(/^By Ana, /)).toBeTruthy();
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ wrapup: "Booked" });

    fireEvent.change(screen.getByLabelText("Add a note"), { target: { value: "Wants Thursday." } });
    fireEvent.click(screen.getByRole("button", { name: "Add note" }));
    expect(await screen.findByText("Wants Thursday.")).toBeTruthy();
    expect((screen.getByLabelText("Add a note") as HTMLTextAreaElement).value).toBe("");

    fireEvent.change(screen.getByLabelText("Add a follow-up"), { target: { value: "x" } });
    const dueOffered = new Date((screen.getByLabelText("Due") as HTMLInputElement).value).getTime();
    expect(dueOffered).toBeGreaterThan(Date.now() + 23 * 3600 * 1000);
    expect(dueOffered).toBeLessThan(Date.now() + 25 * 3600 * 1000);
    fireEvent.change(screen.getByLabelText("Due"), { target: { value: "2030-01-02T09:30" } });
    fireEvent.click(screen.getByRole("button", { name: "Add follow-up" }));
    expect(await screen.findByText("The follow-up field is required.")).toBeTruthy();
    expect(screen.getByLabelText("Add a follow-up").getAttribute("aria-invalid")).toBe("true");
    expect(screen.getByLabelText("Add a note").getAttribute("aria-invalid")).toBe("false");
    const due = (sent.find((s) => s.path.endsWith("/followups"))?.body as { dueAt: string }).dueAt;
    expect(due).toBe(new Date("2030-01-02T09:30").toISOString());
  });

  it("takes a wrap-up off when it is emptied, and ticks a follow-up off for whoever it is", async () => {
    const sent = fakeKit(onCall({
      "GET /api/kit/console/calls/c1/work": { ...emptyWork, wrapup: { label: "Booked", by: null, at: null }, followUps: [followUp({ by: "Ben", mine: false })] },
      "PUT /api/kit/console/calls/c1/wrapup": () => Response.json(emptyWork),
      "PATCH /api/kit/console/followups/f1": () => Response.json(followUp({ by: "Ben", mine: false, doneAt: "2026-11-04T11:00:00Z", doneBy: "Ana" })),
    }));
    open("/console/calls/c1");
    expect(await screen.findByText(/Someone no longer on the team/)).toBeTruthy();
    const box = screen.getByLabelText("Send the form") as HTMLInputElement;
    fireEvent.click(box);
    expect(await screen.findByText(/done by Ana/)).toBeTruthy();
    expect(sent.find((s) => s.method === "PATCH")?.body).toEqual({ done: true });
    expect(box.closest("li")?.textContent).toContain("Ben");

    fireEvent.change(screen.getByLabelText("How it went"), { target: { value: "   " } });
    fireEvent.click(screen.getAllByRole("button", { name: "Save" })[0]);
    await waitFor(() => expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ wrapup: null }));
  });
});

describe("follow-ups", () => {
  it("lists the member's own, to do and done, with their calls, and ticks one off", async () => {
    let done = false;
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/followups?page=1": () => Response.json({ followUps: done ? [] : [followUp({ dueAt: "2020-01-01T09:00:00Z", call: { id: "c1", direction: "inbound", from: "+14155550101", to: "918045671001", startedAt: null } })], total: done ? 0 : 1, page: 1, perPage: 50 }),
      "GET /api/kit/console/followups?page=1&done=true": { followUps: [followUp({ id: "f2", body: "Called back", doneAt: "2026-11-04T11:00:00Z", doneBy: "Ben" })], total: 1, page: 1, perPage: 50 },
      "PATCH /api/kit/console/followups/f1": () => {
        done = true;
        return Response.json(followUp({ doneAt: "2026-11-04T11:00:00Z", doneBy: "Ana" }));
      },
    });
    open("/console/followups");
    const item = (await screen.findByLabelText("Send the form")).closest("li") as HTMLElement;
    expect(item.className).toBe("late");
    expect(item.textContent).toContain("Was due");
    expect(within(item).getByRole("link", { name: "Call with +14155550101" }).getAttribute("href")).toBe("/console/calls/c1");
    fireEvent.click(within(item).getByRole("checkbox"));
    expect(await screen.findByText("Nothing to do.")).toBeTruthy();
    expect(sent.find((s) => s.method === "PATCH")?.body).toEqual({ done: true });

    fireEvent.click(screen.getByRole("button", { name: "Done" }));
    expect(await screen.findByText("Called back")).toBeTruthy();
    expect(screen.getByText(/done by Ben/)).toBeTruthy();
  });
});

describe("the calls list", () => {
  const listing = (over: Record<string, unknown> = {}) => ({
    ...signedIn,
    "GET /api/kit/console/calls?page=1": { calls: [summary({ placedBy: { name: "Ben", me: false }, wrapup: "No answer" }), summary({ id: "c2", placedBy: { name: null, me: false } })], total: 2, page: 1, perPage: 50 },
    "GET /api/kit/console/calls/live": { total: 3, agents: [{ id: "a1", name: "Front desk", count: 2 }], others: 1, calls: [], canListen: false, refreshSeconds: 15 },
    ...over,
  });

  it("shows the calls on now, who placed each browser call, and its wrap-up", async () => {
    fakeKit(listing());
    open("/console/calls");
    const live = await screen.findByText("On calls now: 3");
    expect(live.parentElement?.textContent).toBe("On calls now: 3 · Front desk 2 · from the browser 1");
    const rows = screen.getAllByRole("row").slice(1);
    expect(rows[0].textContent).toContain("Ben");
    expect(rows[0].textContent).toContain("No answer");
    expect(rows[1].textContent).toContain("Someone no longer on the team");
  });

  it("finds one person's calls by number and the member's own, in the address", async () => {
    const sent = fakeKit(listing({
      "GET /api/kit/console/calls?page=1&number=%2B1+415+555+0101": { calls: [summary()], total: 1, page: 1, perPage: 50 },
      "GET /api/kit/console/calls?page=1&number=%2B1+415+555+0101&placedBy=me": noCalls,
    }));
    open("/console/calls");
    fireEvent.change(await screen.findByLabelText("Number"), { target: { value: " +1 415 555 0101 " } });
    fireEvent.click(screen.getByRole("button", { name: "Find" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/calls?number=%2B1+415+555+0101"));
    fireEvent.click(screen.getByLabelText("Only calls I placed"));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/calls?number=%2B1+415+555+0101&placedBy=me"));
    expect(await screen.findByText("No calls yet.")).toBeTruthy();
    expect(sent.map((s) => s.path)).toContain("/api/kit/console/calls?page=1&number=%2B1+415+555+0101&placedBy=me");
    fireEvent.click(screen.getByRole("link", { name: "All calls" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/calls"));
  });

  it("says when calls on now cannot be counted, and the list is still there", async () => {
    fakeKit(listing({ "GET /api/kit/console/calls/live": () => Response.json({ message: "Calls on now could not be counted. Try again shortly." }, { status: 503 }) }));
    open("/console/calls");
    expect(await screen.findByText("Calls on now could not be counted.")).toBeTruthy();
    expect(screen.getAllByRole("row")).toHaveLength(3);
  });
});
