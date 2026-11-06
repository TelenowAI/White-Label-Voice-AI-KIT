/**
 * Console → one campaign (`/console/campaigns/:id`; contract/kit-api.yaml getCampaign, listCampaignTargets,
 * addCampaignTargets, startCampaign, pauseCampaign, cancelCampaign, deleteCampaign, getCampaignReach,
 * getCampaignVariables): where it stands in the console's words (and why a running one is not calling: its window, when
 * it opens), how many people it has reached, what sending its list met, its settings and changing them; who can be called now and the
 * columns its agent uses (read live, once); how it went; adding people from a CSV (read in the browser, checked against the backend's
 * size limit first); start / resume / pause, and cancel or delete only after asking; its people. While it is saving or calling it is looked at again.
 *
 * Not here: the list and making a campaign (CampaignsPage.tsx); its people (CampaignPeople.tsx); how it went (CampaignProgress.tsx); changing its settings (CampaignSettingsForm.tsx); the words for a status or outcome (words.ts).
 */
import { useCallback, useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { KitApiError, kitApi, type Campaign, type CampaignLimits, type CampaignPeopleGroup, type CampaignReach, type CampaignTargetPage } from "../api";
import { t } from "../text";
import { CampaignPeople } from "./CampaignPeople";
import { CampaignProgress } from "./CampaignProgress";
import { CampaignSettingsForm } from "./CampaignSettingsForm";
import { statusWord, voicemailWord } from "./words";

/** How soon a campaign is looked at again: while something is being saved, and while it is calling. */
const SAVING_RECHECK_MS = 5000;
const CALLING_RECHECK_MS = 30000;
const CALLING: Campaign["status"][] = ["queued", "running"];
const OPEN: Campaign["status"][] = ["draft", "queued", "running", "paused"];
const CHANGEABLE: Campaign["status"][] = [...OPEN, "completed"];

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function when(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleString();
}

function readText(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result));
    reader.onerror = () => reject(reader.error);
    reader.readAsText(file);
  });
}

function list(items: string[]): string {
  return new Intl.ListFormat(undefined, { type: "disjunction" }).format(items);
}

export function CampaignPage() {
  const { id = "" } = useParams();
  const [campaign, setCampaign] = useState<Campaign | null>(null);
  const [targets, setTargets] = useState<CampaignTargetPage | null>(null);
  const [limits, setLimits] = useState<CampaignLimits | null>(null);
  const [page, setPage] = useState(1);
  const [show, setShow] = useState<CampaignPeopleGroup | undefined>(undefined);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const [c, people] = await Promise.all([kitApi.campaign(id), kitApi.campaignTargets(id, page, show)]);
      setCampaign(c);
      setTargets(people);
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, [id, page, show]);

  useEffect(() => {
    void load();
  }, [load]);

  useEffect(() => {
    kitApi.campaigns().then((l) => setLimits(l.limits), () => undefined);
  }, []);

  const saving = campaign !== null && (campaign.syncState === "pending" || (targets?.targets.some((x) => x.state === "saving") ?? false));
  const calling = campaign !== null && CALLING.includes(campaign.status);
  useEffect(() => {
    if (!saving && !calling) return;
    const timer = setTimeout(() => void load(), saving ? SAVING_RECHECK_MS : CALLING_RECHECK_MS);
    return () => clearTimeout(timer);
  }, [saving, calling, campaign, targets, load]);

  return (
    <section aria-labelledby="campaign-title">
      <p>
        <Link to="/console/campaigns">{t("backToCampaigns")}</Link>
      </p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {campaign && (
        <>
          <h1 id="campaign-title">{campaign.name}</h1>
          <Standing campaign={campaign} />
          <Settings campaign={campaign} limits={limits} onSaved={setCampaign} />
          {campaign.syncState === "synced" && OPEN.includes(campaign.status) && <Live campaignId={campaign.id} limits={limits} />}
          <Actions campaign={campaign} onChanged={setCampaign} onError={setFailure} />
          {OPEN.includes(campaign.status) && limits && <AddPeople campaignId={campaign.id} limits={limits} onAdded={load} />}
          {campaign.status !== "saving" && campaign.status !== "failed" && <CampaignProgress campaignId={campaign.id} />}
          <CampaignPeople
            campaign={campaign}
            found={targets}
            show={show}
            onShow={(group) => { setShow(group); setPage(1); }}
            page={page}
            onPage={setPage}
            onRetried={(c) => { setCampaign(c); void load(); }}
          />
          <Delete campaign={campaign} onError={setFailure} />
        </>
      )}
    </section>
  );
}

