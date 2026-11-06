/**
 * The sign-in form (contract/kit-api.yaml signIn). Used by the setup wizard when an owner step comes up and the
 * visitor is not the signed-in owner, and by the admin panel and the console (`help` says whose it is). The backend's
 * refusal is shown as it comes: it never says whether the email or the password was wrong.
 *
 * Beside it, each when the brand says so and the page offers it (`offer`): "Forgot your password?" (forgotPassword),
 * "Email me a sign-in link" (requestSignInLink), an emailed code and a code to their phone (SignInWithCode.tsx), "Continue with <provider>"
 * (startSso, a page load to the provider) and "Create an account" (the sign-up page). The console offers them all; the
 * admin panel only the reset (links, codes, providers and sign-ups are customers'); the wizard none (nothing is
 * installed yet). Asking by email shows the backend's answer, which is the same whether or not an account uses the
 * address. Each form the operator guards shows the bot check (Captcha.tsx) and sends its token.
 *
 * With the operator's passwords off and another way in to offer, the console shows no password box: the backend would refuse
 * a customer's password anyway. The admin panel and the wizard always show it, because staff always use theirs.
 *
 * Back from a provider that did not sign the person in (`?signInError=<reason>`), the page says why in its own words —
 * only for a reason it knows, so a link cannot make it say anything else.
 *
 * Not here: choosing the new password (ResetPassword.tsx), signing up (SignUp.tsx), using a link (SignInWithLink.tsx),
 * a one-time code (SignInWithCode.tsx).
 */
