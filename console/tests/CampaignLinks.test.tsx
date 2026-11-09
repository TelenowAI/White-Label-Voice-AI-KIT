/**
 * Console → one campaign → contacts from a link (campaigns/CampaignLink.tsx on CampaignPage.tsx) and Admin → Catalog's switch for it (admin/Catalog.tsx):
 * not offered while the operator has links off nor on a finished campaign; giving a link is a PUT of `{url}`, saying what its first read added
 * (lines with no number and numbers without a country code only when there are some) and switching to the link followed; a followed link shown as
 * text with when it was last read, what it added and why it could not be read; stopping is a DELETE that offers the field again; the backend's
 * reason beside the field for a 422 and an alert for a 403 or 409; a followed link still shown, saying links are off, when the operator turned
 * them off, and on a finished campaign; the operator's switch saved as `campaignLinksAllowed`. Not here: what the backend does (its own tests).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Campaign, CampaignLimits, CampaignSource, CatalogCuration } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { CampaignPage } from "../src/campaigns/CampaignPage";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const root = "/api/kit/console/campaigns/c1";
const sheet = "https://docs.google.com/spreadsheets/d/e/2PACX-1vT/pub?gid=0&single=true&output=csv";
const limits = (linksAllowed: boolean): CampaignLimits => ({
  nameMaxLength: 120, concurrency: { default: 2, max: 50 }, maxAttempts: { default: 3, max: 10 }, retryGapMinutes: { default: 5, min: 1, max: 60 },
  machineDetection: ["voicemail", "off"], phoneColumns: ["phone_number"], importMaxBytes: 1024 * 1024, importMaxRows: 500, linksAllowed, linkEveryMinutes: 15,
});
const followed = (over: Partial<CampaignSource> = {}): CampaignSource => ({ url: sheet, checkedAt: "2026-11-08T10:00:00Z", problem: null, added: 12, ...over });
const campaign = (over: Partial<Campaign> = {}): Campaign => ({
  id: "c1", name: "Recall", agent: { id: "a1", name: "Front desk" }, callerId: null,
  settings: { concurrency: 2, windowStart: null, windowEnd: null, timezone: "UTC", machineDetection: "voicemail", maxAttempts: 2, retryGapMinutes: 5, retryOnNoAnswer: true },
  status: "draft", waiting: null, counts: { people: 0, answered: 0, notReached: 0 }, pushed: { added: 0, duplicates: 0, suppressed: 0, skipped: 0 },
  syncState: "synced", syncError: null, source: null, startedAt: null, completedAt: null, createdAt: "2026-11-04T09:00:00Z", ...over,
});
const refused = (status: number, message: string, errors?: Record<string, string[]>) => Response.json({ message, errors }, { status });

/** The page's own reads, the campaign as it stands now (`now()`), and the answers a test adds. */
function fake(now: () => Campaign, allowed: boolean, more: Record<string, unknown> = {}) {
  return fakeKit({
    [`GET ${root}`]: () => Response.json(now()),
    "GET /api/kit/console/campaigns": { campaigns: [], limits: limits(allowed) },
    [`GET ${root}/targets?page=1`]: { targets: [], total: 0, page: 1, perPage: 50, counts: { waiting: 0, answered: 0, unanswered: 0, failed: 0 } },
    [`GET ${root}/reach`]: { pending: 0, dialable: 0, onDoNotCall: 0, withoutConsent: 0, blocked: false },
    [`GET ${root}/variables`]: { variables: [] },
    ...more,
  });
}

/** The campaign's page once its limits are read (its settings can be changed only then). */
async function show() {
  render(<MemoryRouter initialEntries={["/console/campaigns/c1"]}><Routes><Route path="/console/campaigns/:id" element={<CampaignPage />} /></Routes></MemoryRouter>);
  await screen.findByRole("button", { name: "Change settings" });
}

const section = () => screen.getByRole("region", { name: "Contacts from a link" });
const field = () => screen.getByLabelText("Link to the CSV file") as HTMLInputElement;
const lastRead = (iso: string) => `Last read ${new Date(iso).toLocaleString()}`;

