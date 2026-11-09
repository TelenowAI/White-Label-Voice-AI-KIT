/**
 * Call health (contract/kit-api.yaml getCallHealth, getCustomerCallHealth): the providers that failed on an account's recent
 * calls, over the last hour, 6 hours or day: each with its part (speech recognition, voice, language model) and cause in plain
 * words (the provider had a problem; the agent's own setup; the voice platform; not known), the calls it failed on, when last,
 * and the agents whose calls they were. "No failures in the last hour" when there are none. A read that fails (409: no account
 * on the voice platform yet; 502: cannot be read now) is said quietly in the panel, and the rest of the page stays.
 *
 * - CallHealthPanel: Console → Home (the account's own), and an agent's page (`agentId`: only the failures that name it).
 *   Agent names link to their pages. Customers never see a provider's raw error, a platform id or a price: the backend sends none.
 * - CustomerCallHealth: Admin → a customer's page, with the staff fields too: the platform's provider id, its own word for the
 *   cause, the key used, the failures counted and the provider's last error.
 * - CALL_HEALTH_PART: the parts in words, also used by Admin → Voice platform (admin/PlatformCallHealth.tsx).
 *
 * Used by console/ConsoleArea.tsx, agents/AgentPage.tsx and admin/CustomerPage.tsx. Not here: every customer's added up
 * (admin/PlatformCallHealth.tsx); reading it from the voice platform (the backend's App\Kit\Calls\CallHealth).
 */
import { useCallback, useEffect, useId, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type CallHealth, type CallHealthFailure, type CallHealthPart } from "../api";
import { t, type TextKey } from "../text";

export const CALL_HEALTH_PART: Record<CallHealthPart, TextKey> = {
  stt: "callHealthPart_stt",
  tts: "callHealthPart_tts",
  llm: "callHealthPart_llm",
  other: "callHealthPart_other",
};

const CAUSE: Record<CallHealthFailure["cause"], TextKey> = {
  provider: "callHealthCause_provider",
  setup: "callHealthCause_setup",
  platform: "callHealthCause_platform",
  unknown: "callHealthCause_unknown",
};

/** The windows offered, the first the one shown first; each with how it is offered and how "none" is said for it. */
const WINDOWS: { minutes: number; label: TextKey; none: TextKey }[] = [
  { minutes: 60, label: "callHealthWindow60", none: "callHealthNone60" },
  { minutes: 360, label: "callHealthWindow360", none: "callHealthNone360" },
  { minutes: 1440, label: "callHealthWindow1440", none: "callHealthNone1440" },
];

const when = (at: string | null) => (at ? new Date(at).toLocaleString() : "—");

/** The account's own call health (Console → Home), or only the failures that name one agent (its page). */
export function CallHealthPanel({ agentId }: { agentId?: string }) {
  return <Panel load={kitApi.callHealth} agentId={agentId} help={agentId ? "callHealthAgentHelp" : "callHealthHelp"} />;
}

/** A customer's call health in detail (Admin → the customer's page). */
export function CustomerCallHealth({ customerId }: { customerId: string }) {
  const load = useCallback((minutes: number) => kitApi.customerCallHealth(customerId, minutes), [customerId]);
  return <Panel load={load} staff help="callHealthCustomerHelp" />;
}

function Panel({ load, agentId, staff = false, help }: {
  load: (minutes: number) => Promise<CallHealth>;
  agentId?: string;
  staff?: boolean;
  help: TextKey;
}) {
  const ids = useId();
  const [minutes, setMinutes] = useState(WINDOWS[0].minutes);
  const [read, setRead] = useState<CallHealth | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    let current = true;
    load(minutes).then(
      (health) => {
        if (!current) return;
        setRead(health);
        setFailure(null);
      },
      (err: unknown) => {
        if (!current) return;
        setRead(null);
        setFailure(err instanceof Error ? err.message : String(err));
      },
    );
    return () => {
      current = false;
    };
  }, [load, minutes]);

  const failures = read ? read.failures.filter((f) => !agentId || f.agents.some((a) => a.id === agentId)) : [];
  const shown = read ? WINDOWS.find((w) => w.minutes === read.windowMinutes) : undefined;

  return (
    <section aria-labelledby={`${ids}-title`}>
      <h2 id={`${ids}-title`}>{t("callHealthTitle")}</h2>
      <p className="muted">{t(help)}</p>
      <div className="field field-narrow">
        <label htmlFor={`${ids}-window`}>{t("callHealthWindow")}</label>
        <select id={`${ids}-window`} value={minutes} onChange={(e) => setMinutes(Number(e.target.value))}>
          {WINDOWS.map((w) => <option key={w.minutes} value={w.minutes}>{t(w.label)}</option>)}
        </select>
      </div>
      {failure && <p className="muted" data-testid="call-health-failure">{failure}</p>}
      {read && (
        <>
          <p className="muted">
            {!agentId && <>{t("callHealthAffected", { count: String(read.callsAffected) })} · </>}
            {t("callHealthCheckedAt", { when: when(read.checkedAt) })}
          </p>
          {failures.length === 0 ? (
            <p className="muted" data-testid="call-health-none">
              {shown ? t(shown.none) : t("callHealthNoneMinutes", { minutes: String(read.windowMinutes) })}
            </p>
          ) : (
            <div className="table-scroll">
              <table className="list" aria-label={t("callHealthTitle")}>
                <thead>
                  <tr>
                    <th scope="col">{t("callHealthPart")}</th>
                    <th scope="col">{t("callHealthProvider")}</th>
                    <th scope="col">{t("callHealthCause")}</th>
                    <th scope="col">{t("callHealthCalls")}</th>
                    <th scope="col">{t("callHealthLast")}</th>
                    <th scope="col">{t("callHealthAgents")}</th>
                    {staff && (
                      <>
                        <th scope="col">{t("callHealthProviderId")}</th>
                        <th scope="col">{t("callHealthAttribution")}</th>
                        <th scope="col">{t("callHealthKeySource")}</th>
                        <th scope="col">{t("callHealthIncidents")}</th>
                      </>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {failures.map((f, i) => (
                    <tr key={`${f.part}-${f.provider}-${i}`} data-failure={`${f.part}:${f.provider}`}>
                      <td>{t(CALL_HEALTH_PART[f.part] ?? "callHealthPart_other")}</td>
                      <th scope="row">
                        {f.provider}
                        {staff && f.error && <small className="error wrap-anywhere" data-testid="call-health-error"><br />{t("callHealthError")}: {f.error}</small>}
                      </th>
                      <td>{t(CAUSE[f.cause] ?? "callHealthCause_unknown")}</td>
                      <td>{f.calls}</td>
                      <td>{when(f.lastAt)}</td>
                      <td>
                        {f.agents.map((a, j) => (
                          <span key={a.id}>
                            {j > 0 && ", "}
                            {staff ? a.name : <Link to={`/console/agents/${encodeURIComponent(a.id)}`}>{a.name}</Link>}
                          </span>
                        ))}
                      </td>
                      {staff && (
                        <>
                          <td className="wrap-anywhere">{f.providerId ?? "—"}</td>
                          <td>{f.attribution ?? "—"}</td>
                          <td>{f.keySource ?? "—"}</td>
                          <td>{f.incidents ?? "—"}</td>
                        </>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  );
}
