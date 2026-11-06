/**
 * Console → Wallet → the low-balance email (contract/kit-api.yaml setWalletAlert): the balance the account is emailed
 * at — its own, else the platform's — and, for the roles that get the email (`alert.mayChange`), choosing it: an
 * amount, zero for only when the credit runs out, empty for the platform's. Others read it only. Shown on
 * WalletPage.tsx, which passes the wallet it answers back.
 *
 * Not here: sending the emails (the backend's App\Kit\Wallet\BalanceAlerts).
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Wallet } from "../api";
import { t } from "../text";
import { money } from "./money";

export function WalletAlert({ wallet, onSaved }: { wallet: Wallet; onSaved: (w: Wallet) => void }) {
  const { alert, currency } = wallet;
  const [level, setLevel] = useState(alert.lowBalanceAt ?? "");
  const [errors, setErrors] = useState<string[]>([]);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  const at = alert.lowBalanceAt ?? alert.defaultAt;
  const now = at === null || Number(at) <= 0 ? t("walletAlertOnlyEmpty") : t("walletAlertAt", { level: money(at, currency) });

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors([]);
    setSaved(false);
    try {
      onSaved(await kitApi.setWalletAlert(level.trim() === "" ? null : level.trim()));
      setSaved(true);
    } catch (err) {
      setErrors(err instanceof KitApiError && err.fields.lowBalanceAt ? err.fields.lowBalanceAt : [err instanceof Error ? err.message : String(err)]);
    } finally {
      setBusy(false);
    }
  }

  if (!alert.mayChange) {
    return (
      <p className="muted" data-testid="wallet-alert">{t("walletAlertTitle")}: {now}</p>
    );
  }
  return (
    <form onSubmit={submit} noValidate aria-labelledby="wallet-alert-title">
      <h2 id="wallet-alert-title">{t("walletAlertTitle")}</h2>
      <div className="field field-narrow">
        <label htmlFor="wallet-alert">{t("walletAlertLevel", { currency: currency ?? "" })}</label>
        <input id="wallet-alert" inputMode="decimal" value={level} onChange={(e) => setLevel(e.target.value)} aria-describedby="wallet-alert-help" />
        <small id="wallet-alert-help" className="muted">
          {alert.defaultAt === null ? t("walletAlertEmptyNone") : t("walletAlertEmptyDefault", { level: money(alert.defaultAt, currency) })} {t("walletAlertZero")}
        </small>
        {errors.length > 0 && <small className="error">{errors.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("walletAlertSave")}</button>
      <p role="status" className="muted" data-testid="wallet-alert">{saved ? `${t("siteSaved")} ` : ""}{now}</p>
    </form>
  );
}
