/**
 * Trying a pretend call against the rules as saved (contract/kit-api.yaml tryRouting), for every member: one of the account's numbers, the
 * caller's number, the line that forwarded it and a date and time (the browser's own; empty: now), each optional but the number. The answer
 * says which rule takes the call (or none: the number's own agent), who answers or that the caller hears a busy line, with the rule's language,
 * greeting and cap, and, for a number not routed yet, that its own agent answers every call until it is. The backend's word on a field shows
 * beside it, any other refusal as an alert. Nothing is called.
 *
 * Not here: the voice platform's own test of a routed number (RoutingConnectionPanel.tsx). Used by: CallRoutingPage.tsx.
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type RoutingNumber, type RoutingTry } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** A date and time typed in the browser's own time, as an ISO instant; as typed when it is not one. */
function instant(typed: string): string {
  const at = new Date(typed);
  return Number.isNaN(at.getTime()) ? typed : at.toISOString();
}

/** What would happen, a sentence a line. */
export function tryWords(answer: RoutingTry, number: string): string[] {
  return [
    answer.rule ? t("routingTryRule", { name: answer.rule.name }) : t("routingTryNoRule"),
    answer.outcome === "refuse" ? t("routingTryRefused") : answer.agent ? t("routingTryAnswers", { name: answer.agent.name }) : t("routingTryNoAgent"),
    ...(answer.language ? [t("routingTryLanguage", { language: answer.language })] : []),
    ...(answer.greeting ? [t("routingTryGreeting", { greeting: answer.greeting })] : []),
    ...(answer.maxMinutes !== null ? [t("routingTryMinutes", { n: String(answer.maxMinutes) })] : []),
    ...(answer.routed ? [] : [t("routingTryNotRouted", { number })]),
  ];
}

export function TryRoutingCall({ numbers }: { numbers: RoutingNumber[] }) {
  const [numberId, setNumberId] = useState("");
  const [caller, setCaller] = useState("");
  const [forwarded, setForwarded] = useState("");
  const [at, setAt] = useState("");
  const [said, setSaid] = useState<string[] | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // The first number until another is chosen (and again when the chosen one is no longer listed).
  const chosen = numbers.find((n) => n.id === numberId) ?? numbers[0];

  async function run(e: FormEvent) {
    e.preventDefault();
    if (!chosen) return;
    setBusy(true);
    setErrors({});
    setFailure(null);
    setSaid(null);
    try {
      const found = await kitApi.tryRouting({
        numberId: chosen.id, callerNumber: caller.trim() || null, forwardedFrom: forwarded.trim() || null, at: at ? instant(at) : null,
      });
      setSaid(tryWords(found, chosen.number));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const error = (key: string) => (errors[key]?.length ? <small className="error" id={`try-${key}-error`}>{errors[key].join(" ")}</small> : null);
  const describedBy = (key: string, help?: string) => [...(errors[key]?.length ? [`try-${key}-error`] : []), ...(help ? [help] : [])].join(" ") || undefined;

  return (
    <section aria-labelledby="routing-try-title">
      <h2 id="routing-try-title">{t("routingTryTitle")}</h2>
      <p className="muted">{t("routingTryHelp")}</p>
      {!chosen ? (
        <p className="muted">{t("routingTryNoNumbers")}</p>
      ) : (
        <form aria-label={t("routingTryTitle")} onSubmit={(e) => void run(e)} noValidate>
          <div className="inline-form">
            <div className="field">
              <label htmlFor="try-number">{t("routingTryNumber")}</label>
              <select id="try-number" value={chosen.id} onChange={(e) => setNumberId(e.target.value)} aria-invalid={errors.numberId ? true : undefined}
                aria-describedby={describedBy("numberId")}>
                {numbers.map((n) => <option key={n.id} value={n.id}>{n.number}</option>)}
              </select>
              {error("numberId")}
            </div>
            <div className="field">
              <label htmlFor="try-caller">{t("routingTryCaller")}</label>
              <input id="try-caller" type="tel" value={caller} onChange={(e) => setCaller(e.target.value)} aria-invalid={errors.callerNumber ? true : undefined}
                aria-describedby={describedBy("callerNumber", "try-caller-help")} />
              <small className="muted" id="try-caller-help">{t("routingTryCallerHelp")}</small>
              {error("callerNumber")}
            </div>
            <div className="field">
              <label htmlFor="try-forwarded">{t("routingTryForwarded")}</label>
              <input id="try-forwarded" type="tel" value={forwarded} onChange={(e) => setForwarded(e.target.value)} aria-invalid={errors.forwardedFrom ? true : undefined}
                aria-describedby={describedBy("forwardedFrom", "try-forwarded-help")} />
              <small className="muted" id="try-forwarded-help">{t("routingTryForwardedHelp")}</small>
              {error("forwardedFrom")}
            </div>
            <div className="field">
              <label htmlFor="try-at">{t("routingTryAt")}</label>
              <input id="try-at" type="datetime-local" value={at} onChange={(e) => setAt(e.target.value)} aria-invalid={errors.at ? true : undefined}
                aria-describedby={describedBy("at", "try-at-help")} />
              <small className="muted" id="try-at-help">{t("routingTryAtHelp")}</small>
              {error("at")}
            </div>
          </div>
          <button type="submit" disabled={busy}>{busy ? t("routingTrying") : t("routingTry")}</button>
        </form>
      )}
      {said && (
        <div role="status">
          {said.map((line, i) => <p key={i}>{line}</p>)}
        </div>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
    </section>
  );
}
