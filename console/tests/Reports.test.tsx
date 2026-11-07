/**
 * Admin → Dashboard and Admin → Cost vs price (admin/DashboardPage.tsx, CostsPage.tsx, PeriodPicker.tsx) against a
 * fake backend: the operator's voice-platform account in our words (a value we have no words for shows as it is),
 * paused with its reason and a link to its Billing page; the period's money, customers and calls; what needs a look,
 * each linking to where it is handled, or that nothing does; another period asked for, and a refused one shown by its
 * field; a date typed the moment the period shows is kept (it was lost on a slow CI runner); the cost report grouped as picked, its customers linking to their page, the totals, and its pages; the admin
 * panel opens on the dashboard.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { createRoot } from "react-dom/client";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AdminDashboard, CostReport } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { CostsPage } from "../src/admin/CostsPage";
import { DashboardPage } from "../src/admin/DashboardPage";
import { PeriodPicker } from "../src/admin/PeriodPicker";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const totals = { currency: "INR", count: 3, price: "47.50", cost: "26.64", costUsd: "0.3200", margin: "20.86", marginPercent: "43.9" };

function dashboard(over: Partial<AdminDashboard> = {}): AdminDashboard {
  return {
    period: { from: "2026-10-01", to: "2026-10-31" },
    money: [totals],
    topUps: [{ currency: "INR", count: 2, amount: "1490", refunded: "100" }],
    creditsByHand: [{ currency: "INR", count: 1, amount: "200" }],
    customers: { total: 3, active: 2, suspended: 1, notReady: 1, outOfCredit: null, new: 1 },
    calls: { count: 6, seconds: 300, live: 1 },
    telenow: {
      name: "Telenow", billingUrl: "https://app.telenow.ai/billing", connected: true, keyRejected: false, error: null, checkedAt: "2026-10-31T11:55:00Z",
      account: { mode: "prepaid", state: "low", canSpend: true, stoppedBy: null, balanceUsd: "4.4950000000", monthlySpendLimitUsd: null, periodSpendUsd: "35.50", lowThresholdUsd: "5" },
    },
    attention: { callsNotCharged: 1, failedWrites: 0, paymentsToReview: 2, customersNotSynced: 0, eventsFailed: 0, customersDrifting: 0, jobsNotRunning: false },
    ...over,
  };
}

function report(over: Partial<CostReport> = {}): CostReport {
  return {
    period: { from: "2026-10-01", to: "2026-10-31" },
    by: "customer",
    groups: [{ value: "customer", label: "Customer" }, { value: "component", label: "What was charged" }],
    rows: [
      { key: "w-bolt", label: "Bolt Clinics", currency: "INR", count: 1, price: "30.00", cost: "16.65", costUsd: "0.2000", margin: "13.35", marginPercent: "44.5" },
      { key: "w-acme", label: "Acme Dental", currency: "INR", count: 2, price: "17.50", cost: "9.99", costUsd: "0.1200", margin: "7.51", marginPercent: "42.9" },
    ],
    totals: [totals],
    page: 1,
    perPage: 2,
    total: 3,
    ...over,
  };
}

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function at(path: string, element: ReactElement) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/dashboard" element={element} />
        <Route path="/admin/costs" element={element} />
        <Route path="*" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("Admin → Dashboard", () => {
  it("shows the account, the period's money, customers and calls, and what needs a look", async () => {
    fakeKit({ "GET /api/kit/admin/dashboard": dashboard() });
    at("/admin/dashboard", <DashboardPage />);
    const account = await screen.findByRole("region", { name: "Your Telenow account" });
    expect(within(account).getByTestId("account-state").textContent).toBe("Running low");
    expect(within(account).getByText("Prepaid")).toBeTruthy();
    expect(within(account).queryByRole("alert")).toBeNull();
    expect(within(account).getByRole("link", { name: "Open Telenow billing" }).getAttribute("href")).toBe("https://app.telenow.ai/billing");
    expect(within(account).getByText("$4.50"), "to the cent").toBeTruthy();

    const money = screen.getByRole("region", { name: "Money" });
    expect(within(money).getByTestId("charged-INR").textContent).toContain("43.9%");
    expect(within(money).getByText("Top-ups (2)")).toBeTruthy();
    expect(within(money).getByText("Credit added by staff (1)")).toBeTruthy();
    expect(screen.queryByText("Out of credit"), "not while pricing is not in force").toBeNull();
    expect(screen.getByTestId("live-calls").textContent).toBe("1");

    const look = screen.getByRole("region", { name: "Needs a look" });
    expect(within(look).getByRole("link", { name: "Payments to review: 2" }).getAttribute("href")).toBe("/admin/payments");
    expect(within(look).getByRole("link", { name: /Calls not charged .*: 1\./ }).getAttribute("href")).toBe("/admin/pricing");
    expect(within(look).queryByText(/refused/), "nothing at zero").toBeNull();
  });

  it("says why the account is paused, in its words when it has none of ours, and when nothing needs a look", async () => {
    const d = dashboard({ attention: { callsNotCharged: 0, failedWrites: 0, paymentsToReview: 0, customersNotSynced: 0, eventsFailed: 0, customersDrifting: 0, jobsNotRunning: false } });
    d.telenow.account = { ...d.telenow.account, state: "stopped", canSpend: false, stoppedBy: "spend_cap", monthlySpendLimitUsd: "50" };
    fakeKit({ "GET /api/kit/admin/dashboard": d });
    at("/admin/dashboard", <DashboardPage />);
    const account = await screen.findByRole("region", { name: "Your Telenow account" });
    expect(within(account).getByRole("alert").textContent).toBe("Your customers' paid work is paused: the monthly spend cap is reached.");
    expect(within(account).getByText("$35.50 of $50.00")).toBeTruthy();
    expect(screen.getByText("Nothing needs a look.")).toBeTruthy();
    cleanup();

    const unknown = dashboard();
    unknown.telenow.account = { ...unknown.telenow.account, canSpend: false, state: "frozen", stoppedBy: "new_reason" };
    fakeKit({ "GET /api/kit/admin/dashboard": unknown });
    at("/admin/dashboard", <DashboardPage />);
    expect((await screen.findByRole("alert")).textContent).toBe("Your customers' paid work is paused: new_reason.");
    expect(screen.getByTestId("account-state").textContent).toBe("frozen");
  });

  it("asks for another period, and shows a refused one by its field", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/dashboard": dashboard(),
      "GET /api/kit/admin/dashboard?from=2026-10-05&to=2026-10-06": dashboard({ period: { from: "2026-10-05", to: "2026-10-06" }, money: [], topUps: [], creditsByHand: [] }),
      "GET /api/kit/admin/dashboard?from=2026-10-06&to=2026-10-05": () =>
        Response.json({ message: "Invalid.", errors: { to: ["The To field must be a date after or equal to from."] } }, { status: 422 }),
    });
    at("/admin/dashboard", <DashboardPage />);
    const period = await screen.findByRole("form", { name: "Period" });
    fireEvent.change(within(period).getByLabelText("From"), { target: { value: "2026-10-05" } });
    fireEvent.change(within(period).getByLabelText("To"), { target: { value: "2026-10-06" } });
    fireEvent.click(within(period).getByRole("button", { name: "Show" }));
    expect(await screen.findByText("No charges, top-ups or credits in this period.")).toBeTruthy();

    fireEvent.change(within(period).getByLabelText("From"), { target: { value: "2026-10-06" } });
    fireEvent.change(within(period).getByLabelText("To"), { target: { value: "2026-10-05" } });
    fireEvent.click(within(period).getByRole("button", { name: "Show" }));
    expect(await within(period).findByText("The To field must be a date after or equal to from.")).toBeTruthy();
    expect(sent.map((s) => s.path)).toEqual([
      "/api/kit/admin/dashboard",
      "/api/kit/admin/dashboard?from=2026-10-05&to=2026-10-06",
      "/api/kit/admin/dashboard?from=2026-10-06&to=2026-10-05",
    ]);
  });

  it("keeps a date typed as soon as the period shows, before React's after-render work has run", async () => {
    // Outside act, as on a slow machine: the picker is on the page but what React runs after showing it has not run yet.
    const was = (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT;
    (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = false;
    const host = document.body.appendChild(document.createElement("div"));
    const root = createRoot(host);
    try {
      const shown = new Promise<void>((resolve) => {
        const seen = new MutationObserver(() => {
          if (host.querySelector("#period-from")) {
            seen.disconnect();
            resolve();
          }
        });
        seen.observe(host, { childList: true, subtree: true });
      });
      root.render(<PeriodPicker period={{ from: "2026-10-01", to: "2026-10-31" }} errors={{}} onPick={() => {}} />);
      await shown;
      fireEvent.change(host.querySelector("#period-from")!, { target: { value: "2026-10-05" } });
      fireEvent.change(host.querySelector("#period-to")!, { target: { value: "2026-10-06" } });
      await new Promise((settled) => setTimeout(settled, 50));
      const field = (id: string) => (host.querySelector(id) as HTMLInputElement).value;
      expect([field("#period-from"), field("#period-to")]).toEqual(["2026-10-05", "2026-10-06"]);
    } finally {
      root.unmount();
      host.remove();
      (globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = was;
    }
  });

  it("is where the admin panel opens", async () => {
    fakeKit({
      "GET /api/kit/session": { user: { id: 1, name: "Op", email: "op@example.com", role: "owner" } },
      "GET /api/kit/admin/dashboard": dashboard(),
    });
    render(
      <MemoryRouter initialEntries={["/admin"]}>
        <Routes>
          <Route path="/admin/*" element={<AdminArea />} />
        </Routes>
      </MemoryRouter>,
    );
    expect(await screen.findByRole("heading", { level: 1, name: "Dashboard" })).toBeTruthy();
  });
});

describe("Admin → Cost vs price", () => {
  it("groups the period's charges as picked, with the totals and pages", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/costs": report(),
      "GET /api/kit/admin/costs?by=component&from=2026-10-01&to=2026-10-31": report({
        by: "component", total: 1,
        rows: [{ key: "component:llm", label: "Language model", currency: "INR", count: 4, price: "10.00", cost: "3.00", costUsd: "0.0360", margin: "7.00", marginPercent: "70.0" }],
      }),
      "GET /api/kit/admin/costs?by=customer&from=2026-10-01&to=2026-10-31&page=2": report({
        page: 2, rows: [{ key: "w-cora", label: "Cora Vets", currency: "INR", count: 1, price: "0.00", cost: "0.00", costUsd: "0.0000", margin: "0.00", marginPercent: null }],
      }),
    });
    at("/admin/costs", <CostsPage />);
    const table = await screen.findByRole("table", { name: "Cost vs price" });
    expect(within(table).getByRole("columnheader", { name: "Customer" })).toBeTruthy();
    expect(within(table).getByRole("link", { name: "Bolt Clinics" }).getAttribute("href")).toBe("/admin/customers/w-bolt");
    const total = table.querySelector('[data-row="total"]') as HTMLElement;
    expect(total.textContent).toContain("43.9%");
    expect(within(table.querySelector('[data-row="w-acme"]') as HTMLElement).getByTitle(/0\.12/)).toBeTruthy();
    expect(screen.getByText("Page 1 of 2")).toBeTruthy();

    fireEvent.click(screen.getByRole("button", { name: "Next" }));
    const second = await screen.findByText("Cora Vets");
    expect(second.closest("tr")?.textContent).toContain("—");

    fireEvent.change(screen.getByLabelText("Group by"), { target: { value: "component" } });
    const byWhat = await screen.findByRole("columnheader", { name: "What was charged" });
    expect(byWhat).toBeTruthy();
    expect(screen.getByRole("columnheader", { name: "Lines" })).toBeTruthy();
    expect(screen.queryByRole("link", { name: "Language model" }), "only customers link").toBeNull();
    expect(screen.queryByText("Page 1 of 1")).toBeNull();
    expect(sent.map((s) => s.path)).toEqual([
      "/api/kit/admin/costs",
      "/api/kit/admin/costs?by=customer&from=2026-10-01&to=2026-10-31&page=2",
      "/api/kit/admin/costs?by=component&from=2026-10-01&to=2026-10-31",
    ]);
  });
});
