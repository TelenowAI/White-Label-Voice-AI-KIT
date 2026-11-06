/**
 * An invite link (`/console/invite/:token`, contract/kit-api.yaml getInvite, acceptInvite): which account it is for,
 * and joining it. Someone new chooses a name, and a password while customers may use one. Someone whose email already
 * has an account signs in to it here, any way they can (setup/SignIn.tsx), and then joins: the link alone never joins an
 * account that exists. Signed in as someone else, they are told so and can sign out. Joining signs them in and opens
 * the console. A dead link shows the backend's message.
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { KitApiError, kitApi, type InviteInfo } from "../api";
import { SignIn } from "../setup/SignIn";
import { t } from "../text";

export function InviteAccept({ onJoined }: { onJoined: () => void }) {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const [info, setInfo] = useState<InviteInfo | null>(null);
  const [dead, setDead] = useState<string | null>(null);
  const [name, setName] = useState("");
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(
    () => kitApi.invite(token).then(setInfo, (err: unknown) => setDead(err instanceof Error ? err.message : String(err))),
    [token],
  );

  useEffect(() => {
    void load();
  }, [load]);

  /** Who is signed in here changed: the session, and what this invite asks of them. */
  function changedWho() {
    onJoined();
    void load();
  }

  async function join(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      await kitApi.acceptInvite(token, info?.canJoinNow ? {} : info?.choosesPassword ? { name, password } : { name });
      onJoined();
      navigate("/console", { replace: true });
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length) {
        setErrors(err.fields);
        // Signed out meanwhile: what this page asks for has changed.
        if (info?.canJoinNow) changedWho();
      } else setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  async function signOut() {
    setBusy(true);
    setFailure(null);
    try {
      await kitApi.signOut();
      changedWho();
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  if (dead) return <p role="alert" className="error">{dead}</p>;
  if (!info) return <p aria-busy="true">{t("loading")}</p>;

  const fieldError = (field: string) =>
    errors[field] ? <small id={`join-${field}-error`} className="error">{errors[field].join(" ")}</small> : null;
  const failed = failure && <p role="alert" className="error">{failure}</p>;
  const title = <h1 id="join-title">{t("joinTitle", { name: info.customerName })}</h1>;

  if (info.canJoinNow) {
    return (
      <section className="step" aria-labelledby="join-title">
        {title}
        <p className="muted">{t("joinSignedIn", { email: info.email })}</p>
        <form onSubmit={join} noValidate>
          {failed}
          <button type="submit" disabled={busy}>{busy ? t("joining") : t("join")}</button>
        </form>
      </section>
    );
  }

  if (info.accountExists) {
    return (
      <section className="step" aria-labelledby="join-title">
        {title}
        {info.signedInAs !== null ? (
          <>
            <p className="muted">{t("joinWrongPerson", { who: info.signedInAs, email: info.email })}</p>
            {failed}
            <button type="button" className="secondary" disabled={busy} onClick={() => void signOut()}>{t("signOut")}</button>
          </>
        ) : (
          <SignIn help={t("joinHelpExisting", { email: info.email })} offer={["reset", "link", "code", "sso"]}
            leavingNote={t("joinAfterLeaving")} onSignedIn={changedWho} />
        )}
      </section>
    );
  }

  return (
    <section className="step" aria-labelledby="join-title">
      {title}
      <p className="muted">{t(info.choosesPassword ? "joinHelpNew" : "joinHelpNewNoPassword", { email: info.email })}</p>
      <form onSubmit={join} noValidate>
        <div className="field">
          <label htmlFor="join-name">{t("yourName")}</label>
          <input id="join-name" value={name} autoComplete="name" onChange={(e) => setName(e.target.value)}
            aria-invalid={errors.name ? true : undefined} aria-describedby={errors.name ? "join-name-error" : undefined} />
          {fieldError("name")}
        </div>
        {info.choosesPassword && (
          <div className="field">
            <label htmlFor="join-password">{t("password")}</label>
            <input id="join-password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} autoComplete="new-password"
              aria-invalid={errors.password ? true : undefined} aria-describedby={errors.password ? "join-password-error" : undefined} />
            {fieldError("password")}
          </div>
        )}
        {failed}
        <button type="submit" disabled={busy}>{busy ? t("joining") : t("join")}</button>
      </form>
    </section>
  );
}
