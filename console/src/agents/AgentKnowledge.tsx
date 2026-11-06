/**
 * Console → one agent → Knowledge (contract/kit-api.yaml listKnowledgeBases, setAgentKnowledge): the account's knowledge
 * bases with the ones the agent answers from ticked, saved all at once; the backend's word when one is refused, and
 * whether the voice platform has it yet.
 *
 * Not here: the bases themselves (knowledge/), the rest of the agent (AgentPage.tsx).
 */
import { useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type Agent, type KnowledgeBase } from "../api";
import { t } from "../text";

export function AgentKnowledge({ agent }: { agent: Agent }) {
  const [bases, setBases] = useState<KnowledgeBase[] | null>(null);
  const [chosen, setChosen] = useState<string[]>(agent.knowledgeBases.map((b) => b.id));
  const [notice, setNotice] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    kitApi.knowledgeBases().then((l) => setBases(l.knowledgeBases), (err) => setError(String(err instanceof Error ? err.message : err)));
  }, []);

  async function save(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    setNotice(null);
    try {
      const saved = await kitApi.setAgentKnowledge(agent.id, chosen);
      setChosen(saved.knowledgeBases.map((b) => b.id));
      setNotice(saved.syncState === "synced" ? t("catalogSaved") : (saved.syncError ?? t("syncPending")));
    } catch (err) {
      setError(err instanceof KitApiError ? Object.values(err.fields).flat().join(" ") || err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section aria-labelledby="agent-knowledge-title">
      <h2 id="agent-knowledge-title">{t("knowledgeTitle")}</h2>
      <p className="muted">{t("agentKnowledgeHelp")}</p>
      {bases && bases.length === 0 && (
        <p className="muted">
          {t("agentKnowledgeNone")} <Link to="/console/knowledge">{t("knowledgeTitle")}</Link>
        </p>
      )}
      {bases && bases.length > 0 && (
        <form onSubmit={save} noValidate aria-label={t("knowledgeTitle")}>
          {bases.map((b) => (
            <label key={b.id} className="checkbox">
              <input
                type="checkbox"
                aria-label={t("answersFrom", { name: b.name })}
                checked={chosen.includes(b.id)}
                onChange={(e) => setChosen((c) => (e.target.checked ? [...c, b.id] : c.filter((x) => x !== b.id)))}
              />
              {b.name}
            </label>
          ))}
          <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveKnowledge")}</button>
          {notice && <p role="status">{notice}</p>}
        </form>
      )}
      {error && <p role="alert" className="error">{error}</p>}
    </section>
  );
}
