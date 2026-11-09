/**
 * Console → one agent (`/console/agents/:id`, and `/console/agents/new` to build one, blank or from a ready-made agent the operator offers; contract/kit-api.yaml
 * createAgent, getAgent, updateAgent, deleteAgent, retryAgent): its name, description and instructions, whether it
 * takes calls, how it behaves on a call, where its calls are sent, its chat box on the customer's own website, its voice setup, the knowledge it answers from, whether the voice platform has its latest change (with a retry when it was refused), a
 * link to its calls, its test call, duplicating it (with the copy's one-time notice of what is left to do), and deleting it (asked twice). A new agent starts from the operator's defaults
 * (or from a starter, whose page then says once which one and what is left to do).
 * The instructions (and a new agent's opening line) are checked as they are typed (PromptCheck.tsx, checkPrompt / checkNewPrompt),
 * with a preview on asking (PromptPreview.tsx); a save the backend refuses for broken sections says why at the instructions.
 * Also here: its logo (AgentLogo.tsx) and the providers that failed on its recent calls (calls/CallHealthPanel.tsx).
 *
 * Not here: the list (Agents.tsx), the test call itself (TestCall.tsx), the agent calling one person (CallSomeone.tsx), choosing the voice setup (StackForm.tsx) and checking
 * it (StackCheck.tsx), its
 * knowledge (AgentKnowledge.tsx), its behaviour on a call (AgentSettingsForm.tsx), where its calls are sent (AgentWebhooks.tsx), its chat box for the customer's website (AgentWebChat.tsx), its versions (AgentVersions.tsx), its call steps (flow/FlowPage.tsx), duplicating it (AgentDuplicate.tsx), the starters offered and their notice (AgentFromStarter.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { KitApiError, kitApi, type Agent, type AgentInput, type AgentSettingsForm as AgentSettingsFormSpec, type PromptCheckInput } from "../api";
import { CallHealthPanel } from "../calls/CallHealthPanel";
import { t, type TextKey } from "../text";
import { AgentLogo } from "./AgentLogo";
import { AgentCopyNotice, AgentDuplicate } from "./AgentDuplicate";
import { AgentFromStarter, AgentStarterNotice } from "./AgentFromStarter";
import { AgentKnowledge } from "./AgentKnowledge";
import { AgentSettingsForm } from "./AgentSettingsForm";
import { AgentVersions } from "./AgentVersions";
import { AgentWebChat } from "./AgentWebChat";
import { AgentWebhooks } from "./AgentWebhooks";
import { StackCheck } from "./StackCheck";
import { StackForm } from "./StackForm";
import { SyncState } from "./SyncState";
import { CallSomeone } from "./CallSomeone";
import { PromptFindings, usePromptCheck } from "./PromptCheck";
import { PromptPreview } from "./PromptPreview";
import { TestCall } from "./TestCall";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function AgentPage() {
  const { id = "" } = useParams();
  const isNew = id === "new";
  const navigate = useNavigate();
  const [agent, setAgent] = useState<Agent | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [settingsSavedAt, setSettingsSavedAt] = useState<string | null>(null);
  const [settingsForm, setSettingsForm] = useState<AgentSettingsFormSpec | null>(null);

  useEffect(() => {
    setAgent(null);
    setFailure(null);
    if (isNew) return;
    kitApi.agent(id).then(setAgent, (err: unknown) => setFailure(message(err)));
    kitApi.agentSettingsForm().then(setSettingsForm, (err: unknown) => setFailure(message(err)));
  }, [id, isNew]);

  return (
    <section aria-labelledby="agent-title">
      <p>
        <Link to="/console/agents">{t("backToAgents")}</Link>
      </p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {isNew && (
        <>
          <h1 id="agent-title">{t("newAgent")}</h1>
          <AgentFromStarter />
          <AgentForm
            submitText="createAgent"
            onSave={async (input) => {
              const made = await kitApi.createAgent({ ...input, name: input.name ?? "" });
              navigate(`/console/agents/${made.id}`, { replace: true });
            }}
          />
        </>
      )}
      {agent && (
        <>
          <h1 id="agent-title">{agent.name}</h1>
          <AgentCopyNotice key={`copy-${agent.id}`} agentId={agent.id} />
          <AgentStarterNotice key={`starter-${agent.id}`} agentId={agent.id} />
          <SyncState agent={agent} />
          <p>
            <Link to={`/console/calls?agent=${agent.id}`}>{t("agentCalls")}</Link>
          </p>
          {agent.syncState === "failed" && (
            <button
              type="button"
              className="secondary"
              onClick={() => kitApi.retryAgent(agent.id).then(setAgent, (err: unknown) => setFailure(message(err)))}
            >
              {t("retry")}
            </button>
          )}
          {/* Only the logo is taken from the answer: the forms below keep what is being typed in them. */}
          <AgentLogo key={`logo-${agent.id}`} agent={agent} onChanged={(saved) => setAgent((a) => (a ? { ...a, logoUrl: saved.logoUrl } : saved))} />
          <AgentForm key={agent.updatedAt} agent={agent} submitText="saveAgent" onSave={async (input) => setAgent(await kitApi.updateAgent(agent.id, input))} />
          {settingsForm && <AgentSettingsForm key={`settings-${agent.updatedAt}`} agent={agent} form={settingsForm} savedAt={settingsSavedAt} onSaved={(saved) => {
            setSettingsSavedAt(saved.updatedAt);
            setAgent(saved);
          }} />}
          <AgentWebhooks key={`webhooks-${agent.id}`} agent={agent} />
          <AgentWebChat key={`webchat-${agent.id}`} agent={agent} />
          <StackForm key={`stack-${agent.updatedAt}`} agent={agent} onSaved={setAgent} />
          <StackCheck key={`check-${agent.id}`} agent={agent} />
          <CallHealthPanel key={`health-${agent.id}`} agentId={agent.id} />
          <AgentKnowledge key={`knowledge-${agent.id}`} agent={agent} />
          <section aria-labelledby="agent-flow-link">
            <h2 id="agent-flow-link">{t("flowLink")}</h2>
            <p className="muted">{agent.settings.flow ? t("flowLinkOn") : t("flowLinkOff")}</p>
            <p><Link to={`/console/agents/${agent.id}/flow`}>{t("flowLink")}</Link></p>
          </section>
          <AgentVersions key={`versions-${agent.id}`} agent={agent} onRestored={setAgent} />
          <CallSomeone key={`call-${agent.id}`} agentId={agent.id} />
          <TestCall agentId={agent.id} />
          <AgentDuplicate key={`duplicate-${agent.id}`} agent={agent} />
          <DeleteAgent agent={agent} onDeleted={() => navigate("/console/agents")} onError={setFailure} />
        </>
      )}
    </section>
  );
}

