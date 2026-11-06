/**
 * Admin → Email (`/admin/email`; contract/kit-api.yaml getEmailSettings, saveEmailSite, addEmailSender,
 * updateEmailSender, deleteEmailSender, orderEmailSenders, sendTestEmail): whether the platform can send email; the
 * site's address and who emails come from; the senders in the order they are tried — each form drawn from its
 * driver's own fields (setup/StepForm.tsx FieldInput), a password never shown and kept when left empty — added or
 * changed only once the backend has sent the signed-in staff member a test, moved up, removed only after asking; a
 * test through the whole line; the words of each email (EmailTemplates.tsx); and the recent emails with how each went.
 *
 * Not here: the wizard's Email step (drawn by setup/StepForm.tsx from what the backend declares).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type EmailSettings, type OutgoingEmail, type SetupField } from "../api";
import { FieldInput } from "../setup/StepForm";
import { EmailTemplates } from "./EmailTemplates";
import { t, type TextKey } from "../text";

const statuses: Record<OutgoingEmail["status"], TextKey> = { queued: "emailQueued", sent: "emailSent", failed: "emailFailed" };

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function startValues(fields: SetupField[]): Record<string, string> {
  return Object.fromEntries(fields.map((f) => [f.name, f.value ?? (f.type === "select" ? (f.options[0]?.value ?? "") : "")]));
}

export function EmailPage() {
  const [settings, setSettings] = useState<EmailSettings | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setSettings(await kitApi.emailSettings());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="email-title">
      <h1 id="email-title">{t("emailTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {settings && (
        <>
          <p role="status" className={settings.on ? "muted" : "note"}>{t(settings.on ? "emailOn" : "emailOff")}</p>
          <SiteForm settings={settings} onSaved={setSettings} />
          <Senders settings={settings} onChanged={setSettings} onReload={load} />
          <AddSender settings={settings} onAdded={setSettings} />
          <TestEmail on={settings.on} onSent={load} />
          <EmailTemplates />
          <Recent recent={settings.recent} />
        </>
      )}
    </section>
  );
}

function SiteForm({ settings, onSaved }: { settings: EmailSettings; onSaved: (s: EmailSettings) => void }) {
  const [form, setForm] = useState({ siteUrl: settings.siteUrl, fromAddress: settings.fromAddress ?? "", fromName: settings.fromAddress ? settings.fromName : "" });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setSaved(false);
    try {
      onSaved(await kitApi.saveEmailSite({ siteUrl: form.siteUrl.trim(), fromAddress: form.fromAddress.trim(), fromName: form.fromName.trim() || null }));
      setSaved(true);
    } catch (err) {
      setErrors(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : { siteUrl: [message(err)] });
    } finally {
      setBusy(false);
    }
  }

  const field = (name: keyof typeof form, label: TextKey, type: string, help?: TextKey) => (
    <div className="field">
      <label htmlFor={`email-${name}`}>{t(label)}</label>
      {help && <small className="muted">{t(help)}</small>}
      <input id={`email-${name}`} type={type} value={form[name]} onChange={(e) => setForm((f) => ({ ...f, [name]: e.target.value }))}
        aria-invalid={errors[name] ? true : undefined} />
      {errors[name] && <small className="error">{errors[name].join(" ")}</small>}
    </div>
  );

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("emailSiteTitle")}>
      <h2>{t("emailSiteTitle")}</h2>
      {field("siteUrl", "siteUrl", "url", "siteUrlHelp")}
      {field("fromAddress", "fromAddress", "email")}
      {field("fromName", "fromName", "text", "fromNameHelp")}
      {saved && <p role="status">{t("siteSaved")}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveSite")}</button>
    </form>
  );
}

function SenderForm({ id, fields, label, onSubmit }: { id: string; fields: SetupField[]; label: TextKey; onSubmit: (values: Record<string, string>) => Promise<void> }) {
  const [values, setValues] = useState(() => startValues(fields));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      await onSubmit(values);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} noValidate aria-label={t(label)}>
      {fields.map((f) => (
        <FieldInput key={f.name} stepId={id} field={f} value={values[f.name] ?? ""} errors={errors[f.name] ?? []}
          onChange={(v) => setValues((old) => ({ ...old, [f.name]: v }))} />
      ))}
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("sendingTest") : t(label)}</button>
    </form>
  );
}

function Senders({ settings, onChanged, onReload }: { settings: EmailSettings; onChanged: (s: EmailSettings) => void; onReload: () => void }) {
  const [editing, setEditing] = useState<string | null>(null);
  const [asking, setAsking] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const ids = settings.senders.map((s) => s.id);

  async function act(run: () => Promise<EmailSettings | void>) {
    setFailure(null);
    try {
      const next = await run();
      if (next) onChanged(next);
      else onReload();
      setAsking(null);
    } catch (err) {
      setFailure(message(err));
    }
  }

  return (
    <>
      <h2>{t("sendersTitle")}</h2>
      {settings.senders.length === 0 && <p className="muted">{t("sendersEmpty")}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      <ol className="senders">
        {settings.senders.map((s, i) => (
          <li key={s.id} data-sender={s.id}>
            <div className="actions">
              <span><strong>{s.driverName}</strong> · {s.label}</span>
              {i > 0 && (
                <button type="button" className="secondary" onClick={() => act(() => kitApi.orderEmailSenders([...ids.slice(0, i - 1), s.id, ids[i - 1], ...ids.slice(i + 1)]))}>
                  {t("moveUp")}
                </button>
              )}
              <button type="button" className="secondary" onClick={() => setEditing(editing === s.id ? null : s.id)}>{t("edit")}</button>
              {asking === s.id ? (
                <>
                  <span>{t("confirmRemoveSender", { label: s.label })}</span>
                  <button type="button" onClick={() => act(() => kitApi.deleteEmailSender(s.id))}>{t("yesDelete")}</button>
                  <button type="button" className="secondary" onClick={() => setAsking(null)}>{t("cancel")}</button>
                </>
              ) : (
                <button type="button" className="secondary" onClick={() => setAsking(s.id)}>{t("remove")}</button>
              )}
            </div>
            {editing === s.id && (
              <SenderForm id={`sender-${s.id}`} fields={s.fields} label="saveSender"
                onSubmit={async (values) => {
                  onChanged(await kitApi.updateEmailSender(s.id, values));
                  setEditing(null);
                }} />
            )}
          </li>
        ))}
      </ol>
    </>
  );
}

function AddSender({ settings, onAdded }: { settings: EmailSettings; onAdded: (s: EmailSettings) => void }) {
  const [driver, setDriver] = useState(settings.drivers[0]?.id ?? "");
  // Bumped after each sender is added, so the form starts empty again (no password left in the page).
  const [added, setAdded] = useState(0);
  const chosen = settings.drivers.find((d) => d.id === driver);
  if (settings.senders.length >= settings.maxSenders) return <p className="muted">{t("sendersFull", { max: String(settings.maxSenders) })}</p>;

  return (
    <div className="agent-form">
      <h2>{t("addSenderTitle")}</h2>
      <p className="muted">{t("addSenderHelp")}</p>
      <div className="field">
        <label htmlFor="email-driver">{t("sendWith")}</label>
        <select id="email-driver" value={driver} onChange={(e) => setDriver(e.target.value)}>
          {settings.drivers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      </div>
      {chosen && (
        <SenderForm key={`${chosen.id}-${added}`} id={`new-${chosen.id}`} fields={chosen.fields} label="addSender"
          onSubmit={async (values) => {
            onAdded(await kitApi.addEmailSender(chosen.id, values));
            setAdded((n) => n + 1);
          }} />
      )}
    </div>
  );
}

function TestEmail({ on, onSent }: { on: boolean; onSent: () => void }) {
  const [result, setResult] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  if (!on) return null;

  async function send() {
    setBusy(true);
    setResult(null);
    try {
      const e = await kitApi.sendTestEmail();
      setResult(e.status === "sent" ? t("testSent", { to: e.to }) : t("testWaiting", { error: e.lastError ?? "" }));
      onSent();
    } catch (err) {
      setResult(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <p>
      <button type="button" disabled={busy} onClick={send}>{busy ? t("sendingTest") : t("testEmail")}</button>
      {result && <span role="status"> {result}</span>}
    </p>
  );
}

function Recent({ recent }: { recent: OutgoingEmail[] }) {
  return (
    <>
      <h2>{t("recentEmailsTitle")}</h2>
      {recent.length === 0 && <p className="muted">{t("recentEmailsEmpty")}</p>}
      {recent.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("recentEmailsTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("colKind")}</th>
                <th scope="col">{t("colStatus")}</th>
                <th scope="col">{t("colTo")}</th>
                <th scope="col">{t("colWhen")}</th>
              </tr>
            </thead>
            <tbody>
              {recent.map((e) => (
                <tr key={e.id}>
                  <td>{e.kindName}</td>
                  <td>
                    {t(statuses[e.status])}
                    {e.attempts > 1 && <small className="muted"> · {t("attemptsCount", { n: String(e.attempts) })}</small>}
                    {e.lastError && <small className="error"> {e.lastError}</small>}
                  </td>
                  <td>{e.to}</td>
                  <td>{new Date(e.createdAt).toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
