/**
 * Console → Calls (`/console/calls`, `?agent=<id>` for one agent's, `?number=` for one person's by their number, `?placedBy=me` for those the member
 * placed from the browser, `?from=` and `?to=` for whole days (YYYY-MM-DD, in the browser's time zone, sent with them), `?outcome=` and `?channel=`
 * for one of each; contract/kit-api.yaml listCalls): the current account's calls from the kit's own copy, newest first, a page at a time —
 * when, which agent or member, the direction and the other party's number (or "Web chat" for a conversation in the chat box on the customer's
 * website), how long, how it ended (and its wrap-up), and what it was charged. A call the voice platform
 * is still completing is marked so. Above them, the calls on now (LiveNow.tsx). The filters live in the address, so a link can open one
 * (Insights does, for an agent's days); the backend's word on a day shows beside it. The same calls download as a CSV file (exportCalls, its
 * times in the browser's time zone), but not "only calls I placed", which the file cannot narrow to, so the link is not offered then.
 *
 * Not here: one call's page (CallPage.tsx); the words for outcomes, channels and directions (callText.ts).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { KitApiError, callsExportUrl, kitApi, type CallFilter, type CallPage, type CallSummary } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";
import { CHANNEL_FILTERS, channelText, directionOf, durationText, otherNumber, outcomeText } from "./callText";
import { LiveNow } from "./LiveNow";

/** The filters typed or chosen in the form; the backend's word on any other (the time zone) shows as an alert. */
const FORM_FIELDS = ["from", "to"];

/** Who was on the account's side: its agent, or the member who placed it from the browser. */
function whoText(c: CallSummary): string {
  if (c.agent) return c.agent.name;
  if (c.placedBy) return c.placedBy.me ? t("callYou") : (c.placedBy.name ?? t("formerMember"));
  return t("unknownAgent");
}

