/**
 * A button that asks the backend for a short audio clip (a voice or an agent heard; kitApi.previewVoice /
 * previewAgentVoice) and plays it in an audio element with controls, so the person can play it again. A refusal shows
 * the backend's words. The clip lives only in the page (an object URL, let go when replaced or gone).
 *
 * Used by: voices/VoicesPage.tsx, agents/StackForm.tsx.
 */
import { useEffect, useRef, useState } from "react";
import { KitApiError } from "../api";
import { t } from "../text";

export function ClipButton({ label, load, className = "secondary" }: { label: string; load: () => Promise<Blob>; className?: string }) {
  const [url, setUrl] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const made = useRef<string | null>(null);

  useEffect(() => () => {
    if (made.current) URL.revokeObjectURL(made.current);
  }, []);

  async function play() {
    setBusy(true);
    setError(null);
    try {
      const next = URL.createObjectURL(await load());
      if (made.current) URL.revokeObjectURL(made.current);
      made.current = next;
      setUrl(next);
    } catch (err) {
      setError(err instanceof KitApiError ? Object.values(err.fields).flat().join(" ") || err.message : String(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <span className="clip">
      <button type="button" className={className} disabled={busy} onClick={play}>
        {busy ? t("loading") : label}
      </button>
      {url && <audio controls autoPlay src={url} aria-label={label} />}
      {error && <small role="alert" className="error">{error}</small>}
    </span>
  );
}
