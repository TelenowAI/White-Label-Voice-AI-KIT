/**
 * Console → one knowledge base (`/console/knowledge/:id`; contract/kit-api.yaml getKnowledgeBase, addKnowledgeText,
 * uploadKnowledgeFile, addKnowledgePage, retryKnowledgeDocument, deleteKnowledgeDocument, deleteKnowledgeBase): its
 * documents, newest first, each in the console's words (saving, being read, ready, failed and why, with a retry);
 * adding text, a file (the kinds and size the backend takes, checked before it is sent) or a web page; deleting a
 * document, or the base, only after asking (which agents stop answering from it). A document still being read is
 * looked at again shortly. A ready document kept as a table (a CSV, JSON or Excel file) links to that table.
 *
 * Not here: the list (KnowledgePage.tsx); a table's columns and rows (KnowledgeTablePage.tsx).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { KitApiError, kitApi, type KnowledgeBase, type KnowledgeDocument, type KnowledgeLimits } from "../api";
import { t, type TextKey } from "../text";

type Base = KnowledgeBase & { documents: KnowledgeDocument[] };

const states: Record<KnowledgeDocument["state"], TextKey> = { saving: "docStateSaving", working: "docStateWorking", ready: "docStateReady", failed: "docStateFailed" };
const kinds: Record<KnowledgeDocument["kind"], TextKey> = { text: "kindText", file: "kindFile", page: "kindPage" };
/** How soon a document still being read is looked at again. */
const RECHECK_MS = 5000;

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function fieldErrors(err: unknown): Record<string, string[]> | null {
  return err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : null;
}

export function KnowledgeBasePage() {
  const { id = "" } = useParams();
  const [base, setBase] = useState<Base | null>(null);
  const [limits, setLimits] = useState<KnowledgeLimits | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setBase(await kitApi.knowledgeBase(id));
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, [id]);

  useEffect(() => {
    void load();
    kitApi.knowledgeBases().then((l) => setLimits(l.limits), () => undefined);
  }, [load]);

  const reading = base?.documents.some((d) => d.state === "saving" || d.state === "working") ?? false;
  useEffect(() => {
    if (!reading) return;
    const timer = setTimeout(() => void load(), RECHECK_MS);
    return () => clearTimeout(timer);
  }, [reading, base, load]);

  return (
    <section aria-labelledby="kb-title">
      <p>
        <Link to="/console/knowledge">{t("backToKnowledge")}</Link>
      </p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {base && (
        <>
          <h1 id="kb-title">{base.name}</h1>
          {base.description && <p className="muted">{base.description}</p>}
          <h2>{t("docsTitle")}</h2>
          {base.documents.length === 0 && <p className="muted">{t("docsEmpty")}</p>}
          {base.documents.length > 0 && (
            <div className="table-scroll">
              <table className="list" aria-label={t("docsTitle")}>
                <thead>
                  <tr>
                    <th scope="col">{t("colDocument")}</th>
                    <th scope="col">{t("colKind")}</th>
                    <th scope="col">{t("colState")}</th>
                    <th scope="col">{t("colActions")}</th>
                  </tr>
                </thead>
                <tbody>
                  {base.documents.map((d) => (
                    <Document key={d.id} baseId={base.id} doc={d} onChanged={load} onError={setFailure} />
                  ))}
                </tbody>
              </table>
            </div>
          )}
          <AddText baseId={base.id} onAdded={load} />
          {limits && <AddFile baseId={base.id} limits={limits} onAdded={load} />}
          <AddPage baseId={base.id} onAdded={load} />
          <DeleteBase base={base} onError={setFailure} />
        </>
      )}
    </section>
  );
}

function Document({ baseId, doc: d, onChanged, onError }: { baseId: string; doc: KnowledgeDocument; onChanged: () => void; onError: (m: string) => void }) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);

  async function act(run: () => Promise<unknown>) {
    setBusy(true);
    try {
      await run();
      onChanged();
    } catch (err) {
      onError(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <tr data-document={d.id}>
      <td>
        {d.title}
        {d.source && d.source !== d.title && <small className="muted"> · {d.source}</small>}
      </td>
      <td>{t(kinds[d.kind])}</td>
      <td>
        <div className={`sync sync-${d.state === "ready" ? "synced" : d.state === "failed" ? "failed" : "pending"}`}>
          <span>{t(states[d.state])}</span>
          {d.problem && <small>{d.problem}</small>}
        </div>
      </td>
      <td>
        {asking ? (
          <div className="actions">
            <span>{t("confirmDeleteDoc", { title: d.title })}</span>
            <button type="button" disabled={busy} onClick={() => act(() => kitApi.deleteKnowledgeDocument(baseId, d.id))}>{t("yesDelete")}</button>
            <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
          </div>
        ) : (
          <div className="actions">
            {d.table && d.state === "ready" && (
              <Link to={`/console/knowledge/${encodeURIComponent(baseId)}/documents/${encodeURIComponent(d.id)}/table`}>{t("kbViewTable")}</Link>
            )}
            {d.state === "failed" && (
              <button type="button" className="secondary" disabled={busy} onClick={() => act(() => kitApi.retryKnowledgeDocument(baseId, d.id))}>{t("retry")}</button>
            )}
            <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("remove")}</button>
          </div>
        )}
      </td>
    </tr>
  );
}

