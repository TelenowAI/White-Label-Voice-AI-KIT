/**
 * Admin → Email → Templates (contract/kit-api.yaml listEmailTemplates, saveEmailTemplate, resetEmailTemplate,
 * previewEmailTemplate): every email the platform sends, marked when it has the operator's own words; editing one —
 * its subject, its paragraphs (a blank line between two), its button's words when it has a button, the paragraphs
 * after it — with the placeholders it can use and what each stands for; a preview of the email in the brand before
 * saving (in a sandboxed frame: nothing in it runs); saving, with the backend's words for what is wrong; and going
 * back to the kit's words. Shown on the Email page (EmailPage.tsx).
 *
 * Not here: the senders and the recent emails (EmailPage.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type EmailPreview, type EmailTemplate, type EmailTemplateTexts } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

/** Paragraphs as typed: a blank line between two. */
function paragraphs(text: string): string[] {
  return text.split(/\n\s*\n/).map((p) => p.trim()).filter((p) => p !== "");
}

export function EmailTemplates() {
  const [templates, setTemplates] = useState<EmailTemplate[] | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [editing, setEditing] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setTemplates((await kitApi.emailTemplates()).templates);
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const replace = (changed: EmailTemplate) => setTemplates((list) => list?.map((x) => (x.kind === changed.kind ? changed : x)) ?? null);
  const open = templates?.find((x) => x.kind === editing) ?? null;

  return (
    <section aria-labelledby="templates-title">
      <h2 id="templates-title">{t("templatesTitle")}</h2>
      <p className="muted">{t("templatesHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {templates && (
        <ul className="senders" aria-label={t("templatesTitle")}>
          {templates.map((x) => (
            <li key={x.kind} data-template={x.kind}>
              <div className="actions">
                <span>
                  <strong>{x.name}</strong> · <span className="wrap-anywhere">{x.texts.subject}</span>{" "}
                  {x.edited && <span className="badge">{t("templateEdited")}</span>}
                </span>
                <button type="button" className="secondary" aria-label={t("editTemplateOf", { name: x.name })} onClick={() => setEditing(editing === x.kind ? null : x.kind)}>
                  {t(editing === x.kind ? "close" : "edit")}
                </button>
              </div>
              {open?.kind === x.kind && <Editor key={open.kind} template={open} onChanged={replace} />}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

function Editor({ template, onChanged }: { template: EmailTemplate; onChanged: (x: EmailTemplate) => void }) {
  const [subject, setSubject] = useState(template.texts.subject);
  const [lines, setLines] = useState(template.texts.lines.join("\n\n"));
  const [action, setAction] = useState(template.texts.action ?? "");
  const [after, setAfter] = useState(template.texts.after.join("\n\n"));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [preview, setPreview] = useState<EmailPreview | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  function show(words: EmailTemplateTexts) {
    setSubject(words.subject);
    setLines(words.lines.join("\n\n"));
    setAction(words.action ?? "");
    setAfter(words.after.join("\n\n"));
  }

  const texts = (): EmailTemplateTexts => ({
    subject, lines: paragraphs(lines), action: template.hasButton ? action : null, after: paragraphs(after),
  });

  async function run(act: () => Promise<void>) {
    setBusy(true);
    setErrors({});
    setFailure(null);
    setSaved(false);
    try {
      await act();
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  function save(e: FormEvent) {
    e.preventDefault();
    void run(async () => {
      onChanged(await kitApi.saveEmailTemplate(template.kind, texts()));
      setSaved(true);
    });
  }

  const said = (field: string) => errors[field] && <small className="error">{errors[field].join(" ")}</small>;
  const id = (field: string) => `template-${template.kind}-${field}`;

  return (
    <form className="agent-form" onSubmit={save} noValidate aria-label={t("editTemplateOf", { name: template.name })}>
      <div className="field">
        <label htmlFor={id("subject")}>{t("templateSubject")}</label>
        <input id={id("subject")} value={subject} onChange={(e) => setSubject(e.target.value)} />
        {said("subject")}
      </div>
      <div className="field">
        <label htmlFor={id("lines")}>{t(template.hasButton ? "templateBeforeButton" : "templateText")}</label>
        <textarea id={id("lines")} rows={5} value={lines} onChange={(e) => setLines(e.target.value)} aria-describedby={id("paragraphs")} />
        <small id={id("paragraphs")} className="muted">{t("templateParagraphs")}</small>
        {said("lines")}
      </div>
      {template.hasButton && (
        <div className="field">
          <label htmlFor={id("action")}>{t("templateButton")}</label>
          <input id={id("action")} value={action} onChange={(e) => setAction(e.target.value)} />
          {said("action")}
        </div>
      )}
      <div className="field">
        <label htmlFor={id("after")}>{t(template.hasButton ? "templateAfterButton" : "templateAfter")}</label>
        <textarea id={id("after")} rows={3} value={after} onChange={(e) => setAfter(e.target.value)} />
        {said("after")}
      </div>
      <dl className="facts" aria-label={t("templatePlaceholders")}>
        {template.placeholders.map((p) => (
          <div key={p.name}><dt><code>:{p.name}</code></dt><dd>{p.about ?? ""}</dd></div>
        ))}
      </dl>
      <small className="muted">{t("templatePlaceholdersHelp")}</small>
      {failure && <p role="alert" className="error">{failure}</p>}
      {saved && <p role="status">{t("templateSaved")}</p>}
      <div className="actions">
        <button type="submit" disabled={busy}>{busy ? t("saving") : t("templateSave")}</button>
        <button type="button" className="secondary" disabled={busy} onClick={() => void run(async () => setPreview(await kitApi.previewEmailTemplate(template.kind, texts())))}>
          {t("templatePreview")}
        </button>
        {template.edited && (
          <button type="button" className="secondary" disabled={busy} onClick={() => void run(async () => {
            const back = await kitApi.resetEmailTemplate(template.kind);
            onChanged(back);
            show(back.texts);
            setPreview(null);
          })}>
            {t("templateReset")}
          </button>
        )}
      </div>
      {preview && (
        <div className="email-preview">
          <p><strong>{t("templateSubject")}:</strong> {preview.subject}</p>
          <iframe title={t("templatePreviewOf", { name: template.name })} sandbox="" srcDoc={preview.html} />
        </div>
      )}
    </form>
  );
}
