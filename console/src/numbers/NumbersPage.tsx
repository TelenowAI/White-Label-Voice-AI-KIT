/**
 * Console → Numbers (`/console/numbers`; contract/kit-api.yaml listPhoneNumbers, searchAvailableNumbers, buyPhoneNumber,
 * setPhoneNumberAgent, releasePhoneNumber): the account's numbers — where each is, the agent that answers it (chosen
 * here), its rent and renewal, and whether a change is saved yet — finding numbers for sale by country and digits, each
 * with its setup and monthly price on the account's plan, buying one only after the price is confirmed, and releasing
 * one only after asking (one whose release has not gone through yet is listed, and can be released again).
 *
 * Not here: what a number costs (the backend prices it; the setup and rent are charged to the wallet).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Agent, type AvailableNumber, type PhoneNumber } from "../api";
import { money } from "../wallet/money";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function where(n: { region: string | null; country: string | null }): string {
  return [n.region, n.country].filter(Boolean).join(", ");
}

/** Every saved agent of the account, page by page: any of them can answer a number. */
async function allAgents(): Promise<Agent[]> {
  const first = await kitApi.agents(1);
  const pages = Math.max(1, Math.ceil(first.total / first.perPage));
  const rest = await Promise.all(Array.from({ length: pages - 1 }, (_, i) => kitApi.agents(i + 2)));
  return [first, ...rest].flatMap((p) => p.agents);
}

