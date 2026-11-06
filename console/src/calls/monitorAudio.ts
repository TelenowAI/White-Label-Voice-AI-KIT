/**
 * The sound of a call being listened in to (contract/kit-api.yaml listenToLiveCall): each socket frame is a byte for the side (0 the customer, 1 the agent or the person in the browser) and 20 ms of
 * 8 kHz G.711 μ-law, which the voice platform sends. Decoded here to the browser's samples, and each side played on its own timeline so the two sum as they happened.
 *
 * Not here: getting the address and the socket (ListenIn.tsx).
 */

/** The rate the voice platform sends listening sound at. */
export const MONITOR_RATE = 8000;
/** How far ahead of now a side's next frame is scheduled, so a late frame still plays whole. */
const LEAD_SECONDS = 0.1;
/** A side further behind than this is caught up by dropping frames, so listening stays near live. */
const MAX_BEHIND_SECONDS = 1;

/** One μ-law byte as a 16-bit sample (G.711). */
export function ulawToLinear(byte: number): number {
  const u = ~byte & 0xff;
  const magnitude = ((((u & 0x0f) << 3) + 0x84) << ((u >> 4) & 0x07)) - 0x84;
  return u & 0x80 ? -magnitude : magnitude;
}

const TABLE = Float32Array.from({ length: 256 }, (_, b) => ulawToLinear(b) / 32768);

/** μ-law bytes as samples between -1 and 1. */
export function decodeUlaw(bytes: Uint8Array): Float32Array<ArrayBuffer> {
  const out = new Float32Array(new ArrayBuffer(bytes.length * Float32Array.BYTES_PER_ELEMENT));
  for (let i = 0; i < bytes.length; i++) out[i] = TABLE[bytes[i]];
  return out;
}

/** What the player needs of an AudioContext. */
export interface MonitorContext {
  readonly currentTime: number;
  readonly destination: AudioNode;
  createBuffer(channels: number, length: number, sampleRate: number): AudioBuffer;
  createBufferSource(): AudioBufferSourceNode;
}

/** Plays a listened-in call's frames as they come: each side on its own timeline. */
export class MonitorPlayer {
  private readonly next: [number, number] = [0, 0];

  constructor(private readonly ctx: MonitorContext) {}

  /** One socket frame. Answers whether it was played (an unknown side, an empty frame or one too far behind is not). */
  push(frame: Uint8Array): boolean {
    const side = frame[0];
    if ((side !== 0 && side !== 1) || frame.length < 2) return false;
    const now = this.ctx.currentTime;
    let at = this.next[side];
    if (at > now + MAX_BEHIND_SECONDS) return false;
    if (at < now + LEAD_SECONDS) at = now + LEAD_SECONDS;
    const samples = decodeUlaw(frame.subarray(1));
    const buffer = this.ctx.createBuffer(1, samples.length, MONITOR_RATE);
    buffer.copyToChannel(samples, 0);
    const source = this.ctx.createBufferSource();
    source.buffer = buffer;
    source.connect(this.ctx.destination);
    source.start(at);
    this.next[side] = at + samples.length / MONITOR_RATE;
    return true;
  }
}
