/**
 * The bot check and sign-in providers in the console (setup/Captcha.tsx, setup/SignIn.tsx, setup/SignInWithCode.tsx,
 * setup/SignUp.tsx, admin/CaptchaPage.tsx, admin/SsoPage.tsx), with a fake provider script:
 * - a guarded form loads the provider's script once, draws its widget with the site key and sends its token; a refused
 *   send resets the widget (a token works once); an unguarded form draws nothing and sends no token;
 * - "Continue with <provider>" goes to the provider's start address (never in the admin panel); back from a provider,
 *   the page says why it did not sign the person in — only for a reason it knows;
 * - staff pick the bot check's provider, keys and forms (none clears them), and see the backend's word on a refused
 *   secret; staff add a sign-in provider — Google by preset, another by its issuer — see the address to register,
 *   enable it, and remove it only after asking.
 */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Brand, BrandCaptcha, CaptchaSettings, SsoSettings } from "../src/api";
import { CaptchaPage } from "../src/admin/CaptchaPage";
import { SsoPage } from "../src/admin/SsoPage";
import { SignIn } from "../src/setup/SignIn";
import { SignInWithCode } from "../src/setup/SignInWithCode";
import { SignUp } from "../src/setup/SignUp";
import { brand, fakeKit } from "./fakeKit";

type Rendered = { element: HTMLElement; options: { sitekey: string; callback: (t: string) => void; "expired-callback": () => void } };
let rendered: Rendered[] = [];
let resets: unknown[] = [];

beforeEach(() => {
  rendered = [];
  resets = [];
  (window as unknown as Record<string, unknown>).fakecheck = {
    render: (element: HTMLElement, options: Rendered["options"]) => {
      rendered.push({ element, options });
      return `w${rendered.length}`;
    },
    reset: (id: unknown) => resets.push(id),
  };
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  document.head.querySelectorAll("script").forEach((s) => s.remove());
});

function at(path: string, element: ReactElement) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="*" element={element} />
      </Routes>
    </MemoryRouter>,
  );
}

const captcha: BrandCaptcha = { provider: "standin", script: "https://check.example/api.js?render=explicit", global: "fakecheck", siteKey: "site-1", forms: ["sign_in", "sign_in_code", "sign_up"] };

/** The page's provider script (each test its own address: a page loads a script once) finishes loading. */
async function scriptLoads(src = captcha.script) {
  const tag = await waitFor(() => {
    const found = [...document.head.querySelectorAll("script")].find((tag) => tag.getAttribute("src") === src);
    if (!found) throw new Error("no script yet");
    return found as HTMLScriptElement;
  });
  await act(async () => {
    tag.dispatchEvent(new Event("load"));
  });
  await waitFor(() => expect(rendered.length).toBeGreaterThan(0));
}

describe("the bot check on a guarded form", () => {
  it("draws the widget with the site key, sends its token, and resets it after a refused send", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/brand": { ...brand, captcha },
      "POST /api/kit/session": () => (++tries === 1
        ? Response.json({ message: "x", errors: { email: ["These details do not match an account."] } }, { status: 422 })
        : Response.json({ user: { id: 1, name: "Op", email: "op@voice.example", role: "owner" } })),
    });
    const onSignedIn = vi.fn();
    at("/admin", <SignIn offer={["reset"]} onSignedIn={onSignedIn} />);
    await scriptLoads();
    expect(rendered).toHaveLength(1);
    expect(rendered[0].options.sitekey).toBe("site-1");
    expect(rendered[0].element.dataset.testid).toBe("captcha");
    expect(document.head.querySelectorAll("script")).toHaveLength(1);

    act(() => rendered[0].options.callback("tok-1"));
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "op@voice.example" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "pw" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect((await screen.findByRole("alert")).textContent).toBe("These details do not match an account.");
    await waitFor(() => expect(resets).toEqual(["w1"]));

    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalled());
    const bodies = sent.filter((s) => s.method === "POST").map((s) => s.body);
    expect(bodies).toEqual([{ email: "op@voice.example", password: "pw", captchaToken: "tok-1" }, { email: "op@voice.example", password: "pw" }]);
  });

  it("draws nothing on a form it does not guard", async () => {
    const sent = fakeKit({
      "GET /api/kit/brand": { ...brand, passwordReset: true, captcha },
      "POST /api/kit/password/forgot": () => Response.json({ message: "On its way." }, { status: 202 }),
    });
    at("/console", <SignIn onSignedIn={() => undefined} />);
    fireEvent.click(await screen.findByRole("button", { name: "Forgot your password?" }));
    expect(screen.queryByTestId("captcha")).toBeNull();
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "ana@dental.example" } });
    fireEvent.click(screen.getByRole("button", { name: "Email me a link" }));
    await screen.findByRole("status");
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ email: "ana@dental.example" });
  });

  it("guards asking for an emailed code by that form's own name", async () => {
    const own: BrandCaptcha = { ...captcha, script: `${captcha.script}&test=email-code`, forms: ["sign_in_email_code"] };
    const sent = fakeKit({ "POST /api/kit/session/email-code": () => Response.json({ message: "On its way." }, { status: 202 }) });
    at("/console", <SignInWithCode way="email" captcha={own} onSignedIn={() => undefined} onBack={() => undefined} />);
    await scriptLoads(own.script);
    act(() => rendered[0].options.callback("tok-email"));
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "ana@dental.example" } });
    fireEvent.click(screen.getByRole("button", { name: "Email me a code" }));
    await screen.findByRole("status");
    expect(sent.find((s) => s.path === "/api/kit/session/email-code")?.body).toEqual({ email: "ana@dental.example", captchaToken: "tok-email" });
  });

  it("guards asking for a sign-in code and signing up", async () => {
    const own = { ...captcha, script: `${captcha.script}&test=code` };
    const sent = fakeKit({
      "GET /api/kit/brand": { ...brand, captcha: own },
      "GET /api/kit/signup": { open: true, terms: null, fields: [{ name: "email", label: "Work email", type: "email", required: true, value: null, help: null, options: [], showWhen: null, secretSet: false }] },
      "POST /api/kit/session/code": () => Response.json({ message: "On its way." }, { status: 202 }),
      "POST /api/kit/signup": () => Response.json({ message: "Check your email." }, { status: 202 }),
    });
    at("/console", <SignInWithCode way="sms" captcha={own} onSignedIn={() => undefined} onBack={() => undefined} />);
    await scriptLoads(own.script);
    act(() => rendered[0].options.callback("tok-code"));
    fireEvent.change(screen.getByLabelText("Phone number"), { target: { value: "+919876543210" } });
    fireEvent.click(screen.getByRole("button", { name: "Text me a code" }));
    await screen.findByRole("status");
    expect(sent.find((s) => s.path === "/api/kit/session/code")?.body).toEqual({ phone: "+919876543210", captchaToken: "tok-code" });
    cleanup();

    at("/signup", <SignUp />);
    await waitFor(() => expect(rendered).toHaveLength(2));
    act(() => rendered[1].options.callback("tok-signup"));
    fireEvent.change(await screen.findByLabelText("Work email"), { target: { value: "ana@dental.example" } });
    fireEvent.click(screen.getByRole("button", { name: "Create an account" }));
    await screen.findByText("Check your email.");
    expect(sent.find((s) => s.method === "POST" && s.path === "/api/kit/signup")?.body).toEqual({ email: "ana@dental.example", captchaToken: "tok-signup" });
  });
});

