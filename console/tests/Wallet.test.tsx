/**
 * Wallets and prices in the console and admin (wallet/, admin/Pricing.tsx, admin/CustomerWallet.tsx): a customer sees
 * its balance and entries (each call's charge linked to the call, and on the call's page); the operator sets the
 * currency, rate and default plan, edits price lists as JSON with the backend's own words for what is wrong, deletes a
 * list only after asking; and on a customer's page sees its balance, plan, stop and entries with cost and margin, moves
 * it to another plan, and credits it — a credit sent again after a failure carries the same reference, so it counts once.
 * The low-balance email: the members who get it choose its amount on the wallet page (others only read it), and the
 * operator sets the platform's amount in admin → pricing.
 */
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { CallDetail, ConsoleHome, Customer, CustomerWallet, Pricing, Wallet, WorkspaceList } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { money } from "../src/wallet/money";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const owner = { id: 1, name: "Op", email: "op@example.com", role: "owner" };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const signedIn = { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one };

const credit = { id: 1, kind: "credit" as const, amount: "100", currency: "INR", balanceAfter: "100", note: "Bank transfer", callId: null, numberId: null, paymentId: null, createdAt: "2026-10-30T10:00:00Z" };
const charge = { id: 2, kind: "charge" as const, amount: "-3.42", currency: "INR", balanceAfter: "96.58", note: null, callId: "c1", numberId: null, paymentId: null, createdAt: "2026-10-30T11:40:00Z" };
const fee = { id: 3, kind: "charge" as const, amount: "-522.90", currency: "INR", balanceAfter: "-426.32", note: null, callId: null, numberId: "n1", paymentId: null, createdAt: "2026-10-30T12:00:00Z" };

function wallet(over: Partial<Wallet> = {}): Wallet {
  return {
    currency: "INR", balance: "96.58", inForce: true, entries: [charge, credit], total: 2, page: 1, perPage: 50,
    alert: { lowBalanceAt: null, defaultAt: null, mayChange: false }, ...over,
  };
}

function open(element: ReactElement, path: string, base: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={`${base}/*`} element={element} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("the console's wallet", () => {
  it("shows the balance and each entry, a charge linked to its call", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/wallet?page=1": wallet() });
    open(<ConsoleArea />, "/console", "/console");
    fireEvent.click(await screen.findByRole("link", { name: "Wallet" }));
    expect((await screen.findByTestId("balance")).textContent).toBe("₹96.58");
    const rows = screen.getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(1).map((c) => c.textContent))).toEqual([
      ["Call", "-₹3.42", "₹96.58"],
      ["Credit · Bank transfer", "₹100", "₹100"],
    ]);
    expect(within(rows[0]).getByRole("link", { name: "Call" }).getAttribute("href")).toBe("/console/calls/c1");
  });

  it("shows a number fee as one, linked to the numbers", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/wallet?page=1": wallet({ balance: "-426.32", entries: [fee, charge, credit], total: 3 }) });
    open(<ConsoleArea />, "/console/wallet", "/console");
    const rows = (await screen.findAllByRole("row")).slice(1);
    expect(within(rows[0]).getAllByRole("cell").slice(1).map((c) => c.textContent)).toEqual(["Number fee", "-₹522.90", "-₹426.32"]);
    expect(within(rows[0]).getByRole("link", { name: "Number fee" }).getAttribute("href")).toBe("/console/numbers");
  });

  it("says when charging is not on yet, and when there is nothing", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/wallet?page=1": wallet({ inForce: false, balance: "0", entries: [], total: 0, currency: null }) });
    open(<ConsoleArea />, "/console/wallet", "/console");
    expect(await screen.findByText("Charging is not switched on yet.")).toBeTruthy();
    expect(screen.getByText("Nothing yet.")).toBeTruthy();
    expect(screen.getByTestId("balance").textContent).toBe("0");
  });

  it("shows what a call was charged on its page", async () => {
    const call: CallDetail = {
      id: "c1", agent: null, status: "ended", outcome: "completed", direction: "inbound", channel: "telephony", from: "+14155550123",
      to: "+14155550100", startedAt: "2026-10-30T11:35:57Z", endedAt: "2026-10-30T11:36:47Z", durationSec: 50, complete: true,
      charge: { amount: "3.42", currency: "INR" }, answeredBy: "human", transcript: [], recordings: [], analysis: null, placedBy: null, requestedBy: null, wrapup: null, canListen: false, listens: [],
    };
    fakeKit({ ...signedIn, "GET /api/kit/console/calls/c1": call });
    open(<ConsoleArea />, "/console/calls/c1", "/console");
    expect((await screen.findByText("Charged")).nextElementSibling?.textContent).toBe("₹3.42");
  });

  it("keeps the decimals an amount came with", () => {
    expect([money("3.42", "INR"), money("100", "INR"), money("0.0293950000", "USD"), money("-0.5", "INR"), money("7", null)])
      .toEqual(["₹3.42", "₹100", "$0.0293950000", "-₹0.5", "7"]);
  });
});