function AddText({ baseId, onAdded }: { baseId: string; onAdded: () => void }) {
  const [title, setTitle] = useState("");
  const [text, setText] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await kitApi.addKnowledgeText(baseId, title.trim(), text);
      setTitle("");
      setText("");
      onAdded();
    } catch (err) {
      setErrors(fieldErrors(err) ?? { text: [message(err)] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("addTextTitle")}>
      <h2>{t("addTextTitle")}</h2>
      <div className="field">
        <label htmlFor="doc-title">{t("docTitle")}</label>
        <input id="doc-title" value={title} onChange={(e) => setTitle(e.target.value)} aria-invalid={errors.title ? true : undefined} />
        {errors.title && <small className="error">{errors.title.join(" ")}</small>}
      </div>
      <div className="field">
        <label htmlFor="doc-text">{t("docText")}</label>
        <textarea id="doc-text" rows={6} value={text} onChange={(e) => setText(e.target.value)} aria-invalid={errors.text ? true : undefined} />
        {errors.text && <small className="error">{errors.text.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("addText")}</button>
    </form>
  );
}

function AddFile({ baseId, limits, onAdded }: { baseId: string; limits: KnowledgeLimits; onAdded: () => void }) {
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const max = String(Math.floor(limits.fileMaxBytes / (1024 * 1024)));

  async function pick(e: { target: HTMLInputElement }) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setError(null);
    if (file.size > limits.fileMaxBytes) {
      setError(t("fileTooBig", { max }));
      return;
    }
    setBusy(true);
    try {
      await kitApi.uploadKnowledgeFile(baseId, file);
      onAdded();
    } catch (err) {
      const fields = fieldErrors(err);
      setError(fields ? Object.values(fields).flat().join(" ") : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="field">
      <h2>{t("addFileTitle")}</h2>
      <label htmlFor="doc-file">{t("docFile")}</label>
      <small className="muted">{t("addFileHelp", { types: limits.fileTypes.map((x) => x.toUpperCase()).join(", "), max })}</small>
      <input id="doc-file" type="file" accept={limits.fileTypes.map((x) => `.${x}`).join(",")} disabled={busy} onChange={pick} />
      {error && <small role="alert" className="error">{error}</small>}
    </div>
  );
}

function AddPage({ baseId, onAdded }: { baseId: string; onAdded: () => void }) {
  const [url, setUrl] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await kitApi.addKnowledgePage(baseId, url.trim());
      setUrl("");
      onAdded();
    } catch (err) {
      setErrors(fieldErrors(err) ?? { url: [message(err)] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate aria-label={t("addPageTitle")}>
      <div className="field">
        <label htmlFor="doc-url">{t("docUrl")}</label>
        <input id="doc-url" type="url" value={url} onChange={(e) => setUrl(e.target.value)} aria-invalid={errors.url ? true : undefined} />
        {errors.url && <small className="error">{errors.url.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("addPage")}</button>
    </form>
  );
}

function DeleteBase({ base, onError }: { base: Base; onError: (m: string) => void }) {
  const navigate = useNavigate();
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);

  async function remove() {
    setBusy(true);
    try {
      await kitApi.deleteKnowledgeBase(base.id);
      navigate("/console/knowledge");
    } catch (err) {
      onError(message(err));
      setBusy(false);
    }
  }

  const agents = base.agents.map((a) => a.name).join(", ");
  return (
    <div className="danger-zone">
      {asking ? (
        <div className="actions">
          <span>{agents ? t("confirmDeleteKb", { name: base.name, agents }) : t("confirmDeleteKbUnused", { name: base.name })}</span>
          <button type="button" disabled={busy} onClick={remove}>{t("yesDelete")}</button>
          <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
        </div>
      ) : (
        <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("deleteKnowledgeBase")}</button>
      )}
    </div>
  );
}
