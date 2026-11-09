/**
 * A number's caller id switch (contract/kit-api.yaml setNumberCallerId), shown under the agent of a number that has one: whether this is the
 * number that agent's calls come from when a call names none (a call from the agent's page, a campaign with no caller id). An agent has one at
 * most — the server turns its other one off — so the page reads the numbers again after a change. A refusal (the number has no agent any more,
 * it is still being bought) shows in the backend's words beside the switch, which stays as it was. Every member may change it.
 *
 * Not here: a number with no agent (NumberUnanswered.tsx); choosing the agent (NumbersPage.tsx). Used by: NumbersPage.tsx (each number's row).
 */
import { useState } from "react";
import { KitApiError, kitApi, type PhoneNumber } from "../api";
import { t } from "../text";

interface Props {
  number: PhoneNumber;
  /** The agent that answers it. */
  agent: { id: string; name: string };
  /** The number as saved; the page reads the list again, as the agent's other number may have changed too. */
  onSaved: (n: PhoneNumber) => void;
}

function message(err: unknown): string {
  if (err instanceof KitApiError && Object.keys(err.fields).length > 0) return Object.values(err.fields).flat().join(" ");
  return err instanceof Error ? err.message : String(err);
}

export function NumberCallerId({ number: n, agent, onSaved }: Props) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const help = `number-caller-id-help-${n.id}`;

  async function save(on: boolean) {
    setBusy(true);
    setError(null);
    try {
      onSaved(await kitApi.setNumberCallerId(n.id, on));
    } catch (err) {
      setError(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="number-control">
      <label className="checkbox">
        <input type="checkbox" role="switch" checked={n.agentCallerId} disabled={busy} aria-label={t("numbersCallerIdFor", { number: n.number, agent: agent.name })}
          aria-describedby={help} onChange={(e) => void save(e.target.checked)} />
        {t("numbersCallerId", { agent: agent.name })}
      </label>
      <small id={help} className="muted">{t("numbersCallerIdHelp", { agent: agent.name })}</small>
      {error && <small role="alert" className="error">{error}</small>}
    </div>
  );
}
