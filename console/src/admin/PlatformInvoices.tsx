/**
 * Admin → Platform billing → the voice platform's invoices to the operator (contract/kit-api.yaml getPlatformBilling
 * `invoices`), in the order the backend gives them (newest period first, drafts too): each with its period, status (a
 * draft is not issued yet), when it was issued, is due and was paid, its total in its own currency and in US dollars,
 * and its lines (net) with, when the total holds more than them, a Tax line of the rest. None: the platform invoices
 * postpaid accounts only. Not read (`invoices` null): the backend's reason, quietly, and the rest of the page stays.
 *
 * Used by PlatformBillingPage.tsx. Not here: reading them (the backend's App\Kit\Telenow\PlatformBilling::invoices).
 */
import type { PlatformInvoice } from "../api";
import { t, type TextKey } from "../text";
import { money } from "../wallet/money";
import { NONE, calendarDay, dayOf, usd } from "./platformBillingFormat";

const STATUS: Record<PlatformInvoice["status"], TextKey> = {
  draft: "platformBillingInvoiceDraft",
  issued: "platformBillingInvoiceIssued",
  paid: "platformBillingInvoicePaid",
  overdue: "platformBillingInvoiceOverdue",
  void: "platformBillingInvoiceVoid",
  other: "platformBillingInvoiceOther",
};

const inCurrency = (amount: string | null, currency: string) => (amount === null ? NONE : money(amount, currency || null));

export function PlatformInvoices({ invoices, error }: { invoices: PlatformInvoice[] | null; error: string | null }) {
  return (
    <section aria-labelledby="platform-billing-invoices">
      <h2 id="platform-billing-invoices">{t("platformBillingInvoices")}</h2>
      {invoices === null ? (
        <p className="muted" data-testid="invoices-error">{error ?? t("platformBillingInvoicesFailed")}</p>
      ) : invoices.length === 0 ? (
        <p className="muted">{t("platformBillingInvoicesNone")}</p>
      ) : (
        <ul className="invoices" aria-label={t("platformBillingInvoices")}>
          {invoices.map((inv) => <Invoice key={inv.id} invoice={inv} />)}
        </ul>
      )}
    </section>
  );
}

function Invoice({ invoice }: { invoice: PlatformInvoice }) {
  const period = t("platformBillingInvoicePeriod", { from: calendarDay(invoice.periodStart), to: calendarDay(invoice.periodEnd) });
  return (
    <li data-invoice={invoice.id}>
      <h3>{period}</h3>
      <dl className="facts">
        <div><dt>{t("platformBillingInvoiceStatus")}</dt><dd data-testid="invoice-status">{t(STATUS[invoice.status] ?? "platformBillingInvoiceOther")}</dd></div>
        <div><dt>{t("platformBillingInvoiceIssuedOn")}</dt><dd>{dayOf(invoice.issuedAt)}</dd></div>
        <div><dt>{t("platformBillingInvoiceDueBy")}</dt><dd>{dayOf(invoice.dueAt)}</dd></div>
        <div><dt>{t("platformBillingInvoicePaidOn")}</dt><dd>{dayOf(invoice.paidAt)}</dd></div>
        <div><dt>{t("platformBillingInvoiceTotal")}</dt><dd data-testid="invoice-total">{inCurrency(invoice.total, invoice.currency)}</dd></div>
        <div><dt>{t("platformBillingInvoiceTotalUsd")}</dt><dd data-testid="invoice-total-usd">{usd(invoice.totalUsd)}</dd></div>
      </dl>
      {(invoice.lines.length > 0 || invoice.rest !== null) && (
        <div className="table-scroll">
          <table className="list" aria-label={t("platformBillingInvoiceLines", { period })}>
            <thead>
              <tr>
                <th scope="col">{t("platformBillingInvoiceWhat")}</th>
                <th scope="col">{t("platformBillingInvoiceAmount")}</th>
              </tr>
            </thead>
            <tbody>
              {invoice.lines.map((line, i) => (
                <tr key={`${line.kind}-${i}`}>
                  <th scope="row">{line.description}</th>
                  <td>{inCurrency(line.amount, invoice.currency)}</td>
                </tr>
              ))}
              {invoice.rest !== null && (
                <tr data-row="tax">
                  <th scope="row">{t("platformBillingInvoiceTax")}</th>
                  <td>{inCurrency(invoice.rest, invoice.currency)}</td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </li>
  );
}
