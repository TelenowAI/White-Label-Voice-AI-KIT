/**
 * Telling the agent something during one live call (contract/kit-api.yaml tellAgent): a few words the agent sees from its next reply, as background,
 * never as the caller's words; asked to say it now, on a phone call the agent says it in its own words as soon as nobody is speaking (in a chat it waits
 * for its next reply). Once told, the box is cleared, the page is handed the note to list (`onTold`), and the backend's word on how it reaches the agent
 * is shown. The backend's reason for refusing the words (empty, or more than the agent can take in) is shown by the box; any other refusal as an alert.
 *
 * Not here: the list of what the agent was told (CallPage.tsx). Used by a live call's page (CallPage.tsx), only where the backend says the member may.
 */
import { useId, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type AgentNote } from "../api";
import { t, type TextKey } from "../text";

const deliveryText: Record<AgentNote["delivery"], TextKey> = {
  nextReply: "tellAgentNextReply",
  sayingNow: "tellAgentSayingNow",
  whenBack: "tellAgentWhenBack",
};

export function TellAgent({ callId, onTold }: { callId: string; onTold: (note: AgentNote) => void }) {
  const [text, setText] = useState("");
  const [sayNow, setSayNow] = useState(false);
  const [sending, setSending] = useState(false);
  const [told, setTold] = useState<AgentNote["delivery"] | null>(null);
  /** The backend's reasons for refusing the words. */
  const [refused, setRefused] = useState<string[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const ids = useId();

  async function send(e: FormEvent) {
    e.preventDefault();
    setSending(true);
    setTold(null);
    setRefused([]);
    setFailure(null);
    try {
      const { note } = await kitApi.tellAgent(callId, { text, sayNow });
      setText("");
      setSayNow(false);
      setTold(note.delivery);
      onTold(note);
    } catch (err) {
      if (err instanceof KitApiError && err.fields.text?.length) setRefused(err.fields.text);
      else setFailure(err instanceof Error ? err.message : String(err));
    } finally {
      setSending(false);
    }
  }

  const delivery = told ? deliveryText[told] : undefined;

  return (
    <section aria-labelledby={`${ids}-title`} className="tell-agent">
      <h2 id={`${ids}-title`}>{t("tellAgentTitle")}</h2>
      <form onSubmit={(e) => void send(e)}>
        <label className="field">
          <span>{t("tellAgentLabel")}</span>
          <textarea
            value={text}
            rows={3}
            onChange={(e) => setText(e.target.value)}
            aria-invalid={refused.length > 0}
            aria-describedby={refused.length > 0 ? `${ids}-error ${ids}-help` : `${ids}-help`}
          />
        </label>
        {refused.length > 0 && (
          <p className="error" id={`${ids}-error`}>
            {refused.join(" ")}
          </p>
        )}
        <label className="checkbox">
          <input type="checkbox" checked={sayNow} onChange={(e) => setSayNow(e.target.checked)} />
          <span>{t("tellAgentSayNow")}</span>
        </label>
        <p className="muted" id={`${ids}-help`}>
          {t("tellAgentHelp")}
        </p>
        <button type="submit" disabled={sending || text.trim() === ""}>
          {sending ? t("tellAgentSending") : t("tellAgentSend")}
        </button>
        {told && (
          <small role="status">
            {" "}
            {t("tellAgentTold", { delivery: delivery ? t(delivery) : "" }).trim()}
          </small>
        )}
      </form>
      {failure && (
        <p role="alert" className="error">
          {failure}
        </p>
      )}
    </section>
  );
}
