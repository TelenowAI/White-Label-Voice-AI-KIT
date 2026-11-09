/**
 * Previewing an agent's instructions with sample values (contract/kit-api.yaml checkPrompt, checkNewPrompt with `values`): only
 * when the member asks. A field for each value the agent's words use (from its call form, getAgentCallForm: those every call
 * needs say so), any other name the member adds, and the call it is for (one it answers, one it places, a web chat, or any);
 * then the instructions as that call would get them, and which branch each conditional section kept, counted from 1. Values
 * left empty are not sent; with none at all, `values: {}` still previews.
 *
 * Used by AgentPage.tsx under the instructions (a new agent has no call form: only names the member adds). Not here: the
 * check as it is typed (PromptCheck.tsx); the call form's other uses (CallSomeone.tsx).
 */
import { useId, useState } from "react";
import { kitApi, type PromptChannel, type PromptCheck, type PromptCheckInput } from "../api";
import { t, type TextKey } from "../text";

const CHANNELS: { value: PromptChannel; text: TextKey }[] = [
  { value: "phone_inbound", text: "promptPreviewChannel_phone_inbound" },
  { value: "phone_outbound", text: "promptPreviewChannel_phone_outbound" },
  { value: "chat", text: "promptPreviewChannel_chat" },
];

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** A name as the member may type it, `{plan}` or `plan`: the name alone. */
const bare = (name: string) => name.trim().replace(/^\{\s*/, "").replace(/\s*\}$/, "");

/**
 * `agentId`: the agent whose call form names its values (none for a new agent). `run`: the check to send. `systemPrompt`:
 * the instructions as they stand in the form.
 */
export function PromptPreview({ agentId, run, systemPrompt }: {
  agentId?: string;
  run: (input: PromptCheckInput) => Promise<PromptCheck>;
  systemPrompt: string;
}) {
  const ids = useId();
  const [open, setOpen] = useState(false);
  const [names, setNames] = useState<{ name: string; required: boolean }[]>([]);
  const [values, setValues] = useState<Record<string, string>>({});
  const [adding, setAdding] = useState("");
  const [channel, setChannel] = useState<PromptChannel | "">("");
  const [answer, setAnswer] = useState<PromptCheck | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function start() {
    setOpen(true);
    setFailure(null);
    if (!agentId) return;
    try {
      const form = await kitApi.agentCallForm(agentId);
      setNames((had) => [...form.variables, ...had.filter((h) => !form.variables.some((v) => v.name === h.name))]);
    } catch (err) {
      setFailure(message(err));
    }
  }

  function add() {
    const name = bare(adding);
    if (name === "") return;
    if (!names.some((n) => n.name === name)) setNames([...names, { name, required: false }]);
    setAdding("");
  }

  async function show() {
    setBusy(true);
    setFailure(null);
    try {
      const given = Object.fromEntries(names.map((n) => [n.name, values[n.name] ?? ""]).filter(([, v]) => v.trim() !== ""));
      setAnswer(await run({ systemPrompt: systemPrompt || null, values: given, ...(channel ? { channel } : {}) }));
    } catch (err) {
      setAnswer(null);
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  if (!open) {
    return (
      <p>
        <button type="button" className="secondary" onClick={() => void start()}>{t("promptPreviewOpen")}</button>
      </p>
    );
  }

  return (
    <section className="prompt-preview" aria-labelledby={`${ids}-title`}>
      <h3 id={`${ids}-title`}>{t("promptPreviewTitle")}</h3>
      <p className="muted">{t("promptPreviewHelp")}</p>
      {names.length === 0 && <p className="muted">{t("promptPreviewNoValues")}</p>}
      {names.map((n, i) => (
        <div className="field" key={n.name}>
          <label htmlFor={`${ids}-value-${i}`}>{n.name}</label>
          {n.required && <small className="muted">{t("promptPreviewNeeded")}</small>}
          <input id={`${ids}-value-${i}`} value={values[n.name] ?? ""} onChange={(e) => setValues({ ...values, [n.name]: e.target.value })}
            onKeyDown={(e) => e.key === "Enter" && e.preventDefault()} />
        </div>
      ))}
      <div className="field">
        <label htmlFor={`${ids}-add`}>{t("promptPreviewAddName")}</label>
        <div className="input-row">
          <input id={`${ids}-add`} value={adding} onChange={(e) => setAdding(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault();
                add();
              }
            }} />
          <button type="button" className="secondary" onClick={add}>{t("promptPreviewAdd")}</button>
        </div>
      </div>
      <div className="field">
        <label htmlFor={`${ids}-channel`}>{t("promptPreviewChannel")}</label>
        <select id={`${ids}-channel`} value={channel} onChange={(e) => setChannel(e.target.value as PromptChannel | "")}>
          <option value="">{t("promptPreviewChannelNone")}</option>
          {CHANNELS.map((c) => <option key={c.value} value={c.value}>{t(c.text)}</option>)}
        </select>
      </div>
      <div className="actions">
        <button type="button" disabled={busy || systemPrompt.trim() === ""} onClick={() => void show()}>
          {busy ? t("promptPreviewShowing") : t("promptPreviewShow")}
        </button>
        <button type="button" className="secondary" onClick={() => setOpen(false)}>{t("promptPreviewClose")}</button>
      </div>
      {failure && <p className="muted" data-testid="prompt-preview-failure">{failure}</p>}
      {answer && !answer.preview && <p className="muted">{t("promptPreviewNone")}</p>}
      {answer?.preview && (
        <>
          <h4>{t("promptPreviewText")}</h4>
          <pre data-testid="prompt-preview-text">{answer.preview.text}</pre>
          {answer.preview.sections.length > 0 && (
            <ul className="plain" data-testid="prompt-preview-sections">
              {answer.preview.sections.map((s, i) => (
                <li key={i}>
                  {s.branch === null
                    ? t("promptPreviewNoBranch", { line: String(s.line), branches: String(s.branches) })
                    : t("promptPreviewBranch", { line: String(s.line), branch: String(s.branch), branches: String(s.branches) })}
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </section>
  );
}
