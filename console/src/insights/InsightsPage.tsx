/**
 * Console → Insights (`/console/insights`; contract/kit-api.yaml getInsights): how the account's calls went over whole days in the
 * browser's time zone (always sent) — the backend's default period until other days are picked, every agent's calls or one's — as
 * figures (calls; answered, with their share of the calls; minutes; how long an answered call lasted on average; what was charged, in
 * each currency), the outcomes, sentiments and channels with their counts and shares, each day as bars (DaysChart.tsx), and each
 * agent's calls, whose name opens the calls list of that agent and those days. The period the backend answered shows back in the
 * form; its word on a field shows beside that field, any other refusal as an alert.
 *
 * Not here: the figures themselves (the backend's App\Kit\Reports\Insights); the words for outcomes, channels and sentiments
 * (calls/callText.ts); how a figure reads (insightText.ts); the calls list (calls/Calls.tsx).
 * Used by console/ConsoleArea.tsx.
 */
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type Agent, type InsightCount, type Insights, type InsightsQuery } from "../api";
import { channelText, durationText, outcomeText, sentimentText } from "../calls/callText";
import { t, type TextKey } from "../text";
import { DaysChart } from "./DaysChart";
import { countText, minutesText, shareText, spendText } from "./insightText";

/** The fields of the period form; the backend's word on any other (the time zone) shows as an alert. */
const FORM_FIELDS = ["from", "to", "agentId"];

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** Every saved agent of the account, page by page: the period can be narrowed to any of them. */
async function allAgents(): Promise<Agent[]> {
  const first = await kitApi.agents(1);
  const pages = Math.max(1, Math.ceil(first.total / first.perPage));
  const rest = await Promise.all(Array.from({ length: pages - 1 }, (_, i) => kitApi.agents(i + 2)));
  return [first, ...rest].flatMap((p) => p.agents);
}

/** The calls list of one agent over the days shown (Calls.tsx reads these from the address). */
function callsOf(agentId: string, data: Insights): string {
  return `/console/calls?${new URLSearchParams({ agent: agentId, from: data.from, to: data.to })}`;
}

export function InsightsPage() {
  const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [agentId, setAgentId] = useState("");
  const [agents, setAgents] = useState<Agent[]>([]);
  const [data, setData] = useState<Insights | null>(null);
  const [busy, setBusy] = useState(true);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  // Only the latest question's answer is shown: an earlier one that arrives late is dropped.
  const asked = useRef(0);

  const load = useCallback(async (q: InsightsQuery) => {
    const mine = ++asked.current;
    setBusy(true);
    try {
      const answer = await kitApi.insights({ ...q, timeZone });
      if (mine !== asked.current) return;
      setData(answer);
      setFrom(answer.from);
      setTo(answer.to);
      setErrors({});
      setFailure(null);
    } catch (err) {
      if (mine !== asked.current) return;
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        setErrors(err.fields);
        const elsewhere = Object.entries(err.fields).filter(([field]) => !FORM_FIELDS.includes(field)).flatMap(([, reasons]) => reasons);
        setFailure(elsewhere.length > 0 ? elsewhere.join(" ") : null);
      } else {
        setFailure(message(err));
      }
    } finally {
      if (mine === asked.current) setBusy(false);
    }
  }, [timeZone]);

  useEffect(() => {
    void load({});
  }, [load]);

  // Without the list the form still offers every agent's calls; the period's own answer says what went wrong.
  useEffect(() => {
    allAgents().then(setAgents, () => setAgents([]));
  }, []);

  function submit(e: FormEvent) {
    e.preventDefault();
    void load({ from, to, agentId });
  }

  return (
    <section aria-labelledby="insights-title">
      <h1 id="insights-title">{t("insightsTitle")}</h1>
      <p className="muted">{t("insightsHelp", { zone: data?.timeZone ?? timeZone })}</p>
      <form className="period" onSubmit={submit} aria-label={t("periodLabel")}>
        <div className="field">
          <label htmlFor="insights-from">{t("periodFrom")}</label>
          <input id="insights-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          {errors.from && <small className="error">{errors.from.join(" ")}</small>}
        </div>
        <div className="field">
          <label htmlFor="insights-to">{t("periodTo")}</label>
          <input id="insights-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          {errors.to && <small className="error">{errors.to.join(" ")}</small>}
        </div>
        <div className="field">
          <label htmlFor="insights-agent">{t("colAgent")}</label>
          <select id="insights-agent" value={agentId} onChange={(e) => setAgentId(e.target.value)}>
            <option value="">{t("insightsAllAgents")}</option>
            {agents.map((a) => (
              <option key={a.id} value={a.id}>{a.name}</option>
            ))}
          </select>
          {errors.agentId && <small className="error">{errors.agentId.join(" ")}</small>}
        </div>
        <button type="submit" className="secondary" disabled={busy}>{t("periodShow")}</button>
      </form>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!data && busy && <p className="muted" aria-busy="true">{t("loading")}</p>}
      {data && data.calls === 0 && <p className="muted">{t("insightsEmpty")}</p>}
      {data && data.calls > 0 && (
        <div aria-busy={busy}>
          <Figures data={data} />
          <Counts id="insights-outcomes" title="insightsOutcomes" head="colOutcome" counts={data.outcomes} word={outcomeText} />
          <Counts id="insights-sentiments" title="insightsSentiments" help="insightsSentimentsHelp" head="analysisSentiment" counts={data.sentiments} word={sentimentText} />
          <Counts id="insights-channels" title="insightsChannels" head="callsChannel" counts={data.channels} word={channelText} />
          <DaysChart days={data.days} />
          <AgentsTable data={data} />
        </div>
      )}
    </section>
  );
}

