/**
 * Admin → SMS (`/admin/sms`; contract/kit-api.yaml getSmsSettings, addSmsSender, updateSmsSender, deleteSmsSender,
 * orderSmsSenders, sendTestSms): whether the platform can send texts; the senders in the order they are tried — each
 * form drawn from its driver's own fields (setup/StepForm.tsx FieldInput), a secret never shown and kept when left
 * empty — added or changed only once the backend has texted the test number given, moved up, removed only after
 * asking; a test through the whole line; and the recent texts with how each went (never their words).
 *
 * Not here: who gets texts (sign-in codes: setup/SignInWithCode.tsx; numbers: account/AccountPage.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type OutgoingSms, type SetupField, type SmsSettings } from "../api";
import { FieldInput } from "../setup/StepForm";
import { t, type TextKey } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function startValues(fields: SetupField[]): Record<string, string> {
  return Object.fromEntries(fields.map((f) => [f.name, f.value ?? (f.type === "select" ? (f.options[0]?.value ?? "") : "")]));
}

/** Only the fields shown (a field with `showWhen` is sent only while the other field has one of its values). */
function shown(fields: SetupField[], values: Record<string, string>): SetupField[] {
  return fields.filter((f) => !f.showWhen || f.showWhen.values.includes(values[f.showWhen.field] ?? ""));
}

export function SmsPage() {
  const [settings, setSettings] = useState<SmsSettings | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setSettings(await kitApi.smsSettings());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="sms-title">
      <h1 id="sms-title">{t("smsTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {settings && (
        <>
          <p role="status" className={settings.on ? "muted" : "note"}>{t(settings.on ? "smsOn" : "smsOff")}</p>
          <Senders settings={settings} onChanged={setSettings} onReload={load} />
          <AddSender settings={settings} onAdded={setSettings} />
          <TestSms on={settings.on} onSent={load} />
          <Recent recent={settings.recent} />
        </>
      )}
    </section>
  );
}

function SenderForm({ id, fields, label, onSubmit }: {
  id: string; fields: SetupField[]; label: TextKey; onSubmit: (values: Record<string, string>, testNumber: string) => Promise<void>;
}) {
  const [values, setValues] = useState(() => startValues(fields));
  const [testNumber, setTestNumber] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const visible = shown(fields, values);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      await onSubmit(Object.fromEntries(visible.map((f) => [f.name, values[f.name] ?? ""])), testNumber.trim());
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} noValidate aria-label={t(label)}>
      {visible.map((f) => (
        <FieldInput key={f.name} stepId={id} field={f} value={values[f.name] ?? ""} errors={errors[f.name] ?? []}
          onChange={(v) => setValues((old) => ({ ...old, [f.name]: v }))} />
      ))}
      <div className="field">
        <label htmlFor={`${id}-test-number`}>{t("smsTestNumber")}</label>
        <small className="muted">{t("smsTestNumberHelp")}</small>
        <input id={`${id}-test-number`} type="tel" value={testNumber} onChange={(e) => setTestNumber(e.target.value)}
          aria-invalid={errors.testNumber ? true : undefined} />
        {errors.testNumber && <small className="error">{errors.testNumber.join(" ")}</small>}
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("sendingTestSms") : t(label)}</button>
    </form>
  );
}

function Senders({ settings, onChanged, onReload }: { settings: SmsSettings; onChanged: (s: SmsSettings) => void; onReload: () => void }) {
  const [editing, setEditing] = useState<string | null>(null);
  const [asking, setAsking] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const ids = settings.senders.map((s) => s.id);

  async function act(run: () => Promise<SmsSettings | void>) {
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
                <button type="button" className="secondary" onClick={() => act(() => kitApi.orderSmsSenders([...ids.slice(0, i - 1), s.id, ids[i - 1], ...ids.slice(i + 1)]))}>
                  {t("moveUp")}
                </button>
              )}
              <button type="button" className="secondary" onClick={() => setEditing(editing === s.id ? null : s.id)}>{t("edit")}</button>
              {asking === s.id ? (
                <>
                  <span>{t("confirmRemoveSender", { label: s.label })}</span>
                  <button type="button" onClick={() => act(() => kitApi.deleteSmsSender(s.id))}>{t("yesDelete")}</button>
                  <button type="button" className="secondary" onClick={() => setAsking(null)}>{t("cancel")}</button>
                </>
              ) : (
                <button type="button" className="secondary" onClick={() => setAsking(s.id)}>{t("remove")}</button>
              )}
            </div>
            {editing === s.id && (
              <SenderForm id={`sms-sender-${s.id}`} fields={s.fields} label="saveSmsSender"
                onSubmit={async (values, testNumber) => {
                  onChanged(await kitApi.updateSmsSender(s.id, values, testNumber));
                  setEditing(null);
                }} />
            )}
          </li>
        ))}
      </ol>
    </>
  );
}

