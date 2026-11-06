/**
 * Admin → a customer → Wallet (contract/kit-api.yaml getCustomerWallet, addCustomerCredit, setCustomerPlan, getPricing):
 * its balance and plan, the stop last set at the voice platform, its calls not yet charged, crediting it by hand (a bank
 * transfer, say: the form's reference makes a resent credit count once), and its entries — each charge beside what the
 * voice platform charged for the call and the margin (cost versus price).
 *
 * Not here: the prices themselves (Pricing.tsx).
 */
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type CustomerWallet as Wallet, type PriceListRow } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";
import { useMay } from "./may";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function CustomerWallet({ customerId }: { customerId: string }) {
  const [wallet, setWallet] = useState<Wallet | null>(null);
  const [lists, setLists] = useState<PriceListRow[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  // Plans and credit are money: a role without it reads the wallet only.
  const mayMoney = useMay()("money");

  const load = useCallback(async () => {
    try {
      const [w, p] = await Promise.all([kitApi.customerWallet(customerId), mayMoney ? kitApi.pricing() : Promise.resolve(null)]);
      setWallet(w);
      setLists(p?.lists ?? []);
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, [customerId, mayMoney]);

  useEffect(() => {
    void load();
  }, [load]);

  const planName = (id: string | null) => lists.find((l) => l.id === id)?.name ?? id ?? t("noPlan");

  return (
    <section aria-labelledby="customer-wallet-title">
      <h2 id="customer-wallet-title">{t("walletOf")}</h2>
      {failure && <p role="alert" className="error">{failure}</p>}
      {wallet && (
        <>
          {!wallet.inForce && (
            <p className="muted">
              {t("walletNotInForce")} <Link to="/admin/pricing">{t("pricingTitle")}</Link>
            </p>
          )}
          <dl className="facts">
            <dt>{t("balance")}</dt>
            <dd data-testid="customer-balance">{money(wallet.balance, wallet.currency)}</dd>
            <dt>
              <label htmlFor="customer-plan">{t("plan")}</label>
            </dt>
            <dd>
              <select
                id="customer-plan"
                disabled={!mayMoney}
                value={wallet.plan ?? ""}
                onChange={async (e) => {
                  try {
                    setWallet(await kitApi.setCustomerPlan(customerId, e.target.value || null));
                  } catch (err) {
                    setFailure(message(err));
                  }
                }}
              >
                <option value="">{t("planDefault", { plan: planName(wallet.plan === null ? wallet.effectivePlan : null) })}</option>
                {lists.map((l) => (
                  <option key={l.id} value={l.id}>{l.name}</option>
                ))}
              </select>
            </dd>
            <dt>{t("spendLimit")}</dt>
            <dd>{wallet.spendLimitUsd ? t("spendLimitValue", { usd: wallet.spendLimitUsd }) : t("spendLimitNone")}</dd>
          </dl>
          {wallet.calls.waiting > 0 && <p className="muted">{t("callsWaiting", { n: String(wallet.calls.waiting) })}</p>}
          {wallet.calls.unpriced > 0 && (
            <p role="status" className="sync-failed">
              {t("callsUnpriced", { n: String(wallet.calls.unpriced) })} <Link to="/admin/pricing">{t("pricingTitle")}</Link>
            </p>
          )}
          {wallet.inForce && mayMoney && <AddCredit customerId={customerId} onAdded={load} />}
          {wallet.entries.length === 0 ? (
            <p className="muted">{t("walletEmpty")}</p>
          ) : (
            <div className="table-scroll">
              <table className="list">
                <thead>
                  <tr>
                    <th scope="col">{t("colWhen")}</th>
                    <th scope="col">{t("colWhat")}</th>
                    <th scope="col">{t("colAmount")}</th>
                    <th scope="col">{t("colBalanceAfter")}</th>
                    <th scope="col">{t("colVoiceCost")}</th>
                    <th scope="col">{t("colMargin")}</th>
                  </tr>
                </thead>
                <tbody>
                  {wallet.entries.map((e) => (
                    <tr key={e.id}>
                      <td>{new Date(e.createdAt).toLocaleString()}</td>
                      <td>
                        {t(e.kind === "credit" ? "entryCredit" : e.callId ? "entryCall" : e.numberId ? "entryNumberFee" : "entryCharge")}
                        {e.note && <small className="muted"> · {e.note}</small>}
                      </td>
                      <td>{money(e.amount, e.currency)}</td>
                      <td>{money(e.balanceAfter, e.currency)}</td>
                      <td>{e.telenowCostUsd ? money(e.telenowCostUsd, "USD") : ""}</td>
                      <td>{e.margin ? money(e.margin, e.currency) : ""}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </section>
  );
}

function AddCredit({ customerId, onAdded }: { customerId: string; onAdded: () => void }) {
  const [amount, setAmount] = useState("");
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // One reference per credit: kept until it is accepted, so a form sent again (a timeout, a double click) counts once.
  const reference = useRef(globalThis.crypto.randomUUID());

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await kitApi.addCustomerCredit(customerId, { amount, note: note || null, reference: reference.current });
      reference.current = globalThis.crypto.randomUUID();
      setAmount("");
      setNote("");
      onAdded();
    } catch (err) {
      setError(err instanceof KitApiError ? (err.fields.amount?.join(" ") ?? err.message) : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate aria-label={t("addCredit")}>
      <div className="field field-narrow">
        <label htmlFor="credit-amount">{t("creditAmount")}</label>
        <input id="credit-amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} aria-invalid={error ? true : undefined} />
      </div>
      <div className="field">
        <label htmlFor="credit-note">{t("creditNote")}</label>
        <input id="credit-note" value={note} onChange={(e) => setNote(e.target.value)} />
      </div>
      <button type="submit" disabled={busy}>{busy ? t("adding") : t("addCredit")}</button>
      {error && <small className="error" role="alert">{error}</small>}
    </form>
  );
}
