/**
 * Admin → Sign-ups (`/admin/signups`; contract/kit-api.yaml getSignups, saveSignupSettings, approveSignup, rejectSignup):
 * whether people can sign up now and what that needs (email; pricing for a trial credit); the settings — who may sign
 * up and, for the domains mode, which domains; the ways customers get in; the operator's own form fields (a key, a
 * label, a type, required, and for a choice its choices); the terms and their version; the trial credit in the pricing
 * currency — saved with the backend's word on each refused part; the sign-ups waiting for approval, each with what it
 * answered, approved or refused (asking for a reason first); and the recent sign-ups. A sign-up made with a phone
 * shows its number, one made with a sign-in provider says which.
 *
 * Not here: the public form (setup/SignUp.tsx), the wizard's step (drawn from what the backend declares).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type SignupAdmin, type SignupSettings, type SignupSummary } from "../api";
import { t, type TextKey } from "../text";

const modeWords: Record<string, TextKey> = { closed: "modeClosed", open: "modeOpen", approval: "modeApproval", domains: "modeDomains" };
const methodWords: Record<string, TextKey> = { password: "methodPassword", magic_link: "methodMagicLink", email_code: "methodEmailCode", phone_code: "methodPhoneCode" };
const statusWords: Record<SignupSummary["status"], TextKey> = { unverified: "signupUnverified", waiting: "signupWaiting", done: "signupDone", rejected: "signupRejected" };

/** Who a sign-up is: its email, or its number for one made with a phone; and the sign-in provider it came with. */
function who(s: SignupSummary): string {
  const address = s.email ?? t("signupByPhone", { phone: s.phone ?? "" });
  return s.provider ? `${address} · ${t("signupWithProvider", { provider: s.provider })}` : address;
}

