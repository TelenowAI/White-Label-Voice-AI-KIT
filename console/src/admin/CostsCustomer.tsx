/**
 * Admin → Cost vs price → one customer's figures (contract/kit-api.yaml getCostReport `customer`): on a customer's row,
 * a button to see that customer's figures by what was charged; while the report is of one customer, their name as the
 * backend answered it, a link to their page (where their wallet is), and a way back to every customer.
 *
 * Used by CostsPage.tsx, which asks for the report each of these picks. Not here: the report and its rows (CostsPage.tsx).
 */
import { Link } from "react-router-dom";
import type { CostReport } from "../api";
import { t } from "../text";

export function CustomerParts({ name, onPick }: { name: string; onPick: () => void }) {
  return (
    <button type="button" className="link" aria-label={t("costsCustomerPartsOf", { name })} onClick={onPick}>
      {t("costsCustomerParts")}
    </button>
  );
}

export function PickedCustomer({ customer, onEvery }: { customer: NonNullable<CostReport["customer"]>; onEvery: () => void }) {
  return (
    <div className="actions" data-testid="costs-customer">
      <p>{t("costsShowingCustomer", { name: customer.name })}</p>
      <Link to={`/admin/customers/${encodeURIComponent(customer.id)}`}>{t("costsCustomerWallet")}</Link>
      <button type="button" className="secondary" onClick={onEvery}>{t("costsEveryCustomer")}</button>
    </div>
  );
}
