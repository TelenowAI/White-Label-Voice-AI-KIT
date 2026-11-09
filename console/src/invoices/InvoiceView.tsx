/**
 * One invoice's page, made for printing: reads it with the call it is given (the console's getAccountInvoice or the
 * admin's getCustomerInvoice), says "There is no such invoice." for a 404 and the backend's message for any other
 * refusal; once read, a way back and a Print button above the sheet. Print opens the browser's print window, where
 * the invoice can also be saved as a PDF; the print styles (styles.css `.invoice-page`) leave only the sheet on paper —
 * no brand bar, nav, banners or these tools.
 *
 * Used by InvoicePage.tsx (`/console/invoices/:id`) and admin/CustomerInvoicePage.tsx (`/admin/invoices/:id`). Not here:
 * the sheet (InvoiceSheet.tsx).
 */
import { useEffect, useState, type ReactNode } from "react";
import { KitApiError, type Invoice } from "../api";
import { t } from "../text";
import { InvoiceSheet } from "./InvoiceSheet";

export function InvoiceView<T extends Invoice>({ id, load, back }: { id: string; load: (id: string) => Promise<T>; back: (invoice: T | null) => ReactNode }) {
  const [invoice, setInvoice] = useState<T | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    setInvoice(null);
    setFailure(null);
    load(id).then(
      (read) => current && setInvoice(read),
      (err: unknown) => current && setFailure(err instanceof KitApiError && err.status === 404 ? t("invoiceNotFound") : err instanceof Error ? err.message : String(err)),
    );
    return () => {
      current = false;
    };
  }, [id, load]);

  return (
    <div className="invoice-page">
      <p className="invoice-tools">
        {back(invoice)}
        {invoice && (
          <>
            <button type="button" onClick={() => window.print()}>{t("invoicePrint")}</button>
            <small className="muted">{t("invoicePrintHelp")}</small>
          </>
        )}
      </p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!invoice && !failure && <p aria-busy="true">{t("loading")}</p>}
      {invoice && <InvoiceSheet invoice={invoice} />}
    </div>
  );
}
