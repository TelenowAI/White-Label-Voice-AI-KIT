/**
 * Listening in to one live call (contract/kit-api.yaml listenToLiveCall): the backend answers the address of the voice platform's listening socket, its one-time ticket in it, which works within
 * 30 seconds; the browser opens it and plays both sides as they come (monitorAudio.ts). The listener cannot be heard. The sound starts on the click, as browsers want, so the audio is made then.
 * Stopping, leaving the page, or the call ending closes the socket; the call ending says so, and anything else that closes it says listening stopped.
 *
 * Used by the calls on now (LiveNow.tsx) and a live call's page (CallPage.tsx), only where the backend says the member may listen.
 */
import { useEffect, useRef, useState } from "react";
import { kitApi } from "../api";
import { t, type TextKey } from "../text";
import { MonitorPlayer } from "./monitorAudio";

type State = "idle" | "connecting" | "listening" | "ended" | "stopped" | "failed";

const stateText: Partial<Record<State, TextKey>> = {
  connecting: "listenConnecting",
  listening: "listenListening",
  ended: "listenEnded",
  stopped: "listenStopped",
};

/** The close the voice platform sends when the call itself is over. */
const CALL_ENDED = 1000;

export function ListenIn({ callId }: { callId: string }) {
  const [state, setState] = useState<State>("idle");
  const [failure, setFailure] = useState<string | null>(null);
  const live = useRef<{ socket: WebSocket | null; audio: AudioContext } | null>(null);

  function close() {
    const current = live.current;
    live.current = null;
    if (!current) return;
    if (current.socket) {
      current.socket.onclose = null;
      current.socket.close(1000);
    }
    void current.audio.close().catch(() => undefined);
  }

  useEffect(() => close, [callId]);

  async function start() {
    setFailure(null);
    const Audio = window.AudioContext ?? (window as unknown as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!Audio) {
      setState("failed");
      setFailure(t("listenNoAudio"));
      return;
    }
    const audio = new Audio();
    live.current = { socket: null, audio };
    setState("connecting");
    try {
      const { wsUrl } = await kitApi.listenToLiveCall(callId);
      if (live.current?.audio !== audio) return; // stopped while asking
      const socket = new WebSocket(wsUrl);
      socket.binaryType = "arraybuffer";
      live.current.socket = socket;
      const player = new MonitorPlayer(audio);
      socket.onopen = () => setState("listening");
      socket.onmessage = (m) => {
        if (m.data instanceof ArrayBuffer) player.push(new Uint8Array(m.data));
      };
      socket.onclose = (e) => {
        close();
        setState(e.code === CALL_ENDED ? "ended" : "stopped");
      };
    } catch (err) {
      close();
      setState("failed");
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }

  function stop() {
    close();
    setState("idle");
  }

  const on = state === "connecting" || state === "listening";
  const said = stateText[state];

  return (
    <span className="listen-in">
      {on ? (
        <button type="button" className="secondary" onClick={stop}>
          {t("listenStop")}
        </button>
      ) : (
        <button type="button" className="secondary" onClick={() => void start()}>
          {t("listenStart")}
        </button>
      )}
      {said && (
        <small role="status" className="muted">
          {" "}
          {t(said)}
        </small>
      )}
      {failure && (
        <small role="alert" className="error">
          {" "}
          {failure}
        </small>
      )}
    </span>
  );
}
