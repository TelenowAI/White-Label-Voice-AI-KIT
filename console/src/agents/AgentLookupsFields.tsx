/**
 * Console → one agent → what it asks a web address before a call opens (contract/kit-api.yaml AgentLookup): it fills call variables, for the opening line and the instructions to
 * use. A saved key is never shown: it stays until replaced or removed.
 *
 * Not here: saving, which is AgentSettingsForm.tsx's one save; what the backend checks (App\Kit\Agents\AgentLookups).
 */
import type { AgentLookup, AgentLookupFill, AgentLookupInput } from "../api";
import { t } from "../text";
import { Box, HeaderRows, refusals } from "./settingsRows";

export interface LookupRow {
  name: string;
  url: string;
  method: AgentLookup["method"];
  headers: { name: string; value: string }[];
  body: string;
  fills: AgentLookupFill[];
  tokenHint: string | null;
  token: string;
  removeToken: boolean;
}

export const lookupRows = (lookups: AgentLookup[]): LookupRow[] => lookups.map((l) => ({ name: l.name, url: l.url, method: l.method, headers: l.headers, body: l.body ?? "", fills: l.fills, tokenHint: l.tokenHint, token: "", removeToken: false }));

const newFill = (): AgentLookupFill => ({ variable: "", path: "", fallback: null });
const newRow = (): LookupRow => ({ name: "", url: "", method: "GET", headers: [], body: "", fills: [newFill()], tokenHint: null, token: "", removeToken: false });

export function lookupsInput(rows: LookupRow[]): AgentLookupInput[] {
  return rows.map((row) => ({
    name: row.name.trim(), url: row.url.trim(), method: row.method, headers: row.headers, body: row.method === "GET" ? null : row.body.trim() || null, fills: row.fills,
    ...(row.token.trim() ? { token: row.token.trim() } : row.removeToken ? { token: null } : {}),
  }));
}

export function AgentLookupsFields({ rows, errors, onChange }: { rows: LookupRow[]; errors: Record<string, string[]>; onChange: (rows: LookupRow[]) => void }) {
  const put = (i: number, over: Partial<LookupRow>) => onChange(rows.map((row, j) => (j === i ? { ...row, ...over } : row)));
  return (
    <>
      <p className="muted">{t("settingsLookupsHelp")}</p>
      {refusals(errors, "settings.lookups").length > 0 && rows.length === 0 && <small className="error">{refusals(errors, "settings.lookups").join(" ")}</small>}
      {rows.map((row, i) => {
        const at = `settings.lookups.${i}`;
        const id = `lookup-${i}`;
        const setFills = (fills: AgentLookupFill[]) => put(i, { fills });
        return (
          <fieldset className="field" key={i}>
            <legend>{row.name.trim() || t("settingsLookupNumber", { n: String(i + 1) })}</legend>
            <Box id={`${id}-name`} label={t("settingsLookupName")} value={row.name} errors={refusals(errors, `${at}.name`)} onChange={(v) => put(i, { name: v })} />
            <Box id={`${id}-url`} label={t("settingsToolUrl")} help={t("settingsLookupUrlHelp")} value={row.url} errors={refusals(errors, `${at}.url`)} onChange={(v) => put(i, { url: v })} />
            <div className="field">
              <label htmlFor={`${id}-method`}>{t("settingsMethod")}</label>
              <select id={`${id}-method`} value={row.method} onChange={(e) => put(i, { method: e.target.value as AgentLookup["method"] })}>
                <option value="GET">GET</option>
                <option value="POST">POST</option>
              </select>
            </div>
            <Box id={`${id}-token`} label={t("settingsToolKey")} secret help={row.tokenHint ? t("settingsToolKeySaved", { hint: row.tokenHint }) : t("settingsToolKeyHelp")} value={row.token} errors={refusals(errors, `${at}.token`)} onChange={(v) => put(i, { token: v })} />
            {row.tokenHint && (
              <label className="checkbox">
                <input type="checkbox" checked={row.removeToken} onChange={(e) => put(i, { removeToken: e.target.checked })} />
                {t("settingsToolKeyRemove")}
              </label>
            )}
            <h3>{t("settingsHeaders")}</h3>
            <small className="muted">{t("settingsHeadersHelp")}</small>
            <HeaderRows idPrefix={id} at={`${at}.headers`} headers={row.headers} errors={errors} onChange={(headers) => put(i, { headers })} />
            {row.method === "POST" && <Box id={`${id}-body`} label={t("settingsBody")} rows={3} value={row.body} errors={refusals(errors, `${at}.body`)} onChange={(v) => put(i, { body: v })} />}
            <h3>{t("settingsLookupFills")}</h3>
            <small className="muted">{t("settingsLookupFillsHelp")}</small>
            {refusals(errors, `${at}.fills`).length > 0 && row.fills.length === 0 && <small className="error">{refusals(errors, `${at}.fills`).join(" ")}</small>}
            {row.fills.map((fill, n) => {
              const setFill = (over: Partial<AgentLookupFill>) => setFills(row.fills.map((x, j) => (j === n ? { ...x, ...over } : x)));
              return (
                <div className="field" key={n}>
                  <Box id={`${id}-fill-${n}-variable`} label={t("settingsLookupVariable")} value={fill.variable} errors={refusals(errors, `${at}.fills.${n}.variable`)} onChange={(v) => setFill({ variable: v })} />
                  <Box id={`${id}-fill-${n}-path`} label={t("settingsLookupPath")} help={t("settingsLookupPathHelp")} value={fill.path} errors={refusals(errors, `${at}.fills.${n}.path`)} onChange={(v) => setFill({ path: v })} />
                  <Box id={`${id}-fill-${n}-fallback`} label={t("settingsLookupFallback")} help={t("settingsLookupFallbackHelp")} value={fill.fallback ?? ""} errors={refusals(errors, `${at}.fills.${n}.fallback`)} onChange={(v) => setFill({ fallback: v || null })} />
                  <button type="button" className="secondary" onClick={() => setFills(row.fills.filter((_, j) => j !== n))}>{t("settingsRemove")}</button>
                </div>
              );
            })}
            <button type="button" className="secondary" onClick={() => setFills([...row.fills, newFill()])}>{t("settingsLookupAddFill")}</button>
            <button type="button" className="secondary" onClick={() => onChange(rows.filter((_, j) => j !== i))}>{t("settingsLookupRemove")}</button>
          </fieldset>
        );
      })}
      <button type="button" className="secondary" onClick={() => onChange([...rows, newRow()])}>{t("settingsLookupAdd")}</button>
    </>
  );
}
