/**
 * The day an invoice was issued, as its lists and its printable page show it: its day in UTC — the calendar the
 * backend numbers invoices by (the year in "INV-2026-000001" is the UTC year), so the date always agrees with the
 * number, wherever the viewer is — in the viewer's locale, in the long form ("9 November 2026"), so a printed copy
 * cannot be misread in another country's day-and-month order.
 *
 * Used by InvoiceList.tsx and InvoiceSheet.tsx. Not here: money (wallet/money.ts).
 */
export function issuedOn(at: string): string {
  return new Date(at).toLocaleDateString(undefined, { dateStyle: "long", timeZone: "UTC" });
}
