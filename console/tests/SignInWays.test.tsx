/**
 * The sign-in ways an operator can choose, on the console's pages: a code by email, by text or by WhatsApp, each said so; no
 * password box when passwords are off and another way exists, while staff keep theirs; a sign-up confirmed by an emailed
 * code; and Admin → Sign-in providers saying what Microsoft needs.
 */
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { SignupsPage } from "../src/admin/SignupsPage";
import { SsoPage } from "../src/admin/SsoPage";
import type { Brand, SetupField, SignupAdmin, SignupForm, SsoSettings } from "../src/api";
import { SignIn } from "../src/setup/SignIn";
import { SignUp } from "../src/setup/SignUp";
import { brand, fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const user = { id: 1, name: "Ana", email: "ana@dental.example", role: null, abilities: [] };

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function at(path: string, element: ReactElement) {
  render(<MemoryRouter initialEntries={[path]}><Where /><Routes><Route path="*" element={element} /></Routes></MemoryRouter>);
}

const withBrand = (over: Partial<Brand>) => ({ "GET /api/kit/brand": { ...brand, ...over } });

describe("signing in with a code", () => {
  it("emails a code, says the backend's answer, and signs in with it", async () => {
    const onSignedIn = vi.fn();
    const sent = fakeKit({
      ...withBrand({ signInEmailCode: true }),
      "POST /api/kit/session/email-code": () => Response.json({ message: "If a customer account uses that address, a code is on its way." }, { status: 202 }),
      "POST /api/kit/session/email-code/verify": (b: unknown) => (b as { code: string }).code === "424242"
        ? Response.json({ user })
        : Response.json({ message: "x", errors: { code: ["This code is wrong, used or expired. Ask for a new one."] } }, { status: 422 }),
    });
    at("/console", <SignIn onSignedIn={onSignedIn} />);
    fireEvent.click(await screen.findByRole("button", { name: "Email me a sign-in code" }));
    expect(screen.getByRole("heading", { name: "Sign in with an emailed code" })).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: " ana@dental.example " } });
    fireEvent.click(screen.getByRole("button", { name: "Email me a code" }));
    expect(await screen.findByText("If a customer account uses that address, a code is on its way.")).toBeTruthy();
    expect(sent.find((s) => s.path === "/api/kit/session/email-code")?.body).toEqual({ email: "ana@dental.example" });

    fireEvent.change(screen.getByLabelText("Code"), { target: { value: "000000" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByText("This code is wrong, used or expired. Ask for a new one.")).toBeTruthy();
    expect(onSignedIn).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText("Code"), { target: { value: " 424242 " } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalledWith(user));
    expect(sent.filter((s) => s.path === "/api/kit/session/email-code/verify").pop()?.body).toEqual({ email: "ana@dental.example", code: "424242" });
  });

  it.each([
    ["sms", "Text me a sign-in code", "Sign in with a texted code", "Text me a code"],
    ["whatsapp", "Send a sign-in code on WhatsApp", "Sign in with a WhatsApp code", "Send me a code on WhatsApp"],
    ["mixed", "Send a sign-in code to my phone", "Sign in with a code to your phone", "Send me a code"],
  ] as const)("says where a phone code goes: %s", async (channel, link, title, button) => {
    const sent = fakeKit({
      ...withBrand({ signInCode: true, signInCodeChannel: channel }),
      "POST /api/kit/session/code": () => Response.json({ message: "On its way." }, { status: 202 }),
    });
    at("/console", <SignIn onSignedIn={() => undefined} />);
    fireEvent.click(await screen.findByRole("button", { name: link }));
    expect(screen.getByRole("heading", { name: title })).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Phone number"), { target: { value: " +919876543210 " } });
    fireEvent.click(screen.getByRole("button", { name: button }));
    expect(await screen.findByText("On its way.")).toBeTruthy();
    expect(sent.find((s) => s.path === "/api/kit/session/code")?.body).toEqual({ phone: "+919876543210" });
  });
});

