/**
 * Admin → Starter agents → publishing one (contract/kit-api.yaml previewStarterAgent, publishStarterAgent): pick an
 * account the staff member owns and one of its agents (`sources` of listStarterAgents; with none, how to get one), then
 * Preview reads what a starter made from it would carry — every piece of its free text in full, grouped by where it is
 * (description, instructions, a line by its setting key, analysis, a step's name / instructions / line, an exit), kept
 * as written with its line breaks — and what it leaves out, one line per kind. A starter goes to every account, so
 * Publish stays off until the staff member ticks that they read every text; a new preview, or another agent, unticks it,
 * and the pickers are off while a request runs, so what is shown is always the agent picked.
 * The POST names the agent previewed, with `reviewed: true`; a 422's reasons show by their fields, any other refusal
 * (404: not an agent of an account they own) as an alert.
 *
 * Used by StarterAgentsPage.tsx, which reads the list again once one is published. Not here: the starters list, renaming
 * and offering (StarterAgentsPage.tsx); what the backend carries and leaves out (App\Kit\Agents\StarterAgents).
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type StarterAgent, type StarterAgentPreview, type StarterLeftOutKind, type StarterSource, type StarterTextWhere } from "../api";
import { t, type TextKey } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** Where a text is, in the order the preview shows them, and the heading of each. */
const WHERE: Record<StarterTextWhere, TextKey> = {
  description: "starterWhereDescription",
  prompt: "starterWherePrompt",
  setting: "starterWhereSetting",
  analysis: "starterWhereAnalysis",
  step: "starterWhereStep",
  exit: "starterWhereExit",
};

/** The words for a step's or an exit's part; a setting's or analysis field's key is shown as it is. */
const PART: Record<string, TextKey> = {
  name: "starterPartName",
  instructions: "starterPartInstructions",
  line: "starterPartLine",
  label: "starterPartLabel",
  when: "starterPartWhen",
};

/** What each kind left out is called; `ownVoice` and `transfer` are a sentence of their own. */
const LEFT: Record<StarterLeftOutKind, TextKey> = {
  ownVoice: "starterLeftOwnVoice",
  tools: "starterLeftTools",
  lookups: "starterLeftLookups",
  handoff: "starterLeftHandoff",
  transfer: "starterLeftTransfer",
  emailAfterCall: "starterLeftEmailAfterCall",
  knowledge: "starterLeftKnowledge",
  webhooks: "starterLeftWebhooks",
  steps: "starterLeftSteps",
};

/** A map's own entry only (a key the backend adds later, or one like `constructor`, finds nothing). */
function own(map: Record<string, TextKey>, key: string): TextKey | undefined {
  return Object.hasOwn(map, key) ? map[key] : undefined;
}

/** The fields of the publish request this form shows a reason by. */
const SHOWN = ["agentId", "name", "description", "reviewed"];

function leftLine(item: StarterAgentPreview["leftOut"][number]): string {
  const words = own(LEFT, item.kind);
  const what = words ? t(words) : item.kind;
  if (item.kind === "ownVoice" || item.kind === "transfer") return what;
  const count = String(item.count);
  return item.names.length > 0 ? t("starterLeftNamed", { what, names: item.names.join(", "), count }) : t("starterLeftCounted", { what, count });
}

