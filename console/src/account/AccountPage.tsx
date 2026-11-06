/**
 * Console → Your account (`/console/account`; contract/kit-api.yaml getAccount, requestPhoneCode, verifyPhone,
 * removePhone): the signed-in person's name and email, and their phone number — added only by proving it with a code
 * texted to it (then it can be used to sign in, when the platform offers that), removed only after asking. With no
 * texts on the platform, it says a number cannot be added yet. Someone who signed up with their phone has no email: the
 * number is how they sign in, so it is never offered for removal, only changed to a new one they prove.
 *
 * Not here: signing in with the number (setup/SignInWithCode.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Account } from "../api";
import { t } from "../text";

function fieldsOf(err: unknown): Record<string, string[]> {
  if (err instanceof KitApiError && Object.keys(err.fields).length > 0) return err.fields;
  return { form: [err instanceof Error ? err.message : String(err)] };
}

export function AccountPage() {
  const [account, setAccount] = useState<Account | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setAccount(await kitApi.account());
      setFailure(null);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  return (
    <section aria-labelledby="account-title">
      <h1 id="account-title">{t("accountTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {account && (
        <>
          <dl className="facts">
            <div>
              <dt>{t("colName")}</dt>
              <dd>{account.name}</dd>
            </div>
            <div>
              <dt>{t("email")}</dt>
              <dd>{account.email ?? <span className="muted">{t("noEmail")}</span>}</dd>
            </div>
          </dl>
          <h2>{t("phoneNumber")}</h2>
          {account.phone ? <Phone account={account} onChanged={setAccount} /> : account.texts ? <AddPhone onAdded={setAccount} /> : <p className="muted">{t("phoneNoTexts")}</p>}
        </>
      )}
    </section>
  );
}

function Phone({ account, onChanged }: { account: Account; onChanged: (a: Account) => void }) {
  const [asking, setAsking] = useState(false);
  const [changing, setChanging] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  if (account.email === null) {
    if (changing) return <AddPhone onAdded={(a) => { setChanging(false); onChanged(a); }} />;
    return (
      <div className="actions">
        <span data-testid="phone">{account.phone}</span>
        <span className="muted">{t("phoneIsTheWayIn")}</span>
        {account.texts && <button type="button" className="secondary" onClick={() => setChanging(true)}>{t("changeNumber")}</button>}
      </div>
    );
  }

  async function remove() {
    setFailure(null);
    try {
      onChanged(await kitApi.removePhone());
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }

  return (
    <div className="actions">
      <span data-testid="phone">{account.phone}</span>
      {asking ? (
        <>
          <span>{t("confirmRemovePhone", { phone: account.phone ?? "" })}</span>
          <button type="button" onClick={remove}>{t("yesRemove")}</button>
          <button type="button" className="secondary" onClick={() => setAsking(false)}>{t("cancel")}</button>
        </>
      ) : (
        <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("remove")}</button>
      )}
      {failure && <span role="alert" className="error">{failure}</span>}
    </div>
  );
}

function AddPhone({ onAdded }: { onAdded: (a: Account) => void }) {
  const [phone, setPhone] = useState("");
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
      setAnswer((await kitApi.requestPhoneCode(phone.trim())).message);
      setSentTo(phone.trim());
      setCode("");
    } catch (err) {
      setErrors(fieldsOf(err));
    } finally {
      setBusy(false);
    }
  }

  async function confirm(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      onAdded(await kitApi.verifyPhone(sentTo!, code.trim()));
    } catch (err) {
      setErrors(fieldsOf(err));
    } finally {
      setBusy(false);
    }
  }

  const said = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;

  return sentTo === null ? (
    <form className="agent-form" onSubmit={ask} noValidate aria-label={t("addPhone")}>
      <p className="muted">{t("addPhoneHelp")}</p>
      <div className="field">
        <label htmlFor="account-phone">{t("phoneNumber")}</label>
        <input id="account-phone" type="tel" autoComplete="tel" value={phone} onChange={(e) => setPhone(e.target.value)}
          aria-invalid={errors.phone ? true : undefined} />
        {said("phone")}
      </div>
      {errors.form && <p role="alert" className="error">{errors.form.join(" ")}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("textMeACode")}</button>
    </form>
  ) : (
    <form className="agent-form" onSubmit={confirm} noValidate aria-label={t("confirmPhone")}>
      {answer && <p role="status">{answer}</p>}
      <div className="field">
        <label htmlFor="account-code">{t("codeLabel")}</label>
        <input id="account-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(e) => setCode(e.target.value)}
          aria-invalid={errors.code ? true : undefined} />
        {said("code")}
      </div>
      {errors.form && <p role="alert" className="error">{errors.form.join(" ")}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("confirmPhone")}</button>
      <p>
        <button type="button" className="link" onClick={() => { setSentTo(null); setAnswer(null); setErrors({}); }}>{t("newCode")}</button>
      </p>
    </form>
  );
}
