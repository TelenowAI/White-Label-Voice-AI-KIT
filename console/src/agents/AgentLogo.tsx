/**
 * An agent's logo, on its page (contract/kit-api.yaml setAgentLogo, removeAgentLogo; the image itself is getAgentLogo, at the
 * agent's `logoUrl`): the logo or that there is none, choosing an image (PNG, JPEG or WebP: sent as it is chosen, as
 * multipart/form-data with the field `file`), and removing it. A refused image says why beside the chooser (422 `errors.file`);
 * anything else is said in this section. Each answers the agent, handed to `onChanged`. The logo is also shown on the agent's
 * chat box (laravel/public/chat/widget.js) and small in the agents list (Agents.tsx).
 *
 * Used by AgentPage.tsx. Not here: checking the image (the backend's App\Kit\Agents\AgentLogos).
 */
import { useId, useRef, useState } from "react";
import { KitApiError, kitApi, type Agent } from "../api";
import { t } from "../text";

/** The image types the backend takes; the chooser offers only these. */
const IMAGE_TYPES = "image/png,image/jpeg,image/webp";

export function AgentLogo({ agent, onChanged }: { agent: Agent; onChanged: (agent: Agent) => void }) {
  const ids = useId();
  const chooser = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState<"upload" | "remove" | null>(null);
  const [refused, setRefused] = useState<string[]>([]);
  const [failure, setFailure] = useState<string | null>(null);

  async function act(kind: "upload" | "remove", run: () => Promise<Agent>) {
    setBusy(kind);
    setRefused([]);
    setFailure(null);
    try {
      onChanged(await run());
    } catch (err) {
      if (err instanceof KitApiError && err.fields.file?.length) setRefused(err.fields.file);
      else setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setBusy(null);
      if (chooser.current) chooser.current.value = "";
    }
  }

  return (
    <section aria-labelledby={`${ids}-title`}>
      <h2 id={`${ids}-title`}>{t("agentLogoTitle")}</h2>
      <p className="muted">{t("agentLogoHelp")}</p>
      {agent.logoUrl
        ? <img className="agent-logo" src={agent.logoUrl} alt={t("agentLogoAlt", { name: agent.name })} />
        : <p className="muted">{t("agentLogoNone")}</p>}
      <div className="field">
        <label htmlFor={`${ids}-file`}>{busy === "upload" ? t("agentLogoUploading") : t("agentLogoChoose")}</label>
        <input ref={chooser} id={`${ids}-file`} type="file" accept={IMAGE_TYPES} disabled={busy !== null}
          aria-invalid={refused.length > 0 ? true : undefined} aria-describedby={refused.length > 0 ? `${ids}-refused` : undefined}
          onChange={(e) => {
            const file = e.target.files?.[0];
            if (file) void act("upload", () => kitApi.setAgentLogo(agent.id, file));
          }} />
        {refused.length > 0 && <small id={`${ids}-refused`} className="error">{refused.join(" ")}</small>}
      </div>
      {agent.logoUrl && (
        <button type="button" className="secondary" disabled={busy !== null} onClick={() => void act("remove", () => kitApi.removeAgentLogo(agent.id))}>
          {busy === "remove" ? t("agentLogoRemoving") : t("agentLogoRemove")}
        </button>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
    </section>
  );
}
