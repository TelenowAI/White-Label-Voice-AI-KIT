/**
 * One call step: what its kind has, and its exits. Kept in the words Telenow's check uses ("Line spoken on arrival"), since its sentences are shown as they come. A key in a Function step is never shown: it stays until
 * replaced or removed.
 *
 * Not here: what the backend checks (App\Kit\Agents\AgentFlow), the exits (FlowExitRows.tsx), the page (FlowPage.tsx).
 */
import type { AgentFlowForm, AgentFlowGraph, FlowCapture, FlowExit, FlowStep, FlowTool } from "../../api";
import { t } from "../../text";
import { Box, HeaderRows, refusals } from "../settingsRows";
import { FlowExitRows } from "./FlowExitRows";
import { canLeave, captureFormatWords, captureTypeWords, kindWords, stepLabel } from "./flowModel";

const methods = ["GET", "POST", "PUT", "PATCH", "DELETE"];
const list = (text: string) => text.split(",").map((s) => s.trim()).filter(Boolean);

function Pairs({ id, at, rows, left, right, leftLabel, rightLabel, addLabel, errors, onChange }: {
  id: string;
  at: string;
  rows: Record<string, string>[];
  left: string;
  right: string;
  leftLabel: string;
  rightLabel: string;
  addLabel: string;
  errors: Record<string, string[]>;
  onChange: (rows: Record<string, string>[]) => void;
}) {
  const put = (i: number, key: string, v: string) => onChange(rows.map((row, j) => (j === i ? { ...row, [key]: v } : row)));
  return (
    <>
      {rows.map((row, i) => (
        <div className="field" key={i}>
          <Box id={`${id}-${i}-left`} label={leftLabel} value={row[left] ?? ""} errors={refusals(errors, `${at}.${i}.${left}`)} onChange={(v) => put(i, left, v)} />
          <Box id={`${id}-${i}-right`} label={rightLabel} value={row[right] ?? ""} errors={refusals(errors, `${at}.${i}.${right}`)} onChange={(v) => put(i, right, v)} />
          <button type="button" className="secondary" onClick={() => onChange(rows.filter((_, j) => j !== i))}>{t("settingsRemove")}</button>
        </div>
      ))}
      <button type="button" className="secondary" onClick={() => onChange([...rows, { [left]: "", [right]: "" }])}>{addLabel}</button>
    </>
  );
}

function CaptureRows({ id, at, rows, form, errors, onChange }: { id: string; at: string; rows: FlowCapture[]; form: AgentFlowForm; errors: Record<string, string[]>; onChange: (rows: FlowCapture[]) => void }) {
  const put = (i: number, over: Partial<FlowCapture>) => onChange(rows.map((row, j) => (j === i ? { ...row, ...over } : row)));
  return (
    <section aria-label={t("flowCapture")}>
      <h3>{t("flowCapture")}</h3>
      <small className="muted">{t("flowCaptureHelp")}</small>
      {rows.map((row, i) => (
        <fieldset className="field" key={i}>
          <legend>{row.name || t("flowCaptureNumber", { n: String(i + 1) })}</legend>
          <Box id={`${id}-${i}-name`} label={t("flowCaptureName")} help={t("flowCaptureNameHelp")} value={row.name} errors={refusals(errors, `${at}.${i}.name`)} onChange={(v) => put(i, { name: v })} />
          <div className="field">
            <label htmlFor={`${id}-${i}-type`}>{t("flowCaptureType")}</label>
            <select id={`${id}-${i}-type`} value={row.type} onChange={(e) => put(i, { type: e.target.value })}>
              {form.captureTypes.map((type) => <option key={type} value={type}>{t(captureTypeWords[type] ?? "flowCaptureType_string")}</option>)}
            </select>
          </div>
          {row.type === "enum" && <Box id={`${id}-${i}-values`} label={t("flowCaptureValues")} help={t("flowCaptureValuesHelp")} value={row.values.join(", ")} errors={refusals(errors, `${at}.${i}.values`)} onChange={(v) => put(i, { values: list(v) })} />}
          {row.type === "string" && (
            <div className="field">
              <label htmlFor={`${id}-${i}-format`}>{t("flowCaptureFormat")}</label>
              <select id={`${id}-${i}-format`} value={row.format ?? ""} onChange={(e) => put(i, { format: e.target.value || null })}>
                <option value="">{t("flowCaptureFormatAny")}</option>
                {form.captureFormats.map((format) => <option key={format} value={format}>{t(captureFormatWords[format] ?? "flowCaptureFormatAny")}</option>)}
              </select>
            </div>
          )}
          <Box id={`${id}-${i}-description`} label={t("flowCaptureDescription")} value={row.description ?? ""} errors={refusals(errors, `${at}.${i}.description`)} onChange={(v) => put(i, { description: v })} />
          <label className="checkbox">
            <input type="checkbox" checked={row.required} onChange={(e) => put(i, { required: e.target.checked })} />
            {t("flowCaptureRequired")}
          </label>
          <button type="button" className="secondary" onClick={() => onChange(rows.filter((_, j) => j !== i))}>{t("settingsRemove")}</button>
        </fieldset>
      ))}
      <button type="button" className="secondary" onClick={() => onChange([...rows, { name: "", type: "string", description: null, values: [], required: false, format: null }])}>{t("flowCaptureAdd")}</button>
    </section>
  );
}

