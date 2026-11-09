/**
 * Console → Call routing (routing/) and its marker on Numbers (numbers/NumbersPage.tsx): reached from the nav right after Numbers; how rules work
 * in plain words; each number with its agent, routing switch (a PUT of `{routed}`, the page read again for the connection), a change not saved
 * yet, a number with no agent that cannot be routed (and a way to Numbers), and a refusal (409, 502) in the backend's words; the connection's
 * state, last answer and last problem each in a sentence (never a raw value), and the platform's test of a routed number (answered in N ms and
 * what it would do, or why not; 409, 429, 502 in the backend's words); the rules in order, each summarised (when, then, on or off, a deleted
 * agent), moved (the whole order sent), turned on or off (a PATCH of `enabled`), changed, deleted only after asking, and added (a POST of every
 * field, lines trimmed with blanks and repeats dropped; a refusing rule sends none of an answer's settings and hides them), not past the limit;
 * the backend's 422 at each field, a box's line named as typed, anything else as an alert; trying a call (which rule, who answers, its settings,
 * a note for a number not routed yet; the backend's word at a field); a member reads it all and only tries a call; no vendor's name anywhere.
 * On Numbers: a routed number marked with a link to Call routing, and the backend's 409 when its agent is set to none.
 * Not here: what the backend does (its own tests); the requests' shapes against the contract (api.test.ts).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ConsoleHome, PhoneNumber, RoutingConnection, RoutingLimits, RoutingNumber, RoutingPage, RoutingRule, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { CallRoutingPage } from "../src/routing/CallRoutingPage";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ROOT = "/api/kit/console/routing";
const browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
const limits = (over: Partial<RoutingLimits> = {}): RoutingLimits => ({
  rulesMax: 100, numbersPerRule: 500, nameMaxLength: 120, greetingMaxLength: 500, languageMaxLength: 32, maxMinutes: 240, ...over,
});

const n1: RoutingNumber = { id: "n1", number: "+14155550100", agent: { id: "a1", name: "Front desk" }, routed: true, syncState: "synced", syncError: null };
const n2: RoutingNumber = { id: "n2", number: "+14155550111", agent: { id: "a2", name: "Billing" }, routed: false, syncState: "pending", syncError: "Not saved yet. It will be tried again shortly." };
const n3: RoutingNumber = { id: "n3", number: "+14155550122", agent: null, routed: false, syncState: "synced", syncError: null };

function rule(over: Partial<RoutingRule> & { id: string; name: string }): RoutingRule {
  return {
    enabled: true, numberIds: [], forwardedFrom: [], callerStartsWith: [], days: [], from: null, until: null, timezone: null, action: "answer",
    agent: null, agentGone: false, language: null, greeting: null, maxMinutes: null, ...over,
  };
}

const afterHours = rule({
  id: "r1", name: "After hours", numberIds: ["n1", "n-released"], days: [1, 2, 3, 4, 5], from: "18:00", until: "09:00", timezone: "Europe/London",
  agent: { id: "a2", name: "Billing" }, language: "hi-IN", greeting: "Namaste, we are closed.", maxMinutes: 30,
});
const ukCallers = rule({ id: "r2", name: "UK callers", forwardedFrom: ["+14155550199"], callerStartsWith: ["+44", "+353"], days: [6, 7], timezone: "Europe/London" });
const blockSpam = rule({
  id: "r3", name: "Block spam", enabled: false, action: "refuse", callerStartsWith: ["+1900", "+1976", "+1809", "+1284"],
  forwardedFrom: ["+14155550101", "+14155550102", "+14155550103", "+14155550104"],
});
const oldDesk = rule({ id: "r4", name: "Old desk", agentGone: true, days: [1, 3, 4, 5, 7], from: "09:00", until: "17:00", timezone: "Asia/Kolkata" });

function routing(over: Partial<RoutingPage> = {}): RoutingPage {
  return { rules: [afterHours, ukCallers, blockSpam, oldDesk], numbers: [n1, n2, n3], connection: null, canManage: true, limits: limits(), ...over };
}

const connected = (over: Partial<RoutingConnection> = {}): RoutingConnection => ({
  state: "connected", lastAnsweredAt: "2026-11-08T09:30:00Z", problem: null, problemAt: null, ...over,
});

const phoneNumber = (over: Partial<PhoneNumber> & { id: string; number: string }): PhoneNumber => ({
  country: "US", region: null, type: "local", agent: null, monthlyPrice: null, nextRenewalAt: null, releasing: false, routed: false,
  agentCallerId: false, unanswered: "message", syncState: "synced", syncError: null, createdAt: "2026-10-30T14:00:00Z", ...over,
});

const refused = (status: number, message: string, errors?: Record<string, string[]>) => Response.json({ message, errors }, { status });

/** The page as it stands now (`now()`), the account's agents over two pages, and the answers a test adds. */
function fake(now: () => RoutingPage, more: Record<string, unknown> = {}) {
  return fakeKit({
    [`GET ${ROOT}`]: () => Response.json(now()),
    "GET /api/kit/console/agents?page=1": { agents: [{ id: "a1", name: "Front desk" }], total: 2, page: 1, perPage: 1 },
    "GET /api/kit/console/agents?page=2": { agents: [{ id: "a2", name: "Billing" }], total: 2, page: 2, perPage: 1 },
    ...more,
  });
}

async function show() {
  render(
    <MemoryRouter initialEntries={["/console/routing"]}>
      <Routes>
        <Route path="/console/routing" element={<CallRoutingPage />} />
        <Route path="/console/numbers" element={<h1>Numbers page</h1>} />
      </Routes>
    </MemoryRouter>,
  );
  await screen.findByRole("heading", { name: "Rules" });
}

