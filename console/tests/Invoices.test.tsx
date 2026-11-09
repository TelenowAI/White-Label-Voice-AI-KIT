/**
 * Customers' invoices in the console (invoices/, the link on wallet/WalletPage.tsx, the routes in console/ConsoleArea.tsx)
 * against a fake backend: the wallet links to the invoices; they are listed as the backend gives them (newest first), each
 * number linked to its page, with the day issued, the amount and what has since been refunded, and none said in words;
 * more than one page turned with Newer and Older (the page asked for only past the first, the billing details kept);
 * the billing details shown read-only to a member who may not change them, and changed by one who may — every field sent,
 * trimmed, null when empty, the fields following the answer, the backend's 422 by each field and its 403 as an alert.
 * The printable page at exactly `/console/invoices/:id` (the payment-received email's link): the seller and the buyer
 * with their address line breaks, tax numbers and emails, the number and day issued, the line, and with a tax the amount
 * before tax, "GST 18%" and the total paid (without one the total alone), the way it was paid with, a refunded note with
 * the day issued as its UTC day (the one its number's year counts) to viewers west and east of UTC, in the list too;
 * the invoice unchanged, a Print button that opens the browser's print window, print styles that leave only the sheet,
 * and "There is no such invoice." for a 404 (any other refusal: the backend's message).
 */
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AccountInvoices, ConsoleHome, Wallet, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { linesOf, longDay, noTax, older, rowsOf, summary, withTax } from "./invoiceFixtures";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

