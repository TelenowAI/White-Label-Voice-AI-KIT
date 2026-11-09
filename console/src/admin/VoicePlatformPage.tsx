/**
 * Admin → Voice platform (`/admin/telenow`; contract/kit-api.yaml getVoicePlatformHealth, retryVoicePlatformWrite,
 * retryVoicePlatformEvent, reconcileCustomer): how the link to the voice platform is doing, and what staff can do
 * about it.
 * - Scheduled jobs first, loudly, when they do not run: without the one cron line nothing is charged, synced or sent.
 * - The key: connected, rejected, last checked.
 * - The operator's account's processing country (ProcessingCountry.tsx): read on its own, so its failure never blocks
 *   the rest of the page.
 * - Call health (PlatformCallHealth.tsx): the providers that failed on customers' recent calls, at the last sweep.
 * - Events from the voice platform: the endpoint, the last one received, how many wait or failed; a failed one can be
 *   tried again.
 * - Changes sent to the voice platform: how many wait, the ones it refused, each with its reason and "Try again".
 * - The money check: customers whose spend at the voice platform and what they were charged differ by more than the
 *   tolerance, whose calls differ, or whose check failed; each can be checked now.
 * Every action answers the page as it stands after.
 *
 * Not here: the checks (the backend's App\Kit\Health).
 */
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type VoicePlatformHealth } from "../api";
import { t } from "../text";
import { money, rounded } from "../wallet/money";
import { PlatformCallHealth } from "./PlatformCallHealth";
import { PlatformProcessingCountry } from "./ProcessingCountry";

/** Dollar figures from the voice platform, to the cent. */
const CENTS = 2;
const usd = (amount: string | null) => (amount === null ? "—" : money(rounded(amount, CENTS), "USD"));
const when = (at: string | null) => (at ? new Date(at).toLocaleString() : "—");

