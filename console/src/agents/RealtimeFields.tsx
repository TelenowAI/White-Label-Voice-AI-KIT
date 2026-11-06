/**
 * An agent's realtime voice on its voice setup (contract/kit-api.yaml getStackChoices `realtime`, updateAgent `realtime`, previewRealtimeVoice): none (calls use the
 * voice setup above), or a provider, one of its models and one of that model's voices (or the model's own), and whether a call falls back to the voice setup when the
 * realtime voice cannot start. A pick no longer offered is kept, named as such, and can be turned off; when realtime voices are not available the page says calls use
 * the voice setup. A button plays the chosen voice. The backend's word on a part shows beside it.
 *
 * Used by agents/StackForm.tsx, which saves it. Not here: which are offered (the operator's Catalog page).
 */
import { useId } from "react";
import { kitApi, type RealtimeChoice, type RealtimeNow, type RealtimeVoice } from "../api";
import { t } from "../text";
import { ClipButton } from "../voices/ClipButton";

export type RealtimePick = { provider: string; model: string; voice: string | null; fallback: boolean };

/** The saved realtime voice as the form holds it. */
export function realtimePick(now: RealtimeNow | null): RealtimePick | null {
  return now === null ? null : { provider: now.provider, model: now.model, voice: now.voice, fallback: now.fallback };
}

const voiceLabel = (v: RealtimeVoice) => [v.name ?? v.id, v.gender, v.accent].filter(Boolean).join(" · ");

export function RealtimeFields({ choices, now, value, onChange, errors }: {
  choices: RealtimeChoice[];
  now: RealtimeNow | null;
  value: RealtimePick | null;
  onChange: (next: RealtimePick | null) => void;
  /** The backend's field errors, by path. */
  errors: Record<string, string[]>;
}) {
  const ids = useId();
  if (choices.length === 0 && now === null) return null;
  const provider = choices.find((p) => p.key === value?.provider);
  const model = provider?.models.find((m) => m.id === value?.model);
  const kept = now !== null && !choices.some((p) => p.key === now.provider && p.models.some((m) => m.id === now.model));
  const said = (...paths: string[]) => {
    const words = paths.flatMap((p) => errors[p] ?? []);
    return words.length > 0 ? <small className="error">{words.join(" ")}</small> : null;
  };
  const pickProvider = (key: string) => {
    if (key === "") return onChange(null);
    if (kept && key === now.provider) return onChange(realtimePick(now));
    const p = choices.find((c) => c.key === key);
    if (p) onChange({ provider: p.key, model: p.models[0].id, voice: null, fallback: value?.fallback ?? true });
  };
  const pickModel = (id: string) => {
    if (!value || !provider) return;
    const m = provider.models.find((x) => x.id === id);
    if (m) onChange({ ...value, model: m.id, voice: m.voices.some((v) => v.id === value.voice) ? value.voice : null });
  };

  return (
    <fieldset className="realtime">
      <legend>{t("realtimeTitle")}</legend>
      <small className="muted">{t("realtimeHelp")}</small>
      {now !== null && !now.available && <p className="note">{t("realtimeUnavailable")}</p>}
      <div className="field">
        <label htmlFor={`${ids}-provider`}>{t("realtimeProvider")}</label>
        <select id={`${ids}-provider`} value={value?.provider ?? ""} onChange={(e) => pickProvider(e.target.value)} aria-invalid={errors["realtime.provider"] || errors.realtime ? true : undefined}>
          <option value="">{t("realtimeNone")}</option>
          {kept && <option value={now.provider}>{now.names.provider ? t("realtimeNotOffered", { name: now.names.provider }) : t("realtimeNotOfferedUnnamed")}</option>}
          {choices.map((p) => (
            <option key={p.key} value={p.key}>{p.name}</option>
          ))}
        </select>
        {said("realtime", "realtime.provider")}
      </div>
      {value && provider && (
        <>
          <small className="muted">{t("realtimeLanguages", { count: String(provider.languages.length) })}</small>
          <div className="field">
            <label htmlFor={`${ids}-model`}>{t("realtimeModel")}</label>
            <select id={`${ids}-model`} value={value.model} onChange={(e) => pickModel(e.target.value)} aria-invalid={errors["realtime.model"] ? true : undefined}>
              {provider.models.map((m) => (
                <option key={m.id} value={m.id}>{m.name}</option>
              ))}
            </select>
            {said("realtime.model")}
          </div>
          <div className="field">
            <label htmlFor={`${ids}-voice`}>{t("realtimeVoice")}</label>
            <select id={`${ids}-voice`} value={value.voice ?? ""} onChange={(e) => onChange({ ...value, voice: e.target.value || null })} aria-invalid={errors["realtime.voice"] ? true : undefined}>
              <option value="">{t("realtimeModelVoice")}</option>
              {(model?.voices ?? []).map((v) => (
                <option key={v.id} value={v.id}>{voiceLabel(v)}</option>
              ))}
            </select>
            {said("realtime.voice")}
          </div>
          <label className="checkbox">
            <input type="checkbox" checked={value.fallback} onChange={(e) => onChange({ ...value, fallback: e.target.checked })} />
            {t("realtimeFallback")}
          </label>
          {said("realtime.fallback")}
          <div className="actions">
            <ClipButton key={`${value.provider}/${value.model}/${value.voice ?? ""}`} label={t("realtimeHear")} load={() => kitApi.previewRealtimeVoice(value.provider, value.model, value.voice)} />
          </div>
        </>
      )}
    </fieldset>
  );
}