function AddSender({ settings, onAdded }: { settings: SmsSettings; onAdded: (s: SmsSettings) => void }) {
  const [driver, setDriver] = useState(settings.drivers[0]?.id ?? "");
  // Bumped after each sender is added, so the form starts empty again (no key left in the page).
  const [added, setAdded] = useState(0);
  const chosen = settings.drivers.find((d) => d.id === driver);
  if (settings.senders.length >= settings.maxSenders) return <p className="muted">{t("sendersFull", { max: String(settings.maxSenders) })}</p>;

  return (
    <div className="agent-form">
      <h2>{t("addSenderTitle")}</h2>
      <p className="muted">{t("addSmsSenderHelp")}</p>
      <div className="field">
        <label htmlFor="sms-driver">{t("sendWith")}</label>
        <select id="sms-driver" value={driver} onChange={(e) => setDriver(e.target.value)}>
          {settings.drivers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      </div>
      {chosen && (
        <SenderForm key={`${chosen.id}-${added}`} id={`new-sms-${chosen.id}`} fields={chosen.fields} label="addSmsSender"
          onSubmit={async (values, testNumber) => {
            onAdded(await kitApi.addSmsSender(chosen.id, values, testNumber));
            setAdded((n) => n + 1);
          }} />
      )}
    </div>
  );
}

function TestSms({ on, onSent }: { on: boolean; onSent: () => void }) {
  const [number, setNumber] = useState("");
  const [result, setResult] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  if (!on) return null;

  async function send(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setResult(null);
    try {
      const s = await kitApi.sendTestSms(number.trim());
      setResult(s.status === "sent" ? t("smsTestSent", { to: s.to }) : t("smsTestFailed", { error: s.lastError ?? "" }));
      onSent();
    } catch (err) {
      setResult(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? Object.values(err.fields).flat().join(" ") : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="actions" onSubmit={send} noValidate aria-label={t("testSms")}>
      <label htmlFor="sms-test-number">{t("smsTestNumber")}</label>
      <input id="sms-test-number" type="tel" value={number} onChange={(e) => setNumber(e.target.value)} />
      <button type="submit" disabled={busy}>{busy ? t("sendingTestSms") : t("testSms")}</button>
      {result && <span role="status">{result}</span>}
    </form>
  );
}

const statuses: Record<OutgoingSms["status"], TextKey> = { sent: "emailSent", failed: "smsNotSent" };

function Recent({ recent }: { recent: OutgoingSms[] }) {
  return (
    <>
      <h2>{t("recentSmsTitle")}</h2>
      {recent.length === 0 && <p className="muted">{t("recentSmsEmpty")}</p>}
      {recent.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("recentSmsTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("colKind")}</th>
                <th scope="col">{t("colStatus")}</th>
                <th scope="col">{t("colTo")}</th>
                <th scope="col">{t("colWhen")}</th>
              </tr>
            </thead>
            <tbody>
              {recent.map((s) => (
                <tr key={s.id}>
                  <td>{s.kindName}</td>
                  <td>
                    {t(statuses[s.status])}
                    {s.attempts > 1 && <small className="muted"> · {t("sendersTried", { n: String(s.attempts) })}</small>}
                    {s.lastError && <small className="error"> {s.lastError}</small>}
                  </td>
                  <td>{s.to}</td>
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
