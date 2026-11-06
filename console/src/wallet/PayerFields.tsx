/**
 * What a gateway needs of the payer that their account lacks (`asks`, contract/kit-api.yaml TopUp): an email, asked
 * for just this payment and not kept. Used by wallet/AddCredit.tsx and admin/PaymentsPage.tsx (a staff test payment).
 *
 * Not here: which gateway needs what (the backend says), the rest of the form.
 */
import type { PayerDetails, PayerField } from "../api";
import { t, type TextKey } from "../text";

const fields: Record<PayerField, { label: TextKey; type: string; autoComplete: string }> = {
  email: { label: "payerEmail", type: "email", autoComplete: "email" },
};

/** Only what this gateway asks, trimmed: a detail typed for another gateway is not sent. */
export function payerGiven(asks: PayerField[], payer: PayerDetails): PayerDetails | undefined {
  if (asks.length === 0) return undefined;
  return Object.fromEntries(asks.map((f) => [f, (payer[f] ?? "").trim()]));
}

export function PayerFields({ asks, payer, errors, onChange }: { asks: PayerField[]; payer: PayerDetails; errors: Record<string, string[]>; onChange: (next: PayerDetails) => void }) {
  if (asks.length === 0) return null;

  return (
    <>
      <p className="muted">{t("payerAsks")}</p>
      {asks.map((f) => (
        <div className="field" key={f}>
          <label htmlFor={`payer-${f}`}>{t(fields[f].label)}</label>
          <input id={`payer-${f}`} type={fields[f].type} autoComplete={fields[f].autoComplete} value={payer[f] ?? ""}
            onChange={(e) => onChange({ ...payer, [f]: e.target.value })} aria-invalid={errors[`payer.${f}`] ? true : undefined} />
          {errors[`payer.${f}`] && <small className="error">{errors[`payer.${f}`].join(" ")}</small>}
        </div>
      ))}
    </>
  );
}
