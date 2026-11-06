/**
 * Console → Campaigns (`/console/campaigns`; contract/kit-api.yaml listCampaigns, createCampaign): the account's
 * campaigns, newest first — each with its agent, where it stands and how many people it has called — and making a new
 * one: its name and agent, then its settings (CampaignFields.tsx); the backend's word on a field.
 *
 * Not here: one campaign (CampaignPage.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { KitApiError, kitApi, type Agent, type Campaign, type CampaignLimits, type PhoneNumber } from "../api";
import { t } from "../text";
import { CampaignFields, fieldsToInput, newCampaignFields } from "./CampaignFields";
import { statusWord } from "./words";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** Every saved agent of the account, page by page. */
async function allAgents(): Promise<Agent[]> {
  const first = await kitApi.agents(1);
  const pages = Math.max(1, Math.ceil(first.total / first.perPage));
  const rest = await Promise.all(Array.from({ length: pages - 1 }, (_, i) => kitApi.agents(i + 2)));
  return [first, ...rest].flatMap((p) => p.agents);
}

export function CampaignsPage() {
  const [campaigns, setCampaigns] = useState<Campaign[] | null>(null);
  const [limits, setLimits] = useState<CampaignLimits | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      const list = await kitApi.campaigns();
      setCampaigns(list.campaigns);
      setLimits(list.limits);
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="campaigns-title">
      <h1 id="campaigns-title">{t("campaignsTitle")}</h1>
      <p className="muted">{t("campaignsHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {campaigns && campaigns.length === 0 && <p className="muted">{t("campaignsEmpty")}</p>}
      {campaigns && campaigns.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("campaignsTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("campaignName")}</th>
                <th scope="col">{t("campaignAgent")}</th>
                <th scope="col">{t("colStatus")}</th>
                <th scope="col">{t("colPeople")}</th>
              </tr>
            </thead>
            <tbody>
              {campaigns.map((c) => (
                <tr key={c.id}>
                  <td><Link to={`/console/campaigns/${c.id}`}>{c.name}</Link></td>
                  <td>{c.agent?.name ?? ""}</td>
                  <td>{statusWord(c.status)}</td>
                  <td>{t("campaignCounts", { people: String(c.counts.people), answered: String(c.counts.answered), notReached: String(c.counts.notReached) })}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {limits && <NewCampaign limits={limits} />}
    </section>
  );
}

function NewCampaign({ limits }: { limits: CampaignLimits }) {
  const navigate = useNavigate();
  const [agents, setAgents] = useState<Agent[]>([]);
  const [numbers, setNumbers] = useState<PhoneNumber[]>([]);
  const [form, setForm] = useState({ name: "", agentId: "" });
  const [fields, setFields] = useState(() => newCampaignFields(limits));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    allAgents().then(setAgents, (err) => setFailure(message(err)));
    kitApi.numbers().then((n) => setNumbers(n.numbers.filter((x) => !x.releasing)), () => undefined);
  }, []);

  const set = (key: keyof typeof form) => (e: { target: { value: string } }) => setForm((f) => ({ ...f, [key]: e.target.value }));

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const made = await kitApi.createCampaign({ name: form.name.trim(), agentId: form.agentId, ...fieldsToInput(fields) });
      navigate(`/console/campaigns/${made.id}`);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
      setBusy(false);
    }
  }

  const error = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("newCampaign")}>
      <h2>{t("newCampaign")}</h2>
      <div className="field">
        <label htmlFor="campaign-name">{t("campaignName")}</label>
        <input id="campaign-name" maxLength={limits.nameMaxLength} value={form.name} onChange={set("name")} />
        {error("name")}
      </div>
      <div className="field">
        <label htmlFor="campaign-agent">{t("campaignAgent")}</label>
        <select id="campaign-agent" value={form.agentId} onChange={set("agentId")}>
          <option value="">{t("chooseOne")}</option>
          {agents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </select>
        {error("agentId")}
      </div>
      <CampaignFields values={fields} onChange={(over) => setFields((f) => ({ ...f, ...over }))} limits={limits} numbers={numbers} errors={errors} />
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("createCampaign")}</button>
    </form>
  );
}
