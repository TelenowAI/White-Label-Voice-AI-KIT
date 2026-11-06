/**
 * Signing up (`/signup`; contract/kit-api.yaml getSignupForm, signUp, verifyEmailSignup, signUpByPhone, verifyPhoneSignup,
 * getProviderSignup, signUpWithProvider): the form exactly as the backend declares it — the built-in fields, the
 * operator's own (`answers.<key>`, sent nested under `answers`) and the terms box — drawn by the wizard's field
 * renderer, with the backend's word on each refused field. A sign-up answers "check your email" (the same whether or
 * not the address is known); closed says so, with the way to sign in. When the operator guards signing up, the bot check
 * (Captcha.tsx) is part of the form and its token goes with it. When the operator confirms sign-ups by an emailed code
 * (`emailCode`), the page then asks for that code in place of "check your email"; confirmed, the person is signed in and
 * taken to their console, or told the sign-up waits for approval.
 *
 * Two more ways, each when the backend offers it:
 * - **With a phone** (`phoneFields`): the same form with a number instead of an email and a password, then the code the
 *   backend sent (the brand's `signInCodeChannel` says how, so the button can too); confirmed, the same. "Send a new code"
 *   goes back to the form, values kept.
 * - **With a sign-in provider** ("Continue with <name>", the brand's `signInWith`; a page load to the provider): someone
 *   it vouches for with no account comes back to `/signup?with=provider` and finishes here — the address the provider
 *   confirmed is shown, not asked. When that offer has run out, the page says so, with the way to sign up again.
 *
 * Not here: confirming an emailed address (VerifySignup.tsx), signing in (SignIn.tsx).
 */
import { useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { KitApiError, kitApi, ssoStartUrl, type Brand, type BrandCaptcha, type ProviderSignup, type SetupField, type SignupForm } from "../api";
import { t } from "../text";
import { Captcha } from "./Captcha";
import { codeWords } from "./SignInWithCode";
import { FieldInput } from "./StepForm";

/** The form's values as the backend takes them: `answers.<key>` nested under `answers`. */
function body(values: Record<string, string>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  const answers: Record<string, string> = {};
  for (const [name, value] of Object.entries(values)) {
    if (name.startsWith("answers.")) answers[name.slice("answers.".length)] = value;
    else out[name] = name === "email" || name === "name" || name === "accountName" || name === "phone" ? value.trim() : value;
  }
  return Object.keys(answers).length > 0 ? { ...out, answers } : out;
}

/** A declared form's starting values: what the backend suggests, else a required choice's first option, else empty. */
function initialValues(fields: SetupField[]): Record<string, string> {
  return Object.fromEntries(fields.map((x) => [x.name, x.value ?? (x.type === "select" && x.required ? (x.options[0]?.value ?? "") : "")]));
}

function messageOf(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function SignUp({ go = (url) => window.location.assign(url) }: { go?: (url: string) => void }) {
  const [params] = useSearchParams();
  const [brand, setBrand] = useState<Brand | null>(null);

  useEffect(() => {
    kitApi.brand().then(setBrand, () => undefined);
  }, []);

  return params.get("with") === "provider"
    ? <WithProvider captcha={brand?.captcha ?? null} />
    : <ByEmail captcha={brand?.captcha ?? null} providers={brand?.signInWith ?? []} channel={brand?.signInCodeChannel ?? null} go={go} />;
}

/** Asking for the code a sign-up was sent, and for a new one. */
function ConfirmCode({ answer, onConfirm, onNewCode }: { answer: string | null; onConfirm: (code: string) => Promise<void>; onNewCode: () => void }) {
  const [code, setCode] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function confirm(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      await onConfirm(code.trim());
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(messageOf(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={confirm} noValidate aria-label={t("enterCode")}>
      {failure && <p role="alert" className="error">{failure}</p>}
      {answer && <p role="status">{answer}</p>}
      <div className="field">
        <label htmlFor="signup-code">{t("signUpCodeLabel")}</label>
        <input id="signup-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)}
          aria-invalid={errors.code ? true : undefined} aria-describedby={errors.code ? "signup-code-error" : undefined} />
        {errors.code && <small id="signup-code-error" className="error">{errors.code.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("finishSignUp")}</button>
      <p>
        <button type="button" className="link" onClick={onNewCode}>{t("newCode")}</button>
      </p>
    </form>
  );
}

function ByEmail({ captcha, providers, channel, go }: { captcha: BrandCaptcha | null; providers: Brand["signInWith"]; channel: Brand["signInCodeChannel"]; go: (url: string) => void }) {
  const navigate = useNavigate();
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [waiting, setWaiting] = useState<string | null>(null);
  const [form, setForm] = useState<SignupForm | null>(null);
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [byPhone, setByPhone] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [resetKey, setResetKey] = useState(0);

  useEffect(() => {
    kitApi.signupForm().then((f) => {
      setForm(f);
      setValues(initialValues(f.fields));
    }, (err) => setFailure(messageOf(err)));
  }, []);

  if (byPhone && form?.phoneFields) return <ByPhone fields={form.phoneFields} captcha={captcha} channel={channel} onBack={() => setByPhone(false)} />;

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const { message } = await kitApi.signUp(body(values), token);
      setDone(message);
      if (form?.emailCode) setSentTo((values.email ?? "").trim());
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(messageOf(err));
      setResetKey((k) => k + 1); // a bot check's token works once
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="signup-title">
      <h2 id="signup-title">{t("signUpTitle")}</h2>
      {failure && <p role="alert" className="error">{failure}</p>}
      {form && !form.open && <p className="muted">{t("signUpClosed")}</p>}
      {waiting !== null ? <p role="status">{waiting}</p> : sentTo !== null ? (
        <ConfirmCode answer={done} onNewCode={() => { setSentTo(null); setDone(null); setResetKey((k) => k + 1); }}
          onConfirm={async (code) => {
            const r = await kitApi.verifyEmailSignup(sentTo, code);
            if (r.state === "active") navigate("/console", { replace: true });
            else setWaiting(r.message ?? "");
          }} />
      ) : done && <p role="status">{done}</p>}
      {form?.open && !done && (
        <>
          {providers.length > 0 && (
            <p className="actions">
              {providers.map((p) => (
                <button key={p.id} type="button" className="secondary" onClick={() => go(ssoStartUrl(p.id))}>{t("continueWith", { name: p.name })}</button>
              ))}
            </p>
          )}
          <form onSubmit={submit} noValidate aria-label={t("signUpTitle")}>
            {form.fields.map((f) => (
              <FieldInput key={f.name} stepId="signup" field={f} value={values[f.name] ?? ""} errors={errors[f.name] ?? []}
                onChange={(v) => setValues((old) => ({ ...old, [f.name]: v }))} />
            ))}
            <Captcha captcha={captcha} form="sign_up" onToken={setToken} resetKey={resetKey} />
            {errors.captchaToken && <p role="alert" className="error">{errors.captchaToken.join(" ")}</p>}
            <button type="submit" disabled={busy}>{busy ? t("saving") : t("createAccount")}</button>
          </form>
          {form.phoneFields && (
            <p><button type="button" className="link" onClick={() => setByPhone(true)}>{t("signUpByPhone")}</button></p>
          )}
        </>
      )}
      <p><Link to="/console">{t("haveAccount")}</Link></p>
    </section>
  );
}

function ByPhone({ fields, captcha, channel, onBack }: { fields: SetupField[]; captcha: BrandCaptcha | null; channel: Brand["signInCodeChannel"]; onBack: () => void }) {
  const navigate = useNavigate();
  const [values, setValues] = useState<Record<string, string>>(() => initialValues(fields));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [answer, setAnswer] = useState<string | null>(null);
  const [waiting, setWaiting] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [resetKey, setResetKey] = useState(0);

  async function ask(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      setAnswer((await kitApi.signUpByPhone(body(values), token)).message);
      setSentTo((values.phone ?? "").trim());
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(messageOf(err));
      setResetKey((k) => k + 1); // a bot check's token works once
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="signup-title">
      <h2 id="signup-title">{t("signUpTitle")}</h2>
      {failure && <p role="alert" className="error">{failure}</p>}
      {waiting !== null ? (
        <p role="status">{waiting}</p>
      ) : sentTo === null ? (
        <form onSubmit={ask} noValidate aria-label={t("signUpTitle")}>
          {fields.map((f) => (
            <FieldInput key={f.name} stepId="signup-phone" field={f} value={values[f.name] ?? ""} errors={errors[f.name] ?? []}
              onChange={(v) => setValues((old) => ({ ...old, [f.name]: v }))} />
          ))}
          <Captcha captcha={captcha} form="sign_up" onToken={setToken} resetKey={resetKey} />
          {errors.captchaToken && <p role="alert" className="error">{errors.captchaToken.join(" ")}</p>}
          <button type="submit" disabled={busy}>{busy ? t("saving") : t(codeWords[channel ?? "sms"].button)}</button>
          <p><button type="button" className="link" onClick={onBack}>{t("signUpByEmail")}</button></p>
        </form>
      ) : (
        <ConfirmCode answer={answer} onNewCode={() => { setSentTo(null); setAnswer(null); setErrors({}); }}
          onConfirm={async (code) => {
            const r = await kitApi.verifyPhoneSignup(sentTo, code);
            if (r.state === "active") navigate("/console", { replace: true });
            else setWaiting(r.message ?? "");
          }} />
      )}
      <p><Link to="/console">{t("haveAccount")}</Link></p>
    </section>
  );
}

function WithProvider({ captcha }: { captcha: BrandCaptcha | null }) {
  const navigate = useNavigate();
  const [offer, setOffer] = useState<ProviderSignup | null | undefined>(undefined);
  const [values, setValues] = useState<Record<string, string>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [waiting, setWaiting] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [token, setToken] = useState<string | null>(null);
  const [resetKey, setResetKey] = useState(0);

  useEffect(() => {
    kitApi.providerSignup().then((o) => {
      setOffer(o);
      setValues(initialValues(o.fields));
    }, (err) => {
      setOffer(null);
      setFailure(messageOf(err));
    });
  }, []);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const r = await kitApi.signUpWithProvider(body(values), token);
      if (r.state === "active") navigate("/console", { replace: true });
      else setWaiting(r.message ?? "");
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(messageOf(err));
      setResetKey((k) => k + 1); // a bot check's token works once
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby="signup-title">
      <h2 id="signup-title">{t("signUpTitle")}</h2>
      {offer === undefined && <p aria-busy="true">{t("loading")}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      {waiting !== null && <p role="status">{waiting}</p>}
      {offer && waiting === null && (
        <form onSubmit={submit} noValidate aria-label={t("signUpTitle")}>
          <p className="muted">{t("signUpProviderHelp", { email: offer.email, provider: offer.provider })}</p>
          {offer.fields.map((f) => (
            <FieldInput key={f.name} stepId="signup-provider" field={f} value={values[f.name] ?? ""} errors={errors[f.name] ?? []}
              onChange={(v) => setValues((old) => ({ ...old, [f.name]: v }))} />
          ))}
          <Captcha captcha={captcha} form="sign_up" onToken={setToken} resetKey={resetKey} />
          {errors.captchaToken && <p role="alert" className="error">{errors.captchaToken.join(" ")}</p>}
          <button type="submit" disabled={busy}>{busy ? t("saving") : t("createAccount")}</button>
        </form>
      )}
      {offer === null && <p><Link to="/signup">{t("signUpAgain")}</Link></p>}
    </section>
  );
}
