/**
 * Texts and phone numbers in the console (admin/SmsPage.tsx, setup/SignInWithCode.tsx, account/AccountPage.tsx):
 * - staff add a sender from its driver's own form — fields hidden by `showWhen` are not sent — with the number its test
 *   goes to, see the backend's word on a refused number or a failed test, text a test through the line, remove a sender
 *   only after asking, and see recent texts with every sender's reason;
 * - sign-in offers a texted code only when the brand says so (never in the admin panel): the number, the backend's
 *   same-for-everyone answer, then the code — a wrong one shows the backend's word on it — and the person is signed in;
 * - a person adds a number by proving it with its code, sees it, and removes it only after asking; with no texts on
 *   the platform, the page says so.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Account, SetupField, SmsSettings } from "../src/api";
import { AccountPage } from "../src/account/AccountPage";
import { SmsPage } from "../src/admin/SmsPage";
import { SignIn } from "../src/setup/SignIn";
import { brand, fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
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

function field(over: Partial<SetupField> & { name: string; label: string }): SetupField {
  return { type: "text", required: false, value: null, help: null, options: [], showWhen: null, secretSet: false, ...over };
}

const httpFields: SetupField[] = [
  field({ name: "url", label: "Send address", type: "url", required: true }),
  field({ name: "method", label: "Method", type: "select", required: true, value: "POST", options: [{ value: "POST", label: "POST" }, { value: "GET", label: "GET" }] }),
  field({ name: "format", label: "Send parameters as", type: "select", required: true, value: "json", options: [{ value: "json", label: "JSON" }, { value: "form", label: "A form" }],
    showWhen: { field: "method", values: ["POST"] } }),
  field({ name: "toParam", label: "Number parameter", required: true, value: "to" }),
  field({ name: "textParam", label: "Text parameter", required: true, value: "text" }),
  field({ name: "headerValue", label: "API key header value", type: "secret" }),
];

function sms(over: Partial<SmsSettings> = {}): SmsSettings {
  return { on: false, maxSenders: 5, drivers: [{ id: "http", name: "SMS provider (HTTP API)", fields: httpFields }], senders: [], recent: [], ...over };
}

const sender = { id: "s1", driver: "http", driverName: "SMS provider (HTTP API)", label: "POST sms.example.test", fields: httpFields };

describe("Admin → SMS", () => {
  it("adds a sender with the number its test goes to, sending only the fields shown", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/admin/sms": sms(),
      "POST /api/kit/admin/sms/senders": () => (++tries === 1
        ? Response.json({ message: "x", errors: { testNumber: ["A phone number with its country code, such as +1 415 555 0123."] } }, { status: 422 })
        : tries === 2
          ? Response.json({ message: "The test text was not sent: The provider answered 401: bad key" }, { status: 422 })
          : Response.json(sms({ on: true, senders: [sender] }), { status: 201 })),
    });
    at("/admin/sms", <SmsPage />);
    const form = await screen.findByRole("form", { name: "Add and send a test" });
    fireEvent.change(within(form).getByLabelText("Send address"), { target: { value: "https://sms.example.test/send" } });
    fireEvent.change(within(form).getByLabelText("Method"), { target: { value: "GET" } });
    expect(within(form).queryByLabelText("Send parameters as")).toBeNull();
    fireEvent.change(within(form).getByLabelText("Test number"), { target: { value: "98765 43210" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add and send a test" }));
    expect(await within(form).findByText("A phone number with its country code, such as +1 415 555 0123.")).toBeTruthy();

    fireEvent.change(within(form).getByLabelText("Test number"), { target: { value: " +91 98765 43210 " } });
    fireEvent.click(within(form).getByRole("button", { name: "Add and send a test" }));
    expect((await within(form).findByRole("alert")).textContent).toBe("The test text was not sent: The provider answered 401: bad key");
    fireEvent.click(within(form).getByRole("button", { name: "Add and send a test" }));
    expect(await screen.findByText("POST sms.example.test", { exact: false })).toBeTruthy();
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({
      driver: "http", testNumber: "+91 98765 43210", url: "https://sms.example.test/send", method: "GET", toParam: "to", textParam: "text", headerValue: "",
    });
  });

  it("texts a test through the line, removes a sender only after asking, and lists recent texts with every reason", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/sms": () => Response.json(sms(sent.some((s) => s.method === "DELETE") ? {} : {
        on: true, senders: [sender],
        recent: [{ id: "m1", kind: "sign_in_code", kindName: "Sign-in code", to: "+919876543210", status: "sent", attempts: 2,
          lastError: "Sender 1: The provider answered 503: down", createdAt: "2026-10-31T10:00:00Z", sentAt: "2026-10-31T10:00:01Z" }],
      })),
      "POST /api/kit/admin/sms/test": () => Response.json({ id: "m2", kind: "test", kindName: "Test", to: "+919876543210", status: "sent", attempts: 1,
        lastError: null, createdAt: "2026-10-31T10:01:00Z", sentAt: "2026-10-31T10:01:00Z" }),
      "DELETE /api/kit/admin/sms/senders/s1": () => new Response(null, { status: 204 }),
    });
    at("/admin/sms", <SmsPage />);
    const table = await screen.findByRole("table", { name: "Recent messages" });
    expect(within(table).getAllByRole("cell").map((c) => c.textContent).slice(0, 3))
      .toEqual(["Sign-in code", "Sent · 2 senders tried Sender 1: The provider answered 503: down", "+919876543210"]);

    const test = screen.getByRole("form", { name: "Send a test" });
    fireEvent.change(within(test).getByLabelText("Test number"), { target: { value: "+919876543210" } });
    fireEvent.click(within(test).getByRole("button", { name: "Send a test" }));
    expect((await within(test).findByRole("status")).textContent).toBe("Test sent to +919876543210.");
    expect(sent.find((s) => s.path === "/api/kit/admin/sms/test")?.body).toEqual({ testNumber: "+919876543210" });

    fireEvent.click(screen.getByRole("button", { name: "Remove" }));
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Yes, delete" }));
    expect(await screen.findByText("No sender yet.")).toBeTruthy();
  });
});

describe("signing in with a texted code", () => {
  it("is offered only when the brand says so, and never in the admin panel", async () => {
    fakeKit({ "GET /api/kit/brand": { ...brand, signInCode: false } });
    at("/console", <SignIn onSignedIn={() => undefined} />);
    await waitFor(() => expect(screen.getByRole("button", { name: "Sign in" })).toBeTruthy());
    await new Promise((r) => setTimeout(r, 10));
    expect(screen.queryByRole("button", { name: "Text me a sign-in code" })).toBeNull();
    cleanup();
    fakeKit({ "GET /api/kit/brand": { ...brand, passwordReset: true, signInCode: true } });
    at("/admin", <SignIn offer={["reset"]} onSignedIn={() => undefined} />);
    expect(await screen.findByRole("button", { name: "Forgot your password?" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Text me a sign-in code" })).toBeNull();
  });

  it("asks for the number, then the code, and signs the person in", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/brand": { ...brand, signInCode: true },
      "POST /api/kit/session/code": () => Response.json({ message: "If an account uses that number, a code is on its way." }, { status: 202 }),
      "POST /api/kit/session/code/verify": () => (++tries === 1
        ? Response.json({ message: "x", errors: { code: ["This code is wrong, used or expired. Ask for a new one."] } }, { status: 422 })
        : Response.json({ user: { id: 2, name: "Ana", email: "ana@dental.example", role: null } })),
    });
    const onSignedIn = vi.fn();
    at("/console", <SignIn onSignedIn={onSignedIn} />);
    fireEvent.click(await screen.findByRole("button", { name: "Text me a sign-in code" }));
    fireEvent.change(screen.getByLabelText("Phone number"), { target: { value: " +91 98765 43210 " } });
    fireEvent.click(screen.getByRole("button", { name: "Text me a code" }));
    expect((await screen.findByRole("status")).textContent).toBe("If an account uses that number, a code is on its way.");
    fireEvent.change(screen.getByLabelText("Code"), { target: { value: "000000" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    expect(await screen.findByText("This code is wrong, used or expired. Ask for a new one.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Code"), { target: { value: " 483920 " } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    await waitFor(() => expect(onSignedIn).toHaveBeenCalledWith({ id: 2, name: "Ana", email: "ana@dental.example", role: null }));
    expect(sent.filter((s) => s.method === "POST").map((s) => s.body)).toEqual([
      { phone: "+91 98765 43210" }, { phone: "+91 98765 43210", code: "000000" }, { phone: "+91 98765 43210", code: "483920" },
    ]);
  });
});

function account(over: Partial<Account> = {}): Account {
  return { name: "Ana", email: "ana@dental.example", phone: null, texts: true, ...over };
}

describe("your account's phone number", () => {
  it("is added by proving it with its code", async () => {
    const sent = fakeKit({
      "GET /api/kit/account": account(),
      "POST /api/kit/account/phone": () => Response.json({ message: "We texted a code to +919876543210." }, { status: 202 }),
      "POST /api/kit/account/phone/verify": () => Response.json(account({ phone: "+919876543210" })),
    });
    at("/console/account", <AccountPage />);
    expect(await screen.findByText("ana@dental.example")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Phone number"), { target: { value: " +91 98765 43210 " } });
    fireEvent.click(screen.getByRole("button", { name: "Text me a code" }));
    expect((await screen.findByRole("status")).textContent).toBe("We texted a code to +919876543210.");
    fireEvent.change(screen.getByLabelText("Code"), { target: { value: "483920" } });
    fireEvent.click(screen.getByRole("button", { name: "Confirm the number" }));
    expect((await screen.findByTestId("phone")).textContent).toBe("+919876543210");
    expect(sent.find((s) => s.path === "/api/kit/account/phone/verify")?.body).toEqual({ phone: "+91 98765 43210", code: "483920" });
  });

  it("is removed only after asking, and cannot be added without texts", async () => {
    const sent = fakeKit({
      "GET /api/kit/account": account({ phone: "+919876543210" }),
      "DELETE /api/kit/account/phone": () => Response.json(account()),
    });
    at("/console/account", <AccountPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Remove" }));
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    expect(screen.getByText("Remove +919876543210? You can no longer sign in with a code sent to it.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, remove" }));
    expect(await screen.findByRole("form", { name: "Add a phone number" })).toBeTruthy();
    cleanup();
    fakeKit({ "GET /api/kit/account": account({ texts: false }) });
    at("/console/account", <AccountPage />);
    expect(await screen.findByText("Phone numbers cannot be added yet.")).toBeTruthy();
    expect(screen.queryByRole("form")).toBeNull();
  });
});
