/**
 * Console → Follow-ups (`/console/followups`; contract/kit-api.yaml listFollowUps, setFollowUp): the signed-in member's own follow-ups from calls, those
 * to do soonest first (one past its time says so), or those done latest first, a page at a time; each links to its call and is ticked off here.
 *
 * Not here: writing one (CallWork.tsx, on a call).
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type FollowUpPage } from "../api";
import { t } from "../text";
import { otherNumber } from "./callText";

export function FollowUpsPage() {
  const [done, setDone] = useState(false);
  const [page, setPage] = useState(1);
  const [data, setData] = useState<FollowUpPage | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const load = useCallback(async (p: number, d: boolean) => {
    try {
      setData(await kitApi.followUps(p, d));
      setFailure(null);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load(page, done);
  }, [load, page, done]);

  async function toggle(id: string, value: boolean) {
    setBusy(id);
    try {
      await kitApi.setFollowUp(id, value);
      await load(page, done);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(null);
    }
  }

  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;
  const now = Date.now();

  return (
    <section aria-labelledby="follow-ups-title">
      <h1 id="follow-ups-title">{t("followUpsTitle")}</h1>
      <p className="muted">{t("followUpsHelp")}</p>
      <div className="tabs" role="group" aria-label={t("followUpsTitle")}>
        <button type="button" className={done ? "secondary" : undefined} aria-pressed={!done} onClick={() => { setDone(false); setPage(1); }}>
          {t("followUpsToDo")}
        </button>
        <button type="button" className={done ? undefined : "secondary"} aria-pressed={done} onClick={() => { setDone(true); setPage(1); }}>
          {t("followUpsDone")}
        </button>
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && data.followUps.length === 0 && <p className="muted">{t(done ? "followUpsNoneDone" : "followUpsNoneToDo")}</p>}
      {data && data.followUps.length > 0 && (
        <ul className="follow-ups">
          {data.followUps.map((f) => {
            const late = !f.doneAt && new Date(f.dueAt).getTime() < now;
            const number = f.call ? otherNumber(f.call) : null;
            return (
              <li key={f.id} className={f.doneAt ? "done" : late ? "late" : undefined}>
                <label className="checkbox">
                  <input type="checkbox" checked={f.doneAt !== null} disabled={busy !== null} onChange={(e) => void toggle(f.id, e.target.checked)} />
                  <span>{f.body}</span>
                </label>
                <small className="muted">
                  {t(late ? "followUpLate" : "followUpDue", { when: new Date(f.dueAt).toLocaleString() })}
                  {f.call && (
                    <>
                      {" · "}
                      <Link to={`/console/calls/${f.call.id}`}>{number ? t("callTitleNumber", { number }) : t("callTitleBrowser")}</Link>
                    </>
                  )}
                  {f.doneAt && ` · ${t("followUpDoneBy", { name: f.doneBy ?? t("formerMember") })}`}
                </small>
              </li>
            );
          })}
        </ul>
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
