/**
 * Console → Consent (consent/): the rule and who holds it, how many waiting calls it touches, changing it only after asking; recording one person with the words only where they are needed, the backend's word beside a field; a file, too big refused before it is
 * sent; the list with each record's state, downloading every record as a CSV file (exportConsent), and withdrawing only after asking. A record offers no edit or delete. Not here: what the backend does (ConsentTest.php).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ConsentLimits, ConsentPage as Page, ConsentRecord, ConsentRule } from "../src/api";
import { ConsentPage } from "../src/consent/ConsentPage";
import { howWord, kindWord, scopeWord } from "../src/consent/words";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const root = "/api/kit/console/consent";
const limits: ConsentLimits = { types: ["express_written", "express_oral", "implied"], writtenType: "express_written", scopes: ["marketing", "service"], defaultScope: "service", sources: ["web_form", "paper"], phoneColumns: ["phone_number", "phone"], capturedColumn: "consent_date", expiresColumn: "expires_on", importMaxBytes: 1024 * 1024, importMaxRows: 500 };
const rule = (over: Partial<ConsentRule> = {}): ConsentRule => ({ required: false, byOperator: false, byCustomer: false, byProvider: false, saving: false, waiting: { total: 7, covered: 2, blocked: 5 }, canChange: true, ...over });
const record = (over: Partial<ConsentRecord> = {}): ConsentRecord => ({ id: "r1", phoneNumber: "+14155550101", consentType: "express_written", scope: "marketing", source: "web_form", disclosureText: "I agree.", disclosureVersion: "v1", note: "Form 12", capturedAt: "2026-10-01T09:00:00Z", expiresAt: null, state: "live", withdrawnAt: null, withdrawnNote: null, onDoNotCall: false, recordedBy: "Ana", syncState: "synced", syncError: null, createdAt: "2026-10-01T09:00:00Z", ...over });
const page = (over: Partial<Page> = {}): Page => ({ records: [record()], total: 1, page: 1, perPage: 50, rule: rule(), limits, ...over });

type Route = unknown | ((body: never) => unknown);

function fake(routes: Record<string, Route>) {
  const sent: { method: string; path: string; body: Record<string, unknown> }[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? "GET";
    const body = init?.body ? JSON.parse(String(init.body)) : undefined;
    sent.push({ method, path: String(url), body });
    const all: Record<string, Route> = { [`GET ${root}?page=1`]: page(), ...routes };
    const route = all[`${method} ${url}`];
    if (route === undefined) return new Response(JSON.stringify({ message: `no ${method} ${url}` }), { status: 404 });
    const answer = typeof route === "function" ? (route as (b: unknown) => unknown)(body) : route;
    if (answer instanceof Response) return answer;
    return answer === null ? new Response(null, { status: 204 }) : Response.json(answer);
  }));
  return sent;
}

const show = async () => {
  render(<MemoryRouter><ConsentPage /></MemoryRouter>);
  await screen.findByRole("heading", { name: "Do calls need consent?" });
};

const refused = (status: number, message: string, errors?: Record<string, string[]>) => new Response(JSON.stringify({ message, errors }), { status });
const recordForm = () => screen.getByRole("form", { name: "Record consent" });

describe("whether calls need it", () => {
  it("says it is off, how many waiting calls it would touch, and turns on only after asking how many stop", async () => {
    const sent = fake({ [`PUT ${root}/rule`]: () => rule({ required: true, byCustomer: true }) });
    await show();
    expect(screen.getByText("No. Calls go out without checking for consent.")).toBeTruthy();
    expect(screen.getByText("Of 7 calls waiting in your campaigns, 2 have consent on file. 5 would not be placed if you turn this on.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Require consent" }));
    expect(sent.filter((x) => x.method === "PUT")).toHaveLength(0);
    expect(screen.getByText(/Require consent\? Every call to a number with no consent on file stops, 5 waiting now among them\./)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, require it" }));
    expect(await screen.findByText("Yes. A number with no consent on file is not called, by any agent or campaign.")).toBeTruthy();
    expect(sent.find((x) => x.method === "PUT")?.body).toEqual({ required: true });
    expect(screen.getByText("Of 7 calls waiting in your campaigns, 2 have consent on file. 5 will not be placed.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Yes, stop requiring it" })).toBeNull();
  });

  it("asks without a count when nothing waits, and can be called off", async () => {
    const sent = fake({ [`GET ${root}?page=1`]: page({ rule: rule({ waiting: { total: 0, covered: 0, blocked: 0 } }) }) });
    await show();
    expect(screen.queryByText(/calls waiting/)).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Require consent" }));
    expect(screen.getByText("Require consent? Every call to a number with no consent on file stops.")).toBeTruthy();
    fireEvent.click(within(screen.getByText(/Every call to a number/).closest(".actions")!).getByRole("button", { name: "Cancel" }));
    expect(screen.getByRole("button", { name: "Require consent" })).toBeTruthy();
    expect(sent.filter((x) => x.method === "PUT")).toHaveLength(0);
  });

  it("turns off after asking", async () => {
    const sent = fake({ [`GET ${root}?page=1`]: page({ rule: rule({ required: true, byCustomer: true }) }), [`PUT ${root}/rule`]: () => rule() });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Stop requiring consent" }));
    expect(screen.getByText("Stop requiring consent? Calls will go out without checking for it.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, stop requiring it" }));
    await screen.findByText("No. Calls go out without checking for consent.");
    expect(sent.find((x) => x.method === "PUT")?.body).toEqual({ required: false });
  });

  it("says who holds it, and offers no way to turn off what they hold", async () => {
    fake({ [`GET ${root}?page=1`]: page({ rule: rule({ required: true, byOperator: true }) }) });
    await show();
    expect(screen.getByText("Your provider requires this for every account.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Stop requiring consent" })).toBeNull();
    cleanup();
    fake({ [`GET ${root}?page=1`]: page({ rule: rule({ required: true, byProvider: true }) }) });
    await show();
    expect(screen.getByText("The account your service runs under requires this.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Stop requiring consent" })).toBeNull();
    expect(screen.queryByText("Your provider requires this for every account.")).toBeNull();
  });

  it("offers it only to someone who manages the account, and says so", async () => {
    fake({ [`GET ${root}?page=1`]: page({ rule: rule({ canChange: false }) }) });
    await show();
    expect(screen.queryByRole("button", { name: "Require consent" })).toBeNull();
    expect(screen.getByText("Only someone who manages this account can change this.")).toBeTruthy();
  });

  it("says when it is on its way, when the count could not be read, and when it was refused", async () => {
    fake({ [`GET ${root}?page=1`]: page({ rule: rule({ saving: true, waiting: null }) }), [`PUT ${root}/rule`]: () => refused(409, "The account your service runs under requires consent.") });
    await show();
    expect(screen.getByText("This is on its way and takes effect shortly.")).toBeTruthy();
    expect(screen.queryByText(/calls waiting/)).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Require consent" }));
    expect(screen.getByText("Require consent? Every call to a number with no consent on file stops.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, require it" }));
    expect((await screen.findByRole("alert")).textContent).toContain("requires consent");
    expect(screen.getByRole("button", { name: "Yes, require it" })).toBeTruthy();
  });
});

describe("recording one", () => {
  it("offers the backend's choices, starts from its default, and asks for the words only on the kind that needs them", async () => {
    fake({});
    await show();
    const form = recordForm();
    expect(within(within(form).getByLabelText("Kind of consent")).getAllByRole("option").map((o) => o.textContent)).toEqual(["Written", "Spoken", "Implied"]);
    expect(within(within(form).getByLabelText("How it was given")).getAllByRole("option").map((o) => o.textContent)).toEqual(["A form on a website", "On paper"]);
    expect((within(form).getByLabelText("What for") as HTMLSelectElement).value).toBe("service");
    expect(within(form).getByLabelText("The exact words they agreed to")).toBeTruthy();
    fireEvent.change(within(form).getByLabelText("Kind of consent"), { target: { value: "implied" } });
    expect(within(form).queryByLabelText("The exact words they agreed to")).toBeNull();
    expect(within(form).queryByLabelText("Version of those words")).toBeNull();
  });

  it("sends what was typed, with its dates as moments, reads the list again and empties the number", async () => {
    const sent = fake({ [`POST ${root}`]: () => record() });
    await show();
    const form = recordForm();
    fireEvent.change(within(form).getByLabelText("Number"), { target: { value: " +14155550101 " } });
    fireEvent.change(within(form).getByLabelText("The exact words they agreed to"), { target: { value: " I agree. " } });
    fireEvent.change(within(form).getByLabelText("Version of those words"), { target: { value: " v1 " } });
    fireEvent.change(within(form).getByLabelText("How it was given"), { target: { value: "paper" } });
    fireEvent.change(within(form).getByLabelText("When they agreed"), { target: { value: "2026-10-01T09:00" } });
    fireEvent.change(within(form).getByLabelText("When it ends"), { target: { value: "2027-10-01T09:00" } });
    fireEvent.change(within(form).getByLabelText("Note"), { target: { value: " Form 12 " } });
    fireEvent.click(within(form).getByRole("button", { name: "Record it" }));
    expect(await screen.findByText("Recorded.")).toBeTruthy();
    expect(sent.find((x) => x.method === "POST")?.body).toEqual({ phoneNumber: "+14155550101", consentType: "express_written", scope: "service", source: "paper", disclosureText: "I agree.", disclosureVersion: "v1", capturedAt: new Date("2026-10-01T09:00").toISOString(), expiresAt: new Date("2027-10-01T09:00").toISOString(), note: "Form 12" });
    expect((within(form).getByLabelText("Number") as HTMLInputElement).value).toBe("");
    await waitFor(() => expect(sent.filter((x) => x.path === `${root}?page=1`).length).toBeGreaterThan(1));
  });

  it("sends empty dates and words as nothing, and no words typed before the kind changed", async () => {
    const sent = fake({ [`POST ${root}`]: () => record() });
    await show();
    const form = recordForm();
    fireEvent.change(within(form).getByLabelText("Number"), { target: { value: "+14155550101" } });
    fireEvent.change(within(form).getByLabelText("The exact words they agreed to"), { target: { value: "Old words" } });
    fireEvent.change(within(form).getByLabelText("Kind of consent"), { target: { value: "implied" } });
    fireEvent.click(within(form).getByRole("button", { name: "Record it" }));
    await screen.findByText("Recorded.");
    const body = sent.find((x) => x.method === "POST")?.body;
    expect([body?.disclosureText, body?.disclosureVersion, body?.capturedAt, body?.expiresAt, body?.note]).toEqual([null, null, null, null, null]);
  });

  it("says when the number is on the do-not-call list all the same", async () => {
    fake({ [`POST ${root}`]: () => record({ onDoNotCall: true }) });
    await show();
    fireEvent.click(within(recordForm()).getByRole("button", { name: "Record it" }));
    expect(await screen.findByText("Recorded, but this number is on your do-not-call list, so it still will not be called.")).toBeTruthy();
  });

  it("puts the backend's word beside its field and keeps what was typed, and says a refusal of the whole", async () => {
    fake({ [`POST ${root}`]: () => refused(422, "x", { phoneNumber: ["Needs a plus."], disclosureText: ["Needs words."], capturedAt: ["Not the future."], expiresAt: ["After it began."], note: ["Too long."], source: ["Choose one."], consentType: ["Choose a kind."], scope: ["Choose a use."], disclosureVersion: ["Too long a version."] }) });
    await show();
    const form = recordForm();
    fireEvent.change(within(form).getByLabelText("Number"), { target: { value: "1415" } });
    fireEvent.click(within(form).getByRole("button", { name: "Record it" }));
    for (const word of ["Needs a plus.", "Needs words.", "Not the future.", "After it began.", "Too long.", "Choose one.", "Choose a kind.", "Choose a use.", "Too long a version."]) {
      expect(await within(form).findByText(word)).toBeTruthy();
    }
    expect((within(form).getByLabelText("Number") as HTMLInputElement).value).toBe("1415");
    cleanup();
    fake({ [`POST ${root}`]: () => refused(409, "This number is still being withdrawn.") });
    await show();
    fireEvent.click(within(recordForm()).getByRole("button", { name: "Record it" }));
    expect((await within(recordForm()).findByRole("alert")).textContent).toContain("still being withdrawn");
  });
});

describe("recording a file", () => {
  const pick = (name: string, contents = "phone_number\n+14155550101\n") => {
    const input = screen.getByLabelText("File (CSV)") as HTMLInputElement;
    const file = new File([contents], name, { type: "text/csv" });
    fireEvent.change(input, { target: { files: [file] } });
    return file;
  };

  it("says what it needs from the backend's limits, sends the file with its kind and date, and says what became of the lines", async () => {
    const sent = fake({ [`POST ${root}/import`]: () => ({ added: 3, skipped: 2, duplicates: 1 }) });
    await show();
    expect(screen.getByText(/A header row and the number in a column named phone_number, phone.*Up to 500 people, 1 MB\. Add a consent_date column.*and expires_on/)).toBeTruthy();
    fireEvent.change(screen.getByLabelText("When they agreed, for anyone with no date of their own"), { target: { value: "2026-10-01T09:00" } });
    const kinds = screen.getAllByLabelText("Kind of consent");
    fireEvent.change(kinds[1], { target: { value: "express_oral" } });
    pick("people.csv");
    expect(await screen.findByText(/3 added, 2 skipped .*1 already on file\./)).toBeTruthy();
    expect(sent.find((x) => x.path === `${root}/import`)?.body).toEqual({ csv: "phone_number\n+14155550101\n", consentType: "express_oral", scope: "service", capturedAt: new Date("2026-10-01T09:00").toISOString(), disclosureText: null, disclosureVersion: null });
    await waitFor(() => expect(sent.filter((x) => x.path === `${root}?page=1`).length).toBeGreaterThan(1));
  });

  it("sends the words on written consent", async () => {
    const sent = fake({ [`POST ${root}/import`]: () => ({ added: 1, skipped: 0, duplicates: 0 }) });
    await show();
    fireEvent.change(document.getElementById("consent-file-words")!, { target: { value: " File words " } });
    fireEvent.change(document.getElementById("consent-file-version")!, { target: { value: " v3 " } });
    pick("people.csv");
    await screen.findByText(/1 added/);
    const body = sent.find((x) => x.path === `${root}/import`)?.body;
    expect([body?.consentType, body?.disclosureText, body?.disclosureVersion]).toEqual(["express_written", "File words", "v3"]);
  });

  it("refuses too big a file before it is sent", async () => {
    const sent = fake({});
    await show();
    pick("big.csv", "x".repeat(limits.importMaxBytes + 1));
    expect((await screen.findByRole("alert")).textContent).toContain("The file is too big: 1 MB at most.");
    expect(sent.filter((x) => x.path === `${root}/import`)).toHaveLength(0);
  });

  it("says what the backend said, beside its field or of the whole", async () => {
    fake({ [`POST ${root}/import`]: () => refused(422, "x", { csv: ["No number column."], capturedAt: ["Say when."] }) });
    await show();
    pick("people.csv");
    expect(await screen.findByText("No number column.")).toBeTruthy();
    expect(screen.getByText("Say when.")).toBeTruthy();
    cleanup();
    fake({ [`POST ${root}/import`]: () => refused(409, "Your account is still being set up.") });
    await show();
    pick("people.csv");
    expect((await screen.findByRole("alert")).textContent).toContain("still being set up");
  });
});

describe("the list", () => {
  const row = (phone: string) => screen.getByText(phone).closest("tr") as HTMLElement;

  it("reads each record in words, and offers no edit or delete", async () => {
    fake({ [`GET ${root}?page=1`]: page({ records: [
      record({ id: "a", phoneNumber: "+1", expiresAt: "2027-10-01T09:00:00Z" }),
      record({ id: "b", phoneNumber: "+2", state: "expired", disclosureText: null, disclosureVersion: null, note: null, recordedBy: null, consentType: "implied", scope: "service", source: "import" }),
      record({ id: "c", phoneNumber: "+3", state: "withdrawn", withdrawnAt: "2026-11-01T09:00:00Z", withdrawnNote: "Asked by email" }),
      record({ id: "d", phoneNumber: "+4", onDoNotCall: true }),
      record({ id: "e", phoneNumber: "+5", syncState: "pending" }),
      record({ id: "f", phoneNumber: "+6", syncState: "failed", syncError: "This could not be recorded." }),
    ], total: 6 }) });
    await show();
    const a = row("+1").textContent ?? "";
    for (const part of ["Written · Marketing · A form on a website", "“I agree.” (v1)", "Form 12", "Recorded by Ana", "On file", `Ends ${new Date("2027-10-01T09:00:00Z").toLocaleDateString()}`, new Date("2026-10-01T09:00:00Z").toLocaleDateString()]) {
      expect(a).toContain(part);
    }
    expect(row("+2").textContent).toContain("Implied · Service · From a file");
    expect(row("+2").textContent).toContain("Ended");
    expect(row("+3").textContent).toContain(`Withdrawn ${new Date("2026-11-01T09:00:00Z").toLocaleDateString()}: Asked by email`);
    expect(within(row("+3")).queryByRole("button", { name: "Withdraw" })).toBeNull();
    expect(row("+4").textContent).toContain("On your do-not-call list, so not called");
    expect(row("+1").textContent).not.toContain("do-not-call");
    expect(row("+5").textContent).toContain("Not counted yet: it is on its way.");
    expect(row("+1").textContent).not.toContain("Not counted yet");
    expect(row("+6").textContent).toContain("This could not be recorded.");
    expect(within(row("+2")).getByRole("button", { name: "Withdraw" })).toBeTruthy();
    expect(within(row("+1")).getAllByRole("button").map((b) => b.textContent)).toEqual(["Withdraw"]);
  });

  it("does not say do-not-call on a record that has ended", async () => {
    fake({ [`GET ${root}?page=1`]: page({ records: [record({ state: "expired", onDoNotCall: true })] }) });
    await show();
    expect(row("+14155550101").textContent).not.toContain("do-not-call");
  });

  it("downloads every record as a CSV file", async () => {
    fake({});
    await show();
    const link = screen.getByRole("link", { name: "Download CSV" });
    expect(link.getAttribute("href")).toBe(`${root}/export`);
    expect(link.hasAttribute("download")).toBe(true);
  });

  it("says when there are none", async () => {
    fake({ [`GET ${root}?page=1`]: page({ records: [], total: 0 }) });
    await show();
    expect(screen.getByText("No consent on file.")).toBeTruthy();
  });

  it("says when it cannot be read", async () => {
    fake({ [`GET ${root}?page=1`]: () => refused(503, "Not now.") });
    render(<MemoryRouter><ConsentPage /></MemoryRouter>);
    expect((await screen.findByRole("alert")).textContent).toContain("Not now.");
  });

  it("searches, from the first page, and pages", async () => {
    const sent = fake({
      [`GET ${root}?page=1`]: page({ total: 60 }),
      [`GET ${root}?page=2`]: page({ records: [record({ id: "z", phoneNumber: "+2" })], total: 60, page: 2 }),
      [`GET ${root}?page=1&search=0199`]: page({ records: [record({ id: "y", phoneNumber: "+3" })], total: 1 }),
    });
    await show();
    fireEvent.click(screen.getByRole("button", { name: "Next" }));
    await screen.findByText("+2");
    fireEvent.change(screen.getByLabelText("Search by number"), { target: { value: " 0199 " } });
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    await screen.findByText("+3");
    expect(sent.map((x) => x.path)).toContain(`${root}?page=1&search=0199`);
    expect(sent.map((x) => x.path)).not.toContain(`${root}?page=2&search=0199`);
  });

  it("withdraws only after asking what it does, sends the reason, says so and reads the list again", async () => {
    const sent = fake({ [`POST ${root}/withdraw`]: () => null });
    await show();
    fireEvent.click(within(row("+14155550101")).getByRole("button", { name: "Withdraw" }));
    expect(sent.filter((x) => x.method === "POST")).toHaveLength(0);
    expect(screen.getByText("Withdraw all consent for +14155550101? It goes on your do-not-call list and any waiting calls to it are dropped.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Why (optional)"), { target: { value: " Asked by email " } });
    fireEvent.click(screen.getByRole("button", { name: "Yes, withdraw" }));
    expect(await screen.findByText("Withdrawn. +14155550101 is on your do-not-call list.")).toBeTruthy();
    expect(sent.find((x) => x.method === "POST")?.body).toEqual({ phoneNumber: "+14155550101", note: "Asked by email" });
    await waitFor(() => expect(sent.filter((x) => x.path === `${root}?page=1`).length).toBeGreaterThan(1));
  });

  it("sends no reason when none is given, can be called off, and says when it cannot be done", async () => {
    const sent = fake({ [`POST ${root}/withdraw`]: () => refused(404, "There is no consent on file for this number.") });
    await show();
    fireEvent.click(within(row("+14155550101")).getByRole("button", { name: "Withdraw" }));
    fireEvent.click(within(screen.getByText(/Withdraw all consent/).closest("tr") as HTMLElement).getByRole("button", { name: "Cancel" }));
    expect(screen.queryByText(/Withdraw all consent/)).toBeNull();
    expect(sent.filter((x) => x.method === "POST")).toHaveLength(0);
    fireEvent.click(within(row("+14155550101")).getByRole("button", { name: "Withdraw" }));
    fireEvent.click(screen.getByRole("button", { name: "Yes, withdraw" }));
    expect((await screen.findByRole("alert")).textContent).toContain("no consent on file");
    expect(sent.find((x) => x.method === "POST")?.body).toEqual({ phoneNumber: "+14155550101", note: null });
  });
});

describe("the words", () => {
  it("reads a value it knows, and one it does not with underscores as spaces", () => {
    expect([kindWord("express_oral"), kindWord("something_new"), scopeWord("service"), scopeWord("a_b"), howWord("import"), howWord("c_d"), howWord("toString")]).toEqual(["Spoken", "something new", "Service", "a b", "From a file", "c d", "toString"]);
  });
});