function word(words: Record<string, TextKey>, value: string): string {
  return Object.hasOwn(words, value) ? t(words[value]) : value.replace(/_/g, " ");
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function SignupsPage() {
  const [data, setData] = useState<SignupAdmin | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  // Kept here: the form starts again from what the backend saved (its domains lowercased, its fields trimmed).
  const [saved, setSaved] = useState(false);

  const load = useCallback(async () => {
    try {
      setData(await kitApi.signups());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="signups-title">
      <h1 id="signups-title">{t("signupsTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && (
        <>
          <p role="status" className={data.open ? "muted" : "note"}>
            {t(data.open ? "signupsOpen" : "signupsClosedNow")}
            {!data.emailOn && ` ${t("signupsNeedEmail")}`}
          </p>
          <Waiting waiting={data.waiting} onChanged={setData} />
          {saved && <p role="status">{t("siteSaved")}</p>}
          <SettingsForm key={JSON.stringify(data.settings)} data={data} onEdited={() => setSaved(false)}
            onSaved={(d) => {
              setData(d);
              setSaved(true);
            }} />
          <Recent recent={data.recent} />
        </>
      )}
    </section>
  );
}

type FieldRow = SignupSettings["fields"][number] & { optionsText: string };

function SettingsForm({ data, onSaved, onEdited }: { data: SignupAdmin; onSaved: (d: SignupAdmin) => void; onEdited: () => void }) {
  const s = data.settings;
  const [mode, setMode] = useState(s.mode);
  const [domains, setDomains] = useState(s.domains.join(", "));
  const [methods, setMethods] = useState<string[]>(s.methods);
  const [fields, setFields] = useState<FieldRow[]>(s.fields.map((f) => ({ ...f, optionsText: f.options.join(", ") })));
  const [termsUrl, setTermsUrl] = useState(s.terms?.url ?? "");
  const [termsVersion, setTermsVersion] = useState(s.terms?.version ?? "");
  const [trial, setTrial] = useState(s.trialCredit ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const split = (text: string) => text.split(/[\s,]+/).map((x) => x.trim()).filter(Boolean);
  const setField = (i: number, change: Partial<FieldRow>) => setFields((old) => old.map((f, j) => (j === i ? { ...f, ...change } : f)));

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    onEdited();
    try {
      onSaved(await kitApi.saveSignupSettings({
        mode, domains: split(domains), methods,
        fields: fields.map((f) => ({ key: f.key.trim(), label: f.label.trim(), type: f.type, required: f.required,
          options: f.type === "select" ? f.optionsText.split(",").map((x) => x.trim()).filter(Boolean) : [] })),
        terms: termsUrl.trim() || termsVersion.trim() ? { url: termsUrl.trim(), version: termsVersion.trim() } : null,
        trialCredit: trial.trim() || null,
      }));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const error = (...keys: string[]) => {
    const found = keys.flatMap((k) => Object.entries(errors).filter(([e]) => e === k || e.startsWith(`${k}.`)).flatMap(([, m]) => m));
    return found.length > 0 && <small className="error">{found.join(" ")}</small>;
  };

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("signupSettingsTitle")}>
      <h2>{t("signupSettingsTitle")}</h2>
      <div className="field">
        <label htmlFor="signup-mode">{t("signupMode")}</label>
        <select id="signup-mode" value={mode} onChange={(e) => setMode(e.target.value)}>
          {data.options.modes.map((m) => <option key={m} value={m}>{word(modeWords, m)}</option>)}
        </select>
        {error("mode")}
      </div>
      {mode === "domains" && (
        <div className="field">
          <label htmlFor="signup-domains">{t("signupDomains")}</label>
          <small className="muted">{t("signupDomainsHelp")}</small>
          <input id="signup-domains" value={domains} onChange={(e) => setDomains(e.target.value)} />
          {error("domains")}
        </div>
      )}
      <fieldset className="field">
        <legend>{t("signupMethods")}</legend>
        {data.options.methods.map((m) => (
          <label key={m} className="checkbox">
            <input type="checkbox" checked={methods.includes(m)}
              onChange={(e) => setMethods((old) => (e.target.checked ? [...old, m] : old.filter((x) => x !== m)))} />
            {word(methodWords, m)}
          </label>
        ))}
        <small className="muted">{t("signupWaysHelp")}</small>
        {error("methods")}
      </fieldset>

      <h3>{t("signupFields")}</h3>
      <p className="muted">{t("signupFieldsHelp")}</p>
      {fields.map((f, i) => (
        <fieldset key={i} className="field signup-field" aria-label={t("signupFieldN", { n: String(i + 1) })}>
          <div className="inline-form">
            <div className="field">
              <label htmlFor={`sf-${i}-label`}>{t("fieldLabel")}</label>
              <input id={`sf-${i}-label`} value={f.label} onChange={(e) => setField(i, { label: e.target.value })} />
            </div>
            <div className="field">
              <label htmlFor={`sf-${i}-key`}>{t("fieldKey")}</label>
              <input id={`sf-${i}-key`} value={f.key} onChange={(e) => setField(i, { key: e.target.value })} />
            </div>
            <div className="field">
              <label htmlFor={`sf-${i}-type`}>{t("fieldType")}</label>
              <select id={`sf-${i}-type`} value={f.type} onChange={(e) => setField(i, { type: e.target.value })}>
                {data.options.fieldTypes.map((ft) => <option key={ft} value={ft}>{ft}</option>)}
              </select>
            </div>
          </div>
          {f.type === "select" && (
            <div className="field">
              <label htmlFor={`sf-${i}-options`}>{t("fieldOptions")}</label>
              <input id={`sf-${i}-options`} value={f.optionsText} onChange={(e) => setField(i, { optionsText: e.target.value })} />
            </div>
          )}
          <div className="actions">
            <label className="checkbox">
              <input type="checkbox" checked={f.required} onChange={(e) => setField(i, { required: e.target.checked })} />
              {t("fieldRequired")}
            </label>
            <button type="button" className="secondary" onClick={() => setFields((old) => old.filter((_, j) => j !== i))}>{t("remove")}</button>
          </div>
          {error(`fields.${i}`)}
        </fieldset>
      ))}
      {fields.length < data.options.maxFields && (
        <p>
          <button type="button" className="secondary"
            onClick={() => setFields((old) => [...old, { key: "", label: "", type: data.options.fieldTypes[0] ?? "text", required: false, options: [], optionsText: "" }])}>
            {t("addField")}
          </button>
        </p>
      )}

      <h3>{t("signupTerms")}</h3>
      <div className="inline-form">
        <div className="field">
          <label htmlFor="signup-terms-url">{t("termsUrl")}</label>
          <input id="signup-terms-url" type="url" value={termsUrl} onChange={(e) => setTermsUrl(e.target.value)} />
          {error("terms.url")}
        </div>
        <div className="field field-narrow">
          <label htmlFor="signup-terms-version">{t("termsVersion")}</label>
          <input id="signup-terms-version" value={termsVersion} onChange={(e) => setTermsVersion(e.target.value)} />
          {error("terms.version")}
        </div>
      </div>

      <div className="field field-narrow">
        <label htmlFor="signup-trial">{data.currency ? t("trialCredit", { currency: data.currency }) : t("trialCreditNoPricing")}</label>
        <input id="signup-trial" inputMode="decimal" value={trial} disabled={!data.currency && !trial} onChange={(e) => setTrial(e.target.value)} />
        {error("trialCredit")}
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveSignupSettings")}</button>
    </form>
  );
}

function Answers({ s }: { s: SignupSummary }) {
  const entries = Object.entries(s.answers);
  return entries.length > 0 ? <small className="muted"> {entries.map(([k, v]) => `${k}: ${v}`).join(" · ")}</small> : null;
}

function Waiting({ waiting, onChanged }: { waiting: SignupSummary[]; onChanged: (d: SignupAdmin) => void }) {
  const [refusing, setRefusing] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function act(run: () => Promise<SignupAdmin>) {
    setBusy(true);
    setFailure(null);
    try {
      onChanged(await run());
      setRefusing(null);
      setReason("");
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  if (waiting.length === 0) return null;
  return (
    <>
      <h2>{t("signupsWaiting")}</h2>
      {failure && <p role="alert" className="error">{failure}</p>}
      <ul className="signups">
        {waiting.map((s) => (
          <li key={s.id} data-signup={s.id}>
            <p><strong>{s.accountName}</strong> · {s.name} · {who(s)}<Answers s={s} /></p>
            {refusing === s.id ? (
              <div className="actions">
                <label htmlFor={`reason-${s.id}`}>{t("refuseReason")}</label>
                <input id={`reason-${s.id}`} value={reason} onChange={(e) => setReason(e.target.value)} />
                <button type="button" disabled={busy} onClick={() => act(() => kitApi.rejectSignup(s.id, reason.trim() || null))}>{t("yesRefuse")}</button>
                <button type="button" className="secondary" disabled={busy} onClick={() => setRefusing(null)}>{t("cancel")}</button>
              </div>
            ) : (
              <div className="actions">
                <button type="button" disabled={busy} onClick={() => act(() => kitApi.approveSignup(s.id))}>{t("approve")}</button>
                <button type="button" className="secondary" disabled={busy} onClick={() => setRefusing(s.id)}>{t("refuse")}</button>
              </div>
            )}
          </li>
        ))}
      </ul>
    </>
  );
}

function Recent({ recent }: { recent: SignupSummary[] }) {
  return (
    <>
      <h2>{t("signupsRecent")}</h2>
      {recent.length === 0 && <p className="muted">{t("signupsNone")}</p>}
      {recent.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("signupsRecent")}>
            <thead>
              <tr>
                <th scope="col">{t("colAccount")}</th>
                <th scope="col">{t("colStatus")}</th>
                <th scope="col">{t("email")}</th>
                <th scope="col">{t("colWhen")}</th>
              </tr>
            </thead>
            <tbody>
              {recent.map((s) => (
                <tr key={s.id}>
                  <td>{s.accountName}<Answers s={s} /></td>
                  <td>{word(statusWords, s.status)}{s.reason && <small className="muted"> · {s.reason}</small>}</td>
                  <td>{who(s)}</td>
                  <td>{new Date(s.createdAt).toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
