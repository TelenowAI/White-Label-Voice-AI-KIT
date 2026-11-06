/**
 * Admin → Catalog (`/admin/catalog`; contract/kit-api.yaml getCatalogCuration, saveCatalogCuration, refreshCatalog,
 * listCatalogVoices): the
 * kit's copy of the voice platform's catalog — when it was read, why the last read failed, and reading it again — and
 * the operator's choices: which language model, speech recognition and voice providers customers may pick (and which
 * language models), under which names (a name that carries the platform's own is flagged and cannot be offered), and
 * what a new agent starts with, beside the platform's own recommendation. Saved all at once; the backend's word on a
 * field shows at that field. Also what customers may use: transfers to a person, calls from the browser (with the plans
 * that have no price for one), whether every customer's calls need consent, and whether customers may clone voices of
 * their own, with how many clones an account may have; and which realtime voices customers may pick (RealtimeCuration.tsx).
 *
 * Not here: what customers see (the console's Voices page and an agent's voice setup).
 */
import { useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type CatalogCuration, type CatalogKind, type CurationInput, type StackIds } from "../api";
import { t, type TextKey } from "../text";
import { VoicePicker } from "../voices/VoicePicker";
import { RealtimeCuration, realtimeBody, realtimeFormOf, type RealtimeForm } from "./RealtimeCuration";

type SettingChoice = { offered: boolean; options: Record<string, boolean> };
type Choice = { offered: boolean; name: string; models: Record<string, { offered: boolean; name: string }>; settings: Record<string, SettingChoice> };

/** How the backend names an option in the operator's choices: its value, as text. */
const optionKey = (v: unknown) => (typeof v === "string" ? v : JSON.stringify(v));
type Form = Record<CatalogKind, Record<string, Choice>>;

const kinds: { kind: CatalogKind; title: TextKey }[] = [
  { kind: "llm", title: "kindLlm" },
  { kind: "stt", title: "kindStt" },
  { kind: "tts", title: "kindTts" },
];

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function formOf(c: CatalogCuration): Form {
  const out = {} as Form;
  for (const { kind } of kinds) {
    out[kind] = Object.fromEntries(c.kinds[kind].map((p) => [p.id, {
      offered: p.offered,
      name: p.ownName ?? "",
      models: Object.fromEntries(p.models.map((m) => [m.id, { offered: m.offered, name: m.ownName ?? "" }])),
      settings: Object.fromEntries((p.settings ?? []).map((f) => [f.key, { offered: f.offered, options: Object.fromEntries((f.options ?? []).map((o) => [optionKey(o.value), o.offered])) }])),
    }]));
  }
  return out;
}

