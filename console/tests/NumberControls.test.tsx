/**
 * Console → Numbers (numbers/): under each number's agent, its caller id switch (a PUT of `{agentCallerId}`, then the list read again, as the
 * agent's other number stops being it) or, with no agent, what a call does (a PUT of `{unanswered}`, the last two choices said not to answer
 * the call) — each offered only on its kind of number, a refusal in the backend's words; the renewals: each number's date and, while charging,
 * its rent and a marker, the warning with the largest shortfall to add and a link to the wallet, and dates only while charging is off; the lost
 * numbers, shown only when there are any (why, until when, the agent and caller id, the cost), got back only after a confirm that shows the
 * cost — restored (said, both lists read again), requested (said in its row), refused (the backend's word) — and a lost list that cannot be
 * read leaving the rest of the page working; no vendor's name anywhere.
 *
 * Not here: the list, choosing an agent, buying and releasing (Numbers.test.tsx); the requests against the contract (api.test.ts).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, ConsoleHome, LostNumber, NumberRenewals, PhoneNumber, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { agentSettings } from "./agentSettings";
import { fakeKit, type Sent } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const NUMBERS = "/api/kit/console/numbers";
const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };

function agent(id: string, name: string): Agent {
  return {
    id, name, description: null, systemPrompt: null, isActive: true,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: agentSettings,
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [], logoUrl: null,
    syncState: "synced", syncError: null, createdAt: "2026-10-30T09:00:00Z", updatedAt: "2026-10-30T09:00:00Z",
  };
}

const frontDesk = { id: "a1", name: "Front desk" };
const noRenewals: NumberRenewals = { charging: false, balance: null, soonDays: 7, numbers: [] };

/** A member signed in, the account's two agents, no renewals and no lost numbers, and the answers a test adds. */
function fake(more: Record<string, unknown>): Sent[] {
  return fakeKit({
    "GET /api/kit/session": { user: ana },
    "GET /api/kit/console/home": home,
    "GET /api/kit/console/workspaces": one,
    "GET /api/kit/console/agents?page=1": { agents: [agent("a1", "Front desk")], total: 2, page: 1, perPage: 1 },
    "GET /api/kit/console/agents?page=2": { agents: [agent("a2", "Billing")], total: 2, page: 2, perPage: 1 },
    [`GET ${NUMBERS}/renewals`]: noRenewals,
    [`GET ${NUMBERS}/lost`]: { numbers: [] },
    ...more,
  });
}

function number(over: Partial<PhoneNumber> & { id: string; number: string }): PhoneNumber {
  return {
    country: "India", region: "Karnataka", type: "local", agent: null, monthlyPrice: { amount: "522.90", currency: "INR" },
    nextRenewalAt: "2026-11-30T13:32:12+00:00", releasing: false, routed: false, agentCallerId: false, unanswered: "message",
    syncState: "synced", syncError: null, createdAt: "2026-10-30T13:32:12+00:00", ...over,
  };
}

function lost(over: Partial<LostNumber> & { id: string; number: string }): LostNumber {
  return {
    releasedAt: "2026-11-01T00:00:00Z", until: "2026-11-20T00:00:00Z", askedAt: null, reason: "unpaid", agent: null, wasCallerId: false,
    setupPrice: { amount: "0.00", currency: "INR" }, monthlyPrice: { amount: "522.90", currency: "INR" }, ...over,
  };
}

const refused = (status: number, message: string) => Response.json({ message }, { status });
const writes = (sent: Sent[]) => sent.filter((s) => s.method !== "GET").map((s) => [`${s.method} ${s.path}`, s.body]);
const reads = (sent: Sent[], path: string) => sent.filter((s) => s.method === "GET" && s.path === path).length;
const day = (iso: string) => new Date(iso).toLocaleDateString();

