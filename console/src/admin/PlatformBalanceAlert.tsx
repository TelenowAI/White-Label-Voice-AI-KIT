/**
 * Admin → Platform billing → the low-balance level (contract/kit-api.yaml setPlatformBalanceAlert): the USD amount at
 * which the voice platform calls the operator's balance low, empty for none, with one line on what it does (the staff
 * who handle money are emailed when the balance falls to it and when the platform stops the account; a postpaid account
 * with no cap never counts as low). Save sends the text as typed (trimmed), or null when empty; the field starts at the
 * level the platform holds, to the cent, so saving it untouched is taken as it is. The backend's 422 for the field shows
 * by the field; any other refusal (the platform refused the level, not connected yet, not reachable) as an alert. The
 * answer is the state the platform gives at once, handed to `onSaved` for the page to show, and the field follows it.
 *
 * Used by PlatformBillingPage.tsx. Not here: the state shown (PlatformAccount.tsx), the emails (the backend's
 * App\Kit\Telenow\PlatformBilling).
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type PlatformBillingState } from "../api";
import { t } from "../text";
import { rounded } from "../wallet/money";
import { CENTS } from "./platformBillingFormat";

/** A level as the field shows it: to the cent ("100.0000000000" → "100.00"), empty for none. */
function asTyped(level: string | null | undefined): string {
  return level == null ? "" : rounded(level, CENTS);
}

export function PlatformBalanceAlert({ level, onSaved }: { level: string | null | undefined; onSaved: (state: PlatformBillingState) => void }) {
  const [text, setText] = useState(asTyped(level));
  const [fieldErrors, setFieldErrors] = useState<string[]>([]);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFieldErrors([]);
    setRefusal(null);
    setSaved(false);
    try {
      const answer = await kitApi.setPlatformBalanceAlert(text.trim() === "" ? null : text.trim());
      onSaved(answer);
      if (answer.state) setText(asTyped(answer.state.lowThresholdUsd));
      setSaved(true);
    } catch (err) {
      if (err instanceof KitApiError && err.fields.thresholdUsd) setFieldErrors(err.fields.thresholdUsd);
      else setRefusal(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} noValidate aria-labelledby="platform-billing-alert">
      <h2 id="platform-billing-alert">{t("platformBillingAlertTitle")}</h2>
      <p className="muted">{t("platformBillingAlertHelp")}</p>
      <div className="field field-narrow">
        <label htmlFor="platform-billing-level">{t("platformBillingAlertLevel")}</label>
        <input id="platform-billing-level" inputMode="decimal" value={text} onChange={(e) => setText(e.target.value)} aria-describedby="platform-billing-level-help" />
        <small id="platform-billing-level-help" className="muted">{t("platformBillingAlertEmpty")}</small>
        {fieldErrors.length > 0 && <small className="error">{fieldErrors.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("platformBillingAlertSave")}</button>
      {refusal && <p role="alert" className="error">{refusal}</p>}
      {saved && <p role="status">{t("platformBillingAlertSaved")}</p>}
    </form>
  );
}
