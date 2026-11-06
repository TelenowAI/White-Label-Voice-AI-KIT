/**
 * An emailed sign-in link (`/signin/:token`; contract/kit-api.yaml useSignInLink): a button that signs the customer in
 * — a click, not the page opening, so a mail scanner that fetches the link spends nothing — then on to their console.
 * A dead link says so, with the way back to signing in.
 *
 * Not here: asking for the link (SignIn.tsx).
 */
import { useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { kitApi } from "../api";
import { t } from "../text";

export function SignInWithLink() {
  const { token = "" } = useParams();
  const navigate = useNavigate();
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function use() {
    setBusy(true);
    setFailure(null);
    try {
      await kitApi.useSignInLink(token);
      navigate("/console", { replace: true });
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="link-sign-in-title">
      <h2 id="link-sign-in-title">{t("linkSignInTitle")}</h2>
      {failure ? (
        <>
          <p role="alert" className="error">{failure}</p>
          <p><Link to="/console">{t("backToSignIn")}</Link></p>
        </>
      ) : (
        <button type="button" disabled={busy} onClick={use}>{busy ? t("signingIn") : t("signIn")}</button>
      )}
    </section>
  );
}
