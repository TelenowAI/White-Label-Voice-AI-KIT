/**
 * Recording consent (contract/kit-api.yaml recordConsent, importConsent): one person, or a file of them on one kind of consent and one set of words (too big refused before it is sent). The choices and limits are the backend's, its word shows beside a field, and
 * the exact words agreed to are asked for only on the kind that needs them.
 *
 * Not here: the list (ConsentPage.tsx), the words for a kind (words.ts).
 */
import { useState, type ChangeEvent, type FormEvent } from "react";
import { KitApiError, kitApi, type ConsentLimits } from "../api";
import { t } from "../text";
import { howWord, kindWord, scopeWord } from "./words";

const MB = 1024 * 1024;

function readText(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result ?? ""));
    reader.onerror = () => reject(reader.error ?? new Error("The file could not be read."));
    reader.readAsText(file);
  });
}

/** A date and time typed here, as the moment it means. */
function moment(value: string): string | null {
  return value ? new Date(value).toISOString() : null;
}

type Errors = Record<string, string[]>;

function Said({ errors, field }: { errors: Errors; field: string }) {
  return errors[field] ? <small className="error">{errors[field].join(" ")}</small> : null;
}

/** What a record and a file share: the kind, what for, and the words when that kind needs them. */
function Kind({ limits, prefix, values, set, errors }: { limits: ConsentLimits; prefix: string; values: { consentType: string; scope: string; disclosureText: string; disclosureVersion: string }; set: (over: Partial<{ consentType: string; scope: string; disclosureText: string; disclosureVersion: string }>) => void; errors: Errors }) {
  return (
    <>
      <div className="inline-form">
        <div className="field">
          <label htmlFor={`${prefix}-kind`}>{t("consentKind")}</label>
          <select id={`${prefix}-kind`} value={values.consentType} onChange={(e) => set({ consentType: e.target.value })}>
            {limits.types.map((k) => <option key={k} value={k}>{kindWord(k)}</option>)}
          </select>
          <Said errors={errors} field="consentType" />
        </div>
        <div className="field">
          <label htmlFor={`${prefix}-scope`}>{t("consentScope")}</label>
          <select id={`${prefix}-scope`} value={values.scope} aria-describedby={`${prefix}-scope-help`} onChange={(e) => set({ scope: e.target.value })}>
            {limits.scopes.map((k) => <option key={k} value={k}>{scopeWord(k)}</option>)}
          </select>
          <small id={`${prefix}-scope-help`} className="muted">{t("consentScopeHelp")}</small>
          <Said errors={errors} field="scope" />
        </div>
      </div>
      {values.consentType === limits.writtenType && (
        <>
          <div className="field">
            <label htmlFor={`${prefix}-words`}>{t("consentWords")}</label>
            <textarea id={`${prefix}-words`} rows={3} value={values.disclosureText} aria-describedby={`${prefix}-words-help`} onChange={(e) => set({ disclosureText: e.target.value })} />
            <small id={`${prefix}-words-help`} className="muted">{t("consentWordsHelp")}</small>
            <Said errors={errors} field="disclosureText" />
          </div>
          <div className="field field-narrow">
            <label htmlFor={`${prefix}-version`}>{t("consentWordsVersion")}</label>
            <input id={`${prefix}-version`} value={values.disclosureVersion} onChange={(e) => set({ disclosureVersion: e.target.value })} />
            <Said errors={errors} field="disclosureVersion" />
          </div>
        </>
      )}
    </>
  );
}

const start = (limits: ConsentLimits) => ({ consentType: limits.types[0] ?? "", scope: limits.defaultScope, disclosureText: "", disclosureVersion: "" });

