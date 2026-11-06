/**
 * What the parts of the agent form share: a text box with its refusal beside it, and how a row gets its name. Used by AgentSettingsForm.tsx,
 * AgentToolsFields.tsx, AgentLookupsFields.tsx and AgentHandoffFields.tsx.
 */
import { t } from "../text";

export const humane = (key: string) => {
  const words = key.replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
};

/** The name a row goes by: its own once it has one, else made from what was typed, whatever its language. */
export function keyFor(row: { key: string; name: string }, prefix: string, index: number, taken: string[]): string {
  if (row.key) return row.key;
  const slug = row.name.trim().toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
  const base = /^[a-z]/.test(slug) ? slug : `${prefix}_${index + 1}`;
  let key = base;
  for (let n = 2; taken.includes(key); n++) key = `${base}_${n}`;
  return key;
}

/** Everything refused at `key` or below it. */
export function refusals(errors: Record<string, string[]>, key: string): string[] {
  return Object.entries(errors).filter(([k]) => k === key || k.startsWith(`${key}.`)).flatMap(([, v]) => v);
}

export function Box({ id, label, help, value, onChange, errors, rows = 1, secret = false, numeric = false }: {
  id: string;
  label: string;
  help?: string;
  value: string;
  onChange: (v: string) => void;
  errors: string[];
  rows?: number;
  secret?: boolean;
  numeric?: boolean;
}) {
  const invalid = errors.length > 0 || undefined;
  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {help && <small className="muted">{help}</small>}
      {rows > 1
        ? <textarea id={id} rows={rows} value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={invalid} spellCheck={false} />
        : <input id={id} type={secret ? "password" : "text"} autoComplete={secret ? "new-password" : undefined} inputMode={numeric ? "numeric" : undefined} value={value} onChange={(e) => onChange(e.target.value)} aria-invalid={invalid} />}
      {errors.length > 0 && <small className="error">{errors.join(" ")}</small>}
    </div>
  );
}

export function HeaderRows({ idPrefix, at, headers, errors, onChange }: {
  idPrefix: string;
  at: string;
  headers: { name: string; value: string }[];
  errors: Record<string, string[]>;
  onChange: (rows: { name: string; value: string }[]) => void;
}) {
  return (
    <>
      {headers.map((h, i) => (
        <div className="field" key={i}>
          <Box id={`${idPrefix}-header-${i}-name`} label={`${t("settingsHeaderName")} ${i + 1}`} value={h.name} errors={refusals(errors, `${at}.${i}.name`)} onChange={(v) => onChange(headers.map((x, j) => (j === i ? { ...x, name: v } : x)))} />
          <Box id={`${idPrefix}-header-${i}-value`} label={`${t("settingsHeaderValue")} ${i + 1}`} value={h.value} errors={refusals(errors, `${at}.${i}.value`)} onChange={(v) => onChange(headers.map((x, j) => (j === i ? { ...x, value: v } : x)))} />
          <button type="button" className="secondary" onClick={() => onChange(headers.filter((_, j) => j !== i))}>{t("settingsRemove")}</button>
        </div>
      ))}
      <button type="button" className="secondary" onClick={() => onChange([...headers, { name: "", value: "" }])}>{t("settingsAddHeader")}</button>
    </>
  );
}
