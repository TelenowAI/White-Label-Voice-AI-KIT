/**
 * Console → one call (`/console/calls/:id`; contract/kit-api.yaml getCall, getCallRecording): who and when, how it
 * ended, its recordings (played through the kit: the `<audio>` loads callRecordingUrl with the session cookie), its
 * transcript (the caller and the agent), and what its analysis found. A call the voice platform is still completing
 * says so: its parts may follow. Below, what the team wrote on it (CallWork.tsx), and a link to every call with the same person. A manager can listen in while it is on (ListenIn.tsx), and
 * the page says who has.
 *
 * Not here: the list (Calls.tsx), the words for outcomes and directions (callText.ts).
 */
import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { callRecordingUrl, kitApi, type CallDetail } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";
import { CallWork } from "./CallWork";
import { ListenIn } from "./ListenIn";
import { directionText, durationText, otherNumber, outcomeText } from "./callText";

/** A name the analysis gave by key (appointment_date), in words. */
function humanKey(key: string): string {
  const words = key.replace(/_/g, " ");
  return words.charAt(0).toUpperCase() + words.slice(1);
}

export function CallPage() {
  const { id = "" } = useParams();
  const [call, setCall] = useState<CallDetail | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    setCall(null);
    kitApi.call(id).then(setCall, (err: unknown) => setFailure(err instanceof Error ? err.message : String(err)));
  }, [id]);

  const number = call ? otherNumber(call) : null;

  return (
    <section aria-labelledby="call-title">
      <p>
        <Link to="/console/calls">{t("allCalls")}</Link>
      </p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {call && (
        <>
          <h1 id="call-title">{number ? t("callTitleNumber", { number }) : t("callTitleBrowser")}</h1>
          {!call.complete && <p role="status" className="muted">{t("callStillCompleting")}</p>}
          {call.canListen && (
            <p>
              <ListenIn callId={call.id} />
            </p>
          )}
          {number && (
            <p>
              <Link to={`/console/calls?${new URLSearchParams({ number })}`}>{t("callsWithNumber")}</Link>
            </p>
          )}
          <dl className="facts">
            {call.requestedBy && (
              <>
                <dt>{t("callRequestedBy")}</dt>
                <dd>{call.requestedBy.me ? t("callYou") : (call.requestedBy.name ?? t("formerMember"))}</dd>
              </>
            )}
            {call.placedBy ? (
              <>
                <dt>{t("callPlacedBy")}</dt>
                <dd>{call.placedBy.me ? t("callYou") : (call.placedBy.name ?? t("formerMember"))}</dd>
              </>
            ) : (
              <>
                <dt>{t("colAgent")}</dt>
                <dd>{call.agent ? <Link to={`/console/agents/${call.agent.id}`}>{call.agent.name}</Link> : t("unknownAgent")}</dd>
              </>
            )}
            <dt>{t("colWhen")}</dt>
            <dd>{call.startedAt ? new Date(call.startedAt).toLocaleString() : "—"}</dd>
            <dt>{t("colDuration")}</dt>
            <dd>{durationText(call.durationSec)}</dd>
            <dt>{t("colOutcome")}</dt>
            <dd>{outcomeText(call.outcome)}</dd>
            {call.charge && (
              <>
                <dt>{t("callCharged")}</dt>
                <dd>{money(call.charge.amount, call.charge.currency)}</dd>
              </>
            )}
            <dt>{t("callDirection")}</dt>
            <dd>{directionText(call.direction)}</dd>
            {call.from && (
              <>
                <dt>{t("callFrom")}</dt>
                <dd>{call.from}</dd>
              </>
            )}
            {call.to && (
              <>
                <dt>{t("callTo")}</dt>
                <dd>{call.to}</dd>
              </>
            )}
            {call.listens.length > 0 && (
              <>
                <dt>{t("listenedBy")}</dt>
                <dd>{call.listens.map((l) => `${l.by ?? t("formerMember")}, ${new Date(l.at).toLocaleString()}`).join("; ")}</dd>
              </>
            )}
            {call.answeredBy && (
              <>
                <dt>{t("callAnsweredBy")}</dt>
                <dd>{call.answeredBy.replace(/_/g, " ")}</dd>
              </>
            )}
          </dl>
          {call.recordings.length > 0 && (
            <section aria-labelledby="recording-title">
              <h2 id="recording-title">{t("recordingsTitle")}</h2>
              {call.recordings.map((r) => (
                <audio key={r.index} controls preload="none" src={callRecordingUrl(call.id, r.index)} className="recording" />
              ))}
            </section>
          )}
          <section aria-labelledby="transcript-title">
            <h2 id="transcript-title">{t("transcriptTitle")}</h2>
            {call.transcript.length === 0 ? (
              <p className="muted">{t("transcriptEmpty")}</p>
            ) : (
              <ol className="transcript" aria-label={t("transcriptTitle")}>
                {call.transcript.map((line, i) => (
                  <li key={i}>
                    <strong>{t(line.role === "user" ? "callCaller" : "callAgent")}:</strong> {line.text}
                  </li>
                ))}
              </ol>
            )}
          </section>
          {call.analysis && (
            <section aria-labelledby="analysis-title">
              <h2 id="analysis-title">{t("analysisTitle")}</h2>
              <dl className="facts">
                {call.analysis.summary && (
                  <>
                    <dt>{t("analysisSummary")}</dt>
                    <dd>{call.analysis.summary}</dd>
                  </>
                )}
                {call.analysis.sentiment && (
                  <>
                    <dt>{t("analysisSentiment")}</dt>
                    <dd>{call.analysis.sentiment}</dd>
                  </>
                )}
                {call.analysis.disposition && (
                  <>
                    <dt>{t("analysisDisposition")}</dt>
                    <dd>{call.analysis.disposition.replace(/_/g, " ")}</dd>
                  </>
                )}
                {Object.entries(call.analysis.customData ?? {}).map(([key, value]) => (
                  <div key={key}>
                    <dt>{humanKey(key)}</dt>
                    <dd>{typeof value === "string" ? value : JSON.stringify(value)}</dd>
                  </div>
                ))}
                {Array.isArray(call.analysis.qa) && call.analysis.qa.length > 0 && (
                  <>
                    <dt>{t("analysisChecks")}</dt>
                    <dd>
                      <ul>
                        {call.analysis.qa.map((check, i) => (
                          <li key={check.key ?? i}>
                            {humanKey(check.key ?? "")}: {t(check.met ? "analysisCheckMet" : "analysisCheckNotMet")}
                            {check.evidence && <small className="muted"> · {check.evidence}</small>}
                          </li>
                        ))}
                      </ul>
                    </dd>
                  </>
                )}
                {Array.isArray(call.analysis.actionItems) && call.analysis.actionItems.length > 0 && (
                  <>
                    <dt>{t("analysisActions")}</dt>
                    <dd>
                      <ul>
                        {call.analysis.actionItems.map((item, i) => (
                          <li key={i}>{typeof item === "string" ? item : JSON.stringify(item)}</li>
                        ))}
                      </ul>
                    </dd>
                  </>
                )}
              </dl>
            </section>
          )}
          <CallWork callId={call.id} />
        </>
      )}
    </section>
  );
}