export function VoicePlatformPage() {
  const [health, setHealth] = useState<VoicePlatformHealth | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    kitApi.voicePlatform().then(setHealth, (err) => setFailure(err instanceof Error ? err.message : String(err)));
  }, []);

  async function act(key: string, run: () => Promise<VoicePlatformHealth>) {
    setBusy(key);
    setFailure(null);
    try {
      setHealth(await run());
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(null);
    }
  }

  return (
    <section aria-labelledby="platform-title">
      <h1 id="platform-title">{t("platformTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {health && (
        <>
          <Jobs jobs={health.jobs} />
          <section aria-labelledby="platform-key">
            <h2 id="platform-key">{t("platformKey", { name: health.telenow.name })}</h2>
            {!health.telenow.connected ? (
              <p className="muted">{t("dashboardNotConnected", { name: health.telenow.name })}</p>
            ) : (
              <>
                {health.telenow.keyRejected && <p role="alert" className="error">{t("dashboardKeyRejected", { name: health.telenow.name })}</p>}
                <dl className="facts">
                  {health.telenow.account && <div><dt>{t("platformAccount")}</dt><dd>{health.telenow.account}</dd></div>}
                  <div><dt>{t("platformCheckedAt")}</dt><dd>{when(health.telenow.checkedAt)}</dd></div>
                  {health.telenow.error && <div><dt>{t("platformError")}</dt><dd className="wrap-anywhere">{health.telenow.error}</dd></div>}
                </dl>
              </>
            )}
          </section>

          <PlatformProcessingCountry />

          <PlatformCallHealth summary={health.callHealth} />

          <section aria-labelledby="platform-events">
            <h2 id="platform-events">{t("platformEvents")}</h2>
            {!health.events.registered && <p role="alert" className="error">{t("platformNoEndpoint")}</p>}
            {health.events.error && <p className="error wrap-anywhere">{health.events.error}</p>}
            <dl className="facts">
              {health.events.url && <div><dt>{t("platformEndpoint")}</dt><dd className="wrap-anywhere">{health.events.url}</dd></div>}
              <div><dt>{t("platformLastEvent")}</dt><dd>{when(health.events.lastReceivedAt)}</dd></div>
              <div><dt>{t("platformRecent", { hours: String(health.events.recentHours) })}</dt><dd>{health.events.recent}</dd></div>
              <div><dt>{t("platformWaiting")}</dt><dd>{health.events.waiting}</dd></div>
              <div><dt>{t("platformFailed")}</dt><dd data-testid="events-failed">{health.events.failed}</dd></div>
            </dl>
            {health.events.failures.length > 0 && (
              <ul className="senders">
                {health.events.failures.map((e) => (
                  <li key={e.id} data-event={e.id}>
                    <div className="actions">
                      <span><strong>{e.event}</strong> · {when(e.failedAt)} · {t("platformAttempts", { n: String(e.attempts) })}</span>
                      <button type="button" className="secondary" disabled={busy !== null}
                        onClick={() => void act(`event-${e.id}`, () => kitApi.retryVoicePlatformEvent(e.id))}>
                        {busy === `event-${e.id}` ? t("saving") : t("tryAgain")}
                      </button>
                    </div>
                    {e.error && <small className="error wrap-anywhere">{e.error}</small>}
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section aria-labelledby="platform-writes">
            <h2 id="platform-writes">{t("platformWrites")}</h2>
            <dl className="facts">
              <div><dt>{t("platformWaiting")}</dt><dd>{health.writes.waiting}</dd></div>
              <div><dt>{t("platformRefused")}</dt><dd data-testid="writes-failed">{health.writes.failed}</dd></div>
            </dl>
            {health.writes.failures.length > 0 && (
              <ul className="senders">
                {health.writes.failures.map((w) => (
                  <li key={w.id} data-write={w.id}>
                    <div className="actions">
                      <span><strong>{w.resource} · {w.operation}</strong> · {when(w.failedAt)}</span>
                      <button type="button" className="secondary" disabled={busy !== null}
                        onClick={() => void act(`write-${w.id}`, () => kitApi.retryVoicePlatformWrite(w.id))}>
                        {busy === `write-${w.id}` ? t("saving") : t("tryAgain")}
                      </button>
                    </div>
                    {w.error && <small className="error wrap-anywhere">{w.error}</small>}
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section aria-labelledby="platform-money">
            <h2 id="platform-money">{t("platformMoney")}</h2>
            <p className="muted">
              {t("platformMoneyHelp", { minutes: String(health.reconciliation.everyMinutes), tolerance: usd(health.reconciliation.toleranceUsd) })}
            </p>
            <dl className="facts">
              <div><dt>{t("platformChecked")}</dt><dd>{health.reconciliation.checked}</dd></div>
              <div><dt>{t("platformOldestCheck")}</dt><dd>{when(health.reconciliation.oldestCheckAt)}</dd></div>
              <div><dt>{t("platformDrifting")}</dt><dd data-testid="drifting">{health.reconciliation.drifting}</dd></div>
            </dl>
            {health.reconciliation.customers.length > 0 && (
              <div className="table-scroll">
                <table className="list" aria-label={t("platformMoney")}>
                  <thead>
                    <tr>
                      <th scope="col">{t("colCustomer")}</th>
                      <th scope="col">{t("platformSpend")}</th>
                      <th scope="col">{t("platformCharged")}</th>
                      <th scope="col">{t("platformGap")}</th>
                      <th scope="col">{t("platformCalls")}</th>
                      <th scope="col">{t("platformUncharged")}</th>
                      <th scope="col"><span className="visually-hidden">{t("platformCheckNow")}</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    {health.reconciliation.customers.map((c) => (
                      <tr key={c.customerId} data-customer={c.customerId}>
                        <th scope="row">
                          <Link to={`/admin/customers/${encodeURIComponent(c.customerId)}`}>{c.name}</Link>
                          {c.error && <small className="error wrap-anywhere"><br />{c.error}</small>}
                        </th>
                        <td>{usd(c.telenowSpendUsd)}</td>
                        <td>{usd(c.chargedCostUsd)}</td>
                        <td>{usd(c.driftUsd)}</td>
                        <td>{c.telenowPeriodCalls ?? "—"} / {c.kitPeriodCalls ?? "—"}</td>
                        <td>{c.unchargedCalls ?? "—"}</td>
                        <td>
                          <button type="button" className="secondary" disabled={busy !== null}
                            onClick={() => void act(`check-${c.customerId}`, () => kitApi.reconcileCustomer(c.customerId))}>
                            {busy === `check-${c.customerId}` ? t("saving") : t("platformCheckNow")}
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>
        </>
      )}
    </section>
  );
}

function Jobs({ jobs }: { jobs: VoicePlatformHealth["jobs"] }) {
  return (
    <section aria-labelledby="platform-jobs">
      <h2 id="platform-jobs">{t("platformJobs")}</h2>
      {!jobs.running && (
        <div role="alert" className="error">
          <p>{jobs.lastStartedAt ? t("platformJobsStopped", { when: when(jobs.lastStartedAt) }) : t("platformJobsNever")}</p>
          <p>{t("platformCronHelp")}</p>
          <code className="wrap-anywhere">* * * * * php artisan schedule:run</code>
        </div>
      )}
      {jobs.jobs.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("platformJobs")}>
            <thead>
              <tr>
                <th scope="col">{t("platformJob")}</th>
                <th scope="col">{t("platformLastStarted")}</th>
                <th scope="col">{t("platformResult")}</th>
              </tr>
            </thead>
            <tbody>
              {jobs.jobs.map((j) => (
                <tr key={j.command} data-job={j.command}>
                  <th scope="row"><code>{j.command}</code>{j.late && <> <span className="badge error">{t("platformLate")}</span></>}</th>
                  <td>{when(j.startedAt)}</td>
                  <td>{j.succeeded === null ? "—" : t(j.succeeded ? "platformOk" : "platformJobFailed")}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}
