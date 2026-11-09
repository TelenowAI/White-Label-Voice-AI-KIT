/**
 * Console → one agent → its chat box on the customer's own website (contract/kit-api.yaml getAgentWebChat,
 * updateAgentWebChat): whether it is on, the websites it may show on (one per line), its title and greeting, and the code
 * to put on each page, with a Copy button that falls back to selecting the code. It loads and saves on its own, so it sits
 * apart from the agent's settings form. While the operator does not offer web chat it says so and offers no form; while
 * the plan has no price for a chat it says so (turning it on is then refused, and the refusal shown). A refused field's
 * reasons show at that field, a website's (`websites.N`) with its address.
 *
 * Not here: the chat box itself (the script on the customer's website), the operator's switch (admin/Catalog.tsx), a
 * conversation in the call history (calls/CallPage.tsx), what the backend does (laravel/).
 * Used by: AgentPage.tsx.
 */
import { useEffect, useRef, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Agent, type WebChat } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** The fields this form shows a refusal at; `websites.N` belongs to the websites. */
const fieldOf = (key: string) => (key.startsWith("websites.") ? "websites" : key);
const shownFields = ["enabled", "websites", "title", "greeting"];

export function AgentWebChat({ agent }: { agent: Agent }) {
  const [chat, setChat] = useState<WebChat | null>(null);
  const [enabled, setEnabled] = useState(false);
  const [websites, setWebsites] = useState("");
  const [title, setTitle] = useState("");
  const [greeting, setGreeting] = useState("");
  const [sent, setSent] = useState<string[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState<"copied" | "selected" | null>(null);
  const code = useRef<HTMLTextAreaElement>(null);

  function take(w: WebChat) {
    setChat(w);
    setEnabled(w.enabled);
    setWebsites(w.websites.join("\n"));
    setTitle(w.title ?? "");
    setGreeting(w.greeting ?? "");
  }

  useEffect(() => {
    kitApi.agentWebChat(agent.id).then(take, (err: unknown) => setFailure(message(err)));
  }, [agent.id]);

  async function save(e: FormEvent) {
    e.preventDefault();
    const list = websites.split("\n").map((w) => w.trim()).filter((w) => w !== "");
    setBusy(true);
    setErrors({});
    setFailure(null);
    setNotice(null);
    setSent(list);
    try {
      take(await kitApi.updateAgentWebChat(agent.id, { enabled, websites: list, title: title.trim() || null, greeting: greeting.trim() || null }));
      setNotice(t("webChatSaved"));
    } catch (err) {
      const fields = err instanceof KitApiError ? Object.keys(err.fields) : [];
      if (fields.length > 0) setErrors((err as KitApiError).fields);
      if (!fields.some((k) => shownFields.includes(fieldOf(k)))) setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  async function copy(snippet: string) {
    try {
      await navigator.clipboard.writeText(snippet);
      setCopied("copied");
    } catch {
      code.current?.focus();
      code.current?.select();
      setCopied("selected");
    }
  }

  /** A field's refused reasons; a website's own named by its address, as it was sent. */
  const reasons = (field: string) => Object.entries(errors).filter(([k]) => fieldOf(k) === field).flatMap(([k, list]) => {
    const website = k.startsWith("websites.") ? sent[Number(k.slice("websites.".length))] : undefined;
    return website === undefined ? list : list.map((reason) => t("webChatWebsiteRefused", { website, reason }));
  });
  const field = (key: string) => ({
    id: `webchat-${key}`,
    "aria-invalid": reasons(key).length > 0 ? true : undefined,
    "aria-describedby": reasons(key).length > 0 ? `webchat-${key}-error` : undefined,
  });
  const error = (key: string) => reasons(key).length > 0 && <small id={`webchat-${key}-error`} className="error">{reasons(key).join(" ")}</small>;

  return (
    <section aria-labelledby="agent-web-chat">
      <h2 id="agent-web-chat">{t("webChat")}</h2>
      {chat && !chat.allowed && <p className="muted">{t("webChatNotAllowed")}</p>}
      {chat?.allowed && (
        <>
          <p className="muted">{t("webChatHelp")}</p>
          {!chat.priced && <p className="note">{t("webChatNotPriced")}</p>}
          <form onSubmit={save} noValidate aria-label={t("webChat")}>
            <label className="checkbox">
              <input type="checkbox" {...field("enabled")} checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />
              {t("webChatEnabled")}
            </label>
            {error("enabled")}
            <div className="field">
              <label htmlFor="webchat-websites">{t("webChatWebsites")}</label>
              <small className="muted">{t("webChatWebsitesHelp")}</small>
              <textarea {...field("websites")} rows={4} spellCheck={false} value={websites} onChange={(e) => setWebsites(e.target.value)} />
              {error("websites")}
            </div>
            <div className="field">
              <label htmlFor="webchat-title">{t("webChatTitle")}</label>
              <input {...field("title")} value={title} placeholder={t("webChatTitlePlaceholder", { name: agent.name })} onChange={(e) => setTitle(e.target.value)} />
              {error("title")}
            </div>
            <div className="field">
              <label htmlFor="webchat-greeting">{t("webChatGreeting")}</label>
              <small className="muted">{t("webChatGreetingHelp")}</small>
              <textarea {...field("greeting")} rows={3} value={greeting} onChange={(e) => setGreeting(e.target.value)} />
              {error("greeting")}
            </div>
            <button type="submit" disabled={busy}>{busy ? t("saving") : t("webChatSave")}</button>
          </form>
        </>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
      {notice && <p role="status">{notice}</p>}
      {chat?.allowed && chat.snippet !== null && (
        <div className="field">
          <label htmlFor="webchat-snippet">{t("webChatSnippet")}</label>
          <small className="muted">{t("webChatSnippetHelp")}</small>
          <textarea id="webchat-snippet" ref={code} className="code" readOnly rows={4} spellCheck={false} value={chat.snippet} onFocus={(e) => e.target.select()} />
          <p>
            <button type="button" className="secondary" onClick={() => void copy(chat.snippet ?? "")}>{copied === "copied" ? t("copied") : t("webChatCopy")}</button>
            {copied === "selected" && <small className="muted"> {t("webChatCopySelected")}</small>}
          </p>
        </div>
      )}
    </section>
  );
}
