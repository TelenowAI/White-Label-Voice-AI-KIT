/**
 * Customers' invoices in the admin panel (admin/InvoiceSettingsPage.tsx, CustomerInvoices.tsx, CustomerInvoicePage.tsx,
 * the nav and routes in AdminArea.tsx) against a fake backend: Admin → Invoices shows the operator's settings and what
 * empty means (the brand's name, the support email), a tax needing both name and rate (0 = none), that settings apply
 * from then on, and an example number that follows the prefix typed, its number as many digits as the server's
 * `sequenceDigits`; saving sends every field (never sequenceDigits), trimmed, null when empty, and the fields follow
 * the answer; the backend's 422 shows by each field it names, any other refusal as an alert. The page is in the nav
 * (and opens) for staff with the `money` ability only. A customer's page lists their invoices (newest first as given,
 * refunded beside the amount, Newer and Older past one page, a page asked for only past the first) linked to the
 * admin's view of one, and their billing details read-only; that view is the same printable invoice with a way back to
 * the customer and Print, opens for staff who read customers only, and says "There is no such invoice." for a 404.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Customer, CustomerInvoiceList, InvoiceSettingsAnswer } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { fakeKit } from "./fakeKit";
import { longDay, noTax, older, rowsOf, summary, withTax } from "./invoiceFixtures";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const SETTINGS = "/api/kit/admin/invoice-settings";
const staff = { id: 1, name: "Olu", email: "olu@op.example", role: "admin" };
const session = (abilities: string[]) => ({ "GET /api/kit/session": { user: { ...staff, abilities } } });

const saved: InvoiceSettingsAnswer = { prefix: "INV-", name: null, address: null, taxNumber: null, email: null, taxName: null, taxRate: null, sequenceDigits: 6 };
const LIST = "/api/kit/admin/customers/w1/invoices";
const NO_DETAILS = { name: null, address: null, taxNumber: null };

function listed(over: Partial<CustomerInvoiceList> = {}): CustomerInvoiceList {
  const invoices = over.invoices ?? [];
  return { invoices, page: 1, perPage: 50, total: invoices.length, billingDetails: NO_DETAILS, ...over };
}

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function admin(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/*" element={<AdminArea />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

const helpOf = (input: HTMLElement) => document.getElementById(input.getAttribute("aria-describedby")!)!.textContent;

describe("Admin → Invoices: the settings", () => {
  it("shows what each field does, an example number that follows the prefix, and saves every field, trimmed, null when empty", async () => {
    const year = new Date().getUTCFullYear();
    // This server numbers each year's invoices with four digits.
    const answers: InvoiceSettingsAnswer[] = [
      { prefix: "AV/", name: "Acme Voice Pvt Ltd", address: "12 Residency Road\nBengaluru 560025", taxNumber: "29ABCDE1234F1Z5", email: null, taxName: "GST", taxRate: "18", sequenceDigits: 4 },
      { ...saved, prefix: "", sequenceDigits: 4 },
    ];
    const sent = fakeKit({ ...session(["money"]), [`GET ${SETTINGS}`]: { ...saved, sequenceDigits: 4 }, [`PUT ${SETTINGS}`]: () => Response.json(answers.shift()) });
    admin("/admin/invoices");
    expect(await screen.findByRole("heading", { level: 1, name: "Invoices" })).toBeTruthy();
    expect(screen.getByText("What your customers' invoices carry: your details, a tax and how they are numbered. Each payment credited to a customer's wallet gets one.")).toBeTruthy();
    expect(screen.getByText("Settings apply to the invoices issued from then on; an invoice already issued never changes.")).toBeTruthy();
    const form = await screen.findByRole("form", { name: "Invoices" });
    const field = (label: string) => within(form).getByLabelText(label) as HTMLInputElement | HTMLTextAreaElement;
    const labels = ["Number prefix", "Business name", "Address", "Tax number", "Email", "Tax name", "Tax rate (%)"];
    expect(labels.map((l) => field(l).value)).toEqual(["INV-", "", "", "", "", "", ""]);
    expect(field("Address").tagName).toBe("TEXTAREA");
    expect(helpOf(field("Number prefix"))).toBe(`Numbers read like INV-${year}-0001.`);
    expect(helpOf(field("Business name"))).toBe("Empty: your brand's name.");
    expect(helpOf(field("Email"))).toBe("Empty: your support email.");
    expect(helpOf(field("Tax rate (%)"))).toBe("A tax needs both its name (GST, VAT…) and its rate; a rate of 0 means no tax. The amount a customer pays includes the tax.");

    fireEvent.change(field("Number prefix"), { target: { value: "AV/" } });
    expect(helpOf(field("Number prefix")), "the example follows what is typed").toBe(`Numbers read like AV/${year}-0001.`);
    fireEvent.change(field("Business name"), { target: { value: "  Acme Voice Pvt Ltd " } });
    fireEvent.change(field("Address"), { target: { value: "12 Residency Road\nBengaluru 560025\n" } });
    fireEvent.change(field("Tax number"), { target: { value: "29ABCDE1234F1Z5" } });
    fireEvent.change(field("Email"), { target: { value: "   " } });
    fireEvent.change(field("Tax name"), { target: { value: "GST" } });
    fireEvent.change(field("Tax rate (%)"), { target: { value: "18" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    // Once the answer is in (the button no longer busy), it says so, and the fields show what the backend saved.
    await waitFor(() => expect(within(form).getByRole("button", { name: "Save" })).toHaveProperty("disabled", false));
    expect(within(form).getByRole("status").textContent).toBe("Saved. Invoices issued from now on carry these settings.");
    expect(labels.map((l) => field(l).value)).toEqual(["AV/", "Acme Voice Pvt Ltd", "12 Residency Road\nBengaluru 560025", "29ABCDE1234F1Z5", "", "GST", "18"]);

    // Emptied: every field still sent, as null; the prefix the backend answers ("") is what the field and the example show.
    for (const l of labels) fireEvent.change(field(l), { target: { value: "" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT")).toHaveLength(2));
    await waitFor(() => expect(helpOf(field("Number prefix"))).toBe(`Numbers read like ${year}-0001.`));

    const puts = sent.filter((s) => s.method === "PUT");
    expect(puts.map((s) => s.path)).toEqual([SETTINGS, SETTINGS]);
    expect(puts.map((s) => s.body)).toEqual([
      { prefix: "AV/", name: "Acme Voice Pvt Ltd", address: "12 Residency Road\nBengaluru 560025", taxNumber: "29ABCDE1234F1Z5", email: null, taxName: "GST", taxRate: "18" },
      { prefix: null, name: null, address: null, taxNumber: null, email: null, taxName: null, taxRate: null },
    ]);
    expect(Object.keys(puts[1].body as object)).toEqual(["prefix", "name", "address", "taxNumber", "email", "taxName", "taxRate"]);
    expect(labels.map((l) => field(l).value)).toEqual(["", "", "", "", "", "", ""]);
  });

  it("shows the backend's 422 by each field it names, and any other refusal as an alert", async () => {
    const answers = [
      Response.json({ message: "The Tax name field is required when Tax rate is present. (and 1 more error)", errors: {
        taxName: ["The Tax name field is required when Tax rate is present."],
        taxRate: ["Give the tax rate as a percentage from 0 to 100, with at most two decimals."],
      } }, { status: 422 }),
      Response.json({ message: "Your role cannot do this." }, { status: 403 }),
    ];
    fakeKit({ ...session(["money"]), [`GET ${SETTINGS}`]: saved, [`PUT ${SETTINGS}`]: () => answers.shift()! });
    admin("/admin/invoices");
    const form = await screen.findByRole("form", { name: "Invoices" });
    expect(helpOf(within(form).getByLabelText("Number prefix")), "six digits on a server that numbers with six").toBe(`Numbers read like INV-${new Date().getUTCFullYear()}-000001.`);
    fireEvent.change(within(form).getByLabelText("Tax rate (%)"), { target: { value: "120.555" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect((await within(form).findByTestId("invoice-tax-name-error")).textContent).toBe("The Tax name field is required when Tax rate is present.");
    expect(within(form).getByTestId("invoice-tax-rate-error").textContent).toBe("Give the tax rate as a percentage from 0 to 100, with at most two decimals.");
    expect(within(form).getByLabelText("Tax rate (%)").getAttribute("aria-invalid")).toBe("true");
    expect(within(form).getByLabelText("Number prefix").getAttribute("aria-invalid")).toBeNull();
    expect(within(form).queryByRole("alert")).toBeNull();
    expect(within(form).queryByRole("status")).toBeNull();

    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect((await within(form).findByRole("alert")).textContent).toBe("Your role cannot do this.");
    expect(within(form).queryByTestId("invoice-tax-rate-error")).toBeNull();
  });

  it("is in the nav and opens for staff who handle money, and is neither for others", async () => {
    const sent = fakeKit({ ...session(["money"]), [`GET ${SETTINGS}`]: saved });
    admin("/admin/invoices");
    const nav = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(nav).getAllByRole("link").map((a) => [a.textContent, a.getAttribute("href")])).toEqual([
      ["Pricing", "/admin/pricing"], ["Payments", "/admin/payments"], ["Invoices", "/admin/invoices"], ["Platform billing", "/admin/platform-billing"],
    ]);
    expect(await screen.findByRole("form", { name: "Invoices" })).toBeTruthy();
    expect(sent.map((s) => s.path)).toContain(SETTINGS);
    cleanup();

    const other = fakeKit({ ...session(["health", "customers_read", "reports"]) });
    admin("/admin/invoices");
    const theirs = await screen.findByRole("navigation", { name: "Admin" });
    expect(within(theirs).queryByRole("link", { name: "Invoices" })).toBeNull();
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/admin/dashboard"));
    expect(screen.queryByRole("form", { name: "Invoices" })).toBeNull();
    expect(other.some((s) => s.path === SETTINGS)).toBe(false);
  });
});

describe("Admin → a customer's invoices", () => {
  const acme: Customer = {
    id: "w1", name: "Acme Dental", status: "active", syncState: "synced", syncError: null, telenowSubAccountId: null, balance: "0", priceListId: null,
    maxConcurrentCalls: null, monthlyCapUsd: null, closeRequestedAt: null, closedAt: null, createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-29T10:00:00Z",
  };
  const customerPage = {
    "GET /api/kit/admin/customers/w1": acme,
    "GET /api/kit/admin/customers/w1/team": { members: [], invites: [], roles: ["owner", "member"], canManage: true },
    "GET /api/kit/admin/customers/w1/wallet?page=1": { currency: "INR", balance: "0", plan: null, effectivePlan: null, inForce: true, spendLimitUsd: null,
      spendLimitAt: null, calls: { waiting: 0, unpriced: 0 }, entries: [], total: 0, page: 1, perPage: 50 },
  };

  it("are listed on their page with their billing details, each linked to the same printable invoice, with a way back and Print", async () => {
    const print = vi.spyOn(window, "print").mockImplementation(() => {});
    const sent = fakeKit({
      ...session(["customers_read"]),
      ...customerPage,
      [`GET ${LIST}`]: listed({
        invoices: [summary(noTax), { ...summary(withTax), refunded: "50.00" }],
        billingDetails: { name: "Acme Dental Clinics LLP", address: "MG Road\nPune 411001", taxNumber: "27AAAAA0000A1Z5" },
      }),
      [`GET /api/kit/admin/invoices/${withTax.id}`]: { ...withTax, refunded: "50.00", customerId: "w1" },
    });
    admin("/admin/customers/w1");
    const region = await screen.findByRole("region", { name: "Invoices" });
    const table = await within(region).findByRole("table", { name: "Invoices" });
    expect(rowsOf(table)).toEqual([
      ["Number", "Date", "Amount"],
      ["AV/2026-000002", longDay(noTax.issuedAt), "₹500.00"],
      ["AV/2026-000001", longDay(withTax.issuedAt), "₹1,180.00 · refunded ₹50.00"],
    ]);
    expect(within(table).getAllByRole("link").map((a) => a.getAttribute("href"))).toEqual([`/admin/invoices/${noTax.id}`, `/admin/invoices/${withTax.id}`]);
    expect(within(region).getByRole("heading", { level: 3 }).textContent).toBe("Billing details");
    const facts = within(region).getByTestId("billing-details");
    expect([...facts.querySelectorAll("div")].map((d) => [d.querySelector("dt")!.textContent, d.querySelector("dd")!.innerHTML])).toEqual([
      ["Business name", "Acme Dental Clinics LLP"],
      ["Address", '<span class="address-lines">MG Road<br>Pune 411001</span>'],
      ["Tax number", "27AAAAA0000A1Z5"],
    ]);
    expect(within(region).queryByRole("textbox"), "staff read them; the customer changes them").toBeNull();
    expect(within(region).queryByRole("navigation"), "one page: no pager").toBeNull();

    fireEvent.click(within(table).getByRole("link", { name: "AV/2026-000001" }));
    const sheet = await screen.findByRole("article", { name: "Invoice" });
    expect(screen.getByTestId("where").textContent).toBe(`/admin/invoices/${withTax.id}`);
    expect(rowsOf(within(sheet).getByRole("table", { name: "Invoice" }))).toEqual([
      ["Description", "Amount"],
      ["Credit for Acme Dental on Acme Voice", "₹1,180.00"],
      ["Amount before tax", "₹1,000.00"],
      ["GST 18%", "₹180.00"],
      ["Total paid", "₹1,180.00"],
    ]);
    expect(within(sheet).getByTestId("invoice-refunded").textContent).toBe("₹50.00 of this payment has since been refunded. This invoice stays as it was issued.");
    expect(screen.getByRole("link", { name: "Back to the customer" }).getAttribute("href")).toBe("/admin/customers/w1");
    fireEvent.click(screen.getByRole("button", { name: "Print" }));
    expect(print).toHaveBeenCalledTimes(1);
    expect(sent.map((s) => s.path).filter((p) => p.includes("invoices"))).toEqual(["/api/kit/admin/customers/w1/invoices", `/api/kit/admin/invoices/${withTax.id}`]);
  });

  it("turns pages with Newer and Older, asking for a page only past the first, the billing details kept", async () => {
    const details = { name: "Acme Dental Clinics LLP", address: null, taxNumber: null };
    // Three invoices, two to a page: two pages, the second holding one.
    const first = listed({ invoices: [summary(noTax), summary(withTax)], page: 1, perPage: 2, total: 3, billingDetails: details });
    const sent = fakeKit({ ...session(["customers_read"]), ...customerPage, [`GET ${LIST}`]: first, [`GET ${LIST}?page=2`]: { ...first, invoices: [older], page: 2 } });
    admin("/admin/customers/w1");
    const region = await screen.findByRole("region", { name: "Invoices" });
    const pager = () => within(region).getByRole("navigation", { name: "Pages of invoices" });
    const button = (name: string) => within(pager()).getByRole("button", { name }) as HTMLButtonElement;
    expect((await within(region).findByRole("navigation", { name: "Pages of invoices" })).textContent).toBe("NewerPage 1 of 2Older");
    expect([button("Newer").disabled, button("Older").disabled]).toEqual([true, false]);
    expect(rowsOf(within(region).getByRole("table")).slice(1).map((r) => r[0])).toEqual(["AV/2026-000002", "AV/2026-000001"]);

    fireEvent.click(button("Older"));
    await waitFor(() => expect(pager().textContent).toBe("NewerPage 2 of 2Older"));
    expect(rowsOf(within(region).getByRole("table")).slice(1).map((r) => r[0])).toEqual(["AV/2025-000042"]);
    expect([button("Newer").disabled, button("Older").disabled]).toEqual([false, true]);
    expect(within(region).getByTestId("billing-details").querySelector("dd")!.textContent).toBe("Acme Dental Clinics LLP");

    fireEvent.click(button("Newer"));
    await waitFor(() => expect(pager().textContent).toBe("NewerPage 1 of 2Older"));
    expect(sent.filter((s) => s.path.startsWith(LIST)).map((s) => s.path)).toEqual([LIST, `${LIST}?page=2`, LIST]);
  });

  it("says there are none, and the invoice view says there is no such invoice for a 404", async () => {
    fakeKit({ ...session(["customers_read"]), ...customerPage, [`GET ${LIST}`]: listed() });
    admin("/admin/customers/w1");
    const region = await screen.findByRole("region", { name: "Invoices" });
    expect(await within(region).findByText("No invoices yet.")).toBeTruthy();
    expect([...within(region).getByTestId("billing-details").querySelectorAll("dd")].map((dd) => dd.textContent)).toEqual(["Not set", "Not set", "Not set"]);
    expect(within(region).queryByRole("table")).toBeNull();
    cleanup();

    fakeKit({ ...session(["customers_read"]) });
    admin("/admin/invoices/00000000-0000-4000-8000-0000000000ff");
    expect((await screen.findByRole("alert")).textContent).toBe("There is no such invoice.");
    expect(screen.queryByRole("button", { name: "Print" })).toBeNull();
    expect(screen.getByRole("link", { name: "All customers" }).getAttribute("href")).toBe("/admin/customers");
  });

  it("opens an invoice only for staff who read customers", async () => {
    const sent = fakeKit({ ...session(["money"]), [`GET /api/kit/admin/invoices/${withTax.id}`]: { ...withTax, customerId: "w1" } });
    admin(`/admin/invoices/${withTax.id}`);
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/admin/pricing"));
    expect(screen.queryByRole("article", { name: "Invoice" })).toBeNull();
    expect(sent.some((s) => s.path === `/api/kit/admin/invoices/${withTax.id}`)).toBe(false);
  });
});
