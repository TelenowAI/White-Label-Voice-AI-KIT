/**
 * Admin → Starter agents (`/admin/starters`; contract/kit-api.yaml listStarterAgents, updateStarterAgent): the
 * ready-made agents the operator offers every account. Each starter shows its name, description, how many call steps it
 * carries, the agent and account it was made from (as they were named then), who published it and when, and whether it
 * is offered; it can be renamed or redescribed in place, stopped being offered and offered again (a refusal is an alert
 * on that starter). Below the list, the publish form, given the accounts the staff member owns; publishing reads the
 * list again. The page of the `starters` ability (AdminArea.tsx shows it only then).
 *
 * Not here: publishing a starter after reading what it carries (StarterPublish.tsx); a customer starting an agent from
 * one (agents/AgentFromStarter.tsx); what a starter carries and leaves out (the backend's App\Kit\Agents\StarterAgents).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type StarterAgent, type StarterAgentList } from "../api";
import { t } from "../text";
import { StarterPublish } from "./StarterPublish";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function StarterAgentsPage() {
  const [list, setList] = useState<StarterAgentList | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setList(await kitApi.starterAgents());
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const changed = (s: StarterAgent) => setList((l) => l && { ...l, starters: l.starters.map((x) => (x.id === s.id ? s : x)) });

  return (
    <section aria-labelledby="starters-title">
      <h1 id="starters-title">{t("startersTitle")}</h1>
      <p className="muted">{t("startersHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {list && (
        <>
          {list.starters.length === 0 ? (
            <p className="muted">{t("startersNone")}</p>
          ) : (
            <ul className="senders" aria-label={t("startersTitle")}>
              {list.starters.map((s) => <StarterRow key={s.id} starter={s} onChanged={changed} />)}
            </ul>
          )}
          <StarterPublish sources={list.sources} onPublished={() => void load()} />
        </>
      )}
    </section>
  );
}

function StarterRow({ starter, onChanged }: { starter: StarterAgent; onChanged: (s: StarterAgent) => void }) {
  const [editing, setEditing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  async function offer(offered: boolean) {
    setBusy(true);
    setFailure(null);
    try {
      onChanged(await kitApi.updateStarterAgent(starter.id, { offered }));
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const when = new Date(starter.publishedAt).toLocaleString();
  return (
    <li data-starter={starter.id}>
      <p>
        <strong>{starter.name}</strong> · <span className={starter.offered ? undefined : "muted"}>{starter.offered ? t("starterOffered") : t("starterNotOffered")}</span>
      </p>
      <p className={starter.description ? "note-body" : "muted"}>{starter.description ?? t("starterNoDescription")}</p>
      <p className="muted wrap-anywhere">
        {t("starterSteps", { count: String(starter.steps) })}
        {" · "}
        {t("starterMadeFrom", { agent: starter.from.agent ?? t("starterSomeAgent"), account: starter.from.account ?? t("starterSomeAccount") })}
        {" · "}
        {starter.publishedBy ? t("starterPublishedBy", { who: starter.publishedBy, when }) : t("starterPublishedAt", { when })}
      </p>
      {editing ? (
        <StarterEdit starter={starter} onSaved={(s) => { onChanged(s); setEditing(false); }} onCancel={() => setEditing(false)} />
      ) : (
        <div className="actions">
          <button type="button" className="secondary" onClick={() => { setFailure(null); setEditing(true); }}>{t("starterEdit")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => void offer(!starter.offered)}>
            {starter.offered ? t("starterStopOffering") : t("starterOfferAgain")}
          </button>
        </div>
      )}
      {failure && <p role="alert" className="error">{failure}</p>}
    </li>
  );
}

function StarterEdit({ starter, onSaved, onCancel }: { starter: StarterAgent; onSaved: (s: StarterAgent) => void; onCancel: () => void }) {
  const [name, setName] = useState(starter.name);
  const [description, setDescription] = useState(starter.description ?? "");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      onSaved(await kitApi.updateStarterAgent(starter.id, { name: name.trim(), description: description.trim() || null }));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) {
        setErrors(err.fields);
        // A reason for a field this form has no input for is not lost: the message says it.
        if (Object.keys(err.fields).some((k) => k !== "name" && k !== "description")) setFailure(err.message);
      } else setFailure(message(err));
      setBusy(false);
    }
  }

  const id = (key: string) => `starter-${starter.id}-${key}`;
  const error = (key: string) => errors[key] && <small id={id(`${key}-error`)} className="error">{errors[key].join(" ")}</small>;
  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("starterEditTitle", { name: starter.name })}>
      <div className="field">
        <label htmlFor={id("name")}>{t("starterName")}</label>
        <input id={id("name")} value={name} aria-invalid={errors.name ? true : undefined} onChange={(e) => setName(e.target.value)} />
        {error("name")}
      </div>
      <div className="field">
        <label htmlFor={id("description")}>{t("starterDescription")}</label>
        <small className="muted">{t("starterDescriptionHelp")}</small>
        <textarea id={id("description")} rows={3} value={description} aria-invalid={errors.description ? true : undefined} onChange={(e) => setDescription(e.target.value)} />
        {error("description")}
      </div>
      {failure && <p role="alert" className="error">{failure}</p>}
      <div className="actions">
        <button type="submit" disabled={busy}>{busy ? t("saving") : t("starterSave")}</button>
        <button type="button" className="secondary" disabled={busy} onClick={onCancel}>{t("cancel")}</button>
      </div>
    </form>
  );
}
