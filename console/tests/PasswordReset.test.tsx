/**
 * Password reset in the console: sign-in offers "Forgot your password?" only when the brand says the platform can send
 * email (and never in the wizard); asking sends the address and shows the backend's answer as it comes; a reset link's
 * page names the account, shows a refused password beside the field, and once set goes on to the admin panel for staff
 * or the console for everyone else; a dead link says so, with the way back.
 */
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import { ResetPassword } from "../src/setup/ResetPassword";
import { SignIn } from "../src/setup/SignIn";
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

describe("forgot your password", () => {
  it("is offered only when the platform can send email", async () => {
    const sent = fakeKit({ "GET /api/kit/brand": { ...brand, passwordReset: false } });
    at("/console", <SignIn onSignedIn={() => undefined} />);
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/brand")).toBe(true));
    expect(screen.queryByRole("button", { name: "Forgot your password?" })).toBeNull();
  });

  it("is never offered in the wizard, which does not ask", async () => {
    const sent = fakeKit({ "GET /api/kit/brand": { ...brand, passwordReset: true } });
    at("/setup", <SignIn offer={[]} onSignedIn={() => undefined} />);
    expect(screen.getByRole("button", { name: "Sign in" })).toBeTruthy();
    expect(sent.some((s) => s.path === "/api/kit/brand")).toBe(false);
    expect(screen.queryByRole("button", { name: "Forgot your password?" })).toBeNull();
  });

  it("sends the address typed and shows the backend's answer", async () => {
    const sent = fakeKit({
      "GET /api/kit/brand": { ...brand, passwordReset: true },
      "POST /api/kit/password/forgot": () => Response.json({ message: "If an account uses that address, a link is on its way." }, { status: 202 }),
    });
    at("/console", <SignIn onSignedIn={() => undefined} />);
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "ana@acme.example" } });
    fireEvent.click(await screen.findByRole("button", { name: "Forgot your password?" }));
    expect(screen.getByLabelText("Email")).toHaveProperty("value", "ana@acme.example");
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: " ana@acme.example " } });
    fireEvent.click(screen.getByRole("button", { name: "Email me a link" }));
    expect((await screen.findByRole("status")).textContent).toBe("If an account uses that address, a link is on its way.");
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ email: "ana@acme.example" });
    fireEvent.click(screen.getByRole("button", { name: "Back to signing in" }));
    expect(screen.getByRole("button", { name: "Sign in" })).toBeTruthy();
  });
});

describe("a reset link", () => {
  it("names the account, shows a refused password, and goes on to the admin panel for staff", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/password/reset/tok": { email: "op@acme.example", expiresAt: "2026-10-30T11:00:00Z" },
      "POST /api/kit/password/reset/tok": () => (++tries === 1
        ? Response.json({ message: "x", errors: { password: ["The Password field must be at least 12 characters."] } }, { status: 422 })
        : Response.json({ user: { id: 1, name: "Op", email: "op@acme.example", role: "owner" } })),
    });
    at("/reset/tok", <ResetPassword />, "/reset/:token");
    expect(await screen.findByText("For op@acme.example. Once it is set, you are signed in and your other sessions end.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("New password"), { target: { value: "short" } });
    fireEvent.click(screen.getByRole("button", { name: "Set password and sign in" }));
    expect(await screen.findByText("The Password field must be at least 12 characters.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("New password"), { target: { value: "a long new password 1" } });
    fireEvent.click(screen.getByRole("button", { name: "Set password and sign in" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/admin"));
    expect(sent.filter((s) => s.method === "POST").map((s) => s.body)).toEqual([{ password: "short" }, { password: "a long new password 1" }]);
  });

  it("goes on to the console for a customer", async () => {
    fakeKit({
      "GET /api/kit/password/reset/tok": { email: "ana@acme.example", expiresAt: "2026-10-30T11:00:00Z" },
      "POST /api/kit/password/reset/tok": () => Response.json({ user: { id: 2, name: "Ana", email: "ana@acme.example", role: null } }),
    });
    at("/reset/tok", <ResetPassword />, "/reset/:token");
    fireEvent.change(await screen.findByLabelText("New password"), { target: { value: "a long new password 1" } });
    fireEvent.click(screen.getByRole("button", { name: "Set password and sign in" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console"));
  });

  it("says a dead link no longer works, with the way back", async () => {
    fakeKit({ "GET /api/kit/password/reset/old": () => Response.json({ message: "This link no longer works. Ask for a new one." }, { status: 404 }) });
    at("/reset/old", <ResetPassword />, "/reset/:token");
    expect((await screen.findByRole("alert")).textContent).toBe("This link no longer works. Ask for a new one.");
    expect(screen.queryByLabelText("New password")).toBeNull();
    expect(screen.getByRole("link", { name: "Back to signing in" }).getAttribute("href")).toBe("/console");
  });
});
