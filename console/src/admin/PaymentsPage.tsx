/**
 * Admin → Payments (`/admin/payments`; contract/kit-api.yaml getPayments, savePaymentSettings, addPaymentGateway,
 * updatePaymentGateway, removePaymentGateway, orderPaymentGateways, testPaymentGateway, confirmPayment, cancelPayment,
 * refundPayment):
 * - the top-up rules (smallest, largest) and what a dispute does;
 * - the ways to pay, in the order customers see them: enabled or not, test or live, the webhook address and the events
 *   to tick for an online gateway (and whether a verified event has come yet), a warning when one does not take the
 *   kit's currency; each changed in a form drawn from its driver's own fields (setup/StepForm.tsx FieldInput; a secret
 *   never shown and kept when left empty), its payment methods and plans; moved up; removed only after asking; a test
 *   payment through one in test mode, end to end;
 * - the payments waiting for staff — a bank transfer to confirm with the amount that arrived, or cancel; a payment to
 *   review — and the recent ones, each refunded in part or whole (one click is one refund).
 * Back from a test payment (`?payment=<id>`), how it went.
 *
 * Not here: the wizard's Payments step (drawn by setup/StepForm.tsx from what the backend declares).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import {
  KitApiError, kitApi, type AdminPayment, type PayerDetails, type PaymentGateway, type PaymentGatewayInput, type PaymentStatus, type PaymentsAdmin, type SetupField,
} from "../api";
import { FieldInput } from "../setup/StepForm";
import { t, type TextKey } from "../text";
import { goToCheckout, type Navigation } from "../wallet/checkout";
import { PayerFields, payerGiven } from "../wallet/PayerFields";
import { money, subtract } from "../wallet/money";

const statuses: Record<PaymentStatus, TextKey> = {
  open: "paymentOpen", paid: "paymentPaid", review: "paymentReview", failed: "paymentFailed", canceled: "paymentCanceled", expired: "paymentExpired",
};

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function startValues(fields: SetupField[]): Record<string, string> {
  return Object.fromEntries(fields.map((f) => [f.name, f.value ?? (f.type === "select" ? (f.options[0]?.value ?? "") : "")]));
}

export function PaymentsPage({ nav }: { nav?: Navigation }) {
  const [data, setData] = useState<PaymentsAdmin | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [params] = useSearchParams();
  const returned = params.get("payment");

  const load = useCallback(async () => {
    try {
      setData(await kitApi.paymentsAdmin());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const tested = returned ? data?.recent.find((p) => p.id === returned) : undefined;

  return (
    <section aria-labelledby="payments-title">
      <h1 id="payments-title">{t("paymentsTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && (
        <>
          {!data.inForce && <p className="note">{t("paymentsNotInForce")}</p>}
          {tested && <p role="status" className="note">{t("testPaymentResult", { amount: money(tested.amount, tested.currency), status: t(statuses[tested.status]) })}</p>}
          <RulesForm data={data} onSaved={setData} />
          <Gateways data={data} nav={nav} onChanged={setData} onReload={load} />
          <AddGateway data={data} onAdded={setData} />
          <PaymentList title="waitingPaymentsTitle" empty="waitingPaymentsEmpty" payments={data.waiting} onChanged={setData} />
          <PaymentList title="recentPaymentsTitle" empty="recentPaymentsEmpty" payments={data.recent} onChanged={setData} />
        </>
      )}
    </section>
  );
}

function RulesForm({ data, onSaved }: { data: PaymentsAdmin; onSaved: (d: PaymentsAdmin) => void }) {
  const [form, setForm] = useState({ minAmount: data.settings.minAmount ?? "", maxAmount: data.settings.maxAmount ?? "", disputeAction: data.settings.disputeAction });
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setSaved(false);
    try {
      onSaved(await kitApi.savePaymentSettings({ minAmount: form.minAmount.trim() || null, maxAmount: form.maxAmount.trim() || null, disputeAction: form.disputeAction }));
      setSaved(true);
    } catch (err) {
      setErrors(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : { minAmount: [message(err)] });
    } finally {
      setBusy(false);
    }
  }

  const amount = (name: "minAmount" | "maxAmount", label: TextKey) => (
    <div className="field">
      <label htmlFor={`payments-${name}`}>{t(label, { currency: data.currency ?? "" })}</label>
      <input id={`payments-${name}`} inputMode="decimal" value={form[name]} onChange={(e) => setForm((f) => ({ ...f, [name]: e.target.value }))}
        aria-invalid={errors[name] ? true : undefined} />
      {errors[name] && <small className="error">{errors[name].join(" ")}</small>}
    </div>
  );

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("paymentRulesTitle")}>
      <h2>{t("paymentRulesTitle")}</h2>
      <p className="muted">{t("paymentRulesHelp")}</p>
      {amount("minAmount", "smallestTopUp")}
      {amount("maxAmount", "largestTopUp")}
      <div className="field">
        <label htmlFor="payments-dispute">{t("whenDisputed")}</label>
        <select id="payments-dispute" value={form.disputeAction} onChange={(e) => setForm((f) => ({ ...f, disputeAction: e.target.value }))}>
          {data.disputeActions.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
        {errors.disputeAction && <small className="error">{errors.disputeAction.join(" ")}</small>}
      </div>
      {saved && <p role="status">{t("siteSaved")}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveSite")}</button>
    </form>
  );
}

/** A gateway's form: the kit's own fields (name, mode, methods, plans), then its driver's. */
function GatewayForm({ id, data, driverId, gateway, label, onSubmit }: {
  id: string; data: PaymentsAdmin; driverId: string; gateway?: PaymentGateway; label: TextKey; onSubmit: (input: PaymentGatewayInput) => Promise<void>;
}) {
  const driver = data.drivers.find((d) => d.id === driverId)!;
  const fields = gateway ? gateway.fields : driver.fields;
  const [name, setName] = useState(gateway?.name ?? driver.name);
  const [mode, setMode] = useState(gateway?.mode ?? driver.modes[0]?.value ?? "live");
  const [methods, setMethods] = useState<string[]>(gateway?.methods ?? driver.methods.map((m) => m.value));
  const [plans, setPlans] = useState<string[]>(gateway?.plans ?? []);
  const [values, setValues] = useState(() => startValues(fields));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const toggle = (list: string[], value: string) => (list.includes(value) ? list.filter((v) => v !== value) : [...list, value]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      await onSubmit({
        name: name.trim(), values, plans: plans.length ? plans : null,
        ...(driver.online ? { mode: mode as "test" | "live" } : {}), ...(driver.methods.length ? { methods } : {}),
      });
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const said = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;

  return (
    <form onSubmit={submit} noValidate aria-label={t(label)}>
      <div className="field">
        <label htmlFor={`${id}-name`}>{t("gatewayName")}</label>
        <input id={`${id}-name`} value={name} onChange={(e) => setName(e.target.value)} />
        {said("name")}
      </div>
      {driver.modes.length > 0 && (
        <div className="field">
          <label htmlFor={`${id}-mode`}>{t("gatewayMode")}</label>
          <small className="muted">{t("gatewayModeHelp")}</small>
          <select id={`${id}-mode`} value={mode} onChange={(e) => setMode(e.target.value as "test" | "live")}>
            {driver.modes.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
          {said("mode")}
        </div>
      )}
      {driver.methods.length > 0 && (
        <fieldset className="field">
          <legend>{t("gatewayMethods")}</legend>
          {driver.methods.map((m) => (
            <label key={m.value} className="checkbox">
              <input type="checkbox" checked={methods.includes(m.value)} onChange={() => setMethods((l) => toggle(l, m.value))} />
              {m.label}
            </label>
          ))}
          {said("methods")}
        </fieldset>
      )}
      {data.plans.length > 1 && (
        <fieldset className="field">
          <legend>{t("gatewayPlans")}</legend>
          <small className="muted">{t("gatewayPlansHelp")}</small>
          {data.plans.map((p) => (
            <label key={p.id} className="checkbox">
              <input type="checkbox" checked={plans.includes(p.id)} onChange={() => setPlans((l) => toggle(l, p.id))} />
              {p.name}
            </label>
          ))}
        </fieldset>
      )}
      {fields.map((f) => (
        <FieldInput key={f.name} stepId={id} field={f} value={values[f.name] ?? ""} errors={errors[`values.${f.name}`] ?? []}
          onChange={(v) => setValues((old) => ({ ...old, [f.name]: v }))} />
      ))}
      {failure && <p role="alert" className="error">{failure}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t(label)}</button>
    </form>
  );
}

function Gateways({ data, nav, onChanged, onReload }: { data: PaymentsAdmin; nav?: Navigation; onChanged: (d: PaymentsAdmin) => void; onReload: () => void }) {
  const [editing, setEditing] = useState<string | null>(null);
  const [asking, setAsking] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const ids = data.gateways.map((g) => g.id);

  async function act(run: () => Promise<PaymentsAdmin | void>) {
    setFailure(null);
    try {
      const next = await run();
      if (next) onChanged(next);
      else onReload();
      setAsking(null);
    } catch (err) {
      setFailure(message(err));
    }
  }

  return (
    <>
      <h2>{t("gatewaysTitle")}</h2>
      {data.gateways.length === 0 && <p className="muted">{t("gatewaysEmpty")}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      <ol className="senders">
        {data.gateways.map((g, i) => (
          <li key={g.id} data-gateway={g.id}>
            <div className="actions">
              <span>
                <strong>{g.name}</strong> · {g.driverName}
                {g.online && <> · {t(g.mode === "live" ? "modeLive" : "modeTest")}</>} · {t(g.enabled ? "gatewayOn" : "gatewayOff")}
              </span>
              <button type="button" className="secondary" onClick={() => act(() => kitApi.updatePaymentGateway(g.id, { enabled: !g.enabled }))}>
                {t(g.enabled ? "disableGateway" : "enableGateway")}
              </button>
              {i > 0 && (
                <button type="button" className="secondary" onClick={() => act(() => kitApi.orderPaymentGateways([...ids.slice(0, i - 1), g.id, ids[i - 1], ...ids.slice(i + 1)]))}>
                  {t("moveUp")}
                </button>
              )}
              <button type="button" className="secondary" onClick={() => setEditing(editing === g.id ? null : g.id)}>{t("edit")}</button>
              {asking === g.id ? (
                <>
                  <span>{t("confirmRemoveGateway", { name: g.name })}</span>
                  <button type="button" onClick={() => act(() => kitApi.removePaymentGateway(g.id))}>{t("yesRemove")}</button>
                  <button type="button" className="secondary" onClick={() => setAsking(null)}>{t("cancel")}</button>
                </>
              ) : (
                <button type="button" className="secondary" onClick={() => setAsking(g.id)}>{t("remove")}</button>
              )}
            </div>
            {!g.takesCurrency && <p className="error">{t("gatewayNoCurrency", { currency: data.currency ?? "" })}</p>}
            {g.webhookUrl && (
              <div className="muted">
                <p>{t("webhookAt")} <code className="wrap-anywhere">{g.webhookUrl}</code></p>
                {g.webhookEvents.length > 0 && <p className="wrap-anywhere">{t("webhookEvents", { events: g.webhookEvents.join(", ") })}</p>}
                <p>{g.lastEventAt ? t("lastEventAt", { when: new Date(g.lastEventAt).toLocaleString() }) : t("waitingFirstEvent")}</p>
              </div>
            )}
            {g.online && g.mode === "test" && <TestPayment gateway={g} data={data} nav={nav} />}
            {editing === g.id && (
              <GatewayForm id={`gateway-${g.id}`} data={data} driverId={g.driver} gateway={g} label="saveGateway"
                onSubmit={async (input) => {
                  onChanged(await kitApi.updatePaymentGateway(g.id, input));
                  setEditing(null);
                }} />
            )}
          </li>
        ))}
      </ol>
    </>
  );
}

function TestPayment({ gateway, data, nav }: { gateway: PaymentGateway; data: PaymentsAdmin; nav?: Navigation }) {
  const [amount, setAmount] = useState(data.settings.minAmount ?? "1");
  const [payer, setPayer] = useState<PayerDetails>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFailure(null);
    try {
      goToCheckout((await kitApi.testPaymentGateway(gateway.id, amount.trim(), payerGiven(gateway.asks, payer))).checkout, nav);
    } catch (err) {
      setFailure(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? Object.values(err.fields).flat().join(" ") : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="actions" onSubmit={submit} noValidate aria-label={t("testPayment")}>
      <label htmlFor={`test-${gateway.id}`}>{t("testPaymentAmount", { currency: data.currency ?? "" })}</label>
      <input id={`test-${gateway.id}`} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
      <PayerFields asks={gateway.asks} payer={payer} errors={{}} onChange={setPayer} />
      <button type="submit" className="secondary" disabled={busy}>{t("testPayment")}</button>
      {failure && <span role="alert" className="error">{failure}</span>}
    </form>
  );
}

function AddGateway({ data, onAdded }: { data: PaymentsAdmin; onAdded: (d: PaymentsAdmin) => void }) {
  const [driver, setDriver] = useState(data.drivers[0]?.id ?? "");
  // Bumped after each gateway is added, so the form starts empty again (no secret left in the page).
  const [added, setAdded] = useState(0);
  if (data.gateways.length >= data.maxGateways) return <p className="muted">{t("gatewaysFull", { max: String(data.maxGateways) })}</p>;

  return (
    <div className="agent-form">
      <h2>{t("addGatewayTitle")}</h2>
      <p className="muted">{t("addGatewayHelp")}</p>
      <div className="field">
        <label htmlFor="payments-driver">{t("gatewayDriver")}</label>
        <select id="payments-driver" value={driver} onChange={(e) => setDriver(e.target.value)}>
          {data.drivers.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
        </select>
      </div>
      {driver && (
        <GatewayForm key={`${driver}-${added}`} id={`new-${driver}`} data={data} driverId={driver} label="addGateway"
          onSubmit={async (input) => {
            onAdded(await kitApi.addPaymentGateway(driver, input));
            setAdded((n) => n + 1);
          }} />
      )}
    </div>
  );
}

function PaymentList({ title, empty, payments, onChanged }: { title: TextKey; empty: TextKey; payments: AdminPayment[]; onChanged: (d: PaymentsAdmin) => void }) {
  return (
    <>
      <h2>{t(title)}</h2>
      {payments.length === 0 ? (
        <p className="muted">{t(empty)}</p>
      ) : (
        <div className="table-scroll">
          <table className="list" aria-label={t(title)}>
            <thead>
              <tr>
                <th scope="col">{t("colWhen")}</th>
                <th scope="col">{t("colCustomer")}</th>
                <th scope="col">{t("colAmount")}</th>
                <th scope="col">{t("colStatus")}</th>
                <th scope="col">{t("colPaidWith")}</th>
                <th scope="col">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((p) => <PaymentRow key={p.id} payment={p} onChanged={onChanged} />)}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

function PaymentRow({ payment: p, onChanged }: { payment: AdminPayment; onChanged: (d: PaymentsAdmin) => void }) {
  const left = subtract(p.received ?? p.amount, p.refunded);
  const [doing, setDoing] = useState<"confirm" | "refund" | "cancel" | null>(null);
  const [amount, setAmount] = useState("");
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // One refund per click: a reference made when the form opens, so sending it twice refunds once.
  const [reference, setReference] = useState("");

  function open(what: "confirm" | "refund" | "cancel") {
    setDoing(what);
    setFailure(null);
    setAmount(what === "confirm" ? (p.receivedCurrency ? "" : (p.received ?? p.amount)) : what === "refund" ? left : "");
    setReference(crypto.randomUUID());
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFailure(null);
    try {
      onChanged(doing === "confirm" ? await kitApi.confirmPayment(p.id, amount.trim())
        : doing === "refund" ? await kitApi.refundPayment(p.id, amount.trim(), reference)
          : await kitApi.cancelPayment(p.id));
      setDoing(null);
    } catch (err) {
      setFailure(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? Object.values(err.fields).flat().join(" ") : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <tr data-payment={p.id}>
      <td>{new Date(p.createdAt).toLocaleString()}</td>
      <td>{p.customer ? p.customer.name : t("testPaymentRow")}</td>
      <td>
        {money(p.amount, p.currency)}
        {p.received && p.received !== p.amount && <small className="muted"> · {t("receivedAmount", { amount: p.receivedCurrency ? `${p.received} ${p.receivedCurrency}` : money(p.received, p.currency) })}</small>}
        {Number(p.refunded) > 0 && <small className="muted"> · {t("refundedAmount", { amount: money(p.refunded, p.currency) })}</small>}
      </td>
      <td>
        {t(statuses[p.status])}
        {p.dispute && <small className="error"> · {t(p.dispute === "open" ? "disputeOpen" : p.dispute === "won" ? "disputeWon" : "disputeLost")}</small>}
        {p.error && <small className="error"> · {p.error}</small>}
        {p.refundFailed && <small className="error"> · {t("refundFailedNote")}</small>}
      </td>
      <td>
        {p.gatewayName}
        {p.reference && <small className="muted"> · {p.reference}</small>}
      </td>
      <td>
        {doing ? (
          <form className="actions" onSubmit={submit} noValidate aria-label={t(doing === "confirm" ? "confirmPayment" : doing === "refund" ? "refundPayment" : "cancelPayment")}>
            {doing === "cancel" ? (
              <span>{t("confirmCancelPayment")}</span>
            ) : (
              <>
                <label htmlFor={`${doing}-${p.id}`}>{t(doing === "confirm" ? "amountArrived" : "amountToRefund", { currency: p.currency })}</label>
                <input id={`${doing}-${p.id}`} inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
              </>
            )}
            <button type="submit" disabled={busy}>{t(doing === "confirm" ? "confirmPayment" : doing === "refund" ? "refundPayment" : "yesCancelPayment")}</button>
            <button type="button" className="secondary" onClick={() => setDoing(null)}>{t("cancel")}</button>
            {failure && <span role="alert" className="error">{failure}</span>}
          </form>
        ) : (
          <span className="actions">
            {p.canConfirm && <button type="button" className="secondary" onClick={() => open("confirm")}>{t("confirmPayment")}</button>}
            {p.canCancel && p.canConfirm && <button type="button" className="secondary" onClick={() => open("cancel")}>{t("cancelPayment")}</button>}
            {p.canRefund && !left.startsWith("-") && /[1-9]/.test(left) && <button type="button" className="secondary" onClick={() => open("refund")}>{t("refundPayment")}</button>}
          </span>
        )}
      </td>
    </tr>
  );
}
