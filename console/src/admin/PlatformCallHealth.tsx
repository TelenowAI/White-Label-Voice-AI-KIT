/**
 * Admin → Voice platform → call health (contract/kit-api.yaml getVoicePlatformHealth `callHealth`): every customer's failed
 * providers added up by the last sweep (`kit:call-health`): when it ran, over how many minutes, how many accounts had calls,
 * were read and could not be read, and each provider that failed — its part, the name customers know it by with the platform's
 * id, the calls it failed on (flagged at or over the alert level, which also puts it on the dashboard), when last, and the
 * customers it failed for, the most first, each linking to its page. Before the first sweep: that it has not run yet.
 *
 * Used by VoicePlatformPage.tsx. Not here: one customer's in detail (calls/CallHealthPanel.tsx CustomerCallHealth); the sweep
 * (the backend's App\Kit\Calls\CallHealth).
 */
import { Link } from "react-router-dom";
import type { PlatformCallHealth as Summary } from "../api";
import { CALL_HEALTH_PART } from "../calls/CallHealthPanel";
import { t } from "../text";

const when = (at: string | null) => (at ? new Date(at).toLocaleString() : "—");

export function PlatformCallHealth({ summary }: { summary: Summary | null }) {
  return (
    <section aria-labelledby="platform-call-health">
      <h2 id="platform-call-health">{t("platformCallHealth")}</h2>
      {summary === null ? (
        <p className="muted" data-testid="call-health-not-run">{t("platformCallHealthNotRun")}</p>
      ) : (
        <>
          <p className="muted">{t("platformCallHealthHelp", { minutes: String(summary.windowMinutes), alert: String(summary.alertCalls) })}</p>
          <dl className="facts">
            <div><dt>{t("platformCallHealthCheckedAt")}</dt><dd data-testid="call-health-checked">{when(summary.checkedAt)}</dd></div>
            <div><dt>{t("platformCallHealthActive")}</dt><dd data-testid="call-health-active">{summary.accountsActive}</dd></div>
            <div><dt>{t("platformCallHealthChecked")}</dt><dd data-testid="call-health-read">{summary.accountsChecked}</dd></div>
            <div><dt>{t("platformCallHealthUnread")}</dt><dd data-testid="call-health-unread">{summary.accountsUnread}</dd></div>
          </dl>
          {summary.providers.length === 0 ? (
            <p className="muted">{t("platformCallHealthNone")}</p>
          ) : (
            <div className="table-scroll">
              <table className="list" aria-label={t("platformCallHealth")}>
                <thead>
                  <tr>
                    <th scope="col">{t("callHealthPart")}</th>
                    <th scope="col">{t("callHealthProvider")}</th>
                    <th scope="col">{t("callHealthCalls")}</th>
                    <th scope="col">{t("callHealthLast")}</th>
                    <th scope="col">{t("platformCallHealthCustomers")}</th>
                  </tr>
                </thead>
                <tbody>
                  {summary.providers.map((p) => {
                    const flagged = p.calls >= summary.alertCalls;
                    return (
                      <tr key={`${p.part}-${p.providerId}`} data-provider={p.providerId} data-flagged={flagged ? "" : undefined}>
                        <td>{t(CALL_HEALTH_PART[p.part] ?? "callHealthPart_other")}</td>
                        <th scope="row">
                          {p.provider}
                          <small className="muted wrap-anywhere"><br />{p.providerId}</small>
                        </th>
                        <td>
                          {p.calls}
                          {flagged && <> <span className="badge error">{t("platformCallHealthFlag")}</span></>}
                        </td>
                        <td>{when(p.lastAt)}</td>
                        <td>
                          {p.customers.map((c, i) => (
                            <span key={c.id}>
                              {i > 0 && ", "}
                              <Link to={`/admin/customers/${encodeURIComponent(c.id)}`}>{t("platformCallHealthCustomerCalls", { name: c.name, calls: String(c.calls) })}</Link>
                            </span>
                          ))}
                          {p.customerCount > p.customers.length && (
                            <small className="muted"><br />{t("platformCallHealthMoreCustomers", { count: String(p.customerCount) })}</small>
                          )}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  );
}
