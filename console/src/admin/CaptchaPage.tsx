/**
 * Admin → Bot check (`/admin/captcha`; contract/kit-api.yaml getCaptchaSettings, saveCaptchaSettings): the provider (or
 * none), its site key, its secret (never shown; left empty, the saved one is kept), and the public forms it guards —
 * the backend checks a new secret with the provider before saving and answers why it was refused.
 *
 * Not here: the widget on the forms (setup/Captcha.tsx).
 */
import { useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type CaptchaSettings } from "../api";
import { t } from "../text";

export function CaptchaPage() {
  const [settings, setSettings] = useState<CaptchaSettings | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    kitApi.captchaSettings().then(setSettings, (err) => setFailure(err instanceof Error ? err.message : String(err)));
  }, []);

  return (
    <section aria-labelledby="captcha-title">
      <h1 id="captcha-title">{t("captchaTitle")}</h1>
      <p className="muted">{t("captchaHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {settings && <CaptchaForm settings={settings} onSaved={setSettings} />}
    </section>
  );
}

function CaptchaForm({ settings, onSaved }: { settings: CaptchaSettings; onSaved: (s: CaptchaSettings) => void }) {
  const [provider, setProvider] = useState(settings.provider ?? "");
  const [siteKey, setSiteKey] = useState(settings.siteKey ?? "");
  const [secret, setSecret] = useState("");
  const [forms, setForms] = useState<string[]>(settings.forms);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const toggle = (value: string) => setForms((l) => (l.includes(value) ? l.filter((v) => v !== value) : [...l, value]));

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setSaved(false);
    try {
      onSaved(await kitApi.saveCaptchaSettings({ provider: provider || null, siteKey: provider ? siteKey.trim() : null, secret: secret.trim(), forms }));
      setSecret("");
      setSaved(true);
    } catch (err) {
      setErrors(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : { provider: [err instanceof Error ? err.message : String(err)] });
    } finally {
      setBusy(false);
    }
  }

  const said = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("captchaTitle")}>
      <div className="field">
        <label htmlFor="captcha-provider">{t("captchaProvider")}</label>
        <select id="captcha-provider" value={provider} onChange={(e) => setProvider(e.target.value)}>
          <option value="">{t("captchaNone")}</option>
          {settings.providers.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
        </select>
        {said("provider")}
      </div>
      {provider && (
        <>
          <div className="field">
            <label htmlFor="captcha-site-key">{t("captchaSiteKey")}</label>
            <input id="captcha-site-key" value={siteKey} onChange={(e) => setSiteKey(e.target.value)} spellCheck={false} />
            {said("siteKey")}
          </div>
          <div className="field">
            <label htmlFor="captcha-secret">{t("captchaSecret")}</label>
            {settings.secretSet && <small className="muted">{t("secretKept")}</small>}
            <input id="captcha-secret" type="password" autoComplete="new-password" value={secret} onChange={(e) => setSecret(e.target.value)} spellCheck={false} />
            {said("secret")}
          </div>
          <fieldset className="field">
            <legend>{t("captchaForms")}</legend>
            {settings.formChoices.map((f) => (
              <label key={f.value} className="checkbox">
                <input type="checkbox" checked={forms.includes(f.value)} onChange={() => toggle(f.value)} />
                {f.label}
              </label>
            ))}
            {said("forms")}
          </fieldset>
        </>
      )}
      {saved && <p role="status">{t("siteSaved")}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveSite")}</button>
    </form>
  );
}
