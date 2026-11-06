/**
 * Admin → Sign-in providers (`/admin/sso`; contract/kit-api.yaml getSsoSettings, addSsoProvider, updateSsoProvider,
 * deleteSsoProvider, orderSsoProviders): the OpenID Connect providers customers may sign in with, in the order the
 * sign-in page shows them, and the redirect address to register at each. A provider is a preset (Google, Microsoft: what it needs set up there is said under it) or any issuer;
 * the backend reads its configuration before saving and answers why it could not. A client secret is never shown and
 * is kept when left empty. Enabled or not, moved up, removed only after asking.
 *
 * Not here: the buttons on the sign-in page (setup/SignIn.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type SsoProviderInput, type SsoSettings } from "../api";
import { t, type TextKey } from "../text";

type Provider = SsoSettings["providers"][number];

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function SsoPage() {
  const [settings, setSettings] = useState<SsoSettings | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setSettings(await kitApi.ssoSettings());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="sso-title">
      <h1 id="sso-title">{t("ssoTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {settings && (
        <>
          <p className="muted">{t("ssoHelp")} <code className="wrap-anywhere" data-testid="redirect-uri">{settings.redirectUri}</code></p>
          <Providers settings={settings} onChanged={setSettings} onReload={load} />
          {settings.providers.length >= settings.maxProviders
            ? <p className="muted">{t("ssoProvidersFull", { max: String(settings.maxProviders) })}</p>
            : <AddProvider settings={settings} onAdded={setSettings} />}
        </>
      )}
    </section>
  );
}

function ProviderForm({ id, settings, provider, label, onSubmit }: {
  id: string; settings: SsoSettings; provider?: Provider; label: TextKey; onSubmit: (input: SsoProviderInput) => Promise<void>;
}) {
  const [preset, setPreset] = useState(provider ? (provider.preset ?? "") : (settings.presets[0]?.value ?? ""));
  const [issuer, setIssuer] = useState(provider && provider.preset === null ? provider.issuer : "");
  const [name, setName] = useState(provider?.name ?? settings.presets[0]?.label ?? "");
  const [clientId, setClientId] = useState(provider?.clientId ?? "");
  const [secret, setSecret] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const presetLabel = (value: string) => settings.presets.find((p) => p.value === value)?.label ?? "";
  const help = settings.presets.find((p) => p.value === preset)?.help;

  /** A preset's name comes with it, until the operator gives their own. */
  function choose(value: string) {
    if (name === presetLabel(preset)) setName(presetLabel(value));
    setPreset(value);
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      await onSubmit({ preset: preset || null, issuer: preset ? null : issuer.trim(), name: name.trim(), clientId: clientId.trim(), clientSecret: secret.trim() });
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const said = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  const input = (key: string, labelKey: TextKey, value: string, set: (v: string) => void, type = "text") => (
    <div className="field">
      <label htmlFor={`${id}-${key}`}>{t(labelKey)}</label>
      {key === "clientSecret" && provider?.secretSet && <small className="muted">{t("secretKept")}</small>}
      <input id={`${id}-${key}`} type={type} value={value} onChange={(e) => set(e.target.value)} spellCheck={false}
        autoComplete={type === "password" ? "new-password" : undefined} aria-invalid={errors[key] ? true : undefined} />
      {said(key)}
    </div>
  );

  return (
    <form onSubmit={submit} noValidate aria-label={t(label)}>
      <div className="field">
        <label htmlFor={`${id}-preset`}>{t("ssoPreset")}</label>
        <select id={`${id}-preset`} value={preset} onChange={(e) => choose(e.target.value)}>
          {settings.presets.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
          <option value="">{t("ssoOther")}</option>
        </select>
        {help && <small className="muted">{help}</small>}
        {said("preset")}
      </div>
      {!preset && input("issuer", "ssoIssuer", issuer, setIssuer, "url")}
      {input("name", "ssoName", name, setName)}
      {input("clientId", "ssoClientId", clientId, setClientId)}
      {input("clientSecret", "ssoClientSecret", secret, setSecret, "password")}
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t(label)}</button>
    </form>
  );
}

function Providers({ settings, onChanged, onReload }: { settings: SsoSettings; onChanged: (s: SsoSettings) => void; onReload: () => void }) {
  const [editing, setEditing] = useState<string | null>(null);
  const [asking, setAsking] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const ids = settings.providers.map((p) => p.id);

  async function act(run: () => Promise<SsoSettings | void>) {
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

  const as = (p: Provider, over: Partial<SsoProviderInput>): SsoProviderInput =>
    ({ preset: p.preset, issuer: p.preset ? null : p.issuer, name: p.name, clientId: p.clientId, clientSecret: "", enabled: p.enabled, ...over });

  return (
    <>
      {settings.providers.length === 0 && <p className="muted">{t("ssoEmpty")}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      <ol className="senders">
        {settings.providers.map((p, i) => (
          <li key={p.id} data-provider={p.id}>
            <div className="actions">
              <span><strong>{p.name}</strong> · <span className="wrap-anywhere">{p.issuer}</span> · {t(p.enabled ? "ssoOn" : "ssoOff")}</span>
              <button type="button" className="secondary" onClick={() => act(() => kitApi.updateSsoProvider(p.id, as(p, { enabled: !p.enabled })))}>
                {t(p.enabled ? "disableSso" : "enableSso")}
              </button>
              {i > 0 && (
                <button type="button" className="secondary" onClick={() => act(() => kitApi.orderSsoProviders([...ids.slice(0, i - 1), p.id, ids[i - 1], ...ids.slice(i + 1)]))}>
                  {t("moveUp")}
                </button>
              )}
              <button type="button" className="secondary" onClick={() => setEditing(editing === p.id ? null : p.id)}>{t("edit")}</button>
              {asking === p.id ? (
                <>
                  <span>{t("confirmRemoveSso", { name: p.name })}</span>
                  <button type="button" onClick={() => act(() => kitApi.deleteSsoProvider(p.id))}>{t("yesRemove")}</button>
                  <button type="button" className="secondary" onClick={() => setAsking(null)}>{t("cancel")}</button>
                </>
              ) : (
                <button type="button" className="secondary" onClick={() => setAsking(p.id)}>{t("remove")}</button>
              )}
            </div>
            {editing === p.id && (
              <ProviderForm id={`sso-${p.id}`} settings={settings} provider={p} label="saveSso"
                onSubmit={async (input) => {
                  onChanged(await kitApi.updateSsoProvider(p.id, { ...input, enabled: p.enabled }));
                  setEditing(null);
                }} />
            )}
          </li>
        ))}
      </ol>
    </>
  );
}

function AddProvider({ settings, onAdded }: { settings: SsoSettings; onAdded: (s: SsoSettings) => void }) {
  // Bumped after each provider is added, so the form starts empty again (no secret left in the page).
  const [added, setAdded] = useState(0);
  return (
    <div className="agent-form">
      <h2>{t("addSsoTitle")}</h2>
      <p className="muted">{t("addSsoHelp")}</p>
      <ProviderForm key={added} id="new-sso" settings={settings} label="addSso"
        onSubmit={async (input) => {
          onAdded(await kitApi.addSsoProvider(input));
          setAdded((n) => n + 1);
        }} />
    </div>
  );
}
