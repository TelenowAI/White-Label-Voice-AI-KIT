/**
 * A team invite link (`/admin/invite/:token`; contract/kit-api.yaml getStaffInvite, acceptStaffInvite): the role it
 * gives, and joining. Someone new chooses a name and password; someone whose email already has an account enters its
 * password. Joining signs them in and opens the admin panel. A dead link, or an address that is a customer's, shows the
 * backend's message. Opens without signing in (AdminArea routes it before its sign-in).
 */
import { useEffect, useState, type FormEvent } from "react";
import { useParams } from "react-router-dom";
import { KitApiError, kitApi, type StaffInviteInfo, type User } from "../api";
import { t } from "../text";

export function StaffInviteAccept({ onJoined }: { onJoined: (user: User | null) => void }) {
  const { token = "" } = useParams();
  const [info, setInfo] = useState<StaffInviteInfo | null>(null);
  const [dead, setDead] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    kitApi.staffInvite(token).then(setInfo, (err: unknown) => setDead(err instanceof Error ? err.message : String(err)));
  }, [token]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const joined = await kitApi.acceptStaffInvite(token, info?.accountExists ? { password } : { name, password });
      onJoined(joined.user);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length) setErrors(err.fields);
      else setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  if (dead) return <p role="alert" className="error">{dead}</p>;
  if (!info) return <p aria-busy="true">{t("loading")}</p>;
  const said = (field: string) => (errors[field] ? <small className="error">{errors[field].join(" ")}</small> : null);

  return (
    <section className="step" aria-labelledby="staff-join-title">
      <h1 id="staff-join-title">{t("staffJoinTitle", { role: info.roleLabel })}</h1>
      <p className="muted">{t(info.accountExists ? "joinHelpExisting" : "joinHelpNew", { email: info.email })}</p>
      <form onSubmit={submit} noValidate aria-label={t("staffJoinTitle", { role: info.roleLabel })}>
        {!info.accountExists && (
          <div className="field">
            <label htmlFor="staff-join-name">{t("yourName")}</label>
            <input id="staff-join-name" value={name} autoComplete="name" onChange={(e) => setName(e.target.value)} />
            {said("name")}
          </div>
        )}
        <div className="field">
          <label htmlFor="staff-join-password">{t("password")}</label>
          <input id="staff-join-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)}
            autoComplete={info.accountExists ? "current-password" : "new-password"} />
          {said("password")}
        </div>
        {failure && <p role="alert" className="error">{failure}</p>}
        <button type="submit" disabled={busy}>{busy ? t("joining") : t("join")}</button>
      </form>
    </section>
  );
}
