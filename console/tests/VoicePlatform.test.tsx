/**
 * Admin → Voice platform (admin/VoicePlatformPage.tsx) and the dashboard's warnings about it, against a fake backend:
 * scheduled jobs that do not run are said loudly, with the cron line (never-run and stopped-since alike), and late
 * ones are marked; failed events and refused changes are listed with their reasons and tried again, the page then
 * showing what the backend answers; customers whose money check shows a gap are listed, link to their page and can be
 * checked now; the dashboard sends each of these to this page.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AdminDashboard, VoicePlatformHealth } from "../src/api";
import { DashboardPage } from "../src/admin/DashboardPage";
import { VoicePlatformPage } from "../src/admin/VoicePlatformPage";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function health(over: Partial<VoicePlatformHealth> = {}): VoicePlatformHealth {
  return {
    telenow: { name: "Telenow", connected: true, account: "Acme Ops", keyRejected: false, error: null, checkedAt: "2026-10-31T11:55:00Z" },
    callHealth: null,
    events: {
      registered: true, url: "https://voice.example/api/kit/telenow/events", registeredAt: "2026-10-30T10:00:00Z", checkedAt: null, error: null,
      lastReceivedAt: "2026-10-31T11:50:00Z", recentHours: 24, recent: 12, waiting: 1, failed: 1,
      failures: [{ id: 9, event: "call.completed", error: "No such call", failedAt: "2026-10-31T11:00:00Z", attempts: 8 }],
    },
    writes: {
      waiting: 0, failed: 1,
      failures: [{ id: 4, resource: "workspace", kitId: "w1", operation: "create", error: "sub-account limit reached", failedAt: "2026-10-31T10:00:00Z", attempts: 8 }],
    },
    jobs: {
      lastStartedAt: "2026-10-31T11:59:00Z", running: true,
      jobs: [
        { command: "kit:catalog", expression: "0 * * * *", startedAt: "2026-10-31T09:00:00Z", finishedAt: "2026-10-31T09:00:02Z", succeeded: true, late: true },
        { command: "kit:outbox", expression: "* * * * *", startedAt: "2026-10-31T11:59:00Z", finishedAt: null, succeeded: false, late: false },
      ],
    },
    reconciliation: {
      everyMinutes: 60, toleranceUsd: "0.01", checked: 3, oldestCheckAt: "2026-10-31T11:00:00Z", drifting: 1,
      customers: [{
        customerId: "w-bolt", name: "Bolt Clinics", telenowSpendUsd: "4.5000000000", chargedCostUsd: "2.000000", driftUsd: "2.5000000000",
        periodFrom: "2026-09-30T18:30:00Z", telenowPeriodCalls: 5, kitPeriodCalls: 4, unchargedCalls: 1, checkedAt: "2026-10-31T11:00:00Z", error: null,
      }],
    },
    ...over,
  };
}

function at(element: ReactElement) {
  render(
    <MemoryRouter initialEntries={["/admin/telenow"]}>
      <Routes>
        <Route path="/admin/telenow" element={element} />
        <Route path="/admin/dashboard" element={element} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("Admin → Voice platform", () => {
  it("lists what failed, and tries it again", async () => {
    const after = health({ events: { ...health().events, failed: 0, failures: [] }, writes: { waiting: 1, failed: 0, failures: [] } });
    const sent = fakeKit({
      "GET /api/kit/admin/telenow": health(),
      "POST /api/kit/admin/telenow/events/9/retry": after,
      "POST /api/kit/admin/telenow/writes/4/retry": after,
      "POST /api/kit/admin/telenow/reconcile/w-bolt": health({ reconciliation: { ...health().reconciliation, drifting: 0, customers: [] } }),
    });
    at(<VoicePlatformPage />);
    const jobs = await screen.findByRole("table", { name: "Scheduled jobs" });
    expect(screen.queryByRole("alert"), "jobs run: nothing loud").toBeNull();
    expect(within(jobs.querySelector('[data-job="kit:catalog"]') as HTMLElement).getByText("late")).toBeTruthy();
    expect(within(jobs.querySelector('[data-job="kit:outbox"]') as HTMLElement).queryByText("late")).toBeNull();
    expect(within(jobs.querySelector('[data-job="kit:outbox"]') as HTMLElement).getByText("Failed")).toBeTruthy();

    const money = screen.getByRole("table", { name: "Money check" });
    const bolt = money.querySelector('[data-customer="w-bolt"]') as HTMLElement;
    expect(within(bolt).getByRole("link", { name: "Bolt Clinics" }).getAttribute("href")).toBe("/admin/customers/w-bolt");
    expect(bolt.textContent).toContain("$2.50");
    expect(bolt.textContent).toContain("5 / 4");

    const event = document.querySelector('[data-event="9"]') as HTMLElement;
    expect(within(event).getByText("No such call")).toBeTruthy();
    fireEvent.click(within(event).getByRole("button", { name: "Try again" }));
    expect(await screen.findByText((_, el) => el?.getAttribute("data-testid") === "events-failed" && el.textContent === "0")).toBeTruthy();
    expect(screen.getByTestId("writes-failed").textContent).toBe("0");

    fireEvent.click(within(screen.getByRole("table", { name: "Money check" })).getByRole("button", { name: "Check now" }));
    expect(await screen.findByText((_, el) => el?.getAttribute("data-testid") === "drifting" && el.textContent === "0")).toBeTruthy();
    expect(sent.filter((s) => s.method === "POST").map((s) => s.path)).toEqual([
      "/api/kit/admin/telenow/events/9/retry",
      "/api/kit/admin/telenow/reconcile/w-bolt",
    ]);
  });

  it("says loudly when scheduled jobs do not run, with the cron line", async () => {
    fakeKit({ "GET /api/kit/admin/telenow": health({ jobs: { lastStartedAt: null, running: false, jobs: [] } }) });
    at(<VoicePlatformPage />);
    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toContain("Scheduled jobs have never run.");
    expect(within(alert).getByText("* * * * * php artisan schedule:run")).toBeTruthy();
    cleanup();

    fakeKit({ "GET /api/kit/admin/telenow": health({ jobs: { ...health().jobs, running: false } }), "POST /api/kit/admin/telenow/writes/4/retry": () => Response.json({ message: "That change is not one the voice platform refused." }, { status: 404 }) });
    at(<VoicePlatformPage />);
    expect((await screen.findByRole("alert")).textContent).toContain("Scheduled jobs have not run since");
    fireEvent.click(within(document.querySelector('[data-write="4"]') as HTMLElement).getByRole("button", { name: "Try again" }));
    expect(await screen.findByText("That change is not one the voice platform refused.")).toBeTruthy();
    expect(screen.getByTestId("writes-failed").textContent, "the page stays as it was").toBe("1");
  });

  it("is where the dashboard sends what needs a look about it", async () => {
    const d: AdminDashboard = {
      period: { from: "2026-10-01", to: "2026-10-31" }, money: [], topUps: [], creditsByHand: [],
      customers: { total: 0, active: 0, suspended: 0, notReady: 0, outOfCredit: null, new: 0 }, calls: { count: 0, seconds: 0, live: 0 },
      telenow: { name: "Telenow", billingUrl: "https://app.telenow.ai/billing", connected: true, keyRejected: false, error: null, checkedAt: null, account: null },
      attention: { callsNotCharged: 0, failedWrites: 2, paymentsToReview: 0, customersNotSynced: 0, eventsFailed: 3, customersDrifting: 1, jobsNotRunning: true, providersFailing: 0 },
    };
    fakeKit({ "GET /api/kit/admin/dashboard": d });
    at(<DashboardPage />);
    const look = await screen.findByRole("region", { name: "Needs a look" });
    expect(within(look).getByRole("alert").textContent).toBe("Scheduled jobs are not running: nothing is charged, synced or sent.");
    for (const name of ["Scheduled jobs are not running: nothing is charged, synced or sent.", "Changes the voice platform refused: 2",
      "Events that failed: 3", "Customers whose money check shows a gap: 1"]) {
      expect(within(look).getByRole("link", { name }).getAttribute("href")).toBe("/admin/telenow");
    }
    expect(within(look).queryByText("Nothing needs a look.")).toBeNull();
  });
});
