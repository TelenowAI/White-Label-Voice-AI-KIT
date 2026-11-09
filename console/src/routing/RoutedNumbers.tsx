/**
 * Call routing's numbers (contract/kit-api.yaml setNumberRouting): each of the account's numbers with the agent that answers it and whether its
 * calls follow the rules, with a change not saved yet or refused shown as on the Numbers page. The account's managers switch routing on and off
 * for a number; one with no agent cannot be routed (its agent answers the calls no rule takes), so its switch is off with a way to Numbers to
 * give it one. A refusal (the server not reachable from the voice platform, the platform not reached) shows in the backend's words beside the
 * number. Everyone else sees only whether each is routed.
 *
 * Not here: reading the numbers (CallRoutingPage.tsx, which reads the page again after a change, for the connection). Used by: CallRoutingPage.tsx.
 */
import { useState } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type PhoneNumber, type RoutingNumber } from "../api";
import { t } from "../text";

interface Props {
  numbers: RoutingNumber[];
  canManage: boolean;
  /** A number as saved after its switch changed. */
  onChanged: (n: PhoneNumber) => void;
}

function message(err: unknown): string {
  if (err instanceof KitApiError && Object.keys(err.fields).length > 0) return Object.values(err.fields).flat().join(" ");
  return err instanceof Error ? err.message : String(err);
}

export function RoutedNumbers({ numbers, canManage, onChanged }: Props) {
  return (
    <section aria-labelledby="routing-numbers-title">
      <h2 id="routing-numbers-title">{t("routingNumbersTitle")}</h2>
      <p className="muted">{t("routingNumbersHelp")}</p>
      {numbers.length === 0 ? (
        <p className="muted">
          {t("routingNoNumbers")} <Link to="/console/numbers">{t("routingToNumbers")}</Link>
        </p>
      ) : (
        <div className="table-scroll">
          <table className="list" aria-label={t("routingNumbersTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("colNumber")}</th>
                <th scope="col">{t("colAnsweredBy")}</th>
                <th scope="col">{t("routingColRoute")}</th>
              </tr>
            </thead>
            <tbody>
              {numbers.map((n) => <Row key={n.id} number={n} canManage={canManage} onChanged={onChanged} />)}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function Row({ number: n, canManage, onChanged }: { number: RoutingNumber; canManage: boolean; onChanged: (n: PhoneNumber) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  // Turning routing off stays possible for a routed number whatever its agent.
  const needsAgent = n.agent === null && !n.routed;

  async function route(routed: boolean) {
    setBusy(true);
    setError(null);
    try {
      onChanged(await kitApi.setNumberRouting(n.id, routed));
    } catch (err) {
      setError(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <tr data-number={n.id}>
      <td>
        {n.number}
        {n.syncState !== "synced" && (
          <div className={`sync sync-${n.syncState}`}>
            <span>{t(n.syncState === "failed" ? "syncFailed" : "syncPending")}</span>
            {n.syncError && <small>{n.syncError}</small>}
          </div>
        )}
      </td>
      <td>{n.agent?.name ?? t("numbersNoAgent")}</td>
      <td>
        {canManage ? (
          <>
            <input type="checkbox" role="switch" checked={n.routed} disabled={busy || needsAgent} aria-label={t("routingRouteNumber", { number: n.number })}
              aria-describedby={needsAgent ? `routing-needs-agent-${n.id}` : undefined} onChange={(e) => void route(e.target.checked)} />
            {needsAgent && (
              <small className="muted" id={`routing-needs-agent-${n.id}`}>
                {" "}{t("routingNeedsAgent")} <Link to="/console/numbers">{t("routingToNumbers")}</Link>
              </small>
            )}
            {error && <small role="alert" className="error">{error}</small>}
          </>
        ) : (
          t(n.routed ? "routingRouted" : "routingNotRouted")
        )}
      </td>
    </tr>
  );
}
