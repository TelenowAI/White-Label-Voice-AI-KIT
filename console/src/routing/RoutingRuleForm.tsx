/**
 * The form for a call routing rule, the same for a new one (createRoutingRule, last in the order) and a changed one (updateRoutingRule): its
 * name; on or off; what it matches — the account's numbers called (ticked; none: any), the lines that forwarded the call and how the caller's
 * number starts (a box each, one per line, at most `limits.numbersPerRule`), days, hours from–until and their timezone; then what it does —
 * answer (with one of the account's agents or the number's own, a language, a greeting and a cap in minutes, each optional) or refuse (the
 * answer's fields hidden, and not sent). The backend's word on a field shows beside it, a box's line named; any other refusal (too many rules,
 * not a manager) as an alert.
 *
 * Not here: what is sent and where a refusal goes (ruleFormValues.ts); the list of rules that opens it (RoutingRuleList.tsx).
 * Used by: RoutingRuleList.tsx.
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Agent, type RoutingLimits, type RoutingNumber, type RoutingRule } from "../api";
import { t } from "../text";
import { newRuleValues, placeErrors, ruleInput, ruleValues, type RuleField, type RuleFormValues } from "./ruleFormValues";
import { dayWord, isoDays } from "./routingWords";

interface Props {
  /** The rule to change; null for a new one. */
  rule: RoutingRule | null;
  numbers: RoutingNumber[];
  /** The account's saved agents. */
  agents: Agent[];
  limits: RoutingLimits;
  onSaved: (rule: RoutingRule) => void;
  onCancel: () => void;
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function RoutingRuleForm({ rule, numbers, agents, limits, onSaved, onCancel }: Props) {
  const [values, setValues] = useState<RuleFormValues>(() => (rule ? ruleValues(rule, numbers) : newRuleValues()));
  const [errors, setErrors] = useState<Partial<Record<RuleField, string[]>>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (over: Partial<RuleFormValues>) => setValues((v) => ({ ...v, ...over }));
  const text = (key: "name" | "forwardedFrom" | "callerStartsWith" | "from" | "until" | "timezone" | "agentId" | "language" | "greeting" | "maxMinutes") =>
    (e: { target: { value: string } }) => set({ [key]: e.target.value });
  const toggle = <T,>(list: T[], item: T, on: boolean) => (on ? [...list.filter((x) => x !== item), item] : list.filter((x) => x !== item));
  // The agent the rule answers with stays a choice while the list of agents is not read yet.
  const choices = rule?.agent && !agents.some((a) => a.id === rule.agent?.id) ? [rule.agent, ...agents] : agents;

  /** The backend's word on a field, its id for aria-describedby. */
  const error = (key: RuleField) => (errors[key]?.length ? <small className="error" id={`rule-${key}-error`}>{errors[key]!.join(" ")}</small> : null);
  const invalid = (key: RuleField) => (errors[key]?.length ? true : undefined);
  const describedBy = (key: RuleField, help?: string) => [...(errors[key]?.length ? [`rule-${key}-error`] : []), ...(help ? [help] : [])].join(" ") || undefined;

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    const { input, sent } = ruleInput(values);
    try {
      onSaved(rule ? await kitApi.updateRoutingRule(rule.id, input) : await kitApi.createRoutingRule(input));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        const placed = placeErrors(err.fields, sent);
        setErrors(placed.fields);
        if (placed.other.length > 0) setFailure(placed.other.join(" "));
      } else {
        setFailure(message(err));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="agent-form" aria-label={rule ? t("routingEditRule", { name: rule.name }) : t("routingNewRule")} onSubmit={(e) => void save(e)} noValidate>
      <h3>{rule ? t("routingEditRule", { name: rule.name }) : t("routingNewRule")}</h3>
      <div className="field">
        <label htmlFor="rule-name">{t("routingRuleName")}</label>
        <input id="rule-name" value={values.name} maxLength={limits.nameMaxLength} required onChange={text("name")} aria-invalid={invalid("name")} aria-describedby={describedBy("name")} />
        {error("name")}
      </div>
      <label className="checkbox">
        <input type="checkbox" checked={values.enabled} onChange={(e) => set({ enabled: e.target.checked })} aria-invalid={invalid("enabled")} aria-describedby={describedBy("enabled")} />
        {t("routingRuleEnabled")}
      </label>
      {error("enabled")}

      <fieldset aria-describedby={describedBy("numberIds", "rule-numbers-help")}>
        <legend>{t("routingRuleNumbers")}</legend>
        <small className="muted" id="rule-numbers-help">{t("routingRuleNumbersHelp")}</small>
        {numbers.map((n) => (
          <label className="checkbox" key={n.id}>
            <input type="checkbox" checked={values.numberIds.includes(n.id)} onChange={(e) => set({ numberIds: toggle(values.numberIds, n.id, e.target.checked) })} />
            {n.number}
          </label>
        ))}
        {error("numberIds")}
      </fieldset>

      <div className="field">
        <label htmlFor="rule-forwarded">{t("routingRuleForwarded")}</label>
        <textarea id="rule-forwarded" rows={3} value={values.forwardedFrom} onChange={text("forwardedFrom")} aria-invalid={invalid("forwardedFrom")}
          aria-describedby={describedBy("forwardedFrom", "rule-forwarded-help")} />
        <small className="muted" id="rule-forwarded-help">{t("routingRuleForwardedHelp", { n: String(limits.numbersPerRule) })}</small>
        {error("forwardedFrom")}
      </div>
      <div className="field">
        <label htmlFor="rule-callers">{t("routingRuleCallers")}</label>
        <textarea id="rule-callers" rows={3} value={values.callerStartsWith} onChange={text("callerStartsWith")} aria-invalid={invalid("callerStartsWith")}
          aria-describedby={describedBy("callerStartsWith", "rule-callers-help")} />
        <small className="muted" id="rule-callers-help">{t("routingRuleCallersHelp", { n: String(limits.numbersPerRule) })}</small>
        {error("callerStartsWith")}
      </div>

      <fieldset aria-describedby={describedBy("days", "rule-days-help")}>
        <legend>{t("routingRuleDays")}</legend>
        <small className="muted" id="rule-days-help">{t("routingRuleDaysHelp")}</small>
        <div className="actions">
          {isoDays.map((d) => (
            <label className="checkbox" key={d}>
              <input type="checkbox" checked={values.days.includes(d)} onChange={(e) => set({ days: toggle(values.days, d, e.target.checked) })} />
              {dayWord(d)}
            </label>
          ))}
        </div>
        {error("days")}
      </fieldset>
      <div className="inline-form">
        <div className="field">
          <label htmlFor="rule-from">{t("routingRuleFrom")}</label>
          <input id="rule-from" type="time" value={values.from} onChange={text("from")} aria-invalid={invalid("from")} aria-describedby={describedBy("from", "rule-hours-help")} />
          {error("from")}
        </div>
        <div className="field">
          <label htmlFor="rule-until">{t("routingRuleUntil")}</label>
          <input id="rule-until" type="time" value={values.until} onChange={text("until")} aria-invalid={invalid("until")} aria-describedby={describedBy("until", "rule-hours-help")} />
          {error("until")}
        </div>
        <div className="field">
          <label htmlFor="rule-timezone">{t("routingRuleTimezone")}</label>
          <input id="rule-timezone" value={values.timezone} required={values.days.length > 0 || values.from !== "" || values.until !== ""} onChange={text("timezone")} aria-invalid={invalid("timezone")} aria-describedby={describedBy("timezone", "rule-timezone-help")} />
          {error("timezone")}
        </div>
      </div>
      <small className="muted" id="rule-hours-help">{t("routingRuleHoursHelp")}</small>{" "}
      <small className="muted" id="rule-timezone-help">{t("routingRuleTimezoneHelp")}</small>

      <fieldset aria-describedby={describedBy("action")}>
        <legend>{t("routingRuleAction")}</legend>
        <label className="checkbox">
          <input type="radio" name="rule-action" value="answer" checked={values.action === "answer"} onChange={() => set({ action: "answer" })} />
          {t("routingRuleAnswer")}
        </label>
        <label className="checkbox">
          <input type="radio" name="rule-action" value="refuse" checked={values.action === "refuse"} onChange={() => set({ action: "refuse" })} />
          {t("routingRuleRefuse")}
        </label>
        {error("action")}
      </fieldset>
      {values.action === "answer" && (
        <>
          <div className="field">
            <label htmlFor="rule-agent">{t("routingRuleAgent")}</label>
            <select id="rule-agent" value={values.agentId} onChange={text("agentId")} aria-invalid={invalid("agentId")} aria-describedby={describedBy("agentId")}>
              <option value="">{t("routingRuleOwnAgent")}</option>
              {choices.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
            {error("agentId")}
          </div>
          <div className="field field-narrow">
            <label htmlFor="rule-language">{t("routingRuleLanguage")}</label>
            <input id="rule-language" value={values.language} maxLength={limits.languageMaxLength} onChange={text("language")} aria-invalid={invalid("language")}
              aria-describedby={describedBy("language", "rule-language-help")} />
            <small className="muted" id="rule-language-help">{t("routingRuleLanguageHelp")}</small>
            {error("language")}
          </div>
          <div className="field">
            <label htmlFor="rule-greeting">{t("routingRuleGreeting")}</label>
            <textarea id="rule-greeting" rows={2} value={values.greeting} maxLength={limits.greetingMaxLength} onChange={text("greeting")} aria-invalid={invalid("greeting")}
              aria-describedby={describedBy("greeting", "rule-greeting-help")} />
            <small className="muted" id="rule-greeting-help">{t("routingRuleGreetingHelp", { n: String(limits.greetingMaxLength) })}</small>
            {error("greeting")}
          </div>
          <div className="field field-narrow">
            <label htmlFor="rule-minutes">{t("routingRuleMinutes")}</label>
            <input id="rule-minutes" type="number" min={1} max={limits.maxMinutes} step={1} value={values.maxMinutes} onChange={text("maxMinutes")}
              aria-invalid={invalid("maxMinutes")} aria-describedby={describedBy("maxMinutes", "rule-minutes-help")} />
            <small className="muted" id="rule-minutes-help">{t("routingRuleMinutesHelp", { max: String(limits.maxMinutes) })}</small>
            {error("maxMinutes")}
          </div>
        </>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
      <div className="actions">
        <button type="submit" disabled={busy}>{busy ? t("saving") : t("routingRuleSave")}</button>
        <button type="button" className="secondary" disabled={busy} onClick={onCancel}>{t("cancel")}</button>
      </div>
    </form>
  );
}
