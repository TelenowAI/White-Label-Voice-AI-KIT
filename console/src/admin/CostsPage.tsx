/**
 * Admin → Cost vs price (`/admin/costs`; contract/kit-api.yaml getCostReport): over the days picked, what customers
 * were charged beside what the voice platform charged the operator, and the margin, grouped as the operator picks
 * (the backend's `groups`: by customer, by what was charged, by plan, by day), a page at a time, with the totals of
 * the whole period. A customer's row links to their wallet, where each call is listed, and offers that customer's
 * figures by what was charged; while one customer is picked, the period, the grouping and the pages keep to them until
 * the way back to every customer (CostsCustomer.tsx).
 *
 * Not here: the figures (the backend's App\Kit\Reports\CostReport).
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type CostReport, type MoneyTotals } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";
import { CustomerParts, PickedCustomer } from "./CostsCustomer";
import { PeriodPicker } from "./PeriodPicker";

function Cells({ m }: { m: MoneyTotals }) {
  return (
    <>
      <td>{m.count}</td>
      <td>{money(m.price, m.currency)}</td>
      <td title={money(m.costUsd, "USD")}>{money(m.cost, m.currency)}</td>
      <td>{money(m.margin, m.currency)}</td>
      <td>{m.marginPercent === null ? "—" : `${m.marginPercent}%`}</td>
    </>
  );
}

export function CostsPage() {
  const [report, setReport] = useState<CostReport | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const load = useCallback(async (by: string, from?: string, to?: string, page = 1, customer?: string) => {
    setErrors({});
    try {
      setReport(await kitApi.costReport(by, from, to, page, customer));
      setFailure(null);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load("");
  }, [load]);

  const pages = report ? Math.max(1, Math.ceil(report.total / report.perPage)) : 1;
  const groupLabel = report?.groups.find((g) => g.value === report.by)?.label ?? "";
  const picked = report?.customer?.id;

  return (
    <section aria-labelledby="costs-title">
      <h1 id="costs-title">{t("costsTitle")}</h1>
      <p className="muted">{t("costsHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {report && (
        <>
          {report.customer && (
            <PickedCustomer customer={report.customer} onEvery={() => void load("customer", report.period.from, report.period.to)} />
          )}
          <PeriodPicker period={report.period} errors={errors} onPick={(from, to) => void load(report.by, from, to, 1, picked)} />
          <div className="field">
            <label htmlFor="costs-by">{t("costsBy")}</label>
            <select id="costs-by" value={report.by} onChange={(e) => void load(e.target.value, report.period.from, report.period.to, 1, picked)}>
              {report.groups.map((g) => <option key={g.value} value={g.value}>{g.label}</option>)}
            </select>
            {errors.by && <small className="error">{errors.by.join(" ")}</small>}
          </div>
          {report.rows.length === 0 ? (
            <p className="muted">{t("costsEmpty")}</p>
          ) : (
            <div className="table-scroll">
              <table className="list" aria-label={t("costsTitle")}>
                <thead>
                  <tr>
                    <th scope="col">{groupLabel}</th>
                    <th scope="col">{t(report.by === "component" ? "costsLines" : "costsCharges")}</th>
                    <th scope="col">{t("costsPrice")}</th>
                    <th scope="col">{t("colVoiceCost")}</th>
                    <th scope="col">{t("colMargin")}</th>
                    <th scope="col">{t("costsMarginPercent")}</th>
                  </tr>
                </thead>
                <tbody>
                  {report.rows.map((r) => (
                    <tr key={`${r.key}-${r.currency}`} data-row={r.key}>
                      <th scope="row">
                        {report.by === "customer" ? (
                          <>
                            <Link to={`/admin/customers/${encodeURIComponent(r.key)}`}>{r.label}</Link>{" "}
                            <CustomerParts name={r.label} onPick={() => void load("component", report.period.from, report.period.to, 1, r.key)} />
                          </>
                        ) : r.label}
                      </th>
                      <Cells m={r} />
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  {report.totals.map((m) => (
                    <tr key={m.currency} data-row="total">
                      <th scope="row">{t("costsTotal")}</th>
                      <Cells m={m} />
                    </tr>
                  ))}
                </tfoot>
              </table>
            </div>
          )}
          {pages > 1 && (
            <div className="actions">
              <button type="button" className="secondary" disabled={report.page <= 1}
                onClick={() => void load(report.by, report.period.from, report.period.to, report.page - 1, picked)}>
                {t("previousPage")}
              </button>
              <span>{t("pageOf", { page: String(report.page), pages: String(pages) })}</span>
              <button type="button" className="secondary" disabled={report.page >= pages}
                onClick={() => void load(report.by, report.period.from, report.period.to, report.page + 1, picked)}>
                {t("nextPage")}
              </button>
            </div>
          )}
        </>
      )}
    </section>
  );
}