const writes = (sent: { method: string; path: string; body: unknown }[]) => sent.filter((s) => s.method !== "GET");
const routeSwitch = (number: string) => screen.getByRole("switch", { name: `Route calls to ${number} by these rules` }) as HTMLInputElement;
const numberRows = () => within(screen.getByRole("table", { name: "Your numbers" })).getAllByRole("row").slice(1);
const ruleItems = () => within(screen.getByRole("list", { name: "Rules" })).getAllByRole("listitem");
const ruleNames = () => ruleItems().map((li) => within(li).getByRole("heading").textContent);
const lines = (el: HTMLElement) => [...el.querySelectorAll("p")].map((p) => p.textContent);
/** What a field's aria-describedby points at, as text. */
const describedBy = (el: HTMLElement) => (el.getAttribute("aria-describedby") ?? "").split(" ").map((id) => document.getElementById(id)?.textContent);
const connection = () => screen.getByRole("region", { name: "Connection" });

describe("call routing: the page and its numbers", () => {
  const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
  const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "owner", canManageTeam: true, paused: null };
  const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "owner", current: true }] };

  it("is reached from the console's nav, right after Numbers, and says how rules work in plain words", async () => {
    fake(() => routing(), { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one });
    render(<MemoryRouter initialEntries={["/console"]}><Routes><Route path="/console/*" element={<ConsoleArea />} /></Routes></MemoryRouter>);
    const nav = await screen.findByRole("navigation", { name: "Console" });
    const names = within(nav).getAllByRole("link").map((a) => a.textContent);
    expect(names[names.indexOf("Numbers") + 1]).toBe("Call routing");
    fireEvent.click(within(nav).getByRole("link", { name: "Call routing" }));
    expect(await screen.findByRole("heading", { name: "Call routing", level: 1 })).toBeTruthy();
    expect(screen.getByText(
      "Rules for who takes a call to your routed numbers. They are tried in order, and the first one that matches takes the call. When none matches, or this server cannot answer within about a second, the number's own agent answers.",
    )).toBeTruthy();
    expect(await screen.findByRole("heading", { name: "Rules" })).toBeTruthy();
  });

  it("shows each number with its agent and switch, a change not saved yet, and a number with no agent that cannot be routed", async () => {
    fake(() => routing());
    await show();
    expect(numberRows().map((r) => within(r).getAllByRole("cell").map((c) => c.textContent))).toEqual([
      ["+14155550100", "Front desk", ""],
      ["+14155550111Saving…Not saved yet. It will be tried again shortly.", "Billing", ""],
      ["+14155550122", "No agent", " Give this number an agent first. Go to Numbers"],
    ]);
    expect([routeSwitch("+14155550100").checked, routeSwitch("+14155550111").checked, routeSwitch("+14155550122").checked]).toEqual([true, false, false]);
    expect([routeSwitch("+14155550100").disabled, routeSwitch("+14155550111").disabled, routeSwitch("+14155550122").disabled]).toEqual([false, false, true]);
    expect(describedBy(routeSwitch("+14155550122"))).toEqual([" Give this number an agent first. Go to Numbers"]);
    expect(within(numberRows()[2]).getByRole("link", { name: "Go to Numbers" }).getAttribute("href")).toBe("/console/numbers");
    expect(screen.queryByText(/Only the account's managers/)).toBeNull();
    expect(screen.queryByRole("region", { name: "Connection" })).toBeNull();
  });

  it("routes a number with a PUT, reads the page again and shows the connection; turns routing off the same way", async () => {
    let now = routing();
    const sent = fake(() => now, {
      "PUT /api/kit/console/numbers/n2/routing": (body: unknown) => {
        const { routed } = body as { routed: boolean };
        now = routing({ numbers: [n1, { ...n2, routed, syncState: "synced", syncError: null }, n3], connection: connected() });
        return Response.json(phoneNumber({ id: "n2", number: n2.number, agent: n2.agent, routed, syncState: "pending" }));
      },
    });
    await show();
    fireEvent.click(routeSwitch("+14155550111"));
    await waitFor(() => expect(routeSwitch("+14155550111").checked).toBe(true));
    expect(await within(await screen.findByRole("region", { name: "Connection" })).findByText(
      "The voice platform asks this server about each call to your routed numbers.")).toBeTruthy();
    expect(within(connection()).getAllByRole("button").map((b) => b.getAttribute("aria-label"))).toEqual(["Test +14155550100", "Test +14155550111"]);
    expect(sent.filter((s) => s.path === ROOT)).toHaveLength(2);

    fireEvent.click(routeSwitch("+14155550111"));
    await waitFor(() => expect(routeSwitch("+14155550111").checked).toBe(false));
    expect(writes(sent).map((s) => [`${s.method} ${s.path}`, s.body])).toEqual([
      ["PUT /api/kit/console/numbers/n2/routing", { routed: true }],
      ["PUT /api/kit/console/numbers/n2/routing", { routed: false }],
    ]);
  });

  it("says the backend's word beside the number when it cannot be routed (409, 502), leaving its switch as it was", async () => {
    const notPublic = "The voice platform cannot reach this server: call routing needs this server at a public https address.";
    const unreachable = "The voice platform could not be reached just now. Try again in a moment.";
    const answers = [refused(409, notPublic), refused(502, unreachable)];
    const sent = fake(() => routing(), { "PUT /api/kit/console/numbers/n2/routing": () => answers.shift()! });
    await show();
    fireEvent.click(routeSwitch("+14155550111"));
    expect((await within(numberRows()[1]).findByRole("alert")).textContent).toBe(notPublic);
    expect(routeSwitch("+14155550111").checked).toBe(false);
    fireEvent.click(routeSwitch("+14155550111"));
    await waitFor(() => expect(within(numberRows()[1]).getByRole("alert").textContent).toBe(unreachable));
    expect(routeSwitch("+14155550111").checked).toBe(false);
    expect(writes(sent)).toHaveLength(2);
    expect(sent.filter((s) => s.path === ROOT)).toHaveLength(1);
  });

  it("lets a member read it all, without switches, tests or rule changes, and still try a call", async () => {
    const sent = fake(() => routing({ canManage: false, connection: connected() }), {
      [`POST ${ROOT}/try`]: { rule: null, outcome: "own_agent", agent: { id: "a1", name: "Front desk" }, language: null, greeting: null, maxMinutes: null, routed: true },
    });
    await show();
    expect(screen.getByText("Only the account's managers can change call routing. Anyone can try a call below.")).toBeTruthy();
    expect(numberRows().map((r) => within(r).getAllByRole("cell")[2].textContent)).toEqual(["Routed", "Not routed", "Not routed"]);
    expect(screen.queryAllByRole("switch")).toHaveLength(0);
    expect(within(connection()).queryAllByRole("button")).toHaveLength(0);
    expect(within(screen.getByRole("list", { name: "Rules" })).queryAllByRole("button")).toHaveLength(0);
    expect(screen.queryByRole("button", { name: "Add a rule" })).toBeNull();
    expect(ruleNames()).toEqual(["After hours On", "UK callers On", "Block spam Off", "Old desk On"]);
    expect(sent.some((s) => s.path.startsWith("/api/kit/console/agents"))).toBe(false);

    fireEvent.click(screen.getByRole("button", { name: "Try" }));
    const said = await within(screen.getByRole("region", { name: "Try a call" })).findByRole("status");
    expect(lines(said)).toEqual(["No rule takes this call: the number's own agent answers.", "Front desk answers."]);
    expect(writes(sent).map((s) => s.path)).toEqual([`${ROOT}/try`]);
  });

  it("says a page that cannot be read in the backend's words", async () => {
    fakeKit({ [`GET ${ROOT}`]: () => refused(401, "Sign in first.") });
    render(<MemoryRouter><CallRoutingPage /></MemoryRouter>);
    expect((await screen.findByRole("alert")).textContent).toBe("Sign in first.");
  });
});

