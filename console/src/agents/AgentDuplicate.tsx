/**
 * Console → one agent → duplicating it (contract/kit-api.yaml duplicateAgent): the button that makes a copy of the agent
 * in the same account and opens the copy's page (AgentDuplicate), and the notice that page shows once (AgentCopyNotice):
 * whose copy it is and what is left to do on it, one line for each of the backend's `toRedo` (keys to type again, call
 * steps to publish, …). The notice travels in the router state of that one move and is taken out of it as soon as it is
 * shown, so a reload, or coming back to the page, does not show it again.
 *
 * Not here: the agent's page, which places both (AgentPage.tsx); the call steps page the notice links to
 * (flow/FlowPage.tsx); what the backend copies (App\Kit\Agents\AgentDuplicate).
 */
import { useEffect, useState } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { kitApi, type Agent, type AgentCopyToDo } from "../api";
import { t, type TextKey } from "../text";

/** What the copy's page is told, once, in the router state: which agent it is for, whose copy it is, what is left to do. */
interface CopyMade {
  id: string;
  source: string;
  toRedo: AgentCopyToDo[];
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** The to-do lines that are one sentence with nothing to fill in. */
const setAgain: Partial<Record<AgentCopyToDo["kind"], TextKey>> = {
  voiceSetup: "agentCopyVoiceSetup",
  webhooks: "agentCopyWebhooks",
  tools: "agentCopyTools",
  lookups: "agentCopyLookups",
  handoff: "agentCopyHandoff",
};

export function AgentDuplicate({ agent }: { agent: Agent }) {
  const navigate = useNavigate();
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  async function duplicate() {
    setBusy(true);
    setFailure(null);
    try {
      const made = await kitApi.duplicateAgent(agent.id);
      const copyMade: CopyMade = { id: made.agent.id, source: agent.name, toRedo: made.toRedo };
      navigate(`/console/agents/${made.agent.id}`, { state: { copyMade } });
    } catch (err) {
      setFailure(message(err));
      setBusy(false);
    }
  }

  return (
    <div>
      <p className="muted">{t("duplicateAgentHelp")}</p>
      <button type="button" className="secondary" disabled={busy} onClick={duplicate}>
        {busy ? t("duplicating") : t("duplicateAgent")}
      </button>
      {failure && <p role="alert" className="error">{failure}</p>}
    </div>
  );
}

export function AgentCopyNotice({ agentId }: { agentId: string }) {
  const location = useLocation();
  const navigate = useNavigate();
  const [shown, setShown] = useState<CopyMade | null>(null);

  useEffect(() => {
    const made = (location.state as { copyMade?: CopyMade } | null)?.copyMade;
    // Only the copy's own page takes it: the page being left sees the new state first, for the agent it still shows.
    if (!made || made.id !== agentId) return;
    setShown(made);
    navigate(`${location.pathname}${location.search}${location.hash}`, { replace: true, state: null });
  }, [location, agentId, navigate]);

  if (!shown || shown.id !== agentId) return null;
  return (
    <div role="status">
      <p>{t("agentCopyOf", { name: shown.source })}</p>
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

function ToDo({ item, agentId }: { item: AgentCopyToDo; agentId: string }) {
  if (item.kind === "key") return <li>{t("agentCopyKey", { name: item.name ?? "" })}</li>;
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
  const words = setAgain[item.kind];
  return words ? <li>{t(words)}</li> : null;
}