export function ConsentRecordForm({ limits, onRecorded }: { limits: ConsentLimits; onRecorded: (said: string) => void }) {
  const [kind, setKind] = useState(() => start(limits));
  const [form, setForm] = useState({ phoneNumber: "", source: limits.sources[0] ?? "", capturedAt: "", expiresAt: "", note: "" });
  const [errors, setErrors] = useState<Errors>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const written = kind.consentType === limits.writtenType;
      const saved = await kitApi.recordConsent({
        phoneNumber: form.phoneNumber.trim(), consentType: kind.consentType, scope: kind.scope, source: form.source,
        disclosureText: written ? kind.disclosureText.trim() || null : null, disclosureVersion: written ? kind.disclosureVersion.trim() || null : null,
        capturedAt: moment(form.capturedAt), expiresAt: moment(form.expiresAt), note: form.note.trim() || null,
      });
      setForm((f) => ({ ...f, phoneNumber: "", capturedAt: "", expiresAt: "", note: "" }));
      onRecorded(t(saved.onDoNotCall ? "consentRecordedOnDnc" : "consentRecorded"));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("consentRecordTitle")}>
      <h2>{t("consentRecordTitle")}</h2>
      <div className="field">
        <label htmlFor="consent-number">{t("consentNumber")}</label>
        <input id="consent-number" value={form.phoneNumber} aria-describedby="consent-number-help" onChange={set("phoneNumber")} />
        <small id="consent-number-help" className="muted">{t("consentNumberHelp")}</small>
        <Said errors={errors} field="phoneNumber" />
      </div>
      <Kind limits={limits} prefix="consent" values={kind} set={(over) => setKind((k) => ({ ...k, ...over }))} errors={errors} />
      <div className="field">
        <label htmlFor="consent-how">{t("consentHow")}</label>
        <select id="consent-how" value={form.source} onChange={set("source")}>
          {limits.sources.map((k) => <option key={k} value={k}>{howWord(k)}</option>)}
        </select>
        <Said errors={errors} field="source" />
      </div>
      <div className="inline-form">
        <div className="field">
          <label htmlFor="consent-when">{t("consentWhen")}</label>
          <input id="consent-when" type="datetime-local" value={form.capturedAt} aria-describedby="consent-when-help" onChange={set("capturedAt")} />
          <small id="consent-when-help" className="muted">{t("consentWhenHelp")}</small>
          <Said errors={errors} field="capturedAt" />
        </div>
        <div className="field">
          <label htmlFor="consent-ends">{t("consentEnds")}</label>
          <input id="consent-ends" type="datetime-local" value={form.expiresAt} aria-describedby="consent-ends-help" onChange={set("expiresAt")} />
          <small id="consent-ends-help" className="muted">{t("consentEndsHelp")}</small>
          <Said errors={errors} field="expiresAt" />
        </div>
      </div>
      <div className="field">
        <label htmlFor="consent-note">{t("consentNote")}</label>
        <input id="consent-note" value={form.note} aria-describedby="consent-note-help" onChange={set("note")} />
        <small id="consent-note-help" className="muted">{t("consentNoteHelp")}</small>
        <Said errors={errors} field="note" />
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("consentRecord")}</button>
    </form>
  );
}

export function ConsentImportForm({ limits, onImported }: { limits: ConsentLimits; onImported: (said: string) => void }) {
  const [kind, setKind] = useState(() => start(limits));
  const [capturedAt, setCapturedAt] = useState("");
  const [errors, setErrors] = useState<Errors>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const max = String(Math.floor(limits.importMaxBytes / MB));

  async function pick(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setErrors({});
    setFailure(null);
    if (file.size > limits.importMaxBytes) {
      setFailure(t("consentFileTooBig", { max }));
      return;
    }
    setBusy(true);
    try {
      const written = kind.consentType === limits.writtenType;
      const done = await kitApi.importConsent({
        csv: await readText(file), consentType: kind.consentType, scope: kind.scope, capturedAt: moment(capturedAt),
        disclosureText: written ? kind.disclosureText.trim() || null : null, disclosureVersion: written ? kind.disclosureVersion.trim() || null : null,
      });
      onImported(t("consentImported", { added: String(done.added), skipped: String(done.skipped), duplicates: String(done.duplicates) }));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="agent-form">
      <h2>{t("consentImportTitle")}</h2>
      <small className="muted">{t("consentImportHelp", { columns: limits.phoneColumns.join(", "), rows: String(limits.importMaxRows), max, captured: limits.capturedColumn, expires: limits.expiresColumn })}</small>
      <Kind limits={limits} prefix="consent-file" values={kind} set={(over) => setKind((k) => ({ ...k, ...over }))} errors={errors} />
      <div className="field">
        <label htmlFor="consent-file-when">{t("consentImportWhen")}</label>
        <input id="consent-file-when" type="datetime-local" value={capturedAt} onChange={(e) => setCapturedAt(e.target.value)} />
        <Said errors={errors} field="capturedAt" />
      </div>
      <div className="field">
        <label htmlFor="consent-file">{t("consentImportFile")}</label>
        <input id="consent-file" type="file" accept=".csv,text/csv" disabled={busy} onChange={(e) => void pick(e)} />
        <Said errors={errors} field="csv" />
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
    </div>
  );
}
