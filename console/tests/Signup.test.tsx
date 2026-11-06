/**
 * Signing up in the console: sign-in offers "Create an account" and "Email me a sign-in link" only when the brand says
 * so; the sign-up form is drawn from what the backend declares (its own fields, a choice, the terms box with its link),
 * sends the operator's answers nested and the rest trimmed, and shows the backend's word on each refused field; closed
 * says so; confirming an address is a click, then the console — or the word that it waits for approval; a dead link
 * says so; an emailed sign-in link signs in with a click.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { SetupField, SignupForm } from "../src/api";
import { SignIn } from "../src/setup/SignIn";
import { SignInWithLink } from "../src/setup/SignInWithLink";
import { SignUp } from "../src/setup/SignUp";
import { VerifySignup } from "../src/setup/VerifySignup";
import { brand, fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function at(path: string, element: ReactElement, route = "*") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={route} element={<>{element}<Where /></>} />
        <Route path="*" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  );
}

function field(over: Partial<SetupField> & { name: string; label: string }): SetupField {
  return { type: "text", required: true, value: null, help: null, options: [], showWhen: null, secretSet: false, ...over };
}

const form: SignupForm = {
  open: true,
  emailCode: false,
  phoneFields: null,
  terms: { url: "https://voice.example/terms", version: "2026-10" },
  fields: [
    field({ name: "name", label: "Your name" }),
    field({ name: "email", label: "Work email", type: "email" }),
    field({ name: "password", label: "Password", type: "password", help: "At least 12 characters." }),
    field({ name: "accountName", label: "Company or account name" }),
    field({ name: "answers.phone", label: "Phone" }),
    field({ name: "answers.size", label: "Team size", type: "select", required: false, options: [{ value: "1-10", label: "1-10" }, { value: "11-50", label: "11-50" }] }),
    field({ name: "terms", label: "I agree to the terms (version 2026-10)", type: "checkbox", help: "https://voice.example/terms" }),
  ],
};

describe("sign-in's other ways", () => {
  it("offers creating an account and an emailed link only when the brand says so", async () => {
    const sent = fakeKit({ "GET /api/kit/brand": { ...brand, signUp: false, signInLink: false } });
    at("/console", <SignIn onSignedIn={() => undefined} />);
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/brand")).toBe(true));
    expect(screen.queryByRole("link", { name: "Create an account" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Email me a sign-in link" })).toBeNull();
  });

  it("offers the admin panel's staff only the password reset", async () => {
    const sent = fakeKit({ "GET /api/kit/brand": { ...brand, passwordReset: true, signUp: true, signInLink: true } });
    at("/admin", <SignIn offer={["reset"]} onSignedIn={() => undefined} />);
    expect(await screen.findByRole("button", { name: "Forgot your password?" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Email me a sign-in link" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Create an account" })).toBeNull();
    expect(sent).toHaveLength(1);
  });

  it("asks for a sign-in link and shows the backend's answer", async () => {
    const sent = fakeKit({
      "GET /api/kit/brand": { ...brand, signUp: true, signInLink: true },
      "POST /api/kit/session/link": () => Response.json({ message: "If a customer account uses that address, a sign-in link is on its way." }, { status: 202 }),
    });
    at("/console", <SignIn onSignedIn={() => undefined} />);
    expect((await screen.findByRole("link", { name: "Create an account" })).getAttribute("href")).toBe("/signup");
    fireEvent.click(screen.getByRole("button", { name: "Email me a sign-in link" }));
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "ana@dental.example" } });
    fireEvent.click(screen.getByRole("button", { name: "Email me a link" }));
    expect((await screen.findByRole("status")).textContent).toBe("If a customer account uses that address, a sign-in link is on its way.");
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ email: "ana@dental.example" });
  });
});

describe("the sign-up form", () => {
  it("says so when sign-ups are closed", async () => {
    fakeKit({ "GET /api/kit/signup": { open: false, fields: [], terms: null } });
    at("/signup", <SignUp />);
    expect(await screen.findByText("Sign-ups are closed. Ask for an invite instead.")).toBeTruthy();
    expect(screen.queryByRole("form")).toBeNull();
    expect(screen.getByRole("link", { name: "Already have an account? Sign in" }).getAttribute("href")).toBe("/console");
  });

  it("draws the backend's form, sends the operator's answers nested, and shows its word on each field", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/signup": form,
      "POST /api/kit/signup": () => (++tries === 1
        ? Response.json({ message: "x", errors: { "answers.phone": ["The Phone field is required."], terms: ["The Terms field must be accepted."] } }, { status: 422 })
        : Response.json({ message: "Check your email: we sent a link to confirm your address." }, { status: 202 })),
    });
    at("/signup", <SignUp />);
    const f = await screen.findByRole("form", { name: "Create an account" });
    expect([...(within(f).getByLabelText("Team size") as HTMLSelectElement).options].map((o) => o.value)).toEqual(["1-10", "11-50"]);
    expect(within(f).getByRole("link", { name: "https://voice.example/terms" }).getAttribute("href")).toBe("https://voice.example/terms");
    fireEvent.change(within(f).getByLabelText("Your name"), { target: { value: " Ana " } });
    fireEvent.change(within(f).getByLabelText("Work email"), { target: { value: " ana@dental.example " } });
    fireEvent.change(within(f).getByLabelText("Password"), { target: { value: "a long password 9" } });
    fireEvent.change(within(f).getByLabelText("Company or account name"), { target: { value: "Acme Dental" } });
    fireEvent.click(within(f).getByRole("button", { name: "Create an account" }));
    expect(await within(f).findByText("The Phone field is required.")).toBeTruthy();
    expect(within(f).getByText("The Terms field must be accepted.")).toBeTruthy();

    fireEvent.change(within(f).getByLabelText("Phone"), { target: { value: "+14155550101" } });
    fireEvent.change(within(f).getByLabelText("Team size"), { target: { value: "11-50" } });
    fireEvent.click(within(f).getByLabelText("I agree to the terms (version 2026-10)"));
    fireEvent.click(within(f).getByRole("button", { name: "Create an account" }));
    expect((await screen.findByRole("status")).textContent).toBe("Check your email: we sent a link to confirm your address.");
    expect(screen.queryByRole("form")).toBeNull();
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({
      name: "Ana", email: "ana@dental.example", password: "a long password 9", accountName: "Acme Dental", terms: "1",
      answers: { phone: "+14155550101", size: "11-50" },
    });
  });
});

describe("a sign-up's confirmation link", () => {
  it("confirms with a click, then goes on to the console", async () => {
    const sent = fakeKit({
      "GET /api/kit/signup/verify/tok": { email: "ana@dental.example", name: "Ana", accountName: "Acme Dental" },
      "POST /api/kit/signup/verify/tok": () => Response.json({ state: "active", user: { id: 2, name: "Ana", email: "ana@dental.example", role: null }, message: null }),
    });
    at("/signup/verify/tok", <VerifySignup />, "/signup/verify/:token");
    expect(await screen.findByText("Confirm ana@dental.example to finish signing up Acme Dental.")).toBeTruthy();
    expect(sent.some((s) => s.method === "POST"), "opening the page spends nothing").toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Confirm my email" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console"));
  });

  it("says when the sign-up now waits for approval", async () => {
    fakeKit({
      "GET /api/kit/signup/verify/tok": { email: "ana@dental.example", name: "Ana", accountName: "Acme Dental" },
      "POST /api/kit/signup/verify/tok": () => Response.json({ state: "waiting", user: null, message: "Thanks, your address is confirmed. Your sign-up is waiting for approval." }),
    });
    at("/signup/verify/tok", <VerifySignup />, "/signup/verify/:token");
    fireEvent.click(await screen.findByRole("button", { name: "Confirm my email" }));
    expect((await screen.findByRole("status")).textContent).toBe("Thanks, your address is confirmed. Your sign-up is waiting for approval.");
    expect(screen.queryByRole("button", { name: "Confirm my email" })).toBeNull();
    expect(screen.getByTestId("where").textContent).toBe("/signup/verify/tok");
  });

  it("says a dead link no longer works, with the way to sign up again", async () => {
    fakeKit({ "GET /api/kit/signup/verify/old": () => Response.json({ message: "This link no longer works. Sign up again, or sign in." }, { status: 404 }) });
    at("/signup/verify/old", <VerifySignup />, "/signup/verify/:token");
    expect((await screen.findByRole("alert")).textContent).toBe("This link no longer works. Sign up again, or sign in.");
    expect(screen.getByRole("link", { name: "Sign up again" }).getAttribute("href")).toBe("/signup");
  });
});

describe("an emailed sign-in link", () => {
  it("signs in with a click, and a dead one says so", async () => {
    const sent = fakeKit({
      "POST /api/kit/session/link/tok": () => Response.json({ user: { id: 2, name: "Ana", email: "ana@dental.example", role: null } }),
      "POST /api/kit/session/link/old": () => Response.json({ message: "This link no longer works. Sign up again, or sign in." }, { status: 404 }),
    });
    at("/signin/tok", <SignInWithLink />, "/signin/:token");
    expect(sent).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console"));
    cleanup();
    at("/signin/old", <SignInWithLink />, "/signin/:token");
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect((await screen.findByRole("alert")).textContent).toBe("This link no longer works. Sign up again, or sign in.");
  });
});
