/**
 * Console → one agent → its call steps (agents/flow/FlowPage.tsx and its forms): adding and changing steps and exits, saving a draft, checking and publishing, refusals beside their fields. The map is the one thing stood in for, since
 * it needs a real browser to draw: what it is given is checked here, and it was driven in a real browser. Not here: what the backend does (AgentFlowTest.php).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AgentFlowGraph, AgentFlowState, FlowExit, FlowStep } from "../src/api";
import { FlowPage } from "../src/agents/flow/FlowPage";
import { addExit, addStep, canLeave, changeExit, emptyGraph, exitLabel, firstRefused, moveExit, newStep, removeExit, removeStep, stepLabel, toEdges, toNodes } from "../src/agents/flow/flowModel";

/** The map, standing in: it shows what it was given, and its gestures can be sent from a test. */
const canvas = vi.hoisted(() => ({ last: null as null | { onSelect: (id: string | null) => void; onMove: (id: string, x: number, y: number) => void; onConnect: (from: string, to: string) => void; selected: string | null; graph: { steps: { id: string }[] } } }));
vi.mock("../src/agents/flow/FlowCanvas", () => ({
  FlowCanvas: (props: NonNullable<typeof canvas.last>) => {
    canvas.last = props;
    return <div data-testid="map">{props.graph.steps.length}</div>;
  },
}));

const root = "/api/kit/console/agents/a1/flow";
const base = (over: Partial<FlowStep>): FlowStep => ({ id: "a", kind: "conversation", name: "Greet", x: 0, y: 0, instructions: "Greet them.", replaceInstructions: false, line: null, continueAfterLine: false, capture: [], keypad: null, anywhere: null, tool: null, results: [], sends: [], code: null, transfer: null, handoff: null, ...over });
const graph = (): AgentFlowGraph => ({ start: "a", steps: [base({}), base({ id: "b", kind: "end", name: "Bye", instructions: null, line: "Goodbye." })], exits: [{ id: "e1", from: "a", to: "b", label: null, when: { kind: "ai", expr: null, describe: "the caller is done", digit: null, match: null } }] });
const state = (over: Partial<AgentFlowState> = {}): AgentFlowState => ({ published: null, draft: null, draftSavedAt: null, syncState: "synced", syncError: null, warnings: [], kinds: ["conversation", "extract", "dtmf", "static", "router", "tool", "code", "transfer", "agent", "end", "note"], exitKinds: ["always", "equation", "tool_result", "fallback", "dtmf", "ai"], captureTypes: ["string", "number", "boolean", "enum"], captureFormats: ["email", "alphanumeric"], otherAgents: [{ id: "x1", name: "Billing desk" }], ...over });

function fake(routes: Record<string, (body: { flow: AgentFlowGraph }) => Response>) {
  const sent: { method: string; path: string; body: { flow: AgentFlowGraph } }[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? "GET";
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    sent.push({ method, path: String(url), body });
    const route = routes[`${method} ${url}`];
    if (route) return route(body);
    if (String(url) === "/api/kit/console/agents/a1") return Response.json({ id: "a1", name: "Front desk" });
    return new Response(null, { status: 404 });
  }));
  return sent;
}

const show = async () => {
  render(<MemoryRouter initialEntries={["/console/agents/a1/flow"]}><Routes><Route path="/console/agents/:id/flow" element={<FlowPage />} /></Routes></MemoryRouter>);
  await screen.findByRole("heading", { level: 1, name: /Call steps/ });
};
const openStep = (name: RegExp) => fireEvent.click(within(screen.getByRole("navigation", { name: "Steps" })).getByRole("button", { name }));
const add = (kind: string) => {
  fireEvent.change(screen.getByLabelText("Kind of step"), { target: { value: kind } });
  fireEvent.click(screen.getByRole("button", { name: "Add this step" }));
};
const lastSent = (sent: { method: string; body: { flow: AgentFlowGraph } }[], method: string) => sent.filter((s) => s.method === method).at(-1)?.body.flow as AgentFlowGraph;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  canvas.last = null;
});