const ana = { id: 2, name: "Ana", email: "ana@dental.example", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const signedIn = { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one };
const LIST = "/api/kit/console/invoices";
const DETAILS = "/api/kit/console/billing-details";

function listed(over: Partial<AccountInvoices> = {}): AccountInvoices {
  const invoices = over.invoices ?? [];
  return { invoices, page: 1, perPage: 50, total: invoices.length, billingDetails: { name: null, address: null, taxNumber: null }, canEdit: false, ...over };
}

/** A day as the invoice pages show it: its date in UTC, long form. */
const utcDay = (year: number, month: number, date: number) => new Date(Date.UTC(year, month, date, 12)).toLocaleDateString(undefined, { dateStyle: "long", timeZone: "UTC" });

/** Runs `check` as a viewer in `zone`, then puts the time zone back. */
async function viewerIn(zone: string, check: () => Promise<void>) {
  const tz = process.env.TZ;
  process.env.TZ = zone;
  try {
    await check();
  } finally {
    if (tz === undefined) delete process.env.TZ;
    else process.env.TZ = tz;
  }
}

function open(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("the console's invoices", () => {
  it("are linked from the wallet and listed as given, each number linked to its page, with its day, amount and what was refunded", async () => {
    const wallet: Wallet = { currency: "INR", balance: "96.58", inForce: true, entries: [], total: 0, page: 1, perPage: 50, alert: { lowBalanceAt: null, defaultAt: null, mayChange: false } };
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/wallet?page=1": wallet,
      [`GET ${LIST}`]: listed({ invoices: [summary(noTax), { ...summary(withTax), refunded: "50.00" }] }),
      [`GET ${LIST}/${withTax.id}`]: { ...withTax, refunded: "50.00" },
    });
    open("/console/wallet");
    const link = await screen.findByRole("link", { name: "Invoices and billing details" });
    expect(link.getAttribute("href")).toBe("/console/invoices");
    fireEvent.click(link);

    expect(await screen.findByRole("heading", { level: 1, name: "Invoices" })).toBeTruthy();
    expect(screen.getByText("One invoice for each payment added to your wallet. Open one to print it or save it as a PDF.")).toBeTruthy();
    const table = await screen.findByRole("table", { name: "Invoices" });
    expect(rowsOf(table)).toEqual([
      ["Number", "Date", "Amount"],
      ["AV/2026-000002", longDay(noTax.issuedAt), "₹500.00"],
      ["AV/2026-000001", longDay(withTax.issuedAt), "₹1,180.00 · refunded ₹50.00"],
    ]);
    expect(longDay(withTax.issuedAt), "the long form, not the ISO time").toMatch(/2026/);
    expect(within(table).getAllByRole("link").map((a) => [a.textContent, a.getAttribute("href")])).toEqual([
      ["AV/2026-000002", `/console/invoices/${noTax.id}`],
      ["AV/2026-000001", `/console/invoices/${withTax.id}`],
    ]);
    expect(screen.getByRole("link", { name: "Back to the wallet" }).getAttribute("href")).toBe("/console/wallet");
    expect(screen.queryByRole("navigation", { name: "Pages of invoices" }), "one page: no pager").toBeNull();

    fireEvent.click(within(table).getByRole("link", { name: "AV/2026-000001" }));
    const sheet = await screen.findByRole("article", { name: "Invoice" });
    expect(within(sheet).getByTestId("invoice-number").textContent).toBe("AV/2026-000001");
    expect(sent.filter((s) => s.path.startsWith(LIST)).map((s) => `${s.method} ${s.path}`)).toEqual([`GET ${LIST}`, `GET ${LIST}/${withTax.id}`]);
  });

  it("turns pages with Newer and Older, asking for a page only past the first, and keeps the billing details as they are", async () => {
    // Three invoices, two to a page: two pages, the second holding one.
    const first = listed({ invoices: [summary(noTax), summary(withTax)], page: 1, perPage: 2, total: 3, canEdit: true, billingDetails: { name: "Acme Dental", address: null, taxNumber: null } });
    const sent = fakeKit({ ...signedIn, [`GET ${LIST}`]: first, [`GET ${LIST}?page=2`]: { ...first, invoices: [older], page: 2 } });
    open("/console/invoices");
    const pager = () => screen.getByRole("navigation", { name: "Pages of invoices" });
    const button = (name: string) => within(pager()).getByRole("button", { name }) as HTMLButtonElement;
    expect((await screen.findByRole("navigation", { name: "Pages of invoices" })).textContent).toBe("NewerPage 1 of 2Older");
    expect([button("Newer").disabled, button("Older").disabled]).toEqual([true, false]);
    expect(rowsOf(screen.getByRole("table", { name: "Invoices" })).slice(1).map((r) => r[0])).toEqual(["AV/2026-000002", "AV/2026-000001"]);
    const name = screen.getByLabelText("Business name") as HTMLInputElement;
    fireEvent.change(name, { target: { value: "Acme Dental Clinics LLP" } });

    fireEvent.click(button("Older"));
    await waitFor(() => expect(pager().textContent).toBe("NewerPage 2 of 2Older"));
    expect(rowsOf(screen.getByRole("table", { name: "Invoices" })).slice(1).map((r) => r[0])).toEqual(["AV/2025-000042"]);
    expect([button("Newer").disabled, button("Older").disabled]).toEqual([false, true]);
    expect((screen.getByLabelText("Business name") as HTMLInputElement).value, "what was typed in the details stays").toBe("Acme Dental Clinics LLP");

    fireEvent.click(button("Newer"));
    await waitFor(() => expect(pager().textContent).toBe("NewerPage 1 of 2Older"));
    expect(rowsOf(screen.getByRole("table", { name: "Invoices" })).slice(1).map((r) => r[0])).toEqual(["AV/2026-000002", "AV/2026-000001"]);
    expect(sent.filter((s) => s.path.startsWith(LIST)).map((s) => `${s.method} ${s.path}`)).toEqual([`GET ${LIST}`, `GET ${LIST}?page=2`, `GET ${LIST}`]);
  });

  it("shows the page last asked for when an earlier page's answer comes late", async () => {
    // One to a page, three pages; the third answers late, after Newer has already asked for the first.
    const page = (n: number, invoice: typeof older) => listed({ invoices: [invoice], page: n, perPage: 1, total: 3 });
    const sent = fakeKit({
      ...signedIn,
      [`GET ${LIST}`]: page(1, summary(noTax)),
      [`GET ${LIST}?page=2`]: page(2, summary(withTax)),
      [`GET ${LIST}?page=3`]: () => new Promise<Response>((resolve) => setTimeout(() => resolve(Response.json(page(3, older))), 40)),
    });
    open("/console/invoices");
    const pager = () => screen.getByRole("navigation", { name: "Pages of invoices" });
    const button = (name: string) => within(pager()).getByRole("button", { name });
    expect((await screen.findByRole("navigation", { name: "Pages of invoices" })).textContent).toBe("NewerPage 1 of 3Older");
    fireEvent.click(button("Older"));
    await waitFor(() => expect(pager().textContent).toBe("NewerPage 2 of 3Older"));
    fireEvent.click(button("Older"));
    fireEvent.click(button("Newer"));
    await waitFor(() => expect(sent.filter((s) => s.path.startsWith(LIST)).map((s) => s.path)).toEqual([LIST, `${LIST}?page=2`, `${LIST}?page=3`, LIST]));
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 100)); // the third page's answer has come by now
    });
    expect(pager().textContent).toBe("NewerPage 1 of 3Older");
    expect(rowsOf(screen.getByRole("table", { name: "Invoices" })).slice(1).map((r) => r[0])).toEqual(["AV/2026-000002"]);
  });

  it("says there are none yet, in words, with no table", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}`]: listed() });
    open("/console/invoices");
    expect(await screen.findByText("No invoices yet. Each payment added to your wallet gets one.")).toBeTruthy();
    expect(screen.queryByRole("table")).toBeNull();
  });

  it("shows the read failure as an alert", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}`]: () => Response.json({ message: "You are not a member of this account." }, { status: 403 }) });
    open("/console/invoices");
    expect((await screen.findByRole("alert")).textContent).toBe("You are not a member of this account.");
    expect(screen.queryByRole("region", { name: "Billing details" })).toBeNull();
  });
});

