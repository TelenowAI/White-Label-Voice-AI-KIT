/**
 * The processing country (admin/ProcessingCountry.tsx) on Admin → Voice platform (the operator's account) and on a
 * customer's page, against a fake backend: the country by name with its code, where it comes from in words for each
 * source (the account's country; its time zone at sign-up; not known, with where to set it), none when the platform has
 * none, when it was checked, and what it means (a customer keeps the country it had when it was made); Check again
 * reads it now (`fresh=1`, the customer's id in the path) and shows what it answers; a failed read is said in the panel
 * only — a 409 quietly, others as an error, never an alert — with the rest of the page kept and a country already shown
 * kept on a failed Check again.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ProcessingCountry, VoicePlatformHealth } from "../src/api";
import { CustomerPage } from "../src/admin/CustomerPage";
import { VoicePlatformPage } from "../src/admin/VoicePlatformPage";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const PLATFORM = "/api/kit/admin/telenow/processing-country";
const CUSTOMER = "/api/kit/admin/customers/w9/processing-country";
const CHECKED = "2026-11-09T08:15:00Z";
const LATER = "2026-11-09T10:40:00Z";

const health: VoicePlatformHealth = {
  telenow: { name: "Telenow", connected: true, account: "Acme Ops", keyRejected: false, error: null, checkedAt: "2026-11-09T08:00:00Z" },
  callHealth: null,
  events: { registered: true, url: null, registeredAt: null, checkedAt: null, error: null, lastReceivedAt: null, recentHours: 24, recent: 0, waiting: 0, failed: 0, failures: [] },
  writes: { waiting: 0, failed: 0, failures: [] },
  jobs: { lastStartedAt: "2026-11-09T08:00:00Z", running: true, jobs: [] },
  reconciliation: { everyMinutes: 60, toleranceUsd: "0.01", checked: 0, oldestCheckAt: null, drifting: 0, customers: [] },
};

const customer = { id: "w9", name: "Acme Dental", status: "active", syncState: "synced", syncError: null, telenowSubAccountId: "sub-9", balance: "0",
  priceListId: null, maxConcurrentCalls: null, monthlyCapUsd: null, closeRequestedAt: null, closedAt: null, createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-29T10:00:00Z" };
const team = { members: [], invites: [], roles: ["owner", "member"], canManage: true };

const country = (over: Partial<ProcessingCountry> = {}): ProcessingCountry => ({ country: "IN", source: "country", checkedAt: CHECKED, ...over });

function at(path: string, element: ReactElement, route: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={route} element={element} />
      </Routes>
    </MemoryRouter>,
  );
}

const platformPage = () => at("/admin/telenow", <VoicePlatformPage />, "/admin/telenow");
const customerPage = () => at("/admin/customers/w9", <CustomerPage />, "/admin/customers/:id");
const panel = () => screen.findByRole("region", { name: "Processing country" });
const fact = (id: string) => screen.getByTestId(id).textContent;

describe("the processing country on Admin → Voice platform", () => {
  it("shows the operator's country by name and code, where it comes from, when checked, what it means, and checks again now", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/telenow": health,
      [`GET ${PLATFORM}`]: country(),
      [`GET ${PLATFORM}?fresh=1`]: country({ country: "DE", source: "time_zone", checkedAt: LATER }),
    });
    platformPage();
    const region = await panel();
    expect(await within(region).findByText("India (IN)")).toBeTruthy();
    expect(fact("processing-country-source")).toBe("The account's country");
    expect(fact("processing-country-checked")).toBe(new Date(CHECKED).toLocaleString());
    expect(region.textContent).toContain("serves an agent's language model from a region that holds this country, when the agent leaves the region to it");
    expect(region.textContent).toContain("It is set on the voice platform, not here. A customer copies yours when the customer is made, and keeps it after yours changes.");
    expect(region.textContent).not.toContain("Telenow");

    fireEvent.click(within(region).getByRole("button", { name: "Check again" }));
    expect(await within(region).findByText("Germany (DE)")).toBeTruthy();
    expect(fact("processing-country-source")).toBe("The account's time zone at sign-up: no country is set on the account");
    expect(fact("processing-country-checked")).toBe(new Date(LATER).toLocaleString());
    expect(sent.filter((s) => s.path.startsWith(PLATFORM)).map((s) => s.path)).toEqual([PLATFORM, `${PLATFORM}?fresh=1`]);
  });

  it("says none and not known, with where to set it, when the platform has no country for the account", async () => {
    fakeKit({ "GET /api/kit/admin/telenow": health, [`GET ${PLATFORM}`]: country({ country: null, source: "unknown" }) });
    platformPage();
    await panel();
    expect(await screen.findByText("Not known: set the account's country on the voice platform")).toBeTruthy();
    expect(fact("processing-country")).toBe("None");
  });

  it("shows a code the browser has no name for as it is", async () => {
    fakeKit({ "GET /api/kit/admin/telenow": health, [`GET ${PLATFORM}`]: country({ country: "XZ" }) });
    platformPage();
    await panel();
    await vi.waitFor(() => expect(fact("processing-country")).toBe("XZ"));
  });

  it("says a failed read in the panel only, keeps the rest of the page, and keeps the country shown when Check again fails", async () => {
    let reads = 0;
    fakeKit({
      "GET /api/kit/admin/telenow": health,
      [`GET ${PLATFORM}`]: () => Response.json({ message: "The processing country could not be read just now. Try again shortly." }, { status: 502 }),
    });
    platformPage();
    const region = await panel();
    const failure = await within(region).findByTestId("processing-country-failure");
    expect(failure.textContent).toBe("The processing country could not be read just now. Try again shortly.");
    expect(failure.className).toBe("error");
    expect(screen.queryByRole("alert"), "not the page's alert").toBeNull();
    expect(screen.getByRole("heading", { name: "Changes sent to the voice platform" })).toBeTruthy();
    expect(screen.getByRole("heading", { name: "Money check" })).toBeTruthy();
    cleanup();

    fakeKit({
      "GET /api/kit/admin/telenow": health,
      [`GET ${PLATFORM}`]: country(),
      [`GET ${PLATFORM}?fresh=1`]: () => (++reads, Response.json({ message: "The processing country could not be read just now. Try again shortly." }, { status: 502 })),
    });
    platformPage();
    const again = await panel();
    await within(again).findByText("India (IN)");
    fireEvent.click(within(again).getByRole("button", { name: "Check again" }));
    expect((await within(again).findByTestId("processing-country-failure")).textContent).toBe("The processing country could not be read just now. Try again shortly.");
    expect(fact("processing-country"), "the country read before stays").toBe("India (IN)");
    expect(reads).toBe(1);
  });

  it("says a missing key quietly", async () => {
    fakeKit({
      "GET /api/kit/admin/telenow": { ...health, telenow: { ...health.telenow, connected: false } },
      [`GET ${PLATFORM}`]: () => Response.json({ message: "Set the voice platform's key first." }, { status: 409 }),
    });
    platformPage();
    const failure = await within(await panel()).findByTestId("processing-country-failure");
    expect(failure.textContent).toBe("Set the voice platform's key first.");
    expect(failure.className).toBe("muted");
  });
});

describe("the processing country on a customer's page", () => {
  it("shows the customer's country, says it keeps the one it had when made, and checks again by its id", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/customers/w9": customer,
      "GET /api/kit/admin/customers/w9/team": team,
      [`GET ${CUSTOMER}`]: country({ source: "time_zone" }),
      [`GET ${CUSTOMER}?fresh=1`]: country({ country: "GB", source: "country", checkedAt: LATER }),
    });
    customerPage();
    const region = await panel();
    expect(await within(region).findByText("India (IN)")).toBeTruthy();
    expect(fact("processing-country-source")).toBe("The account's time zone at sign-up: no country is set on the account");
    expect(region.textContent).toContain("A customer keeps the country it had when it was made, even after yours changes.");
    expect(region.textContent).toContain("from a region that holds this country, when an agent leaves the region to it");
    expect(region.textContent).not.toContain("Telenow");

    fireEvent.click(within(region).getByRole("button", { name: "Check again" }));
    expect(await within(region).findByText("United Kingdom (GB)")).toBeTruthy();
    expect(fact("processing-country-source")).toBe("The account's country");
    expect(fact("processing-country-checked")).toBe(new Date(LATER).toLocaleString());
    expect(sent.filter((s) => s.path.includes("processing-country")).map((s) => s.path)).toEqual([CUSTOMER, `${CUSTOMER}?fresh=1`]);
  });

  it("says quietly that a customer has no account on the voice platform yet, and keeps the rest of the page", async () => {
    fakeKit({
      "GET /api/kit/admin/customers/w9": customer,
      "GET /api/kit/admin/customers/w9/team": { ...team, canManage: false },
      [`GET ${CUSTOMER}`]: () => Response.json({ message: "This customer has no account on the voice platform yet." }, { status: 409 }),
    });
    customerPage();
    const failure = await within(await panel()).findByTestId("processing-country-failure");
    expect(failure.textContent).toBe("This customer has no account on the voice platform yet.");
    expect(failure.className).toBe("muted");
    expect(screen.queryByTestId("processing-country")).toBeNull();
    expect(screen.getByRole("heading", { name: "Acme Dental" })).toBeTruthy();
    expect(await screen.findByText("Nobody has joined yet.")).toBeTruthy();
  });
});
