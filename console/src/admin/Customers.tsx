/**
 * Admin → Customers (contract/kit-api.yaml listCustomers, createCustomer, suspendCustomer, resumeCustomer,
 * retryCustomer): the operator's customers, newest first, a page at a time; adding one; suspending, resuming, and
 * sending a failed change to the voice platform again. Each row says whether the voice platform has the latest
 * change: in step, saving (with the reason it is still trying), or failed (with the platform's reason).
 *
 * Not here: a customer's own page, plans and limits (later K1 slices).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type Customer, type CustomerPage } from "../api";
import { t } from "../text";
import { STATUS } from "./CustomerPage";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function Customers() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<CustomerPage | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async (p: number) => {
    try {
      setData(await kitApi.customers(p));
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load(page);
  }, [load, page]);

  const replace = (c: Customer) => setData((d) => (d ? { ...d, customers: d.customers.map((x) => (x.id === c.id ? c : x)) } : d));
  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;

  return (
    <section aria-labelledby="customers-title">
      <h1 id="customers-title">{t("customersTitle")}</h1>
      <AddCustomer
        onAdded={() => {
          if (page === 1) void load(1);
          else setPage(1);
        }}
      />
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && data.customers.length === 0 && <p className="muted">{t("customersEmpty")}</p>}
      {data && data.customers.length > 0 && (
        <div className="table-scroll">
          <table className="customers">
            <thead>
              <tr>
                <th scope="col">{t("colName")}</th>
                <th scope="col">{t("colStatus")}</th>
                <th scope="col">{t("colTelenow")}</th>
                <th scope="col">{t("colAdded")}</th>
                <th scope="col">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody>
              {data.customers.map((c) => (
                <Row key={c.id} customer={c} onChanged={replace} onError={setFailure} />
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

function AddCustomer({ onAdded }: { onAdded: () => void }) {
  const [name, setName] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await kitApi.createCustomer(name);
      setName("");
      onAdded();
    } catch (err) {
      setError(err instanceof KitApiError ? (err.fields.name?.join(" ") ?? err.message) : message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="inline-form" onSubmit={submit} noValidate>
      <div className="field">
        <label htmlFor="new-customer-name">{t("customerName")}</label>
        <input
          id="new-customer-name"
          value={name}
          onChange={(e) => setName(e.target.value)}
          aria-invalid={error ? true : undefined}
          aria-describedby={error ? "new-customer-error" : undefined}
        />
        {error && <small id="new-customer-error" className="error">{error}</small>}
      </div>
      <button type="submit" disabled={busy}>{busy ? t("adding") : t("addCustomer")}</button>
    </form>
  );
}

function Row({ customer: c, onChanged, onError }: { customer: Customer; onChanged: (c: Customer) => void; onError: (m: string) => void }) {
  const [busy, setBusy] = useState(false);
  const act = (run: () => Promise<Customer>) => async () => {
    setBusy(true);
    try {
      onChanged(await run());
    } catch (err) {
      onError(message(err));
    } finally {
      setBusy(false);
    }
  };

  return (
    <tr data-customer={c.id}>
      <td>
        <Link to={`/admin/customers/${c.id}`}>{c.name}</Link>
      </td>
      <td>{t(STATUS[c.status])}</td>
      <td>
        <div className={`sync sync-${c.syncState}`}>
          <span>{t(c.syncState === "synced" ? "syncSynced" : c.syncState === "pending" ? "syncPending" : "syncFailed")}</span>
          {c.syncError && <small>{c.syncError}</small>}
        </div>
      </td>
      <td>{new Date(c.createdAt).toLocaleDateString()}</td>
      <td>
        <div className="actions">
          {c.status === "active" && (
            <button type="button" className="secondary" disabled={busy} onClick={act(() => kitApi.suspendCustomer(c.id))}>
              {t("suspend")}
            </button>
          )}
          {c.status === "suspended" && (
            <button type="button" className="secondary" disabled={busy} onClick={act(() => kitApi.resumeCustomer(c.id))}>
              {t("resume")}
            </button>
          )}
          {c.syncState === "failed" && (
            <button type="button" disabled={busy} onClick={act(() => kitApi.retryCustomer(c.id))}>
              {t("retry")}
            </button>
          )}
        </div>
      </td>
    </tr>
  );
}
