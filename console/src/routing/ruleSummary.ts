/**
 * A call routing rule in two readable lines: when it takes a call ("When: calls to +1…, forwarded from 3 lines; Mon–Fri, 09:00 until 18:00
 * (Europe/London)", or any call when it lists nothing) and what it then does ("Then: answer with Front desk, in hi-IN, greeting “…”, at most 30
 * minutes", or a refusal). A number the rule lists that the account no longer has reads as such; up to three lines or starts are listed, more
 * are counted; three or more days in a row read as a range.
 *
 * Not here: whether its agent was deleted (said beside it by RoutingRuleList.tsx). Used by: RoutingRuleList.tsx.
 */
import type { RoutingNumber, RoutingRule } from "../api";
import { t } from "../text";
import { dayWord } from "./routingWords";

/** Lists longer than this are counted, not listed. */
const listedAtMost = 3;

/** The days as short names, three or more in a row as a range (Mon–Fri); every day when all seven. */
export function daysWords(days: number[]): string {
  const sorted = [...new Set(days)].sort((a, b) => a - b);
  if (sorted.length === 7) return t("routingWhenEveryDay");
  const runs: number[][] = [];
  for (const d of sorted) {
    const last = runs[runs.length - 1];
    if (last && last[last.length - 1] === d - 1) last.push(d);
    else runs.push([d]);
  }
  return runs.flatMap((run) => (run.length >= 3 ? [`${dayWord(run[0])}–${dayWord(run[run.length - 1])}`] : run.map(dayWord))).join(", ");
}

/** The hours, saying "the next day" when they run past midnight. */
export function hoursWords(from: string, until: string): string {
  return t(until <= from ? "routingWhenHoursOvernight" : "routingWhenHours", { from, until });
}

export function whenWords(rule: RoutingRule, numbers: RoutingNumber[]): string {
  const parts: string[] = [];
  if (rule.numberIds.length > 0) {
    const named = rule.numberIds.map((id) => numbers.find((n) => n.id === id)?.number ?? t("routingNumberGone"));
    parts.push(t("routingWhenNumbers", { numbers: named.join(", ") }));
  }
  if (rule.forwardedFrom.length > 0) {
    parts.push(rule.forwardedFrom.length > listedAtMost
      ? t("routingWhenForwardedMany", { n: String(rule.forwardedFrom.length) })
      : t("routingWhenForwarded", { lines: rule.forwardedFrom.join(", ") }));
  }
  if (rule.callerStartsWith.length > 0) {
    parts.push(rule.callerStartsWith.length > listedAtMost
      ? t("routingWhenCallerStartsMany", { n: String(rule.callerStartsWith.length) })
      : t("routingWhenCallerStarts", { starts: rule.callerStartsWith.join(", ") }));
  }
  const time = [
    ...(rule.days.length > 0 ? [daysWords(rule.days)] : []),
    ...(rule.from !== null && rule.until !== null ? [hoursWords(rule.from, rule.until)] : []),
  ].join(", ");
  if (time !== "") parts.push(rule.timezone ? t("routingWhenTimezone", { time, timezone: rule.timezone }) : time);
  return t("routingWhen", { what: parts.length > 0 ? parts.join("; ") : t("routingWhenAny") });
}

export function thenWords(rule: RoutingRule): string {
  if (rule.action === "refuse") return t("routingThen", { what: t("routingThenRefuse") });
  const parts = [
    rule.agent ? t("routingThenAgent", { name: rule.agent.name }) : t("routingThenOwnAgent"),
    ...(rule.language ? [t("routingThenLanguage", { language: rule.language })] : []),
    ...(rule.greeting ? [t("routingThenGreeting", { greeting: rule.greeting })] : []),
    ...(rule.maxMinutes !== null ? [t("routingThenMinutes", { n: String(rule.maxMinutes) })] : []),
  ];
  return t("routingThen", { what: parts.join(", ") });
}
