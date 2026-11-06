/**
 * Console → Knowledge (`/console/knowledge`; contract/kit-api.yaml listKnowledgeBases, createKnowledgeBase): the
 * account's knowledge bases, newest first — each with how many of its documents are ready, being read and failed, and
 * the agents that answer from it — and making a new one (the backend's word on a field).
 *
 * Not here: one base and its documents (KnowledgeBasePage.tsx), which bases an agent uses (agents/AgentKnowledge.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { KitApiError, kitApi, type KnowledgeBase } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function KnowledgePage() {
  const [bases, setBases] = useState<KnowledgeBase[] | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setBases((await kitApi.knowledgeBases()).knowledgeBases);
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="knowledge-title">
      <h1 id="knowledge-title">{t("knowledgeTitle")}</h1>
      <p className="muted">{t("knowledgeHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {bases && bases.length === 0 && <p className="muted">{t("knowledgeEmpty")}</p>}
      {bases && bases.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("knowledgeTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("kbName")}</th>
                <th scope="col">{t("colDocuments")}</th>
                <th scope="col">{t("colUsedBy")}</th>
              </tr>
            </thead>
            <tbody>
              {bases.map((b) => (
                <tr key={b.id}>
                  <td>
                    <Link to={`/console/knowledge/${b.id}`}>{b.name}</Link>
                    {b.description && <small className="muted"> · {b.description}</small>}
                    {b.syncState !== "synced" && (
                      <div className={`sync sync-${b.syncState}`}>
                        <span>{t(b.syncState === "pending" ? "syncPending" : "syncFailed")}</span>
                        {b.syncError && <small>{b.syncError}</small>}
                      </div>
                    )}
                  </td>
                  <td>{t("kbCounts", Object.fromEntries(Object.entries(b.documentCounts).map(([k, v]) => [k, String(v)])))}</td>
                  <td>{b.agents.map((a) => a.name).join(", ")}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <NewBase />
    </section>
  );
}

function NewBase() {
  const navigate = useNavigate();
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const made = await kitApi.createKnowledgeBase(name.trim(), description.trim() || null);
      navigate(`/console/knowledge/${made.id}`);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
      setBusy(false);
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate aria-label={t("newKnowledgeBase")}>
      <div className="field">
        <label htmlFor="kb-name">{t("kbName")}</label>
        <input id="kb-name" value={name} onChange={(e) => setName(e.target.value)} aria-invalid={errors.name ? true : undefined} />
        {errors.name && <small className="error">{errors.name.join(" ")}</small>}
      </div>
      <div className="field">
        <label htmlFor="kb-description">{t("kbDescription")}</label>
        <input id="kb-description" value={description} onChange={(e) => setDescription(e.target.value)} />
        {errors.description && <small className="error">{errors.description.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("createKnowledgeBase")}</button>
      {failure && <p role="alert" className="error">{failure}</p>}
    </form>
  );
}
