/**
 * One lost number in the Numbers page's list (contract/kit-api.yaml LostNumber, getLostNumberBack): the number, why it was given up (its rent
 * not paid, or by the voice platform), until when it can be got back, the agent that answered it (and that it was that agent's caller id), and
 * what getting it back costs — setup and the first month, or "not priced" while there are no prices. Getting it back only after a confirm that
 * shows that cost: back at once (the list says so and is read again), or being got back by hand before a date (said in the row). A number
 * already asked for shows that instead of the action. A refusal (balance short, too late, back already, not now) shows in the backend's words.
 *
 * Not here: reading the list (LostNumbers.tsx). Used by: LostNumbers.tsx.
 */
import { useState } from "react";
import { kitApi, type LostNumber, type PhoneNumber } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";

interface Props {
  lost: LostNumber;
  /** It came back at once, as the number given. */
  onRestored: (n: PhoneNumber) => void;
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function day(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleDateString();
}

export function LostNumberRow({ lost: n, onRestored }: Props) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  /** It could not come back at once: being got back by hand, before `until`. */
  const [requested, setRequested] = useState<{ until: string | null } | null>(null);
  const cost = n.setupPrice && n.monthlyPrice
    ? { setup: money(n.setupPrice.amount, n.setupPrice.currency), monthly: money(n.monthlyPrice.amount, n.monthlyPrice.currency) }
    : null;

  async function getBack() {
    setBusy(true);
    setError(null);
    try {
      const done = await kitApi.getNumberBack(n.id);
      setAsking(false);
      if (done.outcome === "restored") onRestored(done.number);
      else setRequested({ until: done.until ?? null });
    } catch (err) {
      setError(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <tr data-lost={n.id}>
      <td>{n.number}</td>
      <td>{t(n.reason === "unpaid" ? "numbersLost_unpaid" : "numbersLost_other")}</td>
      <td>{n.until ? day(n.until) : ""}</td>
      <td>{n.agent ? (n.wasCallerId ? t("numbersLostCallerId", { agent: n.agent.name }) : n.agent.name) : t("numbersNoAgent")}</td>
      <td>{cost ? t("numbersLostCost", cost) : t("notPriced")}</td>
      <td>
        {requested ? (
          <span role="status">{requested.until ? t("numbersGettingBack", { until: day(requested.until) }) : t("numbersGettingBackNoDate")}</span>
        ) : n.askedAt ? (
          <span>{t("numbersLostAsked", { asked: day(n.askedAt) })}</span>
        ) : asking ? (
          <div className="actions">
            <span>{cost ? t("numbersConfirmGetBack", { number: n.number, ...cost }) : t("numbersConfirmGetBackUnpriced", { number: n.number })}</span>
            <button type="button" disabled={busy} onClick={getBack}>{t("numbersYesGetBack")}</button>
            <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
          </div>
        ) : (
          <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("numbersGetBack")}</button>
        )}
        {error && <small role="alert" className="error">{error}</small>}
      </td>
    </tr>
  );
}
