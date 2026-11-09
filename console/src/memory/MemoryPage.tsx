/**
 * Console → Caller memory (`/console/memory`; contract/kit-api.yaml getCallerMemory, forgetCaller): what the account's agents remember
 * about each caller, most recent first — who they are (a phone caller's number; a web chat visitor, with the website they chatted on
 * when the backend has it; any other caller as the backend names them), the summary and facts kept, how many calls it was made from
 * with a link to the last one, when it was updated, and until when it is used on calls (or that it no longer is). A search box narrows
 * the list in the page. When the backend lists as many as it lists at most, the page says only the most recent are shown.
 *
 * Forgetting cannot be undone, so only someone who manages the account may (`canForget`; anyone else is told so and gets no button):
 * a listed caller after being asked, its row then gone; or any number, listed or not, typed in, the backend saying whether anything
 * was remembered, the list then read again. The backend's word on the number shows beside it, any other refusal as an alert.
 *
 * Not here: whether an agent remembers callers and for how long (its behaviour, agents/AgentBehaviourFields.tsx, which links here);
 * what is kept and how it is used on a call (the voice platform, by way of the backend's App\Kit\Memory\CallerMemory).
 * Used by console/ConsoleArea.tsx.
 */
import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type CallerMemory, type RememberedCaller } from "../api";
import { t } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function day(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleDateString();
}

function moment(iso: string): string {
  const at = new Date(iso);
  return Number.isNaN(at.getTime()) ? iso : at.toLocaleString();
}

/** Who a remembered caller is, in the console's words: a phone caller's number, a web chat visitor (on a website), or as the backend names them. */
function callerName(c: RememberedCaller): string {
  if (c.kind === "chat") return c.website ? t("memoryChatVisitorOn", { website: c.website }) : t("memoryChatVisitor");
  return c.caller;
}

/** Whether a caller answers what was typed in the search box: by who they are, the summary, or a fact's name or value; a phone number however it is written. */
function matches(c: RememberedCaller, typed: string): boolean {
  const q = typed.trim().toLowerCase();
  if (q === "") return true;
  const text = [callerName(c), c.caller, c.summary ?? "", ...c.facts.flatMap((f) => [f.name, f.value])];
  if (text.some((s) => s.toLowerCase().includes(q))) return true;
  const digits = q.replace(/\D/g, "");
  return c.kind === "phone" && digits !== "" && /^[\d\s()+.-]+$/.test(q) && c.caller.replace(/\D/g, "").includes(digits);
}

