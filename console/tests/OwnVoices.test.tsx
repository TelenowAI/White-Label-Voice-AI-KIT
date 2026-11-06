/**
 * The account's own voices (voices/OwnVoices.tsx, voices/Recorder.tsx, and the agent's voice picker): not shown until the operator allows cloning, those made stay listed; a manager
 * names the person and confirms their release, records them in the browser (the microphone let go after) or uploads a file, clones into a provider that can, hears a clone by the
 * voice's own name, removes a clone and deletes a voice only after a confirm, and sees the backend's word when it says no; a member sees the voices but changes nothing; an agent's
 * voice setup offers the voices ready on its provider as a group of their own.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Agent, OwnVoice, OwnVoicesPage, StackChoices } from "../src/api";
import { StackForm } from "../src/agents/StackForm";
import { OwnVoices } from "../src/voices/OwnVoices";
import { fakeKit } from "./fakeKit";
import { agentSettings } from "./agentSettings";

const PAGE = "GET /api/kit/console/own-voices";

function page(over: Partial<OwnVoicesPage> = {}): OwnVoicesPage {
  return {
    allowed: true, manages: true, paused: null, clones: { used: 0, limit: 10 },
    targets: [{ key: "tts-a", name: "Studio voices" }, { key: "tts-b", name: "Bright voices" }],
    relations: ["employee", "voice_actor", "customer", "other"], releaseVersion: "kit-voice-release-1",
    limits: { nameMaxLength: 120, personMaxLength: 120, languageMaxLength: 16, scriptMaxLength: 1000, sampleMaxBytes: 2097152, sampleMinSeconds: 3, sampleTypes: ["wav", "webm"] },
    voices: [], ...over,
  };
}

function voice(over: Partial<OwnVoice> = {}): OwnVoice {
  return {
    id: "v1", voice: "own:v1", name: "Front desk Asha", personName: "Asha Rao", relation: "employee", language: "en", source: "record", hasSample: false, sampleSeconds: null,
    confirmedBy: "Ana", confirmedAt: "2026-11-05T08:00:00Z", releaseVersion: "kit-voice-release-1", createdAt: "2026-11-05T08:00:00Z", clones: [], usedBy: [], ...over,
  };
}

const ready = { provider: "tts-a", providerName: "Studio voices", status: "ready" };

/** A browser's microphone and recorder, the clock read by the recorder, and what was made of them. */
let now = 1_000;
const recorders: FakeRecorder[] = [];
const track = { stop: vi.fn() };
class FakeRecorder {
  static isTypeSupported = (type: string) => type === "audio/webm";
  state = "inactive";
  ondataavailable: ((e: { data: Blob }) => void) | null = null;
  onstop: (() => void) | null = null;
  mimeType: string;
  constructor(_stream: unknown, options?: { mimeType?: string }) {
    this.mimeType = options?.mimeType ?? "";
    recorders.push(this);
  }
  start() {
    this.state = "recording";
  }
  stop() {
    this.state = "inactive";
    this.ondataavailable?.({ data: new Blob(["a take"], { type: this.mimeType }) });
    this.onstop?.();
  }
}
const getUserMedia = vi.fn(async () => ({ getTracks: () => [track] }));