function AgentForm({ agent, submitText, onSave }: { agent?: Agent; submitText: TextKey; onSave: (input: AgentInput) => Promise<void> }) {
  const [name, setName] = useState(agent?.name ?? "");
  const [description, setDescription] = useState(agent?.description ?? "");
  const [systemPrompt, setSystemPrompt] = useState(agent?.systemPrompt ?? "");
  const [isActive, setIsActive] = useState(agent?.isActive ?? true);
  const [opening, setOpening] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [typed, setTyped] = useState(false);

  // An agent's own check knows its values, tools and call steps; a new one's knows none yet. A new agent's opening line is
  // checked with its instructions, as it would be saved; an agent's opening lines are checked where they are (AgentSettingsForm.tsx).
  const agentId = agent?.id;
  const run = useCallback(
    (input: PromptCheckInput) => (agentId ? kitApi.checkPrompt(agentId, input) : kitApi.checkNewPrompt(input)),
    [agentId],
  );
  const greeting = agent ? "" : opening.trim();
  const prompt = usePromptCheck(run, typed && (systemPrompt.trim() !== "" || greeting !== "")
    ? { systemPrompt: systemPrompt || null, ...(greeting ? { greeting } : {}) }
    : null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      await onSave({ name, description: description || null, systemPrompt: systemPrompt || null, ...(agent ? { isActive } : { settings: { greetingInbound: opening.trim() || null } }) });
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const field = (key: string) => ({
    id: `agent-${key}`,
    "aria-invalid": errors[key] ? true : undefined,
    "aria-describedby": errors[key] ? `agent-${key}-error` : undefined,
  });
  const error = (key: string) => errors[key] && <small id={`agent-${key}-error`} className="error">{errors[key].join(" ")}</small>;

  return (
    <form className="agent-form" onSubmit={submit} noValidate>
      <div className="field">
        <label htmlFor="agent-name">{t("agentName")}</label>
        <input {...field("name")} value={name} onChange={(e) => setName(e.target.value)} />
        {error("name")}
      </div>
      <div className="field">
        <label htmlFor="agent-description">{t("agentDescription")}</label>
        <input {...field("description")} value={description} onChange={(e) => setDescription(e.target.value)} />
        {error("description")}
      </div>
      <div className="field">
        <label htmlFor="agent-systemPrompt">{t("agentInstructions")}</label>
        <small className="muted">{t("agentInstructionsHelp")}</small>
        <small className="muted">{t("agentInstructionsSections")}</small>
        <textarea {...field("systemPrompt")} rows={10} value={systemPrompt} onChange={(e) => {
          setSystemPrompt(e.target.value);
          setTyped(true);
        }} />
        {error("systemPrompt")}
        <PromptFindings state={prompt} field="systemPrompt" id="agent-systemPrompt-check" />
        <PromptPreview agentId={agentId} run={run} systemPrompt={systemPrompt} />
      </div>
      {!agent && (
        <div className="field">
          <label htmlFor="agent-settings.greetingInbound">{t("agentOpeningLine")}</label>
          <small className="muted">{t("agentOpeningLineHelp")}</small>
          <input {...field("settings.greetingInbound")} value={opening} onChange={(e) => {
            setOpening(e.target.value);
            setTyped(true);
          }} />
          {error("settings.greetingInbound")}
          {greeting && <PromptFindings state={prompt} field="greeting" id="agent-greeting-check" />}
        </div>
      )}
      {agent && (
        <label className="checkbox">
          <input type="checkbox" checked={isActive} onChange={(e) => setIsActive(e.target.checked)} />
          {t("agentTakesCalls")}
        </label>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>
        {busy ? t("saving") : t(submitText)}
      </button>
    </form>
  );
}

function DeleteAgent({ agent, onDeleted, onError }: { agent: Agent; onDeleted: () => void; onError: (m: string) => void }) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);

  async function remove() {
    setBusy(true);
    try {
      await kitApi.deleteAgent(agent.id);
      onDeleted();
    } catch (err) {
      onError(message(err));
      setBusy(false);
    }
  }

  return (
    <div className="danger-zone">
      {asking ? (
        <div className="actions">
          <span>{t("confirmDelete", { name: agent.name })}</span>
          <button type="button" disabled={busy} onClick={remove}>
            {busy ? t("deleting") : t("deleteAgent")}
          </button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>
            {t("cancel")}
          </button>
        </div>
      ) : (
        <button type="button" className="secondary" onClick={() => setAsking(true)}>
          {t("deleteAgent")}
        </button>
      )}
    </div>
  );
}