export function MemoryPage() {
  const [data, setData] = useState<CallerMemory | null>(null);
  /** Callers forgotten from their row since the list was read: gone from it until it is read again. */
  const [gone, setGone] = useState<ReadonlySet<string>>(new Set());
  const [search, setSearch] = useState("");
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(async () => {
    try {
      setData(await kitApi.callerMemory());
      setGone(new Set());
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const remaining = useMemo(() => (data ? data.callers.filter((c) => !gone.has(c.key)) : []), [data, gone]);
  const shown = useMemo(() => remaining.filter((c) => matches(c, search)), [remaining, search]);

  return (
    <section aria-labelledby="memory-title">
      <h1 id="memory-title">{t("memoryTitle")}</h1>
      <p className="muted">{t("memoryHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {notice && <p role="status">{notice}</p>}
      {!data && !failure && <p aria-busy="true">{t("loading")}</p>}
      {data && (
        <>
          {!data.canForget && <p className="note">{t("memoryOnlyManagers")}</p>}
          {data.limit > 0 && data.callers.length >= data.limit && (
            <p className="note">{t(data.canForget ? "memoryAtLimitForget" : "memoryAtLimit", { limit: String(data.limit) })}</p>
          )}
          {remaining.length === 0 && <p className="muted">{t("memoryEmpty")}</p>}
          {remaining.length > 0 && (
            <div className="field" role="search">
              <label htmlFor="memory-search">{t("memorySearch")}</label>
              <input id="memory-search" type="search" value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>
          )}
          {remaining.length > 0 && shown.length === 0 && <p className="muted">{t("memoryNoMatch")}</p>}
          {shown.length > 0 && (
            <div className="table-scroll">
              <table className="list" aria-label={t("memoryTitle")}>
                <thead>
                  <tr>
                    <th scope="col">{t("memoryColCaller")}</th>
                    <th scope="col">{t("memoryColRemembered")}</th>
                    <th scope="col">{t("memoryColCalls")}</th>
                    <th scope="col">{t("memoryColUpdated")}</th>
                    <th scope="col">{t("memoryColUsed")}</th>
                    {data.canForget && <th scope="col">{t("colActions")}</th>}
                  </tr>
                </thead>
                <tbody>
                  {shown.map((c) => (
                    <Row
                      key={c.key}
                      caller={c}
                      canForget={data.canForget}
                      onForgotten={() => {
                        setGone((was) => new Set([...was, c.key]));
                        setFailure(null);
                        setNotice(t("memoryForgotCaller", { caller: callerName(c) }));
                      }}
                      onError={setFailure}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {data.canForget && (
            <ForgetNumber
              onForgotten={() => {
                setNotice(null);
                void load();
              }}
            />
          )}
        </>
      )}
    </section>
  );
}

function Row({ caller: c, canForget, onForgotten, onError }: {
  caller: RememberedCaller;
  canForget: boolean;
  onForgotten: () => void;
  onError: (m: string) => void;
}) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const who = callerName(c);

  async function forget() {
    setBusy(true);
    try {
      await kitApi.forgetCaller(c.key);
      onForgotten();
    } catch (err) {
      onError(message(err));
      setBusy(false);
    }
  }

  return (
    <tr data-caller={c.key}>
      <td>{who}</td>
      <td>
        {c.summary && <p>{c.summary}</p>}
        {c.facts.length > 0 && (
          <dl className="facts">
            {c.facts.map((f, i) => (
              <div key={`${i}-${f.name}`}>
                <dt>{f.name}</dt>
                <dd>{f.value}</dd>
              </div>
            ))}
          </dl>
        )}
      </td>
      <td>
        {c.calls}
        {c.lastCallId && (
          <>
            {" "}
            <Link to={`/console/calls/${encodeURIComponent(c.lastCallId)}`}>{t("memoryLastCall")}</Link>
          </>
        )}
      </td>
      <td>{moment(c.updatedAt)}</td>
      <td>{c.expired ? t("memoryExpired") : c.expiresAt ? t("memoryUntil", { at: day(c.expiresAt) }) : t("memoryNoEnd")}</td>
      {canForget && (
        <td>
          {asking ? (
            <div className="actions">
              <span>{t("memoryAskForget", { caller: who })}</span>
              <button type="button" disabled={busy} onClick={() => void forget()}>
                {busy ? t("memoryForgetting") : t("memoryYesForget")}
              </button>
              <button type="button" className="secondary" disabled={busy} onClick={() => setAsking(false)}>
                {t("cancel")}
              </button>
            </div>
          ) : (
            <button type="button" className="secondary" onClick={() => setAsking(true)}>
              {t("memoryForget")}
            </button>
          )}
        </td>
      )}
    </tr>
  );
}

/** Forgetting any number, listed or not: the backend says whether anything was remembered about it. */
function ForgetNumber({ onForgotten }: { onForgotten: () => void }) {
  const [number, setNumber] = useState("");
  const [errors, setErrors] = useState<string[]>([]);
  const [failure, setFailure] = useState<string | null>(null);
  const [answer, setAnswer] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors([]);
    setFailure(null);
    setAnswer(null);
    try {
      const { removed } = await kitApi.forgetCaller(number.trim());
      setAnswer(t(removed ? "memoryForgotten" : "memoryNothingRemembered"));
      onForgotten();
    } catch (err) {
      if (err instanceof KitApiError && err.fields.caller) setErrors(err.fields.caller);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const described = ["memory-number-help", errors.length > 0 ? "memory-number-error" : null].filter(Boolean).join(" ");
  return (
    <section aria-labelledby="memory-forget-title">
      <h2 id="memory-forget-title">{t("memoryForgetNumber")}</h2>
      <p id="memory-number-help" className="muted">{t("memoryForgetNumberHelp")}</p>
      <form className="inline-form" onSubmit={submit} noValidate aria-label={t("memoryForgetNumber")}>
        <div className="field field-narrow">
          <label htmlFor="memory-number">{t("memoryNumber")}</label>
          <input id="memory-number" type="tel" value={number} onChange={(e) => setNumber(e.target.value)}
            aria-invalid={errors.length > 0 || undefined} aria-describedby={described} />
          {errors.length > 0 && <small id="memory-number-error" className="error">{errors.join(" ")}</small>}
        </div>
        <button type="submit" disabled={busy}>{busy ? t("memoryForgetting") : t("memoryForget")}</button>
      </form>
      {answer && <p role="status">{answer}</p>}
      {failure && <p role="alert" className="error">{failure}</p>}
    </section>
  );
}
