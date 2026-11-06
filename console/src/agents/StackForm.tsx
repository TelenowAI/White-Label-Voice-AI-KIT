/**
 * Console → one agent → its voice setup (contract/kit-api.yaml getStackChoices, listVoices, updateAgent,
 * previewVoice, previewAgentVoice): the language model and its model, speech recognition, and the voice provider and
 * voice, chosen whole from what the operator offers (voices/VoicePicker.tsx finds one among a provider's, of its chosen model, with the member's starred ones and the
 * account's own voices cloned on it first), each provider's own settings (ProviderSettingsFields.tsx), with a button to
 * hear the chosen voice and one to hear the agent as saved, and a realtime voice in its place (RealtimeFields.tsx). A part the operator no longer offers says so. Before anything
 * is offered, the setup is shown as it is, read only.
 *
 * Not here: the rest of the agent (AgentPage.tsx), browsing voices (voices/VoicesPage.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Agent, type AgentStack, type OwnVoice, type ProviderSettingValue, type StackChoices, type VoiceFilters } from "../api";
import { t } from "../text";
import { ClipButton } from "../voices/ClipButton";
import { VoicePicker } from "../voices/VoicePicker";
import { ProviderSettingsFields } from "./ProviderSettingsFields";
import { RealtimeFields, realtimePick, type RealtimePick } from "./RealtimeFields";

type Kind = "llm" | "stt" | "tts";
const kinds: Kind[] = ["llm", "stt", "tts"];
const providerOf: Record<Kind, keyof AgentStack> = { llm: "llmProvider", stt: "sttProvider", tts: "ttsProvider" };
const settingsOf = (agent: Agent, kind: Kind) => ({ ...(agent.settings[`${kind}Settings`]?.values ?? {}) });

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

const parts: (keyof AgentStack)[] = ["llmProvider", "llmModel", "sttProvider", "ttsProvider", "ttsVoice"];

export function StackForm({ agent, onSaved }: { agent: Agent; onSaved: (a: Agent) => void }) {
  const [choices, setChoices] = useState<StackChoices | null>(null);
  const [stack, setStack] = useState<AgentStack>(agent.stack);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [ownVoices, setOwnVoices] = useState<OwnVoice[]>([]);
  /** Each kind's provider settings as they stand in the form: the agent's for its own provider, none for one changed to. */
  const [settings, setSettings] = useState<Record<Kind, Record<string, ProviderSettingValue>>>(() => ({ llm: settingsOf(agent, "llm"), stt: settingsOf(agent, "stt"), tts: settingsOf(agent, "tts") }));
  /** The realtime voice as it stands in the form; sent only when changed, so a pick kept while none can be picked never blocks saving the rest. */
  const [realtime, setRealtime] = useState<RealtimePick | null>(() => realtimePick(agent.settings.realtime));

  useEffect(() => {
    kitApi.stackChoices().then(setChoices, (err) => setFailure(message(err)));
    kitApi.ownVoices().then((p) => setOwnVoices(p.voices), () => setOwnVoices([]));
  }, []);

  const offeredSettings = (kind: Kind) => (choices?.[kind] as { key: string; settings: StackChoices["tts"][number]["settings"] }[] | undefined)?.find((p) => p.key === stack[providerOf[kind]])?.settings ?? [];
  const ttsModelSetting = offeredSettings("tts").find((s) => s.model);
  const ttsModel = ttsModelSetting ? ((settings.tts[ttsModelSetting.key] as string | null) ?? (ttsModelSetting.default as string | null)) : null;
  const loadVoices = useCallback((f: VoiceFilters) => kitApi.voices({ ...f, ...(ttsModel ? { model: ttsModel } : {}) }), [ttsModel]);
  const offered = choices !== null && choices.llm.length + choices.stt.length + choices.tts.length > 0;
  if (choices !== null && !offered) {
    // A provider is named only by what the operator calls it (its key means nothing to people); a model or voice by its own id.
    const part = (k: keyof AgentStack) => agent.stackNames[k] ?? (agent.stack[k] === null ? null : k.endsWith("Provider") ? t("stackSet") : agent.stack[k]);
    const shown = (...p: (keyof AgentStack)[]) => p.map(part).filter(Boolean).join(" · ") || t("notChosenYet");
    return (
      <section aria-labelledby="voice-setup-title">
        <h2 id="voice-setup-title">{t("voiceSetup")}</h2>
        <p className="muted">{t("stackNoChoices")}</p>
        <dl className="facts">
          <dt>{t("stackLlm")}</dt>
          <dd>{shown("llmProvider", "llmModel")}</dd>
          <dt>{t("stackStt")}</dt>
          <dd>{shown("sttProvider")}</dd>
          <dt>{t("stackTts")}</dt>
          <dd>{shown("ttsProvider", "ttsVoice")}</dd>
        </dl>
      </section>
    );
  }

  const llm = choices?.llm.find((p) => p.key === stack.llmProvider);
  const changeProvider = (kind: Kind, also: Partial<AgentStack> = {}) => (e: { target: { value: string } }) => {
    setStack((s) => ({ ...s, ...also, [providerOf[kind]]: e.target.value || null }));
    setSettings((now) => ({ ...now, [kind]: e.target.value === agent.stack[providerOf[kind]] ? settingsOf(agent, kind) : {} }));
  };
  const change = (key: keyof AgentStack, also: Partial<AgentStack> = {}) => (e: { target: { value: string } }) =>
    setStack((s) => ({ ...s, ...also, [key]: e.target.value || null }));

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const providerSettings = Object.fromEntries(kinds.filter((kind) => offeredSettings(kind).length > 0)
        .map((kind) => [kind, Object.fromEntries(offeredSettings(kind).map((s) => [s.key, settings[kind][s.key] ?? null]))]));
      const realtimeChanged = JSON.stringify(realtime) !== JSON.stringify(realtimePick(agent.settings.realtime));
      onSaved(await kitApi.updateAgent(agent.id, { ...Object.fromEntries(parts.map((k) => [k, stack[k] ?? ""])), ...(Object.keys(providerSettings).length > 0 ? { providerSettings } : {}),
        ...(realtimeChanged ? { realtime } : {}) }));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  /** A part kept though no longer offered: by its name, else (a model or voice) its id — never a provider's key. */
  const keptLabel = (key: keyof AgentStack, value: string) => {
    const name = agent.stackNames[key] ?? (key.endsWith("Provider") ? null : value);
    return name === null ? t("stackNotOfferedUnnamed") : t("stackNotOffered", { name });
  };

  /** A select of the offered options, keeping the saved one (named "Not offered") when the operator no longer offers it. */
  const select = (key: keyof AgentStack, label: string, options: { value: string; label: string }[], onChange = change(key)) => {
    const value = stack[key] ?? "";
    const kept = value !== "" && !options.some((o) => o.value === value) && value === agent.stack[key];
    return (
      <div className="field">
        <label htmlFor={`stack-${key}`}>{label}</label>
        <select id={`stack-${key}`} value={value} onChange={onChange} aria-invalid={errors[key] ? true : undefined}>
          <option value="">{t("chooseOne")}</option>
          {kept && <option value={value}>{keptLabel(key, value)}</option>}
          {options.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
        {errors[key] && <small className="error">{errors[key].join(" ")}</small>}
      </div>
    );
  };

  const providerFields = (kind: Kind, title: string) => (
    <ProviderSettingsFields title={title} settings={offeredSettings(kind)} values={settings[kind]} errors={errors} at={`providerSettings.${kind}`}
      onChange={(key, value) => setSettings((now) => ({ ...now, [kind]: { ...now[kind], [key]: value } }))} />
  );

  /** The saved voice, which the page of voices shown may not hold: by its name, or said to be no longer offered. */
  const savedVoice = stack.ttsVoice === agent.stack.ttsVoice && stack.ttsVoice !== null
    ? agent.stackNames.ttsVoice ?? keptLabel("ttsVoice", stack.ttsVoice) : null;

  return (
    <section aria-labelledby="voice-setup-title">
      <h2 id="voice-setup-title">{t("voiceSetup")}</h2>
      {choices && (
        <form className="agent-form" onSubmit={save} noValidate aria-label={t("voiceSetup")}>
          {select("llmProvider", t("stackLlm"), choices.llm.map((p) => ({ value: p.key, label: p.name })), changeProvider("llm", { llmModel: null }))}
          {select("llmModel", t("stackLlmModel"), (llm?.models ?? []).map((m) => ({ value: m.id, label: m.name })))}
          {providerFields("llm", t("settingsOfLlm"))}
          {select("sttProvider", t("stackStt"), choices.stt.map((p) => ({ value: p.key, label: p.name })), changeProvider("stt"))}
          {providerFields("stt", t("settingsOfStt"))}
          {select("ttsProvider", t("stackTtsProvider"), choices.tts.map((p) => ({ value: p.key, label: p.name })), changeProvider("tts", { ttsVoice: null }))}
          {providerFields("tts", t("settingsOfTts"))}
          <VoicePicker id="stack-ttsVoice" label={t("stackTts")} emptyLabel={t("chooseOne")} load={loadVoices} starredFirst
            provider={stack.ttsProvider && choices.tts.some((p) => p.key === stack.ttsProvider) ? stack.ttsProvider : null}
            value={stack.ttsVoice} savedLabel={savedVoice} onChange={(voice) => setStack((s) => ({ ...s, ttsVoice: voice }))} error={errors.ttsVoice}
            own={ownVoices.filter((v) => v.clones.some((c) => c.provider === stack.ttsProvider && c.status === "ready")).map((v) => ({ value: v.voice, label: v.name }))} />
          <RealtimeFields choices={choices.realtime} now={agent.settings.realtime} value={realtime} onChange={setRealtime} errors={errors} />
          {failure && <p role="alert" className="error">{failure}</p>}
          <div className="actions">
            <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveStack")}</button>
            {stack.ttsProvider && stack.ttsVoice && (stack.ttsVoice !== agent.stack.ttsVoice || agent.stackNames.ttsVoice !== null) && (
              <ClipButton key={`${stack.ttsProvider}/${stack.ttsVoice}`} label={t("hearVoice")} load={() => kitApi.previewVoice(stack.ttsProvider!, stack.ttsVoice!)} />
            )}
            <ClipButton label={t("hearAgent")} load={() => kitApi.previewAgentVoice(agent.id)} />
          </div>
        </form>
      )}
    </section>
  );
}
