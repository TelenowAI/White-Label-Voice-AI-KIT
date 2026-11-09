/**
 * Voices, an agent's voice setup and Admin → Catalog: the offered voices in the console's words (language names from
 * the browser, the operator's provider names) narrowed and paged as the backend is asked; a voice or an agent heard in
 * a clip the backend makes (its refusal in its words); an agent's stack chosen whole from what is offered (every voice
 * of the chosen provider; a part no longer offered kept and said so), the backend's word on a field; and the operator's
 * page: names, the platform's name flagged, models, defaults (the platform's recommendation one click away), saved whole;
 * and what customers may use (transfers, calls from the browser, web chat, consent, cloning), each saved with the rest.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Agent, CatalogCuration, ConsoleHome, StackChoices, Voice, VoicePage, WorkspaceList } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { agentSettings } from "./agentSettings";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

const made: string[] = [];
beforeEach(() => {
  made.length = 0;
  URL.createObjectURL = vi.fn(() => {
    made.push(`blob:clip-${made.length + 1}`);
    return made[made.length - 1];
  });
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const owner = { id: 1, name: "Op", email: "op@example.com", role: "owner" };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const none = { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null };

const choices: StackChoices = {
  llm: [{ key: "pA", name: "OpenAI", settings: [], models: [{ id: "gpt-4o-mini", name: "GPT-4o mini" }, { id: "gpt-4o", name: "GPT-4o" }] },
    { key: "pB", name: "Anthropic", settings: [], models: [{ id: "claude-haiku-4-5", name: "Fast and thrifty" }] }],
  stt: [{ key: "pS", name: "Deepgram", settings: [] }],
  tts: [{ key: "pT", name: "Studio voices", settings: [] }, { key: "pE", name: "ElevenLabs", settings: [] }],
  realtime: [],
  defaults: { llmProvider: "pA", llmModel: "gpt-4o-mini", sttProvider: "pS", ttsProvider: "pT", ttsVoice: "vikram-en-in" },
};

function voice(over: Partial<Voice> & { id: string; name: string }): Voice {
  return { provider: "pT", providerName: "Studio voices", language: "hi", gender: "female", accent: null, style: null, useCase: null,
    description: null, tags: [], recommended: false, starred: false, ...over };
}

const vikram = voice({ id: "vikram-en-in", name: "Vikram", language: "en", gender: "male", accent: "indian", style: "firm professional", recommended: true });
const nandini = voice({ id: "nandini-hi", name: "Nandini" });
const page = (voices: Voice[], over: Partial<VoicePage> = {}): VoicePage =>
  ({ voices, total: voices.length, page: 1, perPage: 24, languages: ["en", "hi"], genders: ["female", "male"], ...over });

const signedIn = {
  "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/catalog": choices,
  "GET /api/kit/console/own-voices": { allowed: false, manages: false, paused: null, clones: { used: 0, limit: 10 }, targets: [], relations: [], releaseVersion: "kit-voice-release-1",
    limits: { nameMaxLength: 120, personMaxLength: 120, languageMaxLength: 16, scriptMaxLength: 1000, sampleMaxBytes: 2097152, sampleMinSeconds: 3, sampleTypes: ["wav"] }, voices: [] },
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
};
const wav = () => new Response(new Blob([new Uint8Array([82, 73, 70, 70])], { type: "audio/wav" }), { headers: { "content-type": "audio/wav" } });

function open(path: string, area = <ConsoleArea />, base = "/console") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path={`${base}/*`} element={area} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("the voices", () => {
  it("lists the offered voices in the console's words and plays one in a clip the backend makes", async () => {
    const sent = fakeKit({ ...signedIn, "GET /api/kit/console/voices?page=1": page([vikram, nandini]), "POST /api/kit/console/voices/preview": wav });
    open("/console/voices");
    const rows = within(await screen.findByRole("table", { name: "Voices" })).getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(0, 5).map((c) => c.textContent))).toEqual([
      ["Vikram · Recommended", "Studio voices", "English", "male", "indian · firm professional"],
      ["Nandini", "Studio voices", "Hindi", "female", ""],
    ]);
    fireEvent.click(within(rows[0]).getByRole("button", { name: "Play Vikram" }));
    const player = (await within(rows[0]).findByLabelText("Play Vikram", { selector: "audio" })) as HTMLAudioElement;
    expect(player.getAttribute("src")).toBe("blob:clip-1");
    expect(sent.filter((s) => s.method === "POST").map((s) => s.body)).toEqual([{ provider: "pT", voice: "vikram-en-in" }]);
  });

  it("narrows by provider, language, gender and words, a page at a time, as the backend is asked", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/voices?page=1": page([vikram, nandini], { total: 30 }),
      "GET /api/kit/console/voices?page=2": page([nandini], { total: 30, page: 2 }),
      "GET /api/kit/console/voices?provider=pT&language=hi&gender=female&search=calm&page=1": page([], { languages: [] }),
    });
    open("/console/voices");
    await screen.findByRole("table", { name: "Voices" });
    fireEvent.click(screen.getByRole("button", { name: "Next" }));
    await waitFor(() => expect(sent.map((s) => s.path)).toContain("/api/kit/console/voices?page=2"));
    const form = screen.getByRole("search", { name: "Narrow the voices" });
    fireEvent.change(within(form).getByLabelText("Provider"), { target: { value: "pT" } });
    fireEvent.change(within(form).getByLabelText("Language"), { target: { value: "hi" } });
    fireEvent.change(within(form).getByLabelText("Gender"), { target: { value: "female" } });
    fireEvent.change(within(form).getByLabelText("Words"), { target: { value: "  calm " } });
    fireEvent.click(within(form).getByRole("button", { name: "Search" }));
    expect(await screen.findByText("No voices match.")).toBeTruthy();
    expect((within(form).getByLabelText("Language") as HTMLSelectElement).options).toHaveLength(3); // the first answer's languages stay
  });

  it("says the backend's words when a clip is refused", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/voices?page=1": page([vikram]),
      "POST /api/kit/console/voices/preview": () => Response.json({ message: "Previews are limited to a few at a time. Try again in a moment." }, { status: 429 }) });
    open("/console/voices");
    fireEvent.click(await screen.findByRole("button", { name: "Play Vikram" }));
    expect((await screen.findByRole("alert")).textContent).toBe("Previews are limited to a few at a time. Try again in a moment.");
  });
});

describe("an agent's voice setup", () => {
  const agent = (over: Partial<Agent> = {}): Agent => ({
    id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true, syncState: "synced", syncError: null,
    stack: { llmProvider: "pA", llmModel: "gpt-4o-mini", sttProvider: "pS", ttsProvider: "pT", ttsVoice: "vikram-en-in" },
    settings: agentSettings, stackCheck: null,
    stackNames: { llmProvider: "OpenAI", llmModel: "GPT-4o mini", sttProvider: "Deepgram", ttsProvider: "Studio voices", ttsVoice: "Vikram" },
    knowledgeBases: [], logoUrl: null,
    createdAt: "2026-10-30T09:00:00Z", updatedAt: "2026-10-30T09:00:00Z", ...over,
  });
  const eleven = [voice({ id: "e1", name: "Aria", provider: "pE", providerName: "ElevenLabs" }), voice({ id: "e2", name: "Sarah", provider: "pE", providerName: "ElevenLabs" })];

  it("chooses the stack whole from what is offered, more of the provider's voices on request, and hears the voice and the agent", async () => {
    let saved: Agent = agent();
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": () => Response.json(saved),
      "GET /api/kit/console/voices?provider=pT": page([vikram, nandini]),
      "GET /api/kit/console/voices?provider=pE": page([eleven[0]], { total: 2, perPage: 1 }),
      "GET /api/kit/console/voices?provider=pE&page=2": page([eleven[1]], { total: 2, perPage: 1, page: 2 }),
      "PATCH /api/kit/console/agents/a1": (body: unknown) => {
        const b = body as Agent["stack"];
        if (b.ttsVoice === "") return Response.json({ message: "x", errors: { ttsVoice: ["Choose one of the voices offered."] } }, { status: 422 });
        saved = agent({ stack: b, updatedAt: "2026-10-30T10:00:00Z", stackNames: { ...saved.stackNames, llmProvider: "Anthropic", ttsVoice: "Sarah" } });
        return Response.json(saved);
      },
      "POST /api/kit/console/voices/preview": wav,
      "POST /api/kit/console/agents/a1/voice-preview": wav,
    });
    open("/console/agents/a1");
    const form = await screen.findByRole("form", { name: "Voice setup" });
    await waitFor(() => expect((within(form).getByLabelText("Voice") as HTMLSelectElement).options).toHaveLength(3));
    fireEvent.change(within(form).getByLabelText("Language model"), { target: { value: "pB" } });
    expect((within(form).getByLabelText("Model") as HTMLSelectElement).value).toBe("");
    fireEvent.change(within(form).getByLabelText("Model"), { target: { value: "claude-haiku-4-5" } });
    fireEvent.change(within(form).getByLabelText("Voice provider"), { target: { value: "pE" } });
    await waitFor(() => expect([...(within(form).getByLabelText("Voice") as HTMLSelectElement).options].map((o) => o.textContent))
      .toEqual(["Choose…", "Aria · Hindi · female"]));
    expect(within(form).getByText("Showing 1 of 2", { exact: false })).toBeTruthy();
    expect(sent.some((s) => s.path.endsWith("provider=pE&page=2")), "never the whole list unasked").toBe(false);
    fireEvent.click(within(form).getByRole("button", { name: "Show more" }));
    await waitFor(() => expect([...(within(form).getByLabelText("Voice") as HTMLSelectElement).options].map((o) => o.textContent))
      .toEqual(["Choose…", "Aria · Hindi · female", "Sarah · Hindi · female"]));
    expect(within(form).queryByRole("button", { name: "Show more" })).toBeNull();
    fireEvent.click(within(form).getByRole("button", { name: "Save voice setup" }));
    expect(await within(form).findByText("Choose one of the voices offered.")).toBeTruthy();

    fireEvent.change(within(form).getByLabelText("Voice"), { target: { value: "e2" } });
    fireEvent.click(within(form).getByRole("button", { name: "Hear this voice" }));
    await waitFor(() => expect(sent.filter((s) => s.path === "/api/kit/console/voices/preview").map((s) => s.body)).toEqual([{ provider: "pE", voice: "e2" }]));
    fireEvent.click(within(form).getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PATCH").pop()?.body).toEqual(
      { llmProvider: "pB", llmModel: "claude-haiku-4-5", sttProvider: "pS", ttsProvider: "pE", ttsVoice: "e2" }));
    fireEvent.click(await screen.findByRole("button", { name: "Hear the agent" }));
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/console/agents/a1/voice-preview")).toBe(true));
  });

  it("keeps a part the operator no longer offers, and says so — a provider never by its key", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent({
        stack: { ...agent().stack, sttProvider: "pGone", llmModel: "gpt-5.4-mini" },
        settings: agentSettings,
        stackNames: { ...agent().stackNames, sttProvider: null, llmModel: null },
      }),
      "GET /api/kit/console/voices?provider=pT": page([vikram]),
    });
    open("/console/agents/a1");
    const stt = (await screen.findByLabelText("Speech recognition")) as HTMLSelectElement;
    expect([stt.value, stt.selectedOptions[0].textContent]).toEqual(["pGone", "Not offered"]);
    const model = screen.getByLabelText("Model") as HTMLSelectElement;
    expect(model.selectedOptions[0].textContent).toBe("Not offered: gpt-5.4-mini");
    expect(document.body.textContent).not.toContain("pGone");
  });

  const options = (el: HTMLElement) => [...(el as HTMLSelectElement).options].map((o) => o.textContent);
  const many = { total: 1050, perPage: 2, languages: ["en", "hi"] };

  it("shows a saved voice by its name though the page shown does not hold it, and it can be heard", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent({ stack: { ...agent().stack, ttsVoice: "deep-voice" }, stackNames: { ...agent().stackNames, ttsVoice: "Deep Voice" } }),
      "GET /api/kit/console/voices?provider=pT": page([vikram, nandini], many),
      "POST /api/kit/console/voices/preview": wav,
    });
    open("/console/agents/a1");
    const form = await screen.findByRole("form", { name: "Voice setup" });
    const chosen = within(form).getByLabelText("Voice") as HTMLSelectElement;
    await waitFor(() => expect(options(chosen)).toEqual(["Choose…", "Deep Voice", "Vikram · English · male", "Nandini · Hindi · female"]));
    expect(chosen.value).toBe("deep-voice");
    fireEvent.click(within(form).getByRole("button", { name: "Hear this voice" }));
    await waitFor(() => expect(sent.filter((s) => s.path === "/api/kit/console/voices/preview").map((s) => s.body)).toEqual([{ provider: "pT", voice: "deep-voice" }]));
  });

  it("says a saved voice is no longer offered, and offers no clip of it", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent({ stack: { ...agent().stack, ttsVoice: "gone-voice" }, stackNames: { ...agent().stackNames, ttsVoice: null } }),
      "GET /api/kit/console/voices?provider=pT": page([vikram]),
    });
    open("/console/agents/a1");
    const form = await screen.findByRole("form", { name: "Voice setup" });
    const chosen = within(form).getByLabelText("Voice") as HTMLSelectElement;
    await waitFor(() => expect(options(chosen)).toEqual(["Choose…", "Not offered: gone-voice", "Vikram · English · male"]));
    expect(within(form).queryByRole("button", { name: "Hear this voice" })).toBeNull();
  });

  it("finds a voice by words and language, and the one chosen stays whatever is narrowed", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      "GET /api/kit/console/voices?provider=pT": page([vikram, nandini], many),
      "GET /api/kit/console/voices?provider=pT&language=hi": page([nandini], { ...many, total: 1 }),
      "GET /api/kit/console/voices?provider=pT&search=aria": page([eleven[0]], { ...many, total: 1 }),
      "GET /api/kit/console/voices?provider=pT&search=nobody": page([], { ...many, total: 0 }),
    });
    open("/console/agents/a1");
    const form = await screen.findByRole("form", { name: "Voice setup" });
    const chosen = within(form).getByLabelText("Voice") as HTMLSelectElement;
    await waitFor(() => expect(options(chosen)).toHaveLength(3));
    const find = within(form).getByLabelText("Find a voice");
    expect(fireEvent.keyDown(find, { key: "Enter" }), "Enter in the words does not submit the form around them").toBe(false);

    fireEvent.change(find, { target: { value: " aria " } });
    await waitFor(() => expect(options(chosen)).toEqual(["Choose…", "Vikram", "Aria · Hindi · female"]));
    fireEvent.change(chosen, { target: { value: "e1" } });
    expect(chosen.value).toBe("e1");

    fireEvent.change(find, { target: { value: "nobody" } });
    await waitFor(() => expect(options(chosen)).toEqual(["Choose…", "Aria · Hindi · female"]));
    expect(chosen.value, "the voice chosen stays").toBe("e1");
    expect(within(form).getByRole("button", { name: "Hear this voice" })).toBeTruthy();

    fireEvent.change(find, { target: { value: "" } });
    fireEvent.change(within(form).getByLabelText("Language"), { target: { value: "hi" } });
    await waitFor(() => expect(sent.map((s) => s.path)).toContain("/api/kit/console/voices?provider=pT&language=hi"));
    await waitFor(() => expect(options(chosen)).toEqual(["Choose…", "Aria · Hindi · female", "Nandini · Hindi · female"]));
  });

  it("offers nothing to find by when every voice fits, and starts again for another provider", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      "GET /api/kit/console/voices?provider=pT": page([vikram, nandini], many),
      "GET /api/kit/console/voices?provider=pE": page(eleven),
    });
    open("/console/agents/a1");
    const form = await screen.findByRole("form", { name: "Voice setup" });
    const find = await within(form).findByLabelText("Find a voice");
    fireEvent.change(within(form).getByLabelText("Language"), { target: { value: "" } });
    fireEvent.change(find, { target: { value: "nobody" } });
    fireEvent.change(within(form).getByLabelText("Voice provider"), { target: { value: "pE" } });
    await waitFor(() => expect(options(within(form).getByLabelText("Voice"))).toEqual(["Choose…", "Aria · Hindi · female", "Sarah · Hindi · female"]));
    expect(within(form).queryByLabelText("Find a voice"), "two voices need no finding").toBeNull();
    expect(within(form).queryByRole("button", { name: "Show more" })).toBeNull();
  });

  it("drops an answer that lands after a newer question, and says when one fails", async () => {
    let lateAnswer: (r: Response) => void = () => {};
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      "GET /api/kit/console/voices?provider=pT": page([vikram, nandini], many),
      "GET /api/kit/console/voices?provider=pT&search=slow": () => new Promise<Response>((resolve) => { lateAnswer = resolve; }),
      "GET /api/kit/console/voices?provider=pT&search=quick": page([eleven[1]], { ...many, total: 1 }),
      "GET /api/kit/console/voices?provider=pT&search=broken": () => Response.json({ message: "The voices could not be read." }, { status: 503 }),
    });
    open("/console/agents/a1");
    const form = await screen.findByRole("form", { name: "Voice setup" });
    const find = await within(form).findByLabelText("Find a voice");
    const chosen = within(form).getByLabelText("Voice") as HTMLSelectElement;
    fireEvent.change(find, { target: { value: "slow" } });
    await new Promise((resolve) => setTimeout(resolve, 350));
    fireEvent.change(find, { target: { value: "quick" } });
    await waitFor(() => expect(options(chosen)).toContain("Sarah · Hindi · female"));
    lateAnswer(Response.json(page([eleven[0]], { ...many, total: 1 })));
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(options(chosen)).not.toContain("Aria · Hindi · female");

    fireEvent.change(find, { target: { value: "broken" } });
    expect((await within(form).findByRole("alert")).textContent).toBe("The voices could not be read.");
  });
});

describe("admin → catalog", () => {
  const curation: CatalogCuration = {
    fetchedAt: "2026-10-30T12:00:00Z", error: null, platformName: "Telenow", browserCallsAllowed: false, browserCallsUnpriced: [], webChatAllowed: false, webChatUnpriced: [], campaignLinksAllowed: false,
    kinds: {
      llm: [{ id: "openai", catalogName: "OpenAI", name: "OpenAI", ownName: null, namesPlatform: false, offered: true, enabled: true, status: "ga", recommended: true,
        models: [{ id: "gpt-4o-mini", catalogName: "GPT-4o mini", name: "GPT-4o mini", ownName: null, namesPlatform: false, offered: true, added: false },
          { id: "gpt-4.1-nano", catalogName: "GPT-4.1 nano", name: "GPT-4.1 nano", ownName: null, namesPlatform: false, offered: false, added: false }], openModels: false, voiceCount: 0, settings: [] }],
      stt: [{ id: "deepgram", catalogName: "Deepgram", name: "Deepgram", ownName: null, namesPlatform: false, offered: false, enabled: true, status: "ga", recommended: true, models: [], openModels: false, voiceCount: 0, settings: [] }],
      tts: [{ id: "telenow", catalogName: "Telenow TTS", name: "Telenow TTS", ownName: null, namesPlatform: true, offered: false, enabled: true, status: "ga", recommended: true, models: [], openModels: false,
        voiceCount: 1050, settings: [] }],
    },
    defaults: { ...none, llmProvider: "openai", llmModel: "gpt-4o-mini" },
    recommended: { ...none, llmProvider: "openai", llmModel: "gpt-5.4-mini" },
    voiceNames: {},
    transferAllowed: false,
    transferStillOn: 0,
    consentRequired: false,
    consentStillToTell: 0,
    cloningAllowed: false,
    clonesPerAccount: 10,
    clonesMost: 50,
    realtime: { enabled: false, providers: [] },
  };
  const staff = { "GET /api/kit/session": { user: owner }, "GET /api/kit/admin/catalog": curation };
  const listVoices = { "GET /api/kit/admin/catalog/voices?provider=telenow": page([vikram], { total: 1050, perPage: 1 }) };

  it("keeps transfers off until the operator allows them, says what they cost, and saves the choice", async () => {
    const sent = fakeKit({ ...staff, ...listVoices, "PUT /api/kit/admin/catalog": () => Response.json({ ...curation, transferAllowed: true }) });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    const box = within(form).getByLabelText("Customers may set up transfers to a person") as HTMLInputElement;
    expect(box.checked).toBe(false);
    expect(within(form).getByText(/you pay the carrier its real price/)).toBeTruthy();
    expect(within(form).queryByText(/still have a transfer/)).toBeNull();
    fireEvent.click(box);
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((c) => c.method === "PUT")).toHaveLength(1));
    expect((sent.find((c) => c.method === "PUT")?.body as { transferAllowed: boolean }).transferAllowed).toBe(true);
    await waitFor(() => expect((within(form).getByLabelText("Customers may set up transfers to a person") as HTMLInputElement).checked).toBe(true));
  });

  it("says how many agents still have a transfer once they are off", async () => {
    fakeKit({ ...staff, ...listVoices, "GET /api/kit/admin/catalog": { ...curation, transferStillOn: 3 } });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    expect(within(form).getByText(/^3 agent\(s\) still have a transfer\./)).toBeTruthy();
  });

  it("saves whether every customer's calls need consent, says what it does and how many are still to be told", async () => {
    const sent = fakeKit({ ...staff, ...listVoices, "PUT /api/kit/admin/catalog": () => Response.json({ ...curation, consentRequired: true, consentStillToTell: 4 }) });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    const box = within(form).getByLabelText("Every customer's calls need consent on file") as HTMLInputElement;
    expect(box.checked).toBe(false);
    expect(within(form).getByText(/stops every customer's calls to numbers with no consent recorded/)).toBeTruthy();
    expect(within(form).queryByText(/still to be told/)).toBeNull();
    fireEvent.click(box);
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((c) => c.method === "PUT")).toHaveLength(1));
    expect((sent.find((c) => c.method === "PUT")?.body as { consentRequired: boolean }).consentRequired).toBe(true);
    expect(await within(form).findByText(/^4 account\(s\) still to be told\./)).toBeTruthy();
  });

  it("chooses which of a provider's own settings customers may change, and which options of one", async () => {
    const withSettings: CatalogCuration = { ...curation, kinds: { ...curation.kinds, tts: curation.kinds.tts.map((p) => p.id === "telenow" ? { ...p, settings: [
      { key: "model", kind: "select", label: "Model", help: null, model: true, offered: true, options: [{ value: "telenow-v1", label: "v1", offered: true }, { value: "kokoro", label: "v2", offered: true }] },
      { key: "keyterms", kind: "text", label: "Key terms", help: null, model: false, offered: false },
    ] } : p) } };
    const sent = fakeKit({ ...staff, ...listVoices, "GET /api/kit/admin/catalog": withSettings, "PUT /api/kit/admin/catalog": () => Response.json(withSettings) });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    fireEvent.click(within(form).getByText("Settings customers may change"));
    const options = within(form).getByRole("group", { name: "Options for Model" });
    expect(within(options).getAllByRole("checkbox").map((c) => (c as HTMLInputElement).checked)).toEqual([true, true]);
    expect((within(form).getByLabelText("Key terms") as HTMLInputElement).checked, "free text off until the operator says").toBe(false);
    fireEvent.click(within(options).getByLabelText("v2"));
    fireEvent.click(within(form).getByLabelText("Key terms"));
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.some((c) => c.method === "PUT")).toBe(true));
    const saved = sent.find((c) => c.method === "PUT")?.body as { kinds: { tts: { telenow: { settings: unknown } } } };
    expect(saved.kinds.tts.telenow.settings).toEqual({ model: { offered: true, options: { "telenow-v1": true, kokoro: false } }, keyterms: { offered: true, options: {} } });
  });

  it("saves whether customers may clone voices and how many clones an account may have, never past the platform's own limit", async () => {
    const sent = fakeKit({
      ...staff, ...listVoices,
      "PUT /api/kit/admin/catalog": (body: unknown) => (body as { clonesPerAccount: number }).clonesPerAccount > 50
        ? Response.json({ message: "The given data was invalid.", errors: { clonesPerAccount: ["The clones per account field must not be greater than 50."] } }, { status: 422 })
        : Response.json({ ...curation, cloningAllowed: true, clonesPerAccount: 3 }),
    });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    const box = within(form).getByLabelText("Customers may clone voices of their own") as HTMLInputElement;
    const clones = within(form).getByLabelText("Cloned voices one account may have") as HTMLInputElement;
    expect([box.checked, clones.value, clones.max]).toEqual([false, "10", "50"]);
    expect(within(form).getByText("At most 50, the voice platform's own limit.")).toBeTruthy();
    fireEvent.click(box);
    fireEvent.change(clones, { target: { value: "51" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(form).findByText("The clones per account field must not be greater than 50.")).toBeTruthy();
    expect(clones.getAttribute("aria-invalid")).toBe("true");
    fireEvent.change(clones, { target: { value: "3" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((c) => c.method === "PUT")).toHaveLength(2));
    expect(sent.filter((c) => c.method === "PUT").map((c) => { const b = c.body as { cloningAllowed: boolean; clonesPerAccount: number }; return [b.cloningAllowed, b.clonesPerAccount]; }))
      .toEqual([[true, 51], [true, 3]]);
    expect((await screen.findByRole("status")).textContent).toBe("Saved.");
  });

  it("saves whether customers may place calls from the browser, and names the plans that cannot price one", async () => {
    const sent = fakeKit({
      ...staff, ...listVoices,
      "GET /api/kit/admin/catalog": { ...curation, browserCallsAllowed: true, browserCallsUnpriced: [{ id: "standard", name: "Standard" }, { id: "gold", name: "Gold" }] },
      "PUT /api/kit/admin/catalog": () => Response.json({ ...curation, browserCallsAllowed: false }),
    });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    const box = within(form).getByLabelText("Customers may place calls from the browser, from their own numbers") as HTMLInputElement;
    expect(box.checked).toBe(true);
    expect(within(form).getByText(/Transferring one also needs transfers to be allowed/)).toBeTruthy();
    expect(within(form).getByText("These plans have no price for a call from the browser, so their customers cannot place one: Standard, Gold.")).toBeTruthy();
    fireEvent.click(box);
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((c) => c.method === "PUT")).toHaveLength(1));
    expect((sent.find((c) => c.method === "PUT")?.body as { browserCallsAllowed: boolean }).browserCallsAllowed).toBe(false);
    await waitFor(() => expect(within(form).queryByText(/have no price for a call from the browser/)).toBeNull());
    expect((within(form).getByLabelText("Customers may place calls from the browser, from their own numbers") as HTMLInputElement).checked).toBe(false);
  });

  it("saves whether customers may put a chat box on their websites, says how a chat is charged, and names the plans that cannot price one", async () => {
    const sent = fakeKit({
      ...staff, ...listVoices,
      "GET /api/kit/admin/catalog": { ...curation, webChatUnpriced: [{ id: "standard", name: "Standard" }, { id: "gold", name: "Gold" }] },
      "PUT /api/kit/admin/catalog": () => Response.json({ ...curation, webChatAllowed: true }),
    });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    const box = within(form).getByLabelText("Customers may put a chat box on their websites, answered by their agents") as HTMLInputElement;
    expect(box.checked).toBe(false);
    expect(box.closest("fieldset")?.querySelector("legend")?.textContent).toBe("Web chat");
    expect(within(form).getByText(/charged by the price lists as a call with the channel chat: its language model, knowledge look-ups and platform fee/)).toBeTruthy();
    expect(within(form).getByText("These plans have no price for a chat, so their customers cannot turn a chat box on, and one that is on stops answering: Standard, Gold.")).toBeTruthy();
    fireEvent.click(box);
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((c) => c.method === "PUT")).toHaveLength(1));
    const saved = sent.find((c) => c.method === "PUT")?.body as { webChatAllowed: boolean; browserCallsAllowed: boolean };
    expect([saved.webChatAllowed, saved.browserCallsAllowed]).toEqual([true, false]);
    await waitFor(() => expect(within(form).queryByText(/have no price for a chat/)).toBeNull());
    expect((within(form).getByLabelText("Customers may put a chat box on their websites, answered by their agents") as HTMLInputElement).checked).toBe(true);
  });

  it("does not say so while they are allowed, when an agent having one is as it should be", async () => {
    fakeKit({ ...staff, ...listVoices, "GET /api/kit/admin/catalog": { ...curation, transferAllowed: true, transferStillOn: 3 } });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    expect((within(form).getByLabelText("Customers may set up transfers to a person") as HTMLInputElement).checked).toBe(true);
    expect(within(form).queryByText(/still have a transfer/)).toBeNull();
  });

  it("names, offers and saves the whole choice, flagging a name that carries the platform's", async () => {
    const sent = fakeKit({
      ...staff,
      ...listVoices,
      "PUT /api/kit/admin/catalog": (body: unknown) => {
        const b = body as { kinds: { tts: Record<string, { name: string | null }> } };
        if (b.kinds.tts.telenow.name === null) return Response.json({ message: "x", errors: { "kinds.tts.telenow.name": ["This name carries \"Telenow\"."] } }, { status: 422 });
        return Response.json(curation);
      },
    });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    expect(within(form).getByText("Carries “Telenow”, which customers never see: give it a name of your own to offer it.")).toBeTruthy();
    fireEvent.click(within(form).getByLabelText("Offer Telenow TTS"));
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(form).findByText("This name carries \"Telenow\".")).toBeTruthy();

    fireEvent.change(within(form).getByLabelText("Name customers see for Telenow TTS"), { target: { value: " Studio voices " } });
    expect(within(form).queryByText(/Carries “Telenow”/)).toBeNull();
    fireEvent.change(within(form).getByLabelText("Name customers see for GPT-4o mini"), { target: { value: "Quick" } });
    fireEvent.change(within(form).getByLabelText("Voice provider"), { target: { value: "telenow" } });
    await waitFor(() => expect((within(form).getByLabelText("Voice") as HTMLSelectElement).options).toHaveLength(2));
    expect(within(form).getByText("1050 voices")).toBeTruthy();
    expect(sent.map((s) => s.path), "the admin's own list, not a customer's").toContain("/api/kit/admin/catalog/voices?provider=telenow");
    fireEvent.change(within(form).getByLabelText("Voice"), { target: { value: "vikram-en-in" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect((await screen.findByRole("status")).textContent).toBe("Saved.");
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({
      transferAllowed: false,
      browserCallsAllowed: false,
      webChatAllowed: false,
      campaignLinksAllowed: false,
      consentRequired: false,
      cloningAllowed: false,
      clonesPerAccount: 10,
      realtime: {},
      kinds: {
        llm: { openai: { offered: true, name: null, models: { "gpt-4o-mini": { offered: true, name: "Quick" }, "gpt-4.1-nano": { offered: false, name: null } }, settings: {} } },
        stt: { deepgram: { offered: false, name: null, settings: {} } },
        tts: { telenow: { offered: true, name: "Studio voices", settings: {} } },
      },
      defaults: { llmProvider: "openai", llmModel: "gpt-4o-mini", sttProvider: null, ttsProvider: "telenow", ttsVoice: "vikram-en-in" },
    });
  });

  it("names the default and the recommended voice though the page shown does not hold them", async () => {
    const withDefaults: CatalogCuration = {
      ...curation,
      defaults: { ...curation.defaults, ttsProvider: "telenow", ttsVoice: "deep-voice" },
      recommended: { ...curation.recommended, ttsProvider: "telenow", ttsVoice: "other-voice" },
      voiceNames: { "deep-voice": "Deep Voice", "other-voice": "Other Voice" },
    };
    fakeKit({ ...staff, ...listVoices, "GET /api/kit/admin/catalog": withDefaults });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    const chosen = within(form).getByLabelText("Voice") as HTMLSelectElement;
    await waitFor(() => expect(chosen.selectedOptions[0]?.textContent).toBe("Deep Voice"));
    fireEvent.click(within(form).getByRole("button", { name: "Use the platform's recommendation" }));
    await waitFor(() => expect(chosen.selectedOptions[0]?.textContent).toBe("Other Voice"));
  });

  it("reads the catalog again, and fills the defaults with the platform's recommendation", async () => {
    const sent = fakeKit({ ...staff, "POST /api/kit/admin/catalog/refresh": { ...curation, fetchedAt: "2026-10-30T13:00:00Z" } });
    open("/admin/catalog", <AdminArea />, "/admin");
    const form = await screen.findByRole("form", { name: "Catalog" });
    fireEvent.click(within(form).getByRole("button", { name: "Use the platform's recommendation" }));
    expect((within(form).getByLabelText("Model") as HTMLSelectElement).value).toBe("gpt-5.4-mini");
    fireEvent.click(screen.getByRole("button", { name: "Read the catalog again" }));
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/admin/catalog/refresh")).toBe(true));
  });
});
