/**
 * Console → one agent → its call steps (`/console/agents/:id/flow`, contract/kit-api.yaml getAgentFlow and the rest): a map and a list of steps, the form of the one open, and saving a draft, checking and publishing.
 * A draft is kept by the kit and never runs. Publishing replaces what calls use, and a change applies from the next call.
 *
 * Not here: the map (FlowCanvas.tsx, loaded with this page), the forms (FlowStepForm.tsx), what the backend checks (App\Kit\Agents\AgentFlow).
 */
import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { KitApiError, kitApi, type AgentFlowGraph, type AgentFlowState, type FlowWarning } from "../../api";
import { t } from "../../text";
import { FlowCanvas } from "./FlowCanvas";
import { FlowStepForm } from "./FlowStepForm";
import { addExit, addStep, changeExit, changeStep, emptyGraph, firstRefused, kindWords, moveExit, newStep, removeExit, removeStep, stepLabel } from "./flowModel";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function FlowPage() {
  const { id = "" } = useParams();
  const [state, setState] = useState<AgentFlowState | null>(null);
  const [agentName, setAgentName] = useState("");
  const [graph, setGraph] = useState<AgentFlowGraph>(emptyGraph());
  const [selected, setSelected] = useState<string | null>(null);
  const [kind, setKind] = useState("conversation");
  const [changed, setChanged] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [warnings, setWarnings] = useState<FlowWarning[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [askingOff, setAskingOff] = useState(false);

  const take = (found: AgentFlowState) => {
    setState(found);
    setGraph(found.draft ?? found.published ?? emptyGraph());
    setChanged(false);
  };

  useEffect(() => {
    kitApi.agentFlow(id).then(take, (err: unknown) => setFailure(message(err)));
    kitApi.agent(id).then((agent) => setAgentName(agent.name), () => undefined);
  }, [id]);

  const edit = (change: (graph: AgentFlowGraph) => AgentFlowGraph) => {
    setGraph(change);
    setChanged(true);
    setDone(null);
  };

  const act = async (work: () => Promise<void>) => {
    setBusy(true);
    setFailure(null);
    setErrors({});
    setDone(null);
    try {
      await work();
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        setErrors(err.fields);
        setSelected(firstRefused(err.fields, graph) ?? selected);
        setFailure(t("flowRefused"));
      } else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  };

  const save = () => act(async () => {
    take(await kitApi.saveAgentFlowDraft(id, graph));
    setDone(t("flowDraftSaved"));
  });
  const check = () => act(async () => {
    const found = await kitApi.checkAgentFlow(id, graph);
    setWarnings(found.warnings);
    setDone(found.warnings.length === 0 ? t("flowCheckClean") : t("flowCheckWarnings"));
  });
  const publish = () => act(async () => {
    const found = await kitApi.publishAgentFlow(id, graph);
    take(found);
    setWarnings(found.warnings);
    setDone(found.syncState === "synced" ? t("flowPublished") : t("flowPublishing"));
  });
  const discard = () => act(async () => {
    take(await kitApi.discardAgentFlowDraft(id));
    setWarnings([]);
    setSelected(null);
    setDone(t("flowDraftDiscarded"));
  });
  const off = () => act(async () => {
    take(await kitApi.unpublishAgentFlow(id));
    setAskingOff(false);
    setWarnings([]);
    setDone(t("flowOff"));
  });

  if (state === null) {
    return (
      <section aria-labelledby="flow-title">
        <h1 id="flow-title">{t("flowTitle")}</h1>
        {failure ? <p role="alert" className="error">{failure}</p> : <p className="muted">{t("loading")}</p>}
      </section>
    );
  }

  const form = state;
  const index = graph.steps.findIndex((s) => s.id === selected);
  const open = index >= 0 ? graph.steps[index] : null;
  const nameOf = (at: string) => {
    const step = graph.steps.find((s) => s.id === at) ?? graph.steps.find((s) => s.id === graph.exits.find((e) => e.id === at)?.from);
    return step;
  };
  const elsewhere = Object.entries(errors).filter(([key]) => !/^flow\.(steps|exits)\.\d+/.test(key)).flatMap(([, list]) => list);

  return (
    <section aria-labelledby="flow-title">
      <p><Link to={`/console/agents/${id}`}>{t("flowBack")}</Link></p>
      <h1 id="flow-title">{t("flowTitle")}{agentName && `: ${agentName}`}</h1>
      <p className="muted">{t("flowHelp")}</p>
      <p>{form.published ? t("flowInUse") : t("flowNotInUse")}{form.draft && ` ${t("flowHasDraft")}`}{changed && ` ${t("flowNotSaved")}`}</p>
      {form.syncState === "failed" && form.syncError && <p className="error">{form.syncError}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      {elsewhere.map((line) => <p key={line} className="error">{line}</p>)}
      {done && <p role="status">{done}</p>}
      {warnings.length > 0 && (
        <section aria-label={t("flowWarnings")}>
          <h2>{t("flowWarnings")}</h2>
          <ul>
            {warnings.map((w, i) => {
              const step = nameOf(w.at);
              return (
                <li key={i}>
                  {w.message}{" "}
                  {step && <button type="button" className="secondary" onClick={() => setSelected(step.id)}>{t("flowOpenStep", { name: stepLabel(step) })}</button>}
                </li>
              );
            })}
          </ul>
        </section>
      )}
      <div className="actions flow-add">
        <div className="field">
          <label htmlFor="flow-add-kind">{t("flowAddKind")}</label>
          <select id="flow-add-kind" value={kind} onChange={(e) => setKind(e.target.value)}>
            {form.kinds.map((k) => <option key={k} value={k}>{t(kindWords[k] ?? "flowKindConversation")}</option>)}
          </select>
        </div>
        <button type="button" className="secondary" onClick={() => {
          const step = newStep(kind, graph);
          edit((g) => addStep(g, step));
          setSelected(step.id);
        }}>{t("flowAddStep")}</button>
      </div>
      <FlowCanvas graph={graph} selected={selected} label={t("flowMap")} onSelect={setSelected} onMove={(step, x, y) => edit((g) => changeStep(g, step, { x, y }))} onConnect={(from, to) => edit((g) => addExit(g, from, to))} />
      <nav aria-label={t("flowSteps")}>
        <h2>{t("flowSteps")}</h2>
        {graph.steps.length === 0 && <p className="muted">{t("flowNoSteps")}</p>}
        <ul className="flow-steps">
          {graph.steps.map((step, i) => {
            const refused = Object.keys(errors).some((key) => key.startsWith(`flow.steps.${i}.`) || key === `flow.steps.${i}`);
            return (
              <li key={step.id}>
                <button type="button" className="secondary" aria-current={selected === step.id ? "true" : undefined} onClick={() => setSelected(step.id)}>
                  {stepLabel(step)} · {t(kindWords[step.kind] ?? "flowKindConversation")}{graph.start === step.id && ` · ${t("flowIsStart")}`}{refused && ` · ${t("flowHasProblem")}`}
                </button>
              </li>
            );
          })}
        </ul>
        {errors["flow.start"] && <small className="error">{errors["flow.start"].join(" ")}</small>}
      </nav>
      {open && (
        <FlowStepForm
          key={open.id}
          step={open}
          index={index}
          graph={graph}
          form={form}
          errors={errors}
          onChange={(over) => edit((g) => changeStep(g, open.id, over))}
          onRemove={() => { edit((g) => removeStep(g, open.id)); setSelected(null); }}
          onMakeStart={() => edit((g) => ({ ...g, start: open.id }))}
          onExit={{ change: (exit, over) => edit((g) => changeExit(g, exit, over)), add: (to) => edit((g) => addExit(g, open.id, to)), remove: (exit) => edit((g) => removeExit(g, exit)), move: (exit, by) => edit((g) => moveExit(g, exit, by)) }}
        />
      )}
      <div className="actions flow-actions">
        <button type="button" className="secondary" disabled={busy} onClick={() => void save()}>{t("flowSaveDraft")}</button>
        <button type="button" className="secondary" disabled={busy} onClick={() => void check()}>{t("flowCheck")}</button>
        <button type="button" disabled={busy} onClick={() => void publish()}>{t("flowPublish")}</button>
        {form.draft && <button type="button" className="secondary" disabled={busy} onClick={() => void discard()}>{t("flowDiscardDraft")}</button>}
        {form.published && !askingOff && <button type="button" className="secondary" disabled={busy} onClick={() => setAskingOff(true)}>{t("flowTurnOff")}</button>}
      </div>
      {askingOff && (
        <div className="actions">
          <span>{t("flowTurnOffAsk")}</span>
          <button type="button" disabled={busy} onClick={() => void off()}>{t("flowTurnOff")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAskingOff(false)}>{t("cancel")}</button>
        </div>
      )}
    </section>
  );
}
