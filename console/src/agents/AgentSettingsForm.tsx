/**
 * Console → one agent → how it behaves on a call and after it (contract/kit-api.yaml AgentSettings, updateAgent): its opening
 * lines, how it speaks and listens (AgentBehaviourFields.tsx), recording, how long a call may run, what it does when the line goes
 * silent, whether it can hang up, transfer to a person (once the operator allows them), its tools, look-ups and hand-overs
 * (AgentToolsFields.tsx, AgentLookupsFields.tsx, AgentHandoffFields.tsx) and what is worked out from each finished call. One form and
 * one save: the backend changes only what changed.
 *
 * Not here: the agent's name and instructions (AgentPage.tsx), its voice setup (StackForm.tsx), how these become the voice
 * platform's settings (App\Kit\Agents\AgentSettings).
 */
import { useState, type FormEvent, type ReactNode } from "react";
import { KitApiError, kitApi, type Agent, type AgentAnalysisField, type AgentSettingsForm as FormSpec, type AgentSettingsInput, type AgentTransferPlace } from "../api";
import { t, type TextKey } from "../text";
import { AgentBehaviourFields, behaviourSettings, behaviourValues } from "./AgentBehaviourFields";
import { AgentEmailFields } from "./AgentEmailFields";
import { AgentHandoffFields } from "./AgentHandoffFields";
import { AgentLookupsFields, lookupRows, lookupsInput } from "./AgentLookupsFields";
import { AgentToolsFields, toolRows, toolsInput } from "./AgentToolsFields";
import { humane, keyFor } from "./settingsRows";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

interface PlaceRow {
  label: string;
  numbers: string;
  mode: AgentTransferPlace["mode"];
}

interface FieldRow {
  key: string;
  name: string;
  type: AgentAnalysisField["type"];
  description: string;
}

interface CheckRow {
  key: string;
  name: string;
  description: string;
}

const fieldTypes: { value: AgentAnalysisField["type"]; label: TextKey }[] = [
  { value: "string", label: "settingsFieldString" },
  { value: "number", label: "settingsFieldNumber" },
  { value: "boolean", label: "settingsFieldBoolean" },
  { value: "date", label: "settingsFieldDate" },
];

function lines(text: string): string[] {
  return text.split(/[\n,]+/).map((n) => n.trim()).filter(Boolean);
}

/**
 * `form`: what the backend offers on this form. `savedAt`: the `updatedAt` this form last saved. A save remounts the form with the
 * agent it answered, and only then does it say so.
 */
