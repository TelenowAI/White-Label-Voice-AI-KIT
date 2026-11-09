/**
 * The call routing rule form's values (as typed) and what is sent from them, the same for a new rule and a changed one: a new rule starts on,
 * answering with the number's own agent, in the browser's own timezone; a changed one leaves out numbers the account no longer has; the lines of the forwarded-from and caller-start boxes are trimmed, with
 * blank lines and repeats dropped; empty text is sent as null; a refusing rule sends none of an answer's settings (the backend keeps none).
 * And the backend's 422 placed at the form's fields: an indexed key (forwardedFrom.2) at its box, saying which line as typed and its value; a key
 * the form has no field for is returned apart, for the form to show as a whole.
 *
 * Not here: the form itself (RoutingRuleForm.tsx). Used by: RoutingRuleForm.tsx.
 */
import type { RoutingNumber, RoutingRule, RoutingRuleInput } from "../api";
import { t } from "../text";

export interface RuleFormValues {
  name: string;
  enabled: boolean;
  numberIds: string[];
  /** One line per number, as typed. */
  forwardedFrom: string;
  callerStartsWith: string;
  days: number[];
  from: string;
  until: string;
  timezone: string;
  action: "answer" | "refuse";
  /** "": the number's own agent. */
  agentId: string;
  language: string;
  greeting: string;
  maxMinutes: string;
}

/** The form's fields, each a key the backend may refuse. */
export const ruleFields = ["name", "enabled", "numberIds", "forwardedFrom", "callerStartsWith", "days", "from", "until", "timezone", "action",
  "agentId", "language", "greeting", "maxMinutes"] as const;

export type RuleField = (typeof ruleFields)[number];

/** A line of a box as sent, with where it was typed (1 = the first line). */
export interface SentLine {
  value: string;
  line: number;
}

/** The lines of the two boxes as last sent, to say which line a refusal is about. */
export interface SentLines {
  forwardedFrom: SentLine[];
  callerStartsWith: SentLine[];
}

export function newRuleValues(): RuleFormValues {
  return {
    name: "", enabled: true, numberIds: [], forwardedFrom: "", callerStartsWith: "", days: [], from: "", until: "",
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, action: "answer", agentId: "", language: "", greeting: "", maxMinutes: "",
  };
}

/** A saved rule's values; a number it lists that the account no longer has is left out (it is not offered, and the backend would refuse it). */
export function ruleValues(rule: RoutingRule, numbers: RoutingNumber[]): RuleFormValues {
  return {
    name: rule.name, enabled: rule.enabled, numberIds: rule.numberIds.filter((id) => numbers.some((n) => n.id === id)), forwardedFrom: rule.forwardedFrom.join("\n"),
    callerStartsWith: rule.callerStartsWith.join("\n"), days: [...rule.days], from: rule.from ?? "", until: rule.until ?? "",
    timezone: rule.timezone ?? Intl.DateTimeFormat().resolvedOptions().timeZone, action: rule.action, agentId: rule.agent?.id ?? "",
    language: rule.language ?? "", greeting: rule.greeting ?? "", maxMinutes: rule.maxMinutes === null ? "" : String(rule.maxMinutes),
  };
}

/** A box's lines: trimmed, blank lines and repeats dropped, each with the line it was typed on. */
export function lines(text: string): SentLine[] {
  const seen = new Set<string>();
  const out: SentLine[] = [];
  text.split(/\r?\n/).forEach((typed, i) => {
    const value = typed.trim();
    if (value === "" || seen.has(value)) return;
    seen.add(value);
    out.push({ value, line: i + 1 });
  });
  return out;
}

function orNull(text: string): string | null {
  return text.trim() === "" ? null : text.trim();
}

/** What is sent for the values, and the boxes' lines as sent. */
export function ruleInput(v: RuleFormValues): { input: RoutingRuleInput & { name: string; action: "answer" | "refuse" }; sent: SentLines } {
  const sent: SentLines = { forwardedFrom: lines(v.forwardedFrom), callerStartsWith: lines(v.callerStartsWith) };
  const answer: RoutingRuleInput = v.action === "answer"
    ? { agentId: v.agentId || null, language: orNull(v.language), greeting: orNull(v.greeting), maxMinutes: v.maxMinutes.trim() === "" ? null : Number(v.maxMinutes) }
    : {};
  return {
    input: {
      name: v.name.trim(), enabled: v.enabled, numberIds: v.numberIds, forwardedFrom: sent.forwardedFrom.map((l) => l.value),
      callerStartsWith: sent.callerStartsWith.map((l) => l.value), days: [...v.days].sort((a, b) => a - b), from: orNull(v.from), until: orNull(v.until),
      timezone: orNull(v.timezone), action: v.action, ...answer,
    },
    sent,
  };
}

function isField(key: string): key is RuleField {
  return (ruleFields as readonly string[]).includes(key);
}

/** The backend's refusals by the form's fields (a box's line named, as typed), and those the form has no field for. */
export function placeErrors(refused: Record<string, string[]>, sent: SentLines): { fields: Partial<Record<RuleField, string[]>>; other: string[] } {
  const fields: Partial<Record<RuleField, string[]>> = {};
  const other: string[] = [];
  for (const [key, reasons] of Object.entries(refused)) {
    const indexed = /^(\w+)\.(\d+)$/.exec(key);
    const base = indexed ? indexed[1] : key;
    if (!isField(base)) {
      other.push(...reasons);
      continue;
    }
    const typed = indexed && (base === "forwardedFrom" || base === "callerStartsWith") ? sent[base][Number(indexed[2])] : undefined;
    const said = typed ? reasons.map((error) => t("routingLineError", { line: String(typed.line), value: typed.value, error })) : reasons;
    fields[base] = [...(fields[base] ?? []), ...said];
  }
  return { fields, other };
}
