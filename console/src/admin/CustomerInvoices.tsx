/**
 * Admin → a customer → Invoices (contract/kit-api.yaml listCustomerInvoices; staff with `customers_read`): the
 * customer's invoices newest first, a page at a time (the page asked for only past the first), each linked to its
 * printable page in the admin panel (`/admin/invoices/:id`), and the billing details its next invoices will carry,
 * read only (the customer's owners and admins change them in their console). A failed read is an alert in this
 * section; the rest of the customer's page stays.
 *
 * Used by CustomerPage.tsx. Not here: one invoice (CustomerInvoicePage.tsx), the operator's own invoice settings
 * (InvoiceSettingsPage.tsx).
 */
import { useEffect, useState } from "react";
import { kitApi, type CustomerInvoiceList } from "../api";
import { BillingDetailsFacts } from "../invoices/BillingDetailsFacts";
import { InvoiceList } from "../invoices/InvoiceList";
import { t } from "../text";

export function CustomerInvoices({ customerId }: { customerId: string }) {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<CustomerInvoiceList | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    kitApi.customerInvoices(customerId, page).then(
      (read) => {
        if (!current) return;
        setData(read);
        setFailure(null);
      },
      (err: unknown) => current && setFailure(err instanceof Error ? err.message : String(err)),
    );
    return () => {
      current = false;
    };
  }, [customerId, page]);

  return (
    <section aria-labelledby="customer-invoices-title">
      <h2 id="customer-invoices-title">{t("invoicesTitle")}</h2>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && (
        <>
          <InvoiceList list={data} linkTo={(id) => `/admin/invoices/${encodeURIComponent(id)}`} empty={t("invoicesNone")} onPage={setPage} />
          <h3>{t("billingDetailsTitle")}</h3>
          <BillingDetailsFacts details={data.billingDetails} />
        </>
      )}
    </section>
  );
}