function Standing({ campaign: c }: { campaign: Campaign }) {
  const pushed = c.pushed.added + c.pushed.duplicates + c.pushed.suppressed + c.pushed.skipped > 0;
  return (
    <>
      <div className={`sync sync-${c.status === "failed" ? "failed" : c.syncState === "pending" ? "pending" : "synced"}`} role="status">
        <span>{statusWord(c.status)}</span>
        {c.status === "failed" && c.syncError && <small>{c.syncError}</small>}
        {c.waiting && (
          <small>{c.waiting.reason === "window" && c.waiting.opensAt ? t("waitingWindow", { at: when(c.waiting.opensAt) }) : t("waitingOther")}</small>
        )}
      </div>
      <p>{t("campaignCounts", { people: String(c.counts.people), answered: String(c.counts.answered), notReached: String(c.counts.notReached) })}</p>
      {pushed && (
        <p className="muted">
          {t("pushedSummary", { added: String(c.pushed.added), suppressed: String(c.pushed.suppressed), skipped: String(c.pushed.skipped), duplicates: String(c.pushed.duplicates) })}
        </p>
      )}
    </>
  );
}

function Settings({ campaign: c, limits, onSaved }: { campaign: Campaign; limits: CampaignLimits | null; onSaved: (c: Campaign) => void }) {
  const [open, setOpen] = useState(false);
  const [done, setDone] = useState<string | null>(null);
  const s = c.settings;
  const window = s.windowStart && s.windowEnd ? t("campaignWindowRange", { from: s.windowStart, until: s.windowEnd, timezone: s.timezone }) : t("campaignAnyTime");
  if (open && limits) {
    return (
      <CampaignSettingsForm
        campaign={c}
        limits={limits}
        onClose={() => setOpen(false)}
        onSaved={(saved) => {
          onSaved(saved);
          setOpen(false);
          setDone(saved.syncState === "pending" ? t("campaignSettingsOnItsWay") : t("campaignSettingsSaved"));
        }}
      />
    );
  }
  return (
    <>
      <dl className="facts">
        <dt>{t("campaignAgent")}</dt>
        <dd>{c.agent ? <Link to={`/console/agents/${c.agent.id}`}>{c.agent.name}</Link> : ""}</dd>
        <dt>{t("campaignCallerId")}</dt>
        <dd>{c.callerId?.number ?? t("campaignNoCallerId")}</dd>
        <dt>{t("campaignWindow")}</dt>
        <dd>{window}</dd>
        <dt>{t("campaignVoicemail")}</dt>
        <dd>{voicemailWord(s.machineDetection)}</dd>
        <dt>{t("campaignAttempts")}</dt>
        <dd>{s.maxAttempts}</dd>
        <dt>{t("campaignRetryGap")}</dt>
        <dd>{t("campaignGapValue", { n: String(s.retryGapMinutes) })} · {t(s.retryOnNoAnswer ? "campaignRetryOn" : "campaignRetryOff")}</dd>
        <dt>{t("campaignConcurrency")}</dt>
        <dd>{s.concurrency}</dd>
      </dl>
      {done && <p role="status">{done}</p>}
      {CHANGEABLE.includes(c.status) && c.syncState !== "pending" && limits && (
        <p><button type="button" className="secondary" onClick={() => { setDone(null); setOpen(true); }}>{t("campaignChangeSettings")}</button></p>
      )}
    </>
  );
}

