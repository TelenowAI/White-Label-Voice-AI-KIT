/**
 * An address as it was typed, line by line: each line break kept as a break, so a printed invoice shows the
 * street, the town and the post code where the customer or the operator put them.
 *
 * Used by InvoiceSheet.tsx and BillingDetailsFacts.tsx. Not here: typing one (BillingDetailsForm.tsx,
 * admin/InvoiceSettingsPage.tsx).
 */
import { Fragment } from "react";

export function AddressLines({ address }: { address: string }) {
  return (
    <span className="address-lines">
      {address.split(/\r?\n/).map((line, i) => (
        <Fragment key={i}>
          {i > 0 && <br />}
          {line}
        </Fragment>
      ))}
    </span>
  );
}
