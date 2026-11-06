/**
 * Call steps as the console edits them (contract/kit-api.yaml AgentFlowGraph): adding, changing and removing steps and exits, and what the canvas draws. Nothing here talks to the backend or draws anything.
 *
 * Not here: the page (FlowPage.tsx), the canvas (FlowCanvas.tsx), the forms (FlowStepForm.tsx, FlowExitRows.tsx).
 */
import type { Edge, Node } from "@xyflow/react";
import type { AgentFlowGraph, FlowExit, FlowStep } from "../../api";
import { t, type TextKey } from "../../text";

/** The word each kind of step goes by. */
export const kindWords: Record<string, TextKey> = {
  conversation: "flowKindConversation", extract: "flowKindExtract", dtmf: "flowKindDtmf", static: "flowKindStatic", router: "flowKindRouter", tool: "flowKindTool",
  code: "flowKindCode", transfer: "flowKindTransfer", agent: "flowKindAgent", end: "flowKindEnd", note: "flowKindNote",
};

export const exitWords: Record<string, TextKey> = {
  always: "flowExitAlways", equation: "flowExitEquation", tool_result: "flowExitToolResult", fallback: "flowExitFallback", dtmf: "flowExitDtmf", ai: "flowExitAi",
};

export const captureTypeWords: Record<string, TextKey> = { string: "flowCaptureType_string", number: "flowCaptureType_number", boolean: "flowCaptureType_boolean", enum: "flowCaptureType_enum" };

export const captureFormatWords: Record<string, TextKey> = { email: "flowCaptureFormat_email", alphanumeric: "flowCaptureFormat_alphanumeric" };

const CANVAS = { columns: 4, across: 260, down: 150 };

export const emptyGraph = (): AgentFlowGraph => ({ start: null, steps: [], exits: [] });

function nextId(prefix: string, taken: string[]): string {
  for (let n = taken.length + 1; ; n++) {
    const id = `${prefix}_${n}`;
    if (!taken.includes(id)) return id;
  }
}

/** A new step of a kind, where the canvas has room, with what its kind always has. */
export function newStep(kind: string, graph: AgentFlowGraph): FlowStep {
  const at = graph.steps.length;
  const id = nextId("step", graph.steps.map((s) => s.id));
  return {
    id, kind, name: t(kindWords[kind] ?? "flowKindConversation"), x: (at % CANVAS.columns) * CANVAS.across, y: Math.floor(at / CANVAS.columns) * CANVAS.down,
    instructions: null, replaceInstructions: false, line: null, continueAfterLine: false, capture: [], keypad: kind === "dtmf" ? { routeOnly: true, only: false } : null, anywhere: null,
    tool: kind === "tool" ? { name: `request_${id}`, description: null, url: "", request: null, parameters: [], tokenHint: null, sayWhileRunning: null, timeoutSecs: null, askFirst: false } : null,
    results: [], sends: [],
    code: kind === "code" ? { source: "", timeoutMs: null, keep: [] } : null,
    transfer: kind === "transfer" ? { destinations: [{ label: null, numbers: [""], mode: "serial" }], message: null, noAnswerMessage: null } : null,
    handoff: kind === "agent" ? { agents: [], message: null } : null,
  };
}

/** Adding a step: the first one added is where calls start. A note is never visited, so it cannot be. */
export function addStep(graph: AgentFlowGraph, step: FlowStep): AgentFlowGraph {
  return { ...graph, start: graph.start ?? (step.kind === "note" ? null : step.id), steps: [...graph.steps, step] };
}

export function changeStep(graph: AgentFlowGraph, id: string, over: Partial<FlowStep>): AgentFlowGraph {
  return { ...graph, steps: graph.steps.map((s) => (s.id === id ? { ...s, ...over } : s)) };
}

/** Removing a step takes the exits to and from it too: one exit pointing nowhere would lose the whole graph on a call. */
export function removeStep(graph: AgentFlowGraph, id: string): AgentFlowGraph {
  const steps = graph.steps.filter((s) => s.id !== id);
  return { start: graph.start === id ? (steps.find((s) => s.kind !== "note")?.id ?? null) : graph.start, steps, exits: graph.exits.filter((e) => e.from !== id && e.to !== id) };
}

