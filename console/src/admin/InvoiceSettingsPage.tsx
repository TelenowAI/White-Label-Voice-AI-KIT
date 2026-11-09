/**
 * Admin → Invoices (`/admin/invoices`; contract/kit-api.yaml getInvoiceSettings, setInvoiceSettings; staff with the
 * `money` ability): what customers' invoices carry — the number prefix (with an example of the numbers it gives: the
 * prefix typed, this year (UTC) and the year's first number padded to the backend's `sequenceDigits`), the
 * operator's business name (empty: the brand's name), address (several lines), tax number and email (empty: the
 * support email), and a tax: its name and rate in percent (both or neither; 0 means no tax; the amount a customer pays
 * includes it). Save sends every field, trimmed, null when empty (never `sequenceDigits`, the server's own setting);
 * the answer replaces what the fields show. The backend's 422 shows by each field it names (any other field's reasons,
 * and any other refusal, as an alert). Settings apply to the invoices issued from then on; an invoice already issued
 * never changes.
 *
 * Not here: the nav entry (AdminArea.tsx), a customer's invoices (CustomerInvoices.tsx, CustomerInvoicePage.tsx),
 * issuing them (the backend's App\Kit\Invoices\CustomerInvoices).
 */
import { useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type InvoiceSettings, type InvoiceSettingsAnswer } from "../api";
import { typedOrNull } from "../invoices/typedOrNull";
import { t, type TextKey } from "../text";

type Field = keyof InvoiceSettings;

/** Each field in the order the form shows it, its label, its id and whether it takes several lines. */
const FIELDS: { field: Field; label: TextKey; id: string; lines?: true; decimal?: true }[] = [
  { field: "prefix", label: "invoiceSettingsPrefix", id: "invoice-prefix" },
  { field: "name", label: "invoiceSettingsName", id: "invoice-name" },
  { field: "address", label: "invoiceSettingsAddress", id: "invoice-address", lines: true },
  { field: "taxNumber", label: "invoiceSettingsTaxNumber", id: "invoice-tax-number" },
  { field: "email", label: "invoiceSettingsEmail", id: "invoice-email" },
  { field: "taxName", label: "invoiceSettingsTaxName", id: "invoice-tax-name" },
  { field: "taxRate", label: "invoiceSettingsTaxRate", id: "invoice-tax-rate", decimal: true },
];

type Typed = Record<Field, string>;

function typed(s: InvoiceSettings): Typed {
  return Object.fromEntries(FIELDS.map(({ field }) => [field, s[field] ?? ""])) as Typed;
}

export function InvoiceSettingsPage() {
  const [values, setValues] = useState<Typed | null>(null);
  const [digits, setDigits] = useState(0);
  const [failure, setFailure] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [refusal, setRefusal] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    kitApi.invoiceSettings().then(shown, (err: unknown) => setFailure(err instanceof Error ? err.message : String(err)));
  }, []);

  function shown(answer: InvoiceSettingsAnswer) {
    setValues(typed(answer));
    setDigits(answer.sequenceDigits);
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!values) return;
    setBusy(true);
    setErrors({});
    setRefusal(null);
    setSaved(false);
    try {
      const sent = Object.fromEntries(FIELDS.map(({ field }) => [field, typedOrNull(values[field])])) as unknown as InvoiceSettings;
      shown(await kitApi.setInvoiceSettings(sent));
      setSaved(true);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        setErrors(err.fields);
        const elsewhere = Object.entries(err.fields).filter(([field]) => !FIELDS.some((f) => f.field === field)).flatMap(([, reasons]) => reasons);
        if (elsewhere.length > 0) setRefusal(elsewhere.join(" "));
      } else {
        setRefusal(err instanceof Error ? err.message : String(err));
      }
    } finally {
      setBusy(false);
    }
  }

  const help = (field: Field): string | null =>
    !values ? null
    : field === "prefix" ? t("invoiceSettingsPrefixExample", { example: `${values.prefix.trim()}${new Date().getUTCFullYear()}-${"1".padStart(digits, "0")}` })
    : field === "name" ? t("invoiceSettingsNameEmpty")
    : field === "email" ? t("invoiceSettingsEmailEmpty")
    : field === "taxRate" ? t("invoiceSettingsTaxHelp")
    : null;

  return (
    <section aria-labelledby="invoice-settings-title">
      <h1 id="invoice-settings-title">{t("invoiceSettingsTitle")}</h1>
      <p className="muted">{t("invoiceSettingsHelp")}</p>
      <p className="muted">{t("invoiceSettingsFromThen")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!values && !failure && <p aria-busy="true">{t("loading")}</p>}
      {values && (
        <form onSubmit={submit} noValidate aria-label={t("invoiceSettingsTitle")}>
          {FIELDS.map(({ field, label, id, lines, decimal }) => {
            const about = help(field);
            const common = {
              id,
              value: values[field],
              "aria-describedby": about ? `${id}-help` : undefined,
              "aria-invalid": errors[field] ? true : undefined,
            };
            return (
              <div className="field" key={field}>
                <label htmlFor={id}>{t(label)}</label>
                {lines ? (
                  <textarea rows={3} {...common} onChange={(e) => setValues({ ...values, [field]: e.target.value })} />
                ) : (
                  <input inputMode={decimal ? "decimal" : undefined} {...common} onChange={(e) => setValues({ ...values, [field]: e.target.value })} />
                )}
                {about && <small id={`${id}-help`} className="muted">{about}</small>}
                {errors[field] && <small className="error" data-testid={`${id}-error`}>{errors[field].join(" ")}</small>}
              </div>
            );
          })}
          <button type="submit" disabled={busy}>{busy ? t("saving") : t("invoiceSettingsSave")}</button>
          {refusal && <p role="alert" className="error">{refusal}</p>}
          {saved && <p role="status">{t("invoiceSettingsSaved")}</p>}
        </form>
      )}
    </section>
  );
}
