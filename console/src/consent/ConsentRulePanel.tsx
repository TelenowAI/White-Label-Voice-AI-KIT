/**
 * Whether the account's calls need consent on file (contract/kit-api.yaml setConsentRule): who says so, how many waiting calls it touches, and changing it, only after asking, because turning it on stops every call to a number with no consent. Only someone who
 * manages the account may, and nobody here can turn it off while the operator or the account above requires it.
 *
 * Not here: the records (ConsentPage.tsx).
 */
import { useState } from "react";
import { kitApi, type ConsentRule } from "../api";
import { t } from "../text";

export function ConsentRulePanel({ rule, onChanged }: { rule: ConsentRule; onChanged: (rule: ConsentRule) => void }) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const w = rule.waiting;
  const held = rule.required && (rule.byOperator || rule.byProvider);

  async function change() {
    setBusy(true);
    setFailure(null);
    try {
      onChanged(await kitApi.setConsentRule(!rule.required));
      setAsking(false);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  const words = { total: String(w?.total ?? 0), covered: String(w?.covered ?? 0), blocked: String(w?.blocked ?? 0) };
  return (
    <>
      <h2>{t("consentRuleTitle")}</h2>
      <p role="status">{t(rule.required ? "consentRuleOn" : "consentRuleOff")}</p>
      {rule.byOperator && <p className="muted">{t("consentByOperator")}</p>}
      {!rule.byOperator && rule.byProvider && <p className="muted">{t("consentByProvider")}</p>}
      {rule.saving && <p className="muted">{t("consentSaving")}</p>}
      {w && w.total > 0 && <p className="muted">{t(rule.required ? "consentWaitingOn" : "consentWaitingOff", words)}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      {rule.canChange && !held && !asking && (
        <p><button type="button" className="secondary" onClick={() => setAsking(true)}>{t(rule.required ? "consentTurnOff" : "consentTurnOn")}</button></p>
      )}
      {!rule.canChange && !held && <p className="muted">{t("consentOnlyManagers")}</p>}
      {asking && (
        <div className="actions">
          <span>{rule.required ? t("consentAskOff") : w && w.blocked > 0 ? t("consentAskOn", words) : t("consentAskOnNone")}</span>
          <button type="button" disabled={busy} onClick={() => void change()}>{t(rule.required ? "consentYesOff" : "consentYesOn")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
        </div>
      )}
    </>
  );
}
