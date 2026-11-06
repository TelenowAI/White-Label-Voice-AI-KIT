/**
 * Console → one agent → handing a live call to another of the account's agents (contract/kit-api.yaml AgentHandoff). Every agent of the account is offered, however many there are:
 * the pages are read when one is first wanted, so an agent with none set reads nothing.
 *
 * Not here: saving, which is AgentSettingsForm.tsx's one save; what the backend checks (App\Kit\Agents\AgentHandoff).
 */
import { useEffect, useState } from "react";
import { kitApi, type Agent, type AgentHandoffTarget } from "../api";
import { t } from "../text";
import { Box, refusals } from "./settingsRows";

export function AgentHandoffFields({ agentId, rows, message, errors, onChange, onMessage }: {
  agentId: string;
  rows: AgentHandoffTarget[];
  message: string;
  errors: Record<string, string[]>;
  onChange: (rows: AgentHandoffTarget[]) => void;
  onMessage: (message: string) => void;
}) {
  const [agents, setAgents] = useState<Agent[] | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [wanted, setWanted] = useState(rows.length > 0);

  useEffect(() => {
    if (!wanted || agents !== null) return;
    let gone = false;
    (async () => {
      const all: Agent[] = [];
      for (let page = 1; ; page++) {
        const found = await kitApi.agents(page);
        all.push(...found.agents);
        if (all.length >= found.total || found.agents.length === 0) break;
      }
      if (!gone) setAgents(all);
    })().catch((err: unknown) => {
      if (!gone) setFailure(err instanceof Error ? err.message : String(err));
    });
    return () => {
      gone = true;
    };
  }, [wanted, agents]);

  const others = (agents ?? []).filter((a) => a.id !== agentId);
  const put = (i: number, over: Partial<AgentHandoffTarget>) => onChange(rows.map((row, j) => (j === i ? { ...row, ...over } : row)));

  return (
    <>
      <p className="muted">{t("settingsHandoffHelp")}</p>
      {failure && <small className="error">{failure}</small>}
      {rows.map((row, i) => {
        const at = `settings.handoff.agents.${i}`;
        const id = `handoff-${i}`;
        const known = others.some((a) => a.id === row.agentId);
        return (
          <fieldset className="field" key={i}>
            <legend>{row.label?.trim() || t("settingsHandoffNumber", { n: String(i + 1) })}</legend>
            <div className="field">
              <label htmlFor={`${id}-agent`}>{t("settingsHandoffAgent")}</label>
              <select id={`${id}-agent`} value={row.agentId} onChange={(e) => put(i, { agentId: e.target.value })} aria-invalid={refusals(errors, `${at}.agentId`).length > 0 || undefined}>
                {!row.agentId && <option value="">{t("settingsHandoffChoose")}</option>}
                {row.agentId && !known && <option value={row.agentId}>{row.label ?? row.agentId}</option>}
                {others.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
              </select>
              {refusals(errors, `${at}.agentId`).length > 0 && <small className="error">{refusals(errors, `${at}.agentId`).join(" ")}</small>}
            </div>
            <Box id={`${id}-label`} label={t("settingsHandoffLabel")} help={t("settingsHandoffLabelHelp")} value={row.label ?? ""} errors={refusals(errors, `${at}.label`)} onChange={(v) => put(i, { label: v || null })} />
            <div className="field">
              <label htmlFor={`${id}-mode`}>{t("settingsHandoffMode")}</label>
              <select id={`${id}-mode`} value={row.mode} onChange={(e) => put(i, { mode: e.target.value as AgentHandoffTarget["mode"] })}>
                <option value="transfer">{t("settingsHandoffTransfer")}</option>
                <option value="connect">{t("settingsHandoffConnect")}</option>
              </select>
            </div>
            <button type="button" className="secondary" onClick={() => onChange(rows.filter((_, j) => j !== i))}>{t("settingsRemove")}</button>
          </fieldset>
        );
      })}
      <button type="button" className="secondary" onClick={() => {
        setWanted(true);
        onChange([...rows, { agentId: "", label: null, mode: "transfer" }]);
      }}>{t("settingsHandoffAdd")}</button>
      {rows.length > 0 && <Box id="handoff-message" label={t("settingsHandoffMessage")} help={t("settingsHandoffMessageHelp")} value={message} errors={refusals(errors, "settings.handoff.message")} onChange={onMessage} />}
    </>
  );
}
