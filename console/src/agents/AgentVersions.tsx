/**
 * Console → one agent → its versions (contract/kit-api.yaml listAgentVersions, getAgentVersionChanges, getAgentVersionResults, restoreAgentVersion): what each save changed, how calls went while it was in
 * use, and putting an earlier one back after asking. Each is read only when asked for, since each is a read of the voice platform.
 *
 * Not here: what may come back and what is taken off (App\Kit\Agents\AgentVersions), the forms that show the agent again afterwards (AgentPage.tsx).
 */
import { useState } from "react";
import { kitApi, type Agent, type AgentVersion, type AgentVersionChange, type AgentVersionWindow } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function kindText(v: AgentVersion): string {
  if (v.kind === "restored" && v.restoredFrom !== null) return t("versionRestored", { version: String(v.restoredFrom) });
  return t(v.kind === "created" ? "versionCreated" : "versionChanged");
}

function changeText(c: AgentVersionChange): string {
  if (c.from !== null && c.to !== null) return t("versionChangeBoth", { from: c.from, to: c.to });
  if (c.to !== null) return c.to;
  if (c.from !== null) return t("versionChangeWas", { from: c.from });
  return t("versionChanged");
}

function seconds(value: number): string {
  const whole = Math.round(value);
  return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, "0")}`;
}

function Results({ title, window }: { title: string; window: AgentVersionWindow }) {
  return (
    <div>
      <h4>{title}</h4>
      {window.calls === 0 ? <p className="muted">{t("versionNoCalls")}</p> : (
        <dl>
          <dt>{t("versionCalls")}</dt>
          <dd>{window.calls}</dd>
          {window.answeredRate !== null && (
            <>
              <dt>{t("versionAnswered")}</dt>
              <dd>{Math.round(window.answeredRate * 100)}%</dd>
            </>
          )}
          {window.avgDurationSec !== null && (
            <>
              <dt>{t("versionLength")}</dt>
              <dd>{seconds(window.avgDurationSec)}</dd>
            </>
          )}
          {window.avgScore !== null && (
            <>
              <dt>{t("versionScore")}</dt>
              <dd>{Math.round(window.avgScore)}</dd>
            </>
          )}
          <dt>{t("versionMood")}</dt>
          <dd>{t("versionMoodValue", { positive: String(window.sentiment.positive), neutral: String(window.sentiment.neutral), negative: String(window.sentiment.negative) })}</dd>
        </dl>
      )}
    </div>
  );
}

export function AgentVersions({ agent, onRestored }: { agent: Agent; onRestored: (agent: Agent) => void }) {
  const [versions, setVersions] = useState<AgentVersion[] | null>(null);
  const [kept, setKept] = useState<string[]>([]);
  const [changes, setChanges] = useState<Record<number, AgentVersionChange[]>>({});
  const [results, setResults] = useState<Record<number, { current: AgentVersionWindow; previous: AgentVersionWindow | null }>>({});
  const [asking, setAsking] = useState<number | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const act = async (work: () => Promise<void>) => {
    setBusy(true);
    setFailure(null);
    try {
      await work();
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  };
  const load = async () => {
    const found = await kitApi.agentVersions(agent.id);
    setVersions(found.versions);
    setKept(found.lastRestore?.kept ?? []);
  };
  const restore = (version: number) => act(async () => {
    const saved = await kitApi.restoreAgentVersion(agent.id, version);
    setAsking(null);
    setDone(saved.syncState === "synced" ? t("versionRestoreDone", { version: String(version) }) : t("versionRestoreSaving"));
    setChanges({});
    setResults({});
    onRestored(saved);
    await load();
  });

  return (
    <section aria-labelledby="agent-versions">
      <h2 id="agent-versions">{t("versions")}</h2>
      <p className="muted">{t("versionsHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {done && <p role="status">{done}</p>}
      {kept.map((line) => <p key={line} className="note">{line}</p>)}
      {versions === null && <button type="button" className="secondary" disabled={busy} onClick={() => void act(load)}>{t("versionsShow")}</button>}
      {versions !== null && versions.length === 0 && <p className="muted">{t("versionsNone")}</p>}
      {versions !== null && versions.length > 0 && (
        <ul className="versions">
          {versions.map((v) => (
            <li key={v.version}>
              <p>
                <strong>{t("versionTitle", { version: String(v.version) })}</strong>
                {v.current && ` (${t("versionInUse")})`}: {kindText(v)}
                {v.at && <small className="muted"> · {new Date(v.at).toLocaleString()}</small>}
              </p>
              <div className="actions">
                <button type="button" className="secondary" disabled={busy} onClick={() => void act(async () => {
                  const found = await kitApi.agentVersionChanges(agent.id, v.version);
                  setChanges({ ...changes, [v.version]: found.changes });
                })}>{t("versionShowChanges")}</button>
                <button type="button" className="secondary" disabled={busy} onClick={() => void act(async () => {
                  const found = await kitApi.agentVersionResults(agent.id, v.version);
                  setResults({ ...results, [v.version]: found });
                })}>{t("versionShowResults")}</button>
                {!v.current && asking !== v.version && <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(v.version)}>{t("versionRestore")}</button>}
              </div>
              {changes[v.version] && (changes[v.version].length === 0
                ? <p className="muted">{t("versionNoChanges")}</p>
                : (
                  <ul>
                    {changes[v.version].map((c, i) => <li key={`${c.label}-${i}`} className="wrap-anywhere"><strong>{c.label}</strong>: {changeText(c)}</li>)}
                  </ul>
                ))}
              {results[v.version] && (
                <>
                  <Results title={t("versionResultsNow")} window={results[v.version].current} />
                  {results[v.version].previous && <Results title={t("versionResultsBefore")} window={results[v.version].previous as AgentVersionWindow} />}
                </>
              )}
              {asking === v.version && (
                <div className="actions">
                  <span>{t("versionRestoreAsk", { version: String(v.version) })}</span>
                  <button type="button" disabled={busy} onClick={() => void restore(v.version)}>{t("versionRestore")}</button>
                  <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(null)}>{t("cancel")}</button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
