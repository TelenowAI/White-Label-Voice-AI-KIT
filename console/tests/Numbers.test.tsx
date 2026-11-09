/**
 * Console → Numbers (numbers/): the account's numbers in the console's words (where, the agent answering, rent and
 * renewal, a change not saved yet or refused, a release not through yet), every saved agent to choose from (all pages),
 * choosing one (the backend's word on it), finding numbers for sale (the backend's word on a field), buying one only
 * after its price is confirmed, and releasing one only after asking. The controls under each agent, the renewals and the lost numbers:
 * NumberControls.test.tsx.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AvailableNumber, ConsoleHome, PhoneNumber, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { agentSettings } from "./agentSettings";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };

function agent(id: string, name: string): Agent {
  return {
    id, name, description: null, systemPrompt: null, isActive: true,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: agentSettings,
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [], logoUrl: null,
    syncState: "synced", syncError: null, createdAt: "2026-10-30T09:00:00Z", updatedAt: "2026-10-30T09:00:00Z",
  };
}

const frontDesk = agent("a1", "Front desk");
const billing = agent("a2", "Billing");
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/agents?page=1": { agents: [frontDesk], total: 2, page: 1, perPage: 1 },
  "GET /api/kit/console/agents?page=2": { agents: [billing], total: 2, page: 2, perPage: 1 },
  "GET /api/kit/console/numbers/renewals": { charging: false, balance: null, soonDays: 7, numbers: [] },
  "GET /api/kit/console/numbers/lost": { numbers: [] },
};

function number(over: Partial<PhoneNumber> & { id: string }): PhoneNumber {
  return {
    number: "+918045671001", country: "India", region: "Karnataka", type: "local", agent: null,
    monthlyPrice: { amount: "522.90", currency: "INR" }, nextRenewalAt: "2026-11-30T13:32:12+00:00", releasing: false,
    routed: false, agentCallerId: false, unanswered: "message", syncState: "synced", syncError: null, createdAt: "2026-10-30T13:32:12+00:00", ...over,
  };
}

const mine = [
  number({ id: "n1", agent: { id: "a1", name: "Front desk" } }),
  number({ id: "n2", number: "+918045671002", monthlyPrice: null, syncState: "failed", syncError: "This could not be done. Try again, or ask support." }),
  number({ id: "n3", number: "+918045671003", releasing: true, syncState: "pending", syncError: "Not saved yet. It will be tried again shortly." }),
  number({ id: "n4", number: "+918045671004", releasing: true, syncState: "failed", syncError: "This could not be done. Try again, or ask support." }),
];

function offer(n: string): AvailableNumber {
  return { number: n, country: "India", region: "Karnataka", type: "local", voice: true, sms: false,
    setupPrice: { amount: "0.00", currency: "INR" }, monthlyPrice: { amount: "522.90", currency: "INR" } };
}

function open() {
  render(
    <MemoryRouter initialEntries={["/console/numbers"]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

async function yours() {
  return within(await screen.findByRole("table", { name: "Your numbers" })).getAllByRole("row").slice(1);
}

describe("the numbers", () => {
  it("lists each number in the console's words, a release not through yet without an agent to choose", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/numbers": { numbers: mine } });
    open();
    const rows = await yours();
    expect(rows.map((r) => within(r).getAllByRole("cell").map((c) => c.textContent))).toEqual([
      ["+918045671001", "Karnataka, India", "No agentFront deskBillingFront desk's caller idFront desk's calls come from this number when a call names none.", "₹522.90",
        new Date("2026-11-30T13:32:12+00:00").toLocaleDateString(), "Release"],
      ["+918045671002FailedThis could not be done. Try again, or ask support.", "Karnataka, India",
        "No agentFront deskBillingWhen no agent answersSay no one is availableA busy lineLine unavailableA busy line or an unavailable line does not answer the call.", "Not priced",
        new Date("2026-11-30T13:32:12+00:00").toLocaleDateString(), "Release"],
      ["+918045671003Being releasedNot saved yet. It will be tried again shortly.", "Karnataka, India", "No agent", "₹522.90", "", ""],
      ["+918045671004FailedThis could not be done. Try again, or ask support.", "Karnataka, India", "No agent", "₹522.90", "", "Try releasing again"],
    ]);
    expect((within(rows[0]).getByLabelText("Agent answering +918045671001") as HTMLSelectElement).value).toBe("a1");
  });

  it("chooses the agent that answers, with the backend's word on it", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/numbers": { numbers: [mine[0]] },
      "PUT /api/kit/console/numbers/n1/agent": (body: unknown) => {
        const { agentId } = body as { agentId: string | null };
        if (agentId === "a2") return Response.json({ message: "x", errors: { agentId: ["Choose one of your saved agents."] } }, { status: 422 });
        return Response.json(number({ id: "n1", agent: agentId ? { id: agentId, name: "Front desk" } : null }));
      },
    });
    open();
    const select = within((await yours())[0]).getByLabelText("Agent answering +918045671001") as HTMLSelectElement;
    await waitFor(() => expect(select.options).toHaveLength(3));
    fireEvent.change(select, { target: { value: "" } });
    await waitFor(() => expect(select.value).toBe(""));
    expect(sent.filter((s) => s.method === "PUT").map((s) => s.body)).toEqual([{ agentId: null }]);
    fireEvent.change(select, { target: { value: "a2" } });
    expect((await screen.findByRole("alert")).textContent).toBe("Choose one of your saved agents.");
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({ agentId: "a2" });
  });

  it("finds numbers for sale and buys one only after its price is confirmed", async () => {
    let bought = false;
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/numbers": () => Response.json({ numbers: bought ? [mine[0]] : [] }),
      "GET /api/kit/console/numbers/available?country=IN&pattern=8045": { numbers: [offer("+918045671001"), offer("+918045671002")] },
      "POST /api/kit/console/numbers": () => {
        bought = true;
        return Response.json(mine[0], { status: 201 });
      },
    });
    open();
    expect(await screen.findByText("No numbers yet. Find one below.")).toBeTruthy();
    const form = screen.getByRole("search", { name: "Find a number" });
    fireEvent.change(within(form).getByLabelText("Country code"), { target: { value: " IN " } });
    fireEvent.change(within(form).getByLabelText("Digits it contains"), { target: { value: "8045" } });
    fireEvent.click(within(form).getByRole("button", { name: "Search" }));
    const forSale = within(await screen.findByRole("table", { name: "Numbers for sale" })).getAllByRole("row").slice(1);
    expect(forSale.map((r) => within(r).getAllByRole("cell").slice(0, 4).map((c) => c.textContent))).toEqual([
      ["+918045671001", "Karnataka, India", "₹0.00", "₹522.90"],
      ["+918045671002", "Karnataka, India", "₹0.00", "₹522.90"],
    ]);

    fireEvent.click(within(forSale[0]).getByRole("button", { name: "Buy" }));
    expect(within(forSale[0]).getByText("Buy +918045671001 for ₹0.00 now, then ₹522.90 a month?")).toBeTruthy();
    expect(sent.some((s) => s.method === "POST")).toBe(false);
    fireEvent.click(within(forSale[0]).getByRole("button", { name: "Yes, buy" }));
    expect((await screen.findByRole("status")).textContent).toBe("+918045671001 is yours.");
    expect(sent.filter((s) => s.method === "POST").map((s) => s.body)).toEqual([{ number: "+918045671001", country: "IN" }]);
    await waitFor(() => expect(screen.getByRole("table", { name: "Numbers for sale" }).querySelectorAll("tbody tr")).toHaveLength(1));
    expect(await yours()).toHaveLength(1);
  });

  it("says the backend's word on a search field and on a refused purchase", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/numbers": { numbers: [] },
      "GET /api/kit/console/numbers/available?country=ZZ": () =>
        Response.json({ message: "x", errors: { country: ["Numbers could not be searched with those details. Check the country code."] } }, { status: 422 }),
      "GET /api/kit/console/numbers/available?country=IN": { numbers: [{ ...offer("+918045671001"), setupPrice: null, monthlyPrice: null }] },
      "POST /api/kit/console/numbers": () =>
        Response.json({ message: "Your balance does not cover this number: 522.90 INR is due now (setup and the first month). Add credit first." }, { status: 409 }),
    });
    open();
    const form = await screen.findByRole("search", { name: "Find a number" });
    fireEvent.change(within(form).getByLabelText("Country code"), { target: { value: "ZZ" } });
    fireEvent.click(within(form).getByRole("button", { name: "Search" }));
    expect(await within(form).findByText("Numbers could not be searched with those details. Check the country code.")).toBeTruthy();

    fireEvent.change(within(form).getByLabelText("Country code"), { target: { value: "IN" } });
    fireEvent.click(within(form).getByRole("button", { name: "Search" }));
    const row = within(await screen.findByRole("table", { name: "Numbers for sale" })).getAllByRole("row")[1];
    expect(within(row).getAllByRole("cell").slice(2, 4).map((c) => c.textContent)).toEqual(["Not priced", "Not priced"]);
    fireEvent.click(within(row).getByRole("button", { name: "Buy" }));
    expect(within(row).getByText("Buy +918045671001?")).toBeTruthy();
    fireEvent.click(within(row).getByRole("button", { name: "Yes, buy" }));
    expect((await within(row).findByRole("alert")).textContent).toBe(
      "Your balance does not cover this number: 522.90 INR is due now (setup and the first month). Add credit first.",
    );
  });

  it("releases a number only after asking, and tries a release again", async () => {
    let released = false;
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/numbers": () => Response.json({ numbers: released ? [mine[3]] : [mine[0], mine[3]] }),
      "DELETE /api/kit/console/numbers/n1": () => {
        released = true;
        return new Response(null, { status: 204 });
      },
      "DELETE /api/kit/console/numbers/n4": () => new Response(null, { status: 204 }),
    });
    open();
    const row = (await yours())[0];
    fireEvent.click(within(row).getByRole("button", { name: "Release" }));
    expect(within(row).getByText("Release +918045671001? Calls to it stop, and it may not be yours again.")).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(within(row).getByRole("button", { name: "Yes, release" }));
    await waitFor(() => expect(screen.getAllByRole("row")).toHaveLength(2));
    fireEvent.click(screen.getByRole("button", { name: "Try releasing again" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/console/numbers/n1", "/api/kit/console/numbers/n4"]));
  });
});
