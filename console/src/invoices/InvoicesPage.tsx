/**
 * Console → Invoices (`/console/invoices`, linked from the wallet; contract/kit-api.yaml listAccountInvoices): the
 * account's invoices, one per payment added to its wallet, newest first, a page at a time (the page asked for only
 * past the first), each linked to its printable page; and its billing details, printed on its invoices as the
 * customer — every member sees them, the account's owners and admins (`canEdit`) change them here, for the invoices
 * issued from then on. Turning a page keeps the details (and anything typed in them) as they are. A failed read is an alert.
 *
 * Not here: one invoice (InvoicePage.tsx), the list's rows (InvoiceList.tsx), the details' form and read-only view
 * (BillingDetailsForm.tsx, BillingDetailsFacts.tsx), the route (console/ConsoleArea.tsx).
 */
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type AccountInvoices } from "../api";
import { t } from "../text";
import { BillingDetailsFacts } from "./BillingDetailsFacts";
import { BillingDetailsForm } from "./BillingDetailsForm";
import { InvoiceList } from "./InvoiceList";

export function InvoicesPage() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<AccountInvoices | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    kitApi.accountInvoices(page).then(
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
  }, [page]);

  return (
    <section aria-labelledby="invoices-title">
      <p>
        <Link to="/console/wallet">{t("invoicesBackToWallet")}</Link>
      </p>
      <h1 id="invoices-title">{t("invoicesTitle")}</h1>
      <p className="muted">{t("invoicesHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!data && !failure && <p aria-busy="true">{t("loading")}</p>}
      {data && (
        <>
          <InvoiceList list={data} linkTo={(id) => `/console/invoices/${encodeURIComponent(id)}`} empty={t("invoicesEmpty")} onPage={setPage} />
          <section aria-labelledby="billing-details-title">
            <h2 id="billing-details-title">{t("billingDetailsTitle")}</h2>
            <p className="muted">{t("billingDetailsHelp")}</p>
            {data.canEdit ? (
              <BillingDetailsForm details={data.billingDetails} onSaved={(saved) => setData((d) => (d ? { ...d, billingDetails: saved, canEdit: saved.canEdit } : d))} />
            ) : (
              <>
                <BillingDetailsFacts details={data.billingDetails} />
                <p className="muted">{t("billingDetailsManagersOnly")}</p>
              </>
            )}
          </section>
        </>
      )}
    </section>
  );
}
