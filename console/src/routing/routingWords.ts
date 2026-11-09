/**
 * Call routing's states in the console's words: whether the voice platform asks this server (a connection state), why it had no answer (a
 * RoutingProblem, one sentence each; a value the console does not know reads as "something else", never as sent) and a day's short name (ISO,
 * 1 = Monday). The backend never sends the platform's own text, and nothing here shows any.
 *
 * Not here: a rule's summary (ruleSummary.ts). Used by: RoutingConnectionPanel.tsx, ruleSummary.ts, RoutingRuleForm.tsx.
 */
import type { RoutingConnection, RoutingProblem } from "../api";
import { t, type TextKey } from "../text";

const states: Record<RoutingConnection["state"], TextKey> = {
  connected: "routingState_connected",
  off: "routingState_off",
  elsewhere: "routingState_elsewhere",
  not_connected: "routingState_not_connected",
  unknown: "routingState_unknown",
};

const problems: Record<Exclude<RoutingProblem, null>, TextKey> = {
  off: "routingProblem_off",
  not_connected: "routingProblem_not_connected",
  slow: "routingProblem_slow",
  refused: "routingProblem_refused",
  unreachable: "routingProblem_unreachable",
  bad_answer: "routingProblem_bad_answer",
  address: "routingProblem_address",
  other: "routingProblem_other",
};

const days: Record<number, TextKey> = {
  1: "routingDay1", 2: "routingDay2", 3: "routingDay3", 4: "routingDay4", 5: "routingDay5", 6: "routingDay6", 7: "routingDay7",
};

/** The ISO days, Monday first. */
export const isoDays = [1, 2, 3, 4, 5, 6, 7];

/** Whether the voice platform asks this server, in a sentence; a state the console does not know reads as not known. */
export function stateWords(state: string): string {
  return t(Object.hasOwn(states, state) ? states[state as RoutingConnection["state"]] : "routingState_unknown");
}

/** Why the platform had no answer, in a sentence; null for no problem. */
export function problemWords(problem: RoutingProblem | string): string | null {
  if (problem === null || problem === "") return null;
  return t(Object.hasOwn(problems, problem) ? problems[problem as Exclude<RoutingProblem, null>] : "routingProblem_other");
}

/** A day's short name (1 = Monday); a number outside 1–7 as it is. */
export function dayWord(day: number): string {
  return Object.hasOwn(days, day) ? t(days[day]) : String(day);
}
