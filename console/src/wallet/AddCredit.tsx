/**
 * Console → Wallet → Add credit (contract/kit-api.yaml getTopUp, startTopUp, getPayment):
 * - the ways to pay this account is offered, in the operator's order, and an amount within the operator's rules —
 *   then on to the gateway's checkout (wallet/checkout.ts), or, for a bank transfer, its instructions and the
 *   reference to quote;
 * - back from a gateway (`?payment=<id>`), the payment's state, read again every `pollSeconds` while it is open; the
 *   wallet is reloaded (`onPaid`) once it is paid;
 * - the account's recent payments, an open bank transfer with its reference.
 * When the account cannot start a top-up (paused), it says why; with nothing offered, it says so.
 *
 * Not here: the balance and entries (WalletPage.tsx), crediting (the backend, once the gateway confirms).
 */
import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import { KitApiError, kitApi, type ConsolePayment, type PayerDetails, type PaymentStatus, type TopUp } from "../api";
import { t, type TextKey } from "../text";
import { goToCheckout, type Navigation } from "./checkout";
import { PayerFields, payerGiven } from "./PayerFields";
import { money } from "./money";

const statuses: Record<PaymentStatus, TextKey> = {
  open: "paymentOpen", paid: "paymentPaid", review: "paymentReview", failed: "paymentFailed", canceled: "paymentCanceled", expired: "paymentExpired",
};

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function AddCredit({ onPaid, nav }: { onPaid: () => void; nav?: Navigation }) {
  const [topUp, setTopUp] = useState<TopUp | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [params] = useSearchParams();
  const returned = params.get("payment");

  const load = useCallback(async () => {
    try {
      setTopUp(await kitApi.topUp());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (failure) return <p role="alert" className="error">{failure}</p>;
  if (!topUp || !topUp.inForce) return null;

  return (
    <section aria-labelledby="add-credit-title">
      <h2 id="add-credit-title">{t("addCreditTitle")}</h2>
      {returned && <Returned id={returned} pollSeconds={topUp.pollSeconds} onSettled={(p) => { void load(); if (p.status === "paid") onPaid(); }} />}
      {topUp.paused ? (
        <p className="note">{topUp.paused}</p>
      ) : topUp.gateways.length === 0 ? (
        <p className="muted">{t("addCreditNone")}</p>
      ) : (
        <TopUpForm topUp={topUp} nav={nav} onStarted={load} />
      )}
      <Payments payments={topUp.payments} />
    </section>
  );
}

function TopUpForm({ topUp, nav, onStarted }: { topUp: TopUp; nav?: Navigation; onStarted: () => void }) {
  const [gatewayId, setGatewayId] = useState(topUp.gateways[0].id);
  const [amount, setAmount] = useState("");
  const [payer, setPayer] = useState<PayerDetails>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [instructions, setInstructions] = useState<{ text: string; reference: string; amount: string } | null>(null);
  const asks = topUp.gateways.find((g) => g.id === gatewayId)?.asks ?? [];
  const currency = topUp.currency ?? "";
  const range = topUp.minAmount && topUp.maxAmount
    ? t("amountBetween", { min: money(topUp.minAmount, currency), max: money(topUp.maxAmount, currency) })
    : topUp.minAmount ? t("amountAtLeast", { min: money(topUp.minAmount, currency) })
      : topUp.maxAmount ? t("amountAtMost", { max: money(topUp.maxAmount, currency) }) : null;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const { payment, checkout } = await kitApi.startTopUp(gatewayId, amount.trim(), payerGiven(asks, payer));
      if (!goToCheckout(checkout, nav) && checkout.instructions) {
        setInstructions({ text: checkout.instructions, reference: checkout.reference ?? "", amount: money(payment.amount, payment.currency) });
        onStarted();
      }
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  if (instructions) {
    return (
      <div className="note" role="status">
        <p>{t("transferAsk", { amount: instructions.amount })}</p>
        <p className="instructions">{instructions.text}</p>
        <p>{t("transferReference")} <strong data-testid="reference">{instructions.reference}</strong></p>
        <p className="muted">{t("transferAfter")}</p>
        <button type="button" className="secondary" onClick={() => { setInstructions(null); setAmount(""); }}>{t("addMoreCredit")}</button>
      </div>
    );
  }

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("addCreditTitle")}>
      <div className="field">
        <label htmlFor="top-up-amount">{t("amountIn", { currency })}</label>
        {range && <small id="top-up-amount-help" className="muted">{range}</small>}
        <input id="top-up-amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)}
          aria-describedby={range ? "top-up-amount-help" : undefined} aria-invalid={errors.amount ? true : undefined} />
        {errors.amount && <small className="error">{errors.amount.join(" ")}</small>}
      </div>
      <fieldset className="field">
        <legend>{t("payWith")}</legend>
        {topUp.gateways.map((g) => (
          <label key={g.id} className="checkbox">
            <input type="radio" name="gateway" value={g.id} checked={gatewayId === g.id} onChange={() => setGatewayId(g.id)} />
            {g.name}
            {g.methods.length > 0 && <small className="muted"> · {g.methods.join(", ")}</small>}
          </label>
        ))}
        {errors.gatewayId && <small className="error">{errors.gatewayId.join(" ")}</small>}
      </fieldset>
      <PayerFields asks={asks} payer={payer} errors={errors} onChange={setPayer} />
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("continueToPay")}</button>
    </form>
  );
}

