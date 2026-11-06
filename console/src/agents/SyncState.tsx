/**
 * Whether the voice platform has an agent's latest change: saved, saving (with why it is still trying) or not saved
 * (with why). Used by the agent list (Agents.tsx) and an agent's page (AgentPage.tsx).
 */
import type { Agent } from "../api";
import { t } from "../text";

export function SyncState({ agent }: { agent: Agent }) {
  return (
    <div className={`sync sync-${agent.syncState}`}>
      <span>{t(agent.syncState === "synced" ? "agentSaved" : agent.syncState === "pending" ? "syncPending" : "syncFailed")}</span>
      {agent.syncError && <small>{agent.syncError}</small>}
    </div>
  );
}