const standard = {
  id: "standard", name: "Standard", customers: 2, updatedAt: "2026-10-30T09:00:00Z",
  definition: { id: "standard", currency: "INR", decimals: 2, rounding: "half-up", billingIncrementSeconds: 60, rules: [] },
};

function pricing(over: Partial<Pricing> = {}): Pricing {
  return { currency: "INR", usdRate: "83.00", defaultPlan: "standard", lowBalanceAt: null, inForce: true, lists: [standard], ...over };
}

const adminSignedIn = { "GET /api/kit/session": { user: owner } };

describe("admin → pricing", () => {
  it("sets the currency, rate and default plan, with the backend's word on a field", async () => {
    let saved: unknown = null;
    const sent = fakeKit({
      ...adminSignedIn,
      "GET /api/kit/admin/pricing": pricing({ usdRate: null, defaultPlan: null, inForce: false }),
      "PUT /api/kit/admin/pricing": (body: unknown) =>
        (body as { usdRate: string }).usdRate === "0"
          ? Response.json({ message: "x", errors: { usdRate: ["How much of your currency one US dollar is, above zero."] } }, { status: 422 })
          : Response.json((saved = pricing())),
    });
    open(<AdminArea />, "/admin/pricing", "/admin");
    expect(await screen.findByText("Charging is off: set a currency, a rate and a default plan.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("One US dollar is (in your currency)"), { target: { value: "0" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    expect(await screen.findByText("How much of your currency one US dollar is, above zero.")).toBeTruthy();

    fireEvent.change(screen.getByLabelText("One US dollar is (in your currency)"), { target: { value: "83.00" } });
    fireEvent.change(screen.getByLabelText("Default plan"), { target: { value: "standard" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    expect(await screen.findByText("Charging is on.")).toBeTruthy();
    expect(saved).not.toBeNull();
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({ currency: "INR", usdRate: "83.00", defaultPlan: "standard" });
  });

  it("starts a new list from a template in the platform currency, and shows what is wrong with it", async () => {
    let lists = [standard];
    const sent = fakeKit({
      ...adminSignedIn,
      "GET /api/kit/admin/pricing": () => Response.json(pricing({ lists })),
      "PUT /api/kit/admin/pricing/lists/pro": (body: unknown) => {
        const def = (body as { definition: { rules: Array<{ price: Record<string, string> }> } }).definition;
        if ("perMinute" in def.rules[1].price) {
          return Response.json({ message: "x", errors: { definition: ["Rule 2: `price` is one of markupPercent, fixed for a fee."] } }, { status: 422 });
        }
        lists = [...lists, { ...standard, id: "pro", name: "Pro", customers: 0 }];
        return Response.json(lists[1]);
      },
    });
    open(<AdminArea />, "/admin/pricing", "/admin");
    fireEvent.click(await screen.findByRole("button", { name: "New price list" }));
    const form = screen.getByRole("form", { name: "New price list" });
    fireEvent.change(within(form).getByLabelText("Id"), { target: { value: "pro" } });
    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: "Pro" } });
    const json = within(form).getByLabelText("Prices (JSON, in the pricing format)") as HTMLTextAreaElement;
    const template = JSON.parse(json.value);
    expect(template).toMatchObject({ currency: "INR", decimals: 2, rules: [{ match: { item: "component" } }, { match: { item: "fee" } }] });

    fireEvent.change(json, { target: { value: "{ not json" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save list" }));
    expect(await within(form).findByText("This is not valid JSON.")).toBeTruthy();
    expect(sent.some((s) => s.method === "PUT")).toBe(false);

    template.id = "pro";
    template.rules[1].price = { perMinute: "1" };
    fireEvent.change(json, { target: { value: JSON.stringify(template) } });
    fireEvent.click(within(form).getByRole("button", { name: "Save list" }));
    expect(await within(form).findByText("Rule 2: `price` is one of markupPercent, fixed for a fee.")).toBeTruthy();

    template.rules[1].price = { markupPercent: "40" };
    fireEvent.change(json, { target: { value: JSON.stringify(template) } });
    fireEvent.click(within(form).getByRole("button", { name: "Save list" }));
    expect(await screen.findByText("Pro", { selector: "strong" })).toBeTruthy();
    expect(screen.queryByRole("form", { name: "New price list" })).toBeNull();
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({ name: "Pro", definition: template });
  });

  it("deletes a list only after asking, and shows why one in use stays", async () => {
    const sent = fakeKit({
      ...adminSignedIn,
      "GET /api/kit/admin/pricing": pricing(),
      "DELETE /api/kit/admin/pricing/lists/standard": () => Response.json({ message: "This is the default plan. Choose another default first." }, { status: 409 }),
    });
    open(<AdminArea />, "/admin/pricing", "/admin");
    fireEvent.click(await screen.findByRole("button", { name: "Delete list" }));
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Yes, delete standard" }));
    expect((await screen.findByRole("alert")).textContent).toBe("This is the default plan. Choose another default first.");
  });
});

describe("admin → a customer's wallet", () => {
  const acme: Customer = {
    id: "w1", name: "Acme Dental", status: "active", syncState: "synced", syncError: null, telenowSubAccountId: "s1",
    balance: "96.58", priceListId: null, maxConcurrentCalls: null, monthlyCapUsd: null, closeRequestedAt: null, closedAt: null,
    createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-30T10:00:00Z",
  };
  const team = { members: [], invites: [], roles: ["owner", "member"], canManage: true };

  function customerWallet(over: Partial<CustomerWallet> = {}): CustomerWallet {
    return {
      currency: "INR", balance: "96.58", plan: null, effectivePlan: "standard", inForce: true, spendLimitUsd: "0.577310",
      spendLimitAt: "2026-10-30T11:41:00Z", calls: { waiting: 0, unpriced: 1 },
      entries: [{ ...charge, telenowCostUsd: "0.0293950000", margin: "0.98" }, { ...credit, telenowCostUsd: null, margin: null }, { ...fee, telenowCostUsd: "5.2500000000", margin: "87.15" }],
      total: 3, page: 1, perPage: 50, ...over,
    };
  }

  it("shows balance, plan, stop and entries with cost and margin, and moves it to another plan", async () => {
    const sent = fakeKit({
      ...adminSignedIn,
      "GET /api/kit/admin/customers/w1": acme,
      "GET /api/kit/admin/customers/w1/team": team,
      "GET /api/kit/admin/customers/w1/wallet?page=1": customerWallet(),
      "GET /api/kit/admin/pricing": pricing({ lists: [standard, { ...standard, id: "pro", name: "Pro" }] }),
      "PUT /api/kit/admin/customers/w1/plan": () => Response.json(customerWallet({ plan: "pro", effectivePlan: "pro" })),
    });
    open(<AdminArea />, "/admin/customers/w1", "/admin");
    expect((await screen.findByTestId("customer-balance")).textContent).toBe("₹96.58");
    expect(screen.getByText("0.577310 USD of its lifetime spend")).toBeTruthy();
    expect(screen.getByRole("status").textContent).toContain("1 call(s) the plan cannot price");
    const rows = within(screen.getByRole("region", { name: "Wallet" })).getAllByRole("row").slice(1);
    expect(within(rows[0]).getAllByRole("cell").slice(1).map((c) => c.textContent)).toEqual(["Call", "-₹3.42", "₹96.58", "$0.0293950000", "₹0.98"]);
    expect(within(rows[2]).getAllByRole("cell")[1].textContent).toBe("Number fee");
    expect((screen.getByLabelText("Plan") as HTMLSelectElement).selectedOptions[0].textContent).toBe("Default (Standard)");

    fireEvent.change(screen.getByLabelText("Plan"), { target: { value: "pro" } });
    await screen.findByDisplayValue("Pro");
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ priceListId: "pro" });
  });

  it("credits it once: a credit sent again after a failure carries the same reference", async () => {
    let tries = 0;
    const sent = fakeKit({
      ...adminSignedIn,
      "GET /api/kit/admin/customers/w1": acme,
      "GET /api/kit/admin/customers/w1/team": team,
      "GET /api/kit/admin/customers/w1/wallet?page=1": customerWallet(),
      "GET /api/kit/admin/pricing": pricing(),
      "POST /api/kit/admin/customers/w1/wallet/credits": () =>
        ++tries === 1 ? Response.json({ message: "Server error" }, { status: 500 }) : Response.json({ ...credit, telenowCostUsd: null, margin: null }, { status: 201 }),
    });
    open(<AdminArea />, "/admin/customers/w1", "/admin");
    const form = await screen.findByRole("form", { name: "Add credit" });
    fireEvent.change(within(form).getByLabelText("Amount"), { target: { value: "500" } });
    fireEvent.change(within(form).getByLabelText("Note"), { target: { value: "Bank transfer" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add credit" }));
    expect((await within(form).findByRole("alert")).textContent).toBe("Server error");
    fireEvent.click(within(form).getByRole("button", { name: "Add credit" }));
    await act(async () => {});
    const posts = sent.filter((s) => s.method === "POST").map((s) => s.body as { amount: string; note: string; reference: string });
    expect(posts).toHaveLength(2);
    expect(posts[1]).toEqual(posts[0]);
    expect(posts[0]).toMatchObject({ amount: "500", note: "Bank transfer" });
    expect(posts[0].reference).toMatch(/^[0-9a-f-]{36}$/);
  });

  it("offers no credit while charging is off, and says where to switch it on", async () => {
    fakeKit({
      ...adminSignedIn,
      "GET /api/kit/admin/customers/w1": acme,
      "GET /api/kit/admin/customers/w1/team": team,
      "GET /api/kit/admin/customers/w1/wallet?page=1": customerWallet({ inForce: false, entries: [], total: 0 }),
      "GET /api/kit/admin/pricing": pricing({ inForce: false }),
    });
    open(<AdminArea />, "/admin/customers/w1", "/admin");
    expect(await screen.findByText("Charging is not switched on yet.")).toBeTruthy();
    expect(screen.queryByRole("form", { name: "Add credit" })).toBeNull();
  });
});

describe("the low-balance email", () => {
  const alert = (over: Partial<Wallet["alert"]> = {}): Wallet["alert"] => ({ lowBalanceAt: null, defaultAt: "100", mayChange: true, ...over });

  it("lets the members who get it choose the amount: zero for only when it runs out, empty for the platform's", async () => {
    const bodies: unknown[] = [];
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/wallet?page=1": wallet({ alert: alert() }),
      "PUT /api/kit/console/wallet/alert": (body: unknown) => {
        bodies.push(body);
        const level = (body as { lowBalanceAt: string | null }).lowBalanceAt;
        return level === "1.234"
          ? Response.json({ message: "x", errors: { lowBalanceAt: ["At most 2 decimal places."] } }, { status: 422 })
          : Response.json(wallet({ entries: [], total: 0, alert: alert({ lowBalanceAt: level }) }));
      },
    });
    open(<ConsoleArea />, "/console/wallet", "/console");
    const input = await screen.findByLabelText("Email me when the balance falls to (INR)");
    expect(screen.getByText("Empty: ₹100, the platform's amount. 0: only when the credit runs out.")).toBeTruthy();
    expect(screen.getByTestId("wallet-alert").textContent).toBe("An email goes when the balance falls to ₹100, and when the credit runs out.");
    const save = (value: string) => {
      fireEvent.change(input, { target: { value } });
      fireEvent.click(screen.getByRole("button", { name: "Save amount" }));
    };

    save("1.234");
    expect(await screen.findByText("At most 2 decimal places.")).toBeTruthy();
    save(" 500 ");
    expect(await screen.findByText("Saved. An email goes when the balance falls to ₹500, and when the credit runs out.")).toBeTruthy();
    expect(screen.getAllByRole("row")).toHaveLength(3);
    save("0");
    expect(await screen.findByText("Saved. An email goes when the credit runs out.")).toBeTruthy();
    save("");
    expect(await screen.findByText("Saved. An email goes when the balance falls to ₹100, and when the credit runs out.")).toBeTruthy();
    expect(bodies).toEqual([{ lowBalanceAt: "1.234" }, { lowBalanceAt: "500" }, { lowBalanceAt: "0" }, { lowBalanceAt: null }]);
  });

  it("only tells other members when it comes, and is not shown before charging is on", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/wallet?page=1": wallet({ alert: alert({ lowBalanceAt: "250", mayChange: false }) }) });
    open(<ConsoleArea />, "/console/wallet", "/console");
    expect((await screen.findByTestId("wallet-alert")).textContent).toBe("Low-balance email: An email goes when the balance falls to ₹250, and when the credit runs out.");
    expect(screen.queryByRole("button", { name: "Save amount" })).toBeNull();
    cleanup();

    fakeKit({ ...signedIn, "GET /api/kit/console/wallet?page=1": wallet({ inForce: false, alert: alert({ defaultAt: null }) }) });
    open(<ConsoleArea />, "/console/wallet", "/console");
    expect(await screen.findByText("Charging is not switched on yet.")).toBeTruthy();
    expect(screen.queryByTestId("wallet-alert")).toBeNull();
    expect(screen.queryByLabelText("Email me when the balance falls to (INR)")).toBeNull();
  });

  it("lets the operator set the platform's amount in admin → pricing, empty for none", async () => {
    const bodies: unknown[] = [];
    fakeKit({
      ...adminSignedIn,
      "GET /api/kit/admin/pricing": pricing({ lowBalanceAt: "100" }),
      "PUT /api/kit/admin/pricing/low-balance": (body: unknown) => {
        bodies.push(body);
        const level = (body as { lowBalanceAt: string | null }).lowBalanceAt;
        return level === "-1"
          ? Response.json({ message: "x", errors: { lowBalanceAt: ["An amount of zero or more."] } }, { status: 422 })
          : Response.json(pricing({ lowBalanceAt: level }));
      },
    });
    open(<AdminArea />, "/admin/pricing", "/admin");
    const input = (await screen.findByLabelText("Email customers when their balance falls to (INR)")) as HTMLInputElement;
    expect(input.value).toBe("100");
    fireEvent.change(input, { target: { value: "-1" } });
    fireEvent.click(screen.getByRole("button", { name: "Save amount" }));
    expect(await screen.findByText("An amount of zero or more.")).toBeTruthy();
    fireEvent.change(input, { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "Save amount" }));
    expect(await screen.findByText("Saved.")).toBeTruthy();
    expect(bodies).toEqual([{ lowBalanceAt: "-1" }, { lowBalanceAt: null }]);
    expect(screen.queryByText("An amount of zero or more.")).toBeNull();
  });

  it("names no currency on the platform's amount before one is set", async () => {
    fakeKit({ ...adminSignedIn, "GET /api/kit/admin/pricing": pricing({ currency: null, usdRate: null, defaultPlan: null, inForce: false, lists: [] }) });
    open(<AdminArea />, "/admin/pricing", "/admin");
    expect(await screen.findByLabelText("Email customers when their balance falls to")).toBeTruthy();
    expect(screen.queryByText(/\(\)/)).toBeNull();
  });
});