function ToolFields({ id, at, step, errors, onChange }: { id: string; at: string; step: FlowStep; errors: Record<string, string[]>; onChange: (over: Partial<FlowStep>) => void }) {
  const tool = step.tool as FlowTool;
  const put = (over: Partial<FlowTool>) => onChange({ tool: { ...tool, ...over } });
  const custom = tool.request !== null;
  const typed = tool.token === undefined || tool.token === null ? "" : tool.token;
  return (
    <>
      <Box id={`${id}-url`} label={t("settingsToolUrl")} help={t("settingsToolUrlHelp")} value={tool.url ?? ""} errors={refusals(errors, `${at}.tool.url`)} onChange={(v) => put({ url: v })} />
      <Box id={`${id}-token`} label={t("settingsToolKey")} secret help={tool.tokenHint ? t("settingsToolKeySaved", { hint: tool.tokenHint }) : t("settingsToolKeyHelp")} value={typed} errors={refusals(errors, `${at}.tool.token`)} onChange={(v) => put({ token: v })} />
      {tool.tokenHint && (
        <label className="checkbox">
          <input type="checkbox" checked={tool.token === null} onChange={(e) => { const next = { ...tool }; if (e.target.checked) next.token = null; else delete next.token; onChange({ tool: next }); }} />
          {t("settingsToolKeyRemove")}
        </label>
      )}
      <Box id={`${id}-sends`} label={t("flowToolSends")} help={t("flowToolSendsHelp")} value={step.sends.join(", ")} errors={refusals(errors, `${at}.sends`)} onChange={(v) => {
        const sends = list(v);
        onChange({ sends, tool: { ...tool, parameters: sends.map((name) => ({ name, type: "string" as const, description: null, required: false })) } });
      }} />
      <label className="checkbox">
        <input type="checkbox" checked={custom} onChange={(e) => put({ request: e.target.checked ? { method: "POST", headers: [], body: null } : null })} />
        {t("settingsToolCustom")}
      </label>
      {tool.request && (
        <>
          <div className="field">
            <label htmlFor={`${id}-method`}>{t("settingsMethod")}</label>
            <select id={`${id}-method`} value={tool.request.method} onChange={(e) => put({ request: { ...(tool.request as NonNullable<FlowTool["request"]>), method: e.target.value } })}>
              {methods.map((m) => <option key={m} value={m}>{m}</option>)}
            </select>
          </div>
          <h4>{t("settingsHeaders")}</h4>
          <HeaderRows idPrefix={id} at={`${at}.tool.request.headers`} headers={tool.request.headers} errors={errors} onChange={(headers) => put({ request: { ...(tool.request as NonNullable<FlowTool["request"]>), headers } })} />
          {tool.request.method !== "GET" && <Box id={`${id}-body`} label={t("settingsBody")} help={t("settingsToolBodyHelp")} rows={4} value={tool.request.body ?? ""} errors={refusals(errors, `${at}.tool.request.body`)} onChange={(v) => put({ request: { ...(tool.request as NonNullable<FlowTool["request"]>), body: v } })} />}
        </>
      )}
      <h4>{t("flowToolResults")}</h4>
      <small className="muted">{t("flowToolResultsHelp")}</small>
      <Pairs id={`${id}-results`} at={`${at}.results`} rows={step.results} left="path" right="variable" leftLabel={t("flowToolResultPath")} rightLabel={t("flowKeepAs")} addLabel={t("flowToolResultAdd")} errors={errors} onChange={(results) => onChange({ results: results as FlowStep["results"] })} />
      <Box id={`${id}-say`} label={t("settingsToolSay")} help={t("settingsToolSayHelp")} value={tool.sayWhileRunning ?? ""} errors={refusals(errors, `${at}.tool.sayWhileRunning`)} onChange={(v) => put({ sayWhileRunning: v })} />
      <Box id={`${id}-timeout`} label={t("settingsToolTimeout")} help={t("settingsToolTimeoutHelp")} numeric value={tool.timeoutSecs === null ? "" : String(tool.timeoutSecs)} errors={refusals(errors, `${at}.tool.timeoutSecs`)} onChange={(v) => put({ timeoutSecs: v.trim() === "" ? null : Number(v) })} />
      <label className="checkbox">
        <input type="checkbox" checked={tool.askFirst} onChange={(e) => put({ askFirst: e.target.checked })} />
        {t("settingsToolAskFirst")}
      </label>
    </>
  );
}

