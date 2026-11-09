/**
 * What a call to a number with no agent does (contract/kit-api.yaml setNumberUnanswered), shown under the agent of a number that has none: a
 * short "no one is available" message (the default; it answers the call), a busy line, or the line unavailable — saying that the last two do
 * not answer the call. A refusal (an agent answers the number now) shows in the backend's words beside the choice, which stays as it was.
 * Every member may change it.
 *
 * Not here: a number with an agent, where this never happens and nothing is offered (NumberCallerId.tsx is shown instead); choosing the agent
 * (NumbersPage.tsx). Used by: NumbersPage.tsx (each number's row).
 */
import { useState } from "react";
import { KitApiError, kitApi, type PhoneNumber, type Unanswered } from "../api";
import { t, type TextKey } from "../text";

interface Props {
  number: PhoneNumber;
  /** The number as saved. */
  onSaved: (n: PhoneNumber) => void;
}

/** The choices, the default first. */
const choices: Record<Unanswered, TextKey> = {
  message: "numbersUnanswered_message",
  busy: "numbersUnanswered_busy",
  unavailable: "numbersUnanswered_unavailable",
};

function message(err: unknown): string {
  if (err instanceof KitApiError && Object.keys(err.fields).length > 0) return Object.values(err.fields).flat().join(" ");
  return err instanceof Error ? err.message : String(err);
}

export function NumberUnanswered({ number: n, onSaved }: Props) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const id = `number-unanswered-${n.id}`;

  async function save(choice: Unanswered) {
    setBusy(true);
    setError(null);
    try {
      onSaved(await kitApi.setNumberUnanswered(n.id, choice));
    } catch (err) {
      setError(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="number-control">
      <label htmlFor={id} className="muted">{t("numbersUnanswered")}</label>
      <select id={id} aria-label={t("numbersUnansweredFor", { number: n.number })} aria-describedby={`${id}-help`} value={n.unanswered} disabled={busy}
        onChange={(e) => void save(e.target.value as Unanswered)}>
        {(Object.keys(choices) as Unanswered[]).map((c) => (
          <option key={c} value={c}>{t(choices[c])}</option>
        ))}
      </select>
      <small id={`${id}-help`} className="muted">{t("numbersUnansweredHelp")}</small>
      {error && <small role="alert" className="error">{error}</small>}
    </div>
  );
}