describe("call routing: the connection and the platform's test", () => {
  it.each([
    ["connected", "The voice platform asks this server about each call to your routed numbers.", "check"],
    ["off", "Routing is turned off at the voice platform: your numbers' own agents answer every call.", "check check-warning"],
    ["elsewhere", "The voice platform asks another address, not this server, so these rules are not used.", "check check-warning"],
    ["not_connected", "The voice platform is not connected to this server: your numbers' own agents answer every call.", "check check-warning"],
    ["unknown", "The voice platform could not be reached just now, so whether it asks this server is not known.", "check check-warning"],
  ] as const)("says the state %s in a sentence", async (state, words, className) => {
    fake(() => routing({ connection: connected({ state, lastAnsweredAt: state === "connected" ? "2026-11-08T09:30:00Z" : null }) }));
    await show();
    const said = within(connection()).getByText(words);
    expect(said.className).toBe(className);
    expect(within(connection()).getByText(state === "connected" ? `Last answered ${new Date("2026-11-08T09:30:00Z").toLocaleString()}.` : "Not answered yet.")).toBeTruthy();
    expect(within(connection()).queryByText(/Last problem/)).toBeNull();
  });

  it.each([
    ["off", "Routing was turned off at the voice platform."],
    ["not_connected", "The voice platform had no address to ask."],
    ["slow", "This server did not answer within the time allowed."],
    ["refused", "This server answered with an error."],
    ["unreachable", "The voice platform could not reach this server."],
    ["bad_answer", "The voice platform could not use this server's answer."],
    ["address", "The voice platform will not use this server's address: call routing needs a public https address."],
    ["other", "Something else went wrong."],
    ["teapot", "Something else went wrong."],
  ])("says the last problem %s in a sentence, with when, never as sent", async (problem, words) => {
    fake(() => routing({ connection: connected({ problem: problem as RoutingConnection["problem"], problemAt: "2026-11-08T09:45:00Z" }) }));
    await show();
    expect(within(connection()).getByText(`Last problem, ${new Date("2026-11-08T09:45:00Z").toLocaleString()}: ${words}`).className).toBe("check check-warning");
    if (problem.includes("_") || problem === "teapot") expect(connection().textContent).not.toContain(problem);
  });

  it("says the last problem without when when the backend has none", async () => {
    fake(() => routing({ connection: connected({ problem: "slow", problemAt: null }) }));
    await show();
    expect(within(connection()).getByText("Last problem: This server did not answer within the time allowed.")).toBeTruthy();
  });

  it("tests a routed number: answered in N ms and what it would do, or why there was no answer", async () => {
    const answers = [
      { answered: true, elapsedMs: 84, action: "answer", problem: null },
      { answered: true, elapsedMs: 91, action: "refuse", problem: null },
      { answered: false, elapsedMs: 1200, action: null, problem: "slow" },
      { answered: false, elapsedMs: 15, action: null, problem: null },
      { answered: true, elapsedMs: 40, action: null, problem: null },
    ];
    const sent = fake(() => routing({ connection: connected() }), { [`POST ${ROOT}/check`]: () => Response.json(answers.shift()) });
    await show();
    const tests = within(connection()).getByRole("list", { name: "Test a routed number" });
    expect(within(tests).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["+14155550100 Test"]);
    const button = within(tests).getByRole("button", { name: "Test +14155550100" });
    const results = [
      "Answered in 84 ms: the call would be answered.",
      "Answered in 91 ms: the call would be refused (the caller hears a busy line).",
      "No answer in time (1200 ms): the number's own agent would answer. This server did not answer within the time allowed.",
      "No answer in time (15 ms): the number's own agent would answer.",
      "Answered in 40 ms.",
    ];
    for (const result of results) {
      fireEvent.click(button);
      await waitFor(() => expect(within(tests).getByRole("status").textContent).toBe(result));
    }
    expect(writes(sent).map((s) => [s.path, s.body])).toEqual(results.map(() => [`${ROOT}/check`, { numberId: "n1" }]));
  });

  it("says a refused test in the backend's words (409, 429, 502)", async () => {
    const said = [
      "Calls to that number are not routed yet.",
      "Too many tries. Wait a minute and try again.",
      "The voice platform could not be reached just now. Try again in a moment.",
    ];
    const answers = [refused(409, said[0]), refused(429, said[1]), refused(502, said[2])];
    fake(() => routing({ connection: connected() }), { [`POST ${ROOT}/check`]: () => answers.shift()! });
    await show();
    const button = within(connection()).getByRole("button", { name: "Test +14155550100" });
    for (const words of said) {
      fireEvent.click(button);
      await waitFor(() => expect(within(connection()).getByRole("alert").textContent).toBe(words));
      expect(within(connection()).queryByRole("status")).toBeNull();
    }
  });
});

