/**
 * An account's billing details, read only (contract/kit-api.yaml BillingDetails): its business name, address (its
 * line breaks kept) and tax number, "Not set" for each it has none of.
 *
 * Used by InvoicesPage.tsx (members who may not change them) and admin/CustomerInvoices.tsx. Not here: changing them
 * (BillingDetailsForm.tsx).
 */
import type { BillingDetails } from "../api";
import { t } from "../text";
import { AddressLines } from "./AddressLines";

export function BillingDetailsFacts({ details }: { details: BillingDetails }) {
  const notSet = <span className="muted">{t("billingDetailsNotSet")}</span>;
  return (
    <dl className="facts" data-testid="billing-details">
      <div><dt>{t("billingDetailsName")}</dt><dd>{details.name ?? notSet}</dd></div>
      <div><dt>{t("billingDetailsAddress")}</dt><dd>{details.address ? <AddressLines address={details.address} /> : notSet}</dd></div>
      <div><dt>{t("billingDetailsTaxNumber")}</dt><dd>{details.taxNumber ?? notSet}</dd></div>
    </dl>
  );
}
