/**
 * Admin → Dashboard (`/admin/dashboard`, the admin's first page; contract/kit-api.yaml getAdminDashboard): over the days
 * picked (this month so far until changed), what customers were charged beside what the voice platform charged the
 * operator, top-ups and credits by hand, customers and calls; and now, the calls live, the operator's own account at
 * the voice platform (whether it may spend, its balance or this month's usage against the cap, a link to its Billing
 * page) and what needs a look, each with a link to where it is handled.
 *
 * Not here: the figures (the backend's App\Kit\Reports), the groups of charges (CostsPage.tsx).
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type AdminDashboard } from "../api";
import { t, type TextKey } from "../text";
import { money, rounded } from "../wallet/money";
import { PeriodPicker } from "./PeriodPicker";

/** Our words for the values the voice platform sends about the operator's account; a value with none shows as it is. */
const WORDS: Record<string, Record<string, TextKey>> = {
  mode: { prepaid: "modePrepaid", postpaid: "modePostpaid" },
  state: { ok: "spendStateOk", low: "spendStateLow", stopped: "spendStateStopped" },
  stoppedBy: { suspended: "stoppedBySuspended", out_of_credit: "stoppedByOutOfCredit", spend_cap: "stoppedBySpendCap" },
};

/** The voice platform's figures for the operator's account, in US dollars to the cent. */
const CENTS = 2;
const usd = (amount: string) => money(rounded(amount, CENTS), "USD");

function said(kind: keyof typeof WORDS, value: string | null | undefined): string | null {
  if (!value) return null;
  const key = WORDS[kind][value];
  return key ? t(key) : value;
}

