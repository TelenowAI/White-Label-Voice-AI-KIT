/**
 * Console → new agent → starting from a ready-made agent (contract/kit-api.yaml listOfferedStarterAgents,
 * createAgentFromStarter): above the blank form, the starters the operator offers, each with its name, description and
 * a "Use this" button (AgentFromStarter; with none offered, or when they cannot be read, nothing shows and the blank form
 * is the page). Using one makes the agent and opens its page, which says once which starter it was made from and what is
 * left to do on it, one line for each of the backend's `toRedo` (a voice setup not offered here, call steps left as a
 * draft) (AgentStarterNotice). As with a duplicate (AgentDuplicate.tsx), the notice travels in the router state of that one
 * move and is taken out of it as soon as it is shown, so a reload does not show it again. A refusal is an alert by the
 * button used.
 *
 * Not here: the new agent's page, which places both (AgentPage.tsx); the operator publishing starters
 * (admin/StarterAgentsPage.tsx); what the backend makes from a starter (App\Kit\Agents\StarterAgents).
 */
import { useEffect, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { kitApi, type AgentCopyToDo, type OfferedStarter } from "../api";
import { t } from "../text";

/** What the new agent's page is told, once, in the router state: which agent it is for, the starter's name, what is left to do. */
interface StarterMade {
  id: string;
  starter: string;
  toRedo: AgentCopyToDo[];
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function AgentFromStarter() {
  const navigate = useNavigate();
  const [starters, setStarters] = useState<OfferedStarter[]>([]);
  const [busy, setBusy] = useState<string | null>(null);
  const [failure, setFailure] = useState<{ id: string; message: string } | null>(null);

  useEffect(() => {
    // The blank form works without them: a list that cannot be read offers none.
    kitApi.offeredStarterAgents().then((r) => setStarters(r.starters), () => setStarters([]));
  }, []);

  async function use(starter: OfferedStarter) {
    setBusy(starter.id);
    setFailure(null);
    try {
      const made = await kitApi.createAgentFromStarter(starter.id);
      const starterMade: StarterMade = { id: made.agent.id, starter: starter.name, toRedo: made.toRedo };
      navigate(`/console/agents/${made.agent.id}`, { replace: true, state: { starterMade } });
    } catch (err) {
      setFailure({ id: starter.id, message: message(err) });
      setBusy(null);
    }
  }

  if (starters.length === 0) return null;
  return (
    <section aria-labelledby="starters-offered-title">
      <h2 id="starters-offered-title">{t("starterStartFrom")}</h2>
      <p className="muted">{t("starterStartFromHelp")}</p>
      <ul className="senders" aria-label={t("starterStartFrom")}>
        {starters.map((s) => (
          <li key={s.id} data-starter={s.id}>
            <p><strong>{s.name}</strong></p>
            {s.description && <p className="note-body">{s.description}</p>}
            <div className="actions">
              <button type="button" className="secondary" disabled={busy !== null} onClick={() => void use(s)}>
                {busy === s.id ? t("starterUsing") : t("starterUse")}
              </button>
            </div>
            {failure?.id === s.id && <p role="alert" className="error">{failure.message}</p>}
          </li>
        ))}
      </ul>
    </section>
  );
}

export function AgentStarterNotice({ agentId }: { agentId: string }) {
  const location = useLocation();
  const navigate = useNavigate();
  const [shown, setShown] = useState<StarterMade | null>(null);

  useEffect(() => {
    const made = (location.state as { starterMade?: StarterMade } | null)?.starterMade;
    // Only the new agent's own page takes it.
    if (!made || made.id !== agentId) return;
    setShown(made);
    navigate(`${location.pathname}${location.search}${location.hash}`, { replace: true, state: null });
  }, [location, agentId, navigate]);

  if (!shown || shown.id !== agentId) return null;
  return (
    <div role="status">
      <p>{t("starterAgentMadeFrom", { name: shown.starter })}</p>
      {shown.toRedo.length > 0 && (
        <ul aria-label={t("agentCopyToDo")}>
          {shown.toRedo.map((item, i) => (
            <ToDo key={i} item={item} agentId={agentId} />
          ))}
        </ul>
      )}
    </div>
  );
}

/** Only a voice setup not offered here and call steps left as a draft can be left to do on an agent made from a starter. */
function ToDo({ item, agentId }: { item: AgentCopyToDo; agentId: string }) {
  if (item.kind === "voiceSetup") return <li>{t("starterVoiceSetup")}</li>;
  if (item.kind === "flow") {
    const [before, after] = t("agentCopyFlow").split("{page}");
    return (
      <li>
        {before}
        <Link to={`/console/agents/${agentId}/flow`}>{t("agentCopyFlowPage")}</Link>
        {after}
      </li>
    );
  }
  return null;
}
