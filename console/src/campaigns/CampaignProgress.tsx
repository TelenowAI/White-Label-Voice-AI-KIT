/**
 * How a campaign went (getCampaignProgress), read from the voice platform only when asked for: how many were reached and why the rest were not, how long people talked, which attempt reached them, and which hours of the day worked.
 *
 * Not here: each person (CampaignPeople.tsx). What it cost is not here either: a customer's wallet lists that call by call.
 */
import { useState } from "react";
import { kitApi, type CampaignProgress as Progress } from "../api";
import { t } from "../text";

const MINUTE = 60;

function length(seconds: number): string {
  return t("durationMinSec", { minutes: String(Math.floor(seconds / MINUTE)), seconds: String(seconds % MINUTE) });
}

function hour(h: number): string {
  return `${String(h).padStart(2, "0")}:00`;
}

export function CampaignProgress({ campaignId }: { campaignId: string }) {
  const [found, setFound] = useState<Progress | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function read() {
    setBusy(true);
    setFailure(null);
    try {
      setFound(await kitApi.campaignProgress(campaignId));
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <h2>{t("progressTitle")}</h2>
      <p><button type="button" className="secondary" disabled={busy} onClick={() => void read()}>{t(found ? "progressAgain" : "progressShow")}</button></p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {found && <Figures p={found} />}
    </>
  );
}

function Figures({ p }: { p: Progress }) {
  const hours = p.byHour.called.flatMap((called, h) => (called > 0 ? [{ h, called, reached: p.byHour.reached[h] ?? 0 }] : []));
  return (
    <>
      <dl className="facts">
        <dt>{t("progressPeople")}</dt><dd>{p.people}</dd>
        <dt>{t("progressCalled")}</dt><dd>{p.called}</dd>
        <dt>{t("progressReached")}</dt><dd>{p.reached}</dd>
        <dt>{t("progressNotReached")}</dt><dd>{p.notReached}</dd>
        <dt>{t("progressWaiting")}</dt><dd>{p.waiting}</dd>
        <dt>{t("progressOnDoNotCall")}</dt><dd>{p.onDoNotCall}</dd>
        <dt>{t("progressLastHour")}</dt><dd>{p.calledLastHour}</dd>
      </dl>
      {p.called === 0 && <p className="muted">{t("progressNothing")}</p>}
      {p.notReached > 0 && (
        <>
          <h3>{t("progressWhy")}</h3>
          <dl className="facts">
            <dt>{t("progressNoAnswer")}</dt><dd>{p.whyNotReached.noAnswer}</dd>
            <dt>{t("progressBusy")}</dt><dd>{p.whyNotReached.busy}</dd>
            <dt>{t("progressVoicemail")}</dt><dd>{p.whyNotReached.voicemail}</dd>
            <dt>{t("progressCouldNotConnect")}</dt><dd>{p.whyNotReached.couldNotConnect}</dd>
          </dl>
        </>
      )}
      {p.reached > 0 && (
        <>
          <h3>{t("progressTalk")}</h3>
          <dl className="facts">
            <dt>{t("progressTalkTotal")}</dt><dd>{length(p.talk.totalSeconds)}</dd>
            {p.talk.averageSeconds !== null && <><dt>{t("progressTalkAverage")}</dt><dd>{length(p.talk.averageSeconds)}</dd></>}
            <dt>{t("progressNobodySpoke")}</dt><dd>{p.talk.nobodySpoke}</dd>
            <dt>{t("progressUnder15")}</dt><dd>{p.talk.under15}</dd>
            <dt>{t("progressUnder30")}</dt><dd>{p.talk.under30}</dd>
            <dt>{t("progressUnder60")}</dt><dd>{p.talk.under60}</dd>
            <dt>{t("progressUnder120")}</dt><dd>{p.talk.under120}</dd>
            <dt>{t("progressOver120")}</dt><dd>{p.talk.over120}</dd>
          </dl>
          <h3>{t("progressAttempts")}</h3>
          <dl className="facts">
            <dt>{t("progressFirst")}</dt><dd>{p.reachedOnAttempt.first}</dd>
            <dt>{t("progressSecond")}</dt><dd>{p.reachedOnAttempt.second}</dd>
            <dt>{t("progressThirdOrLater")}</dt><dd>{p.reachedOnAttempt.thirdOrLater}</dd>
          </dl>
        </>
      )}
      {hours.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("progressHours", { timezone: p.byHour.timezone })}>
            <caption>{t("progressHours", { timezone: p.byHour.timezone })}</caption>
            <thead>
              <tr>
                <th scope="col">{t("progressHourCol")}</th>
                <th scope="col">{t("progressCalled")}</th>
                <th scope="col">{t("progressReached")}</th>
              </tr>
            </thead>
            <tbody>
              {hours.map((x) => (
                <tr key={x.h}>
                  <td>{hour(x.h)}</td>
                  <td>{x.called}</td>
                  <td>{x.reached}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}
