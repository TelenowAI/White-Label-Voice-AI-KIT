/**
 * Admin → one customer (`/admin/customers/:id`): its name and status, what staff may do to it (CustomerActions:
 * limits, looking at its console, closing), its wallet and plan (CustomerWallet), and its team (TeamPanel with the
 * admin's calls). Here the operator invites an account's first user and credits it.
 *
 * Not here: the list (Customers.tsx).
 */
import { useEffect, useMemo, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { kitApi, type Customer } from "../api";
import { TeamPanel, type TeamCalls } from "../team/TeamPanel";
import { CustomerActions } from "./CustomerActions";
import { CustomerWallet } from "./CustomerWallet";
import { useMay } from "./may";
import { t, type TextKey } from "../text";

export const STATUS: Record<Customer["status"], TextKey> = {
  active: "statusActive",
  suspended: "statusSuspended",
  closing: "statusClosing",
  closed: "statusClosed",
};

export function CustomerPage() {
  const { id = "" } = useParams();
  const [customer, setCustomer] = useState<Customer | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const may = useMay();

  useEffect(() => {
    kitApi.customer(id).then(setCustomer, (err: unknown) => setFailure(err instanceof Error ? err.message : String(err)));
  }, [id]);

  const calls = useMemo<TeamCalls>(
    () => ({
      load: () => kitApi.customerTeam(id),
      invite: (email, role) => kitApi.inviteCustomerMember(id, email, role),
      revoke: (inviteId) => kitApi.revokeCustomerInvite(id, inviteId),
      remove: (userId) => kitApi.removeCustomerMember(id, userId),
    }),
    [id],
  );

  return (
    <section aria-labelledby="customer-title">
      <p>
        <Link to="/admin/customers">{t("backToCustomers")}</Link>
      </p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {customer && (
        <>
          <h1 id="customer-title">{customer.name}</h1>
          <p className="muted">
            {t(STATUS[customer.status])} ·{" "}
            {t(customer.syncState === "synced" ? "syncSynced" : customer.syncState === "pending" ? "syncPending" : "syncFailed")}
          </p>
          {customer.syncError && <p className="muted" data-testid="sync-error">{customer.syncError}</p>}
          <CustomerActions customer={customer} may={may} onChanged={setCustomer} />
          <CustomerWallet customerId={customer.id} />
          <TeamPanel calls={calls} />
        </>
      )}
    </section>
  );
}
