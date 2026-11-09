/**
 * The Numbers page's warning that the balance does not cover the next rent of some numbers (contract/kit-api.yaml getNumberRenewals, its
 * `short` ones), near the top: which numbers, how much to add — the largest `shortBy` among them, as it is a running total and so covers them
 * all — that the rent is charged anyway and calls stop when the balance reaches zero, and a way to the wallet to add credit. Nothing while
 * charging is off or every rent is covered.
 *
 * Not here: each number's renewal date and rent (NumberRenewal.tsx); reading the renewals (NumbersPage.tsx). Used by: NumbersPage.tsx.
 */
import { Link } from "react-router-dom";
import type { NumberRenewal, NumberRenewals, Price } from "../api";
import { t } from "../text";
import { money, subtract } from "../wallet/money";

/** The largest shortfall of the renewals given (exact decimals, never floats); null when none has one. */
export function largestShortBy(renewals: NumberRenewal[]): Price | null {
  return renewals.reduce<Price | null>((most, r) => {
    if (!r.shortBy) return most;
    return most === null || subtract(most.amount, r.shortBy.amount).startsWith("-") ? r.shortBy : most;
  }, null);
}

export function RenewalWarning({ renewals }: { renewals: NumberRenewals }) {
  const short = renewals.charging ? renewals.numbers.filter((r) => r.status === "short") : [];
  const add = largestShortBy(short);
  if (short.length === 0 || add === null) return null;
  return (
    <section className="numbers-warning" aria-labelledby="numbers-short-title">
      <p>
        <strong id="numbers-short-title">{t("numbersShortTitle")}</strong>
      </p>
      <p>
        {t(short.length === 1 ? "numbersShortOne" : "numbersShortMany", {
          numbers: short.map((r) => r.number).join(", "),
          amount: money(add.amount, add.currency),
        })}
      </p>
      <p>
        {t("numbersShortHow")} <Link to="/console/wallet">{t("addCredit")}</Link>
      </p>
    </section>
  );
}
