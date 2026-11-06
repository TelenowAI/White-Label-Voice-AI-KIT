/**
 * The bot check's widget (contract/kit-api.yaml Brand.captcha) on a public form the operator guards (`form`): the
 * provider's own script, loaded once per page with explicit rendering, drawn by `window[global].render` — Turnstile,
 * hCaptcha and reCAPTCHA all take the same call. Its token goes to `onToken` (null again when it expires or errors). A
 * token works once, so a form bumps `resetKey` after each refused send and the widget starts over. Nothing is drawn
 * when the form is not guarded.
 *
 * Used by SignIn.tsx (and its ask-by-email forms), SignInWithCode.tsx and SignUp.tsx.
 */
import { useEffect, useRef, useState } from "react";
import type { BrandCaptcha, CaptchaForm } from "../api";
import { t } from "../text";

interface Widget {
  render: (element: HTMLElement, options: Record<string, unknown>) => string | number;
  reset: (id?: string | number) => void;
}

const loading = new Map<string, Promise<void>>();

function load(src: string): Promise<void> {
  let script = loading.get(src);
  if (!script) {
    script = new Promise<void>((resolve, reject) => {
      const tag = document.createElement("script");
      tag.src = src;
      tag.async = true;
      tag.onload = () => resolve();
      tag.onerror = () => {
        loading.delete(src);
        reject(new Error(src));
      };
      document.head.appendChild(tag);
    });
    loading.set(src, script);
  }
  return script;
}

function globalOf(name: string): Widget | undefined {
  const found = (window as unknown as Record<string, unknown>)[name] as Widget | undefined;
  return found && typeof found.render === "function" ? found : undefined;
}

/** The provider's global once its render() is there: some scripts set it up a moment after they load. */
async function widget(name: string, waitMs: number, stepMs: number): Promise<Widget> {
  for (let waited = 0; waited <= waitMs; waited += stepMs) {
    const found = globalOf(name);
    if (found) return found;
    await new Promise((r) => setTimeout(r, stepMs));
  }
  throw new Error(`${name} did not load`);
}

/** Whether this form must pass the bot check. */
export function guarded(captcha: BrandCaptcha | null | undefined, form: CaptchaForm): boolean {
  return !!captcha && captcha.forms.includes(form);
}

export function Captcha({ captcha, form, onToken, resetKey = 0, waitMs = 10_000, stepMs = 100 }: {
  captcha: BrandCaptcha | null | undefined; form: CaptchaForm; onToken: (token: string | null) => void; resetKey?: number; waitMs?: number; stepMs?: number;
}) {
  const box = useRef<HTMLDivElement>(null);
  const id = useRef<string | number | null>(null);
  const [failed, setFailed] = useState(false);
  // The form's callback is new on each render; the widget keeps calling the latest one.
  const tokenTo = useRef(onToken);
  tokenTo.current = onToken;
  const on = guarded(captcha, form);
  const script = captcha?.script;
  const name = captcha?.global;
  const siteKey = captcha?.siteKey;

  useEffect(() => {
    if (!on || !script || !name) return;
    let gone = false;
    load(script)
      .then(() => widget(name, waitMs, stepMs))
      .then((w) => {
        if (gone || !box.current) return;
        id.current = w.render(box.current, {
          sitekey: siteKey,
          callback: (token: string) => tokenTo.current(token),
          "expired-callback": () => tokenTo.current(null),
          "error-callback": () => tokenTo.current(null),
        });
      })
      .catch(() => {
        if (!gone) setFailed(true);
      });
    return () => {
      gone = true;
    };
  }, [on, script, name, siteKey, waitMs, stepMs]);

  useEffect(() => {
    if (resetKey === 0 || id.current === null || !name) return;
    globalOf(name)?.reset(id.current);
    tokenTo.current(null);
  }, [resetKey, name]);

  if (!on) return null;
  return (
    <div className="field">
      <div ref={box} data-testid="captcha" />
      {failed && <small className="error">{t("captchaLoadFailed")}</small>}
    </div>
  );
}