beforeEach(() => {
  now = 1_000;
  recorders.length = 0;
  track.stop.mockClear();
  getUserMedia.mockClear();
  vi.spyOn(Date, "now").mockImplementation(() => now);
  vi.stubGlobal("MediaRecorder", FakeRecorder);
  Object.defineProperty(navigator, "mediaDevices", { value: { getUserMedia }, configurable: true });
  URL.createObjectURL = vi.fn(() => "blob:take"); // jsdom has none: a clip or a take is played from one
  URL.revokeObjectURL = vi.fn();
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

function open() {
  render(<MemoryRouter><OwnVoices /></MemoryRouter>);
}

describe("the account's own voices", () => {
  it("is not there until the operator allows cloning, and voices already made stay listed", async () => {
    const sent = fakeKit({ [PAGE]: page({ allowed: false }) });
    open();
    await waitFor(() => expect(sent).toHaveLength(1));
    await new Promise((r) => setTimeout(r, 10));
    expect(screen.queryByRole("heading", { name: "Your voices" })).toBeNull();
    cleanup();
    fakeKit({ [PAGE]: page({ allowed: false, voices: [voice({ hasSample: true, sampleSeconds: 5.2, clones: [ready] })] }) });
    open();
    expect(await screen.findByRole("heading", { name: "Your voices" })).toBeTruthy();
    expect(screen.getByText("Cloning new voices is not available on this service. The voices below keep working.")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Hear Front desk Asha on Studio voices" })).toBeTruthy();
    expect(screen.queryByRole("heading", { name: "Add a voice" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Clone" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Add a recording" })).toBeNull();
  });

  it("adds a voice naming the person and confirming their release, then records them here and lets the microphone go", async () => {
    let reads = 0;
    const sent = fakeKit({
      [PAGE]: () => Response.json(reads++ === 0 ? page() : page({ voices: [voice({ hasSample: true, sampleSeconds: 5.2 })] })),
      "POST /api/kit/console/own-voices": () => Response.json(voice(), { status: 201 }),
      "POST /api/kit/console/own-voices/v1/sample": () => Response.json(voice({ hasSample: true, sampleSeconds: 5.2 })),
    });
    open();
    const form = within(await screen.findByRole("form", { name: "Add a voice" }));
    fireEvent.change(form.getByLabelText("Voice name"), { target: { value: " Front desk Asha " } });
    fireEvent.change(form.getByLabelText("Whose voice it is"), { target: { value: "Asha Rao" } });
    fireEvent.change(form.getByLabelText("How they relate to you"), { target: { value: "voice_actor" } });
    const release = form.getByLabelText("I confirm we hold Asha Rao's signed release to clone their voice and use it on our calls.") as HTMLInputElement;
    expect(release.required).toBe(true);
    expect(form.getByText("Release kit-voice-release-1")).toBeTruthy();
    fireEvent.click(release);
    fireEvent.click(form.getByRole("button", { name: "Add voice" }));
    await waitFor(() => expect(sent.some((s) => s.method === "POST" && s.path === "/api/kit/console/own-voices")).toBe(true));
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ name: "Front desk Asha", personName: "Asha Rao", relation: "voice_actor", confirmed: true, language: null,
      script: "Hello, and thank you for calling. I'm happy to help you today. Could you tell me a little about what you need?", source: "record" });

    fireEvent.click(await screen.findByRole("button", { name: "Start recording" }));
    expect((await screen.findByRole("status")).textContent).toBe("Recording… 0 s");
    expect(getUserMedia).toHaveBeenCalledWith({ audio: true });
    expect(recorders[0].mimeType).toBe("audio/webm");
    now += 5_234;
    fireEvent.click(screen.getByRole("button", { name: "Stop" }));
    expect(track.stop).toHaveBeenCalled();
    fireEvent.click(await screen.findByRole("button", { name: "Use this recording" }));
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/console/own-voices/v1/sample")).toBe(true));
    const upload = sent.find((s) => s.path === "/api/kit/console/own-voices/v1/sample")!.raw as FormData;
    const file = upload.get("file") as File;
    expect([file.name, file.type, upload.get("seconds")], "the length to the tenth").toEqual(["recording.webm", "audio/webm", "5.2"]);
    expect(await screen.findByText("5.2 s")).toBeTruthy();
    cleanup();
    fakeKit({ [PAGE]: page({ voices: [voice({ hasSample: true, sampleSeconds: 12.199999809265 })] }) });
    open();
    expect(await screen.findByText("12.2 s"), "a length as the voice platform keeps it, shown to the tenth").toBeTruthy();
  });

  it("does not offer a take shorter than the voice platform takes, and says so when the microphone is not allowed", async () => {
    fakeKit({ [PAGE]: page({ voices: [voice()] }) });
    open();
    fireEvent.click(await screen.findByRole("button", { name: "Add a recording" }));
    expect(screen.getByText(/Record at least 3 seconds of the person reading/)).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Start recording" }));
    await screen.findByRole("button", { name: "Stop" });
    now += 1_500;
    fireEvent.click(screen.getByRole("button", { name: "Stop" }));
    expect((await screen.findByRole("alert")).textContent).toBe("That was 1.5 s. Record at least 3 seconds.");
    expect(screen.queryByRole("button", { name: "Use this recording" })).toBeNull();
    getUserMedia.mockRejectedValueOnce(new Error("NotAllowedError"));
    fireEvent.click(screen.getByRole("button", { name: "Record again" }));
    expect((await screen.findByRole("alert")).textContent).toBe("The microphone was not allowed. Allow it in the browser, or upload a file instead.");
  });

  it("uploads a file as it is, and shows the backend's word on it", async () => {
    const sent = fakeKit({
      [PAGE]: page({ voices: [voice({ source: "upload" })] }),
      "POST /api/kit/console/own-voices/v1/sample": () => Response.json({ message: "The given data was invalid.", errors: { file: ["This recording could not be used. Use at least 3 seconds of clear speech, as WAV, MP3, OGG or WebM."] } }, { status: 422 }),
    });
    open();
    fireEvent.click(await screen.findByRole("button", { name: "Add a recording" }));
    const input = screen.getByLabelText("Recording file") as HTMLInputElement;
    expect(input.accept).toBe(".wav,.webm");
    const asha = new File(["RIFF"], "asha.wav", { type: "audio/wav" });
    fireEvent.change(input, { target: { files: [asha] } });
    fireEvent.click(screen.getByRole("button", { name: "Send recording" }));
    expect((await screen.findByRole("alert")).textContent).toBe("This recording could not be used. Use at least 3 seconds of clear speech, as WAV, MP3, OGG or WebM.");
    const upload = sent.find((s) => s.method === "POST")!.raw as FormData;
    expect([(upload.get("file") as File).name, upload.get("seconds")]).toEqual(["asha.wav", null]);
  });

  it("clones into a provider that can, hears a clone by the voice's own name, and removes it only after a confirm", async () => {
    const sent = fakeKit({
      [PAGE]: page({ voices: [voice({ hasSample: true, sampleSeconds: 5.2, clones: [ready] })] }),
      "POST /api/kit/console/own-voices/v1/clones": () => Response.json(voice({ hasSample: true, clones: [ready, { provider: "tts-b", providerName: "Bright voices", status: "ready" }] })),
      "POST /api/kit/console/voices/preview": () => new Response(new Blob(["RIFF"], { type: "audio/wav" }), { headers: { "content-type": "audio/wav" } }),
      "DELETE /api/kit/console/own-voices/v1/clones/tts-a": () => Response.json({ message: "These agents speak with this voice: Billing desk. Give them another voice first." }, { status: 409 }),
    });
    open();
    const target = (await screen.findByLabelText("Clone on")) as HTMLSelectElement;
    expect([...target.options].map((o) => o.textContent), "not one it is cloned on already").toEqual(["Bright voices"]);
    fireEvent.click(screen.getByRole("button", { name: "Clone" }));
    await waitFor(() => expect(sent.find((s) => s.path === "/api/kit/console/own-voices/v1/clones")?.body).toEqual({ provider: "tts-b" }));

    fireEvent.click(screen.getByRole("button", { name: "Hear Front desk Asha on Studio voices" }));
    await waitFor(() => expect(sent.find((s) => s.path === "/api/kit/console/voices/preview")?.body).toEqual({ provider: "tts-a", voice: "own:v1" }));

    fireEvent.click(screen.getByRole("button", { name: "Remove from Studio voices" }));
    expect(screen.getByText("Remove Front desk Asha from Studio voices? Agents can no longer speak with it there.")).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE"), "nothing removed before the yes").toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Yes, remove" }));
    expect((await screen.findByRole("alert")).textContent).toBe("These agents speak with this voice: Billing desk. Give them another voice first.");
  });

  it("deletes a voice only after a confirm, and a member sees the voices but changes nothing", async () => {
    let reads = 0;
    const sent = fakeKit({
      [PAGE]: () => Response.json(reads++ === 0 ? page({ voices: [voice({ hasSample: true, usedBy: [] })] }) : page()),
      "DELETE /api/kit/console/own-voices/v1": () => new Response(null, { status: 204 }),
    });
    open();
    fireEvent.click(await screen.findByRole("button", { name: "Delete voice" }));
    expect(screen.getByText("Delete Front desk Asha, its recording and its clones everywhere? This cannot be undone.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Yes, delete" }));
    expect(await screen.findByText("No voices of your own yet.")).toBeTruthy();
    expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/console/own-voices/v1"]);
    cleanup();

    fakeKit({ [PAGE]: page({ manages: false, targets: [], voices: [voice({ personName: null, relation: null, confirmedBy: null, confirmedAt: null, hasSample: true, clones: [ready],
      usedBy: [{ id: "a1", name: "Billing desk" }] })] }) });
    open();
    expect(await screen.findByText("Only the account owner can add, clone or delete these voices.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Billing desk" }).getAttribute("href")).toBe("/console/agents/a1");
    expect(screen.getByRole("button", { name: "Hear Front desk Asha on Studio voices" })).toBeTruthy();
    for (const name of ["Delete voice", "Remove from Studio voices", "Clone", "Replace the recording"]) expect(screen.queryByRole("button", { name })).toBeNull();
    expect(screen.queryByRole("form", { name: "Add a voice" })).toBeNull();
    expect(screen.queryByText(/Asha Rao/)).toBeNull();
  });
});

