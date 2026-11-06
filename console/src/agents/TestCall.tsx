/**
 * An agent's browser test call (contract/kit-api.yaml startTestCall; SELF_HOSTED_KIT_PLAN.md §5 C7): the kit's backend
 * starts the session with the account's own key and answers only what the browser joins with; @telenow/client's
 * `TelenowCall` joins it (`session` option — no key reaches the browser) and reports the call's state and its
 * transcript, shown as it comes: a line still being spoken is replaced until it is final. Leaving the page hangs up.
 *
 * Not here: the agent's editor (AgentPage.tsx). A refusal (paused service, agent not saved yet, too many calls) is the
 * backend's message, shown as it comes.
 */
import { TelenowCall, type CallState, type TranscriptLine } from "@telenow/client";
import { useEffect, useRef, useState } from "react";
import { kitApi } from "../api";
import { t, type TextKey } from "../text";

const stateText: Record<CallState, TextKey> = {
  idle: "callIdle",
  connecting: "callConnecting",
  live: "callLive",
  reconnecting: "callReconnecting",
  ended: "callEnded",
  error: "callError",
};

/** The states in which there is a call to hang up. */
const inCall: CallState[] = ["connecting", "live", "reconnecting"];

/** A line still being spoken replaces the speaker's previous unfinished line; a final one stays. */
function merge(lines: TranscriptLine[], line: TranscriptLine): TranscriptLine[] {
  const last = lines[lines.length - 1];
  const kept = last && !last.isFinal && last.role === line.role ? lines.slice(0, -1) : lines;
  return [...kept, line];
}

export function TestCall({ agentId }: { agentId: string }) {
  const [state, setState] = useState<CallState>("idle");
  const [lines, setLines] = useState<TranscriptLine[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);
  const call = useRef<TelenowCall | null>(null);

  useEffect(
    () => () => {
      call.current?.stop();
      call.current = null;
    },
    [agentId],
  );

  async function start() {
    setStarting(true);
    setFailure(null);
    setLines([]);
    try {
      const { session } = await kitApi.startTestCall(agentId);
      call.current = new TelenowCall({
        session,
        onState: setState,
        onTranscript: (line) => setLines((ls) => merge(ls, line)),
        onError: setFailure,
      });
      await call.current.start();
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setStarting(false);
    }
  }

  function hangUp() {
    call.current?.stop();
    call.current = null;
  }

  return (
    <section className="test-call" aria-labelledby="test-call-title">
      <h2 id="test-call-title">{t("testCallTitle")}</h2>
      <p className="muted">{t("testCallHelp")}</p>
      <p role="status" className={`call-state-${state}`}>
        {t(stateText[state])}
      </p>
      {inCall.includes(state) ? (
        <button type="button" onClick={hangUp}>
          {t("hangUp")}
        </button>
      ) : (
        <button type="button" disabled={starting} onClick={start}>
          {starting ? t("startingCall") : t("startTestCall")}
        </button>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
      {lines.length > 0 && (
        <ol className="transcript" aria-label={t("testCallTitle")}>
          {lines.map((l, i) => (
            <li key={i} className={l.isFinal ? undefined : "partial"}>
              <strong>{t(l.role === "user" ? "callYou" : "callAgent")}:</strong> {l.text}
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
