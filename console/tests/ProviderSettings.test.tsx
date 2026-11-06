/**
 * Each provider's own settings, voices by model, the setup check and stars in the console (agents/StackForm.tsx with ProviderSettingsFields.tsx, agents/StackCheck.tsx,
 * voices/VoicesPage.tsx, voices/VoicePicker.tsx, admin/Catalog.tsx): settings drawn from what the backend offers and sent whole (null for one left to the provider), the
 * backend's word beside a setting, a provider changed starting afresh, the chosen model narrowing the voices with the member's starred ones first; the check in the kit's words;
 * a star toggled; the operator's choice of settings and options saved.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Agent, StackChoices, Voice } from "../src/api";
import { StackCheck } from "../src/agents/StackCheck";
import { StackForm } from "../src/agents/StackForm";
import { ConsoleArea } from "../src/console/ConsoleArea";
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

const choices: StackChoices = {
  llm: [{ key: "llm-a", name: "Thinking", models: [{ id: "m1", name: "Quick" }], settings: [{ key: "temperature", kind: "number", label: "Temperature", min: 0, max: 2, step: 0.1, default: 0.7, advanced: false, model: false }] }],
  stt: [{ key: "stt-a", name: "Listening", settings: [] }],
  tts: [
    { key: "tts-a", name: "Studio voices", settings: [
      { key: "model", kind: "select", label: "Model", options: [{ value: "oV1", label: "v1 (HD)" }, { value: "oKo", label: "v2 (fast)" }], default: "oV1", advanced: false, model: true },
      { key: "speed", kind: "number", label: "Speed", min: 0.5, max: 1.5, step: 0.05, default: 1.35, advanced: false, model: false, help: "How fast it speaks." },
      { key: "expressive", kind: "toggle", label: "Expressive", default: false, advanced: true, model: false },
    ] },
    { key: "tts-b", name: "Bright voices", settings: [{ key: "stability", kind: "number", label: "Stability", min: 0, max: 1, default: 0.5, advanced: false, model: false },
      { key: "speed", kind: "number", label: "Speed", min: 0.7, max: 1.2, default: 1, advanced: false, model: false }] },
  ],
  realtime: [],
  defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
};

function voice(id: string, name: string, over: Partial<Voice> = {}): Voice {
  return { provider: "tts-a", providerName: "Studio voices", id, name, language: "en", gender: null, accent: null, style: null, useCase: null, description: null, tags: [], recommended: false, starred: false, ...over };
}

const page = (voices: Voice[]) => ({ voices, total: voices.length, page: 1, perPage: 24, languages: ["en"], genders: [] });

const agent = (over: Partial<Agent> = {}): Agent => ({
  id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true, syncState: "synced", syncError: null,
  stack: { llmProvider: "llm-a", llmModel: "m1", sttProvider: "stt-a", ttsProvider: "tts-a", ttsVoice: "vikram" },
  stackNames: { llmProvider: "Thinking", llmModel: "Quick", sttProvider: "Listening", ttsProvider: "Studio voices", ttsVoice: "Vikram" },
  settings: { ...agentSettings, ttsSettings: { values: { speed: 1.2 } } },
  stackCheck: null, knowledgeBases: [], createdAt: "2026-11-05T09:00:00Z", updatedAt: "2026-11-05T09:00:00Z", ...over,
});

function setup(answers: Record<string, unknown> = {}) {
  return fakeKit({
    "GET /api/kit/console/catalog": choices,
    "GET /api/kit/console/own-voices": { allowed: false, voices: [] },
    "GET /api/kit/console/voices?provider=tts-a&model=oV1": page([voice("vikram", "Vikram"), voice("asha", "Asha")]),
    "GET /api/kit/console/voices?provider=tts-a&starred=1&model=oV1": page([voice("asha", "Asha", { starred: true })]),
    "GET /api/kit/console/voices?provider=tts-a&model=oKo": page([voice("af_alloy", "Alloy")]),
    "GET /api/kit/console/voices?provider=tts-a&starred=1&model=oKo": page([]),
    "GET /api/kit/console/voices?provider=tts-b": page([voice("rachel", "Rachel", { provider: "tts-b" })]),
    "GET /api/kit/console/voices?provider=tts-b&starred=1": page([]),
    "PATCH /api/kit/console/agents/a1": (body: unknown) => Response.json(agent({ stack: { ...agent().stack, ...(body as object) } })),
    ...answers,
  });
}

function openForm(over: Partial<Agent> = {}) {
  render(<MemoryRouter><StackForm agent={agent(over)} onSaved={() => undefined} /></MemoryRouter>);
}

describe("each provider's own settings", () => {
  it("draws the chosen providers' settings, sends them whole, and shows the backend's word beside one", async () => {
    let n = 0;
    const sent = setup({
      "PATCH /api/kit/console/agents/a1": () => [
        () => Response.json(agent()),
        () => Response.json({ message: "The given data was invalid.", errors: { "providerSettings.tts.speed": ["Give a number from 0.5 to 1.5."] } }, { status: 422 }),
      ][n++](),
    });
    openForm();
    const voiceSettings = within(await screen.findByRole("group", { name: "Voice settings" }));
    const model = voiceSettings.getByLabelText("Model") as HTMLSelectElement;
    expect([...model.options].map((o) => o.textContent)).toEqual(["Default (v1 (HD))", "v1 (HD)", "v2 (fast)"]);
    const speed = voiceSettings.getByLabelText("Speed") as HTMLInputElement;
    expect([speed.value, speed.min, speed.max, speed.placeholder]).toEqual(["1.2", "0.5", "1.5", "1.35"]);
    expect(voiceSettings.getByText("How fast it speaks.")).toBeTruthy();
    expect(voiceSettings.getAllByText("Expressive"), "an advanced setting shown once").toHaveLength(1);
    expect(voiceSettings.getByText("More settings").closest("details")?.contains(voiceSettings.getByLabelText("Expressive")), "under More settings").toBe(true);
    expect(screen.queryByRole("group", { name: "Listening settings" }), "a provider with none to change shows none").toBeNull();
    fireEvent.change(within(screen.getByRole("group", { name: "Language model settings" })).getByLabelText("Temperature"), { target: { value: "0.4" } });
    fireEvent.change(voiceSettings.getByLabelText("Expressive"), { target: { value: "on" } });
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PATCH")).toHaveLength(1));
    expect((sent.find((s) => s.method === "PATCH")?.body as { providerSettings: unknown }).providerSettings).toEqual({
      llm: { temperature: 0.4 },
      tts: { model: null, speed: 1.2, expressive: true },
    });

    fireEvent.change(speed, { target: { value: "9" } });
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    expect(await voiceSettings.findByText("Give a number from 0.5 to 1.5.")).toBeTruthy();
    expect(speed.getAttribute("aria-invalid")).toBe("true");
  });

  it("starts a provider changed to afresh, and narrows the voices to the chosen model with the starred ones first", async () => {
    const sent = setup();
    openForm();
    const voices = (await screen.findByLabelText("Voice")) as HTMLSelectElement;
    await waitFor(() => expect(voices.querySelector("optgroup")?.label).toBe("Starred"));
    expect([...voices.querySelector("optgroup")!.querySelectorAll("option")].map((o) => o.textContent)).toEqual(["Asha · English"]);
    expect([...voices.options].filter((o) => o.value === "asha"), "a starred voice listed once").toHaveLength(1);
    expect(sent.map((s) => s.path)).toContain("/api/kit/console/voices?provider=tts-a&model=oV1");

    fireEvent.change(within(screen.getByRole("group", { name: "Voice settings" })).getByLabelText("Model"), { target: { value: "oKo" } });
    await waitFor(() => expect([...voices.options].map((o) => o.value)).toContain("af_alloy"));
    expect(sent.map((s) => s.path)).toContain("/api/kit/console/voices?provider=tts-a&model=oKo");

    fireEvent.change(screen.getByLabelText("Voice provider"), { target: { value: "tts-b" } });
    const bright = within(await screen.findByRole("group", { name: "Voice settings" }));
    expect((bright.getByLabelText("Stability") as HTMLInputElement).value, "none of the last provider's settings").toBe("");
    expect((bright.getByLabelText("Speed") as HTMLInputElement).value, "not the last provider's speed (1.2) under the same name").toBe("");
    fireEvent.change(screen.getByLabelText("Voice"), { target: { value: "rachel" } });
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(sent.some((s) => s.method === "PATCH")).toBe(true));
    expect((sent.find((s) => s.method === "PATCH")?.body as { providerSettings: unknown; ttsProvider: string }).providerSettings).toEqual({ llm: { temperature: null }, tts: { stability: null, speed: null } });
  });
});

describe("checking the voice setup", () => {
  it("shows the last check, checks again, and says why a part does not work in the kit's words", async () => {
    let n = 0;
    const answers = [
      () => Response.json({ checkedAt: "2026-11-05T10:00:00Z", parts: [{ part: "llm", status: "ok", reason: null }, { part: "stt", status: "failed", reason: "This provider is not set up on the service yet. Contact support." },
        { part: "tts", status: "not_checked", reason: "There is no automatic check for this provider. Try a test call." }] }),
      () => Response.json({ message: "The voice setup was checked a moment ago. Try again in a few seconds." }, { status: 429 }),
    ];
    const asked = fakeKit({ "POST /api/kit/console/agents/a1/check": () => answers[n++]() });
    render(<StackCheck agent={agent({ stackCheck: { checkedAt: "2026-11-04T10:00:00Z", parts: [{ part: "llm", status: "ok", reason: null }, { part: "stt", status: "ok", reason: null }, { part: "tts", status: "ok", reason: null }] } })} />);
    const list = () => screen.getByRole("list", { name: "Check the voice setup" });
    expect(within(list()).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["Language model: Working", "Listening: Working", "Voice: Working"]);
    fireEvent.click(screen.getByRole("button", { name: "Check the voice setup" }));
    await waitFor(() => expect(within(list()).getAllByRole("listitem")[1].textContent).toBe("Listening: Not working · This provider is not set up on the service yet. Contact support."));
    expect(within(list()).getAllByRole("listitem")[2].textContent).toBe("Voice: Not checked · There is no automatic check for this provider. Try a test call.");
    expect(asked.filter((s) => s.method === "POST")).toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Check the voice setup" }));
    expect((await screen.findByRole("alert")).textContent).toBe("The voice setup was checked a moment ago. Try again in a few seconds.");
  });
});

describe("stars", () => {
  it("stars and unstars a voice, and shows only the starred ones when asked", async () => {
    const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
    const sent = fakeKit({
      "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null },
      "GET /api/kit/console/workspaces": { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] },
      "GET /api/kit/console/catalog": choices,
      "GET /api/kit/console/own-voices": { allowed: false, voices: [] },
      "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf"], textMaxLength: 500000 } },
      "GET /api/kit/console/voices?page=1": page([voice("vikram", "Vikram"), voice("asha", "Asha", { starred: true })]),
      "GET /api/kit/console/voices?starred=1&page=1": page([voice("asha", "Asha", { starred: true })]),
      "PUT /api/kit/console/voices/stars": () => new Response(null, { status: 204 }),
      "DELETE /api/kit/console/voices/stars?provider=tts-a&voice=asha": () => new Response(null, { status: 204 }),
    });
    render(<MemoryRouter initialEntries={["/console/voices"]}><Routes><Route path="/console/*" element={<ConsoleArea />} /></Routes></MemoryRouter>);
    const star = await screen.findByRole("button", { name: "Star Vikram" });
    expect(star.getAttribute("aria-pressed")).toBe("false");
    fireEvent.click(star);
    await waitFor(() => expect(screen.getByRole("button", { name: "Unstar Vikram" }).getAttribute("aria-pressed")).toBe("true"));
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ provider: "tts-a", voice: "vikram" });
    fireEvent.click(screen.getByRole("button", { name: "Unstar Asha" }));
    await waitFor(() => expect(screen.getByRole("button", { name: "Star Asha" })).toBeTruthy());
    expect(sent.some((s) => s.method === "DELETE" && s.path === "/api/kit/console/voices/stars?provider=tts-a&voice=asha")).toBe(true);
    fireEvent.click(screen.getByLabelText("Starred only"));
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    await waitFor(() => expect(sent.map((s) => s.path)).toContain("/api/kit/console/voices?starred=1&page=1"));
  });
});
