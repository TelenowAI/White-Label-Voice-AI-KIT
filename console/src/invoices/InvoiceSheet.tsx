/**
 * The invoice itself, the part that goes on paper (contract/kit-api.yaml Invoice): its number and the day it was
 * issued; the operator selling ("From") and the customer "Billed to", each with name, address (its line breaks
 * kept), tax number and email as they were when it was issued; the line with the amount paid; with a tax, the
 * amount before tax, the tax ("GST 18%": its name and rate) and the total paid, else the total alone (the amount
 * paid includes the tax: net + tax = amount); the way it was paid with, when known; and, when some of the payment
 * has since been refunded, how much (the invoice itself stays as issued). Money in the invoice's own currency.
 *
 * Used by InvoiceView.tsx (the console's and the admin's invoice pages). Not here: loading it, the Print button
 * (InvoiceView.tsx), the print styles (styles.css `.invoice-page`).
 */
import type { Invoice, InvoiceParty } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";
import { AddressLines } from "./AddressLines";
import { issuedOn } from "./issuedOn";

export function InvoiceSheet({ invoice }: { invoice: Invoice }) {
  const inCurrency = (amount: string) => money(amount, invoice.currency);
  return (
    <article className="invoice-sheet" aria-labelledby="invoice-title">
      <header className="invoice-head">
        <h1 id="invoice-title">{t("invoiceTitle")}</h1>
        <dl className="facts">
          <div><dt>{t("invoiceNumber")}</dt><dd data-testid="invoice-number">{invoice.number}</dd></div>
          <div><dt>{t("invoiceIssued")}</dt><dd data-testid="invoice-issued">{issuedOn(invoice.issuedAt)}</dd></div>
        </dl>
      </header>
      <div className="invoice-parties">
        <Party id="invoice-from" title={t("invoiceFrom")} party={invoice.seller} />
        <Party id="invoice-to" title={t("invoiceBilledTo")} party={invoice.buyer} />
      </div>
      <table className="invoice-lines" aria-label={t("invoiceTitle")}>
        <thead>
          <tr>
            <th scope="col">{t("invoiceDescription")}</th>
            <th scope="col">{t("invoiceAmount")}</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>{invoice.description}</td>
            <td>{inCurrency(invoice.amount)}</td>
          </tr>
        </tbody>
        <tfoot>
          {invoice.tax && (
            <>
              <tr>
                <th scope="row">{t("invoiceNet")}</th>
                <td>{inCurrency(invoice.net)}</td>
              </tr>
              <tr>
                <th scope="row">{t("invoiceTaxLine", { name: invoice.tax.name, rate: invoice.tax.rate })}</th>
                <td>{inCurrency(invoice.tax.amount)}</td>
              </tr>
            </>
          )}
          <tr className="invoice-total">
            <th scope="row">{t("invoiceTotal")}</th>
            <td>{inCurrency(invoice.amount)}</td>
          </tr>
        </tfoot>
      </table>
      {invoice.paidWith && <p data-testid="invoice-paid-with">{t("invoicePaidWith", { way: invoice.paidWith })}</p>}
      {invoice.refunded !== null && (
        <p className="invoice-refunded" data-testid="invoice-refunded">{t("invoiceRefunded", { amount: inCurrency(invoice.refunded) })}</p>
      )}
    </article>
  );
}

function Party({ id, title, party }: { id: string; title: string; party: InvoiceParty }) {
  return (
    <section className="invoice-party" aria-labelledby={id}>
      <h2 id={id}>{title}</h2>
      <p><strong>{party.name}</strong></p>
      {party.address && <p><AddressLines address={party.address} /></p>}
      {party.taxNumber && <p>{t("invoiceTaxNumber", { number: party.taxNumber })}</p>}
      {party.email && <p>{party.email}</p>}
    </section>
  );
}