export function Calls() {
  const [params, setParams] = useSearchParams();
  const agentId = params.get("agent") ?? undefined;
  const number = params.get("number") ?? undefined;
  const mine = params.get("placedBy") === "me";
  const from = params.get("from") ?? undefined;
  const to = params.get("to") ?? undefined;
  const outcome = params.get("outcome") ?? undefined;
  const channel = params.get("channel") ?? undefined;
  // The days are the browser's: its time zone goes with them (and with the file, whose times are in it).
  const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const [search, setSearch] = useState(number ?? "");
  const [fromDay, setFromDay] = useState(from ?? "");
  const [toDay, setToDay] = useState(to ?? "");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<CallPage | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  // An address changed by a link, Back or "All calls" replaces what is typed, here while rendering (as admin/PeriodPicker.tsx does).
  const [shown, setShown] = useState(params.toString());
  if (shown !== params.toString()) {
    setShown(params.toString());
    setSearch(number ?? "");
    setFromDay(from ?? "");
    setToDay(to ?? "");
  }

  const load = useCallback(async (p: number, filter: CallFilter) => {
    try {
      setData(await kitApi.calls(p, filter));
      setFailure(null);
      setErrors({});
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        setErrors(err.fields);
        const elsewhere = Object.entries(err.fields).filter(([field]) => !FORM_FIELDS.includes(field)).flatMap(([, reasons]) => reasons);
        setFailure(elsewhere.length > 0 ? elsewhere.join(" ") : null);
      } else {
        setFailure(err instanceof Error ? err.message : String(err));
      }
    }
  }, []);

  useEffect(() => {
    void load(page, { agentId, number, placedBy: mine ? "me" : undefined, from, to, timeZone: from || to ? timeZone : undefined, outcome, channel });
  }, [load, page, agentId, number, mine, from, to, timeZone, outcome, channel]);

  /** Change one filter in the address, back to the first page. */
  function filterBy(name: string, value: string | null) {
    const next = new URLSearchParams(params);
    if (value) next.set(name, value);
    else next.delete(name);
    setParams(next);
    setPage(1);
  }

  /** The typed number and days, and the chosen outcome and channel (with `over` changed), into the address, back to the first page. */
  function applyFilters(over: Record<string, string> = {}) {
    const values: Record<string, string> = { number: search.trim(), from: fromDay, to: toDay, outcome: outcome ?? "", channel: channel ?? "", ...over };
    const next = new URLSearchParams(params);
    for (const [name, value] of Object.entries(values)) {
      if (value) next.set(name, value);
      else next.delete(name);
    }
    setParams(next);
    setPage(1);
  }

  const findNumber = (e: FormEvent) => {
    e.preventDefault();
    applyFilters();
  };

  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;
  const agentName = data?.calls.find((c) => c.agent?.id === agentId)?.agent?.name;

  return (
    <section aria-labelledby="calls-title">
      <h1 id="calls-title">{agentId && agentName ? t("callsOfAgent", { name: agentName }) : t("callsTitle")}</h1>
      <LiveNow />
      <form onSubmit={findNumber} className="inline-form calls-filter" role="search">
        <label className="field">
          <span>{t("callsFindNumber")}</span>
          <input type="search" inputMode="tel" value={search} onChange={(e) => setSearch(e.target.value)} />
        </label>
        <div className="field">
          <label htmlFor="calls-from">{t("periodFrom")}</label>
          <input id="calls-from" type="date" value={fromDay} onChange={(e) => setFromDay(e.target.value)} />
          {errors.from && <small className="error">{errors.from.join(" ")}</small>}
        </div>
        <div className="field">
          <label htmlFor="calls-to">{t("periodTo")}</label>
          <input id="calls-to" type="date" value={toDay} onChange={(e) => setToDay(e.target.value)} />
          {errors.to && <small className="error">{errors.to.join(" ")}</small>}
        </div>
        <div className="field">
          <label htmlFor="calls-outcome">{t("callsOutcome")}</label>
          <select id="calls-outcome" value={outcome ?? ""} onChange={(e) => applyFilters({ outcome: e.target.value })}>
            <option value="">{t("anyOption")}</option>
            {[...(data?.outcomes ?? []), ...(outcome && !(data?.outcomes ?? []).includes(outcome) ? [outcome] : [])].map((o) => (
              <option key={o} value={o}>{outcomeText(o)}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="calls-channel">{t("callsChannel")}</label>
          <select id="calls-channel" value={channel ?? ""} onChange={(e) => applyFilters({ channel: e.target.value })}>
            <option value="">{t("anyOption")}</option>
            {[...CHANNEL_FILTERS, ...(channel && !(CHANNEL_FILTERS as readonly string[]).includes(channel) ? [channel] : [])].map((c) => (
              <option key={c} value={c}>{channelText(c)}</option>
            ))}
          </select>
        </div>
        <button type="submit" className="secondary">{t("callsFind")}</button>
        <label className="checkbox">
          <input type="checkbox" checked={mine} onChange={(e) => filterBy("placedBy", e.target.checked ? "me" : null)} />
          {t("callsPlacedByMe")}
        </label>
      </form>
      {(agentId || number || mine || from || to || outcome || channel) && (
        <p>
          <Link to="/console/calls" onClick={() => { setSearch(""); setPage(1); }}>{t("allCalls")}</Link>
        </p>
      )}
      {!mine && (
        <p>
          <a href={callsExportUrl({ agentId, number, from, to, timeZone, outcome, channel })} download>{t("downloadCsv")}</a>
        </p>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && data.calls.length === 0 && <p className="muted">{t("callsEmpty")}</p>}
      {data && data.calls.length > 0 && (
        <div className="table-scroll">
          <table className="list">
            <thead>
              <tr>
                <th scope="col">{t("colWhen")}</th>
                <th scope="col">{t("colAgent")}</th>
                <th scope="col">{t("colNumber")}</th>
                <th scope="col">{t("colDuration")}</th>
                <th scope="col">{t("colOutcome")}</th>
                <th scope="col">{t("colCost")}</th>
              </tr>
            </thead>
            <tbody>
              {data.calls.map((c) => (
                <tr key={c.id} data-call={c.id}>
                  <td>
                    <Link to={`/console/calls/${c.id}`}>{c.startedAt ? new Date(c.startedAt).toLocaleString() : "—"}</Link>
                  </td>
                  <td>{whoText(c)}</td>
                  <td>
                    {directionOf(c)} {otherNumber(c) ?? ""}
                  </td>
                  <td>{durationText(c.durationSec)}</td>
                  <td>
                    {outcomeText(c.outcome)}
                    {c.wrapup && <small>{c.outcome ? " · " : ""}{c.wrapup}</small>}
                    {!c.complete && <small className="muted"> · {t("syncPending")}</small>}
                  </td>
                  <td>{c.charge ? money(c.charge.amount, c.charge.currency) : ""}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && pages > 1 && (
        <nav className="pager">
          <button type="button" className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t("previousPage")}
          </button>
          <span>{t("pageOf", { page: String(page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>
            {t("nextPage")}
          </button>
        </nav>
      )}
    </section>
  );
}