/** A payment the payer is back from: read again while it is open. */
function Returned({ id, pollSeconds, onSettled }: { id: string; pollSeconds: number; onSettled: (p: ConsolePayment) => void }) {
  const [payment, setPayment] = useState<ConsolePayment | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  // The parent's callback is new on each render; the payment is read once per id.
  const settled = useRef(onSettled);
  settled.current = onSettled;

  useEffect(() => {
    let stop = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    async function read() {
      try {
        const p = await kitApi.payment(id);
        if (stop) return;
        setPayment(p);
        if (p.status === "open") timer = setTimeout(read, pollSeconds * 1000);
        else settled.current(p);
      } catch (err) {
        if (!stop) setFailure(message(err));
      }
    }
    void read();
    return () => {
      stop = true;
      clearTimeout(timer);
    };
  }, [id, pollSeconds]);

  if (failure) return <p role="alert" className="error">{failure}</p>;
  if (!payment) return <p aria-busy="true">{t("loading")}</p>;
  const amount = money(payment.amount, payment.currency);
  const said: Record<PaymentStatus, string> = {
    open: t("returnedOpen", { amount }),
    paid: t("returnedPaid", { amount }),
    review: t("returnedReview", { amount }),
    failed: t("returnedFailed", { amount }),
    canceled: t("returnedFailed", { amount }),
    expired: t("returnedFailed", { amount }),
  };
  return <p role="status" className={payment.status === "paid" ? "note" : payment.status === "open" ? "muted" : "error"}>{said[payment.status]}</p>;
}

function Payments({ payments }: { payments: ConsolePayment[] }) {
  if (payments.length === 0) return null;
  return (
    <>
      <h3>{t("yourPayments")}</h3>
      <div className="table-scroll">
        <table className="list" aria-label={t("yourPayments")}>
          <thead>
            <tr>
              <th scope="col">{t("colWhen")}</th>
              <th scope="col">{t("colAmount")}</th>
              <th scope="col">{t("colStatus")}</th>
              <th scope="col">{t("colPaidWith")}</th>
            </tr>
          </thead>
          <tbody>
            {payments.map((p) => (
              <tr key={p.id}>
                <td>{new Date(p.createdAt).toLocaleString()}</td>
                <td>
                  {money(p.amount, p.currency)}
                  {Number(p.refunded) > 0 && <small className="muted"> · {t("refundedAmount", { amount: money(p.refunded, p.currency) })}</small>}
                </td>
                <td>
                  {t(statuses[p.status])}
                  {p.status === "open" && p.reference && <small className="muted"> · {t("quoteReference", { reference: p.reference })}</small>}
                </td>
                <td>{p.gatewayName}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}