describe("signing in with a provider", () => {
  const withProviders: Brand = { ...brand, signInWith: [{ id: "p1", name: "Google" }, { id: "p2", name: "Acme ID" }] };

  it("goes to the provider's start address, and is never offered in the admin panel", async () => {
    fakeKit({ "GET /api/kit/brand": withProviders });
    const go = vi.fn();
    at("/console", <SignIn onSignedIn={() => undefined} go={go} />);
    fireEvent.click(await screen.findByRole("button", { name: "Continue with Acme ID" }));
    expect(go).toHaveBeenCalledWith("/api/kit/oidc/p2/start");
    expect(screen.getByRole("button", { name: "Continue with Google" })).toBeTruthy();
    cleanup();
    fakeKit({ "GET /api/kit/brand": { ...withProviders, passwordReset: true } });
    at("/admin", <SignIn offer={["reset"]} onSignedIn={() => undefined} go={go} />);
    await screen.findByRole("button", { name: "Forgot your password?" });
    expect(screen.queryByRole("button", { name: "Continue with Google" })).toBeNull();
  });

  it("says why the provider did not sign the person in, only for a reason it knows", async () => {
    fakeKit({ "GET /api/kit/brand": withProviders });
    at("/console?signInError=no_account", <SignIn onSignedIn={() => undefined} />);
    expect((await screen.findByRole("alert")).textContent).toBe("No account here uses that email yet. Ask for an invite.");
    cleanup();
    fakeKit({ "GET /api/kit/brand": withProviders });
    at("/console?signInError=Your%20account%20is%20locked.%20Call%20us", <SignIn onSignedIn={() => undefined} />);
    await screen.findByRole("button", { name: "Continue with Google" });
    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.queryByText(/Call us/)).toBeNull();
  });
});

function captchaSettings(over: Partial<CaptchaSettings> = {}): CaptchaSettings {
  return { provider: null, siteKey: null, secretSet: false, forms: ["sign_up"],
    providers: [{ value: "turnstile", label: "Cloudflare Turnstile" }, { value: "hcaptcha", label: "hCaptcha" }],
    formChoices: [{ value: "sign_up", label: "Signing up" }, { value: "sign_in", label: "Signing in with a password" }], ...over };
}

