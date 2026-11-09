/**
 * Console → Invoices → changing the account's billing details (contract/kit-api.yaml setBillingDetails; its owners
 * and admins, `canEdit`): business name (empty: the account's name is printed), address (several lines) and tax number.
 * Save sends all three, each trimmed, null when empty; the answer replaces what the fields show and goes to `onSaved`.
 * The backend's 422 shows by each field it names (any other field's reasons, and any other refusal such as a 403 for a
 * member who may not, as an alert). What is saved appears on the invoices issued from then on.
 *
 * Used by InvoicesPage.tsx. Not here: reading them (BillingDetailsFacts.tsx for members who may not change them).
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type AccountBillingDetails, type BillingDetails } from "../api";
import { t } from "../text";
import { typedOrNull } from "./typedOrNull";

const FIELDS = ["name", "address", "taxNumber"] as const;

export function BillingDetailsForm({ details, onSaved }: { details: BillingDetails; onSaved: (saved: AccountBillingDetails) => void }) {
  const [name, setName] = useState(details.name ?? "");
  const [address, setAddress] = useState(details.address ?? "");
  const [taxNumber, setTaxNumber] = useState(details.taxNumber ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [refusal, setRefusal] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setRefusal(null);
    setSaved(false);
    try {
      const answer = await kitApi.setBillingDetails({ name: typedOrNull(name), address: typedOrNull(address), taxNumber: typedOrNull(taxNumber) });
      setName(answer.name ?? "");
      setAddress(answer.address ?? "");
      setTaxNumber(answer.taxNumber ?? "");
      onSaved(answer);
      setSaved(true);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        setErrors(err.fields);
        const elsewhere = Object.entries(err.fields).filter(([field]) => !(FIELDS as readonly string[]).includes(field)).flatMap(([, reasons]) => reasons);
        if (elsewhere.length > 0) setRefusal(elsewhere.join(" "));
      } else {
        setRefusal(err instanceof Error ? err.message : String(err));
      }
    } finally {
      setBusy(false);
    }
  }

  const refused = (field: (typeof FIELDS)[number]) =>
    errors[field] ? <small className="error" data-testid={`billing-${field}-error`}>{errors[field].join(" ")}</small> : null;

  return (
    <form onSubmit={submit} noValidate aria-label={t("billingDetailsTitle")}>
      <div className="field">
        <label htmlFor="billing-name">{t("billingDetailsName")}</label>
        <input id="billing-name" value={name} onChange={(e) => setName(e.target.value)} aria-describedby="billing-name-help" aria-invalid={errors.name ? true : undefined} />
        <small id="billing-name-help" className="muted">{t("billingDetailsNameEmpty")}</small>
        {refused("name")}
      </div>
      <div className="field">
        <label htmlFor="billing-address">{t("billingDetailsAddress")}</label>
        <textarea id="billing-address" rows={3} value={address} onChange={(e) => setAddress(e.target.value)} aria-invalid={errors.address ? true : undefined} />
        {refused("address")}
      </div>
      <div className="field">
        <label htmlFor="billing-tax-number">{t("billingDetailsTaxNumber")}</label>
        <input id="billing-tax-number" value={taxNumber} onChange={(e) => setTaxNumber(e.target.value)} aria-invalid={errors.taxNumber ? true : undefined} />
        {refused("taxNumber")}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("billingDetailsSave")}</button>
      {refusal && <p role="alert" className="error">{refusal}</p>}
      {saved && <p role="status">{t("billingDetailsSaved")}</p>}
    </form>
  );
}
