/**
 * Staff roles in the console, against a fake backend (admin/AdminArea.tsx, StaffPage.tsx, StaffInviteAccept.tsx,
 * AuditPage.tsx, CustomerActions.tsx; console/ConsoleArea.tsx's banner):
 * - the admin nav shows only the pages the role may use, and opens on the first of them;
 * - the owner invites someone (the link shown once when it was not emailed), changes a role, removes someone only
 *   after asking, and sees the backend's refusal; someone with an invite link joins and is in the admin panel;
 * - the audit log lists who did what, a customer linking to its page;
 * - a customer's actions are offered only to a role that may use them, and none but looking for a closing one; limits
 *   send empty as none; closing needs the name typed exactly; looking at its console opens the console, which says so,
 *   read-only, with a way back.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Customer, StaffList } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { AuditPage } from "../src/admin/AuditPage";
import { CustomerActions } from "../src/admin/CustomerActions";
import { CustomerWallet } from "../src/admin/CustomerWallet";
import { MayContext } from "../src/admin/may";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function admin(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/*" element={<AdminArea />} />
        <Route path="*" element={<Where />} />
      </Routes>
    </MemoryRouter>,
  );
}

const owner = { id: 1, name: "Olu", email: "olu@op.example", role: "owner",
  abilities: ["reports", "health", "customers_read", "customers_suspend", "customers_manage", "customers_limits", "customers_close", "view_as", "money", "settings", "payment_gateways", "staff", "audit"] };
const support = { id: 3, name: "Sam", email: "sam@op.example", role: "support", abilities: ["health", "customers_read", "customers_suspend"] };

function staffList(over: Partial<StaffList> = {}): StaffList {
  return {
    members: [{ id: 1, name: "Olu", email: "olu@op.example", role: "owner", you: true }, { id: 2, name: "Ada", email: "ada@op.example", role: "admin", you: false }],
    invites: [],
    roles: [{ value: "owner", label: "Owner", about: "Everything" }, { value: "admin", label: "Admin", about: "Most" }, { value: "support", label: "Support", about: "Customers" }],
    ...over,
  };
}

const acme: Customer = {
  id: "w-acme", name: "Acme Dental", status: "active", syncState: "synced", syncError: null, telenowSubAccountId: "s1", balance: "0", priceListId: null,
  maxConcurrentCalls: null, monthlyCapUsd: null, closeRequestedAt: null, closedAt: null, createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-29T10:00:00Z",
};

describe("staff roles in the admin panel", () => {
  it("shows a role only its pages, and opens on the first", async () => {
    fakeKit({ "GET /api/kit/session": { user: support }, "GET /api/kit/admin/customers?page=1": { customers: [], total: 0, page: 1, perPage: 50 } });
    admin("/admin");
    const nav = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(nav).getAllByRole("link").map((a) => a.textContent)).toEqual(["Customers", "Voice platform"]);
    expect(await screen.findByRole("heading", { level: 1, name: "Customers" })).toBeTruthy();
    cleanup();

    fakeKit({ "GET /api/kit/session": { user: owner }, "GET /api/kit/admin/staff": staffList() });
    admin("/admin/staff");
    const all = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(all).getByRole("link", { name: "Staff" })).toBeTruthy();
    expect(within(all).getByRole("link", { name: "Audit log" })).toBeTruthy();
  });

  it("lets the owner invite, change a role and remove someone after asking", async () => {
    const sent = fakeKit({
      "GET /api/kit/session": { user: owner },
      "GET /api/kit/admin/staff": staffList(),
      "POST /api/kit/admin/staff/invites": () => Response.json({ invite: { id: "i1", email: "bo@op.example", role: "support", expiresAt: "2026-11-03T10:00:00Z", createdAt: "2026-10-31T10:00:00Z" }, token: "tok-123", emailed: false }, { status: 201 }),
      "PUT /api/kit/admin/staff/2": { id: 2, name: "Ada", email: "ada@op.example", role: "support", abilities: [] },
      "DELETE /api/kit/admin/staff/2": () => new Response(null, { status: 204 }),
      "PUT /api/kit/admin/staff/1": () => Response.json({ message: "The team needs an owner: make someone else owner first." }, { status: 409 }),
    });
    admin("/admin/staff");
    const form = await screen.findByRole("form", { name: "Invite someone" });
    fireEvent.change(within(form).getByLabelText("Email"), { target: { value: " bo@op.example " } });
    fireEvent.change(within(form).getByLabelText("Role"), { target: { value: "support" } });
    fireEvent.click(within(form).getByRole("button", { name: "Invite" }));
    expect(((await within(form).findByLabelText("Invite link")) as HTMLInputElement).value).toBe(`${window.location.origin}/admin/invite/tok-123`);
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ email: "bo@op.example", role: "support" });

    fireEvent.change(screen.getByLabelText("Role of Ada"), { target: { value: "support" } });
    fireEvent.change(screen.getByLabelText("Role of Olu"), { target: { value: "admin" } });
    expect(await screen.findByText("The team needs an owner: make someone else owner first.")).toBeTruthy();
    const ada = document.querySelector('[data-member="2"]') as HTMLElement;
    fireEvent.click(within(ada).getByRole("button", { name: "Remove" }));
    expect(sent.some((s) => s.method === "DELETE"), "only after asking").toBe(false);
    fireEvent.click(within(ada).getByRole("button", { name: "Yes, remove" }));
    await screen.findAllByLabelText(/Role of/);
    expect(sent.filter((s) => s.method !== "GET").map((s) => `${s.method} ${s.path}`)).toEqual([
      "POST /api/kit/admin/staff/invites", "PUT /api/kit/admin/staff/2", "PUT /api/kit/admin/staff/1", "DELETE /api/kit/admin/staff/2",
    ]);
  });

  it("joins the team with an invite link", async () => {
    const sent = fakeKit({
      "GET /api/kit/staff-invites/tok-123": { email: "bo@op.example", role: "support", roleLabel: "Support", expiresAt: "2026-11-03T10:00:00Z", accountExists: false },
      "POST /api/kit/staff-invites/tok-123/accept": { user: support },
      "GET /api/kit/session": { user: null },
      "GET /api/kit/admin/customers?page=1": { customers: [], total: 0, page: 1, perPage: 50 },
      "GET /api/kit/staff-invites/dead": () => Response.json({ message: "This invite link is not valid any more. Ask for a new one." }, { status: 404 }),
    });
    admin("/admin/invite/tok-123");
    const form = await screen.findByRole("form", { name: "Join the team as Support" });
    fireEvent.change(within(form).getByLabelText("Your name"), { target: { value: "Bo" } });
    fireEvent.change(within(form).getByLabelText("Password"), { target: { value: "a long password 1" } });
    fireEvent.click(within(form).getByRole("button", { name: "Join" }));
    expect(await screen.findByRole("heading", { level: 1, name: "Customers" }), "in the admin panel, on the first page the role may use").toBeTruthy();
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ name: "Bo", password: "a long password 1" });
    cleanup();
    admin("/admin/invite/dead");
    expect((await screen.findByRole("alert")).textContent).toBe("This invite link is not valid any more. Ask for a new one.");
  });

  it("lists the audit log, a customer linking to its page", async () => {
    fakeKit({
      "GET /api/kit/admin/audit": { entries: [
        { id: 2, at: "2026-10-31T10:00:00Z", by: { id: 1, name: "Olu", email: "olu@op.example" }, action: "customer.suspended", label: "Suspended a customer",
          subject: { type: "customer", id: "w-acme", label: "Acme Dental" }, details: { reason: "unpaid" } },
        { id: 1, at: "2026-10-31T09:00:00Z", by: null, action: "staff.joined", label: "Joined the team", subject: { type: "staff", id: "3", label: "sam@op.example" }, details: {} },
      ], page: 1, perPage: 50, total: 2 },
    });
    render(<MemoryRouter><AuditPage /></MemoryRouter>);
    const log = await screen.findByRole("list", { name: "Audit log" });
    expect(within(log).getByRole("link", { name: "Acme Dental" }).getAttribute("href")).toBe("/admin/customers/w-acme");
    expect(log.querySelector('[data-entry="2"]')?.textContent).toContain("reason: unpaid");
    expect(log.querySelector('[data-entry="1"]')?.textContent).toContain("the platform");
  });
});

describe("a customer's actions", () => {
  function actions(customer: Customer, abilities: string[]) {
    render(
      <MemoryRouter initialEntries={["/admin/customers/w-acme"]}>
        <Routes>
          <Route path="/admin/customers/:id" element={<CustomerActions customer={customer} may={(a) => abilities.includes(a)} onChanged={() => {}} />} />
          <Route path="*" element={<Where />} />
        </Routes>
      </MemoryRouter>,
    );
  }

  it("offers each only to a role that may, and none but looking for a closing customer", () => {
    actions(acme, support.abilities);
    expect(screen.queryByRole("form", { name: "Limits at the voice platform" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Close this customer" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Look at their console" })).toBeNull();
    cleanup();
    actions({ ...acme, status: "closing" }, owner.abilities);
    expect(screen.getByRole("button", { name: "Look at their console" })).toBeTruthy();
    expect(screen.queryByRole("form", { name: "Limits at the voice platform" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Close this customer" })).toBeNull();
  });

  it("sends limits, closes only with the name typed, and opens the console to look", async () => {
    const sent = fakeKit({
      "PUT /api/kit/admin/customers/w-acme/limits": (body: unknown) => Response.json({ ...acme, ...(body as object) }),
      "POST /api/kit/admin/customers/w-acme/close": { ...acme, status: "closing" },
      "POST /api/kit/admin/customers/w-acme/view-as": { viewingAs: { customerId: "w-acme", name: "Acme Dental", until: "2026-10-31T10:30:00Z" } },
    });
    actions(acme, owner.abilities);
    const limits = screen.getByRole("form", { name: "Limits at the voice platform" });
    fireEvent.change(within(limits).getByLabelText("Most calls at once"), { target: { value: "3" } });
    fireEvent.click(within(limits).getByRole("button", { name: "Save limits" }));
    await within(limits).findByRole("status");
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ maxConcurrentCalls: 3, monthlyCapUsd: null });

    fireEvent.click(screen.getByRole("button", { name: "Close this customer" }));
    const close = screen.getByRole("form", { name: "Close this customer" });
    const button = within(close).getByRole("button", { name: "Close for good" }) as HTMLButtonElement;
    fireEvent.change(within(close).getByLabelText("Type Acme Dental to confirm"), { target: { value: "acme dental" } });
    expect(button.disabled).toBe(true);
    fireEvent.change(within(close).getByLabelText("Type Acme Dental to confirm"), { target: { value: "Acme Dental" } });
    fireEvent.click(button);
    await vi.waitFor(() => expect(sent.some((s) => s.path.endsWith("/close"))).toBe(true));
    expect(sent.find((s) => s.path.endsWith("/close"))?.body).toEqual({ confirm: "Acme Dental" });

    fireEvent.click(screen.getByRole("button", { name: "Look at their console" }));
    expect((await screen.findByTestId("where")).textContent).toBe("/console");
  });

  it("shows a role without money the wallet read-only", async () => {
    const wallet = { balance: "100", currency: "INR", inForce: true, plan: null, effectivePlan: "base", spendLimitUsd: null, spendLimitAt: null,
      calls: { waiting: 0, unpriced: 0 }, entries: [], total: 0, page: 1, perPage: 50 };
    const sent = fakeKit({ "GET /api/kit/admin/customers/w-acme/wallet?page=1": wallet, "GET /api/kit/admin/pricing": { lists: [] } });
    render(<MemoryRouter><MayContext.Provider value={(a) => a !== "money"}><CustomerWallet customerId="w-acme" /></MayContext.Provider></MemoryRouter>);
    expect(await screen.findByTestId("customer-balance")).toBeTruthy();
    expect(screen.queryByRole("form", { name: "Add credit" })).toBeNull();
    expect((screen.getByLabelText("Plan") as HTMLSelectElement).disabled).toBe(true);
    expect(sent.map((s) => s.path)).toEqual(["/api/kit/admin/customers/w-acme/wallet?page=1"]);
  });

  it("says in the console that staff are looking, read-only, with a way back", async () => {
    const sent = fakeKit({
      "GET /api/kit/session": { user: { ...owner }, viewingAs: { customerId: "w-acme", name: "Acme Dental", until: "2026-10-31T10:30:00Z" } },
      "GET /api/kit/console/home": { workspace: { id: "w-acme", name: "Acme Dental" }, role: "staff_viewer", paused: null },
      "GET /api/kit/console/workspaces": { workspaces: [] },
      "POST /api/kit/session/view-as/stop": { customerId: "w-acme" },
    });
    render(
      <MemoryRouter initialEntries={["/console"]}>
        <Routes>
          <Route path="/console/*" element={<ConsoleArea />} />
          <Route path="*" element={<Where />} />
        </Routes>
      </MemoryRouter>,
    );
    const banner = await screen.findByText(/You are looking at Acme Dental's console as staff, read-only/);
    expect(await screen.findByText("You are looking as staff: nothing can be changed here.")).toBeTruthy();
    fireEvent.click(within(banner.closest(".viewing-as") as HTMLElement).getByRole("button", { name: "Stop" }));
    expect((await screen.findByTestId("where")).textContent).toBe("/admin/customers/w-acme");
    expect(sent.some((s) => s.method === "POST" && s.path === "/api/kit/session/view-as/stop")).toBe(true);
  });
});
