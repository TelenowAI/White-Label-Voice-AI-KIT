/**
 * Admin → a customer's invoice (`/admin/invoices/:id`; contract/kit-api.yaml getCustomerInvoice; staff with
 * `customers_read`): the same printable invoice the customer sees, with a way back to the customer it is for (to all
 * customers when it could not be read).
 *
 * Not here: the page itself (invoices/InvoiceView.tsx, invoices/InvoiceSheet.tsx), the list on the customer's page
 * (CustomerInvoices.tsx), the route (AdminArea.tsx).
 */
import { Link, useParams } from "react-router-dom";
import { kitApi } from "../api";
import { InvoiceView } from "../invoices/InvoiceView";
import { t } from "../text";

export function CustomerInvoicePage() {
  const { id = "" } = useParams();
  return (
    <InvoiceView
      id={id}
      load={kitApi.customerInvoice}
      back={(invoice) =>
        invoice ? (
          <Link to={`/admin/customers/${encodeURIComponent(invoice.customerId)}`}>{t("invoiceBackToCustomer")}</Link>
        ) : (
          <Link to="/admin/customers">{t("backToCustomers")}</Link>
        )
      }
    />
  );
}
