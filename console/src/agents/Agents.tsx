/**
 * Console → Agents (`/console/agents`; contract/kit-api.yaml listAgents): the current account's agents, the latest
 * changed first, a page at a time, each saying whether it takes calls and whether the voice platform has its latest
 * change (saved, saving with the reason it is still trying, or not saved with why), with its logo small beside its name
 * (decorative: hidden when it does not load). Links to building a new one.
 *
 * Not here: one agent's page, its editor and its test call (AgentPage.tsx, TestCall.tsx).
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type AgentPage } from "../api";
import { t } from "../text";
import { SyncState } from "./SyncState";

export function Agents() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<AgentPage | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async (p: number) => {
    try {
      setData(await kitApi.agents(p));
      setFailure(null);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load(page);
  }, [load, page]);

  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;

  return (
    <section aria-labelledby="agents-title">
      <h1 id="agents-title">{t("agentsTitle")}</h1>
      <p>
        <Link to="/console/agents/new">{t("newAgent")}</Link>
      </p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && data.agents.length === 0 && <p className="muted">{t("agentsEmpty")}</p>}
      {data && data.agents.length > 0 && (
        <div className="table-scroll">
          <table className="list">
            <thead>
              <tr>
                <th scope="col">{t("colName")}</th>
                <th scope="col">{t("colStatus")}</th>
                <th scope="col">{t("colSaved")}</th>
                <th scope="col">{t("colUpdated")}</th>
              </tr>
            </thead>
            <tbody>
              {data.agents.map((a) => (
                <tr key={a.id} data-agent={a.id}>
                  <td>
                    {a.logoUrl && <img className="agent-logo-thumb" src={a.logoUrl} alt="" onError={(e) => { e.currentTarget.hidden = true; }} />}
                    <Link to={`/console/agents/${a.id}`}>{a.name}</Link>
                  </td>
                  <td>{t(a.isActive ? "agentActive" : "agentPaused")}</td>
                  <td>
                    <SyncState agent={a} />
                  </td>
                  <td>{new Date(a.updatedAt).toLocaleDateString()}</td>
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
