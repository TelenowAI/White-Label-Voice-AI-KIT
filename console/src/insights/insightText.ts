/**
 * How the figures of Console → Insights read: a day of the period, minutes, a share, a count, and what was charged in each
 * currency (with the console's money formatting, wallet/money.ts), all in the viewer's locale.
 *
 * Not here: the words for outcomes, channels and sentiments (calls/callText.ts).
 * Used by InsightsPage.tsx and DaysChart.tsx; countText also by knowledge/KnowledgeTablePage.tsx.
 */
import type { InsightSpend } from "../api";
import { money } from "../wallet/money";

/** A day as the backend sends it (YYYY-MM-DD, already in the viewer's time zone), shown as that same day; as it came when it is not one. */
export function dayText(day: string): string {
  const at = new Date(`${day}T00:00:00Z`);
  return Number.isNaN(at.getTime()) ? day : at.toLocaleDateString(undefined, { timeZone: "UTC", year: "numeric", month: "short", day: "numeric" });
}

/** Seconds as whole minutes, rounded, like the admin dashboard's. */
export function minutesText(seconds: number): string {
  return Math.round(seconds / 60).toLocaleString();
}

export function countText(n: number): string {
  return n.toLocaleString();
}

/** `part` of `whole` as a percentage; null when there is no whole to share. */
export function shareText(part: number, whole: number): string | null {
  if (whole <= 0) return null;
  return new Intl.NumberFormat(undefined, { style: "percent", maximumFractionDigits: 1 }).format(part / whole);
}

/** What was charged, each currency in its own money; "" when nothing was. */
export function spendText(spend: InsightSpend[]): string {
  return spend.map((s) => money(s.amount, s.currency)).join(" · ");
}
