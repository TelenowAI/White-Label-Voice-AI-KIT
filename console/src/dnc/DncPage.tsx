/**
 * Console → Do not call (`/console/dnc`; contract/kit-api.yaml listDncEntries, addDncEntry, importDncEntries,
 * removeDncEntry): the account's list, newest first, searchable — each number with its reason, how it got there (added,
 * imported, or the caller asked, linked to the call) and whether it is saved yet — adding one, importing a CSV (the
 * backend says how many it added and skipped), and removing one after asking (a caller's own opt-out asked twice).
 *
 * Not here: enforcement (the voice platform never dials a number on the list).
 */
import { useCallback, useEffect, useState, type ChangeEvent, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type DncEntry, type DncPage as Page } from "../api";
import { t, type TextKey } from "../text";

const sources: Record<string, TextKey> = { manual: "sourceManual", csv: "sourceCsv", opt_out: "sourceOptOut" };

/** A chosen file's text (FileReader: every browser has it, and so does the test DOM, which has no Blob.text). */
function readText(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result ?? ""));
    reader.onerror = () => reject(reader.error ?? new Error("The file could not be read."));
    reader.readAsText(file);
  });
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function DncPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [data, setData] = useState<Page | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await kitApi.dnc(page, query));
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, [page, query]);

  useEffect(() => {
    void load();
  }, [load]);

  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;

  return (
    <section aria-labelledby="dnc-title">
      <h1 id="dnc-title">{t("dncTitle")}</h1>
      <p className="muted">{t("dncHelp")}</p>
      <AddNumber onAdded={load} />
      <ImportCsv
        onImported={(counts) => {
          setNotice(t("dncImported", { added: String(counts.added), skipped: String(counts.skipped) }));
          void load();
        }}
      />
      {notice && <p role="status">{notice}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      <form
        className="inline-form"
        role="search"
        onSubmit={(e) => {
          e.preventDefault();
          setPage(1);
          setQuery(search.trim());
        }}
      >
        <div className="field">
          <label htmlFor="dnc-search">{t("dncSearch")}</label>
          <input id="dnc-search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <button type="submit" className="secondary">{t("search")}</button>
      </form>
      {data && data.entries.length === 0 && <p className="muted">{t("dncEmpty")}</p>}
      {data && data.entries.length > 0 && (
        <div className="table-scroll">
          <table className="list">
            <thead>
              <tr>
                <th scope="col">{t("dncNumber")}</th>
                <th scope="col">{t("dncReason")}</th>
                <th scope="col">{t("colSource")}</th>
                <th scope="col">{t("colAdded")}</th>
                <th scope="col">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody>
              {data.entries.map((e) => (
                <Row key={e.id} entry={e} onRemoved={load} onError={setFailure} />
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && pages > 1 && (
        <nav className="pager">
          <button type="button" className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t("previousPage")}
          </button>
          <span>{t("pageOf", { page: String(page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>
            {t("nextPage")}
          </button>
        </nav>
      )}
    </section>
  );
}

function Row({ entry: e, onRemoved, onError }: { entry: DncEntry; onRemoved: () => void; onError: (m: string) => void }) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const source = e.source && e.source in sources ? t(sources[e.source]) : (e.source ?? "").replace(/_/g, " ");

  async function remove() {
    setBusy(true);
    try {
      await kitApi.removeDnc(e.id);
      onRemoved();
    } catch (err) {
      onError(message(err));
      setBusy(false);
    }
  }

  return (
    <tr data-entry={e.id}>
      <td>
        {e.phoneNumber}
        {e.syncState !== "synced" && (
          <div className={`sync sync-${e.syncState}`}>
            <span>{t(e.syncState === "pending" ? "syncPending" : "syncFailed")}</span>
            {e.syncError && <small>{e.syncError}</small>}
          </div>
        )}
      </td>
      <td>{e.reason}</td>
      <td>{e.callId ? <Link to={`/console/calls/${e.callId}`}>{source}</Link> : source}</td>
      <td>{new Date(e.createdAt).toLocaleDateString()}</td>
      <td>
        {asking ? (
          <div className="actions">
            <span>{t(e.source === "opt_out" ? "confirmRemoveOptOut" : "confirmRemoveDnc", { number: e.phoneNumber })}</span>
            <button type="button" disabled={busy} onClick={remove}>{t("yesRemove")}</button>
            <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
          </div>
        ) : (
          <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("remove")}</button>
        )}
      </td>
    </tr>
  );
}

function AddNumber({ onAdded }: { onAdded: () => void }) {
  const [number, setNumber] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    try {
      await kitApi.addDnc(number, reason || null);
      setNumber("");
      setReason("");
      onAdded();
    } catch (err) {
      setErrors(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : { phoneNumber: [message(err)] });
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate aria-label={t("dncAdd")}>
      <div className="field field-narrow">
        <label htmlFor="dnc-number">{t("dncNumber")}</label>
        <input id="dnc-number" type="tel" value={number} onChange={(e) => setNumber(e.target.value)} aria-invalid={errors.phoneNumber ? true : undefined} />
        {errors.phoneNumber && <small className="error">{errors.phoneNumber.join(" ")}</small>}
      </div>
      <div className="field">
        <label htmlFor="dnc-reason">{t("dncReason")}</label>
        <input id="dnc-reason" value={reason} onChange={(e) => setReason(e.target.value)} />
        {errors.reason && <small className="error">{errors.reason.join(" ")}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("adding") : t("dncAdd")}</button>
    </form>
  );
}

function ImportCsv({ onImported }: { onImported: (counts: { added: number; skipped: number }) => void }) {
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function pick(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    setBusy(true);
    setError(null);
    try {
      onImported(await kitApi.importDnc(await readText(file)));
    } catch (err) {
      setError(err instanceof KitApiError ? (err.fields.csv?.join(" ") ?? err.message) : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="field">
      <label htmlFor="dnc-import">{t("dncImport")}</label>
      <small className="muted">{t("dncImportHelp")}</small>
      <input id="dnc-import" type="file" accept=".csv,text/csv,text/plain" disabled={busy} onChange={pick} />
      {error && <small role="alert" className="error">{error}</small>}
    </div>
  );
}