describe("contacts from a link", () => {
  it("is not offered while the operator has links off", async () => {
    fake(() => campaign(), false);
    await show();
    expect(screen.getByLabelText("Call list (CSV file)")).toBeTruthy();
    expect(screen.queryByRole("region", { name: "Contacts from a link" })).toBeNull();
    expect(screen.queryByLabelText("Link to the CSV file")).toBeNull();
  });

  it("is not offered on a finished campaign that follows no link, even with links allowed", async () => {
    fake(() => campaign({ status: "completed" }), true);
    await show();
    expect(screen.queryByRole("region", { name: "Contacts from a link" })).toBeNull();
    expect(screen.queryByLabelText("Link to the CSV file")).toBeNull();
  });

  it("gives a link with a PUT, says what its first read added, reads the people again and shows the link followed", async () => {
    let now = campaign();
    const sent = fake(() => now, true, {
      [`PUT ${root}/source`]: () => {
        now = campaign({ source: followed({ added: 3 }), counts: { people: 3, answered: 0, notReached: 0 } });
        return Response.json({ added: { added: 3, alreadyThere: 2, skipped: 1, noCountryCode: 1 }, campaign: now });
      },
    });
    await show();
    const area = within(section());
    expect(area.getByText("A CSV file at a web address, such as a spreadsheet published to the web as CSV. It is read now and again every 15 minutes until the campaign finishes; only new numbers are added, and no one is taken off.")).toBeTruthy();
    expect((area.getByRole("button", { name: "Use this link" }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(field(), { target: { value: ` ${sheet} ` } });
    fireEvent.click(area.getByRole("button", { name: "Use this link" }));

    expect(await area.findByText("3 added, 2 already in the campaign. 1 skipped (no number). 1 have no + and country code. If calls need consent, these will not be called, because consent is matched to the number exactly.")).toBeTruthy();
    const writes = sent.filter((s) => s.method !== "GET");
    expect(writes.map((s) => `${s.method} ${s.path}`)).toEqual([`PUT ${root}/source`]);
    expect(writes[0].body).toEqual({ url: sheet });
    expect(area.getByText(sheet)).toBeTruthy();
    expect(area.getByText(`${lastRead("2026-11-08T10:00:00Z")} · 3 added from it so far`)).toBeTruthy();
    expect(area.queryByLabelText("Link to the CSV file")).toBeNull();
    expect(area.getByRole("button", { name: "Stop following" })).toBeTruthy();
    await waitFor(() => expect(sent.filter((s) => s.path === `${root}/targets?page=1`)).toHaveLength(2));
    expect(screen.getByText("3 people: 0 answered, 0 not reached")).toBeTruthy();
  });

  it("shows a followed link as text, when it was last read, what it added and why it could not be read last time", async () => {
    const problem = "That link answered with an error (404). Check that anyone with the link can open it.";
    fake(() => campaign({ status: "paused", source: followed({ problem }) }), true);
    await show();
    const area = within(section());
    expect(area.getByText(sheet).tagName).toBe("P");
    expect(area.queryByRole("link")).toBeNull();
    expect(area.getByText(`${lastRead("2026-11-08T10:00:00Z")} · 12 added from it so far`)).toBeTruthy();
    expect(area.getByText(problem).className).toContain("check-warning");
    expect(area.queryByText(/turned links off/)).toBeNull();
    expect(area.queryByLabelText("Link to the CSV file")).toBeNull();
    expect(area.getByText("The link is no longer read. The people it added stay in the campaign.")).toBeTruthy();
  });

  it("stops following with a DELETE and offers the field again; a read that adds everything says only that", async () => {
    let now = campaign({ source: followed() });
    const sent = fake(() => now, true, {
      [`DELETE ${root}/source`]: () => {
        now = campaign();
        return new Response(null, { status: 204 });
      },
      [`PUT ${root}/source`]: () => {
        now = campaign({ source: followed({ added: 1 }) });
        return Response.json({ added: { added: 1, alreadyThere: 0, skipped: 0, noCountryCode: 0 }, campaign: now });
      },
    });
    await show();
    fireEvent.click(within(section()).getByRole("button", { name: "Stop following" }));
    await waitFor(() => expect(field()).toBeTruthy());
    expect(within(section()).queryByText(sheet)).toBeNull();
    expect(sent.filter((s) => s.method !== "GET").map((s) => `${s.method} ${s.path}`)).toEqual([`DELETE ${root}/source`]);

    fireEvent.change(field(), { target: { value: sheet } });
    fireEvent.click(within(section()).getByRole("button", { name: "Use this link" }));
    expect((await within(section()).findByRole("status")).textContent).toBe("1 added, 0 already in the campaign.");
  });

  it("puts the backend's reason beside the field when the link cannot be read, keeping what was typed", async () => {
    const reason = "That link shows a web page, not a CSV file. For a spreadsheet, publish it to the web as CSV and use that link.";
    fake(() => campaign(), true, { [`PUT ${root}/source`]: () => refused(422, reason, { url: [reason] }) });
    await show();
    fireEvent.change(field(), { target: { value: "https://docs.google.com/spreadsheets/d/abc/edit" } });
    fireEvent.click(within(section()).getByRole("button", { name: "Use this link" }));
    await waitFor(() => expect(field().getAttribute("aria-invalid")).toBe("true"));
    const describedBy = field().getAttribute("aria-describedby")!.split(" ").map((id) => document.getElementById(id)?.textContent);
    expect(describedBy).toContain(reason);
    expect(field().closest(".field")?.textContent).toContain(reason);
    expect(field().value).toBe("https://docs.google.com/spreadsheets/d/abc/edit");
    expect(within(section()).queryByRole("alert")).toBeNull();
  });

  it("says a refusal that is not about the link (not allowed, finished) as an alert", async () => {
    const answers = [
      refused(403, "Contacts from a link are not available. Ask your provider."),
      refused(409, "This campaign has finished. Make a new one to call more people."),
    ];
    fake(() => campaign(), true, { [`PUT ${root}/source`]: () => answers.shift()! });
    await show();
    fireEvent.change(field(), { target: { value: sheet } });
    fireEvent.click(within(section()).getByRole("button", { name: "Use this link" }));
    expect((await within(section()).findByRole("alert")).textContent).toBe("Contacts from a link are not available. Ask your provider.");
    expect(field().getAttribute("aria-invalid")).toBeNull();
    fireEvent.click(within(section()).getByRole("button", { name: "Use this link" }));
    await waitFor(() => expect(within(section()).getByRole("alert").textContent).toBe("This campaign has finished. Make a new one to call more people."));
    expect(field().value).toBe(sheet);
  });

  it("still shows a followed link when the operator has turned links off, and says it is not read for now", async () => {
    fake(() => campaign({ source: followed({ checkedAt: null, added: 0 }) }), false);
    await show();
    const area = within(section());
    expect(area.getByText("Your provider has turned links off: this link is not read for now.")).toBeTruthy();
    expect(area.getByText("Not read yet · 0 added from it so far")).toBeTruthy();
    expect(area.getByRole("button", { name: "Stop following" })).toBeTruthy();
    expect(area.queryByLabelText("Link to the CSV file")).toBeNull();
  });

  it("still shows the link a finished campaign followed, with no field for another", async () => {
    fake(() => campaign({ status: "completed", source: followed() }), true);
    await show();
    const area = within(section());
    expect(area.getByText(sheet)).toBeTruthy();
    expect(area.queryByText(/turned links off/)).toBeNull();
    expect(area.queryByLabelText("Link to the CSV file")).toBeNull();
  });
});

describe("admin → catalog → campaign contacts from a link", () => {
  const owner = { id: 1, name: "Op", email: "op@example.com", role: "owner" };
  const none = { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null };
  const curation = (campaignLinksAllowed: boolean): CatalogCuration => ({
    fetchedAt: "2026-11-08T09:00:00Z", error: null, platformName: "Telenow", kinds: { llm: [], stt: [], tts: [] }, defaults: none, recommended: none, voiceNames: {},
    transferAllowed: false, transferStillOn: 0, browserCallsAllowed: false, browserCallsUnpriced: [], webChatAllowed: false, webChatUnpriced: [], campaignLinksAllowed,
    consentRequired: false, consentStillToTell: 0, cloningAllowed: false, clonesPerAccount: 10, clonesMost: 50, realtime: { enabled: false, providers: [] },
  });

  it("shows the operator's choice beside web chat, says what turning it off does, and saves it as campaignLinksAllowed", async () => {
    const sent = fakeKit({
      "GET /api/kit/session": { user: owner }, "GET /api/kit/admin/catalog": curation(true),
      "PUT /api/kit/admin/catalog": (body: unknown) => Response.json(curation((body as { campaignLinksAllowed: boolean }).campaignLinksAllowed)),
    });
    render(<MemoryRouter initialEntries={["/admin/catalog"]}><Routes><Route path="/admin/*" element={<AdminArea />} /></Routes></MemoryRouter>);
    const form = await screen.findByRole("form", { name: "Catalog" });
    const label = "Customers may give a campaign a link to a CSV that this server reads (https, public addresses only)";
    const box = within(form).getByLabelText(label) as HTMLInputElement;
    const set = box.closest("fieldset")!;
    expect(set.querySelector("legend")?.textContent).toBe("Campaign contacts from a link");
    expect(within(set).getByText("Off stops reading every link; they stay on their campaigns.")).toBeTruthy();
    expect(set.previousElementSibling?.querySelector("legend")?.textContent).toBe("Web chat");
    expect(box.checked).toBe(true);
    fireEvent.click(box);
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT")).toHaveLength(1));
    const saved = sent.find((s) => s.method === "PUT")?.body as { campaignLinksAllowed: boolean; webChatAllowed: boolean };
    expect([saved.campaignLinksAllowed, saved.webChatAllowed]).toEqual([false, false]);
    expect((await screen.findByRole("status")).textContent).toBe("Saved.");
    expect((within(form).getByLabelText(label) as HTMLInputElement).checked).toBe(false);
  });
});