describe("the call steps page", () => {
  it("opens the draft if there is one, else what is in use, and says which", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ published: graph(), draft: { ...graph(), start: "b" } })) });
    await show();
    expect(screen.getByText(/These steps are in use on calls\. There is a draft\./)).toBeTruthy();
    expect(within(screen.getByRole("navigation", { name: "Steps" })).getByRole("button", { name: /Bye.*calls start here/ })).toBeTruthy();
  });

  it("says when calls use the agent's own instructions and there is nothing yet", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state()) });
    await show();
    expect(screen.getByText(/Calls use the agent's own instructions until you publish/)).toBeTruthy();
    expect(screen.getByText(/No steps yet/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Discard draft" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Stop using steps" })).toBeNull();
  });

  it("says when it cannot be read", async () => {
    fake({ [`GET ${root}`]: () => Response.json({ message: "Try again." }, { status: 500 }) });
    render(<MemoryRouter initialEntries={["/console/agents/a1/flow"]}><Routes><Route path="/console/agents/:id/flow" element={<FlowPage />} /></Routes></MemoryRouter>);
    expect((await screen.findByRole("alert")).textContent).toBe("Try again.");
  });

  it("offers only the kinds the backend says", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ kinds: ["conversation", "end"] })) });
    await show();
    const options = within(screen.getByLabelText("Kind of step")).getAllByRole("option").map((o) => o.textContent);
    expect(options).toEqual(["Conversation", "Ending"]);
  });

  it("adds a step, opens it, gives the map it, and makes the first one where calls start", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state()) });
    await show();
    add("conversation");
    expect(screen.getByLabelText("Instructions for this step")).toBeTruthy();
    expect(canvas.last?.graph.steps).toHaveLength(1);
    expect(canvas.last?.selected).toBe("step_1");
    expect(within(screen.getByRole("navigation", { name: "Steps" })).getByRole("button", { name: /calls start here/ })).toBeTruthy();
    expect(screen.getByText(/You have changes not saved yet/)).toBeTruthy();
  });

  it("opens a step clicked on the map, keeps a step moved there, and adds an exit drawn there", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`PUT ${root}/draft`]: (body) => Response.json(state({ draft: body.flow })) });
    await show();
    fireEvent.click(screen.getByTestId("map"));
    canvas.last?.onSelect("a");
    expect(await screen.findByLabelText("Instructions for this step")).toBeTruthy();
    canvas.last?.onMove("a", 42, 7);
    canvas.last?.onConnect("a", "a");
    await waitFor(() => expect(screen.getByText(/not saved yet/)).toBeTruthy());
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    const flow = lastSent(sent, "PUT");
    expect([flow.steps[0].x, flow.steps[0].y]).toEqual([42, 7]);
    expect(flow.exits).toHaveLength(2);
  });

  it("shows each kind's own fields and not the others'", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state()) });
    await show();
    const fields = (kind: string) => {
      add(kind);
      return screen.queryAllByRole("textbox").length > 0;
    };
    fields("static");
    expect(screen.getByLabelText("Line spoken on arrival")).toBeTruthy();
    expect(screen.queryByLabelText("Instructions for this step")).toBeNull();
    fields("tool");
    expect(screen.getByLabelText("Web address")).toBeTruthy();
    expect(screen.queryByLabelText("Line spoken on arrival")).toBeNull();
    fields("code");
    expect(screen.getByLabelText("JavaScript")).toBeTruthy();
    fields("transfer");
    expect(screen.getByLabelText("Numbers to try")).toBeTruthy();
    fields("agent");
    expect(within(screen.getByLabelText("Agent to hand over to")).getByRole("option", { name: "Billing desk" })).toBeTruthy();
    fields("dtmf");
    expect(screen.getByLabelText(/A key goes straight/)).toBeTruthy();
    fields("end");
    expect(screen.queryByRole("heading", { name: "Exits" })).toBeNull();
    fields("note");
    expect(screen.queryByRole("button", { name: "Start calls here" })).toBeNull();
    expect(screen.queryByLabelText(/can jump to this step/)).toBeNull();
  });

  it("saves what a conversation step holds", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state()), [`PUT ${root}/draft`]: (body) => Response.json(state({ draft: body.flow })) });
    await show();
    add("conversation");
    fireEvent.change(screen.getByLabelText("Name"), { target: { value: "Ask" } });
    fireEvent.change(screen.getByLabelText("Instructions for this step"), { target: { value: "Ask their plan." } });
    fireEvent.click(screen.getByLabelText(/instead of the agent's own instructions/));
    fireEvent.change(screen.getByLabelText("Line spoken on arrival"), { target: { value: "Which plan?" } });
    fireEvent.click(screen.getByLabelText(/Carry on straight after the line/));
    fireEvent.click(screen.getByRole("button", { name: "Add a detail" }));
    fireEvent.change(screen.getAllByLabelText("Name")[1], { target: { value: "plan" } });
    fireEvent.change(screen.getByLabelText("Kind of answer"), { target: { value: "enum" } });
    fireEvent.change(screen.getByLabelText("The answers it can be"), { target: { value: "gold, silver ," } });
    fireEvent.click(screen.getByLabelText(/must get this before moving on/));
    fireEvent.click(screen.getByLabelText(/can jump to this step/));
    fireEvent.change(screen.getByLabelText("When"), { target: { value: "the caller asks about plans" } });
    fireEvent.click(screen.getByLabelText(/Go back to where the call was/));
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    const step = lastSent(sent, "PUT").steps[0];
    expect(step).toMatchObject({ kind: "conversation", name: "Ask", instructions: "Ask their plan.", replaceInstructions: true, line: "Which plan?", continueAfterLine: true, anywhere: { when: "the caller asks about plans", goBack: true } });
    expect(step.capture).toEqual([{ name: "plan", type: "enum", description: null, values: ["gold", "silver"], required: true, format: null }]);
  });

  it("sends a key only when typed or taken away, and shows a saved one only as its hint", async () => {
    const saved = graph();
    saved.steps.push(base({ id: "t", kind: "tool", name: "Look", instructions: null, tool: { name: "look", description: null, url: "https://example.com", request: null, parameters: [], tokenHint: "••••1111", sayWhileRunning: null, timeoutSecs: null, askFirst: false } }));
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ published: saved })), [`PUT ${root}/draft`]: (body) => Response.json(state({ published: saved, draft: body.flow })) });
    await show();
    openStep(/Look/);
    expect(screen.getByText(/••••1111/)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    expect(lastSent(sent, "PUT").steps[2].tool).not.toHaveProperty("token");
    fireEvent.change(screen.getByLabelText(/Key/), { target: { value: "new_key" } });
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await waitFor(() => expect(lastSent(sent, "PUT").steps[2].tool?.token).toBe("new_key"));
  });

  it("takes a saved key away, and puts it back if unticked", async () => {
    const saved = graph();
    saved.steps.push(base({ id: "t", kind: "tool", name: "Look", instructions: null, tool: { name: "look", description: null, url: "https://example.com", request: null, parameters: [], tokenHint: "••••1111", sayWhileRunning: null, timeoutSecs: null, askFirst: false } }));
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ published: saved })), [`PUT ${root}/draft`]: () => Response.json(state({ published: saved })) });
    await show();
    openStep(/Look/);
    const remove = screen.getByRole("checkbox", { name: /[Rr]emove/ });
    fireEvent.click(remove);
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    expect(lastSent(sent, "PUT").steps[2].tool?.token).toBeNull();
  });

  it("sends what a function step keeps and what it sends as details the request may use", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state()), [`PUT ${root}/draft`]: (body) => Response.json(state({ draft: body.flow })) });
    await show();
    add("tool");
    fireEvent.change(screen.getByLabelText("Web address"), { target: { value: "https://example.com/x" } });
    fireEvent.change(screen.getByLabelText("Details to send"), { target: { value: "order_id, plan" } });
    fireEvent.click(screen.getByRole("button", { name: "Keep a value" }));
    fireEvent.change(screen.getByLabelText(/Where in the answer/), { target: { value: "order.status" } });
    fireEvent.change(screen.getByLabelText("Name to keep it under"), { target: { value: "status" } });
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    const step = lastSent(sent, "PUT").steps[0];
    expect(step.sends).toEqual(["order_id", "plan"]);
    expect(step.results).toEqual([{ path: "order.status", variable: "status" }]);
    expect(step.tool?.parameters.map((p) => p.name)).toEqual(["order_id", "plan"]);
    expect(step.tool?.url).toBe("https://example.com/x");
  });

  it("changes an exit's condition, and asks only for what that condition needs", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`PUT ${root}/draft`]: (body) => Response.json(state({ draft: body.flow })) });
    await show();
    openStep(/Greet/);
    expect((screen.getByLabelText("What the caller says or wants") as HTMLTextAreaElement).value).toBe("the caller is done");
    fireEvent.change(screen.getByLabelText("Taken when"), { target: { value: "equation" } });
    expect(screen.queryByLabelText("What the caller says or wants")).toBeNull();
    fireEvent.change(screen.getByLabelText("Rule"), { target: { value: "{a} == 'b'" } });
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    expect(lastSent(sent, "PUT").exits[0].when).toMatchObject({ kind: "equation", expr: "{a} == 'b'" });
  });

  it("warns when an exit that is always taken would keep the AI's from being used", async () => {
    const g = graph();
    g.exits.push({ id: "e2", from: "a", to: "b", label: null, when: { kind: "fallback", expr: null, describe: null, digit: null, match: null } });
    fake({ [`GET ${root}`]: () => Response.json(state({ draft: g })) });
    await show();
    openStep(/Greet/);
    expect(screen.getByText(/will not be used/)).toBeTruthy();
  });

  it("orders, adds and removes exits", async () => {
    const g = graph();
    g.exits.push({ id: "e2", from: "a", to: "a", label: null, when: { kind: "fallback", expr: null, describe: null, digit: null, match: null } });
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ draft: g })), [`PUT ${root}/draft`]: (body) => Response.json(state({ draft: body.flow })) });
    await show();
    openStep(/Greet/);
    fireEvent.click(screen.getByRole("button", { name: "Try later" }));
    fireEvent.click(screen.getByRole("button", { name: "Add an exit" }));
    fireEvent.click(screen.getAllByRole("button", { name: "Remove this exit" })[2]);
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    expect(lastSent(sent, "PUT").exits.map((e) => e.id)).toEqual(["e2", "e1"]);
  });

  it("removes a step with its exits, and moves where calls start", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`PUT ${root}/draft`]: (body) => Response.json(state({ draft: body.flow })) });
    await show();
    openStep(/Greet/);
    fireEvent.click(screen.getByRole("button", { name: "Remove this step" }));
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    expect(lastSent(sent, "PUT")).toMatchObject({ start: "b", exits: [] });
  });

  it("makes another step where calls start", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`PUT ${root}/draft`]: (body) => Response.json(state({ draft: body.flow })) });
    await show();
    openStep(/Bye/);
    fireEvent.click(screen.getByRole("button", { name: "Start calls here" }));
    fireEvent.click(screen.getByRole("button", { name: "Save draft" }));
    await screen.findByRole("status");
    expect(lastSent(sent, "PUT").start).toBe("b");
  });

  it("publishes, says so, and lists what is worth a look with a way to the step", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/publish`]: (body) => Response.json(state({ published: body.flow, warnings: [{ at: "b", message: "It hangs up in silence." }, { at: "e1", message: "About an exit." }] })) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect((await screen.findByRole("status")).textContent).toContain("next call");
    expect(sent.some((s) => s.method === "POST")).toBe(true);
    expect(screen.getByText(/These steps are in use on calls/)).toBeTruthy();
    expect(screen.getByText(/It hangs up in silence/)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Open Bye" }));
    expect(screen.getByRole("region", { name: "Bye" })).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Open Greet" }));
    expect(screen.getByRole("region", { name: "Greet" })).toBeTruthy();
  });

  it("says when publishing is still going through", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/publish`]: (body) => Response.json(state({ published: body.flow, syncState: "pending" })) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect((await screen.findByRole("status")).textContent).toContain("shortly");
  });

  it("opens the step refused and puts the words beside its field", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/publish`]: () => Response.json({ message: "x", errors: { "flow.steps.1.line": ["Say something."] } }, { status: 422 }) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect(await screen.findByText("Say something.")).toBeTruthy();
    expect(screen.getByRole("region", { name: "Bye" })).toBeTruthy();
    expect(within(screen.getByRole("navigation", { name: "Steps" })).getByRole("button", { name: /Bye.*needs fixing/ })).toBeTruthy();
    expect(screen.getByRole("alert").textContent).toContain("needs fixing first");
  });

  it("opens the step an exit refused leaves from", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/publish`]: () => Response.json({ message: "x", errors: { "flow.exits.0.when.describe": ["Describe it."] } }, { status: 422 }) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect(await screen.findByText("Describe it.")).toBeTruthy();
    expect(screen.getByRole("region", { name: "Greet" })).toBeTruthy();
  });

  it("shows what is refused of the whole, and where calls start", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/publish`]: () => Response.json({ message: "x", errors: { flow: ["flow has no nodes"], "flow.start": ["Choose a start."] } }, { status: 422 }) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect(await screen.findByText("flow has no nodes")).toBeTruthy();
    expect(screen.getAllByText("Choose a start.").length).toBeGreaterThan(0);
  });

  it("says why it could not be published when it is not about a field", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/publish`]: () => Response.json({ message: "They could not be checked just now." }, { status: 409 }) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Publish" }));
    expect((await screen.findByRole("alert")).textContent).toBe("They could not be checked just now.");
  });

  it("checks without saving, and says when all is well", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/check`]: () => Response.json({ warnings: [] }) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Check" }));
    expect((await screen.findByRole("status")).textContent).toBe("No problems found.");
    expect(sent.filter((s) => s.method === "PUT")).toEqual([]);
  });

  it("lists what the check found", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ draft: graph() })), [`POST ${root}/check`]: () => Response.json({ warnings: [{ at: "a", message: "Look at this." }] }) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Check" }));
    expect(await screen.findByText(/Look at this/)).toBeTruthy();
    expect(screen.getByRole("status").textContent).toContain("would run");
  });

  it("discards a draft and goes back to what is in use", async () => {
    const inUse = graph();
    fake({ [`GET ${root}`]: () => Response.json(state({ published: inUse, draft: { ...inUse, start: "b" } })), [`DELETE ${root}/draft`]: () => Response.json(state({ published: inUse })) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Discard draft" }));
    expect((await screen.findByRole("status")).textContent).toBe("Draft discarded.");
    expect(within(screen.getByRole("navigation", { name: "Steps" })).getByRole("button", { name: /Greet.*calls start here/ })).toBeTruthy();
  });

  it("asks before going back to the agent's own instructions", async () => {
    const sent = fake({ [`GET ${root}`]: () => Response.json(state({ published: graph() })), [`DELETE ${root}`]: () => Response.json(state()) });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Stop using steps" }));
    expect(screen.getByText(/Stop using these steps\?/)).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Cancel" }));
    expect(screen.queryByText(/Stop using these steps\?/)).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Stop using steps" }));
    fireEvent.click(screen.getByRole("button", { name: "Stop using steps" }));
    expect((await screen.findByRole("status")).textContent).toContain("own instructions again");
  });

  it("shows why the last write did not go through", async () => {
    fake({ [`GET ${root}`]: () => Response.json(state({ syncState: "failed", syncError: "It was refused." })) });
    await show();
    expect(screen.getByText("It was refused.")).toBeTruthy();
  });
});

describe("what a graph does when it is changed", () => {
  const made = () => {
    let g = emptyGraph();
    const a = newStep("conversation", g);
    g = addStep(g, a);
    const b = newStep("end", g);
    g = addStep(g, b);
    return { g, a, b };
  };

  it("starts at the first step that can be started on", () => {
    const note = newStep("note", emptyGraph());
    const g = addStep(emptyGraph(), note);
    expect(g.start).toBeNull();
    expect(addStep(g, newStep("end", g)).start).toBe("step_2");
  });

  it("gives each new step and exit its own id, whatever was removed before", () => {
    const { g, a, b } = made();
    expect(a.id).not.toBe(b.id);
    const smaller = removeStep(g, a.id);
    expect(newStep("end", smaller).id).not.toBe(b.id);
    const withExit = addExit(addExit(g, a.id, b.id), a.id, b.id);
    expect(new Set(withExit.exits.map((e) => e.id)).size).toBe(2);
    expect(addExit(removeExit(withExit, withExit.exits[0].id), a.id, b.id).exits.map((e) => e.id)).toHaveLength(2);
    expect(new Set(addExit(removeExit(withExit, withExit.exits[0].id), a.id, b.id).exits.map((e) => e.id)).size).toBe(2);
  });

  it("takes exits with a step removed, and moves the start to a step that can be started on", () => {
    const { g, a, b } = made();
    const n = addStep(addExit(g, a.id, b.id), newStep("note", g));
    const out = removeStep(n, a.id);
    expect(out.exits).toEqual([]);
    expect(out.start).toBe(b.id);
    expect(removeStep(out, b.id).start).toBeNull();
    expect(removeStep(n, b.id).start).toBe(a.id);
  });

  it("draws no exit from an ending or a note, nor to a note", () => {
    const { g, a, b } = made();
    const note = newStep("note", g);
    const n = addStep(g, note);
    expect(addExit(n, b.id, a.id).exits).toEqual([]);
    expect(addExit(n, note.id, a.id).exits).toEqual([]);
    expect(addExit(n, a.id, note.id).exits).toEqual([]);
    expect(addExit(n, a.id, "nope").exits).toEqual([]);
    expect(canLeave(undefined)).toBe(false);
  });

  it("starts an exit with what suits the step it leaves", () => {
    const start = (kind: string) => {
      let g = emptyGraph();
      const from = newStep(kind, g);
      g = addStep(g, from);
      const to = newStep("end", g);
      return addExit(addStep(g, to), from.id, to.id).exits[0].when;
    };
    expect(start("dtmf").kind).toBe("dtmf");
    expect(start("conversation").kind).toBe("ai");
    expect(start("extract").kind).toBe("ai");
    expect(start("tool")).toMatchObject({ kind: "tool_result", match: "ok" });
    expect(start("transfer").kind).toBe("tool_result");
    expect(start("static").kind).toBe("always");
    expect(start("router").kind).toBe("always");
  });

  it("moves an exit past the next of its own step, whatever lies between, and no further", () => {
    const g: AgentFlowGraph = { start: "a", steps: [base({}), base({ id: "b" })], exits: ["x", "o", "y"].map((id, i) => ({ id, from: i === 1 ? "b" : "a", to: "b", label: null, when: { kind: "always", expr: null, describe: null, digit: null, match: null } })) };
    expect(moveExit(g, "x", 1).exits.map((e) => e.id)).toEqual(["y", "o", "x"]);
    expect(moveExit(g, "y", -1).exits.map((e) => e.id)).toEqual(["y", "o", "x"]);
    expect(moveExit(g, "y", 1).exits.map((e) => e.id)).toEqual(["x", "o", "y"]);
    expect(moveExit(g, "x", -1).exits.map((e) => e.id)).toEqual(["x", "o", "y"]);
    expect(moveExit(g, "nope", 1)).toBe(g);
  });

  it("reads an exit and a step in words", () => {
    const none: Omit<FlowExit["when"], "kind"> = { expr: null, describe: null, digit: null, match: null };
    const e = (when: Partial<FlowExit["when"]> & { kind: string }, label: string | null = null): FlowExit => ({ id: "x", from: "a", to: "b", label, when: { ...none, ...when } });
    expect(exitLabel(e({ kind: "dtmf", digit: "1" }))).toBe("Press 1");
    expect(exitLabel(e({ kind: "dtmf", digit: "1" }, "Booking"))).toBe("Booking");
    expect(exitLabel(e({ kind: "equation", expr: "{a} == 1" }))).toBe("{a} == 1");
    expect(exitLabel(e({ kind: "ai", describe: "done" }))).toBe("done");
    expect(exitLabel(e({ kind: "fallback" }))).toBe("Otherwise");
    expect(exitLabel(e({ kind: "always" }))).toBe("Always");
    expect(stepLabel(base({ name: "  " }))).toBe("Conversation");
    expect(stepLabel(base({ name: "Hi" }))).toBe("Hi");
  });

  it("changes one exit and leaves the rest", () => {
    const { g, a, b } = made();
    const two = addExit(addExit(g, a.id, b.id), a.id, b.id);
    const out = changeExit(two, two.exits[0].id, { label: "Here" });
    expect(out.exits.map((e) => e.label)).toEqual(["Here", null]);
  });

  it("draws each step where it is, marks the start and the open one, and joins only what can be joined", () => {
    const drawn: AgentFlowGraph = { ...graph(), steps: [...graph().steps, base({ id: "n", kind: "note", name: "Remember", x: null, y: null, instructions: "A note." })] };
    drawn.steps[0] = { ...drawn.steps[0], x: 40, y: 80.5 };
    const nodes = toNodes(drawn, "b");
    expect(nodes.map((n) => [n.id, n.type, n.connectable, n.selected, n.position])).toEqual([
      ["a", "default", true, false, { x: 40, y: 80.5 }],
      ["b", "output", true, true, { x: 0, y: 0 }],
      ["n", "output", false, false, { x: 0, y: 0 }],
    ]);
    expect(nodes.map((n) => n.className)).toEqual(["flow-node flow-node-conversation flow-node-start", "flow-node flow-node-end", "flow-node flow-node-note"]);
    expect(nodes.map((n) => n.data.label)).toEqual(drawn.steps.map(stepLabel));
    const [edge] = toEdges(drawn);
    expect(edge).toEqual({ id: "e1", source: "a", target: "b", label: exitLabel(drawn.exits[0]), markerEnd: { type: "arrowclosed" } });
  });

  it("finds the first thing refused", () => {
    const g = graph();
    expect(firstRefused({ "flow.steps.1.name": ["x"] }, g)).toBe("b");
    expect(firstRefused({ "flow.exits.0.to": ["x"] }, g)).toBe("a");
    expect(firstRefused({ flow: ["x"] }, g)).toBeNull();
    expect(firstRefused({ "flow.steps.9.name": ["x"] }, g)).toBeNull();
  });
});
