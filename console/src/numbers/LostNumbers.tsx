/**
 * The Numbers page's lost numbers (contract/kit-api.yaml listLostNumbers): numbers given up for the account that can still be got back, shown
 * only when there are any — each in a row (LostNumberRow.tsx). Read when the page opens and again after one came back (it leaves the list).
 * When they cannot be read (503 while the platform cannot be reached), the backend's word in a quiet line, and the rest of the page works on.
 *
 * Not here: getting one back (LostNumberRow.tsx); the numbers list read again after one came back (NumbersPage.tsx). Used by: NumbersPage.tsx.
 */
import { useCallback, useEffect, useState } from "react";
import { kitApi, type LostNumber, type PhoneNumber } from "../api";
import { t } from "../text";
import { LostNumberRow } from "./LostNumberRow";

interface Props {
  /** One came back at once, as the number given (a new one, set up as the old one was). */
  onRestored: (n: PhoneNumber) => void;
}

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function LostNumbers({ onRestored }: Props) {
  const [numbers, setNumbers] = useState<LostNumber[] | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setNumbers((await kitApi.lostNumbers()).numbers);
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (failure) return <p className="muted" data-testid="lost-numbers-failure">{failure}</p>;
  if (!numbers || numbers.length === 0) return null;
  return (
    <section aria-labelledby="numbers-lost-title">
      <h2 id="numbers-lost-title">{t("numbersLostTitle")}</h2>
      <p className="muted">{t("numbersLostHelp")}</p>
      <div className="table-scroll">
        <table className="list" aria-label={t("numbersLostTitle")}>
          <thead>
            <tr>
              <th scope="col">{t("colNumber")}</th>
              <th scope="col">{t("colWhy")}</th>
              <th scope="col">{t("colUntil")}</th>
              <th scope="col">{t("colHadAgent")}</th>
              <th scope="col">{t("colGetBackCost")}</th>
              <th scope="col">{t("colActions")}</th>
            </tr>
          </thead>
          <tbody>
            {numbers.map((n) => (
              <LostNumberRow
                key={n.id}
                lost={n}
                onRestored={(back) => {
                  onRestored(back);
                  void load();
                }}
              />
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
