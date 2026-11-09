/**
 * Realtime voices in the console (agents/RealtimeFields.tsx in agents/StackForm.tsx, agents/StackCheck.tsx, admin/RealtimeCuration.tsx in admin/Catalog.tsx): a realtime
 * voice picked among those offered (a provider, a model, one of its voices or the model's own), heard, and saved only when it changed; one no longer offered kept, named
 * so, and turned off; the page saying calls use the voice setup when realtime voices are not available; the setup check's realtime part; the operator offering providers and
 * models under names of its own, told when the platform has them off.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Agent, CatalogCuration, RealtimeChoice, StackChoices } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { StackCheck } from "../src/agents/StackCheck";
import { StackForm } from "../src/agents/StackForm";
import { agentSettings } from "./agentSettings";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

beforeEach(() => {
  URL.createObjectURL = vi.fn(() => "blob:clip");
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const wav = () => new Response(new Blob([new Uint8Array([82, 73, 70, 70])], { type: "audio/wav" }), { headers: { "content-type": "audio/wav" } });

const live: RealtimeChoice = {
  key: "rt-a", name: "Live voices", languages: ["en", "hi", "es"],
  models: [
    { id: "m-live", name: "Everyday", voices: [{ id: "marin", name: "Marin", gender: "female", accent: "American", recommended: true }, { id: "cedar", name: "Cedar", gender: "male" }] },
    { id: "m-mini", name: "Mini", voices: [{ id: "marin", name: "Marin" }] },
  ],
};

const choices = (realtime: RealtimeChoice[]): StackChoices => ({
  llm: [{ key: "llm-a", name: "Thinking", models: [{ id: "m1", name: "Quick" }], settings: [] }],
  stt: [{ key: "stt-a", name: "Listening", settings: [] }],
  tts: [{ key: "tts-a", name: "Studio voices", settings: [] }],
  realtime,
  defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
});

const agent = (over: Partial<Agent["settings"]> = {}): Agent => ({
  id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true, syncState: "synced", syncError: null,
  stack: { llmProvider: "llm-a", llmModel: "m1", sttProvider: "stt-a", ttsProvider: "tts-a", ttsVoice: "vikram" },
  stackNames: { llmProvider: "Thinking", llmModel: "Quick", sttProvider: "Listening", ttsProvider: "Studio voices", ttsVoice: "Vikram" },
  settings: { ...agentSettings, ...over }, stackCheck: null, knowledgeBases: [], logoUrl: null, createdAt: "2026-11-05T09:00:00Z", updatedAt: "2026-11-05T09:00:00Z",
});

function setup(realtime: RealtimeChoice[], answers: Record<string, unknown> = {}) {
  return fakeKit({
    "GET /api/kit/console/catalog": choices(realtime),
    "GET /api/kit/console/own-voices": { allowed: false, voices: [] },
    "GET /api/kit/console/voices?provider=tts-a": { voices: [], total: 0, page: 1, perPage: 24, languages: [], genders: [] },
    "GET /api/kit/console/voices?provider=tts-a&starred=1": { voices: [], total: 0, page: 1, perPage: 24, languages: [], genders: [] },
    "PATCH /api/kit/console/agents/a1": () => Response.json(agent()),
    "POST /api/kit/console/voices/realtime-preview": wav,
    ...answers,
  });
}

const patches = (sent: { method: string; body: unknown }[]) => sent.filter((s) => s.method === "PATCH").map((s) => s.body as Record<string, unknown>);

describe("an agent's realtime voice", () => {
  it("picks one among those offered, hears it, and sends it only when it changed", async () => {
    let n = 0;
    const sent = setup([live], {
      "PATCH /api/kit/console/agents/a1": () => [
        () => Response.json(agent()),
        () => Response.json(agent()),
        () => Response.json({ message: "The given data was invalid.", errors: { "realtime.voice": ["This voice is not one of the offered voices."] } }, { status: 422 }),
      ][n++](),
    });
    render(<MemoryRouter><StackForm agent={agent()} onSaved={() => undefined} /></MemoryRouter>);
    const realtime = within(await screen.findByRole("group", { name: "Realtime voice" }));
    const provider = realtime.getByLabelText("Realtime voice provider") as HTMLSelectElement;
    expect([provider.value, [...provider.options].map((o) => o.textContent)]).toEqual(["", ["None — use the voice setup above", "Live voices"]]);
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(patches(sent)).toHaveLength(1));
    expect(patches(sent)[0], "unchanged: not sent").not.toHaveProperty("realtime");

    fireEvent.change(provider, { target: { value: "rt-a" } });
    expect(realtime.getByText("Speaks 3 languages")).toBeTruthy();
    const model = realtime.getByLabelText("Realtime model") as HTMLSelectElement;
    expect([model.value, [...model.options].map((o) => o.textContent)]).toEqual(["m-live", ["Everyday", "Mini"]]);
    const voice = realtime.getByLabelText("Realtime voice") as HTMLSelectElement;
    expect([voice.value, [...voice.options].map((o) => o.textContent)]).toEqual(["", ["The model's own voice", "Marin · female · American", "Cedar · male"]]);
    fireEvent.change(voice, { target: { value: "cedar" } });
    const fallback = realtime.getByLabelText("If the realtime voice cannot start, use the voice setup above") as HTMLInputElement;
    expect(fallback.checked).toBe(true);
    fireEvent.click(fallback);
    fireEvent.click(realtime.getByRole("button", { name: "Hear this realtime voice" }));
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/console/voices/realtime-preview")).toBe(true));
    expect(sent.find((s) => s.path === "/api/kit/console/voices/realtime-preview")?.body).toEqual({ provider: "rt-a", model: "m-live", voice: "cedar" });
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(patches(sent)).toHaveLength(2));
    expect(patches(sent)[1].realtime).toEqual({ provider: "rt-a", model: "m-live", voice: "cedar", fallback: false });

    fireEvent.change(model, { target: { value: "m-mini" } });
    expect(voice.value, "a voice the model has not: the model's own").toBe("");
    fireEvent.change(voice, { target: { value: "marin" } });
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    expect(await realtime.findByText("This voice is not one of the offered voices.")).toBeTruthy();
    expect(voice.getAttribute("aria-invalid")).toBe("true");
  });

  it("keeps one no longer offered, says calls use the voice setup, and turns it off", async () => {
    const sent = setup([]);
    const kept = { provider: "rt-old", model: "m-x", voice: null, fallback: true, names: { provider: "Old live", model: null, voice: null }, available: false };
    render(<MemoryRouter><StackForm agent={agent({ realtime: kept })} onSaved={() => undefined} /></MemoryRouter>);
    const realtime = within(await screen.findByRole("group", { name: "Realtime voice" }));
    expect(realtime.getByText("Realtime voices are not available right now, so calls use the voice setup above.")).toBeTruthy();
    const provider = realtime.getByLabelText("Realtime voice provider") as HTMLSelectElement;
    expect([provider.value, provider.selectedOptions[0].textContent]).toEqual(["rt-old", "No longer offered: Old live"]);
    expect(realtime.queryByLabelText("Realtime model"), "nothing to pick among").toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(patches(sent)).toHaveLength(1));
    expect(patches(sent)[0], "kept as it is: the rest saves").not.toHaveProperty("realtime");
    fireEvent.change(provider, { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(patches(sent)).toHaveLength(2));
    expect(patches(sent)[1].realtime).toBeNull();
  });

  it("shows nothing of realtime voices when none is offered and the agent has none", async () => {
    setup([]);
    render(<MemoryRouter><StackForm agent={agent()} onSaved={() => undefined} /></MemoryRouter>);
    await screen.findByRole("button", { name: "Save voice setup" });
    expect(screen.queryByRole("group", { name: "Realtime voice" })).toBeNull();
  });

  it("names the realtime part of the setup check", () => {
    fakeKit({});
    render(<StackCheck agent={agent()} />);
    cleanup();
    render(<StackCheck agent={{ ...agent(), stackCheck: { checkedAt: "2026-11-05T10:00:00Z", parts: [{ part: "llm", status: "ok", reason: null },
      { part: "realtime", status: "not_checked", reason: "There is no automatic check for realtime voices. Try a test call." }] } }} />);
    expect(within(screen.getByRole("list", { name: "Check the voice setup" })).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Language model: Working", "Realtime voice: Not checked · There is no automatic check for realtime voices. Try a test call.",
    ]);
  });
});

describe("admin → catalog → realtime voices", () => {
  const owner = { id: 1, name: "Op", email: "op@example.com", role: "owner" };
  const curation = (enabled: boolean): CatalogCuration => ({
    fetchedAt: "2026-11-05T09:00:00Z", error: null, platformName: "Telenow", kinds: { llm: [], stt: [], tts: [] },
    defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    recommended: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null }, voiceNames: {},
    transferAllowed: false, transferStillOn: 0, browserCallsAllowed: false, browserCallsUnpriced: [], webChatAllowed: false, webChatUnpriced: [], campaignLinksAllowed: false, consentRequired: false, consentStillToTell: 0,
    cloningAllowed: false, clonesPerAccount: 10, clonesMost: 50,
    realtime: { enabled, providers: [{ id: "openai", catalogName: "OpenAI Realtime", name: "OpenAI Realtime", ownName: null, namesPlatform: false, offered: false, recommended: false,
      languages: ["en"], models: [
        { id: "gpt-realtime", catalogName: "GPT Realtime", name: "GPT Realtime", ownName: null, namesPlatform: false, offered: true, voiceCount: 10 },
        { id: "gpt-realtime-mini", catalogName: "GPT Realtime mini", name: "GPT Realtime mini", ownName: null, namesPlatform: false, offered: true, voiceCount: 10 },
      ] }] },
  });
  const open = () => render(
    <MemoryRouter initialEntries={["/admin/catalog"]}><Routes><Route path="/admin/*" element={<AdminArea />} /></Routes></MemoryRouter>,
  );

  it("offers providers and models under names of the operator's own, flags one that names the platform, and saves them with the rest", async () => {
    const sent = fakeKit({ "GET /api/kit/session": { user: owner }, "GET /api/kit/admin/catalog": curation(true), "PUT /api/kit/admin/catalog": () => Response.json(curation(true)) });
    open();
    const section = within((await screen.findByText("Realtime voices")).closest("fieldset")!);
    expect(section.queryByText(/has realtime voices off/), "on: no note").toBeNull();
    expect(section.getAllByText("10 voices")).toHaveLength(2);
    fireEvent.click(section.getByLabelText("Offer OpenAI Realtime"));
    fireEvent.change(section.getByLabelText("Name customers see for OpenAI Realtime"), { target: { value: "Telenow Live" } });
    expect(section.getByText(/^Carries “Telenow”/)).toBeTruthy();
    fireEvent.change(section.getByLabelText("Name customers see for OpenAI Realtime"), { target: { value: "Live voices" } });
    fireEvent.click(section.getByLabelText("Offer GPT Realtime mini"));
    fireEvent.change(section.getByLabelText("Name customers see for GPT Realtime"), { target: { value: "Everyday" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.some((s) => s.method === "PUT")).toBe(true));
    expect((sent.find((s) => s.method === "PUT")?.body as { realtime: unknown }).realtime).toEqual({
      openai: { offered: true, name: "Live voices", models: { "gpt-realtime": { offered: true, name: "Everyday" }, "gpt-realtime-mini": { offered: false, name: null } } },
    });
  });

  it("says when the platform has realtime voices off", async () => {
    fakeKit({ "GET /api/kit/session": { user: owner }, "GET /api/kit/admin/catalog": curation(false) });
    open();
    const section = within((await screen.findByText("Realtime voices")).closest("fieldset")!);
    expect(section.getByText("The voice platform has realtime voices off, so customers cannot pick one now. Your choices are kept for when it turns them on.")).toBeTruthy();
  });
});
