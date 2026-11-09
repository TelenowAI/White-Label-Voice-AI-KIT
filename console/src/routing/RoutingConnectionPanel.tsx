/**
 * Whether the voice platform asks this server about calls to the account's routed numbers (RoutingPage `connection`, shown once a number was first
 * routed): its state in a sentence, when it last had an answer, and its last problem in a sentence with when (routingWords.ts; never the
 * platform's own text). The account's managers test each routed number (contract/kit-api.yaml checkRouting): the platform asks this server about
 * a pretend call, and the result says how fast the answer came and what it would do, or why there was none. A refusal (the number no longer
 * routed, too many tests a minute, the platform not reached) shows in the backend's words beside the number.
 *
 * Not here: routing a number (RoutedNumbers.tsx). Used by: CallRoutingPage.tsx.
 */
import { useState } from "react";
import { kitApi, type RoutingCheck, type RoutingConnection, type RoutingNumber } from "../api";
import { t } from "../text";
import { problemWords, stateWords } from "./routingWords";

interface Props {
  connection: RoutingConnection;
  numbers: RoutingNumber[];
  canManage: boolean;
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function moment(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleString();
}

/** What the platform's test found, in sentences. */
export function checkWords(check: RoutingCheck): string {
  const ms = String(check.elapsedMs);
  if (!check.answered) return [t("routingCheckNoAnswer", { ms }), problemWords(check.problem)].filter(Boolean).join(" ");
  if (check.action === "answer") return t("routingCheckAnswer", { ms });
  if (check.action === "refuse") return t("routingCheckRefuse", { ms });
  return t("routingCheckAnswered", { ms });
}

export function RoutingConnectionPanel({ connection: c, numbers, canManage }: Props) {
  const problem = problemWords(c.problem);
  const routed = numbers.filter((n) => n.routed);
  return (
    <section aria-labelledby="routing-connection-title">
      <h2 id="routing-connection-title">{t("routingConnectionTitle")}</h2>
      <p className={c.state === "connected" ? "check" : "check check-warning"}>{stateWords(c.state)}</p>
      <p className="muted">{c.lastAnsweredAt ? t("routingLastAnswered", { at: moment(c.lastAnsweredAt) }) : t("routingNeverAnswered")}</p>
      {problem && (
        <p className="check check-warning">{c.problemAt ? t("routingLastProblemAt", { at: moment(c.problemAt), problem }) : t("routingLastProblem", { problem })}</p>
      )}
      {canManage && routed.length > 0 && (
        <>
          <h3>{t("routingTestTitle")}</h3>
          <p className="muted">{t("routingTestHelp")}</p>
          <ul className="checks" aria-label={t("routingTestTitle")}>
            {routed.map((n) => <Test key={n.id} number={n} />)}
          </ul>
        </>
      )}
    </section>
  );
}

function Test({ number: n }: { number: RoutingNumber }) {
  const [busy, setBusy] = useState(false);
  const [found, setFound] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  async function test() {
    setBusy(true);
    setFound(null);
    setFailure(null);
    try {
      setFound(checkWords(await kitApi.checkRouting(n.id)));
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <li>
      <span>{n.number}</span>{" "}
      <button type="button" className="secondary" disabled={busy} aria-label={t("routingTestNumber", { number: n.number })} onClick={() => void test()}>
        {busy ? t("routingTesting") : t("routingTest")}
      </button>
      {found && <p role="status">{found}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
    </li>
  );
}
