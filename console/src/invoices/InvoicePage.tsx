/**
 * Console → an invoice (`/console/invoices/:id` — exactly this path: the backend's payment-received email links here;
 * contract/kit-api.yaml getAccountInvoice): one of the account's invoices to print or save as a PDF, with a way back
 * to all of them. Every member of the account may open it.
 *
 * Not here: the page itself (InvoiceView.tsx, InvoiceSheet.tsx), the list (InvoicesPage.tsx), the route (console/ConsoleArea.tsx).
 */
import { Link, useParams } from "react-router-dom";
import { kitApi } from "../api";
import { t } from "../text";
import { InvoiceView } from "./InvoiceView";

export function InvoicePage() {
  const { id = "" } = useParams();
  return <InvoiceView id={id} load={kitApi.accountInvoice} back={() => <Link to="/console/invoices">{t("invoicesAll")}</Link>} />;
}
