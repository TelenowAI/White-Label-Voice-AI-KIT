/**
 * Console → Consent (`/console/consent`; contract/kit-api.yaml listConsent, withdrawConsent, exportConsent): the account's record of who agreed to be called, newest first, searchable by number, each with what it says, whether it still counts and whether it has arrived;
 * downloading every record, withdrawn ones too, as a CSV file; withdrawing a number's, only after asking, since that also lists it as do-not-call and drops its waiting calls. A record has no edit or delete: it is what the customer shows after a complaint.
 *
 * Not here: whether calls need it (ConsentRulePanel.tsx), recording (ConsentForms.tsx), enforcement (the voice platform checks it before each call).
 */
import { useCallback, useEffect, useState } from "react";
import { consentExportUrl, kitApi, type ConsentPage as Page, type ConsentRecord } from "../api";
import { t, type TextKey } from "../text";
import { ConsentImportForm, ConsentRecordForm } from "./ConsentForms";
import { ConsentRulePanel } from "./ConsentRulePanel";
import { howWord, kindWord, scopeWord } from "./words";

const COLUMNS = 5;
const STATES: Record<ConsentRecord["state"], TextKey> = { live: "consentStateLive", expired: "consentStateExpired", withdrawn: "consentStateWithdrawn" };

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function day(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleDateString();
}

export function ConsentPage() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [data, setData] = useState<Page | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await kitApi.consent(page, query));
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
    <section aria-labelledby="consent-title">
      <h1 id="consent-title">{t("consentTitle")}</h1>
      <p className="muted">{t("consentHelp")}</p>
      <p><a href={consentExportUrl()} download>{t("downloadCsv")}</a></p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && <ConsentRulePanel rule={data.rule} onChanged={(rule) => setData((d) => (d ? { ...d, rule } : d))} />}
      {data && <ConsentRecordForm limits={data.limits} onRecorded={(said) => { setNotice(said); void load(); }} />}
      {data && <ConsentImportForm limits={data.limits} onImported={(said) => { setNotice(said); void load(); }} />}
      {notice && <p role="status">{notice}</p>}
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
          <label htmlFor="consent-search">{t("consentSearch")}</label>
          <input id="consent-search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
        </div>
        <button type="submit" className="secondary">{t("search")}</button>
      </form>
      {data && data.records.length === 0 && <p className="muted">{t("consentEmpty")}</p>}
      {data && data.records.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("consentTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("consentNumber")}</th>
                <th scope="col">{t("consentColKind")}</th>
                <th scope="col">{t("consentColWhen")}</th>
                <th scope="col">{t("consentColState")}</th>
                <th scope="col">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody>
              {data.records.map((r) => (
                <Row key={r.id} record={r} onWithdrawn={(said) => { setNotice(said); void load(); }} onError={setFailure} />
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && pages > 1 && (
        <nav className="pager">
          <button type="button" className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t("previousPage")}</button>
          <span>{t("pageOf", { page: String(page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>{t("nextPage")}</button>
        </nav>
      )}
    </section>
  );
}

function Row({ record: r, onWithdrawn, onError }: { record: ConsentRecord; onWithdrawn: (said: string) => void; onError: (m: string) => void }) {
  const [asking, setAsking] = useState(false);
  const [why, setWhy] = useState("");
  const [busy, setBusy] = useState(false);

  async function withdraw() {
    setBusy(true);
    try {
      await kitApi.withdrawConsent(r.phoneNumber, why.trim() || null);
      onWithdrawn(t("consentWithdrawn", { number: r.phoneNumber }));
    } catch (err) {
      onError(message(err));
      setBusy(false);
    }
  }

  return (
    <>
    <tr data-record={r.id}>
      <td>
        {r.phoneNumber}
        {r.syncState === "pending" && <div className="sync sync-pending"><small>{t("consentNotYet")}</small></div>}
        {r.syncState === "failed" && <div className="sync sync-failed"><small>{r.syncError}</small></div>}
      </td>
      <td>
        {kindWord(r.consentType)} · {scopeWord(r.scope)} · {howWord(r.source)}
        {r.disclosureText && <small className="muted"> “{r.disclosureText}”{r.disclosureVersion && ` (${r.disclosureVersion})`}</small>}
        {r.note && <small className="muted"> {r.note}</small>}
        {r.recordedBy && <small className="muted"> {t("consentRecordedBy", { name: r.recordedBy })}</small>}
      </td>
      <td>
        {day(r.capturedAt)}
        {r.expiresAt && <small className="muted"> {t("consentEndsOn", { at: day(r.expiresAt) })}</small>}
      </td>
      <td>
        {t(STATES[r.state])}
        {r.state === "withdrawn" && r.withdrawnAt && <small className="muted"> {day(r.withdrawnAt)}{r.withdrawnNote && `: ${r.withdrawnNote}`}</small>}
        {r.state === "live" && r.onDoNotCall && <small className="note"> {t("consentOnDnc")}</small>}
      </td>
      <td>
        {r.state !== "withdrawn" && !asking && <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("consentWithdraw")}</button>}
      </td>
    </tr>
    {r.state !== "withdrawn" && asking && (
      <tr>
        <td colSpan={COLUMNS}>
          <div className="actions">
            <span>{t("consentAskWithdraw", { number: r.phoneNumber })}</span>
            <label htmlFor={`withdraw-${r.id}`}>{t("consentWithdrawNote")}</label>
            <input id={`withdraw-${r.id}`} value={why} onChange={(e) => setWhy(e.target.value)} />
            <button type="button" disabled={busy} onClick={() => void withdraw()}>{t("consentYesWithdraw")}</button>
            <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
          </div>
        </td>
      </tr>
    )}
    </>
  );
}
