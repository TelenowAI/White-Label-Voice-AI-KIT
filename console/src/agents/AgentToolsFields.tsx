/**
 * Console → one agent → its own API tools (contract/kit-api.yaml AgentTool): a web address the agent may call during a call, with the details it should get from the caller.
 * Plain, it is POSTed them as JSON; customised, it sends the method, headers and body written here. A saved key is never shown: it stays until replaced or removed.
 *
 * Not here: saving, which is AgentSettingsForm.tsx's one save; what the backend checks (App\Kit\Agents\AgentTools).
 */
import type { AgentTool, AgentToolInput, AgentToolParameter } from "../api";
import { t } from "../text";
import { Box, HeaderRows, humane, keyFor, refusals } from "./settingsRows";

export interface ToolRow {
  /** The name it is saved under, empty until it is. */
  key: string;
  name: string;
  description: string;
  url: string;
  custom: boolean;
  method: string;
  headers: { name: string; value: string }[];
  body: string;
  parameters: AgentToolParameter[];
  say: string;
  timeout: string;
  askFirst: boolean;
  tokenHint: string | null;
  token: string;
  removeToken: boolean;
}

export const toolRows = (tools: AgentTool[]): ToolRow[] => tools.map((tool) => ({
  key: tool.name, name: humane(tool.name), description: tool.description, url: tool.url, custom: tool.request !== null, method: tool.request?.method ?? "POST",
  headers: tool.request?.headers ?? [], body: tool.request?.body ?? "", parameters: tool.parameters, say: tool.sayWhileRunning ?? "",
  timeout: tool.timeoutSecs === null ? "" : String(tool.timeoutSecs), askFirst: tool.askFirst, tokenHint: tool.tokenHint, token: "", removeToken: false,
}));

const newRow = (): ToolRow => ({ key: "", name: "", description: "", url: "", custom: false, method: "POST", headers: [], body: "", parameters: [], say: "", timeout: "", askFirst: false, tokenHint: null, token: "", removeToken: false });

/** A key goes only when typed or taken away: left out, a saved one stays. */
export function toolsInput(rows: ToolRow[]): AgentToolInput[] {
  const taken: string[] = [];
  return rows.map((row, i) => {
    const name = keyFor(row, "tool", i, taken);
    taken.push(name);
    return {
      name, description: row.description.trim(), url: row.url.trim(),
      request: row.custom ? { method: row.method, headers: row.headers, body: row.body.trim() || null } : null,
      parameters: row.parameters, sayWhileRunning: row.say.trim() || null, timeoutSecs: row.timeout.trim() === "" ? null : Number(row.timeout), askFirst: row.askFirst,
      ...(row.token.trim() ? { token: row.token.trim() } : row.removeToken ? { token: null } : {}),
    };
  });
}

const kinds: AgentToolParameter["type"][] = ["string", "number", "integer", "boolean"];
const methods = ["GET", "POST", "PUT", "PATCH", "DELETE"];

