/**
 * How a call reads in the console: its outcome, direction, channel, sentiment, number and length in the console's words (text.ts),
 * and the outcomes and channels the calls list can be narrowed to. A value the voice platform reports that has no words here yet
 * is shown as it comes, made readable. A web chat (channel `chat`: a conversation in the chat box on the customer's website)
 * reads as one, where a call shows its direction.
 * Used by the calls list (Calls.tsx), a call's page (CallPage.tsx) and Insights (insights/InsightsPage.tsx).
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
  machine: "outcomeMachine",
};

const channels: Record<string, TextKey> = {
  telephony: "channelTelephony",
  web_call: "channelWebCall",
  softphone: "channelSoftphone",
  chat: "dirChat",
};

/** The channels the calls list and its file can be narrowed to (contract/kit-api.yaml listCalls `channel`). */
export const CHANNEL_FILTERS = ["telephony", "web_call", "softphone", "chat"] as const;

const sentiments: Record<string, TextKey> = {
  positive: "sentimentPositive",
  neutral: "sentimentNeutral",
  negative: "sentimentNegative",
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

export function channelText(channel: string | null): string {
  if (!channel) return "";
  return Object.hasOwn(channels, channel) ? t(channels[channel]) : readable(channel);
}

export function sentimentText(sentiment: string | null): string {
  if (!sentiment) return "";
  return Object.hasOwn(sentiments, sentiment) ? t(sentiments[sentiment]) : readable(sentiment);
}

/** A conversation in the chat box on the customer's website, kept as a call with the channel `chat`: no phone number. */
export function isChat(call: Pick<CallSummary, "channel">): boolean {
  return call.channel === "chat";
}

/** Where the list and a call's page show its direction: "Web chat" for a chat, its direction otherwise. */
export function directionOf(call: Pick<CallSummary, "direction" | "channel">): string {
  return isChat(call) ? t("dirChat") : directionText(call.direction);
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