import { useEffect, useState, type FormEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { KitApiError, kitApi, ssoStartUrl, type Brand, type BrandCaptcha, type CaptchaForm, type User } from "../api";
import { t, type TextKey } from "../text";
import { Captcha } from "./Captcha";
import { SignInWithCode, codeWords, type CodeWay } from "./SignInWithCode";

type Asking = {
  title: TextKey; help: TextKey; button: TextKey; form: CaptchaForm;
  send: (email: string, captchaToken: string | null) => Promise<{ message: string }>;
};

const asks: Record<"reset" | "link", Asking> = {
  reset: { title: "forgotTitle", help: "forgotHelp", button: "sendResetLink", form: "password_reset", send: (email, token) => kitApi.forgotPassword(email, token) },
  link: { title: "linkTitle", help: "linkHelp", button: "sendSignInLink", form: "sign_in_link", send: (email, token) => kitApi.requestSignInLink(email, token) },
};

/** Why a provider did not sign the person in (finishSso), in the console's words. */
const ssoReasons: Record<string, TextKey> = {
  expired: "ssoExpired", cancelled: "ssoCancelled", unavailable: "ssoUnavailable", failed: "ssoFailed",
  not_verified: "ssoNotVerified", no_account: "ssoNoAccount", not_allowed: "ssoNotAllowed", staff: "ssoStaff",
};

type Offer = "reset" | "link" | "code" | "sso" | "signUp";

export function SignIn({ onSignedIn, help = t("signInHelp"), offer = ["reset", "link", "code", "sso", "signUp"], leavingNote, go = (url) => window.location.assign(url) }: {
  onSignedIn: (user: User) => void; help?: string; offer?: Offer[];
  /** Said when a way on this page ends somewhere else, for a page that asks people to sign in and carry on there. */
  leavingNote?: string;
  go?: (url: string) => void;
}) {
  const [brand, setBrand] = useState<Brand | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [resetKey, setResetKey] = useState(0);
  const [params] = useSearchParams();
  const reason = ssoReasons[params.get("signInError") ?? ""];
  const [asking, setAsking] = useState<keyof typeof asks | null>(null);
  const [byCode, setByCode] = useState<CodeWay | null>(null);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const offers = offer.join();
  useEffect(() => {
    if (offers !== "") kitApi.brand().then(setBrand, () => undefined);
  }, [offers]);
  const shows = {
    reset: !!brand?.passwordReset && offer.includes("reset"),
    link: !!brand?.signInLink && offer.includes("link"),
    code: !!brand?.signInCode && offer.includes("code"),
    emailCode: !!brand?.signInEmailCode && offer.includes("code"),
    sso: (brand?.signInWith.length ?? 0) > 0 && offer.includes("sso"),
    signUp: !!brand?.signUp && offer.includes("signUp"),
  };

  const phoneWay = brand?.signInCodeChannel ?? "sms";
  const passwordOff = !!brand && !brand.signInPassword && offer.includes("code") && (shows.link || shows.emailCode || shows.code || shows.sso);
  if (asking) return <AskByEmail asking={asks[asking]} email={email} captcha={brand?.captcha} onBack={() => setAsking(null)} />;
  if (byCode) return <SignInWithCode way={byCode} captcha={brand?.captcha} onSignedIn={onSignedIn} onBack={() => setByCode(null)} />;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setMessage(null);
    try {
      const { user } = await kitApi.signIn(email, password, token);
      if (user) onSignedIn(user);
    } catch (err) {
      const fields = err instanceof KitApiError ? Object.values(err.fields).flat() : [];
      setMessage(fields.length ? fields.join(" ") : err instanceof Error ? err.message : String(err));
      setResetKey((k) => k + 1); // a bot check's token works once
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="sign-in-title">
      <h2 id="sign-in-title">{t("signInTitle")}</h2>
      <p className="muted">{help}</p>
      {reason && <p role="alert" className="error">{t(reason)}</p>}
      {shows.sso && (
        <p className="actions">
          {brand!.signInWith.map((p) => (
            <button key={p.id} type="button" className="secondary" onClick={() => go(ssoStartUrl(p.id))}>{t("continueWith", { name: p.name })}</button>
          ))}
        </p>
      )}
      {!passwordOff && <form onSubmit={submit} noValidate>
        <div className="field">
          <label htmlFor="sign-in-email">{t("email")}</label>
          <input id="sign-in-email" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} />
        </div>
        <div className="field">
          <label htmlFor="sign-in-password">{t("password")}</label>
          <input
            id="sign-in-password"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
        </div>
        <Captcha captcha={brand?.captcha} form="sign_in" onToken={setToken} resetKey={resetKey} />
        {message && <p role="alert" className="error">{message}</p>}
        <button type="submit" disabled={busy}>
          {busy ? t("signingIn") : t("signIn")}
        </button>
      </form>}
      {leavingNote && ((shows.reset && !passwordOff) || shows.link || shows.sso) && <p className="muted">{leavingNote}</p>}
      {(shows.reset || shows.link || shows.emailCode || shows.code || shows.signUp) && (
        <p className="actions">
          {shows.reset && !passwordOff && <button type="button" className="link" onClick={() => setAsking("reset")}>{t("forgotPassword")}</button>}
          {shows.link && <button type="button" className="link" onClick={() => setAsking("link")}>{t("emailSignInLink")}</button>}
          {shows.emailCode && <button type="button" className="link" onClick={() => setByCode("email")}>{t(codeWords.email.link)}</button>}
          {shows.code && <button type="button" className="link" onClick={() => setByCode(phoneWay)}>{t(codeWords[phoneWay].link)}</button>}
          {shows.signUp && <Link to="/signup">{t("createAccount")}</Link>}
        </p>
      )}
    </section>
  );
}

function AskByEmail({ asking, email: start, captcha, onBack }: { asking: Asking; email: string; captcha: BrandCaptcha | null | undefined; onBack: () => void }) {
  const [email, setEmail] = useState(start);
  const [token, setToken] = useState<string | null>(null);
  const [resetKey, setResetKey] = useState(0);
  const [answer, setAnswer] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setFailure(null);
    try {
      setAnswer((await asking.send(email.trim(), token)).message);
    } catch (err) {
      const fields = err instanceof KitApiError ? Object.values(err.fields).flat() : [];
      setFailure(fields.length ? fields.join(" ") : err instanceof Error ? err.message : String(err));
      setResetKey((k) => k + 1);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="ask-title">
      <h2 id="ask-title">{t(asking.title)}</h2>
      {answer ? (
        <p role="status">{answer}</p>
      ) : (
        <form onSubmit={submit} noValidate>
          <p className="muted">{t(asking.help)}</p>
          <div className="field">
            <label htmlFor="ask-email">{t("email")}</label>
            <input id="ask-email" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} />
          </div>
          <Captcha captcha={captcha} form={asking.form} onToken={setToken} resetKey={resetKey} />
          {failure && <p role="alert" className="error">{failure}</p>}
          <button type="submit" disabled={busy}>{busy ? t("saving") : t(asking.button)}</button>
        </form>
      )}
      <p>
        <button type="button" className="link" onClick={onBack}>{t("backToSignIn")}</button>
      </p>
    </section>
  );
}