describe("the console's billing details", () => {
  it("are shown read-only to a member who may not change them, each one missing as Not set", async () => {
    const sent = fakeKit({
      ...signedIn,
      [`GET ${LIST}`]: listed({ billingDetails: { name: "Acme Dental Clinics LLP", address: "MG Road\nPune 411001", taxNumber: null }, canEdit: false }),
    });
    open("/console/invoices");
    const region = await screen.findByRole("region", { name: "Billing details" });
    expect(within(region).getByText(
      "Printed on your invoices as the customer. Changes appear on the invoices issued from then on; an invoice already issued keeps what it has.",
    )).toBeTruthy();
    const facts = within(region).getByTestId("billing-details");
    expect([...facts.querySelectorAll("div")].map((d) => [d.querySelector("dt")!.textContent, d.querySelector("dd")!.innerHTML])).toEqual([
      ["Business name", "Acme Dental Clinics LLP"],
      ["Address", '<span class="address-lines">MG Road<br>Pune 411001</span>'],
      ["Tax number", '<span class="muted">Not set</span>'],
    ]);
    expect(within(region).getByText("Only the account's owners and admins can change these.")).toBeTruthy();
    expect(within(region).queryByRole("form")).toBeNull();
    expect(within(region).queryByRole("textbox")).toBeNull();
    expect(within(region).queryByRole("button")).toBeNull();
    expect(sent.some((s) => s.method === "PUT")).toBe(false);
  });

  it("are changed by one who may: every field sent, trimmed, null when empty, and the fields follow the answer", async () => {
    const sent = fakeKit({
      ...signedIn,
      [`GET ${LIST}`]: listed({ billingDetails: { name: "Acme Dental", address: null, taxNumber: "27AAAAA0000A1Z5" }, canEdit: true }),
      [`PUT ${DETAILS}`]: () => Response.json({ name: "Acme Dental Clinics LLP", address: "MG Road\nPune 411001", taxNumber: null, canEdit: true }),
    });
    open("/console/invoices");
    const form = await screen.findByRole("form", { name: "Billing details" });
    const name = within(form).getByLabelText("Business name") as HTMLInputElement;
    const address = within(form).getByLabelText("Address") as HTMLTextAreaElement;
    const taxNumber = within(form).getByLabelText("Tax number") as HTMLInputElement;
    expect([name.value, address.value, taxNumber.value]).toEqual(["Acme Dental", "", "27AAAAA0000A1Z5"]);
    expect(address.tagName, "an address takes several lines").toBe("TEXTAREA");
    expect(document.getElementById(name.getAttribute("aria-describedby")!)!.textContent).toBe("Empty: your account's name.");
    expect(screen.queryByText("Only the account's owners and admins can change these.")).toBeNull();

    fireEvent.change(name, { target: { value: "  Acme Dental Clinics LLP " } });
    fireEvent.change(address, { target: { value: "MG Road\nPune 411001\n" } });
    fireEvent.change(taxNumber, { target: { value: "  " } });
    fireEvent.click(within(form).getByRole("button", { name: "Save billing details" }));

    await waitFor(() => expect(within(form).getByRole("button", { name: "Save billing details" })).toHaveProperty("disabled", false));
    expect(within(form).getByRole("status").textContent).toBe("Saved. Invoices issued from now on carry these details.");
    const puts = sent.filter((s) => s.method === "PUT");
    expect(puts.map((s) => [s.path, s.body])).toEqual([[DETAILS, { name: "Acme Dental Clinics LLP", address: "MG Road\nPune 411001", taxNumber: null }]]);
    expect(puts[0].headers["content-type"]).toBe("application/json");
    expect([name.value, address.value, taxNumber.value]).toEqual(["Acme Dental Clinics LLP", "MG Road\nPune 411001", ""]);
  });

  it("shows the backend's 422 by each field it names and its 403 as an alert", async () => {
    const answers = [
      Response.json({ message: "The Name field must not be greater than 120 characters. (and 1 more error)", errors: {
        name: ["The Name field must not be greater than 120 characters."], taxNumber: ["The Tax number field must not be greater than 64 characters."],
      } }, { status: 422 }),
      Response.json({ message: "Only the account's owners and admins can change its billing details." }, { status: 403 }),
    ];
    fakeKit({ ...signedIn, [`GET ${LIST}`]: listed({ canEdit: true }), [`PUT ${DETAILS}`]: () => answers.shift()! });
    open("/console/invoices");
    const form = await screen.findByRole("form", { name: "Billing details" });
    const save = within(form).getByRole("button", { name: "Save billing details" });

    fireEvent.click(save);
    expect((await within(form).findByTestId("billing-name-error")).textContent).toBe("The Name field must not be greater than 120 characters.");
    expect(within(form).getByTestId("billing-taxNumber-error").textContent).toBe("The Tax number field must not be greater than 64 characters.");
    expect(within(form).queryByTestId("billing-address-error")).toBeNull();
    expect(within(form).getByLabelText("Business name").getAttribute("aria-invalid")).toBe("true");
    expect(within(form).queryByRole("alert")).toBeNull();
    expect(within(form).queryByRole("status")).toBeNull();

    fireEvent.click(save);
    expect((await within(form).findByRole("alert")).textContent).toBe("Only the account's owners and admins can change its billing details.");
    expect(within(form).queryByTestId("billing-name-error")).toBeNull();
    expect(within(form).queryByRole("status")).toBeNull();
  });
});

