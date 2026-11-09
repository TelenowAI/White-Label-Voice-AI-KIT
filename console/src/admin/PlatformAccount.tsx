/**
 * Admin → Platform billing → the operator's own account at the voice platform, as last read (contract/kit-api.yaml
 * PlatformBillingState): whether it may spend (running, running low, or stopped, with why, loudly), prepaid or
 * postpaid, its balance, what it can still spend (a postpaid account with no cap has no limit), this period's spend
 * and the monthly cap, and when it was last checked. Before the first read it says so. A value the platform sends
 * that we have no words for shows as it is.
 *
 * Used by PlatformBillingPage.tsx. Not here: the low-balance level (PlatformBalanceAlert.tsx), the invoices
 * (PlatformInvoices.tsx), reading the state (the backend's `kit:telenow-status`).
 */
import type { PlatformBillingState } from "../api";
import { t, type TextKey } from "../text";
import { NONE, dayOf, moment, usd } from "./platformBillingFormat";

/** Our words for the values the voice platform sends about the account. */
const WORDS: Record<"state" | "mode" | "stoppedBy", Record<string, TextKey>> = {
  state: { ok: "spendStateOk", low: "spendStateLow", stopped: "spendStateStopped" },
  mode: { prepaid: "modePrepaid", postpaid: "modePostpaid" },
  stoppedBy: { out_of_credit: "platformBillingStoppedOutOfCredit", spend_cap: "platformBillingStoppedSpendCap", suspended: "platformBillingStoppedSuspended" },
};

function said(kind: keyof typeof WORDS, value: string | null | undefined): string | null {
  if (!value) return null;
  const key = WORDS[kind][value];
  return key ? t(key) : value;
}

export function PlatformAccount({ state, checkedAt }: PlatformBillingState) {
  return (
    <section aria-labelledby="platform-billing-account">
      <h2 id="platform-billing-account">{t("platformBillingAccount")}</h2>
      {state === null ? (
        <p className="muted" data-testid="platform-state">{t("platformBillingNotRead")}</p>
      ) : (
        <>
          {state.state === "stopped" && (
            <p role="alert" className="error">
              {t("platformBillingStopped", { reason: said("stoppedBy", state.stoppedBy) ?? t("platformBillingStoppedNoReason") })}
            </p>
          )}
          {state.state === "low" && <p className="error">{t("platformBillingLow")}</p>}
          <dl className="facts">
            <div><dt>{t("platformBillingState")}</dt><dd data-testid="platform-state">{said("state", state.state) ?? NONE}</dd></div>
            <div><dt>{t("platformBillingMode")}</dt><dd>{said("mode", state.mode) ?? NONE}</dd></div>
            <div><dt>{t("platformBillingBalance")}</dt><dd data-testid="platform-balance">{usd(state.balanceUsd)}</dd></div>
            <div>
              <dt>{t("platformBillingHeadroom")}</dt>
              <dd data-testid="platform-headroom">{state.headroomUsd === null ? t("platformBillingNoLimit") : usd(state.headroomUsd)}</dd>
            </div>
            <div>
              <dt>{t("platformBillingPeriodSpend")}</dt>
              <dd data-testid="platform-spend">
                {usd(state.periodSpendUsd)}
                {state.periodStart && <small className="muted"> {t("platformBillingSince", { when: dayOf(state.periodStart) })}</small>}
              </dd>
            </div>
            <div>
              <dt>{t("platformBillingCap")}</dt>
              <dd data-testid="platform-cap">{state.monthlySpendLimitUsd == null ? t("platformBillingNoCap") : usd(state.monthlySpendLimitUsd)}</dd>
            </div>
          </dl>
        </>
      )}
      {checkedAt && <p className="muted" data-testid="platform-checked">{t("platformBillingCheckedAt", { when: moment(checkedAt) })}</p>}
    </section>
  );
}
