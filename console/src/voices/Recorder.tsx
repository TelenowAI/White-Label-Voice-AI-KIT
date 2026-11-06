/**
 * Recording a person in the browser for one of the account's own voices: the microphone (getUserMedia) into a MediaRecorder, the seconds counted as it goes, then a chance to hear the
 * take before it is used, or record again. The recording stays in the page until it is used; the microphone is let go as soon as recording stops. A take shorter than the voice
 * platform takes is not offered for use.
 *
 * Used by voices/OwnVoices.tsx. Not here: sending it (kitApi.uploadOwnVoiceSample), uploading a file instead (OwnVoices.tsx).
 */
import { useEffect, useRef, useState } from "react";
import { t } from "../text";

/** How often the seconds shown move on while recording. */
const TICK_MS = 250;

/** The extension the voice platform reads a recording's type by. */
function extension(type: string): string {
  if (type.includes("webm")) return "webm";
  if (type.includes("ogg")) return "ogg";
  if (type.includes("mp4") || type.includes("aac")) return "m4a";
  if (type.includes("wav")) return "wav";
  return "webm";
}

export interface Take {
  blob: Blob;
  filename: string;
  seconds: number;
}

export function Recorder({ minSeconds, busy, onUse }: { minSeconds: number; busy: boolean; onUse: (take: Take) => void }) {
  const [state, setState] = useState<"idle" | "recording" | "done">("idle");
  const [seconds, setSeconds] = useState(0);
  const [take, setTake] = useState<Take | null>(null);
  const [url, setUrl] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const recorder = useRef<MediaRecorder | null>(null);
  const stream = useRef<MediaStream | null>(null);
  const started = useRef(0);
  const timer = useRef<ReturnType<typeof setInterval> | null>(null);
  const supported = typeof window !== "undefined" && typeof window.MediaRecorder !== "undefined" && !!navigator.mediaDevices?.getUserMedia;

  const letGo = () => {
    if (timer.current) clearInterval(timer.current);
    timer.current = null;
    stream.current?.getTracks().forEach((track) => track.stop());
    stream.current = null;
  };

  useEffect(() => () => {
    letGo();
    if (recorder.current?.state === "recording") recorder.current.stop();
  }, []);

  useEffect(() => () => {
    if (url) URL.revokeObjectURL(url);
  }, [url]);

  async function start() {
    setFailure(null);
    setTake(null);
    setUrl(null);
    try {
      stream.current = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      setFailure(t("recordDenied"));
      return;
    }
    const type = ["audio/webm", "audio/ogg", "audio/mp4"].find((m) => typeof MediaRecorder.isTypeSupported !== "function" || MediaRecorder.isTypeSupported(m));
    const r = new MediaRecorder(stream.current, type ? { mimeType: type } : undefined);
    const chunks: Blob[] = [];
    r.ondataavailable = (e) => {
      if (e.data.size > 0) chunks.push(e.data);
    };
    r.onstop = () => {
      const long = (Date.now() - started.current) / 1000;
      letGo();
      const blob = new Blob(chunks, { type: r.mimeType || type || "audio/webm" });
      setTake({ blob, filename: `recording.${extension(blob.type)}`, seconds: long });
      setUrl(typeof URL.createObjectURL === "function" ? URL.createObjectURL(blob) : null);
      setSeconds(long);
      setState("done");
    };
    recorder.current = r;
    started.current = Date.now();
    setSeconds(0);
    r.start();
    setState("recording");
    timer.current = setInterval(() => setSeconds((Date.now() - started.current) / 1000), TICK_MS);
  }

  function stop() {
    if (recorder.current?.state === "recording") recorder.current.stop();
  }

  if (!supported) return <p className="note">{t("recordNoMicrophone")}</p>;
  const short = take !== null && take.seconds < minSeconds;

  return (
    <div className="recorder">
      {state === "recording" ? (
        <>
          <p role="status">{t("recordingNow", { seconds: String(Math.floor(seconds)) })}</p>
          <button type="button" onClick={stop}>{t("recordStop")}</button>
        </>
      ) : (
        <button type="button" className={take ? "secondary" : undefined} onClick={() => void start()} disabled={busy}>
          {take ? t("recordAgain") : t("recordStart")}
        </button>
      )}
      {state === "done" && take && (
        <>
          {url && <audio controls src={url} aria-label={t("ownVoiceRecording")} />}
          {short ? (
            <p role="alert" className="error">{t("recordTooShort", { seconds: take.seconds.toFixed(1), min: String(minSeconds) })}</p>
          ) : (
            <button type="button" disabled={busy} onClick={() => onUse(take)}>{busy ? t("ownVoiceUploading") : t("recordUse")}</button>
          )}
        </>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
    </div>
  );
}