export function AgentToolsFields({ rows, errors, onChange }: { rows: ToolRow[]; errors: Record<string, string[]>; onChange: (rows: ToolRow[]) => void }) {
  const put = (i: number, over: Partial<ToolRow>) => onChange(rows.map((row, j) => (j === i ? { ...row, ...over } : row)));
  return (
    <>
      <p className="muted">{t("settingsToolsHelp")}</p>
      {rows.map((row, i) => {
        const at = `settings.tools.${i}`;
        const id = `tool-${i}`;
        const setParameters = (parameters: AgentToolParameter[]) => put(i, { parameters });
        return (
          <fieldset className="field" key={i}>
            <legend>{row.name.trim() || t("settingsToolNumber", { n: String(i + 1) })}</legend>
            <Box id={`${id}-name`} label={t("settingsToolName")} value={row.name} errors={refusals(errors, `${at}.name`)} onChange={(v) => put(i, { name: v })} />
            <Box id={`${id}-description`} label={t("settingsToolDescription")} help={t("settingsToolDescriptionHelp")} rows={2} value={row.description} errors={refusals(errors, `${at}.description`)} onChange={(v) => put(i, { description: v })} />
            <Box id={`${id}-url`} label={t("settingsToolUrl")} help={t("settingsToolUrlHelp")} value={row.url} errors={refusals(errors, `${at}.url`)} onChange={(v) => put(i, { url: v })} />
            <Box id={`${id}-token`} label={t("settingsToolKey")} secret help={row.tokenHint ? t("settingsToolKeySaved", { hint: row.tokenHint }) : t("settingsToolKeyHelp")} value={row.token} errors={refusals(errors, `${at}.token`)} onChange={(v) => put(i, { token: v })} />
            {row.tokenHint && (
              <label className="checkbox">
                <input type="checkbox" checked={row.removeToken} onChange={(e) => put(i, { removeToken: e.target.checked })} />
                {t("settingsToolKeyRemove")}
              </label>
            )}
            <h3>{t("settingsToolDetails")}</h3>
            <small className="muted">{t("settingsToolDetailsHelp")}</small>
            {refusals(errors, `${at}.parameters`).length > 0 && row.parameters.length === 0 && <small className="error">{refusals(errors, `${at}.parameters`).join(" ")}</small>}
            {row.parameters.map((p, n) => {
              const setP = (over: Partial<AgentToolParameter>) => setParameters(row.parameters.map((x, j) => (j === n ? { ...x, ...over } : x)));
              return (
                <div className="field" key={n}>
                  <Box id={`${id}-detail-${n}-name`} label={t("settingsToolDetailName")} value={p.name} errors={refusals(errors, `${at}.parameters.${n}.name`)} onChange={(v) => setP({ name: v })} />
                  <div className="field">
                    <label htmlFor={`${id}-detail-${n}-type`}>{t("settingsToolDetailType")}</label>
                    <select id={`${id}-detail-${n}-type`} value={p.type} onChange={(e) => setP({ type: e.target.value as AgentToolParameter["type"] })}>
                      {kinds.map((kind) => <option key={kind} value={kind}>{t(`settingsDetailKind_${kind}`)}</option>)}
                    </select>
                  </div>
                  <Box id={`${id}-detail-${n}-description`} label={t("settingsToolDetailDescription")} value={p.description ?? ""} errors={refusals(errors, `${at}.parameters.${n}.description`)} onChange={(v) => setP({ description: v || null })} />
                  <label className="checkbox">
                    <input type="checkbox" checked={p.required} onChange={(e) => setP({ required: e.target.checked })} />
                    {t("settingsToolDetailRequired")}
                  </label>
                  <button type="button" className="secondary" onClick={() => setParameters(row.parameters.filter((_, j) => j !== n))}>{t("settingsRemove")}</button>
                </div>
              );
            })}
            <button type="button" className="secondary" onClick={() => setParameters([...row.parameters, { name: "", type: "string", description: null, required: false }])}>{t("settingsToolAddDetail")}</button>
            <label className="checkbox">
              <input type="checkbox" checked={row.custom} onChange={(e) => put(i, { custom: e.target.checked })} />
              {t("settingsToolCustom")}
            </label>
            <small className="muted">{t("settingsToolCustomHelp")}</small>
            {row.custom && (
              <>
                <div className="field">
                  <label htmlFor={`${id}-method`}>{t("settingsMethod")}</label>
                  <select id={`${id}-method`} value={row.method} onChange={(e) => put(i, { method: e.target.value })}>
                    {methods.map((m) => <option key={m} value={m}>{m}</option>)}
                  </select>
                </div>
                <h3>{t("settingsHeaders")}</h3>
                <small className="muted">{t("settingsHeadersHelp")}</small>
                <HeaderRows idPrefix={id} at={`${at}.request.headers`} headers={row.headers} errors={errors} onChange={(headers) => put(i, { headers })} />
                {row.method !== "GET" && <Box id={`${id}-body`} label={t("settingsBody")} help={t("settingsToolBodyHelp")} rows={4} value={row.body} errors={refusals(errors, `${at}.request.body`)} onChange={(v) => put(i, { body: v })} />}
              </>
            )}
            <Box id={`${id}-say`} label={t("settingsToolSay")} help={t("settingsToolSayHelp")} value={row.say} errors={refusals(errors, `${at}.sayWhileRunning`)} onChange={(v) => put(i, { say: v })} />
            <Box id={`${id}-timeout`} label={t("settingsToolTimeout")} help={t("settingsToolTimeoutHelp")} numeric value={row.timeout} errors={refusals(errors, `${at}.timeoutSecs`)} onChange={(v) => put(i, { timeout: v })} />
            <label className="checkbox">
              <input type="checkbox" checked={row.askFirst} onChange={(e) => put(i, { askFirst: e.target.checked })} />
              {t("settingsToolAskFirst")}
            </label>
            <small className="muted">{t("settingsToolAskFirstHelp")}</small>
            <button type="button" className="secondary" onClick={() => onChange(rows.filter((_, j) => j !== i))}>{t("settingsToolRemove")}</button>
          </fieldset>
        );
      })}
      <button type="button" className="secondary" onClick={() => onChange([...rows, newRow()])}>{t("settingsToolAdd")}</button>
    </>
  );
}