export function Catalog() {
  const [data, setData] = useState<CatalogCuration | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [defaults, setDefaults] = useState<StackIds | null>(null);
  const [transferAllowed, setTransferAllowed] = useState(false);
  const [consentRequired, setConsentRequired] = useState(false);
  const [browserCallsAllowed, setBrowserCallsAllowed] = useState(false);
  const [cloningAllowed, setCloningAllowed] = useState(false);
  const [clonesPerAccount, setClonesPerAccount] = useState("");
  const [realtime, setRealtime] = useState<RealtimeForm>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<"read" | "save" | null>(null);

  function take(c: CatalogCuration) {
    setData(c);
    setForm(formOf(c));
    setDefaults(c.defaults);
    setTransferAllowed(c.transferAllowed);
    setConsentRequired(c.consentRequired);
    setBrowserCallsAllowed(c.browserCallsAllowed);
    setCloningAllowed(c.cloningAllowed);
    setClonesPerAccount(String(c.clonesPerAccount));
    setRealtime(realtimeFormOf(c));
  }

  useEffect(() => {
    kitApi.catalogCuration().then(take, (err) => setFailure(message(err)));
  }, []);

  async function readAgain() {
    setBusy("read");
    setFailure(null);
    try {
      take(await kitApi.refreshCatalog());
    } catch (err) {
      setFailure(message(err));
      kitApi.catalogCuration().then(setData, () => undefined);
    } finally {
      setBusy(null);
    }
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    if (!form || !defaults) return;
    setBusy("save");
    setErrors({});
    setFailure(null);
    setNotice(null);
    const body: CurationInput = { kinds: {}, defaults, transferAllowed, browserCallsAllowed, consentRequired, cloningAllowed, clonesPerAccount: Number(clonesPerAccount), realtime: realtimeBody(realtime) };
    for (const { kind } of kinds) {
      body.kinds[kind] = Object.fromEntries(Object.entries(form[kind]).map(([id, c]) => [id, {
        offered: c.offered,
        name: c.name.trim() || null,
        ...(kind === "llm" ? { models: Object.fromEntries(Object.entries(c.models).map(([m, mc]) => [m, { offered: mc.offered, name: mc.name.trim() || null }])) } : {}),
        settings: c.settings,
      }]));
    }
    try {
      take(await kitApi.saveCatalogCuration(body));
      setNotice(t("catalogSaved"));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(null);
    }
  }

  const choose = (kind: CatalogKind, id: string, change: Partial<Choice>) =>
    setForm((f) => f && { ...f, [kind]: { ...f[kind], [id]: { ...f[kind][id], ...change } } });
  const chooseModel = (id: string, model: string, change: Partial<{ offered: boolean; name: string }>) =>
    setForm((f) => f && { ...f, llm: { ...f.llm, [id]: { ...f.llm[id], models: { ...f.llm[id].models, [model]: { ...f.llm[id].models[model], ...change } } } } });
  const chooseSetting = (kind: CatalogKind, id: string, key: string, change: Partial<SettingChoice>) =>
    setForm((f) => f && { ...f, [kind]: { ...f[kind], [id]: { ...f[kind][id], settings: { ...f[kind][id].settings, [key]: { ...f[kind][id].settings[key], ...change } } } } });
  const error = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;

  return (
    <section aria-labelledby="catalog-title">
      <h1 id="catalog-title">{t("catalogTitle")}</h1>
      <p className="muted">{t("catalogHelp")}</p>
      {data && (
        <p>
          {data.fetchedAt ? t("catalogReadAt", { when: new Date(data.fetchedAt).toLocaleString() }) : t("catalogNeverRead")}{" "}
          <button type="button" className="secondary" disabled={busy !== null} onClick={readAgain}>
            {busy === "read" ? t("catalogReading") : t("catalogReadAgain")}
          </button>
        </p>
      )}
      {data?.error && <p role="status" className="error">{t("catalogLastError", { message: data.error.message })}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      {notice && <p role="status">{notice}</p>}
      {data && form && defaults && data.fetchedAt && (
        <form onSubmit={save} noValidate aria-label={t("catalogTitle")}>
          {kinds.map(({ kind, title }) => (
            <fieldset key={kind}>
              <legend>{t(title)}</legend>
              <div className="table-scroll">
                <table className="list">
                  <thead>
                    <tr>
                      <th scope="col">{t("colOffered")}</th>
                      <th scope="col">{t("colProvider")}</th>
                      <th scope="col">{t("colCustomerName")}</th>
                      {kind !== "stt" && <th scope="col">{kind === "llm" ? t("colModels") : t("voicesTitle")}</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {data.kinds[kind].map((p) => {
                      const c = form[kind][p.id];
                      const shownName = c.name.trim() || p.catalogName;
                      const carries = shownName.toLowerCase().includes(data.platformName.toLowerCase());
                      return (
                        <tr key={p.id}>
                          <td>
                            <input type="checkbox" aria-label={t("offerName", { name: p.catalogName })} checked={c.offered} disabled={!p.enabled}
                              onChange={(e) => choose(kind, p.id, { offered: e.target.checked })} />
                          </td>
                          <td>
                            {p.catalogName}
                            {!p.enabled && <small className="muted"> · {t("catalogNotAvailable")}</small>}
                            {p.status && p.status !== "ga" && <small className="muted"> · {p.status}</small>}
                          </td>
                          <td>
                            <input aria-label={t("customerNameFor", { name: p.catalogName })} value={c.name} placeholder={p.catalogName}
                              onChange={(e) => choose(kind, p.id, { name: e.target.value })} />
                            {carries && <small className="error">{t("catalogNamesPlatform", { platform: data.platformName })}</small>}
                            {error(`kinds.${kind}.${p.id}.name`)}
                          </td>
                          {kind === "llm" && (
                            <td>
                              {p.models.map((m) => (
                                <div key={m.id} className="model-choice">
                                  <input type="checkbox" aria-label={t("offerName", { name: m.catalogName })} checked={c.models[m.id]?.offered ?? true}
                                    onChange={(e) => chooseModel(p.id, m.id, { offered: e.target.checked })} />
                                  <input aria-label={t("customerNameFor", { name: m.catalogName })} value={c.models[m.id]?.name ?? ""} placeholder={m.catalogName}
                                    onChange={(e) => chooseModel(p.id, m.id, { name: e.target.value })} />
                                  {error(`kinds.llm.${p.id}.models.${m.id}.name`)}
                                </div>
                              ))}
                            </td>
                          )}
                          {kind === "tts" && <td>{t("catalogVoicesCount", { count: String(p.voiceCount) })}</td>}
                          {(p.settings ?? []).length > 0 && (
                            <td>
                              <details>
                                <summary>{t("catalogSettingsTitle")}</summary>
                                <small className="muted">{t("catalogSettingsHelp")}</small>
                                {p.settings.map((f) => (
                                  <div key={f.key} className="setting-choice">
                                    <label className="checkbox">
                                      <input type="checkbox" checked={c.settings[f.key]?.offered ?? f.offered} onChange={(e) => chooseSetting(kind, p.id, f.key, { offered: e.target.checked })} />
                                      {f.label}{f.model ? ` · ${t("colModels")}` : ""}
                                    </label>
                                    {f.options && f.options.length > 0 && (
                                      <div role="group" aria-label={t("catalogSettingOptions", { setting: f.label })}>
                                        {f.options.map((o) => (
                                          <label key={optionKey(o.value)} className="checkbox option">
                                            <input type="checkbox" checked={c.settings[f.key]?.options[optionKey(o.value)] ?? o.offered}
                                              onChange={(e) => chooseSetting(kind, p.id, f.key, { options: { ...c.settings[f.key]?.options, [optionKey(o.value)]: e.target.checked } })} />
                                            {o.label}
                                          </label>
                                        ))}
                                      </div>
                                    )}
                                    {error(`kinds.${kind}.${p.id}.settings.${f.key}`)}
                                  </div>
                                ))}
                              </details>
                            </td>
                          )}
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </fieldset>
          ))}
          <Defaults data={data} form={form} defaults={defaults} onChange={setDefaults} errors={errors} />
          <RealtimeCuration data={data} form={realtime} onChange={setRealtime} errors={errors} />
          <fieldset>
            <legend>{t("catalogTransfers")}</legend>
            <label className="checkbox">
              <input type="checkbox" checked={transferAllowed} onChange={(e) => setTransferAllowed(e.target.checked)} />
              {t("catalogTransfersAllowed")}
            </label>
            <small className="muted">{t("catalogTransfersHelp")}</small>
            {!data.transferAllowed && data.transferStillOn > 0 && <p className="note">{t("catalogTransfersStillOn", { n: String(data.transferStillOn) })}</p>}
          </fieldset>
          <fieldset>
            <legend>{t("catalogBrowserCalls")}</legend>
            <label className="checkbox">
              <input type="checkbox" checked={browserCallsAllowed} onChange={(e) => setBrowserCallsAllowed(e.target.checked)} />
              {t("catalogBrowserCallsAllowed")}
            </label>
            <small className="muted">{t("catalogBrowserCallsHelp")}</small>
            {data.browserCallsUnpriced.length > 0 && (
              <p className="note">{t("catalogBrowserCallsUnpriced", { plans: data.browserCallsUnpriced.map((p) => p.name).join(", ") })}</p>
            )}
          </fieldset>
          <fieldset>
            <legend>{t("catalogConsent")}</legend>
            <label className="checkbox">
              <input type="checkbox" checked={consentRequired} onChange={(e) => setConsentRequired(e.target.checked)} />
              {t("catalogConsentRequired")}
            </label>
            <small className="muted">{t("catalogConsentHelp")}</small>
            {data.consentRequired && data.consentStillToTell > 0 && <p className="note">{t("catalogConsentStillToTell", { n: String(data.consentStillToTell) })}</p>}
          </fieldset>
          <fieldset>
            <legend>{t("catalogCloning")}</legend>
            <label className="checkbox">
              <input type="checkbox" checked={cloningAllowed} onChange={(e) => setCloningAllowed(e.target.checked)} />
              {t("catalogCloningAllowed")}
            </label>
            <small className="muted">{t("catalogCloningHelp")}</small>
            <div className="field">
              <label htmlFor="catalog-clones">{t("catalogClonesPerAccount")}</label>
              <input id="catalog-clones" type="number" inputMode="numeric" min={1} max={data.clonesMost} value={clonesPerAccount}
                onChange={(e) => setClonesPerAccount(e.target.value)} aria-invalid={errors.clonesPerAccount ? true : undefined} />
              <small className="muted">{t("catalogClonesMost", { n: String(data.clonesMost) })}</small>
              {errors.clonesPerAccount && <small className="error">{errors.clonesPerAccount.join(" ")}</small>}
            </div>
          </fieldset>
          <button type="submit" disabled={busy !== null}>{busy === "save" ? t("saving") : t("saveCatalog")}</button>
        </form>
      )}
    </section>
  );
}

/** The new-agent defaults, chosen among the providers ticked above (as the form stands). */
function Defaults({ data, form, defaults, onChange, errors }: {
  data: CatalogCuration; form: Form; defaults: StackIds; onChange: (d: StackIds) => void; errors: Record<string, string[]>;
}) {
  const offered = (kind: CatalogKind) => data.kinds[kind].filter((p) => form[kind][p.id]?.offered);
  const llm = data.kinds.llm.find((p) => p.id === defaults.llmProvider);
  const set = (key: keyof StackIds, also: Partial<StackIds> = {}) => (e: { target: { value: string } }) => onChange({ ...defaults, ...also, [key]: e.target.value || null });
  const select = (key: keyof StackIds, label: TextKey, options: { id: string; name: string }[], onSet = set(key)) => (
    <div className="field">
      <label htmlFor={`default-${key}`}>{t(label)}</label>
      <select id={`default-${key}`} value={defaults[key] ?? ""} onChange={onSet}>
        <option value="">{t("noneOption")}</option>
        {defaults[key] && !options.some((o) => o.id === defaults[key]) && <option value={defaults[key]!}>{defaults[key]}</option>}
        {options.map((o) => (
          <option key={o.id} value={o.id}>{o.name}</option>
        ))}
      </select>
      {errors[`defaults.${key}`] && <small className="error">{errors[`defaults.${key}`].join(" ")}</small>}
    </div>
  );
  const named = (kind: CatalogKind) => offered(kind).map((p) => ({ id: p.id, name: form[kind][p.id].name.trim() || p.catalogName }));

  return (
    <fieldset>
      <legend>{t("defaultsTitle")}</legend>
      {select("llmProvider", "stackLlm", named("llm"), set("llmProvider", { llmModel: null }))}
      {select("llmModel", "stackLlmModel", (llm?.models ?? []).filter((m) => form.llm[llm!.id]?.models[m.id]?.offered ?? true)
        .map((m) => ({ id: m.id, name: form.llm[llm!.id]?.models[m.id]?.name.trim() || m.catalogName })))}
      {select("sttProvider", "stackStt", named("stt"))}
      {select("ttsProvider", "stackTtsProvider", named("tts"), set("ttsProvider", { ttsVoice: null }))}
      <VoicePicker id="default-ttsVoice" label={t("stackTts")} emptyLabel={t("noneOption")} load={kitApi.catalogVoices}
        provider={defaults.ttsProvider} value={defaults.ttsVoice} savedLabel={defaults.ttsVoice && (data.voiceNames[defaults.ttsVoice] ?? defaults.ttsVoice)}
        onChange={(voice) => onChange({ ...defaults, ttsVoice: voice })} error={errors["defaults.ttsVoice"]} />
      <button type="button" className="secondary" onClick={() => onChange({ ...data.recommended })}>{t("useRecommended")}</button>
    </fieldset>
  );
}