function open() {
  render(
    <MemoryRouter initialEntries={["/console/numbers"]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

async function yours() {
  return within(await screen.findByRole("table", { name: "Your numbers" })).getAllByRole("row").slice(1);
}

const numberRows = () => within(screen.getByRole("table", { name: "Your numbers" })).getAllByRole("row").slice(1);

const callerIdSwitch = (row: HTMLElement, num: string, agentName = "Front desk") =>
  within(row).getByRole("switch", { name: `${num} is ${agentName}'s caller id` }) as HTMLInputElement;
const unansweredChoice = (row: HTMLElement, num: string) => within(row).getByLabelText(`When no agent answers ${num}`) as HTMLSelectElement;
const lostRows = () => within(screen.getByRole("table", { name: "Lost numbers" })).getAllByRole("row").slice(1);
const cells = (row: HTMLElement) => within(row).getAllByRole("cell").map((c) => c.textContent);

describe("a number's caller id", () => {
  it("makes a number its agent's caller id with a PUT, then reads the list again, as the agent's other number stops being it", async () => {
    let numbers = [
      number({ id: "n1", number: "+918045671001", agent: frontDesk }),
      number({ id: "n2", number: "+918045671002", agent: frontDesk, agentCallerId: true }),
      number({ id: "n3", number: "+918045671003" }),
      number({ id: "n4", number: "+918045671004", agent: frontDesk, releasing: true, syncState: "pending" }),
    ];
    const sent = fake({
      [`GET ${NUMBERS}`]: () => Response.json({ numbers }),
      [`PUT ${NUMBERS}/n1/caller-id`]: (body: unknown) => {
        const on = (body as { agentCallerId: boolean }).agentCallerId;
        numbers = numbers.map((n) => (n.id === "n1" ? { ...n, agentCallerId: on } : n.id === "n2" && on ? { ...n, agentCallerId: false } : n));
        return Response.json(numbers[0]);
      },
    });
    open();
    const rows = await yours();
    expect(within(rows[0]).getByText("Front desk's calls come from this number when a call names none.")).toBeTruthy();
    expect([callerIdSwitch(rows[0], "+918045671001").checked, callerIdSwitch(rows[1], "+918045671002").checked]).toEqual([false, true]);
    expect(within(rows[2]).queryByRole("switch")).toBeNull();
    expect(within(rows[3]).queryByRole("switch")).toBeNull();
    expect(reads(sent, NUMBERS)).toBe(1);

    fireEvent.click(callerIdSwitch(rows[0], "+918045671001"));
    await waitFor(() => expect(callerIdSwitch(numberRows()[1], "+918045671002").checked).toBe(false));
    const now = await yours();
    expect(callerIdSwitch(now[0], "+918045671001").checked).toBe(true);
    expect(reads(sent, NUMBERS)).toBe(2);
    expect(writes(sent)).toEqual([[`PUT ${NUMBERS}/n1/caller-id`, { agentCallerId: true }]]);

    fireEvent.click(callerIdSwitch(now[0], "+918045671001"));
    await waitFor(() => expect(callerIdSwitch(numberRows()[0], "+918045671001").checked).toBe(false));
    expect(writes(sent)).toEqual([[`PUT ${NUMBERS}/n1/caller-id`, { agentCallerId: true }], [`PUT ${NUMBERS}/n1/caller-id`, { agentCallerId: false }]]);
  });

  it("says the backend's word beside the switch when it is refused, and leaves it as it was", async () => {
    const why = "Give this number an agent first: it can only be the caller id of the agent that answers it.";
    const sent = fake({
      [`GET ${NUMBERS}`]: { numbers: [number({ id: "n1", number: "+918045671001", agent: frontDesk })] },
      [`PUT ${NUMBERS}/n1/caller-id`]: () => refused(409, why),
    });
    open();
    const row = (await yours())[0];
    fireEvent.click(callerIdSwitch(row, "+918045671001"));
    expect((await within(row).findByRole("alert")).textContent).toBe(why);
    expect(callerIdSwitch(row, "+918045671001").checked).toBe(false);
    expect(writes(sent)).toEqual([[`PUT ${NUMBERS}/n1/caller-id`, { agentCallerId: true }]]);
    expect(reads(sent, NUMBERS)).toBe(1);
  });
});

describe("when no agent answers", () => {
  it("chooses what a call to a number with no agent does with a PUT, says the busy and unavailable lines do not answer, and is not offered with an agent", async () => {
    const sent = fake({
      [`GET ${NUMBERS}`]: {
        numbers: [
          number({ id: "n1", number: "+918045671001", agent: frontDesk }),
          number({ id: "n2", number: "+918045671002" }),
          number({ id: "n3", number: "+918045671003", releasing: true, syncState: "pending" }),
        ],
      },
      [`PUT ${NUMBERS}/n2/unanswered`]: (body: unknown) =>
        Response.json(number({ id: "n2", number: "+918045671002", unanswered: (body as { unanswered: PhoneNumber["unanswered"] }).unanswered })),
      [`PUT ${NUMBERS}/n2/agent`]: () => Response.json(number({ id: "n2", number: "+918045671002", agent: frontDesk })),
    });
    open();
    const rows = await yours();
    const choice = unansweredChoice(rows[1], "+918045671002");
    expect([...choice.options].map((o) => [o.value, o.textContent])).toEqual([
      ["message", "Say no one is available"], ["busy", "A busy line"], ["unavailable", "Line unavailable"],
    ]);
    expect(choice.value).toBe("message");
    expect(within(rows[1]).getByText("A busy line or an unavailable line does not answer the call.")).toBeTruthy();
    expect(within(rows[0]).queryByText("When no agent answers")).toBeNull();
    expect(within(rows[0]).queryByRole("combobox", { name: "When no agent answers +918045671001" })).toBeNull();
    expect(within(rows[2]).queryByText("When no agent answers")).toBeNull();
    expect(within(rows[1]).queryByRole("switch")).toBeNull();

    fireEvent.change(choice, { target: { value: "busy" } });
    await waitFor(() => expect(unansweredChoice(rows[1], "+918045671002").value).toBe("busy"));
    fireEvent.change(unansweredChoice(rows[1], "+918045671002"), { target: { value: "unavailable" } });
    await waitFor(() => expect(unansweredChoice(rows[1], "+918045671002").value).toBe("unavailable"));
    expect(writes(sent)).toEqual([
      [`PUT ${NUMBERS}/n2/unanswered`, { unanswered: "busy" }],
      [`PUT ${NUMBERS}/n2/unanswered`, { unanswered: "unavailable" }],
    ]);

    const agentChoice = within(rows[1]).getByLabelText("Agent answering +918045671002") as HTMLSelectElement;
    await waitFor(() => expect(agentChoice.options).toHaveLength(3));
    fireEvent.change(agentChoice, { target: { value: "a1" } });
    await waitFor(() => expect(within(rows[1]).queryByText("When no agent answers")).toBeNull());
    expect(callerIdSwitch(rows[1], "+918045671002").checked).toBe(false);
  });

  it("says the backend's word beside the choice when it is refused, and leaves it as it was", async () => {
    const why = "An agent answers this number, so this never happens. Take the agent off first if the number should stop taking calls.";
    const sent = fake({
      [`GET ${NUMBERS}`]: { numbers: [number({ id: "n2", number: "+918045671002" })] },
      [`PUT ${NUMBERS}/n2/unanswered`]: () => refused(409, why),
    });
    open();
    const row = (await yours())[0];
    fireEvent.change(unansweredChoice(row, "+918045671002"), { target: { value: "busy" } });
    expect((await within(row).findByRole("alert")).textContent).toBe(why);
    expect(unansweredChoice(row, "+918045671002").value).toBe("message");
    expect(writes(sent)).toEqual([[`PUT ${NUMBERS}/n2/unanswered`, { unanswered: "busy" }]]);
  });
});

describe("renewals", () => {
  const at1 = "2026-11-12T10:00:00Z";
  const at2 = "2026-11-10T10:00:00Z";
  const at3 = "2026-12-20T10:00:00Z";
  const n1 = number({ id: "n1", number: "+918045671001", nextRenewalAt: at1 });
  const n2 = number({ id: "n2", number: "+918045671002", nextRenewalAt: at2 });
  const n3 = number({ id: "n3", number: "+918045671003", nextRenewalAt: at3 });
  const inr = (amount: string) => ({ amount, currency: "INR" });

  it("warns when the balance does not cover the next rent: the numbers, the largest shortfall to add, and a way to the wallet; each row its rent and marker", async () => {
    // Balance 100.00; in renewal order: n2 50.00 (covered, soon), n1 145.00 (running total 195.00: short by 95.00), n3 155.00 (350.00: 250.00).
    fake({
      [`GET ${NUMBERS}`]: { numbers: [n1, n2, n3] },
      [`GET ${NUMBERS}/renewals`]: {
        charging: true, balance: inr("100.00"), soonDays: 7, numbers: [
          { id: "n2", number: n2.number, renewsAt: at2, rent: inr("50.00"), status: "soon", shortBy: null },
          { id: "n1", number: n1.number, renewsAt: at1, rent: inr("145.00"), status: "short", shortBy: inr("95.00") },
          { id: "n3", number: n3.number, renewsAt: at3, rent: inr("155.00"), status: "short", shortBy: inr("250.00") },
        ],
      } satisfies NumberRenewals,
    });
    open();
    const warning = await screen.findByRole("region", { name: "Your balance is low" });
    expect([...warning.querySelectorAll("p")].map((p) => p.textContent)).toEqual([
      "Your balance is low",
      "Your balance does not cover the next rent of +918045671001, +918045671003. Add ₹250.00 to cover them all.",
      "The rent is charged anyway, and calls stop when your balance reaches zero. Add credit",
    ]);
    expect(within(warning).getByRole("link", { name: "Add credit" }).getAttribute("href")).toBe("/console/wallet");
    expect(warning.compareDocumentPosition(screen.getByRole("table", { name: "Your numbers" })) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    const rows = await yours();
    expect(rows.map((r) => cells(r)[4])).toEqual([
      `${day(at1)} for ₹145.00 Not covered`,
      `${day(at2)} for ₹50.00 Soon`,
      `${day(at3)} for ₹155.00 Not covered`,
    ]);
  });

  it("says one number's shortfall in the singular", async () => {
    fake({
      [`GET ${NUMBERS}`]: { numbers: [n1] },
      [`GET ${NUMBERS}/renewals`]: {
        charging: true, balance: inr("0.00"), soonDays: 7,
        numbers: [{ id: "n1", number: n1.number, renewsAt: at1, rent: inr("145.00"), status: "short", shortBy: inr("145.00") }],
      } satisfies NumberRenewals,
    });
    open();
    expect(await within(await screen.findByRole("region", { name: "Your balance is low" }))
      .findByText("Your balance does not cover the next rent of +918045671001. Add ₹145.00 to cover it.")).toBeTruthy();
  });

  it("shows the dates only while charging is off: no rent, no marker, no warning, whatever a row says", async () => {
    const sent = fake({
      [`GET ${NUMBERS}`]: { numbers: [n1, n2] },
      [`GET ${NUMBERS}/renewals`]: {
        charging: false, balance: null, soonDays: 7, numbers: [
          { id: "n2", number: n2.number, renewsAt: at2, rent: null, status: "soon", shortBy: null },
          { id: "n1", number: n1.number, renewsAt: at1, rent: inr("145.00"), status: "short", shortBy: inr("145.00") },
        ],
      } satisfies NumberRenewals,
    });
    open();
    await waitFor(() => expect(reads(sent, `${NUMBERS}/renewals`)).toBe(1));
    const rows = await yours();
    await waitFor(() => expect(rows.map((r) => cells(r)[4])).toEqual([day(at1), day(at2)]));
    expect(screen.queryByRole("region", { name: "Your balance is low" })).toBeNull();
    expect(document.body.textContent).not.toMatch(/Not covered|Soon|for ₹/);
  });
});

describe("lost numbers", () => {
  const l1 = lost({ id: "l1", number: "+918045679001", agent: frontDesk, wasCallerId: true });
  const l2 = lost({ id: "l2", number: "+918045679002", reason: "other", until: null, setupPrice: null, monthlyPrice: null, askedAt: "2026-11-07T10:00:00Z" });
  const l3 = lost({ id: "l3", number: "+918045679003", agent: { id: "a2", name: "Billing" }, setupPrice: null, monthlyPrice: null });

  it("lists each with why, until when, the agent it had (and that it was its caller id) and what getting it back costs; one asked for says so", async () => {
    fake({ [`GET ${NUMBERS}`]: { numbers: [] }, [`GET ${NUMBERS}/lost`]: { numbers: [l1, l2, l3] } });
    open();
    expect(await screen.findByRole("heading", { name: "Lost numbers" })).toBeTruthy();
    expect(screen.getByText("Numbers this account lost that you can still get back for a while.")).toBeTruthy();
    expect(lostRows().map(cells)).toEqual([
      ["+918045679001", "Given up when its rent was not paid.", day("2026-11-20T00:00:00Z"), "Front desk, as its caller id",
        "₹0.00 setup and ₹522.90 for the first month", "Get it back"],
      ["+918045679002", "Given up by the voice platform.", "", "No agent", "Not priced", `Asked for on ${day("2026-11-07T10:00:00Z")}. It is being got back by hand.`],
      ["+918045679003", "Given up when its rent was not paid.", day("2026-11-20T00:00:00Z"), "Billing", "Not priced", "Get it back"],
    ]);
    expect(within(lostRows()[1]).queryByRole("button")).toBeNull();
    expect(document.body.textContent).not.toMatch(/telenow|plivo|teleone|twilio|exotel|carrier/i);
  });

  it("gets one back only after a confirm that shows the cost; back at once: says so, and reads the numbers and the lost numbers again", async () => {
    let back = false;
    const restored = number({ id: "n9", number: "+918045679001", agent: frontDesk, agentCallerId: true });
    const sent = fake({
      [`GET ${NUMBERS}`]: () => Response.json({ numbers: back ? [restored] : [] }),
      [`GET ${NUMBERS}/lost`]: () => Response.json({ numbers: back ? [] : [l1] }),
      [`POST ${NUMBERS}/l1/get-back`]: () => {
        back = true;
        return Response.json({ outcome: "restored", number: restored });
      },
    });
    open();
    await screen.findByRole("table", { name: "Lost numbers" });
    const row = lostRows()[0];
    fireEvent.click(within(row).getByRole("button", { name: "Get it back" }));
    expect(within(row).getByText("Get +918045679001 back for ₹0.00 setup and ₹522.90 for the first month?")).toBeTruthy();
    expect(writes(sent)).toEqual([]);
    fireEvent.click(within(row).getByRole("button", { name: "Cancel" }));
    expect(within(row).queryByText(/^Get \+918045679001 back/)).toBeNull();
    fireEvent.click(within(row).getByRole("button", { name: "Get it back" }));
    fireEvent.click(within(row).getByRole("button", { name: "Yes, get it back" }));
    expect((await screen.findByRole("status")).textContent).toBe("+918045679001 is back. It is listed as a new number, set up as the old one was.");
    expect(writes(sent)).toEqual([[`POST ${NUMBERS}/l1/get-back`, undefined]]);
    expect(sent.find((s) => s.method === "POST")?.raw).toBeUndefined();
    await waitFor(() => expect(screen.queryByRole("table", { name: "Lost numbers" })).toBeNull());
    expect((await yours()).map((r) => cells(r)[0])).toEqual(["+918045679001"]);
    expect([reads(sent, NUMBERS), reads(sent, `${NUMBERS}/lost`), reads(sent, `${NUMBERS}/renewals`)]).toEqual([2, 2, 2]);
  });

  it("being got back by hand: says so in its row until the date, without reading the lists again; unpriced, the confirm names no cost", async () => {
    const sent = fake({
      [`GET ${NUMBERS}`]: { numbers: [] },
      [`GET ${NUMBERS}/lost`]: { numbers: [l1, l3] },
      [`POST ${NUMBERS}/l3/get-back`]: { outcome: "requested", until: "2026-11-25T00:00:00Z" },
      [`POST ${NUMBERS}/l1/get-back`]: { outcome: "requested", until: null },
    });
    open();
    await screen.findByRole("table", { name: "Lost numbers" });
    fireEvent.click(within(lostRows()[1]).getByRole("button", { name: "Get it back" }));
    expect(within(lostRows()[1]).getByText("Get +918045679003 back?")).toBeTruthy();
    fireEvent.click(within(lostRows()[1]).getByRole("button", { name: "Yes, get it back" }));
    expect((await within(lostRows()[1]).findByRole("status")).textContent).toBe(
      `It could not come back at once, so it is being got back by hand before ${day("2026-11-25T00:00:00Z")}. It shows in your numbers when it is back.`,
    );
    expect(within(lostRows()[1]).queryByRole("button")).toBeNull();

    fireEvent.click(within(lostRows()[0]).getByRole("button", { name: "Get it back" }));
    fireEvent.click(within(lostRows()[0]).getByRole("button", { name: "Yes, get it back" }));
    expect((await within(lostRows()[0]).findByRole("status")).textContent).toBe(
      "It could not come back at once, so it is being got back by hand. It shows in your numbers when it is back.",
    );
    expect(writes(sent)).toEqual([[`POST ${NUMBERS}/l3/get-back`, undefined], [`POST ${NUMBERS}/l1/get-back`, undefined]]);
    expect([reads(sent, NUMBERS), reads(sent, `${NUMBERS}/lost`)]).toEqual([1, 1]);
  });

  it("says the backend's word in its row when getting it back is refused, each time", async () => {
    const short = "Your balance does not cover getting this number back: 522.90 INR is due now (setup and the first month). Add credit first.";
    const late = "It is too late to get this number back. If it is still for sale, buy it again from the search.";
    const answers = [refused(409, short), refused(409, late)];
    const sent = fake({
      [`GET ${NUMBERS}`]: { numbers: [] },
      [`GET ${NUMBERS}/lost`]: { numbers: [l1] },
      [`POST ${NUMBERS}/l1/get-back`]: () => answers.shift()!,
    });
    open();
    await screen.findByRole("table", { name: "Lost numbers" });
    const row = lostRows()[0];
    fireEvent.click(within(row).getByRole("button", { name: "Get it back" }));
    fireEvent.click(within(row).getByRole("button", { name: "Yes, get it back" }));
    expect((await within(row).findByRole("alert")).textContent).toBe(short);
    fireEvent.click(within(row).getByRole("button", { name: "Yes, get it back" }));
    await waitFor(() => expect(within(row).getByRole("alert").textContent).toBe(late));
    expect(screen.queryByRole("status")).toBeNull();
    expect(writes(sent)).toHaveLength(2);
    expect([reads(sent, NUMBERS), reads(sent, `${NUMBERS}/lost`)]).toEqual([1, 1]);
  });

  it("cannot be read (503): the backend's word in a quiet line, and the rest of the page works; the renewals failing too, each number's own date", async () => {
    const why = "Lost numbers could not be checked just now. Try again shortly.";
    let n1 = number({ id: "n1", number: "+918045671001", agent: frontDesk });
    const sent = fake({
      [`GET ${NUMBERS}`]: () => Response.json({ numbers: [n1] }),
      [`GET ${NUMBERS}/lost`]: () => refused(503, why),
      [`GET ${NUMBERS}/renewals`]: () => refused(500, "Server Error"),
      [`PUT ${NUMBERS}/n1/caller-id`]: () => {
        n1 = { ...n1, agentCallerId: true };
        return Response.json(n1);
      },
    });
    open();
    const row = (await yours())[0];
    const line = await screen.findByText(why);
    expect(line.className).toBe("muted");
    await waitFor(() => expect(reads(sent, `${NUMBERS}/renewals`)).toBe(1));
    expect(screen.queryByRole("heading", { name: "Lost numbers" })).toBeNull();
    expect(screen.queryByRole("alert")).toBeNull();
    expect(cells(row)[4]).toBe(day("2026-11-30T13:32:12+00:00"));
    fireEvent.click(callerIdSwitch(row, "+918045671001"));
    await waitFor(() => expect(callerIdSwitch(numberRows()[0], "+918045671001").checked).toBe(true));
    expect(writes(sent)).toEqual([[`PUT ${NUMBERS}/n1/caller-id`, { agentCallerId: true }]]);
    expect(screen.getByRole("search", { name: "Find a number" })).toBeTruthy();
  });
});
