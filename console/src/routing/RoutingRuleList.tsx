/**
 * Call routing's rules, in the order they are tried (contract/kit-api.yaml orderRoutingRules, updateRoutingRule, deleteRoutingRule; making and
 * changing one in RoutingRuleForm.tsx): each with its name, on or off, when it takes a call and what it then does (ruleSummary.ts), and a warning
 * when its agent was deleted. The account's managers move one up or down (the whole order sent), turn one on or off, change one, delete one after
 * being asked, and add one (not past `limits.rulesMax`, saying why); one form is open at a time. Everyone else only reads them. A refusal shows
 * as an alert, in the backend's words.
 *
 * Not here: reading the rules (CallRoutingPage.tsx, which keeps them). Used by: CallRoutingPage.tsx.
 */
import { useState } from "react";
import { kitApi, type Agent, type RoutingLimits, type RoutingNumber, type RoutingRule } from "../api";
import { t } from "../text";
import { RoutingRuleForm } from "./RoutingRuleForm";
import { thenWords, whenWords } from "./ruleSummary";

interface Props {
  rules: RoutingRule[];
  numbers: RoutingNumber[];
  agents: Agent[];
  limits: RoutingLimits;
  canManage: boolean;
  /** The rules as they now are, in order. */
  onRules: (rules: RoutingRule[]) => void;
}

/** The form open for a new rule (a rule's own form is open by its id). */
const NEW = "new";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function RoutingRuleList({ rules, numbers, agents, limits, canManage, onRules }: Props) {
  const [open, setOpen] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const full = rules.length >= limits.rulesMax;

  async function act(work: () => Promise<RoutingRule[]>, done?: string) {
    setBusy(true);
    setFailure(null);
    setNotice(null);
    try {
      onRules(await work());
      if (done) setNotice(done);
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  /** The whole order with the rule at `i` and the one at `j` swapped. */
  const swap = (i: number, j: number) =>
    act(async () => {
      const ids = rules.map((r) => r.id);
      [ids[i], ids[j]] = [ids[j], ids[i]];
      return (await kitApi.orderRoutingRules(ids)).rules;
    });

  const turn = (rule: RoutingRule) =>
    act(async () => {
      const saved = await kitApi.updateRoutingRule(rule.id, { enabled: !rule.enabled });
      return rules.map((r) => (r.id === saved.id ? saved : r));
    });

  const remove = (rule: RoutingRule) =>
    act(async () => {
      await kitApi.deleteRoutingRule(rule.id);
      return rules.filter((r) => r.id !== rule.id);
    }, t("routingDeleted", { name: rule.name }));

  return (
    <section aria-labelledby="routing-rules-title">
      <h2 id="routing-rules-title">{t("routingRulesTitle")}</h2>
      <p className="muted">{t("routingRulesHelp")}</p>
      {notice && <p role="status">{notice}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      {rules.length === 0 && <p className="muted">{t("routingNoRules")}</p>}
      {rules.length > 0 && (
        <ol className="checks" aria-label={t("routingRulesTitle")}>
          {rules.map((r, i) => (
            <li key={r.id} className="check">
              {open === r.id ? (
                <RoutingRuleForm rule={r} numbers={numbers} agents={agents} limits={limits}
                  onSaved={(saved) => {
                    setOpen(null);
                    onRules(rules.map((x) => (x.id === saved.id ? saved : x)));
                  }}
                  onCancel={() => setOpen(null)} />
              ) : (
                <Rule rule={r} numbers={numbers} canManage={canManage} busy={busy} first={i === 0} last={i === rules.length - 1}
                  onUp={() => void swap(i, i - 1)} onDown={() => void swap(i, i + 1)} onTurn={() => void turn(r)} onDelete={() => void remove(r)}
                  onEdit={() => {
                    setNotice(null);
                    setOpen(r.id);
                  }} />
              )}
            </li>
          ))}
        </ol>
      )}
      {canManage && (open === NEW ? (
        <RoutingRuleForm rule={null} numbers={numbers} agents={agents} limits={limits}
          onSaved={(saved) => {
            setOpen(null);
            onRules([...rules, saved]);
          }}
          onCancel={() => setOpen(null)} />
      ) : (
        <p>
          <button type="button" disabled={busy || full} aria-describedby={full ? "routing-rules-full" : undefined} onClick={() => {
            setNotice(null);
            setOpen(NEW);
          }}>{t("routingAddRule")}</button>{" "}
          {full && <small className="muted" id="routing-rules-full">{t("routingRulesFull", { max: String(limits.rulesMax) })}</small>}
        </p>
      ))}
    </section>
  );
}

function Rule({ rule: r, numbers, canManage, busy, first, last, onUp, onDown, onTurn, onEdit, onDelete }: {
  rule: RoutingRule;
  numbers: RoutingNumber[];
  canManage: boolean;
  busy: boolean;
  first: boolean;
  last: boolean;
  onUp: () => void;
  onDown: () => void;
  onTurn: () => void;
  onEdit: () => void;
  onDelete: () => void;
}) {
  const [asking, setAsking] = useState(false);
  return (
    <>
      <h3>
        {r.name} <span className="badge">{t(r.enabled ? "routingRuleOn" : "routingRuleOff")}</span>
      </h3>
      <p>{whenWords(r, numbers)}</p>
      <p>{thenWords(r)}</p>
      {r.agentGone && <p className="check check-warning">{t("routingAgentGone")}</p>}
      {canManage && (asking ? (
        <div className="actions">
          <span>{t("routingConfirmDelete", { name: r.name })}</span>
          <button type="button" disabled={busy} onClick={onDelete}>{t("routingYesDelete")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
        </div>
      ) : (
        <div className="actions">
          {!first && <button type="button" className="secondary" disabled={busy} onClick={onUp}>{t("moveUp")}</button>}
          {!last && <button type="button" className="secondary" disabled={busy} onClick={onDown}>{t("moveDown")}</button>}
          <button type="button" className="secondary" disabled={busy} onClick={onTurn}>{t(r.enabled ? "routingTurnOff" : "routingTurnOn")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={onEdit}>{t("edit")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(true)}>{t("routingDelete")}</button>
        </div>
      ))}
    </>
  );
}
