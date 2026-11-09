/**
 * Listening in (calls/monitorAudio.ts, ListenIn.tsx, LiveNow.tsx, CallPage.tsx): μ-law decoded as G.711 says; each side of the call on its own timeline, a late frame caught up and a side far behind
 * dropped; the listen button only where the backend says the member may; the address the backend answers opened, its frames played, the call ending or stopping said so and the sound closed; a
 * refusal shown; who listened on the call's page.
 */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { CallDetail, ConsoleHome, LiveCalls, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { MONITOR_RATE, MonitorPlayer, decodeUlaw, ulawToLinear, type MonitorContext } from "../src/calls/monitorAudio";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

/** A stand-in for the browser's audio: what was scheduled, when, at what rate. */
class FakeAudio implements MonitorContext {
  currentTime = 10;
  readonly destination = {} as AudioNode;
  readonly started: { at: number; length: number; rate: number; first: number }[] = [];
  closed = false;
  createBuffer(_channels: number, length: number, rate: number): AudioBuffer {
    let data: Float32Array = new Float32Array(length);
    return { length, sampleRate: rate, copyToChannel: (d: Float32Array) => { data = d; }, getChannelData: () => data } as unknown as AudioBuffer;
  }
  createBufferSource(): AudioBufferSourceNode {
    const node = {
      buffer: null as AudioBuffer | null,
      connect: () => undefined,
      start: (at: number) => this.started.push({ at, length: node.buffer!.length, rate: node.buffer!.sampleRate, first: node.buffer!.getChannelData(0)[0] }),
    };
    return node as unknown as AudioBufferSourceNode;
  }
  resume = async () => undefined;
  close = async () => { this.closed = true; };
}

/** A stand-in for the listening socket. */
class FakeSocket {
  static last: FakeSocket | null = null;
  binaryType = "blob";
  onopen: (() => void) | null = null;
  onmessage: ((m: { data: unknown }) => void) | null = null;
  onclose: ((e: { code: number }) => void) | null = null;
  closedWith: number | null = null;
  constructor(readonly url: string) { FakeSocket.last = this; }
  close(code: number) { this.closedWith = code; }
}

let audios: FakeAudio[] = [];

beforeEach(() => {
  audios = [];
  FakeSocket.last = null;
  vi.stubGlobal("AudioContext", class extends FakeAudio { constructor() { super(); audios.push(this); } });
  vi.stubGlobal("WebSocket", FakeSocket);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("the sound", () => {
  it("decodes μ-law as G.711 says", () => {
    expect([ulawToLinear(0xff), ulawToLinear(0x7f), ulawToLinear(0x00), ulawToLinear(0x80), ulawToLinear(0xf0), ulawToLinear(0x70)]).toEqual([0, -0, -32124, 32124, 120, -120]);
    const samples = decodeUlaw(Uint8Array.from([0x80, 0x00, 0xff]));
    expect([...samples]).toEqual([32124 / 32768, -32124 / 32768, 0]);
  });

  it("plays each side on its own timeline, catches up a late one and drops one far behind", () => {
    const audio = new FakeAudio();
    const player = new MonitorPlayer(audio);
    const frame = (side: number) => Uint8Array.from([side, ...new Array(160).fill(0x80)]);
    expect(player.push(frame(0))).toBe(true);
    expect(player.push(frame(0))).toBe(true);
    expect(player.push(frame(1))).toBe(true);
    expect(audio.started.map((s) => [s.at, s.length, s.rate])).toEqual([[10.1, 160, MONITOR_RATE], [10.12, 160, MONITOR_RATE], [10.1, 160, MONITOR_RATE]]);
    expect(audio.started[0].first).toBeCloseTo(32124 / 32768);
    expect(player.push(Uint8Array.from([2, 0x80]))).toBe(false);
    expect(player.push(Uint8Array.from([0]))).toBe(false);
    audio.currentTime = 20; // the customer's side fell behind now: it starts again just ahead
    player.push(frame(0));
    expect(audio.started.at(-1)?.at).toBeCloseTo(20.1);
    audio.currentTime = 20.1;
    for (let i = 0; i < 60; i++) player.push(frame(0)); // 1.2 s arrive at once
    const ahead = audio.started.at(-1)!.at - audio.currentTime;
    expect(ahead).toBeLessThanOrEqual(1);
    expect(audio.started.length).toBeLessThan(3 + 1 + 1 + 60);
  });
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "owner", canManageTeam: true, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "owner", current: true }] };
const signedIn = { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one };
const noCalls = { calls: [], total: 0, page: 1, perPage: 50 };

function live(over: Partial<LiveCalls> = {}): LiveCalls {
  return {
    total: 2, agents: [{ id: "a1", name: "Front desk", count: 1 }], others: 1, canListen: true, refreshSeconds: 15,
    calls: [
      { id: "c1", agent: { id: "a1", name: "Front desk" }, placedBy: null, direction: "inbound", channel: "telephony", from: "+14155550123", to: "+918045671001", startedAt: "2026-11-04T10:00:00Z", canListen: true },
      { id: "c2", agent: null, placedBy: { name: "Ben", me: false }, direction: "web", channel: "web_call", from: null, to: null, startedAt: "2026-11-04T10:01:00Z", canListen: false },
    ],
    ...over,
  };
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

describe("listening in", () => {
  it("offers it only where the member may, opens the address answered and plays what comes", async () => {
    const sent = fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1": noCalls, "GET /api/kit/console/calls/live": live(),
      "POST /api/kit/console/calls/c1/listen": { wsUrl: "wss://voice.example/ws/monitor?ticket=abc" } });
    open("/console/calls");
    const list = await screen.findByRole("list", { name: "On now" });
    const [first, second] = within(list).getAllByRole("listitem");
    expect(first.textContent).toContain("Front desk");
    expect(first.textContent).toContain("+14155550123");
    expect(within(second).queryByRole("button", { name: "Listen in" })).toBeNull();
    expect(second.textContent).toContain("Ben");

    fireEvent.click(within(first).getByRole("button", { name: "Listen in" }));
    await waitFor(() => expect(FakeSocket.last?.url).toBe("wss://voice.example/ws/monitor?ticket=abc"));
    expect(audios).toHaveLength(1);
    expect(FakeSocket.last!.binaryType).toBe("arraybuffer");
    expect(sent.filter((s) => s.method === "POST").map((s) => s.path)).toEqual(["/api/kit/console/calls/c1/listen"]);
    act(() => FakeSocket.last!.onopen?.());
    expect(within(first).getByText("Listening. They cannot hear you.")).toBeTruthy();
    act(() => FakeSocket.last!.onmessage?.({ data: Uint8Array.from([0, ...new Array(160).fill(0xff)]).buffer }));
    act(() => FakeSocket.last!.onmessage?.({ data: "not sound" }));
    act(() => FakeSocket.last!.onmessage?.({ data: "160" })); // a text frame is never sound, even one that reads as a length
    expect(audios[0].started).toHaveLength(1);

    act(() => FakeSocket.last!.onclose?.({ code: 1000 }));
    expect(within(first).getByText("The call has ended.")).toBeTruthy();
    expect(audios[0].closed).toBe(true);
  });

  it("stops when asked, and says so when the socket closes for any other reason", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1": noCalls, "GET /api/kit/console/calls/live": live(),
      "POST /api/kit/console/calls/c1/listen": { wsUrl: "wss://voice.example/ws/monitor?ticket=abc" } });
    open("/console/calls");
    const first = within(await screen.findByRole("list", { name: "On now" })).getAllByRole("listitem")[0];
    fireEvent.click(within(first).getByRole("button", { name: "Listen in" }));
    await waitFor(() => expect(FakeSocket.last).not.toBeNull());
    const socket = FakeSocket.last!;
    fireEvent.click(within(first).getByRole("button", { name: "Stop listening" }));
    expect(socket.closedWith).toBe(1000);
    expect(audios[0].closed).toBe(true);
    expect(within(first).getByRole("button", { name: "Listen in" })).toBeTruthy();

    fireEvent.click(within(first).getByRole("button", { name: "Listen in" }));
    await waitFor(() => expect(FakeSocket.last).not.toBe(socket));
    act(() => FakeSocket.last!.onclose?.({ code: 1008 }));
    expect(within(first).getByText("Listening stopped.")).toBeTruthy();
  });

  it("shows the backend's refusal and keeps no sound open", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1": noCalls, "GET /api/kit/console/calls/live": live(),
      "POST /api/kit/console/calls/c1/listen": () => Response.json({ message: "This call is no longer on." }, { status: 409 }) });
    open("/console/calls");
    const first = within(await screen.findByRole("list", { name: "On now" })).getAllByRole("listitem")[0];
    fireEvent.click(within(first).getByRole("button", { name: "Listen in" }));
    expect(await within(first).findByText("This call is no longer on.")).toBeTruthy();
    expect(FakeSocket.last).toBeNull();
    expect(audios[0].closed).toBe(true);
  });

  it("says so when the browser cannot play sound", async () => {
    vi.stubGlobal("AudioContext", undefined);
    fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1": noCalls, "GET /api/kit/console/calls/live": live() });
    open("/console/calls");
    const first = within(await screen.findByRole("list", { name: "On now" })).getAllByRole("listitem")[0];
    fireEvent.click(within(first).getByRole("button", { name: "Listen in" }));
    expect(within(first).getByText("This browser cannot play the call.")).toBeTruthy();
  });

  it("lists nobody when none is on, and no listen buttons for someone who cannot", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1": noCalls, "GET /api/kit/console/calls/live": live({ canListen: false, calls: live().calls.map((c) => ({ ...c, canListen: false })) }) });
    open("/console/calls");
    const list = await screen.findByRole("list", { name: "On now" });
    expect(within(list).queryAllByRole("button", { name: "Listen in" })).toHaveLength(0);
    cleanup();
    fakeKit({ ...signedIn, "GET /api/kit/console/calls?page=1": noCalls, "GET /api/kit/console/calls/live": live({ total: 0, agents: [], others: 0, calls: [] }) });
    open("/console/calls");
    expect(await screen.findByText("On calls now: 0")).toBeTruthy();
    expect(screen.queryByRole("list", { name: "On now" })).toBeNull();
  });

  it("is on a live call's page for a manager, with who listened", async () => {
    const call: CallDetail = {
      id: "c1", agent: { id: "a1", name: "Front desk" }, placedBy: null, requestedBy: { name: "Ben", me: false }, wrapup: null, status: "active", outcome: null, direction: "inbound", channel: "telephony", from: "+14155550123",
      to: "+918045671001", startedAt: "2026-11-04T10:00:00Z", endedAt: null, durationSec: null, complete: false, charge: null, canListen: true,
      listens: [{ by: "Ana", at: "2026-11-04T10:02:00Z" }, { by: null, at: "2026-11-04T10:01:00Z" }], canTellAgent: false, agentNotes: [], answeredBy: null, transcript: [], recordings: [], analysis: null,
    };
    fakeKit({ ...signedIn, "GET /api/kit/console/calls/c1": call, "GET /api/kit/console/calls/c1/work": { wrapup: null, notes: [], followUps: [], suggestions: [], limits: { noteMaxLength: 2000, wrapupMaxLength: 64, followUpMaxLength: 500 } } });
    open("/console/calls/c1");
    expect(await screen.findByRole("button", { name: "Listen in" })).toBeTruthy();
    const said = screen.getByText("Listened in").nextElementSibling?.textContent ?? "";
    expect(said).toContain("Ana");
    expect(said).toContain("Someone no longer on the team");
    expect(screen.getByText("Asked for by").nextElementSibling?.textContent).toBe("Ben");
    cleanup();
    fakeKit({ ...signedIn, "GET /api/kit/console/calls/c1": { ...call, canListen: false, listens: [] }, "GET /api/kit/console/calls/c1/work": { wrapup: null, notes: [], followUps: [], suggestions: [], limits: { noteMaxLength: 2000, wrapupMaxLength: 64, followUpMaxLength: 500 } } });
    open("/console/calls/c1");
    expect(await screen.findByText("Front desk")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Listen in" })).toBeNull();
    expect(screen.queryByText("Listened in")).toBeNull();
  });
});
