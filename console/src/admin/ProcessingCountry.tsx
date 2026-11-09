/**
 * Admin → an account's processing country on the voice platform (contract/kit-api.yaml getPlatformProcessingCountry,
 * getCustomerProcessingCountry): the country by name with its code (the browser's own names, Intl.DisplayNames), where
 * it comes from in words (the account's country, its time zone at sign-up, or not known — with where to set it), when
 * it was checked, Check again (read from the voice platform now, `fresh`), and what it means. A read that fails is said
 * quietly in this panel — a 409 (no key yet; a customer with no account on the voice platform yet) as a note, anything
 * else as an error — and the rest of the page stays; Check again keeps the country shown when it fails.
 *
 * Used by VoicePlatformPage.tsx (PlatformProcessingCountry: the operator's own account) and CustomerPage.tsx
 * (CustomerProcessingCountry: a customer's, which keeps the country it had when it was made). Admin only: customers
 * never see it. Not here: setting it (done on the voice platform, never in the kit).
 */
import { useCallback, useEffect, useId, useState } from "react";
import { KitApiError, kitApi, type ProcessingCountry } from "../api";
import { t, type TextKey } from "../text";

const SOURCE: Record<ProcessingCountry["source"], TextKey> = {
  country: "processingCountryFromCountry",
  time_zone: "processingCountryFromTimeZone",
  unknown: "processingCountryFromUnknown",
};

/** A conflict (409) is a state the account is in, said as a note; anything else is an error. */
const CONFLICT = 409;

/** A two-letter country code as people read it, in the viewer's own language, with the code ("India (IN)"); the code alone when the browser has no name. */
export function countryName(code: string): string {
  let name: string | undefined;
  try {
    name = new Intl.DisplayNames(undefined, { type: "region" }).of(code);
  } catch {
    name = undefined;
  }
  return name && name !== code ? t("processingCountryNamed", { name, code }) : code;
}

/** The operator's own account's processing country (Admin → Voice platform). */
export function PlatformProcessingCountry() {
  return <ProcessingCountryPanel load={kitApi.platformProcessingCountry} help="processingCountryPlatformHelp" />;
}

/** A customer's processing country (Admin → the customer's page). */
export function CustomerProcessingCountry({ customerId }: { customerId: string }) {
  const load = useCallback((fresh: boolean) => kitApi.customerProcessingCountry(customerId, fresh), [customerId]);
  return <ProcessingCountryPanel load={load} help="processingCountryCustomerHelp" />;
}

function ProcessingCountryPanel({ load, help }: { load: (fresh: boolean) => Promise<ProcessingCountry>; help: TextKey }) {
  const titleId = useId();
  const [read, setRead] = useState<ProcessingCountry | null>(null);
  const [failure, setFailure] = useState<{ message: string; quiet: boolean } | null>(null);
  const [busy, setBusy] = useState(false);

  const failed = (err: unknown) =>
    setFailure({ message: err instanceof Error ? err.message : String(err), quiet: err instanceof KitApiError && err.status === CONFLICT });

  useEffect(() => {
    let current = true;
    load(false).then(
      (country) => {
        if (!current) return;
        setRead(country);
        setFailure(null);
      },
      (err: unknown) => current && failed(err),
    );
    return () => {
      current = false;
    };
  }, [load]);

  async function checkAgain() {
    setBusy(true);
    setFailure(null);
    try {
      setRead(await load(true));
    } catch (err) {
      failed(err);
    } finally {
      setBusy(false);
    }
  }

  return (
    <section aria-labelledby={titleId}>
      <h2 id={titleId}>{t("processingCountryTitle")}</h2>
      <p className="muted">{t(help)}</p>
      {read && (
        <dl className="facts">
          <div>
            <dt>{t("processingCountryCountry")}</dt>
            <dd data-testid="processing-country">{read.country ? countryName(read.country) : t("processingCountryNone")}</dd>
          </div>
          <div>
            <dt>{t("processingCountrySource")}</dt>
            <dd data-testid="processing-country-source">{t(SOURCE[read.source])}</dd>
          </div>
          <div>
            <dt>{t("processingCountryCheckedAt")}</dt>
            <dd data-testid="processing-country-checked">{new Date(read.checkedAt).toLocaleString()}</dd>
          </div>
        </dl>
      )}
      {failure && <p className={failure.quiet ? "muted" : "error"} data-testid="processing-country-failure">{failure.message}</p>}
      <button type="button" className="secondary" disabled={busy} onClick={() => void checkAgain()}>
        {busy ? t("processingCountryChecking") : t("processingCountryCheckAgain")}
      </button>
    </section>
  );
}
