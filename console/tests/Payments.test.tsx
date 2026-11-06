/**
 * Paying in the console and admin (wallet/AddCredit.tsx, wallet/WalletPage.tsx, admin/PaymentsPage.tsx):
 * - a customer picks one of the ways to pay it is offered and an amount, and goes on to the gateway (a redirect, or a
 *   form posted there) — or, for a bank transfer, sees the instructions and the reference to quote; the backend's
 *   word on a refused amount shows beside it; a paused account, nothing offered, and no wallet each say so;
 * - back from a gateway, the payment is read again while it is open, and the wallet reloaded once it is paid;
 * - wallet entries name a top-up, a refund and a disputed amount held and given back;
 * - staff save the top-up rules; add a way to pay from its driver's own form (a bank transfer's instructions, an
 *   online gateway's keys, methods and plans), see where an online one sends its events and whether one came, enable
 *   it, test it end to end; confirm a bank transfer with the amount that arrived, give one up after asking, refund
 *   exactly what is left — a refund sent again after a failure carries the same reference — and remove a way to pay
 *   only after asking.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactElement } from "react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AdminPayment, ConsolePayment, PaymentGateway, PaymentsAdmin, SetupField, TopUp, Wallet } from "../src/api";
import { PaymentsPage } from "../src/admin/PaymentsPage";
import { AddCredit } from "../src/wallet/AddCredit";
import { WalletPage } from "../src/wallet/WalletPage";
import { rounded, subtract } from "../src/wallet/money";
import { fakeKit } from "./fakeKit";

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

function nav() {
  return { assign: vi.fn(), post: vi.fn() };
}

const bank = { id: "g1", name: "Bank transfer (NEFT)", online: false, methods: [], asks: [] };
const cards = { id: "g2", name: "Cards and UPI", online: true, methods: ["Cards", "UPI"], asks: [] };

function payment(over: Partial<ConsolePayment> = {}): ConsolePayment {
  return { id: "p1", amount: "500.00", currency: "INR", status: "open", gatewayName: "Cards and UPI", refunded: "0", instructions: null, reference: null,
    createdAt: "2026-10-31T10:00:00Z", paidAt: null, ...over };
}

function topUp(over: Partial<TopUp> = {}): TopUp {
  return { inForce: true, currency: "INR", decimals: 2, minAmount: "100", maxAmount: "50000", paused: null, gateways: [bank, cards], payments: [], pollSeconds: 0.01, ...over };
}

describe("adding credit", () => {
  it("goes on to the gateway's checkout with the way to pay and the amount", async () => {
    const sent = fakeKit({
      "GET /api/kit/console/payments": topUp(),
      "POST /api/kit/console/payments": () => Response.json({ payment: payment(), checkout: { kind: "redirect", url: "https://pay.example/ord_1", fields: {}, instructions: null, reference: null } }, { status: 201 }),
    });
    const n = nav();
    at("/console/wallet", <AddCredit onPaid={() => undefined} nav={n} />);
    const form = await screen.findByRole("form", { name: "Add credit" });
    expect(within(form).getByText("Between ₹100 and ₹50,000.")).toBeTruthy();
    expect(within(form).getByText(/Cards, UPI/)).toBeTruthy();
    fireEvent.click(within(form).getByLabelText(/Cards and UPI/));
    fireEvent.change(within(form).getByLabelText("Amount (INR)"), { target: { value: " 500 " } });
    fireEvent.click(within(form).getByRole("button", { name: "Continue to pay" }));
    await waitFor(() => expect(n.assign).toHaveBeenCalledWith("https://pay.example/ord_1"));
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ gatewayId: "g2", amount: "500" });
    expect(n.post).not.toHaveBeenCalled();
  });

  it("posts the fields to a gateway that takes its order by form", async () => {
    fakeKit({
      "GET /api/kit/console/payments": topUp(),
      "POST /api/kit/console/payments": () => Response.json({ payment: payment(), checkout: { kind: "post", url: "https://pay.example/_payment", fields: { key: "k", hash: "h" }, instructions: null, reference: null } }, { status: 201 }),
    });
    const n = nav();
    at("/console/wallet", <AddCredit onPaid={() => undefined} nav={n} />);
    fireEvent.change(await screen.findByLabelText("Amount (INR)"), { target: { value: "500" } });
    fireEvent.click(screen.getByRole("button", { name: "Continue to pay" }));
    await waitFor(() => expect(n.post).toHaveBeenCalledWith("https://pay.example/_payment", { key: "k", hash: "h" }));
  });

  it("asks for an email only when the way to pay needs it, shows the backend's word on it, and sends it trimmed", async () => {
    let refuse = true;
    const sent = fakeKit({
      "GET /api/kit/console/payments": topUp({ gateways: [bank, { ...cards, asks: ["email"] }] }),
      "POST /api/kit/console/payments": () => {
        if (refuse) return Response.json({ message: "x", errors: { "payer.email": ["The Email must be a valid email address."] } }, { status: 422 });
        return Response.json({ payment: payment(), checkout: { kind: "redirect", url: "https://pay.example/ord_1", fields: {}, instructions: null, reference: null } }, { status: 201 });
      },
    });
    const n = nav();
    at("/console/wallet", <AddCredit onPaid={() => undefined} nav={n} />);
    const form = await screen.findByRole("form", { name: "Add credit" });
    expect(within(form).queryByLabelText("Email")).toBeNull();
    expect(within(form).queryByText("This way to pay needs these for this payment. They are not kept.")).toBeNull();

    fireEvent.click(within(form).getByLabelText(/Cards and UPI/));
    expect(within(form).getByText("This way to pay needs these for this payment. They are not kept.")).toBeTruthy();
    fireEvent.change(within(form).getByLabelText("Amount (INR)"), { target: { value: "500" } });
    fireEvent.click(within(form).getByRole("button", { name: "Continue to pay" }));
    expect(await within(form).findByText("The Email must be a valid email address.")).toBeTruthy();
    expect(within(form).getByLabelText("Email").getAttribute("aria-invalid")).toBe("true");
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ gatewayId: "g2", amount: "500", payer: { email: "" } });

    refuse = false;
    fireEvent.change(within(form).getByLabelText("Email"), { target: { value: " ben@clinic.example " } });
    fireEvent.click(within(form).getByRole("button", { name: "Continue to pay" }));
    await waitFor(() => expect(n.assign).toHaveBeenCalledWith("https://pay.example/ord_1"));
    expect(sent.filter((s) => s.method === "POST")[1].body).toEqual({ gatewayId: "g2", amount: "500", payer: { email: "ben@clinic.example" } });
  });

  it("does not send an email typed for another way to pay", async () => {
    const sent = fakeKit({
      "GET /api/kit/console/payments": topUp({ gateways: [{ ...cards, asks: ["email"] }, { ...bank, id: "g3", online: true, name: "No email needed" }] }),
      "POST /api/kit/console/payments": () => Response.json({ payment: payment(), checkout: { kind: "redirect", url: "https://pay.example/ord_1", fields: {}, instructions: null, reference: null } }, { status: 201 }),
    });
    const n = nav();
    at("/console/wallet", <AddCredit onPaid={() => undefined} nav={n} />);
    fireEvent.change(await screen.findByLabelText("Email"), { target: { value: "ben@clinic.example" } });
    fireEvent.click(screen.getByLabelText(/No email needed/));
    expect(screen.queryByLabelText("Email")).toBeNull();
    fireEvent.change(screen.getByLabelText("Amount (INR)"), { target: { value: "500" } });
    fireEvent.click(screen.getByRole("button", { name: "Continue to pay" }));
    await waitFor(() => expect(n.assign).toHaveBeenCalled());
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ gatewayId: "g3", amount: "500" });
  });

  it("shows a bank transfer's instructions and its reference, then lists it", async () => {
    let started = false;
    fakeKit({
      "GET /api/kit/console/payments": () => Response.json(topUp({ payments: started ? [payment({ gatewayName: "Bank transfer (NEFT)", reference: "K7QX2M9A", instructions: "Pay to account 123" })] : [] })),
      "POST /api/kit/console/payments": () => {
        started = true;
        return Response.json({ payment: payment({ reference: "K7QX2M9A" }), checkout: { kind: "instructions", url: null, fields: {}, instructions: "Pay to Acme Voice\nIFSC HDFC0000001", reference: "K7QX2M9A" } }, { status: 201 });
      },
    });
    const n = nav();
    at("/console/wallet", <AddCredit onPaid={() => undefined} nav={n} />);
    fireEvent.change(await screen.findByLabelText("Amount (INR)"), { target: { value: "500" } });
    fireEvent.click(screen.getByRole("button", { name: "Continue to pay" }));
    expect(await screen.findByText("Send ₹500.00 as these instructions say:")).toBeTruthy();
    expect(screen.getByText(/IFSC HDFC0000001/).textContent).toBe("Pay to Acme Voice\nIFSC HDFC0000001");
    expect(screen.getByTestId("reference").textContent).toBe("K7QX2M9A");
    const list = await screen.findByRole("table", { name: "Your payments" });
    expect(within(list).getAllByRole("cell").map((c) => c.textContent).slice(1)).toEqual(["₹500.00", "Waiting · reference K7QX2M9A", "Bank transfer (NEFT)"]);
    expect(n.assign).not.toHaveBeenCalled();
  });

  it("shows the backend's word on a refused amount", async () => {
    fakeKit({
      "GET /api/kit/console/payments": topUp(),
      "POST /api/kit/console/payments": () => Response.json({ message: "x", errors: { amount: ["The smallest top-up is 100.00 INR."] } }, { status: 422 }),
    });
    at("/console/wallet", <AddCredit onPaid={() => undefined} nav={nav()} />);
    fireEvent.change(await screen.findByLabelText("Amount (INR)"), { target: { value: "50" } });
    fireEvent.click(screen.getByRole("button", { name: "Continue to pay" }));
    expect(await screen.findByText("The smallest top-up is 100.00 INR.")).toBeTruthy();
  });

  it("says why it cannot start one, says when nothing is offered, and stays away while there is no wallet", async () => {
    fakeKit({ "GET /api/kit/console/payments": topUp({ paused: "This account is suspended." }) });
    at("/console/wallet", <AddCredit onPaid={() => undefined} />);
    expect(await screen.findByText("This account is suspended.")).toBeTruthy();
    expect(screen.queryByRole("form")).toBeNull();
    cleanup();
    fakeKit({ "GET /api/kit/console/payments": topUp({ gateways: [] }) });
    at("/console/wallet", <AddCredit onPaid={() => undefined} />);
    expect(await screen.findByText("There is no way to pay yet. Contact support to add credit.")).toBeTruthy();
    cleanup();
    const sent = fakeKit({ "GET /api/kit/console/payments": topUp({ inForce: false }) });
    at("/console/wallet", <AddCredit onPaid={() => undefined} />);
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(screen.queryByRole("heading", { name: "Add credit" })).toBeNull();
  });

  it("back from the gateway, waits while the payment is open and reloads the wallet once it is paid", async () => {
    let reads = 0;
    const sent = fakeKit({
      "GET /api/kit/console/payments": topUp(),
      "GET /api/kit/console/payments/p1": () => Response.json(payment(++reads < 3 ? {} : { status: "paid" })),
    });
    const onPaid = vi.fn();
    at("/console/wallet?payment=p1", <AddCredit onPaid={onPaid} />);
    expect((await screen.findByRole("status")).textContent).toBe("Waiting for your payment of ₹500.00 to be confirmed…");
    await waitFor(() => expect(screen.getByRole("status").textContent).toBe("₹500.00 was added to your wallet."));
    expect(onPaid).toHaveBeenCalledTimes(1);
    expect(reads).toBe(3);
    await new Promise((r) => setTimeout(r, 50));
    expect(sent.filter((s) => s.path === "/api/kit/console/payments/p1")).toHaveLength(3);
  });

  it("says when a payment did not go through, or is being checked", async () => {
    fakeKit({ "GET /api/kit/console/payments": topUp(), "GET /api/kit/console/payments/p1": payment({ status: "failed" }) });
    const onPaid = vi.fn();
    at("/console/wallet?payment=p1", <AddCredit onPaid={onPaid} />);
    expect((await screen.findByRole("status")).textContent).toBe("Your payment of ₹500.00 did not go through. Nothing was charged to your wallet.");
    cleanup();
    fakeKit({ "GET /api/kit/console/payments": topUp(), "GET /api/kit/console/payments/p1": payment({ status: "review" }) });
    at("/console/wallet?payment=p1", <AddCredit onPaid={onPaid} />);
    expect((await screen.findByRole("status")).textContent).toBe("We received your payment of ₹500.00 and are checking it.");
    expect(onPaid).not.toHaveBeenCalled();
  });

  it("names a top-up, a refund and a disputed amount held and given back", async () => {
    const entry = (id: number, kind: Wallet["entries"][number]["kind"], amount: string, paymentId: string | null) =>
      ({ id, kind, amount, currency: "INR", balanceAfter: "0", note: null, callId: null, numberId: null, paymentId, createdAt: "2026-10-31T10:00:00Z" });
    fakeKit({
      "GET /api/kit/console/payments": topUp({ gateways: [] }),
      "GET /api/kit/console/wallet?page=1": { currency: "INR", balance: "0", inForce: true, total: 5, page: 1, perPage: 50,
        alert: { lowBalanceAt: null, defaultAt: null, mayChange: false },
        entries: [entry(6, "refund", "50.00", "p1"), entry(5, "release", "100", "p1"), entry(4, "hold", "-100", "p1"), entry(3, "refund", "-50", "p1"), entry(2, "credit", "500", "p1"), entry(1, "credit", "100", null)] },
    });
    at("/console/wallet", <WalletPage />);
    const table = await screen.findByRole("table");
    expect(within(table).getAllByRole("row").slice(1).map((r) => within(r).getAllByRole("cell")[1].textContent))
      .toEqual(["Refund failed, given back", "Disputed payment given back", "Disputed payment held", "Refund", "Top-up", "Credit"]);
  });
});

function field(over: Partial<SetupField> & { name: string; label: string }): SetupField {
  return { type: "text", required: true, value: null, help: null, options: [], showWhen: null, secretSet: false, ...over };
}

const manualDriver = { id: "manual", name: "Bank transfer", online: false, fields: [field({ name: "instructions", label: "How to pay", type: "textarea" })], methods: [], modes: [], partialRefunds: true };
const standinDriver = {
  id: "standin", name: "Stand-in", online: true, partialRefunds: true,
  fields: [field({ name: "keyId", label: "Key id" }), field({ name: "keySecret", label: "Key secret", type: "secret" })],
  methods: [{ value: "card", label: "Cards" }, { value: "upi", label: "UPI" }],
  modes: [{ value: "test", label: "Test" }, { value: "live", label: "Live" }],
};

function gateway(over: Partial<PaymentGateway> = {}): PaymentGateway {
  return { id: "g2", driver: "standin", driverName: "Stand-in", name: "Cards and UPI", mode: "test", online: true, enabled: false, methods: ["card"], plans: null,
    takesCurrency: true, asks: [], webhookUrl: "https://voice.acme.example/api/kit/payments/webhooks/g2", webhookEvents: ["payment.paid", "refund.processed"], lastEventAt: null,
    fields: [field({ name: "keyId", label: "Key id", value: "sk_test_acme" }), field({ name: "keySecret", label: "Key secret", type: "secret", secretSet: true })], ...over };
}

function adminPayment(over: Partial<AdminPayment> = {}): AdminPayment {
  return { id: "p1", customer: { id: "w1", name: "Acme Dental" }, gatewayName: "Bank transfer (NEFT)", amount: "500.00", currency: "INR", status: "open", received: null,
    receivedCurrency: null, refunded: "0", refundFailed: false, dispute: null, reference: "K7QX2M9A", error: null, createdAt: "2026-10-31T10:00:00Z", paidAt: null,
    canConfirm: true, canCancel: true, canRefund: false, partialRefunds: true, ...over };
}

function admin(over: Partial<PaymentsAdmin> = {}): PaymentsAdmin {
  return { currency: "INR", inForce: true, settings: { minAmount: null, maxAmount: null, disputeAction: "hold" },
    disputeActions: [{ value: "nothing", label: "Nothing" }, { value: "hold", label: "Hold" }, { value: "suspend", label: "Suspend" }],
    maxGateways: 20, plans: [{ id: "standard", name: "Standard" }, { id: "gold", name: "Gold" }], drivers: [manualDriver, standinDriver],
    gateways: [], waiting: [], recent: [], ...over };
}

describe("Admin → Payments", () => {
  it("saves the top-up rules, an empty limit as none", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin(),
      "PUT /api/kit/admin/payments/settings": (b: unknown) => Response.json(admin({ settings: b as PaymentsAdmin["settings"] })),
    });
    at("/admin/payments", <PaymentsPage />);
    const form = await screen.findByRole("form", { name: "Top-up rules" });
    fireEvent.change(within(form).getByLabelText("Smallest top-up (INR)"), { target: { value: " 100 " } });
    fireEvent.change(within(form).getByLabelText("When a payment is disputed"), { target: { value: "suspend" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(form).findByText("Saved.")).toBeTruthy();
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ minAmount: "100", maxAmount: null, disputeAction: "suspend" });
  });

  it("adds a bank transfer from its driver's form, with nothing online about it", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin(),
      "POST /api/kit/admin/payments/gateways": () => Response.json(admin({ gateways: [gateway({ id: "g1", driver: "manual", driverName: "Bank transfer", name: "NEFT", online: false, mode: "live", methods: [], webhookUrl: null, webhookEvents: [], fields: [] })] }), { status: 201 }),
    });
    at("/admin/payments", <PaymentsPage />);
    const form = await screen.findByRole("form", { name: "Add" });
    expect(within(form).queryByLabelText("Mode")).toBeNull();
    fireEvent.change(within(form).getByLabelText("Name customers see"), { target: { value: " NEFT " } });
    fireEvent.change(within(form).getByLabelText("How to pay"), { target: { value: "Pay to account 123\nIFSC X" } });
    fireEvent.click(within(form).getByLabelText("Gold"));
    fireEvent.click(within(form).getByRole("button", { name: "Add" }));
    expect(await screen.findByText(/NEFT/, { selector: "strong" })).toBeTruthy();
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ driver: "manual", name: "NEFT", values: { instructions: "Pay to account 123\nIFSC X" }, plans: ["gold"] });
  });

  it("adds an online gateway with its mode and methods, and shows the gateway's refusal of its keys", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin(),
      "POST /api/kit/admin/payments/gateways": () => (++tries === 1
        ? Response.json({ message: "The gateway did not accept these details: Authentication failed" }, { status: 422 })
        : Response.json(admin({ gateways: [gateway()] }), { status: 201 })),
    });
    at("/admin/payments", <PaymentsPage />);
    fireEvent.change(await screen.findByLabelText("Gateway"), { target: { value: "standin" } });
    const form = await screen.findByRole("form", { name: "Add" });
    fireEvent.change(within(form).getByLabelText("Key id"), { target: { value: "sk_test_acme" } });
    fireEvent.change(within(form).getByLabelText("Key secret"), { target: { value: "wrong" } });
    fireEvent.click(within(form).getByLabelText("UPI"));
    fireEvent.click(within(form).getByRole("button", { name: "Add" }));
    expect((await within(form).findByRole("alert")).textContent).toBe("The gateway did not accept these details: Authentication failed");
    fireEvent.change(within(form).getByLabelText("Key secret"), { target: { value: "test secret" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add" }));
    expect(await screen.findByText("Waiting for its first verified event.")).toBeTruthy();
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({
      driver: "standin", name: "Stand-in", mode: "test", methods: ["card"], plans: null, values: { keyId: "sk_test_acme", keySecret: "test secret" },
    });
    expect(screen.getByText("https://voice.acme.example/api/kit/payments/webhooks/g2")).toBeTruthy();
    expect(screen.getByText("Events to tick: payment.paid, refund.processed.")).toBeTruthy();
  });

  it("enables a gateway, edits it keeping its secret, warns when it takes not the currency, and tests it end to end", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin({ gateways: [gateway({ takesCurrency: false })], settings: { minAmount: "100", maxAmount: null, disputeAction: "hold" } }),
      "PUT /api/kit/admin/payments/gateways/g2": (b: unknown) => Response.json(admin({ gateways: [gateway({ ...(b as object), lastEventAt: "2026-10-31T10:00:00Z" })] })),
      "POST /api/kit/admin/payments/gateways/g2/test": () => Response.json({ payment: adminPayment({ customer: null }), checkout: { kind: "redirect", url: "https://pay.example/ord_t", fields: {}, instructions: null, reference: null } }, { status: 201 }),
    });
    const n = nav();
    at("/admin/payments", <PaymentsPage nav={n} />);
    expect(await screen.findByText("It does not take INR, so it is offered to no one.")).toBeTruthy();
    const test = screen.getByRole("form", { name: "Make a test payment" });
    expect((within(test).getByLabelText("Test amount (INR)") as HTMLInputElement).value).toBe("100");
    fireEvent.click(within(test).getByRole("button", { name: "Make a test payment" }));
    await waitFor(() => expect(n.assign).toHaveBeenCalledWith("https://pay.example/ord_t"));
    expect(sent.find((s) => s.path.endsWith("/test"))?.body).toEqual({ amount: "100" });

    fireEvent.click(screen.getByRole("button", { name: "Edit" }));
    const edit = screen.getByRole("form", { name: "Save" });
    expect((within(edit).getByLabelText("Key secret") as HTMLInputElement).value).toBe("");
    fireEvent.change(within(edit).getByLabelText("Mode"), { target: { value: "live" } });
    fireEvent.click(within(edit).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(screen.queryByRole("form", { name: "Save" })).toBeNull());
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({
      name: "Cards and UPI", mode: "live", methods: ["card"], plans: null, values: { keyId: "sk_test_acme", keySecret: "" },
    });

    fireEvent.click(screen.getByRole("button", { name: "Enable" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({ enabled: true }));
    expect(await screen.findByText(/Last verified event:/)).toBeTruthy();
  });

  it("removes a way to pay only after asking", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/payments": () => Response.json(admin({ gateways: sent.some((s) => s.method === "DELETE") ? [] : [gateway()] })),
      "DELETE /api/kit/admin/payments/gateways/g2": () => new Response(null, { status: 204 }),
    });
    at("/admin/payments", <PaymentsPage />);
    fireEvent.click(await screen.findByRole("button", { name: "Remove" }));
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    expect(screen.getByText("Remove Cards and UPI? Its payments stay, and late refunds and disputes still arrive.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, remove" }));
    expect(await screen.findByText("No way to pay yet: customers get credit only when you add it by hand.")).toBeTruthy();
  });

  it("confirms a bank transfer with the amount that arrived, and gives one up only after asking", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin({ waiting: [adminPayment()] }),
      "POST /api/kit/admin/payments/p1/confirm": () => Response.json(admin({ recent: [adminPayment({ status: "paid", received: "499.50", canConfirm: false, canCancel: false, canRefund: true })] })),
    });
    at("/admin/payments", <PaymentsPage />);
    const waiting = await screen.findByRole("table", { name: "Waiting for you" });
    fireEvent.click(within(waiting).getByRole("button", { name: "Cancel" }));
    expect(within(waiting).getByText("Give up on this payment?")).toBeTruthy();
    fireEvent.click(within(waiting).getByRole("button", { name: "Cancel" }));
    expect(sent.some((s) => s.path.endsWith("/cancel"))).toBe(false);

    fireEvent.click(within(waiting).getByRole("button", { name: "Confirm received" }));
    const input = within(waiting).getByLabelText("Amount that arrived (INR)") as HTMLInputElement;
    expect(input.value).toBe("500.00");
    fireEvent.change(input, { target: { value: "499.50" } });
    fireEvent.click(within(waiting).getByRole("button", { name: "Confirm received" }));
    const recent = await screen.findByRole("table", { name: "Recent payments" });
    expect(within(recent).getByText(/499.50 received/)).toBeTruthy();
    expect(sent.find((s) => s.path.endsWith("/confirm"))?.body).toEqual({ amount: "499.50" });
    expect(screen.getByText("Nothing to confirm.")).toBeTruthy();
  });

  it("refunds exactly what is left, and a refund sent again carries the same reference", async () => {
    let tries = 0;
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin({ recent: [adminPayment({ status: "paid", received: "499.50", refunded: "200", canConfirm: false, canCancel: false, canRefund: true })] }),
      "POST /api/kit/admin/payments/p1/refunds": () => (++tries === 1
        ? Response.json({ message: "The gateway did not confirm the refund (timeout). Sending the same refund again refunds at most once." }, { status: 502 })
        : Response.json(admin({ recent: [adminPayment({ status: "paid", received: "499.50", refunded: "499.50", canConfirm: false, canCancel: false, canRefund: true })] }))),
    });
    at("/admin/payments", <PaymentsPage />);
    const recent = await screen.findByRole("table", { name: "Recent payments" });
    fireEvent.click(within(recent).getByRole("button", { name: "Refund" }));
    expect((within(recent).getByLabelText("Amount to refund (INR)") as HTMLInputElement).value).toBe("299.50");
    fireEvent.click(within(recent).getByRole("button", { name: "Refund" }));
    expect((await within(recent).findByRole("alert")).textContent).toBe("The gateway did not confirm the refund (timeout). Sending the same refund again refunds at most once.");
    fireEvent.click(within(recent).getByRole("button", { name: "Refund" }));
    await waitFor(() => expect(within(recent).queryByRole("button", { name: "Refund" })).toBeNull());
    const bodies = sent.filter((s) => s.path.endsWith("/refunds")).map((s) => s.body as { amount: string; reference: string });
    expect(bodies).toHaveLength(2);
    expect(bodies[0]).toEqual(bodies[1]);
    expect(bodies[0].amount).toBe("299.50");
    expect(bodies[0].reference).toMatch(/^[0-9a-f-]{36}$/);
  });

  it("tells staff when a refund failed at the bank, and only then", async () => {
    fakeKit({ "GET /api/kit/admin/payments": admin({ recent: [
      adminPayment({ id: "r1", status: "paid", canConfirm: false, canCancel: false, refundFailed: true }),
      adminPayment({ id: "r2", status: "paid", canConfirm: false, canCancel: false, refunded: "10.00" }),
    ] }) });
    at("/admin/payments", <PaymentsPage />);
    const recent = await screen.findByRole("table", { name: "Recent payments" });
    const note = "A refund failed at the bank. Its amount is back on the customer's wallet, and they have not been paid back.";
    expect(within(recent).getAllByText(note, { exact: false })).toHaveLength(1);
    expect(recent.querySelector('[data-payment="r1"]')?.textContent).toContain(note);
    expect(recent.querySelector('[data-payment="r2"]')?.textContent).not.toContain(note);
  });

  it("asks staff for an email for a test payment when their account has none", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin({ gateways: [gateway({ asks: ["email"] })] }),
      "POST /api/kit/admin/payments/gateways/g2/test": () => Response.json({ payment: adminPayment(), checkout: { kind: "redirect", url: "https://pay.example/test", fields: {}, instructions: null, reference: null } }, { status: 201 }),
    });
    const n = nav();
    at("/admin/payments", <PaymentsPage nav={n} />);
    const form = await screen.findByRole("form", { name: "Make a test payment" });
    fireEvent.change(within(form).getByLabelText("Email"), { target: { value: " op@voice.example " } });
    fireEvent.click(within(form).getByRole("button", { name: "Make a test payment" }));
    await waitFor(() => expect(n.assign).toHaveBeenCalledWith("https://pay.example/test"));
    expect(sent.find((s) => s.path.endsWith("/test"))?.body).toMatchObject({ payer: { email: "op@voice.example" } });
  });

  it("asks staff for nothing more on a test payment when their account has what it needs", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/payments": admin({ gateways: [gateway()] }),
      "POST /api/kit/admin/payments/gateways/g2/test": () => Response.json({ payment: adminPayment(), checkout: { kind: "redirect", url: "https://pay.example/test", fields: {}, instructions: null, reference: null } }, { status: 201 }),
    });
    const n = nav();
    at("/admin/payments", <PaymentsPage nav={n} />);
    const form = await screen.findByRole("form", { name: "Make a test payment" });
    expect(within(form).queryByLabelText("Email")).toBeNull();
    fireEvent.click(within(form).getByRole("button", { name: "Make a test payment" }));
    await waitFor(() => expect(n.assign).toHaveBeenCalled());
    expect((sent.find((s) => s.path.endsWith("/test"))?.body as { payer?: unknown }).payer).toBeUndefined();
  });

  it("says how a test payment went when the gateway sends staff back", async () => {
    fakeKit({ "GET /api/kit/admin/payments": admin({ recent: [adminPayment({ id: "t1", customer: null, status: "paid", canConfirm: false, canCancel: false })] }) });
    at("/admin/payments?payment=t1", <PaymentsPage />);
    expect((await screen.findByRole("status")).textContent).toBe("Test payment of ₹500.00: Received.");
    expect(screen.getByText("Test payment", { selector: "td" })).toBeTruthy();
  });
});

describe("subtract", () => {
  it("is exact on decimal strings", () => {
    expect([subtract("499.50", "200"), subtract("100.10", "0.2"), subtract("5", "5.00"), subtract("1", "2.5"), subtract("0.3", "0.1")])
      .toEqual(["299.50", "99.90", "0.00", "-1.5", "0.2"]);
  });
});

describe("rounded", () => {
  it("rounds half away from zero, exactly", () => {
    expect([rounded("40.0000000000", 2), rounded("4.4949999999", 2), rounded("4.495", 2), rounded("-0.125", 2), rounded("-0.001", 2), rounded("0", 2), rounded("12.3", 2), rounded("99.995", 2)])
      .toEqual(["40.00", "4.49", "4.50", "-0.13", "0.00", "0.00", "12.30", "100.00"]);
  });
});