export function StarterPublish({ sources, onPublished }: { sources: StarterSource[]; onPublished: () => void }) {
  const [accountId, setAccountId] = useState(sources[0]?.id ?? "");
  const [agentId, setAgentId] = useState(sources[0]?.agents[0]?.id ?? "");
  const [preview, setPreview] = useState<StarterAgentPreview | null>(null);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const [reviewed, setReviewed] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [previewFailure, setPreviewFailure] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [published, setPublished] = useState<StarterAgent | null>(null);

  if (sources.length === 0) {
    return (
      <section aria-labelledby="starter-publish-title">
        <h2 id="starter-publish-title">{t("starterPublishTitle")}</h2>
        <p className="muted">{t("starterNoSources")}</p>
      </section>
    );
  }

  const account = sources.find((s) => s.id === accountId) ?? sources[0];
  const agentChosen = account.agents.some((a) => a.id === agentId);

  /** Another account or agent: what was read and ticked was for the one before. */
  function choose(nextAccount: string, nextAgent: string) {
    setAccountId(nextAccount);
    setAgentId(nextAgent);
    setPreview(null);
    setReviewed(false);
    setErrors({});
    setPreviewFailure(null);
    setFailure(null);
  }

  async function read() {
    setBusy(true);
    setPreviewFailure(null);
    setFailure(null);
    setErrors({});
    setPublished(null);
    try {
      const p = await kitApi.previewStarterAgent(agentId);
      setPreview(p);
      setName(p.agent.name);
      setDescription("");
      setReviewed(false);
    } catch (err) {
      setPreview(null);
      setPreviewFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  async function publish(e: FormEvent) {
    e.preventDefault();
    if (!preview || !reviewed) return;
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const made = await kitApi.publishStarterAgent({ agentId: preview.agent.id, name: name.trim(), description: description.trim() || null, reviewed: true });
      setPublished(made);
      setPreview(null);
      setReviewed(false);
      onPublished();
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        setErrors(err.fields);
        // A reason for a field this form has no input for is not lost: the message says it.
        if (Object.keys(err.fields).some((k) => !SHOWN.includes(k))) setFailure(err.message);
      } else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const error = (key: string) => errors[key] && <small id={`starter-${key}-error`} className="error">{errors[key].join(" ")}</small>;
  const invalid = (key: string) => ({
    "aria-invalid": errors[key] ? true : undefined,
    "aria-describedby": errors[key] ? `starter-${key}-error` : undefined,
  });

  return (
    <section aria-labelledby="starter-publish-title">
      <h2 id="starter-publish-title">{t("starterPublishTitle")}</h2>
      <div className="field">
        <label htmlFor="starter-account">{t("starterAccount")}</label>
        <select id="starter-account" value={account.id} disabled={busy} onChange={(e) => {
          const next = sources.find((s) => s.id === e.target.value);
          choose(e.target.value, next?.agents[0]?.id ?? "");
        }}>
          {sources.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      </div>
      {account.agents.length === 0 ? (
        <p className="muted">{t("starterNoAgents")}</p>
      ) : (
        <div className="field">
          <label htmlFor="starter-agentId">{t("starterAgent")}</label>
          <select id="starter-agentId" value={agentChosen ? agentId : ""} disabled={busy} {...invalid("agentId")} onChange={(e) => choose(account.id, e.target.value)}>
            {!agentChosen && <option value="" disabled>{t("chooseOne")}</option>}
            {account.agents.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
          {error("agentId")}
        </div>
      )}
      <div className="actions">
        <button type="button" className="secondary" disabled={busy || !agentChosen} onClick={() => void read()}>
          {busy && !preview ? t("starterPreviewing") : t("starterPreview")}
        </button>
      </div>
      {previewFailure && <p role="alert" className="error">{previewFailure}</p>}
      {published && <p role="status">{t("starterPublished", { name: published.name })}</p>}
      {preview && <StarterPreview preview={preview} />}
      {preview && (
        <form className="agent-form" onSubmit={publish} noValidate aria-label={t("starterPublishTitle")}>
          <div className="field">
            <label htmlFor="starter-name">{t("starterName")}</label>
            <input id="starter-name" value={name} {...invalid("name")} onChange={(e) => setName(e.target.value)} />
            {error("name")}
          </div>
          <div className="field">
            <label htmlFor="starter-description">{t("starterDescription")}</label>
            <small className="muted">{t("starterDescriptionHelp")}</small>
            <textarea id="starter-description" rows={3} value={description} {...invalid("description")} onChange={(e) => setDescription(e.target.value)} />
            {error("description")}
          </div>
          <label className="checkbox">
            <input type="checkbox" checked={reviewed} {...invalid("reviewed")} onChange={(e) => setReviewed(e.target.checked)} />
            {t("starterReviewed")}
          </label>
          {error("reviewed")}
          {failure && <p role="alert" className="error">{failure}</p>}
          <button type="submit" disabled={busy || !reviewed}>{busy ? t("starterPublishing") : t("starterPublish")}</button>
        </form>
      )}
    </section>
  );
}

/** Every text in full, grouped by where it is, then what is left out. A `where` the console does not know yet still shows, under its own name. */
function StarterPreview({ preview }: { preview: StarterAgentPreview }) {
  const known = Object.keys(WHERE) as StarterTextWhere[];
  const wheres = [...known, ...new Set(preview.texts.map((x) => x.where).filter((w) => !known.includes(w)))];
  const groups = wheres.map((where) => ({ where, texts: preview.texts.filter((x) => x.where === where) })).filter((g) => g.texts.length > 0);
  const heading = (where: string) => {
    const words = own(WHERE, where);
    return words ? t(words) : where;
  };
  const part = (where: string, key: string | null) => {
    if (key === null) return null;
    const words = (where === "step" || where === "exit") && own(PART, key);
    return words ? t(words) : key;
  };

  return (
    <>
      <h3>{t("starterPreviewOf", { agent: preview.agent.name, account: preview.agent.account })}</h3>
      <section aria-labelledby="starter-texts-title">
        <h4 id="starter-texts-title">{t("starterTextsTitle")}</h4>
        <p className="muted">{t("starterTextsHelp")}</p>
        {groups.length === 0 && <p className="muted">{t("starterNoTexts")}</p>}
        {groups.map((g) => (
          <section key={g.where} className="starter-texts" aria-labelledby={`starter-where-${g.where}`}>
            <h5 id={`starter-where-${g.where}`}>{heading(g.where)}</h5>
            {g.texts.map((x, i) => (
              <div key={i} className="starter-text">
                {(x.key !== null || x.of !== null) && <small className="muted">{[x.of, x.key === null ? null : part(g.where, x.key)].filter((w) => w !== null).join(" · ")}</small>}
                <pre>{x.text}</pre>
              </div>
            ))}
          </section>
        ))}
      </section>
      <p>{t("starterPreviewSteps", { count: String(preview.steps) })}</p>
      <section aria-labelledby="starter-left-title">
        <h4 id="starter-left-title">{t("starterLeftOut")}</h4>
        {preview.leftOut.length === 0 ? (
          <p className="muted">{t("starterLeftNone")}</p>
        ) : (
          <ul aria-label={t("starterLeftOut")}>
            {preview.leftOut.map((x) => <li key={x.kind}>{leftLine(x)}</li>)}
          </ul>
        )}
      </section>
    </>
  );
}
