/**
 * Sending the payer to a gateway's checkout (contract/kit-api.yaml Checkout): `redirect` opens its page; `post` sends
 * its fields there as a form, the way such gateways take an order. `instructions` (a bank transfer) has no page: the
 * caller shows them. The browser's own navigation is passed in, so tests can watch it.
 * Used by wallet/AddCredit.tsx and admin/PaymentsPage.tsx (a staff test payment).
 */
import type { Checkout } from "../api";

export interface Navigation {
  assign: (url: string) => void;
  post: (url: string, fields: Record<string, string>) => void;
}

export const browserNavigation: Navigation = {
  assign: (url) => window.location.assign(url),
  post: (url, fields) => {
    const form = document.createElement("form");
    form.method = "POST";
    form.action = url;
    for (const [name, value] of Object.entries(fields)) {
      const input = document.createElement("input");
      input.type = "hidden";
      input.name = name;
      input.value = value;
      form.appendChild(input);
    }
    document.body.appendChild(form);
    form.submit();
  },
};

/** Go to the checkout; answers false for `instructions`, which have no page to go to. */
export function goToCheckout(checkout: Checkout, nav: Navigation = browserNavigation): boolean {
  if (checkout.kind === "redirect" && checkout.url) {
    nav.assign(checkout.url);
    return true;
  }
  if (checkout.kind === "post" && checkout.url) {
    nav.post(checkout.url, checkout.fields);
    return true;
  }
  return false;
}
