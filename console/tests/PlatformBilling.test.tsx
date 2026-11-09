/**
 * Admin → Platform billing (admin/PlatformBillingPage.tsx, PlatformAccount.tsx, PlatformBalanceAlert.tsx,
 * PlatformInvoices.tsx) against a fake backend: the operator's account at the voice platform in our words — running,
 * running low, stopped with each reason (and none given), prepaid with its balance and what it can still spend,
 * postpaid with no limit or with its cap, this period's spend, when it was checked, and before the first read that it
 * has not been read; the low-balance level saved as typed (the level held, to the cent, saved untouched; trimmed text;
 * null when empty) with the state the answer gives replacing the one shown; the backend's refusals (a refused level by
 * the field; 422 without a field, 409 and 503 as an alert, the state kept); the invoices in the order given, each
 * status in our words, their dates, totals in their currency and in US dollars, their lines and a Tax line of the
 * rest, their period as its own days to a viewer west of UTC; none, and invoices not read shown quietly with the rest of the page kept; a failed read as an alert; and the
 * page in the nav (and open) for staff with the `money` ability only.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { PlatformBilling, PlatformInvoice, PlatformSpendState } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { PlatformBillingPage } from "../src/admin/PlatformBillingPage";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const PAGE = "/api/kit/admin/platform-billing";
const ALERT = "/api/kit/admin/platform-billing/alert";
const CHECKED = "2026-11-08T18:30:00Z";

const running: PlatformSpendState = {
  state: "ok", canSpend: true, stoppedBy: null, mode: "prepaid", balanceUsd: "40.0000000000", periodStart: "2026-10-31T18:30:00Z",
  periodSpendUsd: "12.3450000000", monthlySpendLimitUsd: null, headroomUsd: "40.0000000000", lowThresholdUsd: "100.0000000000",
};

function billing(over: Partial<PlatformBilling> = {}): PlatformBilling {
  return { state: running, checkedAt: CHECKED, invoices: [], invoicesError: null, ...over };
}

function invoice(over: Partial<PlatformInvoice> & { id: string }): PlatformInvoice {
  return {
    periodStart: "2026-09-01", periodEnd: "2026-09-30", status: "paid", issuedAt: "2026-10-04T18:27:27Z", dueAt: "2026-10-11T18:27:27Z",
    paidAt: "2026-10-09T18:27:27Z", currency: "INR", total: "979.40", totalUsd: "11.80", lines: [], rest: null, ...over,
  };
}

const draft = invoice({ id: "inv-nov", periodStart: "2026-11-01", periodEnd: "2026-11-30", status: "draft", issuedAt: null, dueAt: null, paidAt: null, total: "249.00", totalUsd: "3.00" });
const issued = invoice({
  id: "inv-oct", periodStart: "2026-10-01", periodEnd: "2026-10-31", status: "issued", issuedAt: "2026-11-03T18:27:27Z", dueAt: "2026-11-10T18:27:27Z", paidAt: null,
  total: "1469.10", totalUsd: "17.70", rest: "224.10",
  lines: [
    { kind: "chat", description: "Chat sessions", amount: "83.00" },
    { kind: "platform_fee", description: "Platform fee", amount: "186.75" },
    { kind: "number_rental", description: "Phone number rentals", amount: "435.75" },
    { kind: "agent_calls", description: "AI agent calls", amount: "539.50" },
    { kind: "adjustment", description: "Adjustment", amount: null },
  ],
});
const paid = invoice({ id: "inv-sep" });

function page() {
  render(
    <MemoryRouter initialEntries={["/admin/platform-billing"]}>
      <Routes>
        <Route path="/admin/platform-billing" element={<PlatformBillingPage />} />
      </Routes>
    </MemoryRouter>,
  );
}

async function account() {
  return screen.findByRole("region", { name: "Your account" });
}

const day = (at: string) => new Date(at).toLocaleDateString();
const calendarDay = (date: string) => new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, { timeZone: "UTC" });

describe("Admin → Platform billing: the account", () => {
  it("shows a running prepaid account: its balance, what it can still spend, this period's spend, no cap, and when it was checked", async () => {
    fakeKit({ [`GET ${PAGE}`]: billing() });
    page();
    const a = await account();
    expect(within(a).getByTestId("platform-state").textContent).toBe("Running");
    expect(within(a).getByText("Prepaid")).toBeTruthy();
    expect(within(a).getByTestId("platform-balance").textContent, "to the cent").toBe("$40.00");
    expect(within(a).getByTestId("platform-headroom").textContent).toBe("$40.00");
    expect(within(a).getByTestId("platform-spend").textContent).toBe(`$12.35 since ${day("2026-10-31T18:30:00Z")}`);
    expect(within(a).getByTestId("platform-cap").textContent).toBe("None");
    expect(within(a).getByTestId("platform-checked").textContent).toBe(`Last checked ${new Date(CHECKED).toLocaleString()}`);
    expect(within(a).queryByRole("alert")).toBeNull();
    expect(within(a).queryByText(/^Running low:/)).toBeNull();
    expect((screen.getByLabelText("Low-balance level (USD)") as HTMLInputElement).value, "the level held, to the cent").toBe("100.00");
  });

  it("says when it runs low", async () => {
    fakeKit({ [`GET ${PAGE}`]: billing({ state: { ...running, state: "low", balanceUsd: "4.495", headroomUsd: "4.495" } }) });
    page();
    const a = await account();
    expect(within(a).getByTestId("platform-state").textContent).toBe("Running low");
    expect(within(a).getByText("Running low: what it can still spend is at or below your low-balance level.")).toBeTruthy();
    expect(within(a).getByTestId("platform-balance").textContent).toBe("$4.50");
    expect(within(a).queryByRole("alert")).toBeNull();
  });

  it.each([
    ["out_of_credit", "it is out of credit"],
    ["spend_cap", "it reached its monthly spend cap"],
    ["suspended", "it is suspended"],
    [null, "it gave no reason"],
  ] as const)("says it is stopped, and why: %s", async (stoppedBy, reason) => {
    fakeKit({ [`GET ${PAGE}`]: billing({ state: { ...running, state: "stopped", canSpend: false, stoppedBy, balanceUsd: "0", headroomUsd: "0" } }) });
    page();
    const a = await account();
    expect(within(a).getByRole("alert").textContent).toBe(
      `The voice platform has stopped your account: ${reason}. Your customers' paid work is paused until it can spend again.`,
    );
    expect(within(a).getByTestId("platform-state").textContent).toBe("Stopped");
    expect(within(a).getByTestId("platform-headroom").textContent).toBe("$0.00");
  });

  it("gives a postpaid account with no cap no limit, and one with a cap its cap", async () => {
    const postpaid: PlatformSpendState = { ...running, mode: "postpaid", headroomUsd: null, monthlySpendLimitUsd: null };
    fakeKit({ [`GET ${PAGE}`]: billing({ state: postpaid }) });
    page();
    let a = await account();
    expect(within(a).getByText("Postpaid")).toBeTruthy();
    expect(within(a).getByTestId("platform-headroom").textContent).toBe("No limit (postpaid, no monthly cap)");
    expect(within(a).getByTestId("platform-cap").textContent).toBe("None");
    cleanup();

    fakeKit({ [`GET ${PAGE}`]: billing({ state: { ...postpaid, monthlySpendLimitUsd: "500.0000000000", headroomUsd: "120.5" } }) });
    page();
    a = await account();
    expect(within(a).getByTestId("platform-headroom").textContent).toBe("$120.50");
    expect(within(a).getByTestId("platform-cap").textContent).toBe("$500.00");
    expect(within(a).getByTestId("platform-balance").textContent, "the balance, not what it can still spend").toBe("$40.00");
  });

  it("says it has not been read yet, before the first read", async () => {
    fakeKit({ [`GET ${PAGE}`]: billing({ state: null, checkedAt: null }) });
    page();
    const a = await account();
    expect(within(a).getByTestId("platform-state").textContent).toBe("Your account at the voice platform has not been read yet. It is read every few minutes.");
    expect(within(a).queryByText("Balance")).toBeNull();
    expect(within(a).queryByTestId("platform-checked")).toBeNull();
    expect((screen.getByLabelText("Low-balance level (USD)") as HTMLInputElement).value).toBe("");
  });

  it("shows a failed read of the page as an alert", async () => {
    fakeKit({ [`GET ${PAGE}`]: () => Response.json({ message: "Your role may not do that." }, { status: 403 }) });
    page();
    expect((await screen.findByRole("alert")).textContent).toBe("Your role may not do that.");
    expect(screen.queryByRole("region", { name: "Your account" })).toBeNull();
  });
});

describe("Admin → Platform billing: the low-balance level", () => {
  it("saves the level held untouched, a typed one trimmed, and none when empty, showing the state each answer gives", async () => {
    const answers: Record<string, { state: PlatformSpendState; checkedAt: string }> = {
      "100.00": { state: running, checkedAt: "2026-11-08T18:55:00Z" },
      "25.5": { state: { ...running, state: "low", balanceUsd: "20.0000000000", headroomUsd: "20.0000000000", lowThresholdUsd: "25.5000000000" }, checkedAt: "2026-11-08T19:00:00Z" },
      none: { state: { ...running, balanceUsd: "20.0000000000", headroomUsd: "20.0000000000", lowThresholdUsd: null }, checkedAt: "2026-11-08T19:05:00Z" },
    };
    const sent = fakeKit({
      [`GET ${PAGE}`]: billing(),
      [`PUT ${ALERT}`]: (body: unknown) => Response.json(answers[(body as { thresholdUsd: string | null }).thresholdUsd ?? "none"]),
    });
    page();
    const form = await screen.findByRole("form", { name: "Low-balance level" });
    expect(within(form).getByText(
      "The staff who handle money are emailed when the balance falls to this level, and when the voice platform stops the account. A postpaid account with no cap never counts as low.",
    )).toBeTruthy();
    const field = within(form).getByLabelText("Low-balance level (USD)") as HTMLInputElement;
    const state = () => within(screen.getByRole("region", { name: "Your account" })).getByTestId("platform-state").textContent;
    const checked = () => screen.getByTestId("platform-checked").textContent;

    fireEvent.click(within(form).getByRole("button", { name: "Save level" }));
    expect((await within(form).findByRole("status")).textContent).toBe("Saved.");
    expect(checked()).toBe(`Last checked ${new Date("2026-11-08T18:55:00Z").toLocaleString()}`);

    fireEvent.change(field, { target: { value: " 25.5 " } });
    fireEvent.click(within(form).getByRole("button", { name: "Save level" }));
    await waitFor(() => expect(state()).toBe("Running low"));
    expect(screen.getByTestId("platform-balance").textContent).toBe("$20.00");
    expect(checked()).toBe(`Last checked ${new Date("2026-11-08T19:00:00Z").toLocaleString()}`);
    expect(field.value, "the level the platform now holds").toBe("25.50");
    expect(within(form).getByRole("status").textContent).toBe("Saved.");

    fireEvent.change(field, { target: { value: "  " } });
    fireEvent.click(within(form).getByRole("button", { name: "Save level" }));
    await waitFor(() => expect(state()).toBe("Running"));
    expect(checked()).toBe(`Last checked ${new Date("2026-11-08T19:05:00Z").toLocaleString()}`);
    expect(field.value).toBe("");

    const puts = sent.filter((s) => s.method === "PUT");
    expect(puts.map((s) => s.path)).toEqual([ALERT, ALERT, ALERT]);
    expect(puts.map((s) => s.body)).toEqual([{ thresholdUsd: "100.00" }, { thresholdUsd: "25.5" }, { thresholdUsd: null }]);
    expect(sent.filter((s) => s.method === "GET").map((s) => s.path), "the page is not read again").toEqual([PAGE]);
  });

  it("shows a refused level by the field, and the platform's other refusals as an alert, keeping the state", async () => {
    const shape = "Give the level in USD: a whole number of up to 7 digits, with at most two decimals. Leave it empty for none.";
    const refusals = [
      Response.json({ message: shape, errors: { thresholdUsd: [shape] } }, { status: 422 }),
      Response.json({ message: "The voice platform did not take that level." }, { status: 422 }),
      Response.json({ message: "This platform is not connected to the voice platform yet." }, { status: 409 }),
      Response.json({ message: "The voice platform could not be reached just now. Try again shortly." }, { status: 503 }),
    ];
    const sent = fakeKit({ [`GET ${PAGE}`]: billing(), [`PUT ${ALERT}`]: () => refusals.shift()! });
    page();
    const form = await screen.findByRole("form", { name: "Low-balance level" });
    const field = within(form).getByLabelText("Low-balance level (USD)") as HTMLInputElement;
    const save = within(form).getByRole("button", { name: "Save level" });

    fireEvent.change(field, { target: { value: "12.345" } });
    fireEvent.click(save);
    const byField = await within(form).findByText(shape);
    expect(byField.className).toBe("error");
    expect(byField.closest(".field")?.querySelector("input")?.id).toBe("platform-billing-level");
    expect(within(form).queryByRole("alert")).toBeNull();

    for (const message of [
      "The voice platform did not take that level.",
      "This platform is not connected to the voice platform yet.",
      "The voice platform could not be reached just now. Try again shortly.",
    ]) {
      fireEvent.click(save);
      await waitFor(() => expect(within(form).getByRole("alert").textContent).toBe(message));
      expect(within(form).queryByText(shape), "the field's reason is gone").toBeNull();
    }
    expect(within(form).queryByRole("status")).toBeNull();
    expect(within(screen.getByRole("region", { name: "Your account" })).getByTestId("platform-state").textContent).toBe("Running");
    expect(field.value, "what was typed stays").toBe("12.345");
    expect(sent.filter((s) => s.method === "PUT").map((s) => s.body)).toEqual(Array(4).fill({ thresholdUsd: "12.345" }));
  });
});

describe("Admin → Platform billing: the invoices", () => {
  it("lists them in the order given, each with its period, status, dates, totals, lines and a Tax line of the rest", async () => {
    const more = [
      invoice({ id: "inv-aug", periodStart: "2026-08-01", periodEnd: "2026-08-31", status: "overdue", paidAt: null }),
      invoice({ id: "inv-jul", periodStart: "2026-07-01", periodEnd: "2026-07-31", status: "void", paidAt: null }),
      invoice({ id: "inv-jun", periodStart: "2026-06-01", periodEnd: "2026-06-30", status: "other", paidAt: null, total: null, totalUsd: null }),
    ];
    fakeKit({ [`GET ${PAGE}`]: billing({ invoices: [draft, issued, paid, ...more] }) });
    page();
    const list = await screen.findByRole("list", { name: "Invoices" });
    const items = within(list).getAllByRole("listitem");
    expect(items.map((li) => li.getAttribute("data-invoice"))).toEqual(["inv-nov", "inv-oct", "inv-sep", "inv-aug", "inv-jul", "inv-jun"]);
    expect(items.map((li) => within(li).getByTestId("invoice-status").textContent)).toEqual(["Not issued yet", "Issued", "Paid", "Overdue", "Void", "Other"]);

    const [nov, oct, sep, , , jun] = items;
    expect(within(nov).getByRole("heading", { level: 3 }).textContent).toBe(`${calendarDay("2026-11-01")} – ${calendarDay("2026-11-30")}`);
    expect(within(nov).getByTestId("invoice-total").textContent).toBe("₹249.00");
    expect(within(nov).getByTestId("invoice-total-usd").textContent).toBe("$3.00");
    expect(within(nov).queryByRole("table"), "a draft with no lines yet").toBeNull();
    expect(within(nov).getAllByText("—")).toHaveLength(3);

    const period = `${calendarDay("2026-10-01")} – ${calendarDay("2026-10-31")}`;
    const facts = (li: HTMLElement) => [...li.querySelectorAll("dl > div")].map((d) => `${d.querySelector("dt")?.textContent}: ${d.querySelector("dd")?.textContent}`);
    expect(facts(oct)).toEqual([
      "Status: Issued",
      `Issued on: ${day("2026-11-03T18:27:27Z")}`,
      `Due by: ${day("2026-11-10T18:27:27Z")}`,
      "Paid on: —",
      "Total: ₹1,469.10",
      "Total in US dollars: $17.70",
    ]);
    const lines = within(oct).getByRole("table", { name: `Lines of ${period}` });
    expect(within(lines).getAllByRole("row").slice(1).map((r) => [...r.children].map((c) => c.textContent))).toEqual([
      ["Chat sessions", "₹83.00"],
      ["Platform fee", "₹186.75"],
      ["Phone number rentals", "₹435.75"],
      ["AI agent calls", "₹539.50"],
      ["Adjustment", "—"],
      ["Tax", "₹224.10"],
    ]);
    expect(within(sep).queryByRole("table"), "no lines and no rest").toBeNull();
    expect(facts(sep)).toContain(`Paid on: ${day("2026-10-09T18:27:27Z")}`);
    expect(facts(jun).slice(4)).toEqual(["Total: —", "Total in US dollars: —"]);
  });

  it("shows an invoice's period as its own days to a viewer west of UTC, never the day before", async () => {
    const tz = process.env.TZ;
    process.env.TZ = "America/Los_Angeles";
    try {
      expect(new Date("2026-11-01T00:00:00Z").getDate(), "the viewer is west of UTC").toBe(31);
      fakeKit({ [`GET ${PAGE}`]: billing({ invoices: [draft] }) });
      page();
      const list = await screen.findByRole("list", { name: "Invoices" });
      const local = (month: number, date: number) => new Date(2026, month, date).toLocaleDateString();
      expect(within(list).getByRole("heading", { level: 3 }).textContent).toBe(`${local(10, 1)} – ${local(10, 30)}`);
    } finally {
      if (tz === undefined) delete process.env.TZ;
      else process.env.TZ = tz;
    }
  });

  it("shows lines without a Tax line when nothing is beyond them", async () => {
    fakeKit({ [`GET ${PAGE}`]: billing({ invoices: [{ ...issued, rest: null, lines: issued.lines.slice(0, 1), total: "83.00", totalUsd: "1.00" }] }) });
    page();
    const list = await screen.findByRole("list", { name: "Invoices" });
    expect(within(list).getByText("Chat sessions")).toBeTruthy();
    expect(within(list).queryByText("Tax")).toBeNull();
  });

  it("says there are none, and shows why they could not be read quietly, keeping the rest of the page", async () => {
    fakeKit({ [`GET ${PAGE}`]: billing() });
    page();
    const none = await screen.findByRole("region", { name: "Invoices" });
    expect(within(none).getByText("No invoices — the voice platform invoices postpaid accounts only.")).toBeTruthy();
    expect(within(none).queryByRole("list")).toBeNull();
    cleanup();

    fakeKit({ [`GET ${PAGE}`]: billing({ invoices: null, invoicesError: "The voice platform could not be reached just now. Try again shortly." }) });
    page();
    const failed = await screen.findByRole("region", { name: "Invoices" });
    expect(within(failed).getByTestId("invoices-error").textContent).toBe("The voice platform could not be reached just now. Try again shortly.");
    expect(within(failed).queryByText(/^No invoices/)).toBeNull();
    expect(screen.queryByRole("alert"), "quietly").toBeNull();
    expect(within(screen.getByRole("region", { name: "Your account" })).getByTestId("platform-state").textContent).toBe("Running");
    expect(screen.getByRole("form", { name: "Low-balance level" })).toBeTruthy();
  });
});

describe("Admin → Platform billing: who sees it", () => {
  function Where() {
    return <span data-testid="where">{useLocation().pathname}</span>;
  }
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

  it("is in the nav and opens for staff who handle money, and is neither for others", async () => {
    const sent = fakeKit({ "GET /api/kit/session": { user: { ...staff, abilities: ["money"] } }, [`GET ${PAGE}`]: billing() });
    admin("/admin/platform-billing");
    const nav = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(nav).getAllByRole("link").map((a) => [a.textContent, a.getAttribute("href")])).toEqual([
      ["Pricing", "/admin/pricing"], ["Payments", "/admin/payments"], ["Invoices", "/admin/invoices"], ["Platform billing", "/admin/platform-billing"],
    ]);
    expect(await screen.findByRole("heading", { level: 1, name: "Platform billing" })).toBeTruthy();
    expect(await screen.findByRole("region", { name: "Your account" })).toBeTruthy();
    expect(sent.map((s) => s.path)).toContain(PAGE);
    cleanup();

    const other = fakeKit({ "GET /api/kit/session": { user: { ...staff, role: "support", abilities: ["health", "customers_read", "reports"] } } });
    admin("/admin/platform-billing");
    const theirs = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(theirs).queryByRole("link", { name: "Platform billing" })).toBeNull();
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/admin/dashboard"));
    expect(screen.queryByRole("heading", { level: 1, name: "Platform billing" })).toBeNull();
    expect(other.some((s) => s.path === PAGE)).toBe(false);
  });
});
