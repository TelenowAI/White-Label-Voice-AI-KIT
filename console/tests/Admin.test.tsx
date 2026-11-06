/**
 * The admin panel (admin/): signed out it asks for a sign-in; signed in it lists the customers with whether the voice
 * platform has each one's latest change, adds one, suspends and resumes, retries a failed one, pages, shows the
 * backend's refusal (a non-staff user's 403, a refused name), and signs out.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Customer, CustomerPage } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { brand, fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const owner = { id: 1, name: "Op", email: "op@example.com", role: "owner" };

function customer(over: Partial<Customer> & { id: string; name: string }): Customer {
  return {
    status: "active", syncState: "synced", syncError: null, telenowSubAccountId: null, balance: "0", priceListId: null,
    maxConcurrentCalls: null, monthlyCapUsd: null, closeRequestedAt: null, closedAt: null,
    createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-29T10:00:00Z", ...over,
  };
}

function page(customers: Customer[], over: Partial<CustomerPage> = {}): CustomerPage {
  return { customers, total: customers.length, page: 1, perPage: 50, ...over };
}

function admin(path = "/admin/customers") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/*" element={<AdminArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

const rowOf = (name: string) => screen.getByText(name).closest("tr")!;

describe("the admin panel", () => {
  it("asks a signed-out visitor to sign in, then shows the customers", async () => {
    let user: typeof owner | null = null;
    fakeKit({
      "GET /api/kit/session": () => Response.json({ user }),
      "POST /api/kit/session": () => Response.json({ user: (user = owner) }),
      "GET /api/kit/admin/customers?page=1": page([customer({ id: "a", name: "Acme Dental" })]),
    });
    admin();
    expect(await screen.findByText("The admin panel is for the platform's staff.")).toBeTruthy();

    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "op@example.com" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "pw" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("heading", { name: "Customers" })).toBeTruthy();
    expect(await screen.findByText("Acme Dental")).toBeTruthy();
  });

  it("offers a signed-out staff member only the password reset, never a customer's sign-in link or sign-up", async () => {
    fakeKit({ "GET /api/kit/session": { user: null }, "GET /api/kit/brand": { ...brand, passwordReset: true, signUp: true, signInLink: true } });
    admin();
    expect(await screen.findByRole("button", { name: "Forgot your password?" })).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Email me a sign-in link" })).toBeNull();
    expect(screen.queryByRole("link", { name: "Create an account" })).toBeNull();
  });

  it("says, per customer, whether the voice platform has the latest change and why not", async () => {
    fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": page([
        customer({ id: "a", name: "Settled" }),
        customer({ id: "b", name: "Still saving", syncState: "pending", syncError: "Could not reach the platform" }),
        customer({ id: "c", name: "Refused", syncState: "failed", syncError: "this API key's role (developer) cannot do this" }),
      ]),
    });
    admin();
    await screen.findByText("Settled");

    expect(within(rowOf("Settled")).getByText("In step")).toBeTruthy();
    expect(within(rowOf("Settled")).queryByRole("button", { name: "Retry" })).toBeNull();
    expect(within(rowOf("Still saving")).getByText("Saving…")).toBeTruthy();
    expect(within(rowOf("Still saving")).getByText("Could not reach the platform")).toBeTruthy();
    expect(within(rowOf("Refused")).getByText("Failed")).toBeTruthy();
    expect(within(rowOf("Refused")).getByRole("button", { name: "Retry" })).toBeTruthy();
  });

  it("names a closing or closed customer as such and offers neither suspending nor resuming it", async () => {
    fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": page([
        customer({ id: "a", name: "Live" }),
        customer({ id: "b", name: "Paused", status: "suspended" }),
        customer({ id: "c", name: "Going", status: "closing", syncState: "pending", syncError: "Waiting for the last calls to be read and charged." }),
        customer({ id: "d", name: "Gone", status: "closed" }),
      ]),
    });
    admin();
    await screen.findByText("Live");
    expect(within(rowOf("Live")).getByRole("button", { name: "Suspend" })).toBeTruthy();
    expect(within(rowOf("Paused")).getByRole("button", { name: "Resume" })).toBeTruthy();
    for (const [name, word] of [["Going", "Closing"], ["Gone", "Closed"]]) {
      expect(within(rowOf(name)).getByText(word)).toBeTruthy();
      expect(within(rowOf(name)).queryByRole("button", { name: "Suspend" })).toBeNull();
      expect(within(rowOf(name)).queryByRole("button", { name: "Resume" })).toBeNull();
    }
    expect(within(rowOf("Going")).getByText("Waiting for the last calls to be read and charged.")).toBeTruthy();
  });

  it("adds a customer and shows it at the top", async () => {
    let list = [customer({ id: "a", name: "Older" })];
    const sent = fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": () => Response.json(page(list)),
      "POST /api/kit/admin/customers": (body: unknown) => {
        const made = customer({ id: "n", name: (body as { name: string }).name });
        list = [made, ...list];
        return Response.json(made, { status: 201 });
      },
    });
    admin();
    await screen.findByText("Older");

    fireEvent.change(screen.getByLabelText("Customer name"), { target: { value: "Acme Dental" } });
    fireEvent.click(screen.getByRole("button", { name: "Add customer" }));

    await screen.findByText("Acme Dental");
    expect(screen.getAllByRole("row").slice(1).map((r) => within(r).getAllByRole("cell")[0].textContent)).toEqual(["Acme Dental", "Older"]);
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ name: "Acme Dental" });
    expect((screen.getByLabelText("Customer name") as HTMLInputElement).value).toBe("");
  });

  it("shows a refused name beside the field", async () => {
    fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": page([]),
      "POST /api/kit/admin/customers": () => Response.json({ message: "The name field is required.", errors: { name: ["The name field is required."] } }, { status: 422 }),
    });
    admin();
    await screen.findByText("No customers yet.");
    fireEvent.click(screen.getByRole("button", { name: "Add customer" }));

    const input = screen.getByLabelText("Customer name");
    await vi.waitFor(() => expect(input.getAttribute("aria-invalid")).toBe("true"));
    expect(document.getElementById(input.getAttribute("aria-describedby")!)?.textContent).toBe("The name field is required.");
  });

  it("suspends and resumes a customer", async () => {
    const sent = fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": page([customer({ id: "a", name: "Acme Dental" })]),
      "POST /api/kit/admin/customers/a/suspend": customer({ id: "a", name: "Acme Dental", status: "suspended" }),
      "POST /api/kit/admin/customers/a/resume": customer({ id: "a", name: "Acme Dental" }),
    });
    admin();
    await screen.findByText("Acme Dental");

    fireEvent.click(within(rowOf("Acme Dental")).getByRole("button", { name: "Suspend" }));
    expect(await within(rowOf("Acme Dental")).findByText("Suspended")).toBeTruthy();
    fireEvent.click(within(rowOf("Acme Dental")).getByRole("button", { name: "Resume" }));
    expect(await within(rowOf("Acme Dental")).findByText("Active")).toBeTruthy();
    expect(sent.filter((s) => s.method === "POST").map((s) => s.path)).toEqual([
      "/api/kit/admin/customers/a/suspend",
      "/api/kit/admin/customers/a/resume",
    ]);
  });

  it("sends a failed customer's change again", async () => {
    fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": page([customer({ id: "c", name: "Refused", syncState: "failed", syncError: "no" })]),
      "POST /api/kit/admin/customers/c/retry": customer({ id: "c", name: "Refused" }),
    });
    admin();
    await screen.findByText("Refused");
    fireEvent.click(within(rowOf("Refused")).getByRole("button", { name: "Retry" }));
    expect(await within(rowOf("Refused")).findByText("In step")).toBeTruthy();
    expect(within(rowOf("Refused")).queryByRole("button", { name: "Retry" })).toBeNull();
  });

  it("pages through more customers than a page holds", async () => {
    fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": page([customer({ id: "a", name: "Newest" })], { total: 3, perPage: 1 }),
      "GET /api/kit/admin/customers?page=2": page([customer({ id: "b", name: "Middle" })], { total: 3, perPage: 1, page: 2 }),
    });
    admin();
    await screen.findByText("Newest");
    expect(screen.getByText("Page 1 of 3")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Next" }));
    expect(await screen.findByText("Middle")).toBeTruthy();
    expect(screen.getByText("Page 2 of 3")).toBeTruthy();
  });

  it("shows the backend's refusal to a user who is not staff", async () => {
    fakeKit({
      "GET /api/kit/session": { user: { ...owner, role: null } },
      "GET /api/kit/admin/customers?page=1": () => Response.json({ message: "Only the operator's staff can do this." }, { status: 403 }),
    });
    admin();
    expect((await screen.findByRole("alert")).textContent).toBe("Only the operator's staff can do this.");
  });

  it("signs out back to the sign-in form", async () => {
    const sent = fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/customers?page=1": page([]),
      "DELETE /api/kit/session": () => new Response(null, { status: 204 }),
    });
    admin();
    fireEvent.click(await screen.findByRole("button", { name: "Sign out" }));
    expect(await screen.findByRole("heading", { name: "Sign in to continue" })).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(true);
  });
});
