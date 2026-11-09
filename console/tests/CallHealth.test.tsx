/**
 * Call health (calls/CallHealthPanel.tsx, admin/PlatformCallHealth.tsx, the dashboard's item), against a fake backend. Console →
 * Home: the account's failures with the part and cause in plain words, the calls, and the agents linked to their pages; the window
 * asked by `minutes` (the last hour first, then 6 hours or a day); "No failures in the last hour" when there are none; a 409 or 502
 * said quietly with the page kept. An agent's page: only the failures that name that agent. Admin → a customer's page: the same with
 * the staff fields and the provider's last error, the customer's id in the path. Admin → Voice platform: not run yet before the
 * first sweep; then when, over how long, the accounts, and each provider with its customers linked, flagged at the alert level or
 * over. The dashboard: providers failing as one more "needs a look" item, only when there are some.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AdminDashboard, Agent, CallHealth, ConsoleHome, PlatformCallHealth, VoicePlatformHealth, WorkspaceList } from "../src/api";
import { CustomerPage } from "../src/admin/CustomerPage";
import { DashboardPage } from "../src/admin/DashboardPage";
import { VoicePlatformPage } from "../src/admin/VoicePlatformPage";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { agentSettings, settingsForm } from "./agentSettings";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const HEALTH = "/api/kit/console/call-health";
const CHECKED = "2026-11-09T10:00:00Z";
const LAST = "2026-11-09T09:41:00Z";

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/catalog": { llm: [], stt: [], tts: [], defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null } },
  "GET /api/kit/console/agents/settings-form": settingsForm,
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
};

function agent(id: string, name: string): Agent {
  return {
    id, name, description: null, systemPrompt: null, isActive: true,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: agentSettings,
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [], logoUrl: null, syncState: "synced", syncError: null,
    createdAt: "2026-11-09T09:00:00Z", updatedAt: "2026-11-09T09:00:00Z",
  };
}

const frontDesk = { id: "a1", name: "Front desk" };
const nightDesk = { id: "a2", name: "Night desk" };

function health(over: Partial<CallHealth> = {}): CallHealth {
  return {
    windowMinutes: 60, callsAffected: 5, checkedAt: CHECKED,
    failures: [
      { part: "tts", provider: "Studio voices", cause: "setup", calls: 4, lastAt: LAST, agents: [frontDesk, nightDesk] },
      { part: "stt", provider: "the speech recognition provider", cause: "provider", calls: 2, lastAt: null, agents: [nightDesk] },
      { part: "llm", provider: "Fast models", cause: "platform", calls: 1, lastAt: LAST, agents: [nightDesk] },
      { part: "other", provider: "the call", cause: "unknown", calls: 1, lastAt: LAST, agents: [nightDesk] },
    ],
    ...over,
  };
}

const none = (minutes: number): CallHealth => ({ windowMinutes: minutes, callsAffected: 0, checkedAt: CHECKED, failures: [] });

function openConsole(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

function at(path: string, element: ReactElement, route: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={route} element={element} />
      </Routes>
    </MemoryRouter>,
  );
}

const panel = () => screen.findByRole("region", { name: "Call health" });
const row = (region: HTMLElement, key: string) => region.querySelector(`[data-failure="${key}"]`) as HTMLElement | null;
const cells = (r: HTMLElement) => [...r.querySelectorAll("td, th")].map((c) => c.textContent);

describe("call health in the console", () => {
  it("lists the account's failures on the home page in plain words, links the agents, and asks for another window by minutes", async () => {
    const sent = fakeKit({ ...signedIn, [`GET ${HEALTH}?minutes=60`]: health(), [`GET ${HEALTH}?minutes=360`]: none(360) });
    openConsole("/console");
    const region = await panel();
    await waitFor(() => expect(row(region, "tts:Studio voices")).toBeTruthy());
    expect(cells(row(region, "tts:Studio voices")!)).toEqual([
      "Voice", "Studio voices", "The agent's own setup (for example, a voice the provider does not have)", "4", new Date(LAST).toLocaleString(), "Front desk, Night desk",
    ]);
    expect(cells(row(region, "stt:the speech recognition provider")!).slice(0, 5)).toEqual(["Speech recognition", "the speech recognition provider", "The provider had a problem", "2", "—"]);
    expect(cells(row(region, "llm:Fast models")!).slice(0, 3)).toEqual(["Language model", "Fast models", "The voice platform had a problem"]);
    expect(cells(row(region, "other:the call")!).slice(0, 3)).toEqual(["Another part", "the call", "Not known"]);
    expect(within(row(region, "tts:Studio voices")!).getByRole("link", { name: "Front desk" }).getAttribute("href")).toBe("/console/agents/a1");
    expect(within(row(region, "tts:Studio voices")!).getByRole("link", { name: "Night desk" }).getAttribute("href")).toBe("/console/agents/a2");
    expect(region.textContent).toContain("Calls with a failure: 5");
    expect(region.textContent).toContain(`Checked ${new Date(CHECKED).toLocaleString()}`);
    expect(region.textContent).not.toMatch(/telenow/i);
    expect(screen.getByRole("heading", { name: "Welcome to Acme Dental" })).toBeTruthy();

    const over = within(region).getByLabelText("Over") as HTMLSelectElement;
    expect([...over.options].map((o) => [o.value, o.textContent])).toEqual([["60", "The last hour"], ["360", "The last 6 hours"], ["1440", "The last day"]]);
    fireEvent.change(over, { target: { value: "360" } });
    expect((await within(region).findByTestId("call-health-none")).textContent).toBe("No failures in the last 6 hours.");
    expect(row(region, "tts:Studio voices")).toBeNull();
    expect(sent.filter((s) => s.path.startsWith(HEALTH)).map((s) => s.path)).toEqual([`${HEALTH}?minutes=60`, `${HEALTH}?minutes=360`]);
  });

  it("drops what an earlier window showed when another cannot be read", async () => {
    fakeKit({ ...signedIn, [`GET ${HEALTH}?minutes=60`]: health(), [`GET ${HEALTH}?minutes=1440`]: () => Response.json({ message: "Call health cannot be read now." }, { status: 502 }) });
    openConsole("/console");
    const region = await panel();
    await waitFor(() => expect(row(region, "tts:Studio voices")).toBeTruthy());
    fireEvent.change(within(region).getByLabelText("Over"), { target: { value: "1440" } });
    expect((await within(region).findByTestId("call-health-failure")).textContent).toBe("Call health cannot be read now.");
    expect(row(region, "tts:Studio voices")).toBeNull();
    expect(region.textContent).not.toContain("Calls with a failure");
  });

  it("says when there are no failures in the last hour", async () => {
    fakeKit({ ...signedIn, [`GET ${HEALTH}?minutes=60`]: none(60) });
    openConsole("/console");
    const region = await panel();
    expect((await within(region).findByTestId("call-health-none")).textContent).toBe("No failures in the last hour.");
    expect(within(region).queryByRole("table")).toBeNull();
  });

  it("says a window it has no words for by its minutes", async () => {
    fakeKit({ ...signedIn, [`GET ${HEALTH}?minutes=60`]: none(90) });
    openConsole("/console");
    expect((await within(await panel()).findByTestId("call-health-none")).textContent).toBe("No failures in the last 90 minutes.");
  });

  it.each([
    [409, "This account is not set up on the voice platform yet."],
    [502, "Call health cannot be read now."],
  ])("says a %s quietly and keeps the page", async (status, message) => {
    fakeKit({ ...signedIn, [`GET ${HEALTH}?minutes=60`]: () => Response.json({ message }, { status }) });
    openConsole("/console");
    const region = await panel();
    const said = await within(region).findByTestId("call-health-failure");
    expect(said.textContent).toBe(message);
    expect(said.className).toBe("muted");
    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.getByRole("heading", { name: "Welcome to Acme Dental" })).toBeTruthy();
    expect(within(region).getByLabelText("Over")).toBeTruthy();
  });

  it("shows on an agent's page only the failures that name that agent", async () => {
    const pageOf = (a: Agent) => ({
      [`GET /api/kit/console/agents/${a.id}`]: a,
      [`GET /api/kit/console/agents/${a.id}/webhooks`]: { webhooks: [], events: [] },
      [`GET /api/kit/console/agents/${a.id}/web-chat`]: { allowed: false, priced: true, enabled: false, websites: [], title: null, greeting: null, snippet: null },
    });
    fakeKit({ ...signedIn, ...pageOf(agent("a1", "Front desk")), ...pageOf(agent("a3", "Spare desk")), [`GET ${HEALTH}?minutes=60`]: health() });
    openConsole("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk", level: 1 });
    const region = await panel();
    await waitFor(() => expect(row(region, "tts:Studio voices")).toBeTruthy());
    expect([...region.querySelectorAll("[data-failure]")].map((r) => r.getAttribute("data-failure"))).toEqual(["tts:Studio voices"]);
    expect(region.textContent, "the account's count is not this agent's").not.toContain("Calls with a failure");
    expect(region.textContent).toContain("Providers that failed on this agent's recent calls.");
    cleanup();

    openConsole("/console/agents/a3");
    await screen.findByRole("heading", { name: "Spare desk", level: 1 });
    expect((await within(await panel()).findByTestId("call-health-none")).textContent).toBe("No failures in the last hour.");
  });
});

describe("call health on Admin → a customer's page", () => {
  const customer = { id: "w9", name: "Acme Dental", status: "active", syncState: "synced", syncError: null, telenowSubAccountId: "sub-9", balance: "0",
    priceListId: null, maxConcurrentCalls: null, monthlyCapUsd: null, closeRequestedAt: null, closedAt: null, createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-29T10:00:00Z" };
  const CUSTOMER = "/api/kit/admin/customers/w9/call-health";

  it("shows the staff fields and the provider's last error, for the customer in the path", async () => {
    const staff = health({
      failures: [{
        part: "tts", provider: "Studio voices", cause: "setup", calls: 4, lastAt: LAST, agents: [frontDesk],
        providerId: "elevenlabs", attribution: "customer_config", keySource: "platform", incidents: 6, error: "voice_not_found: voice 'Rachel' does not exist",
      }],
    });
    const sent = fakeKit({ "GET /api/kit/admin/customers/w9": customer, [`GET ${CUSTOMER}?minutes=60`]: staff });
    at("/admin/customers/w9", <CustomerPage />, "/admin/customers/:id");
    const region = await panel();
    await waitFor(() => expect(row(region, "tts:Studio voices")).toBeTruthy());
    const r = row(region, "tts:Studio voices")!;
    expect(cells(r)).toEqual([
      "Voice", "Studio voicesLast error: voice_not_found: voice 'Rachel' does not exist", "The agent's own setup (for example, a voice the provider does not have)",
      "4", new Date(LAST).toLocaleString(), "Front desk", "elevenlabs", "customer_config", "platform", "6",
    ]);
    expect(within(r).getByTestId("call-health-error").className).toContain("error");
    expect(within(r).queryByRole("link"), "staff have no page of the customer's agent").toBeNull();
    expect([...region.querySelectorAll("thead th")].map((th) => th.textContent)).toEqual([
      "Part", "Provider", "Cause", "Calls", "Last", "Agents", "Platform id", "The platform's cause", "Key", "Failures counted",
    ]);
    expect(sent.filter((s) => s.path.includes("/call-health")).map((s) => s.path)).toEqual([`${CUSTOMER}?minutes=60`]);
  });

  it("says quietly when the customer has no account on the voice platform yet", async () => {
    fakeKit({ "GET /api/kit/admin/customers/w9": customer, [`GET ${CUSTOMER}?minutes=60`]: () => Response.json({ message: "This customer has no account on the voice platform yet." }, { status: 409 }) });
    at("/admin/customers/w9", <CustomerPage />, "/admin/customers/:id");
    const said = await within(await panel()).findByTestId("call-health-failure");
    expect(said.textContent).toBe("This customer has no account on the voice platform yet.");
    expect(said.className).toBe("muted");
    expect(screen.getByRole("heading", { name: "Acme Dental" })).toBeTruthy();
  });
});

describe("call health on Admin → Voice platform and the dashboard", () => {
  const base: VoicePlatformHealth = {
    telenow: { name: "Telenow", connected: true, account: "Acme Ops", keyRejected: false, error: null, checkedAt: CHECKED },
    callHealth: null,
    events: { registered: true, url: null, registeredAt: null, checkedAt: null, error: null, lastReceivedAt: null, recentHours: 24, recent: 0, waiting: 0, failed: 0, failures: [] },
    writes: { waiting: 0, failed: 0, failures: [] },
    jobs: { lastStartedAt: CHECKED, running: true, jobs: [] },
    reconciliation: { everyMinutes: 60, toleranceUsd: "0.01", checked: 0, oldestCheckAt: null, drifting: 0, customers: [] },
  };
  const swept: PlatformCallHealth = {
    checkedAt: CHECKED, windowMinutes: 30, accountsActive: 12, accountsChecked: 10, accountsUnread: 2, alertCalls: 5,
    providers: [
      { part: "tts", providerId: "elevenlabs", provider: "Studio voices", calls: 5, customerCount: 3, lastAt: LAST,
        customers: [{ id: "w9", name: "Acme Dental", calls: 3 }, { id: "w/7", name: "Bolt Clinics", calls: 1 }] },
      { part: "stt", providerId: "deepgram", provider: "Clear listening", calls: 4, customerCount: 1, lastAt: null, customers: [{ id: "w9", name: "Acme Dental", calls: 4 }] },
    ],
  };
  const platformRow = (id: string) => document.querySelector(`[data-provider="${id}"]`) as HTMLElement;

  it("says the check has not run yet before the first sweep", async () => {
    fakeKit({ "GET /api/kit/admin/telenow": base });
    at("/admin/telenow", <VoicePlatformPage />, "/admin/telenow");
    const region = await panel();
    expect(within(region).getByTestId("call-health-not-run").textContent).toBe("Not checked yet: the call health check has not run.");
    expect(within(region).queryByRole("table")).toBeNull();
  });

  it("shows the last sweep: when, the window, the accounts, and each provider with its customers, flagged at the alert level or over", async () => {
    fakeKit({ "GET /api/kit/admin/telenow": { ...base, callHealth: swept } });
    at("/admin/telenow", <VoicePlatformPage />, "/admin/telenow");
    const region = await panel();
    expect(region.textContent).toContain("Providers that failed on customers' calls in the 30 minutes before the last check. A provider with 5 or more failed calls is flagged");
    expect(["call-health-checked", "call-health-active", "call-health-read", "call-health-unread"].map((id) => within(region).getByTestId(id).textContent))
      .toEqual([new Date(CHECKED).toLocaleString(), "12", "10", "2"]);

    const voices = platformRow("elevenlabs");
    expect(voices.hasAttribute("data-flagged"), "at the alert level").toBe(true);
    expect(within(voices).getByText("At the alert level")).toBeTruthy();
    expect(cells(voices).slice(0, 2)).toEqual(["Voice", "Studio voiceselevenlabs"]);
    expect(within(voices).getByRole("link", { name: "Acme Dental (3)" }).getAttribute("href")).toBe("/admin/customers/w9");
    expect(within(voices).getByRole("link", { name: "Bolt Clinics (1)" }).getAttribute("href")).toBe("/admin/customers/w%2F7");
    expect(voices.textContent).toContain("3 customers in all");

    const listening = platformRow("deepgram");
    expect(listening.hasAttribute("data-flagged"), "one under the alert level").toBe(false);
    expect(within(listening).queryByText("At the alert level")).toBeNull();
    expect(cells(listening)[0]).toBe("Speech recognition");
    expect(listening.textContent).not.toContain("customers in all");
  });

  function dashboard(providersFailing: number, rest = 0): AdminDashboard {
    return {
      period: { from: "2026-11-01", to: "2026-11-09" }, money: [], topUps: [], creditsByHand: [],
      customers: { total: 0, active: 0, suspended: 0, notReady: 0, outOfCredit: null, new: 0 }, calls: { count: 0, seconds: 0, live: 0 },
      telenow: { name: "Telenow", billingUrl: "https://app.telenow.ai/billing", connected: true, keyRejected: false, error: null, checkedAt: null, account: null },
      attention: { callsNotCharged: 0, failedWrites: rest, paymentsToReview: 0, customersNotSynced: 0, eventsFailed: 0, customersDrifting: 0, jobsNotRunning: false, providersFailing },
    };
  }

  it("puts providers failing on the dashboard as one more item to look at, linking to Voice platform, only when there are some", async () => {
    fakeKit({ "GET /api/kit/admin/dashboard": dashboard(3) });
    at("/admin/dashboard", <DashboardPage />, "/admin/dashboard");
    const look = await screen.findByRole("region", { name: "Needs a look" });
    expect(within(look).getByRole("link", { name: "Providers failing on customers' calls: 3" }).getAttribute("href")).toBe("/admin/telenow");
    expect(within(look).queryByText("Nothing needs a look.")).toBeNull();
    cleanup();

    fakeKit({ "GET /api/kit/admin/dashboard": dashboard(0, 2) });
    at("/admin/dashboard", <DashboardPage />, "/admin/dashboard");
    const quiet = await screen.findByRole("region", { name: "Needs a look" });
    expect(within(quiet).getByRole("link", { name: "Changes the voice platform refused: 2" })).toBeTruthy();
    expect(within(quiet).queryByText(/Providers failing/)).toBeNull();
    cleanup();

    fakeKit({ "GET /api/kit/admin/dashboard": dashboard(0) });
    at("/admin/dashboard", <DashboardPage />, "/admin/dashboard");
    expect(within(await screen.findByRole("region", { name: "Needs a look" })).getByText("Nothing needs a look.")).toBeTruthy();
  });
});
