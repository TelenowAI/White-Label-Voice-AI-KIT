/**
 * A customer account's team: its members, the invites waiting to be accepted, and — for whoever may manage it
 * (`canManage`) — inviting, withdrawing an invite and removing a member. The same panel serves admin (any account,
 * admin/CustomerPage.tsx) and the console (the current account, console/ConsoleArea.tsx); each passes its own calls.
 *
 * A new invite is emailed when the platform can send email (the backend says `emailed`, and answers no token);
 * otherwise its link is shown once, built from this page's own origin and the token the backend answered. The backend
 * keeps only its hash. Roles come from the backend (`roles`), never a list here.
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, type InviteCreated, type Team } from "../api";
import { t } from "../text";

export interface TeamCalls {
  load: () => Promise<Team>;
  invite: (email: string, role: string) => Promise<InviteCreated>;
  revoke: (inviteId: string) => Promise<void>;
  remove: (userId: number) => Promise<void>;
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function TeamPanel({ calls }: { calls: TeamCalls }) {
  const [team, setTeam] = useState<Team | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [made, setMade] = useState<InviteCreated | null>(null);

  const load = useCallback(async () => {
    try {
      setTeam(await calls.load());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, [calls]);

  useEffect(() => {
    void load();
  }, [load]);

  const act = (run: () => Promise<void>) => async () => {
    try {
      await run();
      await load();
    } catch (err) {
      setFailure(message(err));
    }
  };

  return (
    <section aria-labelledby="team-title" className="team">
      <h2 id="team-title">{t("teamTitle")}</h2>
      {failure && <p role="alert" className="error">{failure}</p>}
      {team && (
        <>
          {team.members.length === 0 ? (
            <p className="muted">{t("teamEmpty")}</p>
          ) : (
            <div className="table-scroll">
              <table className="list">
                <thead>
                  <tr>
                    <th scope="col">{t("colName")}</th>
                    <th scope="col">{t("colEmail")}</th>
                    <th scope="col">{t("colRole")}</th>
                    <th scope="col">{t("colJoined")}</th>
                    {team.canManage && <th scope="col">{t("colActions")}</th>}
                  </tr>
                </thead>
                <tbody>
                  {team.members.map((m) => (
                    <tr key={m.userId}>
                      <td>{m.name}</td>
                      <td>{m.email ?? <span className="muted">{t("signedUpByPhone")}</span>}</td>
                      <td>{m.role}</td>
                      <td>{new Date(m.joinedAt).toLocaleDateString()}</td>
                      {team.canManage && (
                        <td>
                          <button type="button" className="secondary" onClick={act(() => calls.remove(m.userId))}>
                            {t("remove")}
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {team.invites.length > 0 && (
            <>
              <h3>{t("invitesTitle")}</h3>
              <div className="table-scroll">
                <table className="list">
                  <thead>
                    <tr>
                      <th scope="col">{t("colEmail")}</th>
                      <th scope="col">{t("colRole")}</th>
                      <th scope="col">{t("colExpires")}</th>
                      {team.canManage && <th scope="col">{t("colActions")}</th>}
                    </tr>
                  </thead>
                  <tbody>
                    {team.invites.map((i) => (
                      <tr key={i.id}>
                        <td>{i.email}</td>
                        <td>{i.role}</td>
                        <td>{new Date(i.expiresAt).toLocaleString()}</td>
                        {team.canManage && (
                          <td>
                            <button type="button" className="secondary" onClick={act(() => calls.revoke(i.id))}>
                              {t("withdraw")}
                            </button>
                          </td>
                        )}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </>
          )}

          {team.canManage && (
            <InviteForm
              roles={team.roles}
              invite={calls.invite}
              onMade={async (m) => {
                setMade(m);
                await load();
              }}
            />
          )}
          {made && <InviteLink made={made} />}
        </>
      )}
    </section>
  );
}

function InviteForm({ roles, invite, onMade }: { roles: string[]; invite: TeamCalls["invite"]; onMade: (m: InviteCreated) => void }) {
  const [email, setEmail] = useState("");
  const [role, setRole] = useState(roles[0] ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      onMade(await invite(email, role));
      setEmail("");
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate aria-labelledby="invite-title">
      <h3 id="invite-title" className="form-title">{t("inviteTitle")}</h3>
      <div className="field">
        <label htmlFor="invite-email">{t("inviteEmail")}</label>
        <input id="invite-email" type="email" value={email} onChange={(e) => setEmail(e.target.value)}
          aria-invalid={errors.email ? true : undefined} aria-describedby={errors.email ? "invite-email-error" : undefined} />
        {errors.email && <small id="invite-email-error" className="error">{errors.email.join(" ")}</small>}
      </div>
      <div className="field field-narrow">
        <label htmlFor="invite-role">{t("inviteRole")}</label>
        <select id="invite-role" value={role} onChange={(e) => setRole(e.target.value)}>
          {roles.map((r) => (
            <option key={r} value={r}>
              {r}
            </option>
          ))}
        </select>
      </div>
      <button type="submit" disabled={busy}>{busy ? t("inviting") : t("sendInvite")}</button>
      {failure && <p role="alert" className="error">{failure}</p>}
    </form>
  );
}

function InviteLink({ made }: { made: InviteCreated }) {
  const link = `${window.location.origin}/console/invite/${made.token}`;
  const [copied, setCopied] = useState(false);

  if (made.emailed || made.token === null) {
    return (
      <p className="invite-link" role="status">
        {t("inviteEmailed", { email: made.invite.email, expires: new Date(made.invite.expiresAt).toLocaleString() })}
      </p>
    );
  }
  return (
    <div className="invite-link" role="status">
      <p>{t("inviteLinkHelp", { email: made.invite.email, expires: new Date(made.invite.expiresAt).toLocaleString() })}</p>
      <div className="input-row">
        <input readOnly value={link} aria-label={t("inviteTitle")} onFocus={(e) => e.target.select()} />
        <button
          type="button"
          onClick={async () => {
            await navigator.clipboard?.writeText(link);
            setCopied(true);
          }}
        >
          {copied ? t("copied") : t("copyLink")}
        </button>
      </div>
    </div>
  );
}
