/**
 * Admin → Staff (`/admin/staff`; contract/kit-api.yaml listStaff, inviteStaff, revokeStaffInvite, updateStaffRole,
 * removeStaff): the operator's team and what each role may do; inviting someone (the link is emailed when the platform
 * can send email, else shown once here to pass on), changing a role, cancelling an invite and removing someone (after
 * asking). The backend keeps an owner: its refusal is shown as it says. The owner's page (`staff` ability).
 *
 * Not here: joining with an invite (StaffInviteAccept.tsx), the audit log (AuditPage.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type StaffInvite, type StaffList } from "../api";
import { t } from "../text";

export function StaffPage() {
  const [staff, setStaff] = useState<StaffList | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [asking, setAsking] = useState<number | null>(null);

  const load = useCallback(async () => {
    try {
      setStaff(await kitApi.staff());
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function act(run: () => Promise<unknown>) {
    setFailure(null);
    try {
      await run();
      await load();
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }

  const roleLabel = (role: string) => staff?.roles.find((r) => r.value === role)?.label ?? role;

  return (
    <section aria-labelledby="staff-title">
      <h1 id="staff-title">{t("staffTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {staff && (
        <>
          <dl className="facts">
            {staff.roles.map((r) => <div key={r.value}><dt>{r.label}</dt><dd>{r.about}</dd></div>)}
          </dl>
          <ul className="senders" aria-label={t("staffMembers")}>
            {staff.members.map((m) => (
              <li key={m.id} data-member={m.id}>
                <div className="actions">
                  <span><strong>{m.name}</strong> · <span className="wrap-anywhere">{m.email}</span>{m.you && <> · {t("staffYou")}</>}</span>
                  <select aria-label={t("staffRoleOf", { name: m.name })} value={m.role} onChange={(e) => void act(() => kitApi.updateStaffRole(m.id, e.target.value))}>
                    {staff.roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
                  </select>
                  {asking === m.id ? (
                    <>
                      <span>{t("staffConfirmRemove", { name: m.name })}</span>
                      <button type="button" onClick={() => void act(async () => { setAsking(null); await kitApi.removeStaff(m.id); })}>{t("yesRemove")}</button>
                      <button type="button" className="secondary" onClick={() => setAsking(null)}>{t("cancel")}</button>
                    </>
                  ) : (
                    <button type="button" className="secondary" onClick={() => setAsking(m.id)}>{t("remove")}</button>
                  )}
                </div>
              </li>
            ))}
          </ul>
          {staff.invites.length > 0 && (
            <>
              <h2>{t("staffInvites")}</h2>
              <ul className="senders" aria-label={t("staffInvites")}>
                {staff.invites.map((i) => (
                  <li key={i.id} data-invite={i.id}>
                    <div className="actions">
                      <span className="wrap-anywhere">{i.email} · {roleLabel(i.role)} · {t("staffInviteUntil", { when: new Date(i.expiresAt).toLocaleString() })}</span>
                      <button type="button" className="secondary" onClick={() => void act(() => kitApi.revokeStaffInvite(i.id))}>{t("staffCancelInvite")}</button>
                    </div>
                  </li>
                ))}
              </ul>
            </>
          )}
          <InviteForm roles={staff.roles} onInvited={() => void load()} />
        </>
      )}
    </section>
  );
}

function InviteForm({ roles, onInvited }: { roles: StaffList["roles"]; onInvited: () => void }) {
  const [email, setEmail] = useState("");
  const [role, setRole] = useState(roles[roles.length - 1]?.value ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [made, setMade] = useState<{ invite: StaffInvite; token: string | null; emailed: boolean } | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      setMade(await kitApi.inviteStaff(email.trim(), role));
      setEmail("");
      onInvited();
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  const link = made?.token ? `${window.location.origin}/admin/invite/${made.token}` : null;
  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("staffInviteTitle")}>
      <h2>{t("staffInviteTitle")}</h2>
      <div className="field">
        <label htmlFor="staff-email">{t("email")}</label>
        <input id="staff-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
        {errors.email && <small className="error">{errors.email.join(" ")}</small>}
      </div>
      <div className="field">
        <label htmlFor="staff-role">{t("staffRole")}</label>
        <select id="staff-role" value={role} onChange={(e) => setRole(e.target.value)}>
          {roles.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
        </select>
        {errors.role && <small className="error">{errors.role.join(" ")}</small>}
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
      {made && (
        <div className="invite-link" role="status">
          {link ? (
            <>
              <p>{t("inviteLinkHelp", { email: made.invite.email, expires: new Date(made.invite.expiresAt).toLocaleString() })}</p>
              <input readOnly value={link} aria-label={t("staffInviteLink")} onFocus={(e) => e.target.select()} />
            </>
          ) : (
            <p>{t("inviteEmailed", { email: made.invite.email, expires: new Date(made.invite.expiresAt).toLocaleString() })}</p>
          )}
        </div>
      )}
      <button type="submit" disabled={busy}>{busy ? t("inviting") : t("staffInviteButton")}</button>
    </form>
  );
}
