/**
 * How Admin → Platform billing shows the voice platform's figures: US dollars to the cent (the platform sends up to ten
 * places), a date-time in the viewer's locale, and a calendar day (an invoice's period) as that same day wherever the
 * viewer is; "—" for a value the platform did not send.
 *
 * Used by PlatformAccount.tsx, PlatformBalanceAlert.tsx and PlatformInvoices.tsx. Not here: the words (text.ts), money
 * in an invoice's own currency (wallet/money.ts `money`).
 */
import { money, rounded } from "../wallet/money";

/** The voice platform's dollar figures, to the cent. */
export const CENTS = 2;

export const NONE = "—";

export function usd(amount: string | null | undefined): string {
  return amount == null ? NONE : money(rounded(amount, CENTS), "USD");
}

export function moment(at: string | null | undefined): string {
  return at ? new Date(at).toLocaleString() : NONE;
}

export function dayOf(at: string | null | undefined): string {
  return at ? new Date(at).toLocaleDateString() : NONE;
}

/** A date with no time ("2026-11-01") as that day: read as midnight UTC and shown in UTC, never the day before. */
export function calendarDay(date: string | null | undefined): string {
  return date ? new Date(`${date}T00:00:00Z`).toLocaleDateString(undefined, { timeZone: "UTC" }) : NONE;
}
