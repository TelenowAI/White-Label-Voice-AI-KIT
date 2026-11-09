/**
 * A page of invoices in the order given (the backend's: newest first): each one's number, linked to its printable
 * page, the day it was issued, and the amount paid in its currency — with "refunded …" beside it when some of the
 * payment has since been given back. None: the words the caller gives. More than one page: "Newer" and "Older" either
 * side of "Page 2 of 3", each handing the page wanted to `onPage` (the caller reads it).
 *
 * Used by InvoicesPage.tsx (the console, linking to `/console/invoices/:id`) and admin/CustomerInvoices.tsx (a
 * customer's page, linking to `/admin/invoices/:id`). Not here: reading them.
 */
import { Link } from "react-router-dom";
import type { InvoicePage } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";
import { issuedOn } from "./issuedOn";

export function InvoiceList({ list, linkTo, empty, onPage }: { list: InvoicePage; linkTo: (id: string) => string; empty: string; onPage: (page: number) => void }) {
  const pages = list.perPage > 0 ? Math.max(1, Math.ceil(list.total / list.perPage)) : 1;
  return (
    <>
      {list.invoices.length === 0 ? (
        <p className="muted">{empty}</p>
      ) : (
        <div className="table-scroll">
          <table className="list" aria-label={t("invoicesTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("colInvoiceNumber")}</th>
                <th scope="col">{t("colInvoiceDate")}</th>
                <th scope="col">{t("colAmount")}</th>
              </tr>
            </thead>
            <tbody>
              {list.invoices.map((invoice) => (
                <tr key={invoice.id}>
                  <td><Link to={linkTo(invoice.id)}>{invoice.number}</Link></td>
                  <td>{issuedOn(invoice.issuedAt)}</td>
                  <td>
                    {money(invoice.amount, invoice.currency)}
                    {invoice.refunded !== null && (
                      <small className="muted"> · {t("invoiceRefundedShort", { amount: money(invoice.refunded, invoice.currency) })}</small>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {pages > 1 && (
        <nav className="pager" aria-label={t("invoicesPages")}>
          <button type="button" className="secondary" disabled={list.page <= 1} onClick={() => onPage(list.page - 1)}>
            {t("invoicesNewer")}
          </button>
          <span>{t("pageOf", { page: String(list.page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={list.page >= pages} onClick={() => onPage(list.page + 1)}>
            {t("invoicesOlder")}
          </button>
        </nav>
      )}
    </>
  );
}