describe("an agent's voice setup", () => {
  it("offers the account's own voices ready on the chosen provider, as their own group, and saves one by its own name", async () => {
    const choices: StackChoices = {
      llm: [{ key: "llm-a", name: "Thinking", settings: [], models: [{ id: "m1", name: "Quick" }] }], stt: [{ key: "stt-a", name: "Listening", settings: [] }],
      tts: [{ key: "tts-a", name: "Studio voices", settings: [] }, { key: "tts-b", name: "Bright voices", settings: [] }], realtime: [], defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    };
    const stack = { llmProvider: "llm-a", llmModel: "m1", sttProvider: "stt-a", ttsProvider: "tts-a", ttsVoice: "vikram" };
    const agent = { id: "a1", stack, stackNames: { llmProvider: "Thinking", llmModel: "Quick", sttProvider: "Listening", ttsProvider: "Studio voices", ttsVoice: "Vikram" },
      settings: agentSettings, stackCheck: null } as unknown as Agent;
    const voicePage = (provider: string) => ({ voices: [{ provider, providerName: "", id: "vikram", name: "Vikram", language: "en", gender: null, accent: null, style: null, description: null, recommended: false }],
      total: 1, page: 1, perPage: 24, languages: ["en"], genders: [] });
    const sent = fakeKit({
      "GET /api/kit/console/catalog": choices,
      "GET /api/kit/console/voices?provider=tts-a": voicePage("tts-a"),
      "GET /api/kit/console/voices?provider=tts-b": voicePage("tts-b"),
      [PAGE]: page({ voices: [voice({ hasSample: true, clones: [ready] }), voice({ id: "v2", voice: "own:v2", name: "Not cloned yet", clones: [] }),
        voice({ id: "v3", voice: "own:v3", name: "Still cloning", clones: [{ ...ready, status: "pending" }] })] }),
      "PATCH /api/kit/console/agents/a1": (body: unknown) => Response.json({ ...agent, stack: { ...stack, ...(body as object) } }),
    });
    render(<MemoryRouter><StackForm agent={agent} onSaved={() => undefined} /></MemoryRouter>);
    const select = (await screen.findByLabelText("Voice")) as HTMLSelectElement;
    await waitFor(() => expect(select.querySelector("optgroup")).not.toBeNull());
    const group = select.querySelector("optgroup")!;
    expect([group.label, [...group.querySelectorAll("option")].map((o) => [o.value, o.textContent])]).toEqual(["Your voices", [["own:v1", "Front desk Asha"]]]);
    fireEvent.change(select, { target: { value: "own:v1" } });
    expect([...select.options].filter((o) => o.value === "own:v1"), "listed once, in its group").toHaveLength(1);
    fireEvent.click(screen.getByRole("button", { name: "Save voice setup" }));
    await waitFor(() => expect(sent.find((s) => s.method === "PATCH")?.body).toMatchObject({ ttsProvider: "tts-a", ttsVoice: "own:v1" }));

    fireEvent.change(screen.getByLabelText("Voice provider"), { target: { value: "tts-b" } });
    await waitFor(() => expect((screen.getByLabelText("Voice") as HTMLSelectElement).querySelector("optgroup")).toBeNull());
  });
});
