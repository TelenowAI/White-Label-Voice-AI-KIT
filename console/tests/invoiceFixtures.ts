/**
 * Customers' invoices for the console's and the admin's invoice tests (Invoices.test.tsx, AdminInvoices.test.tsx), with
 * the values of the backend's own test (laravel/tests/Feature/Kit/CustomerInvoicesTest.php): one with GST 18% included
 * (₹1,180.00 paid = ₹1,000.00 before tax + ₹180.00 tax), paid by bank transfer, both sides' details full and the
 * addresses on two lines; one with no tax, each side with only a name and an email, the way to pay unknown. Also: an
 * invoice's row in a list, and the day it was issued as the pages show it (its UTC day, long form, the viewer's locale).
 */
import type { Invoice, InvoiceSummary } from "../src/api";

export const withTax: Invoice = {
  id: "00000000-0000-4000-8000-0000000000a1", number: "AV/2026-000001", issuedAt: "2026-11-09T10:00:00+00:00", currency: "INR",
  description: "Credit for Acme Dental on Acme Voice", amount: "1180.00", net: "1000.00", tax: { name: "GST", rate: "18", amount: "180.00" },
  seller: { name: "Acme Voice Pvt Ltd", address: "12 Residency Road\nBengaluru 560025", taxNumber: "29ABCDE1234F1Z5", email: "billing@acme-voice.example" },
  buyer: { name: "Acme Dental Clinics LLP", address: "MG Road\nPune 411001", taxNumber: "27AAAAA0000A1Z5", email: "ana@dental.example" },
  paidWith: "Bank transfer (NEFT)", refunded: null,
};

export const noTax: Invoice = {
  id: "00000000-0000-4000-8000-0000000000a2", number: "AV/2026-000002", issuedAt: "2026-11-10T09:00:00+00:00", currency: "INR",
  description: "Credit for Acme Dental on Acme Voice", amount: "500.00", net: "500.00", tax: null,
  seller: { name: "Acme Voice", address: null, taxNumber: null, email: "help@acme-voice.example" },
  buyer: { name: "Acme Dental", address: null, taxNumber: null, email: "ben@dental.example" },
  paidWith: null, refunded: null,
};

/** An older invoice, from the year before: a third row for paging (two to a page leaves it alone on the second). */
export const older: InvoiceSummary = {
  id: "00000000-0000-4000-8000-0000000000a3", number: "AV/2025-000042", issuedAt: "2025-12-01T10:00:00+00:00", currency: "INR", amount: "250.00", refunded: null,
};

export function summary(invoice: Invoice): InvoiceSummary {
  const { id, number, issuedAt, currency, amount, refunded } = invoice;
  return { id, number, issuedAt, currency, amount, refunded };
}

export const longDay = (at: string) => new Date(at).toLocaleDateString(undefined, { dateStyle: "long", timeZone: "UTC" });

/** Each row of a table as its cells' text, the header row too. */
export function rowsOf(table: HTMLElement): string[][] {
  return [...table.querySelectorAll("tr")].map((row) => [...row.children].map((cell) => cell.textContent ?? ""));
}

/** A party's lines on the sheet (name, address with its breaks, tax number, email) as their HTML. */
export function linesOf(party: HTMLElement): string[] {
  return [...party.querySelectorAll("p")].map((p) => p.innerHTML);
}