function Figures({ data }: { data: Insights }) {
  const share = shareText(data.answered, data.calls);
  return (
    <dl className="facts">
      <div><dt>{t("insightsCalls")}</dt><dd>{countText(data.calls)}</dd></div>
      <div>
        <dt>{t("insightsAnswered")}</dt>
        <dd>{share === null ? countText(data.answered) : t("insightsAnsweredValue", { n: countText(data.answered), share })}</dd>
      </div>
      <div><dt>{t("insightsMinutes")}</dt><dd>{minutesText(data.seconds)}</dd></div>
      <div><dt>{t("insightsAverage")}</dt><dd>{durationText(data.averageSeconds)}</dd></div>
      <div><dt>{t("insightsCharged")}</dt><dd>{spendText(data.spend) || t("insightsNothingCharged")}</dd></div>
    </dl>
  );
}

/** One kind of count (outcomes, sentiments or channels), each with its share of all those counted; null reads as none. */
function Counts({ id, title, help, head, counts, word }: {
  id: string;
  title: TextKey;
  help?: TextKey;
  head: TextKey;
  counts: InsightCount[];
  word: (key: string) => string;
}) {
  const whole = counts.reduce((sum, c) => sum + c.count, 0);
  return (
    <section aria-labelledby={id}>
      <h2 id={id}>{t(title)}</h2>
      {help && <p className="muted">{t(help)}</p>}
      {counts.length === 0 ? (
        <p className="muted">{t("insightsNoneCounted")}</p>
      ) : (
        <div className="table-scroll">
          <table className="list" aria-labelledby={id}>
            <thead>
              <tr>
                <th scope="col">{t(head)}</th>
                <th scope="col">{t("colCalls")}</th>
                <th scope="col">{t("colShare")}</th>
              </tr>
            </thead>
            <tbody>
              {counts.map((c) => (
                <tr key={c.key ?? ""}>
                  <th scope="row">{c.key === null ? t("insightsNone") : word(c.key)}</th>
                  <td>{countText(c.count)}</td>
                  <td>{shareText(c.count, whole) ?? ""}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

function AgentsTable({ data }: { data: Insights }) {
  return (
    <section aria-labelledby="insights-agents">
      <h2 id="insights-agents">{t("insightsAgents")}</h2>
      <div className="table-scroll">
        <table className="list" aria-labelledby="insights-agents">
          <thead>
            <tr>
              <th scope="col">{t("colAgent")}</th>
              <th scope="col">{t("colCalls")}</th>
              <th scope="col">{t("colAnswered")}</th>
              <th scope="col">{t("colMinutes")}</th>
              <th scope="col">{t("colCharged")}</th>
            </tr>
          </thead>
          <tbody>
            {data.agents.map((a) => (
              <tr key={a.agentId ?? ""}>
                <th scope="row">
                  {a.agentId ? <Link to={callsOf(a.agentId, data)}>{a.name ?? t("unknownAgent")}</Link> : t("insightsNoAgent")}
                </th>
                <td>{countText(a.calls)}</td>
                <td>{countText(a.answered)}</td>
                <td>{minutesText(a.seconds)}</td>
                <td>{spendText(a.spend)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