/** Which condition a new exit starts with: what suits a step that waits for the caller, or one that does not. */
function firstCondition(from: FlowStep | undefined): FlowExit["when"] {
  const none = { expr: null, describe: null, digit: null, match: null };
  if (from?.kind === "dtmf") return { kind: "dtmf", ...none };
  if (from?.kind === "tool" || from?.kind === "transfer" || from?.kind === "agent") return { kind: "tool_result", ...none, match: "ok" };
  if (from?.kind === "conversation" || from?.kind === "extract") return { kind: "ai", ...none };
  return { kind: "always", ...none };
}

/** A step with no exit, nor any waiting for the caller, stops the call there, so an end or a note gets none. */
export function canLeave(step: FlowStep | undefined): boolean {
  return step !== undefined && step.kind !== "end" && step.kind !== "note";
}

export function addExit(graph: AgentFlowGraph, from: string, to: string): AgentFlowGraph {
  const source = graph.steps.find((s) => s.id === from);
  const target = graph.steps.find((s) => s.id === to);
  if (!canLeave(source) || target === undefined || target.kind === "note") return graph;
  return { ...graph, exits: [...graph.exits, { id: nextId("exit", graph.exits.map((e) => e.id)), from, to, label: null, when: firstCondition(source) }] };
}

export function changeExit(graph: AgentFlowGraph, id: string, over: Partial<FlowExit>): AgentFlowGraph {
  return { ...graph, exits: graph.exits.map((e) => (e.id === id ? { ...e, ...over } : e)) };
}

export function removeExit(graph: AgentFlowGraph, id: string): AgentFlowGraph {
  return { ...graph, exits: graph.exits.filter((e) => e.id !== id) };
}

/** An exit's place among those of its step is the order they are tried in: moved past the next one of the same step, wherever others lie between. */
export function moveExit(graph: AgentFlowGraph, id: string, by: -1 | 1): AgentFlowGraph {
  const at = graph.exits.findIndex((e) => e.id === id);
  if (at < 0) return graph;
  const from = graph.exits[at].from;
  const places = graph.exits.flatMap((e, i) => (e.from === from ? [i] : []));
  const other = places[places.indexOf(at) + by];
  if (other === undefined) return graph;
  const exits = [...graph.exits];
  [exits[at], exits[other]] = [exits[other], exits[at]];
  return { ...graph, exits };
}

/** What an exit says on the canvas. */
export function exitLabel(exit: FlowExit): string {
  if (exit.label) return exit.label;
  switch (exit.when.kind) {
    case "dtmf": return t("flowExitPress", { digit: exit.when.digit ?? "?" });
    case "equation": return exit.when.expr ?? t(exitWords.equation);
    case "ai": return exit.when.describe ?? t(exitWords.ai);
    case "tool_result": return t(exitWords.tool_result);
    default: return t(exitWords[exit.when.kind] ?? "flowExitAlways");
  }
}

export function stepLabel(step: FlowStep): string {
  return step.name?.trim() || t(kindWords[step.kind] ?? "flowKindConversation");
}

/** The first refused step or exit, by what the refusals name, so the page can open it. */
export function firstRefused(errors: Record<string, string[]>, graph: AgentFlowGraph): string | null {
  for (const key of Object.keys(errors)) {
    const step = /^flow\.steps\.(\d+)/.exec(key);
    if (step) return graph.steps[Number(step[1])]?.id ?? null;
    const exit = /^flow\.exits\.(\d+)/.exec(key);
    if (exit) return graph.exits[Number(exit[1])]?.from ?? null;
  }
  return null;
}

/** What the map draws for the steps. Types only from the drawing library, so this file does not load it. A step that cannot be left has nothing to draw an exit from, and a note joins nothing. */
export const toNodes = (graph: AgentFlowGraph, selected: string | null): Node[] => graph.steps.map((step) => ({
  id: step.id, position: { x: step.x ?? 0, y: step.y ?? 0 }, data: { label: stepLabel(step) }, selected: step.id === selected,
  className: [`flow-node flow-node-${step.kind}`, graph.start === step.id ? "flow-node-start" : ""].join(" ").trim(),
  type: canLeave(step) ? "default" : "output", connectable: step.kind !== "note",
}));

export const toEdges = (graph: AgentFlowGraph): Edge[] => graph.exits.map((exit) => ({
  id: exit.id, source: exit.from, target: exit.to, label: exitLabel(exit), markerEnd: { type: "arrowclosed" },
}));
