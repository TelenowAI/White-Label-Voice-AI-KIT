/**
 * A form field's text as the invoice writes send it (setBillingDetails, setInvoiceSettings): trimmed, and null when
 * nothing is left, since every field is sent and empty means none.
 *
 * Used by BillingDetailsForm.tsx and admin/InvoiceSettingsPage.tsx. Not here: the calls (api.ts).
 */
export function typedOrNull(text: string): string | null {
  const trimmed = text.trim();
  return trimmed === "" ? null : trimmed;
}
