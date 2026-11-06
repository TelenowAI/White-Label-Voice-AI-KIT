/**
 * A sign-up's confirmation link (`/signup/verify/:token`; contract/kit-api.yaml getSignupVerification, verifySignup):
 * whose address it confirms, and a button that confirms it — a click, not the page opening, so a mail scanner that
 * fetches the link spends nothing. Confirmed, the person is signed in and taken to their console, or told their
 * sign-up waits for approval. A dead link says so, with the way to sign up again.
 *
 * Not here: the form (SignUp.tsx).
 */
import { useEffect, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { kitApi } from "../api";
import { t } from "../text";

export function VerifySignup() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const [signup, setSignup] = useState<{ email: string; accountName: string } | null | undefined>(undefined);
  const [waiting, setWaiting] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    kitApi.signupVerification(token).then(setSignup, (err) => {
      setSignup(null);
      setFailure(err instanceof Error ? err.message : String(err));
    });
  }, [token]);

  async function confirm() {
    setBusy(true);
    setFailure(null);
    try {
      const r = await kitApi.verifySignup(token);
      if (r.state === "active") navigate("/console", { replace: true });
      else setWaiting(r.message ?? "");
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="verify-title">
      <h2 id="verify-title">{t("verifyTitle")}</h2>
      {signup === undefined && <p aria-busy="true">{t("loading")}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      {waiting !== null && <p role="status">{waiting}</p>}
      {signup && waiting === null && (
        <>
          <p className="muted">{t("verifyHelp", { email: signup.email, account: signup.accountName })}</p>
          <button type="button" disabled={busy} onClick={confirm}>{busy ? t("saving") : t("confirmEmail")}</button>
        </>
      )}
      {signup === null && <p><Link to="/signup">{t("signUpAgain")}</Link></p>}
    </section>
  );
}
