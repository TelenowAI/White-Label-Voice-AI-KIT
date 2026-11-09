/**
 * Console → one table in a knowledge base (`/console/knowledge/:baseId/documents/:documentId/table`; contract/kit-api.yaml
 * getKnowledgeTable, setKnowledgeColumnTypes): a CSV, JSON or Excel document as the agents use it. What kind of file it is and
 * how many rows and columns it has; how agents use it (read whole on every call when it is small enough, otherwise looked rows
 * up in, and how far finding rows by meaning reaches); each column's heading with how agents read it (text, a number, yes or
 * no, a date), saved for the changed columns only, the backend's word on a column beside it; and its rows, a page at a time.
 * The document's title, once the base answers it, is the heading. Counts read like the console's other counts
 * (insights/insightText.ts countText).
 *
 * Not here: the base and its documents, which link here (KnowledgeBasePage.tsx); reading a file into a table and looking rows
 * up on calls (the voice platform, by way of the backend). Used by console/ConsoleArea.tsx.
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";
import { KitApiError, kitApi, type KnowledgeColumnType, type KnowledgeTable, type KnowledgeTableColumn } from "../api";
import { countText } from "../insights/insightText";
import { t, type TextKey } from "../text";

/** Rows asked for at a time (the backend's own default page). */
const PAGE = 50;

/** The kinds of file a table comes from; one the backend names otherwise reads as a file. */
const formats: Partial<Record<string, TextKey>> & Record<KnowledgeTable["format"], TextKey> = { csv: "kbTableCsv", json: "kbTableJson", xlsx: "kbTableXlsx" };
/** The ways a column can be read, in the order they are offered. */
const types: Record<KnowledgeColumnType, TextKey> = { text: "kbTypeText", number: "kbTypeNumber", bool: "kbTypeBool", date: "kbTypeDate" };

/** A refusal in the console's words: not a table, not ready yet, or as the backend says it. */
function message(err: unknown): string {
  if (err instanceof KitApiError && err.status === 404) return t("kbTableNotTable");
  if (err instanceof KitApiError && err.status === 409) return t("kbTableNotReady");
  return err instanceof Error ? err.message : String(err);
}

/** A cell as text: none as empty, true or false as those words, a number as it is. */
function cellText(cell: unknown): string {
  if (cell === null || cell === undefined) return "";
  return typeof cell === "object" ? JSON.stringify(cell) : String(cell);
}

