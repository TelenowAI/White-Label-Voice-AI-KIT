/**
 * Console → Voices → Your voices (contract/kit-api.yaml listOwnVoices, createOwnVoice, uploadOwnVoiceSample, cloneOwnVoice, removeOwnVoiceClone, deleteOwnVoice, previewVoice): voices of
 * real people who agreed to have their voice cloned. Every member sees them and hears their clones; the account's owner adds one (the person named, how they relate to the account,
 * and their signed release confirmed), records them here or uploads a file, clones the voice into a provider that can, and removes a clone or deletes the voice — never one an agent
 * speaks with (the backend names those agents). Shown only once the operator allows cloning, or while the account has such voices.
 *
 * Not here: recording in the browser (Recorder.tsx), putting one on an agent (agents/StackForm.tsx with voices/VoicePicker.tsx), the provider voices (VoicesPage.tsx).
 */
import { useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type OwnVoice, type OwnVoiceInput, type OwnVoicesPage, type VoiceRelation } from "../api";
import { t, type TextKey } from "../text";
import { ClipButton } from "./ClipButton";
import { Recorder, type Take } from "./Recorder";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

const relationText: Record<VoiceRelation, TextKey> = {
  employee: "relation_employee",
  voice_actor: "relation_voice_actor",
  customer: "relation_customer",
  other: "relation_other",
};

export function OwnVoices() {
  const [page, setPage] = useState<OwnVoicesPage | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  /** The voice whose recording step is open: one just added opens on it. */
  const [recording, setRecording] = useState<string | null>(null);

  const load = () => kitApi.ownVoices().then(setPage, (err) => setFailure(message(err)));
  useEffect(() => {
    void load();
  }, []);

  if (failure && !page) return <p role="alert" className="error">{failure}</p>;
  if (!page || (!page.allowed && page.voices.length === 0)) return null;
  const canMake = page.allowed && page.manages && page.paused === null;
  const changed = (v: OwnVoice) => setPage((p) => p && { ...p, voices: p.voices.map((x) => (x.id === v.id ? v : x)) });

  return (
    <section aria-labelledby="own-voices-title" className="own-voices">
      <h2 id="own-voices-title">{t("ownVoicesTitle")}</h2>
      <p className="muted">{t("ownVoicesHelp")}</p>
      {page.paused && <p role="status" className="note">{page.paused}</p>}
      {!page.allowed && <p className="note">{t("ownVoicesNotAllowed")}</p>}
      {page.allowed && !page.manages && <p className="muted">{t("ownVoicesManagersOnly")}</p>}
      <p className="muted">{t("ownVoicesUsage", { used: String(page.clones.used), limit: String(page.clones.limit) })}</p>
      {page.voices.length === 0 ? (
        <p className="muted">{t("ownVoicesNone")}</p>
      ) : (
        <ul className="plain own-voice-list">
          {page.voices.map((v) => (
            <li key={v.id}>
              <OwnVoiceItem voice={v} page={page} canMake={canMake} recordingOpen={recording === v.id} onRecordingOpen={(open) => setRecording(open ? v.id : null)}
                onChanged={(n) => { changed(n); void load(); }} onDeleted={() => void load()} />
            </li>
          ))}
        </ul>
      )}
      {canMake && <NewVoiceForm page={page} onMade={(v) => { setPage((p) => p && { ...p, voices: [...p.voices, v] }); setRecording(v.id); }} />}
    </section>
  );
}

