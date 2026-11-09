/**
 * A number's next renewal, in its row on the Numbers page (contract/kit-api.yaml getNumberRenewals): the date it renews and, while charging is
 * on, the rent it renews for, with a gentle "soon" marker when it renews within the backend's warning days and a "not covered" one when the
 * balance does not cover it. While charging is off, the date only. Before the renewals are read (or when they cannot be), the number's own
 * renewal date; nothing for a number being released.
 *
 * Not here: the warning of what to add (RenewalWarning.tsx). Used by: NumbersPage.tsx (each number's row).
 */
import type { NumberRenewal as Renewal, PhoneNumber } from "../api";
import { t } from "../text";
import { money } from "../wallet/money";

interface Props {
  number: PhoneNumber;
  /** Its next rent as the renewals read it (none: not read, or not among them). */
  renewal: Renewal | undefined;
  /** Whether customers are charged (NumberRenewals `charging`). */
  charging: boolean;
}

export function NumberRenewal({ number: n, renewal, charging }: Props) {
  if (n.releasing) return null;
  const at = renewal?.renewsAt ?? n.nextRenewalAt;
  if (!at) return null;
  const rent = charging ? renewal?.rent : null;
  const status = charging ? renewal?.status : undefined;
  return (
    <>
      {new Date(at).toLocaleDateString()}
      {rent && <>{" "}<small className="muted">{t("numbersRenewsFor", { rent: money(rent.amount, rent.currency) })}</small></>}
      {status === "soon" && <>{" "}<span className="badge">{t("numbersRenewsSoon")}</span></>}
      {status === "short" && <>{" "}<span className="badge error">{t("numbersRenewalNotCovered")}</span></>}
    </>
  );
}
