/**
 * A campaign's people on its page: one group at a time with how many stand where, each with how their call went, a page at a time; downloading what is showing; and calling again those nobody answered or everyone whose
 * call failed, only after asking, because each call is charged. The counts on the buttons are the backend's, from what it has read so far, and what comes back says how many it really was.
 *
 * Not here: fetching them (CampaignPage.tsx, which looks again while calling), the words for an outcome (words.ts).
 */
import { useState } from "react";
import { campaignExportUrl, kitApi, type Campaign, type CampaignPeopleGroup, type CampaignRetryScope, type CampaignTarget, type CampaignTargetPage } from "../api";
import { t, type TextKey } from "../text";
import { outcomeWord } from "./words";

const GROUPS: { value: CampaignPeopleGroup; word: TextKey }[] = [
  { value: "waiting", word: "groupWaiting" }, { value: "answered", word: "groupAnswered" }, { value: "unanswered", word: "groupUnanswered" }, { value: "failed", word: "groupFailed" },
];

interface Props {
  campaign: Campaign;
  found: CampaignTargetPage | null;
  show: CampaignPeopleGroup | undefined;
  onShow: (group: CampaignPeopleGroup | undefined) => void;
  page: number;
  onPage: (page: number) => void;
  onRetried: (c: Campaign) => void;
}

function outcome(x: CampaignTarget): string {
  if (x.state === "saving") return t("outcomeSaving");
  if (x.state === "waiting") return t("outcomeWaiting");
  return outcomeWord(x.outcome ?? "");
}

export function CampaignPeople({ campaign, found, show, onShow, page, onPage, onRetried }: Props) {
  const pages = found ? Math.max(1, Math.ceil(found.total / found.perPage)) : 1;
  const everyone = found ? Object.values(found.counts).reduce((a, b) => a + b, 0) : 0;
  return (
    <>
      <h2>{t("colPeople")}</h2>
      {found && everyone === 0 && <p className="muted">{t("campaignNoPeople")}</p>}
      {found && everyone > 0 && (
        <>
          <div className="actions" role="group" aria-label={t("campaignPeopleGroups")}>
            <button type="button" className="secondary" aria-pressed={show === undefined} onClick={() => onShow(undefined)}>{t("groupAll", { n: String(everyone) })}</button>
            {GROUPS.map((g) => (
              <button key={g.value} type="button" className="secondary" aria-pressed={show === g.value} onClick={() => onShow(g.value)}>{t(g.word, { n: String(found.counts[g.value]) })}</button>
            ))}
          </div>
          <Retry campaign={campaign} counts={found.counts} onRetried={onRetried} />
          <p><a href={campaignExportUrl(campaign.id, show)} download>{t("campaignDownload")}</a></p>
          {found.total === 0 && <p className="muted">{t("campaignNoneInGroup")}</p>}
          {found.total > 0 && (
            <div className="table-scroll">
              <table className="list" aria-label={t("colPeople")}>
                <thead>
                  <tr>
                    <th scope="col">{t("colPhone")}</th>
                    <th scope="col">{t("colOutcome")}</th>
                    <th scope="col">{t("colDetails")}</th>
                  </tr>
                </thead>
                <tbody>
                  {found.targets.map((x) => (
                    <tr key={x.id} data-target={x.id}>
                      <td>{x.phoneNumber}</td>
                      <td>
                        {outcome(x)}
                        {x.tryingAgain && <small className="muted"> · {t("outcomeTryingAgain")}</small>}
                        {x.state === "done" && x.attempts !== null && x.attempts > 1 && <small className="muted"> · {t("attemptsCount", { n: String(x.attempts) })}</small>}
                      </td>
                      <td>{Object.entries(x.variables).map(([k, v]) => `${k}: ${v}`).join(" · ")}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
      {found && pages > 1 && (
        <nav className="pager">
          <button type="button" className="secondary" disabled={page <= 1} onClick={() => onPage(page - 1)}>{t("previousPage")}</button>
          <span>{t("pageOf", { page: String(page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={page >= pages} onClick={() => onPage(page + 1)}>{t("nextPage")}</button>
        </nav>
      )}
    </>
  );
}

function Retry({ campaign, counts, onRetried }: { campaign: Campaign; counts: CampaignTargetPage["counts"]; onRetried: (c: Campaign) => void }) {
  const [asking, setAsking] = useState<CampaignRetryScope | null>(null);
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const all = counts.unanswered + counts.failed;

  async function retry(scope: CampaignRetryScope) {
    setBusy(true);
    setFailure(null);
    setDone(null);
    try {
      const answer = await kitApi.retryCampaign(campaign.id, scope);
      setAsking(null);
      setDone(answer.retried === 0 ? t("retryNobody") : t(answer.campaign.status === "paused" ? "retryDonePaused" : "retryDone", { n: String(answer.retried) }));
      onRetried(answer.campaign);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  if (campaign.status === "cancelled" || campaign.status === "saving" || campaign.status === "failed" || campaign.syncState === "pending") return null;
  return (
    <>
      {asking ? (
        <div className="actions">
          <span>{t(asking === "unanswered" ? "retryAskUnanswered" : "retryAskAll", { n: String(asking === "unanswered" ? counts.unanswered : all) })}</span>
          <button type="button" disabled={busy} onClick={() => void retry(asking)}>{t("retryYes")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(null)}>{t("cancel")}</button>
        </div>
      ) : (
        all > 0 && (
          <div className="actions">
            {counts.unanswered > 0 && <button type="button" className="secondary" onClick={() => setAsking("unanswered")}>{t("retryUnanswered", { n: String(counts.unanswered) })}</button>}
            {counts.failed > 0 && <button type="button" className="secondary" onClick={() => setAsking("all")}>{t("retryAll", { n: String(all) })}</button>}
          </div>
        )
      )}
      {done && <p role="status">{done}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
    </>
  );
}
