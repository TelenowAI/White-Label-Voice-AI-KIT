/**
 * Admin → Audit log (`/admin/audit`; contract/kit-api.yaml listAuditEvents): who did what to whom in the admin panel,
 * newest first, a page at a time — each action in the backend's words, the customer or person it was about, and its
 * details. Read-only.
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type AuditPage as Page } from "../api";
import { t } from "../text";

/** A detail's value as plain text (a role, an amount, a time); nothing for an empty one. */
function said(value: unknown): string {
  if (value === null || value === undefined || value === "") return "—";
  return typeof value === "object" ? JSON.stringify(value) : String(value);
}

export function AuditPage() {
  const [page, setPage] = useState<Page | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async (n: number) => {
    try {
      setPage(await kitApi.auditLog(n));
      setFailure(null);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load(1);
  }, [load]);

  const pages = page ? Math.max(1, Math.ceil(page.total / page.perPage)) : 1;
  return (
    <section aria-labelledby="audit-title">
      <h1 id="audit-title">{t("auditTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {page && page.entries.length === 0 && <p className="muted">{t("auditEmpty")}</p>}
      {page && page.entries.length > 0 && (
        <ul className="senders" aria-label={t("auditTitle")}>
          {page.entries.map((e) => (
            <li key={e.id} data-entry={e.id}>
              <div>
                <strong>{e.label}</strong>
                {e.subject && (
                  <> · {e.subject.type === "customer"
                    ? <Link to={`/admin/customers/${encodeURIComponent(e.subject.id)}`}>{e.subject.label ?? e.subject.id}</Link>
                    : <span className="wrap-anywhere">{e.subject.label ?? e.subject.id}</span>}</>
                )}
              </div>
              <small className="muted">
                {new Date(e.at).toLocaleString()} · {e.by ? e.by.name : t("auditByKit")}
                {Object.entries(e.details).map(([k, v]) => <span key={k}> · {k}: {said(v)}</span>)}
              </small>
            </li>
          ))}
        </ul>
      )}
      {page && pages > 1 && (
        <div className="actions">
          <button type="button" className="secondary" disabled={page.page <= 1} onClick={() => void load(page.page - 1)}>{t("previousPage")}</button>
          <span>{t("pageOf", { page: String(page.page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={page.page >= pages} onClick={() => void load(page.page + 1)}>{t("nextPage")}</button>
        </div>
      )}
    </section>
  );
}
