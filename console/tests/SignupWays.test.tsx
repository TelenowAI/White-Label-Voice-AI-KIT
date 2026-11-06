/**
 * Signing up with a phone or a sign-in provider in the console (setup/SignUp.tsx; owner 11-01), and how a phone-only
 * person shows elsewhere: the phone form is offered only when the backend declares it, sends the number trimmed and
 * the operator's answers nested, then the texted code — signed in and on to the console, or told it waits; the
 * providers are offered on the sign-up page as start addresses; someone a provider vouched for finishes with the address
 * it confirmed shown, not asked, and a run-out offer says so; the sign-in page names a not-allowed address; Your account
 * says there is no email and never offers removing the only way in; the team and Admin → Sign-ups show who signed up by
 * phone or with which provider.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AccountPage } from "../src/account/AccountPage";
import { AdminArea } from "../src/admin/AdminArea";
import type { SetupField, SignupAdmin, SignupForm, SignupSummary, Team } from "../src/api";
import { SignIn } from "../src/setup/SignIn";
import { SignUp } from "../src/setup/SignUp";
import { TeamPanel } from "../src/team/TeamPanel";
import { brand, fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function Where() {
  const { pathname, search } = useLocation();
  return <span data-testid="where">{pathname + search}</span>;
}

function at(path: string, element: ReactElement) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/signup" element={<>{element}<Where /></>} />
        <Route path="*" element={<>{path.startsWith("/signup") ? null : element}<Where /></>} />
      </Routes>
    </MemoryRouter>,
  );
}

function field(over: Partial<SetupField> & { name: string; label: string }): SetupField {
  return { type: "text", required: true, value: null, help: null, options: [], showWhen: null, secretSet: false, ...over };
}

const emailFields = [field({ name: "name", label: "Your name" }), field({ name: "email", label: "Work email", type: "email" }),
  field({ name: "accountName", label: "Company or account name" })];
const phoneFields = [field({ name: "name", label: "Your name" }), field({ name: "phone", label: "Mobile number", type: "tel" }),
  field({ name: "accountName", label: "Company or account name" }), field({ name: "answers.size", label: "Team size", required: false })];
const form: SignupForm = { open: true, emailCode: false, fields: emailFields, terms: null, phoneFields };

describe("signing up with a phone", () => {
  it("is offered only when the backend declares it", async () => {
    fakeKit({ "GET /api/kit/signup": { ...form, phoneFields: null } });
    at("/signup", <SignUp />);
    await screen.findByRole("form", { name: "Create an account" });
    expect(screen.queryByRole("button", { name: "Sign up with a phone instead" })).toBeNull();
  });

  it("texts a code for the phone form, then finishes with it and goes on to the console", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/signup": form,
      "POST /api/kit/signup/phone": () => Response.json({ message: "We are texting a code to +919876543210. Enter it to finish signing up." }, { status: 202 }),
      "POST /api/kit/signup/phone/verify": () => (++tries === 1
        ? Response.json({ message: "x", errors: { code: ["This code is wrong, used or expired. Ask for a new one."] } }, { status: 422 })
        : Response.json({ state: "active", user: { id: 2, name: "Ana", email: null, role: null }, message: null })),
    });
    at("/signup", <SignUp />);
    fireEvent.click(await screen.findByRole("button", { name: "Sign up with a phone instead" }));
    const f = screen.getByRole("form", { name: "Create an account" });
    expect(within(f).queryByLabelText("Work email")).toBeNull();
    expect((within(f).getByLabelText("Mobile number") as HTMLInputElement).type).toBe("tel");
    fireEvent.change(within(f).getByLabelText("Your name"), { target: { value: " Ana " } });
    fireEvent.change(within(f).getByLabelText("Mobile number"), { target: { value: " +91 98765 43210 " } });
    fireEvent.change(within(f).getByLabelText("Company or account name"), { target: { value: "Acme Dental" } });
    fireEvent.change(within(f).getByLabelText("Team size"), { target: { value: "1-10" } });
    fireEvent.click(within(f).getByRole("button", { name: "Text me a code" }));
    expect((await screen.findByRole("status")).textContent).toBe("We are texting a code to +919876543210. Enter it to finish signing up.");
    expect(sent.find((s) => s.path === "/api/kit/signup/phone")?.body).toEqual({ name: "Ana", phone: "+91 98765 43210", accountName: "Acme Dental",
      answers: { size: "1-10" } });

    fireEvent.change(screen.getByLabelText("Your code"), { target: { value: " 111111 " } });
    fireEvent.click(screen.getByRole("button", { name: "Finish signing up" }));
    expect(await screen.findByText("This code is wrong, used or expired. Ask for a new one.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Your code"), { target: { value: "483920" } });
    fireEvent.click(screen.getByRole("button", { name: "Finish signing up" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console"));
    expect(sent.filter((s) => s.path === "/api/kit/signup/phone/verify").map((s) => s.body)).toEqual([
      { phone: "+91 98765 43210", code: "111111" }, { phone: "+91 98765 43210", code: "483920" }]);
  });

  it("says when it waits for approval, and a new code goes back to the form with its values", async () => {
    fakeKit({
      "GET /api/kit/signup": form,
      "POST /api/kit/signup/phone": () => Response.json({ message: "We are texting a code." }, { status: 202 }),
      "POST /api/kit/signup/phone/verify": () => Response.json({ state: "waiting", user: null, message: "Thanks, your number is confirmed. Your sign-up is waiting for approval." }),
    });
    at("/signup", <SignUp />);
    fireEvent.click(await screen.findByRole("button", { name: "Sign up with a phone instead" }));
    fireEvent.change(screen.getByLabelText("Mobile number"), { target: { value: "+919876543210" } });
    fireEvent.click(screen.getByRole("button", { name: "Text me a code" }));
    fireEvent.click(await screen.findByRole("button", { name: "Send a new code" }));
    expect((screen.getByLabelText("Mobile number") as HTMLInputElement).value).toBe("+919876543210");
    fireEvent.click(screen.getByRole("button", { name: "Text me a code" }));
    fireEvent.change(await screen.findByLabelText("Your code"), { target: { value: "483920" } });
    fireEvent.click(screen.getByRole("button", { name: "Finish signing up" }));
    expect(await screen.findByText("Thanks, your number is confirmed. Your sign-up is waiting for approval.")).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/signup");
  });
});

describe("signing up with a sign-in provider", () => {
  it("offers each provider on the sign-up page as its start address", async () => {
    const go = vi.fn();
    fakeKit({ "GET /api/kit/brand": { ...brand, signUp: true, signInWith: [{ id: "g1", name: "Google" }] }, "GET /api/kit/signup": form });
    at("/signup", <SignUp go={go} />);
    fireEvent.click(await screen.findByRole("button", { name: "Continue with Google" }));
    expect(go).toHaveBeenCalledWith("/api/kit/oidc/g1/start");
  });

  it("finishes with the address the provider confirmed, shown and not asked", async () => {
    const sent = fakeKit({
      "GET /api/kit/signup/provider": { provider: "Google", email: "cy@clinic.example", terms: null,
        fields: [field({ name: "name", label: "Your name", value: "Cy Lee" }), field({ name: "accountName", label: "Company or account name" })] },
      "POST /api/kit/signup/provider": () => Response.json({ state: "active", user: { id: 3, name: "Cy Lee", email: "cy@clinic.example", role: null }, message: null }),
    });
    at("/signup?with=provider", <SignUp />);
    expect(await screen.findByText("You are signing up as cy@clinic.example, confirmed by Google.")).toBeTruthy();
    expect((screen.getByLabelText("Your name") as HTMLInputElement).value).toBe("Cy Lee");
    expect(screen.queryByLabelText("Work email")).toBeNull();
    expect(sent.some((s) => s.path === "/api/kit/signup"), "not the email form").toBe(false);
    fireEvent.change(screen.getByLabelText("Company or account name"), { target: { value: " Clinic Co " } });
    fireEvent.click(screen.getByRole("button", { name: "Create an account" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console"));
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ name: "Cy Lee", accountName: "Clinic Co" });
  });

  it("says when the offer has run out, with the way to sign up again", async () => {
    fakeKit({ "GET /api/kit/signup/provider": () => Response.json({ message: "This sign-up is no longer open." }, { status: 404 }) });
    at("/signup?with=provider", <SignUp />);
    expect((await screen.findByRole("alert")).textContent).toBe("This sign-up is no longer open.");
    expect(screen.queryByRole("form")).toBeNull();
    expect(screen.getByRole("link", { name: "Sign up again" }).getAttribute("href")).toBe("/signup");
  });

  it("says on the sign-in page when sign-ups are not open to that address", async () => {
    fakeKit({ "GET /api/kit/brand": { ...brand, signInWith: [{ id: "g1", name: "Google" }] } });
    at("/console?signInError=not_allowed", <SignIn onSignedIn={() => undefined} />);
    expect((await screen.findByRole("alert")).textContent).toBe("Sign-ups are not open to that email address. Sign up another way, or ask for an invite.");
  });
});

describe("someone who signed up with a phone", () => {
  it("has no email on Your account and is never offered removing the only way in, only changing it", async () => {
    fakeKit({ "GET /api/kit/account": { name: "Ana", email: null, phone: "+919876543210", texts: true } });
    at("/console/account", <AccountPage />);
    expect(await screen.findByText("None: you sign in with a code sent to your number")).toBeTruthy();
    expect(screen.getByTestId("phone").textContent).toBe("+919876543210");
    expect(screen.getByText("This number is how you sign in.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Remove" })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Change number" }));
    expect(screen.getByRole("form", { name: "Add a phone number" })).toBeTruthy();
  });

  it("shows in the team as signed up by phone", async () => {
    const team: Team = { roles: ["owner", "member"], canManage: false, invites: [],
      members: [{ userId: 2, name: "Ana", email: null, role: "owner", joinedAt: "2026-11-01T10:00:00Z" }] };
    render(<TeamPanel calls={{ load: async () => team, invite: vi.fn(), revoke: vi.fn(), remove: vi.fn() }} />);
    const row = (await screen.findByText("Ana")).closest("tr")!;
    expect(within(row).getByText("Signed up by phone")).toBeTruthy();
  });

  it("shows in Admin → Sign-ups by its number, and a provider's by its name", async () => {
    const summary = (over: Partial<SignupSummary> & { id: string }): SignupSummary => ({ email: null, phone: null, provider: null, name: "Ana",
      accountName: "Acme Dental", status: "waiting", answers: {}, termsVersion: null, reason: null, workspaceId: null,
      createdAt: "2026-11-01T10:00:00Z", verifiedAt: "2026-11-01T10:05:00Z", decidedAt: null, ...over });
    const data: SignupAdmin = {
      settings: { mode: "approval", domains: [], methods: ["password", "phone_code"], fields: [], terms: null, trialCredit: null },
      options: { modes: ["closed", "open", "approval", "domains"], methods: ["password", "magic_link", "phone_code"], fieldTypes: ["text"], maxFields: 10 },
      emailOn: true, currency: null, open: true,
      waiting: [summary({ id: "s1", phone: "+919876543210" })],
      recent: [summary({ id: "s2", email: "cy@clinic.example", provider: "Google", status: "done", name: "Cy", accountName: "Clinic Co" })],
    };
    fakeKit({ "GET /api/kit/session": { user: { id: 1, name: "Op", email: "op@voice.example", role: "owner" } }, "GET /api/kit/admin/signups": data });
    render(
      <MemoryRouter initialEntries={["/admin/signups"]}>
        <Routes>
          <Route path="/admin/*" element={<AdminArea />} />
        </Routes>
      </MemoryRouter>,
    );
    const waiting = (await screen.findByText("Acme Dental")).closest("li")!;
    expect(waiting.textContent).toContain("+919876543210 (by phone)");
    const recent = screen.getByRole("table", { name: "Recent sign-ups" });
    expect(within(recent).getByText("cy@clinic.example · with Google")).toBeTruthy();
    expect(screen.getByText(/people can also sign up with just a phone/)).toBeTruthy();
  });
});
