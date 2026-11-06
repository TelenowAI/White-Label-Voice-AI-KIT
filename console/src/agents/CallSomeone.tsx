/**
 * An agent calling one person now (contract/kit-api.yaml getAgentCallForm, placeAgentCall), on the agent's page: the number, which of the account's numbers to call from
 * (its own unless another is picked; one must be picked when it has none), a field for each detail its words use (those it needs say so), and, if wanted, another opening
 * line and what to do when a machine answers. The form is read when opened. Once placed, a link to the call; a refused field says why beside it.
 *
 * Not here: the test call in the browser (TestCall.tsx), many people at once (campaigns/).
 */
import { useId, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type AgentCallForm } from "../api";
import { t, type TextKey } from "../text";

const machineText: Record<AgentCallForm["machineAnswers"][number], TextKey> = {
  voicemail: "agentCallMachineVoicemail",
  hang_up: "agentCallMachineHangUp",
};

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function CallSomeone({ agentId }: { agentId: string }) {
  const [form, setForm] = useState<AgentCallForm | null>(null);
  const [number, setNumber] = useState("");
  const [callerId, setCallerId] = useState("");
  const [details, setDetails] = useState<Record<string, string>>({});
  const [openingLine, setOpeningLine] = useState("");
  const [machine, setMachine] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [placed, setPlaced] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const ids = useId();

  async function open() {
    setFailure(null);
    try {
      const f = await kitApi.agentCallForm(agentId);
      setForm(f);
      setCallerId(f.ownNumber ? "" : (f.callerIds[0]?.id ?? ""));
    } catch (err) {
      setFailure(message(err));
    }
  }

  async function place(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    setPlaced(null);
    try {
      const given = Object.fromEntries(Object.entries(details).filter(([, v]) => v.trim() !== ""));
      const { callId } = await kitApi.placeAgentCall(agentId, {
        number,
        callerId: callerId || null,
        ...(Object.keys(given).length > 0 ? { variables: given } : {}),
        openingLine: openingLine.trim() || null,
        machine: (machine || null) as "voicemail" | "hang_up" | null,
      });
      setPlaced(callId);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const said = (name: string) => (errors[name]?.length ? <span className="error" id={`${ids}-${name}-error`}>{errors[name].join(" ")}</span> : null);
  const invalid = (name: string) => ({ "aria-invalid": !!errors[name], "aria-describedby": errors[name] ? `${ids}-${name}-error` : undefined });
  const own = form?.callerIds.find((c) => c.id === form.ownNumber);

  return (
    <section className="test-call" aria-labelledby={`${ids}-title`}>
      <h2 id={`${ids}-title`}>{t("agentCallTitle")}</h2>
      <p className="muted">{t("agentCallHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!form && (
        <button type="button" className="secondary" onClick={() => void open()}>
          {t("agentCallOpen")}
        </button>
      )}
      {form && form.paused && <p role="status" className="note">{form.paused}</p>}
      {form && !form.paused && form.callerIds.length === 0 && (
        <p className="note">
          {t("agentCallNoNumbers")} <Link to="/console/numbers">{t("numbersTitle")}</Link>
        </p>
      )}
      {form && !form.paused && form.callerIds.length > 0 && (
        <form onSubmit={place}>
          <label className="field">
            <span>{t("agentCallNumber")}</span>
            <input type="tel" inputMode="tel" autoComplete="off" placeholder="+14155550100" maxLength={form.limits.numberMaxLength} value={number}
              onChange={(e) => setNumber(e.target.value)} {...invalid("number")} />
          </label>
          {said("number")}
          <label className="field">
            <span>{t("agentCallFrom")}</span>
            <select value={callerId} onChange={(e) => setCallerId(e.target.value)} {...invalid("callerId")}>
              {own ? <option value="">{t("agentCallFromOwn", { number: own.number })}</option> : <option value="" disabled>{t("agentCallPickFrom")}</option>}
              {form.callerIds.filter((c) => c.id !== form.ownNumber).map((c) => (
                <option key={c.id} value={c.id}>{c.number}</option>
              ))}
            </select>
          </label>
          {said("callerId")}
          {form.variables.length > 0 && (
            <fieldset>
              <legend>{t("agentCallDetails")}</legend>
              {form.variables.map((v) => (
                <div key={v.name}>
                  <label className="field">
                    <span>{v.required ? t("agentCallDetailNeeded", { name: v.name }) : v.name}</span>
                    <input value={details[v.name] ?? ""} maxLength={form.limits.variableValueMaxLength} required={v.required}
                      onChange={(e) => setDetails((d) => ({ ...d, [v.name]: e.target.value }))} {...invalid(`variables.${v.name}`)} />
                  </label>
                  {said(`variables.${v.name}`)}
                </div>
              ))}
              {said("variables")}
            </fieldset>
          )}
          <label className="field">
            <span>{t("agentCallOpening")}</span>
            <textarea rows={2} maxLength={form.limits.openingLineMaxLength} value={openingLine} onChange={(e) => setOpeningLine(e.target.value)} {...invalid("openingLine")} />
          </label>
          {said("openingLine")}
          <label className="field">
            <span>{t("agentCallMachine")}</span>
            <select value={machine} onChange={(e) => setMachine(e.target.value)}>
              <option value="">{t("agentCallMachineAgent")}</option>
              {form.machineAnswers.map((m) => (
                <option key={m} value={m}>{t(machineText[m])}</option>
              ))}
            </select>
          </label>
          <button type="submit" disabled={busy || number.trim() === ""}>{busy ? t("agentCallPlacing") : t("agentCallPlace")}</button>
          {placed && (
            <p role="status">
              <Link to={`/console/calls/${placed}`}>{t("agentCallPlaced")}</Link>
            </p>
          )}
        </form>
      )}
    </section>
  );
}