function TransferFields({ id, at, step, errors, onChange }: { id: string; at: string; step: FlowStep; errors: Record<string, string[]>; onChange: (over: Partial<FlowStep>) => void }) {
  const transfer = step.transfer as NonNullable<FlowStep["transfer"]>;
  const put = (over: Partial<typeof transfer>) => onChange({ transfer: { ...transfer, ...over } });
  const first = transfer.destinations[0] ?? { label: null, numbers: [], mode: "serial" };
  return (
    <>
      <Box id={`${id}-numbers`} label={t("flowTransferNumbers")} help={t("flowTransferNumbersHelp")} value={first.numbers.join(", ")} errors={[...refusals(errors, `${at}.transfer.destinations`), ...refusals(errors, `${at}.transfer.enabled`)]} onChange={(v) => put({ destinations: [{ ...first, numbers: v.split(",").map((s) => s.trim()) }] })} />
      <Box id={`${id}-message`} label={t("flowTransferMessage")} value={transfer.message ?? ""} errors={refusals(errors, `${at}.transfer.message`)} onChange={(v) => put({ message: v })} />
      <Box id={`${id}-no-answer`} label={t("flowTransferNoAnswer")} help={t("flowTransferNoAnswerHelp")} value={transfer.noAnswerMessage ?? ""} errors={refusals(errors, `${at}.transfer.noAnswerMessage`)} onChange={(v) => put({ noAnswerMessage: v })} />
    </>
  );
}

