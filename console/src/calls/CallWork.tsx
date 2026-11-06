/**
 * What the team writes on one call (contract/kit-api.yaml getCallWork, setCallWrapup, addCallNote, addCallFollowUp, setFollowUp): a wrap-up in a few words
 * (the account's recent ones offered as it is typed), notes (kept, never changed), and follow-ups, something to do by when, each on its writer's own list
 * (FollowUpsPage.tsx). Any member may write, and mark a follow-up done. Each save answers the call's whole work, shown as it comes; a field the backend
 * refuses says why beside it.
 *
 * Used by a call's page (CallPage.tsx) and, for the call just placed, the Phone page (phone/PhonePage.tsx).
 */
import { useEffect, useId, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type CallWork as Work } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** A local date and time in the shape a datetime-local field takes. */
function localInput(date: Date): string {
  const pad = (n: number) => String(n).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Tomorrow at this hour: what a new follow-up is due by unless changed. */
function tomorrow(): string {
  const d = new Date();
  d.setDate(d.getDate() + 1);
  d.setSeconds(0, 0);
  return localInput(d);
}

export function CallWork({ callId }: { callId: string }) {
  const [work, setWork] = useState<Work | null>(null);
  const [wrapup, setWrapup] = useState("");
  const [note, setNote] = useState("");
  const [followUp, setFollowUp] = useState("");
  const [dueAt, setDueAt] = useState(tomorrow);
  /** The fields the backend refused, and which form sent them: a note and a follow-up both have a `body`. */
  const [errors, setErrors] = useState<{ form: string; fields: Record<string, string[]> }>({ form: "", fields: {} });
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const ids = useId();

  function take(w: Work) {
    setWork(w);
    setWrapup(w.wrapup?.label ?? "");
  }

  useEffect(() => {
    setWork(null);
    kitApi.callWork(callId).then(take, (err: unknown) => setFailure(message(err)));
  }, [callId]);

  async function act(which: string, run: () => Promise<Work>, after?: () => void) {
    setBusy(which);
    setErrors({ form: "", fields: {} });
    setFailure(null);
    try {
      take(await run());
      after?.();
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors({ form: which, fields: err.fields });
      else setFailure(message(err));
    } finally {
      setBusy(null);
    }
  }

  const saveWrapup = (e: FormEvent) => {
    e.preventDefault();
    void act("wrapup", () => kitApi.setCallWrapup(callId, wrapup.trim() || null));
  };
  const addNote = (e: FormEvent) => {
    e.preventDefault();
    void act("note", () => kitApi.addCallNote(callId, note), () => setNote(""));
  };
  const addFollowUp = (e: FormEvent) => {
    e.preventDefault();
    const due = new Date(dueAt);
    if (Number.isNaN(due.getTime())) {
      setErrors({ form: "followUp", fields: { dueAt: [t("followUpNeedsDue")] } });
      return;
    }
    void act("followUp", () => kitApi.addCallFollowUp(callId, followUp, due.toISOString()), () => setFollowUp(""));
  };
  async function toggle(id: string, done: boolean) {
    setBusy(`done-${id}`);
    setFailure(null);
    try {
      const saved = await kitApi.setFollowUp(id, done);
      setWork((w) => (w ? { ...w, followUps: w.followUps.map((f) => (f.id === id ? { ...saved, call: f.call } : f)) } : w));
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(null);
    }
  }

  const refused = (form: string, name: string) => (errors.form === form ? errors.fields[name] : undefined);
  const fieldError = (form: string, name: string) => {
    const reasons = refused(form, name);
    return reasons?.length ? <span className="error" id={`${ids}-${form}-${name}-error`}>{reasons.join(" ")}</span> : null;
  };
  const described = (form: string, name: string) => (refused(form, name) ? `${ids}-${form}-${name}-error` : undefined);

  return (
    <section aria-labelledby={`${ids}-title`} className="call-work">
      <h2 id={`${ids}-title`}>{t("callWorkTitle")}</h2>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!work && !failure && <p className="muted">{t("loading")}</p>}
      {work && (
        <>
          <form onSubmit={saveWrapup} className="inline-form">
            <label className="field">
              <span>{t("wrapupLabel")}</span>
              <input
                value={wrapup}
                maxLength={work.limits.wrapupMaxLength}
                list={`${ids}-suggestions`}
                onChange={(e) => setWrapup(e.target.value)}
                aria-invalid={!!refused("wrapup", "wrapup")}
                aria-describedby={described("wrapup", "wrapup")}
              />
              <datalist id={`${ids}-suggestions`}>
                {work.suggestions.map((s) => (
                  <option key={s} value={s} />
                ))}
              </datalist>
            </label>
            <button type="submit" disabled={busy !== null}>{busy === "wrapup" ? t("saving") : t("saveWrapup")}</button>
            {fieldError("wrapup", "wrapup")}
          </form>
          {work.wrapup && (
            <p className="muted">
              {t("wrapupBy", { name: work.wrapup.by ?? t("formerMember"), when: work.wrapup.at ? new Date(work.wrapup.at).toLocaleString() : "" })}
            </p>
          )}

          <h3>{t("notesTitle")}</h3>
          {work.notes.length === 0 ? (
            <p className="muted">{t("notesEmpty")}</p>
          ) : (
            <ol className="notes">
              {work.notes.map((n) => (
                <li key={n.id}>
                  <p className="note-body">{n.body}</p>
                  <small className="muted">
                    {n.mine ? t("callYou") : (n.by ?? t("formerMember"))} · {new Date(n.at).toLocaleString()}
                  </small>
                </li>
              ))}
            </ol>
          )}
          <form onSubmit={addNote}>
            <label className="field">
              <span>{t("addNoteLabel")}</span>
              <textarea
                value={note}
                maxLength={work.limits.noteMaxLength}
                rows={3}
                onChange={(e) => setNote(e.target.value)}
                aria-invalid={!!refused("note", "body")}
                aria-describedby={described("note", "body")}
              />
            </label>
            {fieldError("note", "body")}
            <button type="submit" disabled={busy !== null || note.trim() === ""}>{busy === "note" ? t("saving") : t("addNote")}</button>
          </form>

          <h3>{t("followUpsTitle")}</h3>
          {work.followUps.length === 0 ? (
            <p className="muted">{t("followUpsEmpty")}</p>
          ) : (
            <ul className="follow-ups">
              {work.followUps.map((f) => (
                <li key={f.id} className={f.doneAt ? "done" : undefined}>
                  <label className="checkbox">
                    <input type="checkbox" checked={f.doneAt !== null} disabled={busy !== null} onChange={(e) => void toggle(f.id, e.target.checked)} />
                    <span>{f.body}</span>
                  </label>
                  <small className="muted">
                    {t("followUpDue", { when: new Date(f.dueAt).toLocaleString() })} · {f.mine ? t("callYou") : (f.by ?? t("formerMember"))}
                    {f.doneAt && ` · ${t("followUpDoneBy", { name: f.doneBy ?? t("formerMember") })}`}
                  </small>
                </li>
              ))}
            </ul>
          )}
          <form onSubmit={addFollowUp}>
            <label className="field">
              <span>{t("addFollowUpLabel")}</span>
              <input
                value={followUp}
                maxLength={work.limits.followUpMaxLength}
                onChange={(e) => setFollowUp(e.target.value)}
                aria-invalid={!!refused("followUp", "body")}
                aria-describedby={described("followUp", "body")}
              />
            </label>
            {fieldError("followUp", "body")}
            <label className="field">
              <span>{t("followUpDueLabel")}</span>
              <input
                type="datetime-local"
                value={dueAt}
                onChange={(e) => setDueAt(e.target.value)}
                aria-invalid={!!refused("followUp", "dueAt")}
                aria-describedby={described("followUp", "dueAt")}
              />
            </label>
            {fieldError("followUp", "dueAt")}
            <button type="submit" disabled={busy !== null || followUp.trim() === ""}>{busy === "followUp" ? t("saving") : t("addFollowUp")}</button>
          </form>
        </>
      )}
    </section>
  );
}