export function KnowledgeTablePage() {
  const { baseId = "", documentId = "" } = useParams();
  const [table, setTable] = useState<KnowledgeTable | null>(null);
  const [title, setTitle] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async (offset: number) => {
    setBusy(true);
    try {
      setTable(await kitApi.knowledgeTable(baseId, documentId, { offset, limit: PAGE }));
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }, [baseId, documentId]);

  useEffect(() => {
    void load(0);
    kitApi.knowledgeBase(baseId).then((b) => setTitle(b.documents.find((d) => d.id === documentId)?.title ?? null), () => undefined);
  }, [load, baseId, documentId]);

  const step = table && table.limit > 0 ? table.limit : PAGE;
  return (
    <section aria-labelledby="kb-table-title">
      <p>
        <Link to={`/console/knowledge/${encodeURIComponent(baseId)}`}>{t("kbTableBack")}</Link>
      </p>
      <h1 id="kb-table-title">{title ?? t("kbTableTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {!table && !failure && <p aria-busy="true">{t("loading")}</p>}
      {table && (
        <>
          <p className="muted">
            {t("kbTableSize", { kind: t(formats[table.format] ?? "kindFile"), rows: countText(table.rowCount), columns: countText(table.columns.length) })}
          </p>
          {table.inPrompt ? (
            <p>{t("kbTableInPrompt")}</p>
          ) : (
            <p>
              {t("kbTableLookUp")}
              {typeof table.searchByMeaningRows === "number" && (
                <>{" "}{t("kbTableByMeaning", { n: countText(table.searchByMeaningRows), rowCount: countText(table.rowCount) })}</>
              )}
            </p>
          )}
          <Columns
            key={documentId}
            baseId={baseId}
            documentId={documentId}
            columns={table.columns}
            onSaved={(columns) => setTable((was) => (was ? { ...was, columns } : was))}
          />
          <h2>{t("kbTableRows")}</h2>
          {table.rowCount === 0 ? (
            <p className="muted">{t("kbTableNoRows")}</p>
          ) : (
            <>
              <div className="table-scroll">
                <table className="list" aria-label={t("kbTableRows")}>
                  <thead>
                    <tr>
                      {table.columns.map((c) => (
                        <th key={c.key} scope="col">{c.name}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {table.rows.map((row, i) => (
                      <tr key={table.offset + i}>
                        {table.columns.map((c, j) => (
                          <td key={c.key}>{cellText(row[j])}</td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <nav className="pager" aria-label={t("kbTableRows")}>
                <button type="button" className="secondary" disabled={busy || table.offset <= 0} onClick={() => void load(Math.max(0, table.offset - step))}>
                  {t("previousPage")}
                </button>
                <span>
                  {t("kbTableRange", { from: countText(table.offset + 1), to: countText(table.offset + table.rows.length), total: countText(table.rowCount) })}
                </span>
                <button type="button" className="secondary" disabled={busy || table.offset + table.rows.length >= table.rowCount} onClick={() => void load(table.offset + step)}>
                  {t("nextPage")}
                </button>
              </nav>
            </>
          )}
        </>
      )}
    </section>
  );
}

/** Each column's heading and how agents read it; Save sends only the columns changed, then shows the columns as the backend answers them. */
function Columns({ baseId, documentId, columns, onSaved }: {
  baseId: string;
  documentId: string;
  columns: KnowledgeTableColumn[];
  onSaved: (columns: KnowledgeTableColumn[]) => void;
}) {
  /** What was picked for a column, by its key, since the columns were last answered. */
  const [chosen, setChosen] = useState<Record<string, KnowledgeColumnType>>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);

  const changed: Record<string, KnowledgeColumnType> = Object.fromEntries(
    columns.filter((c) => chosen[c.key] !== undefined && chosen[c.key] !== c.type).map((c) => [c.key, chosen[c.key]]),
  );
  const anyChanged = Object.keys(changed).length > 0;
  const columnError = (c: KnowledgeTableColumn) => errors[`types.${c.key}`];
  const otherErrors = Object.entries(errors).filter(([k]) => !columns.some((c) => k === `types.${c.key}`)).flatMap(([, v]) => v);

  async function submit(e: FormEvent) {
    e.preventDefault();
    if (!anyChanged) return;
    setBusy(true);
    setErrors({});
    setFailure(null);
    setSaved(false);
    try {
      const answer = await kitApi.setKnowledgeColumnTypes(baseId, documentId, changed);
      onSaved(answer.columns);
      setChosen({});
      setSaved(true);
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("kbTableColumns")}>
      <h2>{t("kbTableColumns")}</h2>
      <p id="kb-types-help" className="muted">{t("kbTableTypesHelp")}</p>
      {columns.map((c, i) => {
        const problem = columnError(c);
        return (
          <div className="field field-narrow" key={c.key}>
            <label htmlFor={`kb-column-${i}`}>{c.name}</label>
            <select
              id={`kb-column-${i}`}
              value={chosen[c.key] ?? c.type}
              onChange={(e) => {
                setChosen({ ...chosen, [c.key]: e.target.value as KnowledgeColumnType });
                setSaved(false);
              }}
              aria-invalid={problem ? true : undefined}
              aria-describedby={problem ? `kb-types-help kb-column-${i}-error` : "kb-types-help"}
            >
              {(Object.keys(types) as KnowledgeColumnType[]).map((type) => (
                <option key={type} value={type}>{t(types[type])}</option>
              ))}
            </select>
            {problem && <small id={`kb-column-${i}-error`} className="error">{problem.join(" ")}</small>}
          </div>
        );
      })}
      {otherErrors.length > 0 && <small className="error">{otherErrors.join(" ")}</small>}
      <button type="submit" disabled={busy || !anyChanged}>{busy ? t("saving") : t("kbTableSave")}</button>
      {saved && <p role="status">{t("kbTableSaved")}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
    </form>
  );
}