/** Who can be called now and the columns its agent uses — read live from the voice platform, once. */
function Live({ campaignId, limits }: { campaignId: string; limits: CampaignLimits | null }) {
  const [reach, setReach] = useState<CampaignReach | null>(null);
  const [variables, setVariables] = useState<string[] | null>(null);
  const [problem, setProblem] = useState<string | null>(null);

  useEffect(() => {
    kitApi.campaignReach(campaignId).then(setReach, (err) => setProblem(message(err)));
    kitApi.campaignVariables(campaignId).then((v) => setVariables(v.variables.map((x) => x.name)), (err) => setProblem(message(err)));
  }, [campaignId]);

  return (
    <>
      {reach && reach.pending > 0 && (
        <p className="muted">
          {reach.blocked
            ? t("reachBlocked")
            : t("reachSummary", { dialable: String(reach.dialable), pending: String(reach.pending), dnc: String(reach.onDoNotCall), consent: String(reach.withoutConsent) })}
          {!reach.blocked && reach.withoutConsent > 0 && <> <Link to="/console/consent">{t("campaignReachConsent")}</Link></>}
        </p>
      )}
      {variables && variables.length > 0 && <p className="muted">{t("campaignVariables", { names: variables.join(", ") })}</p>}
      {variables && variables.length === 0 && limits && limits.phoneColumns.length > 0 && (
        <p className="muted">{t("campaignVariablesNone", { column: limits.phoneColumns[0] })}</p>
      )}
      {problem && <p className="muted">{problem}</p>}
    </>
  );
}

function Actions({ campaign: c, onChanged, onError }: { campaign: Campaign; onChanged: (c: Campaign) => void; onError: (m: string) => void }) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);

  async function act(run: () => Promise<Campaign>) {
    setBusy(true);
    try {
      onChanged(await run());
      setAsking(false);
    } catch (err) {
      onError(message(err));
    } finally {
      setBusy(false);
    }
  }

  if (!OPEN.includes(c.status) || c.syncState === "pending") return null;
  return (
    <div className="actions">
      {asking ? (
        <>
          <span>{t("confirmCancelCampaign", { name: c.name })}</span>
          <button type="button" disabled={busy} onClick={() => act(() => kitApi.cancelCampaign(c.id))}>{t("yesCancelCampaign")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
        </>
      ) : (
        <>
          {c.status === "draft" && <button type="button" disabled={busy} onClick={() => act(() => kitApi.startCampaign(c.id))}>{t("startCampaign")}</button>}
          {c.status === "paused" && <button type="button" disabled={busy} onClick={() => act(() => kitApi.startCampaign(c.id))}>{t("resumeCampaign")}</button>}
          {CALLING.includes(c.status) && (
            <button type="button" className="secondary" disabled={busy} onClick={() => act(() => kitApi.pauseCampaign(c.id))}>{t("pauseCampaign")}</button>
          )}
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(true)}>{t("cancelCampaign")}</button>
        </>
      )}
    </div>
  );
}

function AddPeople({ campaignId, limits, onAdded }: { campaignId: string; limits: CampaignLimits; onAdded: () => void }) {
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const max = String(Math.floor(limits.importMaxBytes / (1024 * 1024)));

  async function pick(e: { target: HTMLInputElement }) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setError(null);
    setDone(null);
    if (file.size > limits.importMaxBytes) {
      setError(t("fileTooBig", { max }));
      return;
    }
    setBusy(true);
    try {
      const added = await kitApi.addCampaignTargets(campaignId, await readText(file));
      setDone(t("peopleAdded", { added: String(added.added), skipped: String(added.skipped) }) + (added.noCountryCode > 0 ? ` ${t("peopleNoCountryCode", { n: String(added.noCountryCode) })}` : ""));
      onAdded();
    } catch (err) {
      setError(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? Object.values(err.fields).flat().join(" ") : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="field">
      <h2>{t("addPeople")}</h2>
      <label htmlFor="campaign-csv">{t("campaignCsv")}</label>
      <small className="muted">{t("addPeopleHelp", { columns: list(limits.phoneColumns), rows: String(limits.importMaxRows), max })}</small>
      <input id="campaign-csv" type="file" accept=".csv,text/csv" disabled={busy} onChange={pick} />
      {error && <small role="alert" className="error">{error}</small>}
      {done && <small role="status">{done}</small>}
    </div>
  );
}

function Delete({ campaign, onError }: { campaign: Campaign; onError: (m: string) => void }) {
  const navigate = useNavigate();
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);

  async function remove() {
    setBusy(true);
    try {
      await kitApi.deleteCampaign(campaign.id);
      navigate("/console/campaigns");
    } catch (err) {
      onError(message(err));
      setBusy(false);
    }
  }

  return (
    <div className="danger-zone">
      {asking ? (
        <div className="actions">
          <span>{t("confirmDeleteCampaign", { name: campaign.name })}</span>
          <button type="button" disabled={busy} onClick={remove}>{t("yesDelete")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
        </div>
      ) : (
        <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("deleteCampaign")}</button>
      )}
    </div>
  );
}