export function NumbersPage() {
  const [numbers, setNumbers] = useState<PhoneNumber[] | null>(null);
  const [agents, setAgents] = useState<Agent[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setNumbers((await kitApi.numbers()).numbers);
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
    allAgents().then(setAgents, (err) => setFailure(message(err)));
  }, [load]);

  return (
    <section aria-labelledby="numbers-title">
      <h1 id="numbers-title">{t("numbersTitle")}</h1>
      <p className="muted">{t("numbersHelp")}</p>
      {notice && <p role="status">{notice}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
      <h2>{t("numbersYours")}</h2>
      {numbers && numbers.length === 0 && <p className="muted">{t("numbersEmpty")}</p>}
      {numbers && numbers.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("numbersYours")}>
            <thead>
              <tr>
                <th scope="col">{t("colNumber")}</th>
                <th scope="col">{t("colWhere")}</th>
                <th scope="col">{t("colAnsweredBy")}</th>
                <th scope="col">{t("colMonthly")}</th>
                <th scope="col">{t("colRenews")}</th>
                <th scope="col">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody>
              {numbers.map((n) => (
                <Row
                  key={n.id}
                  number={n}
                  agents={agents}
                  onChanged={(changed) => setNumbers((list) => list?.map((x) => (x.id === changed.id ? changed : x)) ?? null)}
                  onReleased={load}
                  onError={setFailure}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
      <FindNumber
        onBought={(n) => {
          setNotice(t("numbersBought", { number: n.number }));
          void load();
        }}
      />
    </section>
  );
}

function Row({ number: n, agents, onChanged, onReleased, onError }: {
  number: PhoneNumber;
  agents: Agent[];
  onChanged: (n: PhoneNumber) => void;
  onReleased: () => void;
  onError: (m: string) => void;
}) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [agentError, setAgentError] = useState<string | null>(null);
  const choices = n.agent && !agents.some((a) => a.id === n.agent?.id) ? [n.agent, ...agents] : agents;

  async function choose(agentId: string) {
    setBusy(true);
    setAgentError(null);
    try {
      onChanged(await kitApi.setNumberAgent(n.id, agentId || null));
    } catch (err) {
      setAgentError(err instanceof KitApiError ? (err.fields.agentId?.join(" ") ?? err.message) : message(err));
    } finally {
      setBusy(false);
    }
  }

  async function release() {
    setBusy(true);
    try {
      await kitApi.releaseNumber(n.id);
      setAsking(false);
      onReleased();
    } catch (err) {
      onError(message(err));
    } finally {
      setBusy(false);
    }
  }

  const label = t("numbersAgentFor", { number: n.number });
  return (
    <tr data-number={n.id}>
      <td>
        {n.number}
        {n.syncState !== "synced" && (
          <div className={`sync sync-${n.syncState}`}>
            <span>{t(n.syncState === "failed" ? "syncFailed" : n.releasing ? "numbersReleasing" : "syncPending")}</span>
            {n.syncError && <small>{n.syncError}</small>}
          </div>
        )}
      </td>
      <td>{where(n)}</td>
      <td>
        {n.releasing ? (
          n.agent?.name ?? t("numbersNoAgent")
        ) : (
          <>
            <select aria-label={label} value={n.agent?.id ?? ""} disabled={busy} onChange={(e) => void choose(e.target.value)}>
              <option value="">{t("numbersNoAgent")}</option>
              {choices.map((a) => (
                <option key={a.id} value={a.id}>{a.name}</option>
              ))}
            </select>
            {agentError && <small role="alert" className="error">{agentError}</small>}
          </>
        )}
      </td>
      <td>{n.monthlyPrice ? money(n.monthlyPrice.amount, n.monthlyPrice.currency) : t("notPriced")}</td>
      <td>{n.nextRenewalAt && !n.releasing ? new Date(n.nextRenewalAt).toLocaleDateString() : ""}</td>
      <td>
        {n.releasing ? (
          n.syncState === "failed" && (
            <button type="button" className="secondary" disabled={busy} onClick={release}>{t("numbersReleaseAgain")}</button>
          )
        ) : asking ? (
          <div className="actions">
            <span>{t("numbersConfirmRelease", { number: n.number })}</span>
            <button type="button" disabled={busy} onClick={release}>{t("numbersYesRelease")}</button>
            <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
          </div>
        ) : (
          <button type="button" className="secondary" onClick={() => setAsking(true)}>{t("numbersRelease")}</button>
        )}
      </td>
    </tr>
  );
}

function FindNumber({ onBought }: { onBought: (n: PhoneNumber) => void }) {
  const [country, setCountry] = useState("");
  const [digits, setDigits] = useState("");
  const [searched, setSearched] = useState<{ country: string; numbers: AvailableNumber[] } | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function search(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const wanted = country.trim();
      setSearched({ country: wanted, numbers: (await kitApi.availableNumbers(wanted, digits.trim())).numbers });
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
      setSearched(null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <h2>{t("numbersFind")}</h2>
      <form className="inline-form" role="search" aria-label={t("numbersFind")} onSubmit={search} noValidate>
        <div className="field field-narrow">
          <label htmlFor="numbers-country">{t("numbersCountry")}</label>
          <input id="numbers-country" value={country} maxLength={2} autoCapitalize="characters" onChange={(e) => setCountry(e.target.value)}
            aria-invalid={errors.country ? true : undefined} aria-describedby="numbers-country-help" />
          <small id="numbers-country-help" className="muted">{t("numbersCountryHelp")}</small>
          {errors.country && <small className="error">{errors.country.join(" ")}</small>}
        </div>
        <div className="field field-narrow">
          <label htmlFor="numbers-digits">{t("numbersDigits")}</label>
          <input id="numbers-digits" inputMode="numeric" value={digits} onChange={(e) => setDigits(e.target.value)} aria-invalid={errors.pattern ? true : undefined} />
          {errors.pattern && <small className="error">{errors.pattern.join(" ")}</small>}
        </div>
        <button type="submit" className="secondary" disabled={busy}>{t("search")}</button>
      </form>
      {failure && <p role="alert" className="error">{failure}</p>}
      {searched && searched.numbers.length === 0 && <p className="muted">{t("numbersNoneForSale")}</p>}
      {searched && searched.numbers.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("numbersForSale")}>
            <thead>
              <tr>
                <th scope="col">{t("colNumber")}</th>
                <th scope="col">{t("colWhere")}</th>
                <th scope="col">{t("colSetup")}</th>
                <th scope="col">{t("colMonthly")}</th>
                <th scope="col">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody>
              {searched.numbers.map((n) => (
                <Offer
                  key={n.number}
                  offer={n}
                  country={searched.country}
                  onBought={(bought) => {
                    setSearched((s) => s && { ...s, numbers: s.numbers.filter((x) => x.number !== n.number) });
                    onBought(bought);
                  }}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  );
}

function Offer({ offer: n, country, onBought }: { offer: AvailableNumber; country: string; onBought: (n: PhoneNumber) => void }) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const price = (p: AvailableNumber["setupPrice"]) => (p ? money(p.amount, p.currency) : t("notPriced"));

  async function buy() {
    setBusy(true);
    setError(null);
    try {
      onBought(await kitApi.buyNumber(n.number, country));
    } catch (err) {
      setError(err instanceof KitApiError ? Object.values(err.fields).flat().join(" ") || err.message : message(err));
      setBusy(false);
    }
  }

  return (
    <tr>
      <td>{n.number}</td>
      <td>{where(n)}</td>
      <td>{price(n.setupPrice)}</td>
      <td>{price(n.monthlyPrice)}</td>
      <td>
        {asking ? (
          <div className="actions">
            <span>
              {n.setupPrice && n.monthlyPrice
                ? t("numbersConfirmBuy", { number: n.number, setup: price(n.setupPrice), monthly: price(n.monthlyPrice) })
                : t("numbersConfirmBuyUnpriced", { number: n.number })}
            </span>
            <button type="button" disabled={busy} onClick={buy}>{t("numbersYesBuy")}</button>
            <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>{t("cancel")}</button>
          </div>
        ) : (
          <button type="button" onClick={() => setAsking(true)}>{t("numbersBuy")}</button>
        )}
        {error && <small role="alert" className="error">{error}</small>}
      </td>
    </tr>
  );
}
