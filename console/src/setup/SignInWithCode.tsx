/**
 * Signing in with a one-time code (contract/kit-api.yaml requestEmailCode, useEmailCode, requestSignInCode, useSignInCode),
 * offered beside the sign-in form when the brand says so (SignIn.tsx). `way` says where the code goes: to the address on
 * the account, or to the phone they proved, by text, by WhatsApp, or either when the operator lined up both, so the page can
 * say where to look. First the address or number: the backend's answer is the same whether or not a customer uses it. Then
 * the code; a wrong, used or expired one shows the backend's word on the code, and a new code can be asked for.
 *
 * Not here: proving a number (account/AccountPage.tsx), the password form (SignIn.tsx).
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Brand, type BrandCaptcha, type User } from "../api";
import { t, type TextKey } from "../text";
import { Captcha } from "./Captcha";

export type CodeWay = "email" | NonNullable<Brand["signInCodeChannel"]>;

/** What each way is called: on the sign-in page's link, on this page, and on its button. */
export const codeWords: Record<CodeWay, { link: TextKey; title: TextKey; help: TextKey; button: TextKey }> = {
  email: { link: "emailSignInCode", title: "codeEmailTitle", help: "codeEmailHelp", button: "codeEmailButton" },
  sms: { link: "textSignInCode", title: "codeTitle", help: "codeHelp", button: "textMeACode" },
  whatsapp: { link: "whatsAppSignInCode", title: "codeWhatsAppTitle", help: "codeWhatsAppHelp", button: "codeWhatsAppButton" },
  mixed: { link: "phoneSignInCode", title: "codePhoneTitle", help: "codePhoneHelp", button: "codePhoneButton" },
};

function fieldsOf(err: unknown): Record<string, string[]> {
  if (err instanceof KitApiError && Object.keys(err.fields).length > 0) return err.fields;
  return { form: [err instanceof Error ? err.message : String(err)] };
}

export function SignInWithCode({ way, captcha, onSignedIn, onBack }: { way: CodeWay; captcha?: BrandCaptcha | null; onSignedIn: (user: User) => void; onBack: () => void }) {
  const byEmail = way === "email";
  const words = codeWords[way];
  const [address, setAddress] = useState("");
  const [token, setToken] = useState<string | null>(null);
  const [resetKey, setResetKey] = useState(0);
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [answer, setAnswer] = useState<string | null>(null);
  const [code, setCode] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function ask(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const to = address.trim();
      setAnswer((await (byEmail ? kitApi.requestEmailCode(to, token) : kitApi.requestSignInCode(to, token))).message);
      setSentTo(to);
      setCode("");
    } catch (err) {
      setErrors(fieldsOf(err));
      setResetKey((k) => k + 1);
    } finally {
      setBusy(false);
    }
  }

  async function use(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      const { user } = await (byEmail ? kitApi.useEmailCode(sentTo!, code.trim()) : kitApi.useSignInCode(sentTo!, code.trim()));
      if (user) onSignedIn(user);
    } catch (err) {
      setErrors(fieldsOf(err));
    } finally {
      setBusy(false);
    }
  }

  const said = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  const field = byEmail ? "email" : "phone";

  return (
    <section className="step" aria-labelledby="code-title">
      <h2 id="code-title">{t(words.title)}</h2>
      {sentTo === null ? (
        <form onSubmit={ask} noValidate aria-label={t(words.title)}>
          <p className="muted">{t(words.help)}</p>
          <div className="field">
            <label htmlFor="code-address">{t(byEmail ? "email" : "phoneNumber")}</label>
            <input id="code-address" type={byEmail ? "email" : "tel"} autoComplete={byEmail ? "username" : "tel"} value={address} onChange={(e) => setAddress(e.target.value)}
              aria-invalid={errors[field] ? true : undefined} />
            {said(field)}
          </div>
          <Captcha captcha={captcha} form={byEmail ? "sign_in_email_code" : "sign_in_code"} onToken={setToken} resetKey={resetKey} />
          {said("captchaToken")}
          {errors.form && <p role="alert" className="error">{errors.form.join(" ")}</p>}
          <button type="submit" disabled={busy}>{busy ? t("saving") : t(words.button)}</button>
        </form>
      ) : (
        <form onSubmit={use} noValidate aria-label={t("enterCode")}>
          {answer && <p role="status">{answer}</p>}
          <div className="field">
            <label htmlFor="code-code">{t("codeLabel")}</label>
            <input id="code-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)}
              aria-invalid={errors.code ? true : undefined} />
            {said("code")}
          </div>
          {errors.form && <p role="alert" className="error">{errors.form.join(" ")}</p>}
          <button type="submit" disabled={busy}>{busy ? t("signingIn") : t("signIn")}</button>
          <p>
            <button type="button" className="link" onClick={() => { setSentTo(null); setAnswer(null); setErrors({}); }}>{t("newCode")}</button>
          </p>
        </form>
      )}
      <p>
        <button type="button" className="link" onClick={onBack}>{t("backToSignIn")}</button>
      </p>
    </section>
  );
}