export function DashboardPage() {
  const [data, setData] = useState<AdminDashboard | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const load = useCallback(async (from?: string, to?: string) => {
    setErrors({});
    try {
      setData(await kitApi.dashboard(from, to));
      setFailure(null);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="dashboard-title">
      <h1 id="dashboard-title">{t("dashboardTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && (
        <>
          <PeriodPicker period={data.period} errors={errors} onPick={(from, to) => void load(from, to)} />
          <Account telenow={data.telenow} />
          <Money data={data} />
          <h2>{t("dashboardCustomers")}</h2>
          <dl className="facts">
            <div><dt>{t("dashboardCustomersTotal")}</dt><dd>{data.customers.total}</dd></div>
            <div><dt>{t("dashboardCustomersActive")}</dt><dd>{data.customers.active}</dd></div>
            <div><dt>{t("dashboardCustomersSuspended")}</dt><dd>{data.customers.suspended}</dd></div>
            <div><dt>{t("dashboardCustomersNotReady")}</dt><dd>{data.customers.notReady}</dd></div>
            {data.customers.outOfCredit !== null && (
              <div><dt>{t("dashboardCustomersOutOfCredit")}</dt><dd>{data.customers.outOfCredit}</dd></div>
            )}
            <div><dt>{t("dashboardCustomersNew")}</dt><dd>{data.customers.new}</dd></div>
          </dl>
          <h2>{t("dashboardCalls")}</h2>
          <dl className="facts">
            <div><dt>{t("dashboardCallsCount")}</dt><dd>{data.calls.count}</dd></div>
            <div><dt>{t("dashboardCallsMinutes")}</dt><dd>{Math.round(data.calls.seconds / 60)}</dd></div>
            <div><dt>{t("dashboardCallsLive")}</dt><dd data-testid="live-calls">{data.calls.live}</dd></div>
          </dl>
          <Attention attention={data.attention} />
        </>
      )}
    </section>
  );
}

function Account({ telenow }: { telenow: AdminDashboard["telenow"] }) {
  const a = telenow.account;
  const name = telenow.name;
  return (
    <section aria-labelledby="account-title">
      <h2 id="account-title">{t("dashboardAccount", { name })}</h2>
      {!telenow.connected ? (
        <p className="muted">{t("dashboardNotConnected", { name })}</p>
      ) : (
        <>
          {telenow.keyRejected && <p role="alert" className="error">{t("dashboardKeyRejected", { name })}</p>}
          {a?.canSpend === false && (
            <p role="alert" className="error">
              {t("dashboardPaused", { reason: said("stoppedBy", a.stoppedBy) ?? "—" })}
            </p>
          )}
          {a && (
            <dl className="facts">
              <div><dt>{t("dashboardMode")}</dt><dd>{said("mode", a.mode) ?? "—"}</dd></div>
              <div><dt>{t("dashboardState")}</dt><dd data-testid="account-state">{said("state", a.state) ?? "—"}</dd></div>
              {a.mode === "prepaid" && a.balanceUsd != null && (
                <div><dt>{t("dashboardBalance")}</dt><dd>{usd(a.balanceUsd)}</dd></div>
              )}
              {a.periodSpendUsd != null && (
                <div>
                  <dt>{t("dashboardPeriodSpend")}</dt>
                  <dd>
                    {a.monthlySpendLimitUsd != null
                      ? t("dashboardOfCap", { spent: usd(a.periodSpendUsd), cap: usd(a.monthlySpendLimitUsd) })
                      : usd(a.periodSpendUsd)}
                  </dd>
                </div>
              )}
              {a.lowThresholdUsd != null && (
                <div><dt>{t("dashboardLowAt")}</dt><dd>{usd(a.lowThresholdUsd)}</dd></div>
              )}
            </dl>
          )}
          <p className="muted">
            {telenow.checkedAt ? t("dashboardCheckedAt", { when: new Date(telenow.checkedAt).toLocaleString() }) : t("dashboardNotChecked")}
            {telenow.error && <> · {telenow.error}</>}
          </p>
          <p>
            <a href={telenow.billingUrl} target="_blank" rel="noreferrer">{t("dashboardOpenBilling", { name })}</a>
          </p>
        </>
      )}
    </section>
  );
}

function Money({ data }: { data: AdminDashboard }) {
  return (
    <section aria-labelledby="money-title">
      <h2 id="money-title">{t("dashboardMoney")}</h2>
      {data.money.length === 0 && data.topUps.length === 0 && data.creditsByHand.length === 0 ? (
        <p className="muted">{t("dashboardNoMoney")}</p>
      ) : (
        <dl className="facts">
          {data.money.map((m) => (
            <div key={`charged-${m.currency}`} data-testid={`charged-${m.currency}`}>
              <dt>{t("dashboardCharged", { count: String(m.count) })}</dt>
              <dd>
                {money(m.price, m.currency)} · {t("dashboardCost")} {money(m.cost, m.currency)} · {t("dashboardMargin")}{" "}
                {money(m.margin, m.currency)}
                {m.marginPercent !== null && ` (${m.marginPercent}%)`}
              </dd>
            </div>
          ))}
          {data.topUps.map((p) => (
            <div key={`topups-${p.currency}`}>
              <dt>{t("dashboardTopUps", { count: String(p.count) })}</dt>
              <dd>
                {money(p.amount, p.currency)}
                {p.refunded !== "0" && <> · {t("dashboardRefunded")} {money(p.refunded, p.currency)}</>}
              </dd>
            </div>
          ))}
          {data.creditsByHand.map((c) => (
            <div key={`credits-${c.currency}`}>
              <dt>{t("dashboardCreditsByHand", { count: String(c.count) })}</dt>
              <dd>{money(c.amount, c.currency)}</dd>
            </div>
          ))}
        </dl>
      )}
      <p><Link to="/admin/costs">{t("dashboardSeeCosts")}</Link></p>
    </section>
  );
}

function Attention({ attention }: { attention: AdminDashboard["attention"] }) {
  const all: { count: number; text: TextKey; to: string }[] = [
    { count: attention.paymentsToReview, text: "attentionPayments", to: "/admin/payments" },
    { count: attention.customersNotSynced, text: "attentionCustomers", to: "/admin/customers" },
    { count: attention.callsNotCharged, text: "attentionCalls", to: "/admin/pricing" },
    { count: attention.failedWrites, text: "attentionWrites", to: "/admin/telenow" },
    { count: attention.eventsFailed, text: "attentionEvents", to: "/admin/telenow" },
    { count: attention.customersDrifting, text: "attentionDrifting", to: "/admin/telenow" },
  ];
  const items = all.filter((i) => i.count > 0);
  return (
    <section aria-labelledby="attention-title">
      <h2 id="attention-title">{t("attentionTitle")}</h2>
      {attention.jobsNotRunning && (
        <p role="alert" className="error"><Link to="/admin/telenow">{t("attentionJobs")}</Link></p>
      )}
      {items.length === 0 && !attention.jobsNotRunning ? (
        <p className="muted">{t("attentionNone")}</p>
      ) : (
        <ul>
          {items.map((i) => (
            <li key={i.text}>
              <Link to={i.to}>{t(i.text, { count: String(i.count) })}</Link>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
