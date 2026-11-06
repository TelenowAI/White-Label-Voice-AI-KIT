/**
 * Admin → Pricing (`/admin/pricing`; contract/kit-api.yaml getPricing, updatePricing, savePriceList, deletePriceList,
 * setLowBalanceDefault): the currency the operator bills in, how much of it one US dollar is, and the default plan —
 * charging is on once all three are set — the balance customers get a low-balance email at unless they chose their
 * own, and the price lists, one per plan, each edited as JSON in the pricing spec's format (contract/pricing/README.md)
 * with the backend's own words for what is wrong with it.
 *
 * Not here: a customer's plan and wallet (CustomerWallet.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type PriceListRow, type Pricing as PricingState } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** A list to start from: every call component and every fee at a markup, in the operator's currency. */
function starter(id: string, currency: string | null) {
  return {
    id, currency: currency ?? "USD", decimals: 2, rounding: "half-up", billingIncrementSeconds: 60,
    rules: [
      { id: "calls", match: { item: "component" }, price: { markupPercent: "40" } },
      { id: "fees", match: { item: "fee" }, price: { markupPercent: "40" } },
    ],
  };
}

export function Pricing() {
  const [data, setData] = useState<PricingState | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [editing, setEditing] = useState<PriceListRow | "new" | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await kitApi.pricing());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="pricing-title">
      <h1 id="pricing-title">{t("pricingTitle")}</h1>
      <p className="muted">{t("pricingHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && (
        <>
          <p role="status" className={data.inForce ? "sync-synced" : "sync-pending"}>
            {t(data.inForce ? "pricingInForce" : "pricingNotInForce")}
          </p>
          <PricingSettings key={`${data.currency}-${data.usdRate}-${data.defaultPlan}`} data={data} onSaved={setData} />
          <LowBalanceDefault data={data} onSaved={setData} />
          <h2>{t("priceListsTitle")}</h2>
          <ul className="price-lists">
            {data.lists.map((l) => (
              <li key={l.id}>
                <strong>{l.name}</strong> <code>{l.id}</code> <small className="muted">{t("listCustomers", { n: String(l.customers) })}</small>{" "}
                <button type="button" className="secondary" onClick={() => setEditing(l)}>{t("edit")}</button>{" "}
                <DeleteList id={l.id} onDeleted={load} onError={setFailure} />
              </li>
            ))}
          </ul>
          {editing === null ? (
            <button type="button" onClick={() => setEditing("new")}>{t("newPriceList")}</button>
          ) : (
            <ListEditor
              key={editing === "new" ? "new" : editing.id}
              list={editing === "new" ? null : editing}
              currency={data.currency}
              onSaved={async () => {
                setEditing(null);
                await load();
              }}
              onCancel={() => setEditing(null)}
            />
          )}
        </>
      )}
    </section>
  );
}

