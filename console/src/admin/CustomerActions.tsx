/**
 * Admin → one customer's actions (contract/kit-api.yaml setCustomerLimits, viewAsCustomer, closeCustomer), each shown
 * only to a role that may use it (`may`, from the session's abilities):
 * - limits at the voice platform: the most calls at once and a monthly cap (USD), empty for none;
 * - looking at its console as it sees it, read-only for a while (the console says so, and how to stop);
 * - closing it for good, after typing its name: the voice platform releases its numbers, then closes the account; its
 *   wallet stays as it is, to settle by hand.
 * Nothing but reading is offered for a customer being closed or closed. Used by CustomerPage.tsx.
 */
import { useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { KitApiError, kitApi, type Customer } from "../api";
import { t } from "../text";

export function CustomerActions({ customer, may, onChanged }: { customer: Customer; may: (ability: string) => boolean; onChanged: (c: Customer) => void }) {
  const ended = customer.status === "closing" || customer.status === "closed";
  return (
    <>
      {may("view_as") && <ViewAs customer={customer} />}
      {!ended && may("customers_limits") && <Limits customer={customer} onChanged={onChanged} />}
      {!ended && may("customers_close") && <Close customer={customer} onChanged={onChanged} />}
    </>
  );
}

function ViewAs({ customer }: { customer: Customer }) {
  const navigate = useNavigate();
  const [failure, setFailure] = useState<string | null>(null);
  return (
    <p>
      <button type="button" className="secondary" onClick={async () => {
        try {
          await kitApi.viewAsCustomer(customer.id);
          navigate("/console");
        } catch (err) {
          setFailure(err instanceof Error ? err.message : String(err));
        }
      }}>
        {t("viewAsStart")}
      </button>{" "}
      <small className="muted">{t("viewAsHelp")}</small>
      {failure && <small className="error"> {failure}</small>}
    </p>
  );
}

function Limits({ customer, onChanged }: { customer: Customer; onChanged: (c: Customer) => void }) {
  const [calls, setCalls] = useState(customer.maxConcurrentCalls === null ? "" : String(customer.maxConcurrentCalls));
  const [cap, setCap] = useState(customer.monthlyCapUsd ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saved, setSaved] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setErrors({});
    setSaved(false);
    try {
      onChanged(await kitApi.setCustomerLimits(customer.id, {
        maxConcurrentCalls: calls.trim() === "" ? null : Number(calls),
        monthlyCapUsd: cap.trim() === "" ? null : cap.trim(),
      }));
      setSaved(true);
    } catch (err) {
      setErrors(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : { form: [err instanceof Error ? err.message : String(err)] });
    }
  }

  const said = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("limitsTitle")}>
      <h2>{t("limitsTitle")}</h2>
      <p className="muted">{t("limitsHelp")}</p>
      <div className="field">
        <label htmlFor="limit-calls">{t("limitsCalls")}</label>
        <input id="limit-calls" inputMode="numeric" value={calls} onChange={(e) => setCalls(e.target.value)} />
        {said("maxConcurrentCalls")}
      </div>
      <div className="field">
        <label htmlFor="limit-cap">{t("limitsCap")}</label>
        <input id="limit-cap" inputMode="decimal" value={cap} onChange={(e) => setCap(e.target.value)} />
        {said("monthlyCapUsd")}
      </div>
      {said("form")}
      {saved && <p role="status">{t("siteSaved")}</p>}
      <button type="submit">{t("limitsSave")}</button>
    </form>
  );
}

function Close({ customer, onChanged }: { customer: Customer; onChanged: (c: Customer) => void }) {
  const [open, setOpen] = useState(false);
  const [typed, setTyped] = useState("");
  const [failure, setFailure] = useState<string | null>(null);

  if (!open) {
    return <p><button type="button" className="secondary" onClick={() => setOpen(true)}>{t("closeCustomer")}</button></p>;
  }
  return (
    <form className="agent-form" aria-label={t("closeCustomer")} noValidate onSubmit={async (e) => {
      e.preventDefault();
      try {
        onChanged(await kitApi.closeCustomer(customer.id, typed));
      } catch (err) {
        setFailure(err instanceof Error ? err.message : String(err));
      }
    }}>
      <h2>{t("closeCustomer")}</h2>
      <p>{t("closeHelp")}</p>
      <div className="field">
        <label htmlFor="close-confirm">{t("closeConfirm", { name: customer.name })}</label>
        <input id="close-confirm" value={typed} onChange={(e) => setTyped(e.target.value)} autoComplete="off" />
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
      <div className="actions">
        <button type="submit" disabled={typed !== customer.name}>{t("closeForGood")}</button>
        <button type="button" className="secondary" onClick={() => setOpen(false)}>{t("cancel")}</button>
      </div>
    </form>
  );
}