describe("the console's printable invoice", () => {
  it("opens at exactly /console/invoices/:id and shows both sides, the number, the day, the line and, with a tax, the net, the tax and the total", async () => {
    const sent = fakeKit({ ...signedIn, [`GET ${LIST}/${withTax.id}`]: withTax });
    open(`/console/invoices/${withTax.id}`);
    const sheet = await screen.findByRole("article", { name: "Invoice" });
    expect(within(sheet).getByRole("heading", { level: 1 }).textContent).toBe("Invoice");
    expect([...sheet.querySelectorAll("header dt")].map((dt) => dt.textContent)).toEqual(["Invoice number", "Date issued"]);
    expect(within(sheet).getByTestId("invoice-number").textContent).toBe("AV/2026-000001");
    expect(within(sheet).getByTestId("invoice-issued").textContent).toBe(longDay(withTax.issuedAt));
    expect(linesOf(within(sheet).getByRole("region", { name: "From" }))).toEqual([
      "<strong>Acme Voice Pvt Ltd</strong>",
      '<span class="address-lines">12 Residency Road<br>Bengaluru 560025</span>',
      "Tax number: 29ABCDE1234F1Z5",
      "billing@acme-voice.example",
    ]);
    expect(linesOf(within(sheet).getByRole("region", { name: "Billed to" }))).toEqual([
      "<strong>Acme Dental Clinics LLP</strong>",
      '<span class="address-lines">MG Road<br>Pune 411001</span>',
      "Tax number: 27AAAAA0000A1Z5",
      "ana@dental.example",
    ]);
    expect(rowsOf(within(sheet).getByRole("table", { name: "Invoice" }))).toEqual([
      ["Description", "Amount"],
      ["Credit for Acme Dental on Acme Voice", "₹1,180.00"],
      ["Amount before tax", "₹1,000.00"],
      ["GST 18%", "₹180.00"],
      ["Total paid", "₹1,180.00"],
    ]);
    expect(within(sheet).getByTestId("invoice-paid-with").textContent).toBe("Paid with: Bank transfer (NEFT)");
    expect(within(sheet).queryByTestId("invoice-refunded")).toBeNull();
    expect(screen.getByRole("link", { name: "All invoices" }).getAttribute("href")).toBe("/console/invoices");
    expect(sent.filter((s) => s.path.startsWith(LIST)).map((s) => `${s.method} ${s.path}`)).toEqual([`GET ${LIST}/${withTax.id}`]);
  });

  it("without a tax shows the total alone, and leaves out the details and the way to pay it does not have", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}/${noTax.id}`]: noTax });
    open(`/console/invoices/${noTax.id}`);
    const sheet = await screen.findByRole("article", { name: "Invoice" });
    expect(rowsOf(within(sheet).getByRole("table", { name: "Invoice" }))).toEqual([
      ["Description", "Amount"],
      ["Credit for Acme Dental on Acme Voice", "₹500.00"],
      ["Total paid", "₹500.00"],
    ]);
    expect(linesOf(within(sheet).getByRole("region", { name: "From" }))).toEqual(["<strong>Acme Voice</strong>", "help@acme-voice.example"]);
    expect(linesOf(within(sheet).getByRole("region", { name: "Billed to" }))).toEqual(["<strong>Acme Dental</strong>", "ben@dental.example"]);
    expect(within(sheet).queryByText("Amount before tax")).toBeNull();
    expect(within(sheet).queryByTestId("invoice-paid-with")).toBeNull();
    expect(sheet.textContent).not.toContain("Paid with");
    expect(sheet.textContent).not.toContain("Tax number");
  });

  it("says how much of the payment has since been refunded, the invoice itself unchanged", async () => {
    fakeKit({ ...signedIn, [`GET ${LIST}/${withTax.id}`]: { ...withTax, refunded: "50.00" } });
    open(`/console/invoices/${withTax.id}`);
    const sheet = await screen.findByRole("article", { name: "Invoice" });
    expect(within(sheet).getByTestId("invoice-refunded").textContent).toBe(
      "₹50.00 of this payment has since been refunded. This invoice stays as it was issued.",
    );
    expect(rowsOf(within(sheet).getByRole("table", { name: "Invoice" })).at(-1)).toEqual(["Total paid", "₹1,180.00"]);
  });

  it("prints from the Print button, and its print styles leave only the sheet on paper", async () => {
    const print = vi.spyOn(window, "print").mockImplementation(() => {});
    fakeKit({ ...signedIn, [`GET ${LIST}/${withTax.id}`]: withTax });
    open(`/console/invoices/${withTax.id}`);
    const sheet = await screen.findByRole("article", { name: "Invoice" });
    const button = screen.getByRole("button", { name: "Print" });
    expect(print).not.toHaveBeenCalled();
    fireEvent.click(button);
    expect(print).toHaveBeenCalledTimes(1);
    expect(screen.getByText("To keep a PDF, choose Save as PDF in the print window.")).toBeTruthy();

    // What the print styles hide is on this page, outside the sheet; the sheet is not inside what they hide.
    const page = sheet.closest(".invoice-page")!;
    expect(page).not.toBeNull();
    expect(button.closest(".invoice-tools")!.parentElement).toBe(page);
    expect(sheet.closest(".invoice-tools")).toBeNull();
    expect(document.querySelector(".admin-nav")!.contains(page)).toBe(false);

    const css = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), "../src/styles.css"), "utf8");
    const print_ = [...css.matchAll(/@media print\s*\{([\s\S]*?)\n\}/g)].map((m) => m[1]).find((block) => block.includes(".invoice-page"));
    expect(print_, "an @media print block for the invoice page").toBeDefined();
    const hidden = [...print_!.matchAll(/([^{}]+)\{\s*display:\s*none;\s*\}/g)].flatMap((m) => m[1].split(",").map((s) => s.trim()));
    expect(hidden).toEqual([
      "body:has(.invoice-page) .brand-bar",
      "body:has(.invoice-page) .admin-nav",
      "body:has(.invoice-page) .viewing-as",
      "body:has(.invoice-page) .paused",
      ".invoice-page .invoice-tools",
    ]);
    expect(hidden.some((s) => s.includes("invoice-sheet")), "the sheet itself always prints").toBe(false);
  });

  it("shows the day issued as its UTC day, the one its number's year counts, to viewers west and east of UTC, in the list too", async () => {
    const newYear = { ...withTax, id: "00000000-0000-4000-8000-0000000000b1", number: "AV/2027-000001", issuedAt: "2027-01-01T00:30:00+00:00" };
    const lastDay = { ...withTax, id: "00000000-0000-4000-8000-0000000000b2", number: "AV/2026-000099", issuedAt: "2026-12-31T23:30:00+00:00" };
    const cases = [
      // [the viewer's zone, the invoice, its local date there (proving the viewer's calendar differs, or not), the day shown]
      ["America/Los_Angeles", newYear, 31, utcDay(2027, 0, 1)],
      ["America/Los_Angeles", lastDay, 31, utcDay(2026, 11, 31)],
      ["Asia/Kolkata", lastDay, 1, utcDay(2026, 11, 31)],
    ] as const;
    expect(utcDay(2027, 0, 1)).toContain("2027");
    for (const [zone, invoice, localDate, shown] of cases) {
      await viewerIn(zone, async () => {
        expect(new Date(invoice.issuedAt).getDate(), `the viewer in ${zone}`).toBe(localDate);
        fakeKit({ ...signedIn, [`GET ${LIST}/${invoice.id}`]: invoice, [`GET ${LIST}`]: listed({ invoices: [summary(invoice)] }) });
        open(`/console/invoices/${invoice.id}`);
        const sheet = await screen.findByRole("article", { name: "Invoice" });
        expect(within(sheet).getByTestId("invoice-number").textContent).toBe(invoice.number);
        expect(within(sheet).getByTestId("invoice-issued").textContent, `${zone}, ${invoice.number}`).toBe(shown);
        cleanup();
        open("/console/invoices");
        const table = await screen.findByRole("table", { name: "Invoices" });
        expect(rowsOf(table)[1].slice(0, 2), `${zone}, ${invoice.number} in the list`).toEqual([invoice.number, shown]);
        cleanup();
      });
    }
  });

  it("says there is no such invoice for a 404, and shows any other refusal as the backend says it", async () => {
    fakeKit({ ...signedIn }); // the fake answers 404 for what it does not know
    open("/console/invoices/00000000-0000-4000-8000-0000000000ff");
    expect((await screen.findByRole("alert")).textContent).toBe("There is no such invoice.");
    expect(screen.queryByRole("article")).toBeNull();
    expect(screen.queryByRole("button", { name: "Print" })).toBeNull();
    expect(screen.getByRole("link", { name: "All invoices" }).getAttribute("href")).toBe("/console/invoices");
    cleanup();

    fakeKit({ ...signedIn, [`GET ${LIST}/${withTax.id}`]: () => Response.json({ message: "Server error" }, { status: 500 }) });
    open(`/console/invoices/${withTax.id}`);
    expect((await screen.findByRole("alert")).textContent).toBe("Server error");
    expect(screen.queryByRole("article")).toBeNull();
  });
});
