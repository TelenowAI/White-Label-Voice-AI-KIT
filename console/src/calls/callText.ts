/**
 * How a call reads in the console: its outcome, direction, number and length in the console's words (text.ts).
 * A value the voice platform reports that has no words here yet is shown as it comes, made readable.
 * Used by the calls list (Calls.tsx) and a call's page (CallPage.tsx).
 */
import type { CallSummary } from "../api";
import { t, type TextKey } from "../text";

const outcomes: Record<string, TextKey> = {
  answered: "outcomeCompleted",
  "no-answer": "outcomeNoAnswer",
  completed: "outcomeCompleted",
  no_answer: "outcomeNoAnswer",
  busy: "outcomeBusy",
  failed: "outcomeFailed",
};

const directions: Record<string, TextKey> = {
  inbound: "dirInbound",
  outbound: "dirOutbound",
  web: "dirWeb",
};

function readable(value: string): string {
  return value.replace(/[_-]/g, " ");
}

export function outcomeText(outcome: string | null): string {
  if (!outcome) return "";
  return outcome in outcomes ? t(outcomes[outcome]) : readable(outcome);
}

export function directionText(direction: string | null): string {
  if (!direction) return "";
  return direction in directions ? t(directions[direction]) : readable(direction);
}

/** The other party's number: the caller on an incoming call, the one called otherwise; null for a browser call. */
export function otherNumber(call: Pick<CallSummary, "direction" | "from" | "to">): string | null {
  return call.direction === "inbound" ? call.from : call.to;
}

/** `m:ss` (or `h:mm:ss`). */
export function durationText(seconds: number | null): string {
  if (seconds === null) return "";
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  const s = String(seconds % 60).padStart(2, "0");
  return h > 0 ? `${h}:${String(m).padStart(2, "0")}:${s}` : `${m}:${s}`;
}