export function AgentSettingsForm({ agent, form, savedAt, onSaved }: { agent: Agent; form: FormSpec; savedAt?: string | null; onSaved: (a: Agent) => void }) {
  const s = agent.settings;
  const [behaviour, setBehaviour] = useState(() => behaviourValues(form.groups, s));
  const [tools, setTools] = useState(() => toolRows(s.tools));
  const [lookups, setLookups] = useState(() => lookupRows(s.lookups));
  const [handoffs, setHandoffs] = useState(s.handoff.agents);
  const [handoffMessage, setHandoffMessage] = useState(s.handoff.message ?? "");
  const [emailRecipients, setEmailRecipients] = useState(s.emailAfterCall.recipients);
  const [emailOnlyAnswered, setEmailOnlyAnswered] = useState(s.emailAfterCall.onlyAnswered);
  const [canAdjustVolume, setCanAdjustVolume] = useState(s.canAdjustVolume);
  const [sendCaller, setSendCaller] = useState(s.sendCallerToTools);
  const [greetingInbound, setGreetingInbound] = useState(s.greetingInbound ?? "");
  const [greetingOutbound, setGreetingOutbound] = useState(s.greetingOutbound ?? "");
  const [voicemail, setVoicemail] = useState(s.voicemailMessage ?? "");
  const [recording, setRecording] = useState(s.recording);
  const [canEndCall, setCanEndCall] = useState(s.canEndCall);
  const [maxDuration, setMaxDuration] = useState(s.maxDurationSecs === null ? "" : String(s.maxDurationSecs));
  const [maxDurationMessage, setMaxDurationMessage] = useState(s.maxDurationMessage ?? "");
  const [hangupOn, setHangupOn] = useState(s.silenceHangupSecs !== null);
  const [hangupSecs, setHangupSecs] = useState(s.silenceHangupSecs === null ? "" : String(s.silenceHangupSecs));
  const [hangupMessage, setHangupMessage] = useState(s.silenceHangupMessage ?? "");
  const [checkinOn, setCheckinOn] = useState(s.silenceCheckinSecs !== null);
  const [checkinSecs, setCheckinSecs] = useState(s.silenceCheckinSecs === null ? "" : String(s.silenceCheckinSecs));
  const [checkinMessage, setCheckinMessage] = useState(s.silenceCheckinMessage ?? "");
  const [transferOn, setTransferOn] = useState(s.transfer.enabled);
  const [places, setPlaces] = useState<PlaceRow[]>(s.transfer.destinations.map((d) => ({ label: d.label ?? "", numbers: d.numbers.join("\n"), mode: d.mode })));
  const [transferMessage, setTransferMessage] = useState(s.transfer.message ?? "");
  const [noAnswerMessage, setNoAnswerMessage] = useState(s.transfer.noAnswerMessage ?? "");
  const [analysisOn, setAnalysisOn] = useState(s.analysis.enabled);
  const [minDuration, setMinDuration] = useState(String(s.analysis.minDurationSec));
  const [minTurns, setMinTurns] = useState(String(s.analysis.minCustomerTurns));
  const [fields, setFields] = useState<FieldRow[]>(s.analysis.customFields.map((f) => ({ key: f.key, name: f.label ?? humane(f.key), type: f.type, description: f.description ?? "" })));
  const [checks, setChecks] = useState<CheckRow[]>(s.analysis.qaCriteria.map((c) => ({ key: c.key, name: humane(c.key), description: c.description ?? "" })));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(savedAt === agent.updatedAt);

  const change = <T,>(set: (v: T) => void) => (v: T) => {
    setSaved(false);
    set(v);
  };

  function settings(): AgentSettingsInput {
    const fieldKeys: string[] = [];
    const checkKeys: string[] = [];
    return {
      greetingInbound: greetingInbound.trim() || null,
      greetingOutbound: greetingOutbound.trim() || null,
      voicemailMessage: voicemail.trim() || null,
      recording,
      canEndCall,
      // An agent with no limit keeps none until one is typed; a limit cannot be taken away.
      ...(maxDuration.trim() === "" && s.maxDurationSecs === null ? {} : { maxDurationSecs: Number(maxDuration) }),
      maxDurationMessage: maxDurationMessage.trim() || null,
      silenceHangupSecs: hangupOn ? Number(hangupSecs) : null,
      silenceHangupMessage: hangupMessage.trim() || null,
      silenceCheckinSecs: checkinOn ? Number(checkinSecs) : null,
      silenceCheckinMessage: checkinMessage.trim() || null,
      // Left out when the kit cannot send email: nothing here could change it.
      ...(form.emailOn ? { emailAfterCall: { recipients: emailRecipients, onlyAnswered: emailOnlyAnswered } } : {}),
      canAdjustVolume,
      sendCallerToTools: sendCaller,
      tools: toolsInput(tools),
      lookups: lookupsInput(lookups),
      handoff: { agents: handoffs, message: handoffMessage.trim() || null },
      // Left out when the operator has them off: an agent that still has one is taken care of by the backend.
      ...(form.transferAllowed ? {
        transfer: {
          enabled: transferOn,
          destinations: transferOn ? places.map((p) => ({ label: p.label.trim() || null, numbers: lines(p.numbers), mode: p.mode })) : [],
          message: transferMessage.trim() || null,
          noAnswerMessage: noAnswerMessage.trim() || null,
        },
      } : {}),
      analysis: {
        enabled: analysisOn,
        minDurationSec: Number(minDuration),
        minCustomerTurns: Number(minTurns),
        customFields: fields.map((f, i) => {
          const key = keyFor(f, "detail", i, fieldKeys);
          fieldKeys.push(key);
          return { key, label: f.name.trim() || null, type: f.type, description: f.description.trim() || null };
        }),
        qaCriteria: checks.map((c, i) => {
          const key = keyFor(c, "check", i, checkKeys);
          checkKeys.push(key);
          return { key, description: c.description.trim() || null };
        }),
      },
    };
  }

  async function save(e: FormEvent) {
    e.preventDefault();
    const missing: Record<string, string[]> = {};
    if (hangupOn && hangupSecs.trim() === "") missing["settings.silenceHangupSecs"] = [t("settingsSecondsNeeded")];
    if (checkinOn && checkinSecs.trim() === "") missing["settings.silenceCheckinSecs"] = [t("settingsSecondsNeeded")];
    setErrors(missing);
    setFailure(null);
    setSaved(false);
    if (Object.keys(missing).length > 0) return;
    setBusy(true);
    try {
      onSaved(await kitApi.updateAgent(agent.id, { settings: { ...settings(), ...behaviourSettings(form.groups, behaviour) } }));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
      setBusy(false);
      return;
    }
    setBusy(false);
  }

  const errorFor = (key: string) => {
    const said = Object.entries(errors).filter(([k]) => k === key || k.startsWith(`${key}.`)).flatMap(([, v]) => v);
    return said.length > 0 ? <small className="error">{said.join(" ")}</small> : null;
  };
  const invalid = (key: string) => (errors[key] ? true : undefined);

  const text = (id: string, key: string, label: TextKey, value: string, set: (v: string) => void, help?: TextKey, rows = 1): ReactNode => (
    <div className="field">
      <label htmlFor={id}>{t(label)}</label>
      {help && <small className="muted">{t(help)}</small>}
      {rows > 1
        ? <textarea id={id} rows={rows} value={value} onChange={(e) => change(set)(e.target.value)} aria-invalid={invalid(key)} />
        : <input id={id} value={value} onChange={(e) => change(set)(e.target.value)} aria-invalid={invalid(key)} />}
      {errorFor(key)}
    </div>
  );
  const number = (id: string, key: string, label: TextKey, value: string, set: (v: string) => void): ReactNode => (
    <div className="field field-narrow">
      <label htmlFor={id}>{t(label)}</label>
      <input id={id} inputMode="numeric" value={value} onChange={(e) => change(set)(e.target.value)} aria-invalid={invalid(key)} />
      {errorFor(key)}
    </div>
  );
  const toggle = (label: TextKey, value: boolean, set: (v: boolean) => void): ReactNode => (
    <label className="checkbox">
      <input type="checkbox" checked={value} onChange={(e) => change(set)(e.target.checked)} />
      {t(label)}
    </label>
  );

  return (
    <form className="agent-form" onSubmit={save} noValidate aria-label={t("saveSettings")}>
      <section aria-labelledby="settings-opening">
        <h2 id="settings-opening">{t("settingsOpening")}</h2>
        {s.greetingInbound === null && <p className="note">{t("settingsNoOpening")}</p>}
        {text("settings-greetingInbound", "settings.greetingInbound", "settingsGreetingInbound", greetingInbound, setGreetingInbound, "settingsGreetingHelp")}
        {text("settings-greetingOutbound", "settings.greetingOutbound", "settingsGreetingOutbound", greetingOutbound, setGreetingOutbound, "settingsGreetingHelp")}
        {text("settings-voicemail", "settings.voicemailMessage", "settingsVoicemail", voicemail, setVoicemail, "settingsVoicemailHelp")}
      </section>

      <section aria-labelledby="settings-behaviour">
        <h2 id="settings-behaviour">{t("settingsBehaviour")}</h2>
        <AgentBehaviourFields groups={form.groups} values={behaviour} errors={errors} onChange={(name, value) => change(setBehaviour)({ ...behaviour, [name]: value })} />
      </section>

      <section aria-labelledby="settings-during">
        <h2 id="settings-during">{t("settingsDuring")}</h2>
        {toggle("settingsRecording", recording, setRecording)}
        {toggle("settingsCanEndCall", canEndCall, setCanEndCall)}
        {s.maxDurationSecs === null && <p className="note">{t("settingsNoLimit")}</p>}
        {number("settings-maxDuration", "settings.maxDurationSecs", "settingsMaxDuration", maxDuration, setMaxDuration)}
        {text("settings-maxDurationMessage", "settings.maxDurationMessage", "settingsMaxDurationMessage", maxDurationMessage, setMaxDurationMessage, "settingsAutoLineHelp")}
        {toggle("settingsSilenceHangup", hangupOn, setHangupOn)}
        {hangupOn && (
          <>
            {number("settings-hangupSecs", "settings.silenceHangupSecs", "settingsAfterSeconds", hangupSecs, setHangupSecs)}
            {text("settings-hangupMessage", "settings.silenceHangupMessage", "settingsWhatItSays", hangupMessage, setHangupMessage, "settingsAutoLineHelp")}
          </>
        )}
        {toggle("settingsSilenceCheckin", checkinOn, setCheckinOn)}
        {checkinOn && (
          <>
            {number("settings-checkinSecs", "settings.silenceCheckinSecs", "settingsAfterSeconds", checkinSecs, setCheckinSecs)}
            {text("settings-checkinMessage", "settings.silenceCheckinMessage", "settingsWhatItSays", checkinMessage, setCheckinMessage, "settingsAutoLineHelp")}
          </>
        )}
      </section>

      {form.transferAllowed && (
        <section aria-labelledby="settings-transfer">
          <h2 id="settings-transfer">{t("settingsTransfer")}</h2>
          {toggle("settingsTransferOn", transferOn, (on) => {
            setTransferOn(on);
            if (on && places.length === 0) setPlaces([{ label: "", numbers: "", mode: "serial" }]);
          })}
          {transferOn && (
            <>
              <p className="muted">{t("settingsTransferHelp")}</p>
              {errors["settings.transfer.destinations"] && <small className="error">{errors["settings.transfer.destinations"].join(" ")}</small>}
              {places.map((p, i) => {
                const at = `settings.transfer.destinations.${i}`;
                const put = (over: Partial<PlaceRow>) => change(setPlaces)(places.map((x, j) => (j === i ? { ...x, ...over } : x)));
                return (
                  <fieldset className="field" key={i}>
                    <legend>{p.label.trim() || t("settingsPlaceNumber", { n: String(i + 1) })}</legend>
                    {text(`place-${i}-label`, `${at}.label`, "settingsPlaceName", p.label, (v) => put({ label: v }), "settingsPlaceNameHelp")}
                    {text(`place-${i}-numbers`, `${at}.numbers`, "settingsPlaceNumbers", p.numbers, (v) => put({ numbers: v }), "settingsPlaceNumbersHelp", 2)}
                    <div className="field">
                      <label htmlFor={`place-${i}-mode`}>{t("settingsPlaceMode")}</label>
                      <select id={`place-${i}-mode`} value={p.mode} onChange={(e) => put({ mode: e.target.value as PlaceRow["mode"] })}>
                        <option value="serial">{t("settingsModeSerial")}</option>
                        <option value="parallel">{t("settingsModeParallel")}</option>
                      </select>
                    </div>
                    <button type="button" className="secondary" onClick={() => change(setPlaces)(places.filter((_, j) => j !== i))}>{t("settingsRemovePlace")}</button>
                  </fieldset>
                );
              })}
              <button type="button" className="secondary" onClick={() => change(setPlaces)([...places, { label: "", numbers: "", mode: "serial" }])}>{t("settingsAddPlace")}</button>
              {text("settings-transferMessage", "settings.transfer.message", "settingsTransferMessage", transferMessage, setTransferMessage, "settingsTransferMessageHelp")}
              {text("settings-noAnswer", "settings.transfer.noAnswerMessage", "settingsNoAnswerMessage", noAnswerMessage, setNoAnswerMessage, "settingsNoAnswerHelp")}
            </>
          )}
        </section>
      )}

      <section aria-labelledby="settings-tools">
        <h2 id="settings-tools">{t("settingsTools")}</h2>
        <AgentToolsFields rows={tools} errors={errors} onChange={change(setTools)} />
        {toggle("settingsCanAdjustVolume", canAdjustVolume, setCanAdjustVolume)}
        {toggle("settingsSendCaller", sendCaller, setSendCaller)}
        <small className="muted">{t("settingsSendCallerHelp")}</small>
      </section>

      <section aria-labelledby="settings-lookups">
        <h2 id="settings-lookups">{t("settingsLookups")}</h2>
        <AgentLookupsFields rows={lookups} errors={errors} onChange={change(setLookups)} />
      </section>

      <section aria-labelledby="settings-handoff">
        <h2 id="settings-handoff">{t("settingsHandoff")}</h2>
        <AgentHandoffFields agentId={agent.id} rows={handoffs} message={handoffMessage} errors={errors} onChange={change(setHandoffs)} onMessage={change(setHandoffMessage)} />
      </section>

      <section aria-labelledby="settings-after">
        <h2 id="settings-after">{t("settingsAfter")}</h2>
        {form.emailOn && <AgentEmailFields recipients={emailRecipients} onlyAnswered={emailOnlyAnswered} errors={errors} onRecipients={change(setEmailRecipients)} onOnlyAnswered={change(setEmailOnlyAnswered)} />}
        {toggle("settingsAnalysisOn", analysisOn, setAnalysisOn)}
        {analysisOn && (
          <>
            <p className="muted">{t("settingsAnalysisHelp")}</p>
            {number("settings-minDuration", "settings.analysis.minDurationSec", "settingsMinDuration", minDuration, setMinDuration)}
            {number("settings-minTurns", "settings.analysis.minCustomerTurns", "settingsMinTurns", minTurns, setMinTurns)}
            <h3>{t("settingsFields")}</h3>
            {errors["settings.analysis.customFields"] && <small className="error">{errors["settings.analysis.customFields"].join(" ")}</small>}
            {fields.map((f, i) => {
              const at = `settings.analysis.customFields.${i}`;
              const put = (over: Partial<FieldRow>) => change(setFields)(fields.map((x, j) => (j === i ? { ...x, ...over } : x)));
              return (
                <fieldset className="field" key={i}>
                  <legend>{f.name.trim() || t("settingsFieldNumber_", { n: String(i + 1) })}</legend>
                  {text(`field-${i}-name`, `${at}.key`, "settingsFieldName", f.name, (v) => put({ name: v }))}
                  <div className="field">
                    <label htmlFor={`field-${i}-type`}>{t("settingsFieldType")}</label>
                    <select id={`field-${i}-type`} value={f.type} onChange={(e) => put({ type: e.target.value as FieldRow["type"] })}>
                      {fieldTypes.map((o) => <option key={o.value} value={o.value}>{t(o.label)}</option>)}
                    </select>
                  </div>
                  {text(`field-${i}-description`, `${at}.description`, "settingsFieldDescription", f.description, (v) => put({ description: v }))}
                  <button type="button" className="secondary" onClick={() => change(setFields)(fields.filter((_, j) => j !== i))}>{t("settingsRemove")}</button>
                </fieldset>
              );
            })}
            <button type="button" className="secondary" onClick={() => change(setFields)([...fields, { key: "", name: "", type: "string", description: "" }])}>{t("settingsAddField")}</button>
            <h3>{t("settingsChecks")}</h3>
            {errors["settings.analysis.qaCriteria"] && <small className="error">{errors["settings.analysis.qaCriteria"].join(" ")}</small>}
            {checks.map((c, i) => {
              const at = `settings.analysis.qaCriteria.${i}`;
              const put = (over: Partial<CheckRow>) => change(setChecks)(checks.map((x, j) => (j === i ? { ...x, ...over } : x)));
              return (
                <fieldset className="field" key={i}>
                  <legend>{c.name.trim() || t("settingsCheckNumber", { n: String(i + 1) })}</legend>
                  {text(`check-${i}-name`, `${at}.key`, "settingsCheckName", c.name, (v) => put({ name: v }))}
                  {text(`check-${i}-description`, `${at}.description`, "settingsCheckDescription", c.description, (v) => put({ description: v }))}
                  <button type="button" className="secondary" onClick={() => change(setChecks)(checks.filter((_, j) => j !== i))}>{t("settingsRemove")}</button>
                </fieldset>
              );
            })}
            <button type="button" className="secondary" onClick={() => change(setChecks)([...checks, { key: "", name: "", description: "" }])}>{t("settingsAddCheck")}</button>
          </>
        )}
      </section>

      {failure && <p role="alert" className="error">{failure}</p>}
      {Object.keys(errors).length > 0 && !failure && <p role="alert" className="error">{Object.values(errors)[0].join(" ")}</p>}
      {saved && <p role="status">{t("settingsSaved")}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveSettings")}</button>
    </form>
  );
}
