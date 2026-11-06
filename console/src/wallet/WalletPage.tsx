/**
 * Console → Wallet (`/console/wallet`; contract/kit-api.yaml getWallet): the account's balance and its entries,
 * newest first — each credit and top-up, the charge of each call (linked to the call) and number fee, and a payment
 * refunded or disputed — with adding credit above them, and, once charging is on, when the low-balance email comes.
 *
 * Not here: topping up (AddCredit.tsx), choosing the low-balance email (WalletAlert.tsx).
 */
import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type Wallet, type WalletEntry } from "../api";
import { t, type TextKey } from "../text";
import { AddCredit } from "./AddCredit";
import { WalletAlert } from "./WalletAlert";
import { money } from "./money";

const kinds: Record<WalletEntry["kind"], TextKey> = {
  credit: "entryCredit", charge: "entryCharge", refund: "entryRefund", hold: "entryHold", release: "entryRelease",
};

export function WalletPage() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<Wallet | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async (p: number) => {
    try {
      setData(await kitApi.wallet(p));
      setFailure(null);
    } catch (err) {
      setFailure(err instanceof Error ? err.message : String(err));
    }
  }, []);

  useEffect(() => {
    void load(page);
  }, [load, page]);

  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;

  return (
    <section aria-labelledby="wallet-title">
      <h1 id="wallet-title">{t("walletTitle")}</h1>
      {failure && <p role="alert" className="error">{failure}</p>}
      {data && (
        <>
          <p className="balance">
            <span className="muted">{t("balance")}</span> <strong data-testid="balance">{money(data.balance, data.currency)}</strong>
          </p>
          {!data.inForce && <p className="muted">{t("walletNotInForce")}</p>}
          <AddCredit onPaid={() => void load(page)} />
          {data.inForce && <WalletAlert wallet={data} onSaved={(w) => setData((d) => (d ? { ...d, alert: w.alert } : w))} />}
          <h2>{t("walletEntriesTitle")}</h2>
          {data.entries.length === 0 ? (
            <p className="muted">{t("walletEmpty")}</p>
          ) : (
            <div className="table-scroll">
              <table className="list">
                <thead>
                  <tr>
                    <th scope="col">{t("colWhen")}</th>
                    <th scope="col">{t("colWhat")}</th>
                    <th scope="col">{t("colAmount")}</th>
                    <th scope="col">{t("colBalanceAfter")}</th>
                  </tr>
                </thead>
                <tbody>
                  {data.entries.map((e) => (
                    <tr key={e.id}>
                      <td>{new Date(e.createdAt).toLocaleString()}</td>
                      <td>
                        {e.callId ? (
                          <Link to={`/console/calls/${e.callId}`}>{t("entryCall")}</Link>
                        ) : e.numberId ? (
                          <Link to="/console/numbers">{t("entryNumberFee")}</Link>
                        ) : (
                          t(e.kind === "credit" && e.paymentId ? "entryTopUp" : e.kind === "refund" && !e.amount.startsWith("-") ? "entryRefundFailed" : kinds[e.kind])
                        )}
                        {e.note && <small className="muted"> · {e.note}</small>}
                      </td>
                      <td>{money(e.amount, e.currency)}</td>
                      <td>{money(e.balanceAfter, e.currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {pages > 1 && (
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
        </>
      )}
    </section>
  );
}
