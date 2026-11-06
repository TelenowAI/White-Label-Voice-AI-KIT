/**
 * The exits of one step: where each goes, when it is taken, and the order they are tried in. Telenow tries keypad exits first, then rules, then "Otherwise", and asks the AI only if none of those gave an answer, so the
 * form says so where it matters.
 *
 * Not here: drawing them (FlowCanvas.tsx), the rest of a step (FlowStepForm.tsx).
 */
import type { AgentFlowGraph, FlowExit, FlowStep } from "../../api";
import { t } from "../../text";
import { Box, refusals } from "../settingsRows";
import { exitWords, stepLabel } from "./flowModel";

export function FlowExitRows({ step, graph, exitKinds, errors, onChange, onAdd, onRemove, onMove }: {
  step: FlowStep;
  graph: AgentFlowGraph;
  exitKinds: string[];
  errors: Record<string, string[]>;
  onChange: (id: string, over: Partial<FlowExit>) => void;
  onAdd: (to: string) => void;
  onRemove: (id: string) => void;
  onMove: (id: string, by: -1 | 1) => void;
}) {
  const mine = graph.exits.filter((e) => e.from === step.id);
  const targets = graph.steps.filter((s) => s.kind !== "note");
  const places = (exit: FlowExit) => `flow.exits.${graph.exits.indexOf(exit)}`;
  const when = (exit: FlowExit, over: Partial<FlowExit["when"]>) => onChange(exit.id, { when: { ...exit.when, ...over } });
  const hasCatchAll = mine.some((e) => e.when.kind === "always" || e.when.kind === "fallback");
  const hasAi = mine.some((e) => e.when.kind === "ai");

  return (
    <section aria-label={t("flowExits")}>
      <h3>{t("flowExits")}</h3>
      <small className="muted">{t("flowExitsHelp")}</small>
      {hasCatchAll && hasAi && <p className="note">{t("flowExitCatchAllBeatsAi")}</p>}
      {mine.map((exit, i) => {
        const id = `exit-${exit.id}`;
        const at = places(exit);
        return (
          <fieldset className="field" key={exit.id}>
            <legend>{t("flowExitNumber", { n: String(i + 1) })}</legend>
            <div className="field">
              <label htmlFor={`${id}-to`}>{t("flowExitTo")}</label>
              <select id={`${id}-to`} value={exit.to} onChange={(e) => onChange(exit.id, { to: e.target.value })}>
                {targets.map((s) => <option key={s.id} value={s.id}>{stepLabel(s)}</option>)}
              </select>
              {refusals(errors, `${at}.to`).length > 0 && <small className="error">{refusals(errors, `${at}.to`).join(" ")}</small>}
            </div>
            <div className="field">
              <label htmlFor={`${id}-kind`}>{t("flowExitWhen")}</label>
              <select id={`${id}-kind`} value={exit.when.kind} onChange={(e) => when(exit, { kind: e.target.value })}>
                {exitKinds.map((kind) => <option key={kind} value={kind}>{t(exitWords[kind] ?? "flowExitAlways")}</option>)}
              </select>
            </div>
            {exit.when.kind === "ai" && <Box id={`${id}-describe`} label={t("flowExitDescribe")} help={t("flowExitDescribeHelp")} rows={2} value={exit.when.describe ?? ""} errors={refusals(errors, `${at}.when.describe`)} onChange={(v) => when(exit, { describe: v })} />}
            {exit.when.kind === "equation" && <Box id={`${id}-expr`} label={t("flowExitExpr")} help={t("flowExitExprHelp")} value={exit.when.expr ?? ""} errors={refusals(errors, `${at}.when.expr`)} onChange={(v) => when(exit, { expr: v })} />}
            {exit.when.kind === "dtmf" && <Box id={`${id}-digit`} label={t("flowExitDigit")} value={exit.when.digit ?? ""} errors={refusals(errors, `${at}.when.digit`)} onChange={(v) => when(exit, { digit: v })} />}
            {exit.when.kind === "tool_result" && (
              <div className="field">
                <label htmlFor={`${id}-match`}>{t("flowExitMatch")}</label>
                <select id={`${id}-match`} value={exit.when.match ?? "ok"} onChange={(e) => when(exit, { match: e.target.value })}>
                  <option value="ok">{t("flowExitMatchOk")}</option>
                  <option value="error">{t("flowExitMatchError")}</option>
                  <option value="timeout">{t("flowExitMatchTimeout")}</option>
                  <option value="declined">{t("flowExitMatchDeclined")}</option>
                </select>
              </div>
            )}
            <Box id={`${id}-label`} label={t("flowExitLabel")} value={exit.label ?? ""} errors={refusals(errors, `${at}.label`)} onChange={(v) => onChange(exit.id, { label: v })} />
            <div className="actions">
              {i > 0 && <button type="button" className="secondary" onClick={() => onMove(exit.id, -1)}>{t("flowExitUp")}</button>}
              {i < mine.length - 1 && <button type="button" className="secondary" onClick={() => onMove(exit.id, 1)}>{t("flowExitDown")}</button>}
              <button type="button" className="secondary" onClick={() => onRemove(exit.id)}>{t("flowExitRemove")}</button>
            </div>
          </fieldset>
        );
      })}
      {targets.length > 0 && <button type="button" className="secondary" onClick={() => onAdd(targets.find((s) => s.id !== step.id)?.id ?? targets[0].id)}>{t("flowExitAdd")}</button>}
    </section>
  );
}
