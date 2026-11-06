/**
 * A password reset link's page (`/reset/:token`; contract/kit-api.yaml getPasswordReset, resetPassword): whose
 * password it sets, choosing the new one, then — signed in — on to the admin panel for the operator's staff (a user
 * with a role) or the console for everyone else. A link that no longer works says so, with the way back to signing in.
 *
 * Not here: asking for the link (SignIn.tsx).
 */
import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { KitApiError, kitApi } from "../api";
import { t } from "../text";

export function ResetPassword() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const [email, setEmail] = useState<string | null | undefined>(undefined);
  const [password, setPassword] = useState("");
  const [errors, setErrors] = useState<string[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    kitApi.passwordReset(token).then((r) => setEmail(r.email), (err) => {
      setEmail(null);
      setFailure(err instanceof Error ? err.message : String(err));
    });
  }, [token]);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors([]);
    setFailure(null);
    try {
      const { user } = await kitApi.resetPassword(token, password);
      navigate(user?.role ? "/admin" : "/console", { replace: true });
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(Object.values(err.fields).flat());
      else setFailure(err instanceof Error ? err.message : String(err));
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="reset-title">
      <h2 id="reset-title">{t("resetTitle")}</h2>
      {email === undefined && <p aria-busy="true">{t("loading")}</p>}
      {email && (
        <form onSubmit={submit} noValidate>
          <p className="muted">{t("resetHelp", { email })}</p>
          <div className="field">
            <label htmlFor="reset-password">{t("newPassword")}</label>
            <input id="reset-password" type="password" autoComplete="new-password" value={password} onChange={(e) => setPassword(e.target.value)}
              aria-invalid={errors.length > 0 || undefined} />
            {errors.length > 0 && <small className="error">{errors.join(" ")}</small>}
          </div>
          {failure && <p role="alert" className="error">{failure}</p>}
          <button type="submit" disabled={busy}>{busy ? t("saving") : t("setPassword")}</button>
        </form>
      )}
      {email === null && (
        <>
          {failure && <p role="alert" className="error">{failure}</p>}
          <p><Link to="/console">{t("backToSignIn")}</Link></p>
        </>
      )}
    </section>
  );
}
