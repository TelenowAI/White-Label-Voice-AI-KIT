/**
 * A campaign's status, its voicemail handling and a call's outcome in the console's words (a value the console has no
 * word for shows as the backend sends it, dashes as spaces). Used by: CampaignsPage.tsx, CampaignPage.tsx.
 */
import type { Campaign } from "../api";
import { t, type TextKey } from "../text";

const statuses: Record<Campaign["status"], TextKey> = {
  saving: "statusSaving", failed: "statusFailed", draft: "statusDraft", queued: "statusQueued", running: "statusRunning",
  paused: "statusPaused", completed: "statusCompleted", cancelled: "statusCancelled",
};

const outcomes: Record<string, TextKey> = {
  answered: "outcomeCompleted", "no-answer": "outcomeNoAnswer", busy: "outcomeBusy", machine: "outcomeMachine", failed: "outcomeFailed",
};

const voicemail: Record<string, TextKey> = { off: "voicemailOff", voicemail: "voicemailVoicemail", hangup: "voicemailHangup" };

function word(words: Record<string, TextKey>, value: string): string {
  return Object.hasOwn(words, value) ? t(words[value]) : value.replace(/-/g, " ");
}

export function statusWord(status: Campaign["status"]): string {
  return t(statuses[status]);
}

export function outcomeWord(outcome: string): string {
  return word(outcomes, outcome);
}

export function voicemailWord(value: string): string {
  return word(voicemail, value);
}
