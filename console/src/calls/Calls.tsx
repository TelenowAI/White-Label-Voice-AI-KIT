/**
 * Console → Calls (`/console/calls`, `?agent=<id>` for one agent's, `?number=` for one person's by their number, `?placedBy=me` for those the member
 * placed from the browser; contract/kit-api.yaml listCalls): the current account's calls from the kit's own copy, newest first, a page at a time —
 * when, which agent or member, the other party's number, how long, how it ended (and its wrap-up), and what it was charged. A call the voice platform
 * is still completing is marked so. Above them, the calls on now (LiveNow.tsx). The filters live in the address, so a link can open one.
 *
 * Not here: one call's page (CallPage.tsx); the words for outcomes and directions (callText.ts).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { kitApi, type CallFilter, type CallPage, type CallSummary } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";
import { directionText, durationText, otherNumber, outcomeText } from "./callText";
import { LiveNow } from "./LiveNow";

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
  const [search, setSearch] = useState(number ?? "");
  const [page, setPage] = useState(1);
  const [data, setData] = useState<CallPage | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async (p: number, filter: CallFilter) => {
    try {
      setData(await kitApi.calls(p, filter));
      setFailure(null);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load(page, { agentId, number, placedBy: mine ? "me" : undefined });
  }, [load, page, agentId, number, mine]);

  /** Change one filter in the address, back to the first page. */
  function filterBy(name: string, value: string | null) {
    const next = new URLSearchParams(params);
    if (value) next.set(name, value);
    else next.delete(name);
    setParams(next);
    setPage(1);
  }

  const findNumber = (e: FormEvent) => {
    e.preventDefault();
    filterBy("number", search.trim() || null);
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
        <button type="submit" className="secondary">{t("callsFind")}</button>
        <label className="checkbox">
          <input type="checkbox" checked={mine} onChange={(e) => filterBy("placedBy", e.target.checked ? "me" : null)} />
          {t("callsPlacedByMe")}
        </label>
      </form>
      {(agentId || number || mine) && (
        <p>
          <Link to="/console/calls" onClick={() => { setSearch(""); setPage(1); }}>{t("allCalls")}</Link>
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
                    {directionText(c.direction)} {otherNumber(c) ?? ""}
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
