/**
 * Admin → Platform billing (`/admin/platform-billing`, the page the backend's low-balance and stopped emails link to;
 * contract/kit-api.yaml getPlatformBilling; staff with the `money` ability): the operator's own account at the voice
 * platform — its state as last read, the low-balance level, and the platform's invoices to it. Saving the level
 * answers the state the platform gives at once, which replaces the state shown (and when it was checked); the invoices
 * stay as read. A failed read of the page is an alert; failed invoices alone leave the rest (PlatformInvoices.tsx).
 *
 * Not here: the parts (PlatformAccount.tsx, PlatformBalanceAlert.tsx, PlatformInvoices.tsx), their formats
 * (platformBillingFormat.ts), the nav entry (AdminArea.tsx).
 */
import { useEffect, useState } from "react";
import { kitApi, type PlatformBilling } from "../api";
import { t } from "../text";
import { PlatformAccount } from "./PlatformAccount";
import { PlatformBalanceAlert } from "./PlatformBalanceAlert";
import { PlatformInvoices } from "./PlatformInvoices";

export function PlatformBillingPage() {
  const [billing, setBilling] = useState<PlatformBilling | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    kitApi.platformBilling().then(setBilling, (err: unknown) => setFailure(err instanceof Error ? err.message : String(err)));
  }, []);

  return (
    <section aria-labelledby="platform-billing-title">
      <h1 id="platform-billing-title">{t("platformBillingTitle")}</h1>
      <p className="muted">{t("platformBillingHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!billing && !failure && <p aria-busy="true">{t("loading")}</p>}
      {billing && (
        <>
          <PlatformAccount state={billing.state} checkedAt={billing.checkedAt} />
          <PlatformBalanceAlert
            level={billing.state?.lowThresholdUsd}
            onSaved={(answer) => setBilling((b) => (b ? { ...b, state: answer.state, checkedAt: answer.checkedAt } : b))}
          />
          <PlatformInvoices invoices={billing.invoices} error={billing.invoicesError} />
        </>
      )}
    </section>
  );
}