describe("with passwords off", () => {
  it("shows no password box or reset when another way exists", async () => {
    fakeKit(withBrand({ signInPassword: false, signInEmailCode: true, passwordReset: true }));
    at("/console", <SignIn onSignedIn={() => undefined} />);
    expect(await screen.findByRole("button", { name: "Email me a sign-in code" })).toBeTruthy();
    expect(screen.queryByLabelText("Password")).toBeNull();
    expect(screen.queryByRole("button", { name: "Forgot your password?" })).toBeNull();
  });

  it("keeps the box when nothing else could be offered, rather than a page with no way in", async () => {
    fakeKit(withBrand({ signInPassword: false, signUp: true }));
    at("/console", <SignIn onSignedIn={() => undefined} />);
    expect(await screen.findByRole("link", { name: "Create an account" })).toBeTruthy(); // the brand has arrived
    expect(screen.getByLabelText("Password")).toBeTruthy();
  });

  it("staff keep theirs on the admin panel's sign-in", async () => {
    fakeKit(withBrand({ signInPassword: false, signInEmailCode: true, passwordReset: true }));
    at("/admin", <SignIn onSignedIn={() => undefined} offer={["reset"]} />);
    expect(await screen.findByRole("button", { name: "Forgot your password?" })).toBeTruthy();
    expect(screen.getByLabelText("Password")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Email me a sign-in code" })).toBeNull();
  });
});

describe("a sign-up confirmed by an emailed code", () => {
  const field = (name: string, label: string, type = "text"): SetupField => ({ name, label, type, required: true, value: null, help: null, options: [], showWhen: null, secretSet: false }) as SetupField;
  const form: SignupForm = { open: true, emailCode: true, phoneFields: null, terms: null,
    fields: [field("name", "Your name"), field("email", "Work email", "email"), field("accountName", "Company or account name")] };

  function fill() {
    fireEvent.change(screen.getByLabelText("Your name"), { target: { value: "Cy" } });
    fireEvent.change(screen.getByLabelText("Work email"), { target: { value: " cy@clinic.example " } });
    fireEvent.change(screen.getByLabelText("Company or account name"), { target: { value: "Clinic Co" } });
    fireEvent.click(screen.getByRole("button", { name: "Create an account" }));
  }

  it("asks for the code, then signs in and goes to the console", async () => {
    const sent = fakeKit({
      "GET /api/kit/signup": form,
      "POST /api/kit/signup": () => Response.json({ message: "Check your email: we sent a code." }, { status: 202 }),
      "POST /api/kit/signup/email/verify": (b: unknown) => (b as { code: string }).code === "424242"
        ? Response.json({ state: "active", user, message: null })
        : Response.json({ message: "x", errors: { code: ["This code is wrong."] } }, { status: 422 }),
    });
    at("/signup", <SignUp />);
    await screen.findByLabelText("Work email");
    fill();
    expect(await screen.findByText("Check your email: we sent a code.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Your code"), { target: { value: "000000" } });
    fireEvent.click(screen.getByRole("button", { name: "Finish signing up" }));
    expect(await screen.findByText("This code is wrong.")).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/signup");
    fireEvent.change(screen.getByLabelText("Your code"), { target: { value: " 424242 " } });
    fireEvent.click(screen.getByRole("button", { name: "Finish signing up" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console"));
    expect(sent.filter((s) => s.path === "/api/kit/signup/email/verify").pop()?.body).toEqual({ email: "cy@clinic.example", code: "424242" });
  });

  it("says when it waits for approval, and a new code goes back to the form", async () => {
    fakeKit({
      "GET /api/kit/signup": form,
      "POST /api/kit/signup": () => Response.json({ message: "Check your email." }, { status: 202 }),
      "POST /api/kit/signup/email/verify": () => Response.json({ state: "waiting", user: null, message: "Waiting for approval." }),
    });
    at("/signup", <SignUp />);
    await screen.findByLabelText("Work email");
    fill();
    fireEvent.click(await screen.findByRole("button", { name: "Send a new code" }));
    expect((await screen.findByLabelText("Work email") as HTMLInputElement).value).toBe("cy@clinic.example");
    fireEvent.click(screen.getByRole("button", { name: "Create an account" }));
    fireEvent.change(await screen.findByLabelText("Your code"), { target: { value: "424242" } });
    fireEvent.click(screen.getByRole("button", { name: "Finish signing up" }));
    expect(await screen.findByText("Waiting for approval.")).toBeTruthy();
  });

  it("a link sign-up only says to check the email", async () => {
    fakeKit({
      "GET /api/kit/signup": { ...form, emailCode: false },
      "POST /api/kit/signup": () => Response.json({ message: "Check your email: we sent a link." }, { status: 202 }),
    });
    at("/signup", <SignUp />);
    await screen.findByLabelText("Work email");
    fill();
    expect(await screen.findByText("Check your email: we sent a link.")).toBeTruthy();
    expect(screen.queryByLabelText("Your code")).toBeNull();
  });
});

describe("a phone sign-up says how the code comes", () => {
  it("on its button", async () => {
    const phone: SetupField = { name: "phone", label: "Mobile number", type: "tel", required: true, value: null, help: null, options: [], showWhen: null, secretSet: false } as SetupField;
    fakeKit({
      ...withBrand({ signInCodeChannel: "whatsapp" }),
      "GET /api/kit/signup": { open: true, emailCode: false, terms: null, fields: [], phoneFields: [phone] },
    });
    at("/signup", <SignUp />);
    fireEvent.click(await screen.findByRole("button", { name: "Sign up with a phone instead" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Send me a code on WhatsApp" })).toBeTruthy());
  });
});

describe("admin: sign-ups", () => {
  it("offers an emailed code by its own word", async () => {
    const data: SignupAdmin = {
      settings: { mode: "open", domains: [], methods: ["password", "email_code"], fields: [], terms: null, trialCredit: null },
      options: { modes: ["closed", "open", "approval", "domains"], methods: ["password", "magic_link", "email_code", "phone_code"], fieldTypes: ["text"], maxFields: 10 },
      emailOn: true, currency: null, open: true, waiting: [], recent: [],
    };
    fakeKit({ "GET /api/kit/admin/signups": data });
    at("/admin/signups", <SignupsPage />);
    expect((await screen.findByLabelText("A code emailed each time") as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText("A link emailed each time") as HTMLInputElement).checked).toBe(false);
  });
});

describe("admin: sign-in providers", () => {
  const sso: SsoSettings = { redirectUri: "https://voice.acme.example/api/kit/oidc/callback", maxProviders: 5, providers: [], presets: [
    { value: "google", label: "Google", issuer: "https://accounts.google.com", help: null },
    { value: "microsoft", label: "Microsoft", issuer: "https://login.microsoftonline.com/common/v2.0", help: "Add the optional claims email and xms_edov." },
  ] };

  it("offers Microsoft with its name and what it needs, and keeps a name the operator typed", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/sso": sso,
      "POST /api/kit/admin/sso/providers": () => Response.json(sso, { status: 201 }),
    });
    at("/admin/sso", <SsoPage />);
    const provider = await screen.findByLabelText("Provider") as HTMLSelectElement;
    const name = screen.getByLabelText("Button name") as HTMLInputElement;
    expect(name.value).toBe("Google");
    expect(screen.queryByText("Add the optional claims email and xms_edov.")).toBeNull();
    fireEvent.change(provider, { target: { value: "microsoft" } });
    expect(name.value).toBe("Microsoft");
    expect(screen.getByText("Add the optional claims email and xms_edov.")).toBeTruthy();
    fireEvent.change(name, { target: { value: "Work account" } });
    fireEvent.change(provider, { target: { value: "google" } });
    expect(name.value).toBe("Work account");
    fireEvent.change(provider, { target: { value: "microsoft" } });
    fireEvent.change(screen.getByLabelText("Client ID"), { target: { value: "kit-client" } });
    fireEvent.change(screen.getByLabelText("Client secret"), { target: { value: "s3cret" } });
    fireEvent.click(screen.getByRole("button", { name: "Add" }));
    await waitFor(() => expect(sent.find((s) => s.method === "POST")?.body).toEqual(
      { preset: "microsoft", issuer: null, name: "Work account", clientId: "kit-client", clientSecret: "s3cret" }));
  });
});