function HandoffFields({ id, at, step, form, errors, onChange }: { id: string; at: string; step: FlowStep; form: AgentFlowForm; errors: Record<string, string[]>; onChange: (over: Partial<FlowStep>) => void }) {
  const handoff = step.handoff as NonNullable<FlowStep["handoff"]>;
  const chosen = handoff.agents[0]?.agentId ?? "";
  return (
    <>
      <div className="field">
        <label htmlFor={`${id}-agent`}>{t("flowHandoffAgent")}</label>
        <select id={`${id}-agent`} value={chosen} onChange={(e) => onChange({ handoff: { ...handoff, agents: e.target.value ? [{ agentId: e.target.value, label: form.otherAgents.find((a) => a.id === e.target.value)?.name ?? "", mode: "transfer" }] : [] } })}>
          <option value="">{t("flowHandoffChoose")}</option>
          {form.otherAgents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
        </select>
        {refusals(errors, `${at}.handoff`).length > 0 && <small className="error">{refusals(errors, `${at}.handoff`).join(" ")}</small>}
      </div>
      <Box id={`${id}-message`} label={t("flowHandoffMessage")} value={handoff.message ?? ""} errors={refusals(errors, `${at}.handoff.message`)} onChange={(v) => onChange({ handoff: { ...handoff, message: v } })} />
    </>
  );
}

function CodeFields({ id, at, step, errors, onChange }: { id: string; at: string; step: FlowStep; errors: Record<string, string[]>; onChange: (over: Partial<FlowStep>) => void }) {
  const code = step.code as NonNullable<FlowStep["code"]>;
  const put = (over: Partial<typeof code>) => onChange({ code: { ...code, ...over } });
  return (
    <>
      <Box id={`${id}-source`} label={t("flowCodeSource")} help={t("flowCodeSourceHelp")} rows={8} value={code.source ?? ""} errors={refusals(errors, `${at}.code.source`)} onChange={(v) => put({ source: v })} />
      <h4>{t("flowCodeKeep")}</h4>
      <small className="muted">{t("flowCodeKeepHelp")}</small>
      <Pairs id={`${id}-keep`} at={`${at}.code.keep`} rows={code.keep} left="field" right="variable" leftLabel={t("flowCodeField")} rightLabel={t("flowKeepAs")} addLabel={t("flowCodeKeepAdd")} errors={errors} onChange={(keep) => put({ keep: keep as typeof code.keep })} />
    </>
  );
}

export function FlowStepForm({ step, index, graph, form, errors, onChange, onRemove, onMakeStart, onExit }: {
  step: FlowStep;
  index: number;
  graph: AgentFlowGraph;
  form: AgentFlowForm;
  errors: Record<string, string[]>;
  onChange: (over: Partial<FlowStep>) => void;
  onRemove: () => void;
  onMakeStart: () => void;
  onExit: { change: (id: string, over: Partial<FlowExit>) => void; add: (to: string) => void; remove: (id: string) => void; move: (id: string, by: -1 | 1) => void };
}) {
  const at = `flow.steps.${index}`;
  const id = `step-${step.id}`;
  const hasInstructions = ["conversation", "extract", "dtmf", "router", "note"].includes(step.kind);
  const hasLine = ["conversation", "extract", "dtmf", "static", "router", "end"].includes(step.kind);
  const hasContinue = ["conversation", "extract", "dtmf"].includes(step.kind);
  const lineHelp = step.kind === "end" ? "flowLineHelpEnd" : step.kind === "dtmf" ? "flowLineHelpMenu" : step.kind === "static" ? "flowLineHelpSay" : "flowLineHelp";
  return (
    <section className="flow-step" aria-label={stepLabel(step)}>
      <h2>{stepLabel(step)}</h2>
      <p className="muted">{t(kindWords[step.kind] ?? "flowKindConversation")}{graph.start === step.id && ` · ${t("flowIsStart")}`}</p>
      <div className="actions">
        {graph.start !== step.id && step.kind !== "note" && <button type="button" className="secondary" onClick={onMakeStart}>{t("flowMakeStart")}</button>}
        <button type="button" className="secondary" onClick={onRemove}>{t("flowStepRemove")}</button>
      </div>
      <Box id={`${id}-name`} label={t("flowStepName")} value={step.name ?? ""} errors={refusals(errors, `${at}.name`)} onChange={(v) => onChange({ name: v })} />
      {hasInstructions && <Box id={`${id}-instructions`} label={t(step.kind === "note" ? "flowNoteText" : "flowInstructions")} help={step.kind === "note" ? undefined : t("flowInstructionsHelp")} rows={5} value={step.instructions ?? ""} errors={refusals(errors, `${at}.instructions`)} onChange={(v) => onChange({ instructions: v })} />}
      {hasInstructions && step.kind !== "note" && (
        <label className="checkbox">
          <input type="checkbox" checked={step.replaceInstructions} onChange={(e) => onChange({ replaceInstructions: e.target.checked })} />
          {t("flowReplaceInstructions")}
        </label>
      )}
      {hasLine && <Box id={`${id}-line`} label={t("flowLine")} help={t(lineHelp)} rows={2} value={step.line ?? ""} errors={refusals(errors, `${at}.line`)} onChange={(v) => onChange({ line: v })} />}
      {hasContinue && (
        <label className="checkbox">
          <input type="checkbox" checked={step.continueAfterLine} onChange={(e) => onChange({ continueAfterLine: e.target.checked })} />
          {t("flowContinueAfterLine")}
        </label>
      )}
      {step.kind === "dtmf" && step.keypad && (
        <>
          <label className="checkbox">
            <input type="checkbox" checked={step.keypad.routeOnly} onChange={(e) => onChange({ keypad: { ...(step.keypad as NonNullable<FlowStep["keypad"]>), routeOnly: e.target.checked } })} />
            {t("flowKeypadRouteOnly")}
          </label>
          <label className="checkbox">
            <input type="checkbox" checked={step.keypad.only} onChange={(e) => onChange({ keypad: { ...(step.keypad as NonNullable<FlowStep["keypad"]>), only: e.target.checked } })} />
            {t("flowKeypadOnly")}
          </label>
        </>
      )}
      {step.kind === "tool" && <ToolFields id={id} at={at} step={step} errors={errors} onChange={onChange} />}
      {step.kind === "code" && <CodeFields id={id} at={at} step={step} errors={errors} onChange={onChange} />}
      {step.kind === "transfer" && <TransferFields id={id} at={at} step={step} errors={errors} onChange={onChange} />}
      {step.kind === "agent" && <HandoffFields id={id} at={at} step={step} form={form} errors={errors} onChange={onChange} />}
      {["conversation", "extract", "dtmf"].includes(step.kind) && <CaptureRows id={`${id}-capture`} at={`${at}.capture`} rows={step.capture} form={form} errors={errors} onChange={(capture) => onChange({ capture })} />}
      {step.kind !== "note" && (
        <>
          <label className="checkbox">
            <input type="checkbox" checked={step.anywhere !== null} onChange={(e) => onChange({ anywhere: e.target.checked ? { when: "", examples: [], goBack: false } : null })} />
            {t("flowAnywhere")}
          </label>
          {step.anywhere && (
            <>
              <Box id={`${id}-anywhere`} label={t("flowAnywhereWhen")} help={t("flowAnywhereWhenHelp")} rows={2} value={step.anywhere.when} errors={refusals(errors, `${at}.anywhere.when`)} onChange={(v) => onChange({ anywhere: { ...(step.anywhere as NonNullable<FlowStep["anywhere"]>), when: v } })} />
              <label className="checkbox">
                <input type="checkbox" checked={step.anywhere.goBack} onChange={(e) => onChange({ anywhere: { ...(step.anywhere as NonNullable<FlowStep["anywhere"]>), goBack: e.target.checked } })} />
                {t("flowAnywhereGoBack")}
              </label>
            </>
          )}
        </>
      )}
      {canLeave(step) && <FlowExitRows step={step} graph={graph} exitKinds={form.exitKinds} errors={errors} onChange={onExit.change} onAdd={onExit.add} onRemove={onExit.remove} onMove={onExit.move} />}
    </section>
  );
}