describe("Admin → Bot check", () => {
  it("saves the provider, keys and forms, shows a refused secret, and clears it all with none", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/admin/captcha": captchaSettings(),
      "PUT /api/kit/admin/captcha": (b: unknown) => (++tries === 1
        ? Response.json({ message: "x", errors: { secret: ["The provider did not accept this secret key."] } }, { status: 422 })
        : Response.json(captchaSettings({ ...(b as object), secretSet: true } as Partial<CaptchaSettings>))),
    });
    at("/admin/captcha", <CaptchaPage />);
    const form = await screen.findByRole("form", { name: "Bot check" });
    expect(within(form).queryByLabelText("Site key")).toBeNull();
    fireEvent.change(within(form).getByLabelText("Provider"), { target: { value: "turnstile" } });
    fireEvent.change(within(form).getByLabelText("Site key"), { target: { value: " 0x4AAA-site " } });
    fireEvent.change(within(form).getByLabelText("Secret key"), { target: { value: "wrong" } });
    fireEvent.click(within(form).getByLabelText("Signing in with a password"));
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(form).findByText("The provider did not accept this secret key.")).toBeTruthy();
    fireEvent.change(within(form).getByLabelText("Secret key"), { target: { value: "0x4AAA-secret" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(form).findByRole("status")).toBeTruthy();
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({ provider: "turnstile", siteKey: "0x4AAA-site", secret: "0x4AAA-secret", forms: ["sign_up", "sign_in"] });
    expect((within(form).getByLabelText("Secret key") as HTMLInputElement).value).toBe("");

    fireEvent.change(within(form).getByLabelText("Provider"), { target: { value: "" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT")).toHaveLength(3));
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({ provider: null, siteKey: null, secret: "", forms: ["sign_up", "sign_in"] });
  });
});

function sso(over: Partial<SsoSettings> = {}): SsoSettings {
  return { redirectUri: "https://voice.acme.example/api/kit/oidc/callback", maxProviders: 5,
    presets: [{ value: "google", label: "Google", issuer: "https://accounts.google.com", help: null }], providers: [], ...over };
}

const acme = { id: "p2", preset: null, name: "Acme ID", issuer: "https://id.acme.example", clientId: "kit", secretSet: true, enabled: true };

describe("Admin → Sign-in providers", () => {
  it("shows the address to register, adds Google by preset and another by its issuer, with the backend's word on a bad issuer", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/admin/sso": sso(),
      "POST /api/kit/admin/sso/providers": () => (++tries === 2
        ? Response.json({ message: "x", errors: { issuer: ["This provider's OpenID configuration could not be read: 404"] } }, { status: 422 })
        : Response.json(sso({ providers: tries === 1 ? [{ ...acme, id: "p1", preset: "google", name: "Google", issuer: "https://accounts.google.com" }] : [acme] }), { status: 201 })),
    });
    at("/admin/sso", <SsoPage />);
    expect((await screen.findByTestId("redirect-uri")).textContent).toBe("https://voice.acme.example/api/kit/oidc/callback");
    let form = screen.getByRole("form", { name: "Add" });
    expect(within(form).queryByLabelText("Issuer")).toBeNull();
    expect((within(form).getByLabelText("Button name") as HTMLInputElement).value).toBe("Google");
    fireEvent.change(within(form).getByLabelText("Client ID"), { target: { value: " 123.apps.googleusercontent.com " } });
    fireEvent.change(within(form).getByLabelText("Client secret"), { target: { value: "GOCSPX-1" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add" }));
    await screen.findByText("Google", { selector: "strong" });
    expect(sent.filter((s) => s.method === "POST")[0].body).toEqual({ preset: "google", issuer: null, name: "Google", clientId: "123.apps.googleusercontent.com", clientSecret: "GOCSPX-1" });

    form = screen.getByRole("form", { name: "Add" });
    fireEvent.change(within(form).getByLabelText("Provider"), { target: { value: "" } });
    fireEvent.change(within(form).getByLabelText("Issuer"), { target: { value: "https://id.acme.example " } });
    fireEvent.change(within(form).getByLabelText("Button name"), { target: { value: "Acme ID" } });
    fireEvent.change(within(form).getByLabelText("Client ID"), { target: { value: "kit" } });
    fireEvent.change(within(form).getByLabelText("Client secret"), { target: { value: "s" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add" }));
    expect(await within(form).findByText("This provider's OpenID configuration could not be read: 404")).toBeTruthy();
    fireEvent.click(within(form).getByRole("button", { name: "Add" }));
    await screen.findByText("Acme ID", { selector: "strong" });
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({ preset: null, issuer: "https://id.acme.example", name: "Acme ID", clientId: "kit", clientSecret: "s" });
  });

  it("enables and disables a provider keeping its secret, and removes it only after asking", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/sso": () => Response.json(sso({ providers: sent.some((s) => s.method === "DELETE") ? [] : [acme] })),
      "PUT /api/kit/admin/sso/providers/p2": () => Response.json(sso({ providers: [{ ...acme, enabled: false }] })),
      "DELETE /api/kit/admin/sso/providers/p2": () => new Response(null, { status: 204 }),
    });
    at("/admin/sso", <SsoPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Disable" }));
    await screen.findByRole("button", { name: "Enable" });
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ preset: null, issuer: "https://id.acme.example", name: "Acme ID", clientId: "kit", clientSecret: "", enabled: false });
    fireEvent.click(screen.getByRole("button", { name: "Remove" }));
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    expect(screen.getByText("Remove Acme ID? People who signed in with it sign in another way.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, remove" }));
    expect(await screen.findByText("No sign-in provider yet.")).toBeTruthy();
  });
});
