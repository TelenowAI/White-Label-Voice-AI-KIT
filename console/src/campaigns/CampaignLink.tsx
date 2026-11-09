/**
 * A campaign's people from a link, on its page (contract/kit-api.yaml setCampaignSource, removeCampaignSource): a CSV at a web address, such as a
 * spreadsheet published to the web as CSV, that the kit's server reads now and again every `linkEveryMinutes` while the campaign is not finished,
 * each read adding only the numbers the campaign does not have yet and never taking anyone off.
 *
 * With no link: giving one, then what its first read added (as adding people from a CSV file says it), the backend's reason beside the field when
 * the link cannot be read, and any other refusal as an alert. With one: the link as text (the customer's own, never a link to follow), when it was
 * last read, how many it has added, why it could not be read last time, and stopping (the people it added stay). Shown while the operator allows
 * links, or while the campaign has one (then saying when links are turned off); a campaign that takes no more people takes no new link, but one it
 * follows still shows.
 *
 * Not here: reading the campaign and the backend's limits, and looking again while it calls (CampaignPage.tsx, which places this and says whether
 * the campaign still takes people); adding people from a CSV file (CampaignPage.tsx). Used by: CampaignPage.tsx.
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Campaign, type CampaignLimits, type CampaignLinkRead } from "../api";
import { t } from "../text";

interface Props {
  campaign: Campaign;
  /** The backend's limits (whether links are allowed, how often one is read); null until they are read. */
  limits: CampaignLimits | null;
  /** Whether the campaign still takes people (it has not finished). */
  open: boolean;
  onChanged: (c: Campaign) => void;
  /** People were added: the page reads its people again. */
  onAdded: () => void;
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function when(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleString();
}

/** What a read added, as adding people from a CSV file says it: lines with no number and numbers without a country code only when there are some. */
function summary(read: CampaignLinkRead): string {
  return [
    t("campaignLinkAdded", { added: String(read.added), alreadyThere: String(read.alreadyThere) }),
    ...(read.skipped > 0 ? [t("campaignLinkSkipped", { n: String(read.skipped) })] : []),
    ...(read.noCountryCode > 0 ? [t("peopleNoCountryCode", { n: String(read.noCountryCode) })] : []),
  ].join(" ");
}

export function CampaignLink({ campaign, limits, open, onChanged, onAdded }: Props) {
  const [url, setUrl] = useState("");
  const [refused, setRefused] = useState<string[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const source = campaign.source;
  const takesLink = source === null && open && limits?.linksAllowed === true;

  async function follow(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setRefused([]);
    setFailure(null);
    setDone(null);
    try {
      const answer = await kitApi.setCampaignSource(campaign.id, url.trim());
      setUrl("");
      setDone(summary(answer.added));
      onChanged(answer.campaign);
      onAdded();
    } catch (err) {
      if (err instanceof KitApiError && (err.fields.url?.length ?? 0) > 0) setRefused(err.fields.url);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  async function stop() {
    setBusy(true);
    setFailure(null);
    setDone(null);
    try {
      await kitApi.removeCampaignSource(campaign.id);
      onChanged({ ...campaign, source: null });
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  if (source === null && !takesLink) return null;
  return (
    <section aria-labelledby="campaign-link-title">
      <h2 id="campaign-link-title">{t("campaignLinkTitle")}</h2>
      {source ? (
        <>
          <p className="wrap-anywhere">{source.url}</p>
          <p className="muted">
            {source.checkedAt ? t("campaignLinkLastRead", { at: when(source.checkedAt) }) : t("campaignLinkNotRead")} · {t("campaignLinkAddedSoFar", { n: String(source.added) })}
          </p>
          {source.problem && <p className="check check-warning">{source.problem}</p>}
          {limits && !limits.linksAllowed && <p className="note">{t("campaignLinkOff")}</p>}
          <p>
            <button type="button" className="secondary" disabled={busy} aria-describedby="campaign-link-stop-help" onClick={() => void stop()}>{t("campaignLinkStop")}</button>{" "}
            <small className="muted" id="campaign-link-stop-help">{t("campaignLinkStopHelp")}</small>
          </p>
        </>
      ) : (
        limits && (
          <form onSubmit={(e) => void follow(e)} noValidate>
            <div className="field">
              <label htmlFor="campaign-link">{t("campaignLinkUrl")}</label>
              <input id="campaign-link" type="url" inputMode="url" value={url} onChange={(e) => setUrl(e.target.value)}
                aria-invalid={refused.length > 0 ? true : undefined} aria-describedby={refused.length > 0 ? "campaign-link-error campaign-link-help" : "campaign-link-help"} />
              <small className="muted" id="campaign-link-help">{t("campaignLinkHelp", { n: String(limits.linkEveryMinutes) })}</small>
              {refused.length > 0 && <small className="error" id="campaign-link-error">{refused.join(" ")}</small>}
            </div>
            <button type="submit" disabled={busy || url.trim() === ""}>{busy ? t("campaignLinkReading") : t("campaignLinkUse")}</button>
          </form>
        )
      )}
      {done && <p role="status">{done}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
    </section>
  );
}