describe("call routing: the rules", () => {
  it("summarises each rule in order: when it takes a call, what it then does, on or off, and a deleted agent", async () => {
    fake(() => routing({ rules: [afterHours, ukCallers, blockSpam, oldDesk, rule({ id: "r5", name: "Every day", days: [7, 1, 2, 3, 4, 5, 6], timezone: "UTC" })] }));
    await show();
    expect(ruleNames()).toEqual(["After hours On", "UK callers On", "Block spam Off", "Old desk On", "Every day On"]);
    expect(ruleItems().map(lines)).toEqual([
      ["When: calls to +14155550100, a number no longer yours; Mon–Fri, 18:00 until 09:00 the next day (Europe/London)",
        "Then: answer with Billing, in hi-IN, greeting “Namaste, we are closed.”, at most 30 minutes"],
      ["When: forwarded from +14155550199; the caller's number starts with +44, +353; Sat, Sun (Europe/London)", "Then: answer with the number's own agent"],
      ["When: forwarded from 4 lines; the caller's number starts with one of 4", "Then: refuse the call (busy line)"],
      ["When: Mon, Wed–Fri, Sun, 09:00 until 17:00 (Asia/Kolkata)", "Then: answer with the number's own agent", "Its agent was deleted: the number's own agent answers."],
      ["When: every day (UTC)", "Then: answer with the number's own agent"],
    ]);
    expect(within(ruleItems()[3]).getByText("Its agent was deleted: the number's own agent answers.").className).toBe("check check-warning");
    expect(within(ruleItems()[0]).queryByRole("button", { name: "Move up" })).toBeNull();
    expect(within(ruleItems()[4]).queryByRole("button", { name: "Move down" })).toBeNull();
    expect(within(ruleItems()[2]).getByRole("button", { name: "Turn on" })).toBeTruthy();
    expect(within(ruleItems()[0]).getByRole("button", { name: "Turn off" })).toBeTruthy();
  });

  it("says when there are no rules yet, and a rule that matches anything as any call", async () => {
    const sent = fake(() => routing({ rules: [] }), {
      [`POST ${ROOT}/rules`]: (body: unknown) => Response.json(rule({ id: "r9", name: (body as { name: string }).name, timezone: browserZone }), { status: 201 }),
    });
    await show();
    expect(screen.getByText("No rules yet: the number's own agent answers every call.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Add a rule" }));
    const form = screen.getByRole("form", { name: "New rule" });
    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: "  Everyone  " } });
    fireEvent.click(within(form).getByRole("button", { name: "Save rule" }));
    await waitFor(() => expect(ruleNames()).toEqual(["Everyone On"]));
    expect(lines(ruleItems()[0])).toEqual(["When: any call", "Then: answer with the number's own agent"]);
    expect((writes(sent)[0].body as { name: string }).name).toBe("Everyone");
    expect(screen.queryByText("No rules yet: the number's own agent answers every call.")).toBeNull();
  });

  it("moves a rule up or down by sending the whole order, and shows the order the backend answers", async () => {
    const sent = fake(() => routing(), {
      [`PUT ${ROOT}/order`]: (body: unknown) => {
        const { ids } = body as { ids: string[] };
        return Response.json({ rules: ids.map((id) => [afterHours, ukCallers, blockSpam, oldDesk].find((r) => r.id === id)) });
      },
    });
    await show();
    fireEvent.click(within(ruleItems()[1]).getByRole("button", { name: "Move up" }));
    await waitFor(() => expect(ruleNames()).toEqual(["UK callers On", "After hours On", "Block spam Off", "Old desk On"]));
    fireEvent.click(within(ruleItems()[2]).getByRole("button", { name: "Move down" }));
    await waitFor(() => expect(ruleNames()).toEqual(["UK callers On", "After hours On", "Old desk On", "Block spam Off"]));
    expect(writes(sent).map((s) => [`${s.method} ${s.path}`, s.body])).toEqual([
      [`PUT ${ROOT}/order`, { ids: ["r2", "r1", "r3", "r4"] }],
      [`PUT ${ROOT}/order`, { ids: ["r2", "r1", "r4", "r3"] }],
    ]);
  });

  it("turns a rule on or off with a PATCH of enabled alone, and says a refusal as an alert", async () => {
    const answers = [Response.json({ ...blockSpam, enabled: true }), refused(403, "Only the account's managers can change call routing.")];
    const sent = fake(() => routing(), { [`PATCH ${ROOT}/rules/r3`]: () => answers.shift()! });
    await show();
    fireEvent.click(within(ruleItems()[2]).getByRole("button", { name: "Turn on" }));
    await waitFor(() => expect(ruleNames()[2]).toBe("Block spam On"));
    fireEvent.click(within(ruleItems()[2]).getByRole("button", { name: "Turn off" }));
    const rules = screen.getByRole("region", { name: "Rules" });
    expect((await within(rules).findByRole("alert")).textContent).toBe("Only the account's managers can change call routing.");
    expect(ruleNames()[2]).toBe("Block spam On");
    expect(writes(sent).map((s) => [`${s.method} ${s.path}`, s.body])).toEqual([
      [`PATCH ${ROOT}/rules/r3`, { enabled: true }],
      [`PATCH ${ROOT}/rules/r3`, { enabled: false }],
    ]);
  });

  it("deletes a rule only after asking", async () => {
    const sent = fake(() => routing(), { [`DELETE ${ROOT}/rules/r3`]: () => new Response(null, { status: 204 }) });
    await show();
    fireEvent.click(within(ruleItems()[2]).getByRole("button", { name: "Delete" }));
    expect(within(ruleItems()[2]).getByText(
      "Delete the rule Block spam? Calls it would take go to the next rule that matches, or to the number's own agent.")).toBeTruthy();
    fireEvent.click(within(ruleItems()[2]).getByRole("button", { name: "Cancel" }));
    expect(within(ruleItems()[2]).queryByText(/Delete the rule/)).toBeNull();
    expect(writes(sent)).toHaveLength(0);
    fireEvent.click(within(ruleItems()[2]).getByRole("button", { name: "Delete" }));
    fireEvent.click(within(ruleItems()[2]).getByRole("button", { name: "Yes, delete" }));
    await waitFor(() => expect(ruleNames()).toEqual(["After hours On", "UK callers On", "Old desk On"]));
    expect(within(screen.getByRole("region", { name: "Rules" })).getByRole("status").textContent).toBe("Deleted Block spam.");
    expect(writes(sent).map((s) => `${s.method} ${s.path}`)).toEqual([`DELETE ${ROOT}/rules/r3`]);
  });

  it("adds a rule with a POST of every field, lines trimmed with blanks and repeats dropped, last in the list", async () => {
    const sent = fake(() => routing(), {
      [`POST ${ROOT}/rules`]: (body: unknown) => Response.json({ ...rule({ id: "r9", name: "x" }), ...(body as object), agent: { id: "a2", name: "Billing" } }, { status: 201 }),
    });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Add a rule" }));
    const form = screen.getByRole("form", { name: "New rule" });
    const field = (label: string) => within(form).getByLabelText(label) as HTMLInputElement;
    expect([field("Timezone").value, field("Timezone").required]).toEqual([browserZone, false]);
    expect(field("On: this rule is tried").checked).toBe(true);
    expect((field("Answer the call") as HTMLInputElement).checked).toBe(true);
    expect(within(form).getByText("One number per line, with + and its country code, like +14155550123. Left empty: any call, forwarded or not. Up to 500.")).toBeTruthy();
    expect(field("Greeting").maxLength).toBe(500);
    expect([field("Longest call, in minutes").min, field("Longest call, in minutes").max]).toEqual(["1", "240"]);
    const agent = field("Agent") as unknown as HTMLSelectElement;
    await waitFor(() => expect([...agent.options].map((o) => o.textContent)).toEqual(["The number's own agent", "Front desk", "Billing"]));

    fireEvent.change(field("Name"), { target: { value: "Weekday mornings" } });
    fireEvent.click(field("+14155550100"));
    fireEvent.change(field("Forwarded from these lines"), { target: { value: " +14155550199 \n\n+14155550188\n+14155550199\n" } });
    fireEvent.change(field("The caller's number starts with"), { target: { value: "+44" } });
    fireEvent.click(field("Tue"));
    fireEvent.click(field("Mon"));
    expect(field("Timezone").required).toBe(true);
    fireEvent.change(field("From"), { target: { value: "09:00" } });
    fireEvent.change(field("Until"), { target: { value: "17:00" } });
    fireEvent.change(agent, { target: { value: "a2" } });
    fireEvent.change(field("Language"), { target: { value: " hi-IN " } });
    fireEvent.change(field("Greeting"), { target: { value: "Namaste" } });
    fireEvent.change(field("Longest call, in minutes"), { target: { value: "30" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save rule" }));

    await waitFor(() => expect(ruleNames()).toEqual(["After hours On", "UK callers On", "Block spam Off", "Old desk On", "Weekday mornings On"]));
    expect(writes(sent).map((s) => [`${s.method} ${s.path}`, s.body])).toEqual([[`POST ${ROOT}/rules`, {
      name: "Weekday mornings", enabled: true, numberIds: ["n1"], forwardedFrom: ["+14155550199", "+14155550188"], callerStartsWith: ["+44"], days: [1, 2],
      from: "09:00", until: "17:00", timezone: browserZone, action: "answer", agentId: "a2", language: "hi-IN", greeting: "Namaste", maxMinutes: 30,
    }]]);
    expect(lines(ruleItems()[4])).toEqual([
      `When: calls to +14155550100; forwarded from +14155550199, +14155550188; the caller's number starts with +44; Mon, Tue, 09:00 until 17:00 (${browserZone})`,
      "Then: answer with Billing, in hi-IN, greeting “Namaste”, at most 30 minutes",
    ]);
    expect(screen.queryByRole("form", { name: "New rule" })).toBeNull();
  });

  it("hides an answer's fields for a refusing rule and sends none of them", async () => {
    const sent = fake(() => routing(), {
      [`POST ${ROOT}/rules`]: (body: unknown) => Response.json({ ...rule({ id: "r9", name: "x" }), ...(body as object), timezone: browserZone }, { status: 201 }),
    });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Add a rule" }));
    const form = screen.getByRole("form", { name: "New rule" });
    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: "No spam" } });
    fireEvent.change(within(form).getByLabelText("Greeting"), { target: { value: "Hello" } });
    fireEvent.click(within(form).getByLabelText("Refuse it: the caller hears a busy line"));
    for (const label of ["Agent", "Language", "Greeting", "Longest call, in minutes"]) expect(within(form).queryByLabelText(label)).toBeNull();
    fireEvent.click(within(form).getByLabelText("Answer the call"));
    expect((within(form).getByLabelText("Greeting") as HTMLTextAreaElement).value).toBe("Hello");
    fireEvent.click(within(form).getByLabelText("Refuse it: the caller hears a busy line"));
    fireEvent.click(within(form).getByRole("button", { name: "Save rule" }));
    await waitFor(() => expect(ruleNames()).toHaveLength(5));
    const body = writes(sent)[0].body as Record<string, unknown>;
    expect(body).toEqual({ name: "No spam", enabled: true, numberIds: [], forwardedFrom: [], callerStartsWith: [], days: [], from: null, until: null, timezone: browserZone, action: "refuse" });
    expect(["agentId", "language", "greeting", "maxMinutes"].filter((k) => k in body)).toEqual([]);
    expect(lines(ruleItems()[4])).toEqual(["When: any call", "Then: refuse the call (busy line)"]);
  });

  it("changes a rule in the same form, its values filled in (a number no longer the account's left out), with a PATCH", async () => {
    const sent = fake(() => routing(), {
      [`PATCH ${ROOT}/rules/r1`]: (body: unknown) => Response.json({ ...afterHours, ...(body as object), agent: afterHours.agent }),
    });
    await show();
    fireEvent.click(within(ruleItems()[0]).getByRole("button", { name: "Edit" }));
    const form = screen.getByRole("form", { name: "Change After hours" });
    const field = (label: string) => within(form).getByLabelText(label) as HTMLInputElement;
    expect(field("Name").value).toBe("After hours");
    expect(["+14155550100", "+14155550111", "+14155550122"].map((n) => field(n).checked)).toEqual([true, false, false]);
    expect(["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"].map((d) => field(d).checked)).toEqual([true, true, true, true, true, false, false]);
    expect([field("From").value, field("Until").value, field("Timezone").value, field("Timezone").required]).toEqual(["18:00", "09:00", "Europe/London", true]);
    expect([field("Agent").value, field("Language").value, field("Greeting").value, field("Longest call, in minutes").value]).toEqual(["a2", "hi-IN", "Namaste, we are closed.", "30"]);
    expect(screen.queryByRole("button", { name: "Add a rule" })).toBeTruthy();

    fireEvent.change(field("Name"), { target: { value: "Evenings" } });
    fireEvent.click(field("Fri"));
    fireEvent.click(within(form).getByRole("button", { name: "Save rule" }));
    await waitFor(() => expect(ruleNames()[0]).toBe("Evenings On"));
    expect(screen.queryByRole("form", { name: "Change After hours" })).toBeNull();
    expect(writes(sent).map((s) => [`${s.method} ${s.path}`, s.body])).toEqual([[`PATCH ${ROOT}/rules/r1`, {
      name: "Evenings", enabled: true, numberIds: ["n1"], forwardedFrom: [], callerStartsWith: [], days: [1, 2, 3, 4], from: "18:00", until: "09:00",
      timezone: "Europe/London", action: "answer", agentId: "a2", language: "hi-IN", greeting: "Namaste, we are closed.", maxMinutes: 30,
    }]]);
  });

  it("closes the form on Cancel without sending anything", async () => {
    const sent = fake(() => routing());
    await show();
    fireEvent.click(within(ruleItems()[1]).getByRole("button", { name: "Edit" }));
    fireEvent.click(within(screen.getByRole("form", { name: "Change UK callers" })).getByRole("button", { name: "Cancel" }));
    expect(screen.queryByRole("form", { name: "Change UK callers" })).toBeNull();
    expect(lines(ruleItems()[1])).toEqual(["When: forwarded from +14155550199; the caller's number starts with +44, +353; Sat, Sun (Europe/London)", "Then: answer with the number's own agent"]);
    expect(ruleNames()[1]).toBe("UK callers On");
    expect(writes(sent)).toHaveLength(0);
  });

  it("puts the backend's word at each field, naming a box's line as typed, and anything else as an alert", async () => {
    const reasons: Record<string, string[]> = {
      name: ["The name field must not be greater than 120 characters."],
      enabled: ["The enabled field must be true or false."],
      numberIds: ["Choose your own numbers."],
      "forwardedFrom.1": ["Write each number with + and its country code, like +14155550123."],
      "callerStartsWith.0": ["Write each start with + and digits, like +44 or +1415."],
      "days.0": ["The selected days.0 is invalid."],
      from: ["Give both the start and the end of the hours, or neither."],
      until: ["The hours end when they start: choose a different end."],
      timezone: ["Choose the timezone the days and hours are in."],
      action: ["The selected action is invalid."],
      agentId: ["Choose one of your saved agents."],
      language: ["Write a language code, like en-US or hi-IN."],
      greeting: ["The greeting field must not be greater than 500 characters."],
      maxMinutes: ["The max minutes field must not be greater than 240."],
      ids: ["List every rule once, in the order you want."],
    };
    fake(() => routing(), { [`POST ${ROOT}/rules`]: () => refused(422, "Write each number with + and its country code, like +14155550123.", reasons) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Add a rule" }));
    const form = screen.getByRole("form", { name: "New rule" });
    const field = (label: string) => within(form).getByLabelText(label) as HTMLInputElement;
    fireEvent.change(field("Name"), { target: { value: "Late" } });
    fireEvent.change(field("Forwarded from these lines"), { target: { value: "+14155550199\n\n0207 946 0000" } });
    fireEvent.change(field("The caller's number starts with"), { target: { value: "\n44" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save rule" }));

    await waitFor(() => expect(field("Name").getAttribute("aria-invalid")).toBe("true"));
    const at = (label: string) => describedBy(field(label))[0];
    expect(at("Name")).toBe("The name field must not be greater than 120 characters.");
    expect(at("On: this rule is tried")).toBe("The enabled field must be true or false.");
    expect(at("Forwarded from these lines")).toBe("Line 3 (0207 946 0000): Write each number with + and its country code, like +14155550123.");
    expect(at("The caller's number starts with")).toBe("Line 2 (44): Write each start with + and digits, like +44 or +1415.");
    expect(describedBy(field("Forwarded from these lines"))[1]).toBe("One number per line, with + and its country code, like +14155550123. Left empty: any call, forwarded or not. Up to 500.");
    expect(at("From")).toBe("Give both the start and the end of the hours, or neither.");
    expect(at("Until")).toBe("The hours end when they start: choose a different end.");
    expect(at("Timezone")).toBe("Choose the timezone the days and hours are in.");
    expect(at("Agent")).toBe("Choose one of your saved agents.");
    expect(at("Language")).toBe("Write a language code, like en-US or hi-IN.");
    expect(at("Greeting")).toBe("The greeting field must not be greater than 500 characters.");
    expect(at("Longest call, in minutes")).toBe("The max minutes field must not be greater than 240.");
    const group = (legend: string) => within(form).getByRole("group", { name: legend });
    expect(describedBy(group("Numbers called"))[0]).toBe("Choose your own numbers.");
    expect(describedBy(group("Days"))[0]).toBe("The selected days.0 is invalid.");
    expect(describedBy(group("What happens"))[0]).toBe("The selected action is invalid.");
    expect(within(form).getByRole("alert").textContent).toBe("List every rule once, in the order you want.");
    expect(field("Forwarded from these lines").value).toBe("+14155550199\n\n0207 946 0000");
    expect(field("Name").value).toBe("Late");
  });

  it("says a refusal that is not about a field as an alert in the form (409 too many rules, 403), keeping what was typed", async () => {
    const answers = [refused(409, "An account can have up to 100 rules."), refused(403, "Only the account's managers can change call routing.")];
    fake(() => routing(), { [`POST ${ROOT}/rules`]: () => answers.shift()! });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Add a rule" }));
    const form = screen.getByRole("form", { name: "New rule" });
    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: "Late" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save rule" }));
    expect((await within(form).findByRole("alert")).textContent).toBe("An account can have up to 100 rules.");
    fireEvent.click(within(form).getByRole("button", { name: "Save rule" }));
    await waitFor(() => expect(within(form).getByRole("alert").textContent).toBe("Only the account's managers can change call routing."));
    expect((within(form).getByLabelText("Name") as HTMLInputElement).value).toBe("Late");
    expect(within(form).getByLabelText("Name").getAttribute("aria-invalid")).toBeNull();
  });

  it("offers no new rule at the limit, saying why", async () => {
    fake(() => routing({ limits: limits({ rulesMax: 4 }) }));
    await show();
    const add = screen.getByRole("button", { name: "Add a rule" }) as HTMLButtonElement;
    expect(add.disabled).toBe(true);
    expect(describedBy(add)).toEqual(["An account can have up to 4 rules. Delete one to add another."]);
  });
});

describe("call routing: trying a call", () => {
  const tryAnswer = (over: Record<string, unknown>) => ({ rule: null, outcome: "own_agent", agent: null, language: null, greeting: null, maxMinutes: null, routed: true, ...over });

  it("says which rule takes the call, who answers, its settings, and that a number not routed yet answers with its own agent", async () => {
    const answers = [
      tryAnswer({ rule: { id: "r1", name: "After hours" }, outcome: "answer", agent: { id: "a2", name: "Billing" }, language: "hi-IN", greeting: "Namaste, we are closed.", maxMinutes: 30, routed: false }),
      tryAnswer({ agent: { id: "a1", name: "Front desk" } }),
      tryAnswer({ rule: { id: "r3", name: "Block spam" }, outcome: "refuse" }),
      tryAnswer({}),
    ];
    const sent = fake(() => routing(), { [`POST ${ROOT}/try`]: () => Response.json(answers.shift()) });
    await show();
    const area = within(screen.getByRole("region", { name: "Try a call" }));
    fireEvent.change(area.getByLabelText("Number called"), { target: { value: "n2" } });
    fireEvent.change(area.getByLabelText("Caller's number"), { target: { value: " +447700900123 " } });
    fireEvent.change(area.getByLabelText("Date and time"), { target: { value: "2026-11-09T22:30" } });
    fireEvent.click(area.getByRole("button", { name: "Try" }));
    await waitFor(() => expect(lines(area.getByRole("status"))).toEqual([
      "The rule “After hours” takes this call.",
      "Billing answers.",
      "Language: hi-IN.",
      "Greeting: “Namaste, we are closed.”",
      "At most 30 minutes.",
      "Calls to +14155550111 are not routed yet: until they are, its own agent answers every call.",
    ]));

    fireEvent.change(area.getByLabelText("Number called"), { target: { value: "n1" } });
    fireEvent.change(area.getByLabelText("Caller's number"), { target: { value: "" } });
    fireEvent.change(area.getByLabelText("Forwarded from"), { target: { value: "+14155550199" } });
    fireEvent.change(area.getByLabelText("Date and time"), { target: { value: "" } });
    fireEvent.click(area.getByRole("button", { name: "Try" }));
    await waitFor(() => expect(lines(area.getByRole("status"))).toEqual(["No rule takes this call: the number's own agent answers.", "Front desk answers."]));
    fireEvent.click(area.getByRole("button", { name: "Try" }));
    await waitFor(() => expect(lines(area.getByRole("status"))).toEqual(["The rule “Block spam” takes this call.", "Refused: the caller hears a busy line."]));
    fireEvent.click(area.getByRole("button", { name: "Try" }));
    await waitFor(() => expect(lines(area.getByRole("status"))).toEqual(["No rule takes this call: the number's own agent answers.", "No agent answers: this number has none."]));

    expect(writes(sent).map((s) => s.body)).toEqual([
      { numberId: "n2", callerNumber: "+447700900123", forwardedFrom: null, at: new Date("2026-11-09T22:30").toISOString() },
      { numberId: "n1", callerNumber: null, forwardedFrom: "+14155550199", at: null },
      { numberId: "n1", callerNumber: null, forwardedFrom: "+14155550199", at: null },
      { numberId: "n1", callerNumber: null, forwardedFrom: "+14155550199", at: null },
    ]);
  });

  it("puts the backend's word at a field, and says any other refusal as an alert", async () => {
    const answers = [
      refused(422, "x", { callerNumber: ["The caller number field must not be greater than 32 characters."] }),
      refused(500, "Something went wrong."),
    ];
    fake(() => routing(), { [`POST ${ROOT}/try`]: () => answers.shift()! });
    await show();
    const region = screen.getByRole("region", { name: "Try a call" });
    const caller = within(region).getByLabelText("Caller's number");
    fireEvent.click(within(region).getByRole("button", { name: "Try" }));
    await waitFor(() => expect(caller.getAttribute("aria-invalid")).toBe("true"));
    expect(describedBy(caller)).toEqual(["The caller number field must not be greater than 32 characters.", "Left empty: a caller whose number is not shown."]);
    fireEvent.click(within(region).getByRole("button", { name: "Try" }));
    expect((await within(region).findByRole("alert")).textContent).toBe("Something went wrong.");
    expect(caller.getAttribute("aria-invalid")).toBeNull();
  });

  it("asks for a number first when the account has none", async () => {
    fake(() => routing({ numbers: [], rules: [] }));
    await show();
    expect(screen.getByText("Try a call once this account has a number.")).toBeTruthy();
    expect(screen.queryByRole("form", { name: "Try a call" })).toBeNull();
    expect(within(screen.getByRole("region", { name: "Your numbers" })).getByRole("link", { name: "Go to Numbers" }).getAttribute("href")).toBe("/console/numbers");
  });
});

describe("call routing: white label", () => {
  it("names no vendor anywhere on the page, its results included", async () => {
    fake(() => routing({ connection: connected({ state: "elsewhere", problem: "bad_answer", problemAt: "2026-11-08T09:45:00Z" }) }), {
      [`POST ${ROOT}/check`]: { answered: false, elapsedMs: 1200, action: null, problem: "address" },
      [`POST ${ROOT}/try`]: { rule: null, outcome: "own_agent", agent: null, language: null, greeting: null, maxMinutes: null, routed: false },
    });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Test +14155550100" }));
    await within(connection()).findByRole("status");
    fireEvent.click(screen.getByRole("button", { name: "Try" }));
    await within(screen.getByRole("region", { name: "Try a call" })).findByRole("status");
    fireEvent.click(screen.getByRole("button", { name: "Add a rule" }));
    expect(document.body.textContent).not.toMatch(/telenow|twilio|plivo|exotel|vonage|telnyx|livekit|bandwidth\.com/i);
    expect(document.body.textContent).toMatch(/voice platform/);
  });
});

describe("numbers page: routed numbers", () => {
  const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
  const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
  const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
  const routed = phoneNumber({ id: "n1", number: "+14155550100", agent: { id: "a1", name: "Front desk" }, routed: true });
  const plain = phoneNumber({ id: "n2", number: "+14155550111", agent: { id: "a1", name: "Front desk" } });

  it("marks a routed number with a link to Call routing, and says the backend's word when its agent is set to none", async () => {
    const why = "Calls to this number are routed, so it needs an agent for the calls no rule takes. Turn routing off for it first.";
    const sent = fake(() => routing({ canManage: false }), {
      "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one,
      "GET /api/kit/console/numbers": { numbers: [routed, plain] },
      "PUT /api/kit/console/numbers/n1/agent": () => refused(409, why),
    });
    render(<MemoryRouter initialEntries={["/console/numbers"]}><Routes><Route path="/console/*" element={<ConsoleArea />} /></Routes></MemoryRouter>);
    const rows = within(await screen.findByRole("table", { name: "Your numbers" })).getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell")[0].textContent)).toEqual(["+14155550100 Routed", "+14155550111"]);
    const marker = within(rows[0]).getByRole("link", { name: "Routed" });
    expect(marker.getAttribute("href")).toBe("/console/routing");
    expect(within(rows[1]).queryByRole("link", { name: "Routed" })).toBeNull();

    const select = within(rows[0]).getByLabelText("Agent answering +14155550100") as HTMLSelectElement;
    await waitFor(() => expect(select.options).toHaveLength(3));
    fireEvent.change(select, { target: { value: "" } });
    expect((await within(rows[0]).findByRole("alert")).textContent).toBe(why);
    expect(select.value).toBe("a1");
    expect(writes(sent).map((s) => [`${s.method} ${s.path}`, s.body])).toEqual([["PUT /api/kit/console/numbers/n1/agent", { agentId: null }]]);

    fireEvent.click(marker);
    expect(await screen.findByRole("heading", { name: "Call routing", level: 1 })).toBeTruthy();
  });
});