function OwnVoiceItem({ voice, page, canMake, recordingOpen, onRecordingOpen, onChanged, onDeleted }: {
  voice: OwnVoice;
  page: OwnVoicesPage;
  canMake: boolean;
  recordingOpen: boolean;
  onRecordingOpen: (open: boolean) => void;
  onChanged: (v: OwnVoice) => void;
  onDeleted: () => void;
}) {
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [target, setTarget] = useState("");
  const [asking, setAsking] = useState<{ kind: "remove"; provider: string; name: string } | { kind: "delete" } | null>(null);
  const cloned = new Set(voice.clones.map((c) => c.provider));
  const targets = page.targets.filter((p) => !cloned.has(p.key));
  const title = `own-voice-${voice.id}`;

  async function act(send: () => Promise<OwnVoice | void>, after: (v: OwnVoice | void) => void) {
    setBusy(true);
    setFailure(null);
    try {
      after(await send());
      setAsking(null);
    } catch (err) {
      setFailure(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? Object.values(err.fields).flat().join(" ") : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <article aria-labelledby={title} className="card">
      <h3 id={title}>{voice.name}</h3>
      {voice.personName && (
        <p className="muted">
          {voice.personName}{voice.relation ? ` · ${t(relationText[voice.relation])}` : ""}
          {voice.confirmedBy && voice.confirmedAt ? ` · ${t("ownVoiceConfirmedBy", { who: voice.confirmedBy, when: new Date(voice.confirmedAt).toLocaleDateString() })}` : ""}
        </p>
      )}
      <dl className="facts">
        <dt>{t("ownVoiceRecording")}</dt>
        <dd>{voice.hasSample ? (voice.sampleSeconds !== null ? t("ownVoiceSeconds", { seconds: String(Math.round(voice.sampleSeconds * 10) / 10) }) : t("ownVoiceRecordingAdded")) : t("ownVoiceNoRecording")}</dd>
        <dt>{t("ownVoiceClones")}</dt>
        <dd>
          {voice.clones.length === 0 ? t("ownVoiceNotCloned") : (
            <ul className="plain">
              {voice.clones.map((c) => {
                const provider = c.providerName ?? t("ownVoiceProviderGone");
                return (
                  <li key={c.provider}>
                    {provider}{c.status !== "ready" ? ` (${c.status})` : ""}{" "}
                    {c.status === "ready" && c.providerName && <ClipButton label={t("ownVoiceHear", { name: voice.name, provider })} load={() => kitApi.previewVoice(c.provider, voice.voice)} />}
                    {page.manages && (
                      <button type="button" className="link" disabled={busy} onClick={() => setAsking({ kind: "remove", provider: c.provider, name: provider })}>
                        {t("ownVoiceRemoveClone", { provider })}
                      </button>
                    )}
                  </li>
                );
              })}
            </ul>
          )}
        </dd>
        {voice.usedBy.length > 0 && (
          <>
            <dt>{t("ownVoiceUsedBy")}</dt>
            <dd>
              {voice.usedBy.map((a, i) => (
                <span key={a.id}>{i > 0 && ", "}<Link to={`/console/agents/${a.id}`}>{a.name}</Link></span>
              ))}
            </dd>
          </>
        )}
      </dl>
      {canMake && (
        recordingOpen ? (
          <SampleStep voice={voice} page={page} onDone={(v) => { onRecordingOpen(false); onChanged(v); }} onCancel={() => onRecordingOpen(false)} />
        ) : (
          <button type="button" className="secondary" onClick={() => onRecordingOpen(true)}>{voice.hasSample ? t("ownVoiceReplaceRecording") : t("ownVoiceAddRecording")}</button>
        )
      )}
      {canMake && voice.hasSample && (
        targets.length === 0 ? (
          voice.clones.length === 0 && <p className="muted">{t("ownVoiceNoTargets")}</p>
        ) : (
          <form className="inline-form" onSubmit={(e) => { e.preventDefault(); void act(() => kitApi.cloneOwnVoice(voice.id, target || targets[0].key), (v) => v && onChanged(v)); }}>
            <div className="field">
              <label htmlFor={`${title}-target`}>{t("ownVoiceCloneOn")}</label>
              <select id={`${title}-target`} value={target || targets[0].key} onChange={(e) => setTarget(e.target.value)}>
                {targets.map((p) => (
                  <option key={p.key} value={p.key}>{p.name}</option>
                ))}
              </select>
            </div>
            <button type="submit" disabled={busy}>{busy ? t("ownVoiceCloning") : t("ownVoiceClone")}</button>
          </form>
        )
      )}
      {page.manages && !asking && (
        <button type="button" className="link danger" disabled={busy} onClick={() => setAsking({ kind: "delete" })}>{t("ownVoiceDelete")}</button>
      )}
      {asking && (
        <div className="confirm" role="group">
          <span>{asking.kind === "delete" ? t("confirmDeleteOwnVoice", { name: voice.name }) : t("confirmRemoveClone", { name: voice.name, provider: asking.name })}</span>
          <button type="button" disabled={busy} onClick={() => void (asking.kind === "delete"
            ? act(() => kitApi.deleteOwnVoice(voice.id), () => onDeleted())
            : act(() => kitApi.removeOwnVoiceClone(voice.id, asking.provider), (v) => v && onChanged(v)))}>
            {asking.kind === "delete" ? t("yesDelete") : t("yesRemove")}
          </button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(null)}>{t("cancel")}</button>
        </div>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
    </article>
  );
}

/** Adding the recording: recorded here, or a file, as the voice was set up to be made. */
function SampleStep({ voice, page, onDone, onCancel }: { voice: OwnVoice; page: OwnVoicesPage; onDone: (v: OwnVoice) => void; onCancel: () => void }) {
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const min = page.limits.sampleMinSeconds;

  async function send(blob: Blob, filename: string, seconds: number | null) {
    setBusy(true);
    setFailure(null);
    try {
      onDone(await kitApi.uploadOwnVoiceSample(voice.id, blob, filename, seconds));
    } catch (err) {
      setFailure(err instanceof KitApiError && err.fields.file ? err.fields.file.join(" ") : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="sample-step">
      {voice.source === "record" ? (
        <>
          <p className="muted">{t("ownVoiceRecordHelp", { seconds: String(min) })}</p>
          <Recorder minSeconds={min} busy={busy} onUse={(take: Take) => void send(take.blob, take.filename, take.seconds)} />
        </>
      ) : (
        <form onSubmit={(e) => { e.preventDefault(); if (file) void send(file, file.name, null); }}>
          <p className="muted">{t("ownVoiceUploadHelp", { seconds: String(min), mb: String(Math.floor(page.limits.sampleMaxBytes / (1024 * 1024))), types: page.limits.sampleTypes.join(", ") })}</p>
          <label className="field">
            <span>{t("ownVoiceFile")}</span>
            <input type="file" accept={page.limits.sampleTypes.map((x) => `.${x}`).join(",")} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
          </label>
          <button type="submit" disabled={busy || file === null}>{busy ? t("ownVoiceUploading") : t("ownVoiceUpload")}</button>
        </form>
      )}
      <button type="button" className="link" onClick={onCancel}>{t("cancel")}</button>
      {failure && <p role="alert" className="error">{failure}</p>}
    </div>
  );
}

/** A new voice: the person named, how they relate to the account, and their signed release confirmed. */
function NewVoiceForm({ page, onMade }: { page: OwnVoicesPage; onMade: (v: OwnVoice) => void }) {
  const blank = (): OwnVoiceInput => ({ name: "", personName: "", relation: page.relations[0], confirmed: false, language: null, script: t("ownVoiceScriptDefault"), source: "record" });
  const [input, setInput] = useState<OwnVoiceInput>(blank);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = <K extends keyof OwnVoiceInput>(key: K, value: OwnVoiceInput[K]) => setInput((i) => ({ ...i, [key]: value }));
  const said = (k: string) => (errors[k] ? <small className="error" id={`own-voice-${k}-error`}>{errors[k].join(" ")}</small> : null);
  const invalid = (k: string) => ({ "aria-invalid": errors[k] ? true : undefined, "aria-describedby": errors[k] ? `own-voice-${k}-error` : undefined });

  async function make(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      onMade(await kitApi.createOwnVoice({ ...input, name: input.name.trim(), personName: input.personName.trim(), language: input.language?.trim() || null, script: input.script?.trim() || null }));
      setInput(blank());
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="agent-form" onSubmit={make} aria-labelledby="own-voice-new-title">
      <h3 id="own-voice-new-title">{t("ownVoicesAdd")}</h3>
      <label className="field">
        <span>{t("ownVoiceName")}</span>
        <input value={input.name} maxLength={page.limits.nameMaxLength} required onChange={(e) => set("name", e.target.value)} {...invalid("name")} />
      </label>
      {said("name")}
      <label className="field">
        <span>{t("ownVoicePerson")}</span>
        <input value={input.personName} maxLength={page.limits.personMaxLength} required onChange={(e) => set("personName", e.target.value)} {...invalid("personName")} />
      </label>
      {said("personName")}
      <label className="field">
        <span>{t("ownVoiceRelation")}</span>
        <select value={input.relation} onChange={(e) => set("relation", e.target.value as VoiceRelation)} {...invalid("relation")}>
          {page.relations.map((r) => (
            <option key={r} value={r}>{t(relationText[r])}</option>
          ))}
        </select>
      </label>
      {said("relation")}
      <label className="field">
        <span>{t("ownVoiceLanguage")}</span>
        <input value={input.language ?? ""} maxLength={page.limits.languageMaxLength} placeholder="en" onChange={(e) => set("language", e.target.value)} {...invalid("language")} />
      </label>
      {said("language")}
      <label className="field">
        <span>{t("ownVoiceScript")}</span>
        <textarea rows={3} value={input.script ?? ""} maxLength={page.limits.scriptMaxLength} onChange={(e) => set("script", e.target.value)} {...invalid("script")} />
      </label>
      {said("script")}
      <fieldset>
        <legend>{t("ownVoiceSource")}</legend>
        {(["record", "upload"] as const).map((s) => (
          <label key={s} className="checkbox">
            <input type="radio" name="own-voice-source" checked={input.source === s} onChange={() => set("source", s)} />
            {t(s === "record" ? "ownVoiceSourceRecord" : "ownVoiceSourceUpload")}
          </label>
        ))}
      </fieldset>
      <label className="checkbox">
        <input type="checkbox" checked={input.confirmed} required onChange={(e) => set("confirmed", e.target.checked)} {...invalid("confirmed")} />
        {t("ownVoiceRelease", { person: input.personName.trim() || t("ownVoiceThisPerson") })}
      </label>
      <small className="muted">{t("ownVoiceReleaseVersion", { version: page.releaseVersion })}</small>
      {said("confirmed")}
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("ownVoiceCreating") : t("ownVoiceCreate")}</button>
    </form>
  );
}