function PricingSettings({ data, onSaved }: { data: PricingState; onSaved: (p: PricingState) => void }) {
  const [currency, setCurrency] = useState(data.currency ?? "");
  const [usdRate, setUsdRate] = useState(data.usdRate ?? "");
  const [defaultPlan, setDefaultPlan] = useState(data.defaultPlan ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      onSaved(await kitApi.updatePricing({ currency, usdRate, defaultPlan: defaultPlan || null }));
    } catch (err) {
      setErrors(err instanceof KitApiError ? err.fields : { currency: [message(err)] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate>
      <div className="field field-narrow">
        <label htmlFor="pricing-currency">{t("currency")}</label>
        <input id="pricing-currency" value={currency} onChange={(e) => setCurrency(e.target.value.toUpperCase())} />
        {errors.currency && <small className="error">{errors.currency.join(" ")}</small>}
      </div>
      <div className="field field-narrow">
        <label htmlFor="pricing-rate">{t("usdRate")}</label>
        <input id="pricing-rate" inputMode="decimal" value={usdRate} onChange={(e) => setUsdRate(e.target.value)} />
        {errors.usdRate && <small className="error">{errors.usdRate.join(" ")}</small>}
      </div>
      <div className="field field-narrow">
        <label htmlFor="pricing-default">{t("defaultPlan")}</label>
        <select id="pricing-default" value={defaultPlan} onChange={(e) => setDefaultPlan(e.target.value)}>
          <option value="">{t("noPlan")}</option>
          {data.lists.map((l) => (
            <option key={l.id} value={l.id}>{l.name}</option>
          ))}
        </select>
        {errors.defaultPlan && <small className="error">{errors.defaultPlan.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("savePricing")}</button>
    </form>
  );
}

/** The balance customers get a low-balance email at unless they chose their own; empty for none. */
function LowBalanceDefault({ data, onSaved }: { data: PricingState; onSaved: (p: PricingState) => void }) {
  const [level, setLevel] = useState(data.lowBalanceAt ?? "");
  const [errors, setErrors] = useState<string[]>([]);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors([]);
    setSaved(false);
    try {
      onSaved(await kitApi.setLowBalanceDefault(level.trim() === "" ? null : level.trim()));
      setSaved(true);
    } catch (err) {
      setErrors(err instanceof KitApiError && err.fields.lowBalanceAt ? err.fields.lowBalanceAt : [message(err)]);
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={submit} noValidate aria-labelledby="pricing-low-balance-title">
      <h2 id="pricing-low-balance-title">{t("lowBalanceTitle")}</h2>
      <div className="field field-narrow">
        <label htmlFor="pricing-low-balance">{data.currency ? t("lowBalanceDefault", { currency: data.currency }) : t("lowBalanceDefaultNoCurrency")}</label>
        <input id="pricing-low-balance" inputMode="decimal" value={level} onChange={(e) => setLevel(e.target.value)} aria-describedby="pricing-low-balance-help" />
        <small id="pricing-low-balance-help" className="muted">{t("lowBalanceDefaultHelp")}</small>
        {errors.length > 0 && <small className="error">{errors.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("lowBalanceSave")}</button>
      {saved && <p role="status">{t("siteSaved")}</p>}
    </form>
  );
}

function ListEditor({ list, currency, onSaved, onCancel }: { list: PriceListRow | null; currency: string | null; onSaved: () => void; onCancel: () => void }) {
  const [id, setId] = useState(list?.id ?? "standard");
  const [name, setName] = useState(list?.name ?? "");
  const [json, setJson] = useState(JSON.stringify(list?.definition ?? starter(list?.id ?? "standard", currency), null, 2));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    let definition: unknown;
    try {
      definition = JSON.parse(json);
    } catch {
      setErrors({ definition: [t("notJson")] });
      return;
    }
    setBusy(true);
    setErrors({});
    try {
      await kitApi.savePriceList(id, name, definition);
      onSaved();
    } catch (err) {
      setErrors(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : { definition: [message(err)] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={list ? list.name : t("newPriceList")}>
      {!list && (
        <div className="field">
          <label htmlFor="list-id">{t("listId")}</label>
          <input id="list-id" value={id} onChange={(e) => setId(e.target.value)} />
        </div>
      )}
      <div className="field">
        <label htmlFor="list-name">{t("listName")}</label>
        <input id="list-name" value={name} onChange={(e) => setName(e.target.value)} />
        {errors.name && <small className="error">{errors.name.join(" ")}</small>}
      </div>
      <div className="field">
        <label htmlFor="list-definition">{t("listDefinition")}</label>
        <textarea id="list-definition" rows={16} spellCheck={false} className="code" value={json} onChange={(e) => setJson(e.target.value)} />
        {errors.definition && (
          <ul className="error" aria-label={t("listDefinition")}>
            {errors.definition.map((p, i) => (
              <li key={i}>{p}</li>
            ))}
          </ul>
        )}
      </div>
      <div className="actions">
        <button type="submit" disabled={busy}>{busy ? t("saving") : t("saveList")}</button>
        <button type="button" className="secondary" onClick={onCancel}>{t("cancel")}</button>
      </div>
    </form>
  );
}

function DeleteList({ id, onDeleted, onError }: { id: string; onDeleted: () => void; onError: (m: string) => void }) {
  const [asking, setAsking] = useState(false);
  if (!asking) {
    return <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("deleteList")}</button>;
  }
  return (
    <>
      <button
        type="button"
        onClick={async () => {
          try {
            await kitApi.deletePriceList(id);
            onDeleted();
          } catch (err) {
            onError(message(err));
          } finally {
            setAsking(false);
          }
        }}
      >
        {t("confirmDeleteList", { id })}
      </button>{" "}
      <button type="button" className="secondary" onClick={() => setAsking(false)}>{t("cancel")}</button>
    </>
  );
}
