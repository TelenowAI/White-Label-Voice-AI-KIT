/**
 * Console → Phone (`/console/phone`; contract/kit-api.yaml getPhone, placeBrowserCall, hangUpBrowserCall, transferBrowserCall): a member calls someone from
 * this browser, from one of the account's numbers. The kit's backend places the call with the account's own key and answers only what the browser joins
 * with; @telenow/client's `TelenowCall` joins it (`session` option, no key reaches the browser) with the microphone. While it is on: mute, hang up, and —
 * when the service allows it — hand it to another number. During and after it, the call's wrap-up, notes and follow-ups (CallWork.tsx). Below, the calls
 * this member placed.
 *
 * Hanging up leaves the call in the browser and ends it at the voice platform too, so the person called is not left on a line nobody is on; leaving the
 * page does the same. Nothing is offered until the operator allows such calls, and none while the service is paused or the plan has no price for one; a
 * refusal is the backend's message, a refused field says so beside it.
 *
 * Not here: listening in on a call (not offered), the calls list (calls/Calls.tsx).
 */
import { TelenowCall, type CallState } from "@telenow/client";
import { useEffect, useId, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { KitApiError, kitApi, type CallPage, type PhonePage as Phone } from "../api";
import { CallWork } from "../calls/CallWork";
import { durationText, outcomeText } from "../calls/callText";
import { t, type TextKey } from "../text";

const stateText: Record<CallState, TextKey> = {
  idle: "callIdle",
  connecting: "phoneRinging",
  live: "callLive",
  reconnecting: "callReconnecting",
  ended: "callEnded",
  error: "callError",
};

/** The states in which there is a call to hang up. */
const inCall: CallState[] = ["connecting", "live", "reconnecting"];

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function PhonePage() {
  const [phone, setPhone] = useState<Phone | null>(null);
  const [callerId, setCallerId] = useState("");
  const [number, setNumber] = useState("");
  const [transferTo, setTransferTo] = useState("");
  const [state, setState] = useState<CallState>("idle");
  const [muted, setMuted] = useState(false);
  const [callId, setCallId] = useState<string | null>(null);
  const [mine, setMine] = useState<CallPage | null>(null);
  const [errors, setErrors] = useState<{ form: string; fields: Record<string, string[]> }>({ form: "", fields: {} });
  const [failure, setFailure] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState<"call" | "transfer" | null>(null);
  const live = useRef<{ call: TelenowCall; id: string } | null>(null);
  const ids = useId();

  const loadMine = () => kitApi.calls(1, { placedBy: "me" }).then(setMine, () => undefined);

  useEffect(() => {
    kitApi.phone().then((p) => {
      setPhone(p);
      setCallerId((c) => c || p.callerIds[0]?.id || "");
    }, (err: unknown) => setFailure(message(err)));
    void loadMine();
  }, []);

  // Leaving the page ends the call, here and at the voice platform.
  useEffect(
    () => () => {
      const current = live.current;
      live.current = null;
      if (current) {
        current.call.stop();
        kitApi.hangUpBrowserCall(current.id).catch(() => undefined);
      }
    },
    [],
  );

  function refused(err: unknown, form: string) {
    if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors({ form, fields: err.fields });
    else setFailure(message(err));
  }

  async function place(e: FormEvent) {
    e.preventDefault();
    setBusy("call");
    setErrors({ form: "", fields: {} });
    setFailure(null);
    setNotice(null);
    try {
      const placed = await kitApi.placeBrowserCall(callerId, number);
      const call = new TelenowCall({
        session: placed.session,
        onState: (s) => {
          setState(s);
          if (s !== "ended" && s !== "error") return;
          // Over in the browser: on an error the line may still be open at the voice platform, so it is ended there too.
          if (s === "error" && live.current?.id === placed.callId) kitApi.hangUpBrowserCall(placed.callId).catch(() => undefined);
          if (live.current?.id === placed.callId) live.current = null;
          void loadMine();
        },
        onError: setFailure,
      });
      live.current = { call, id: placed.callId };
      setCallId(placed.callId);
      setMuted(false);
      await call.start();
    } catch (err) {
      refused(err, "call");
    } finally {
      setBusy(null);
    }
  }

  async function hangUp() {
    const current = live.current;
    live.current = null;
    if (!current) return;
    current.call.stop();
    try {
      await kitApi.hangUpBrowserCall(current.id);
    } catch (err) {
      // Over already is what was asked for.
      if (!(err instanceof KitApiError && err.status === 409)) setFailure(message(err));
    }
    setState("ended");
    void loadMine();
  }

  function toggleMute() {
    const current = live.current;
    if (!current) return;
    current.call.setMuted(!muted);
    setMuted(!muted);
  }

  async function transfer(e: FormEvent) {
    e.preventDefault();
    const current = live.current;
    if (!current) return;
    setBusy("transfer");
    setErrors({ form: "", fields: {} });
    setFailure(null);
    try {
      await kitApi.transferBrowserCall(current.id, transferTo);
      // The person called is with the new number now; this browser is no longer on the call.
      live.current = null;
      current.call.stop();
      setState("ended");
      setNotice(t("phoneTransferred", { number: transferTo }));
      setTransferTo("");
      void loadMine();
    } catch (err) {
      refused(err, "transfer");
    } finally {
      setBusy(null);
    }
  }

  const fieldError = (form: string, name: string) => {
    const reasons = errors.form === form ? errors.fields[name] : undefined;
    return reasons?.length ? <span className="error" id={`${ids}-${form}-${name}-error`}>{reasons.join(" ")}</span> : null;
  };
  const invalid = (form: string, name: string) => errors.form === form && !!errors.fields[name];

  const calling = inCall.includes(state);
  const blocked = phone && (!phone.allowed ? t("phoneNotAllowed") : phone.paused ?? (!phone.priced ? t("phoneNotPriced") : null));

  return (
    <section aria-labelledby={`${ids}-title`} className="phone">
      <h1 id={`${ids}-title`}>{t("phoneTitle")}</h1>
      <p className="muted">{t("phoneHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {notice && <p role="status">{notice}</p>}
      {!phone && !failure && <p className="muted">{t("loading")}</p>}
      {phone && blocked && <p role="status" className="note">{blocked}</p>}
      {phone && !blocked && phone.callerIds.length === 0 && (
        <p className="note">
          {t("phoneNoNumbers")} <Link to="/console/numbers">{t("numbersTitle")}</Link>
        </p>
      )}
      {phone && !blocked && phone.callerIds.length > 0 && (
        <>
          <form onSubmit={place} className="phone-dial">
            <label className="field">
              <span>{t("phoneFrom")}</span>
              <select value={callerId} onChange={(e) => setCallerId(e.target.value)} disabled={calling}
                aria-invalid={invalid("call", "callerId")} aria-describedby={invalid("call", "callerId") ? `${ids}-call-callerId-error` : undefined}>
                {phone.callerIds.map((c) => (
                  <option key={c.id} value={c.id}>{c.number}</option>
                ))}
              </select>
            </label>
            {fieldError("call", "callerId")}
            <label className="field">
              <span>{t("phoneToCall")}</span>
              <input type="tel" inputMode="tel" autoComplete="off" placeholder="+14155550100" value={number} disabled={calling}
                onChange={(e) => setNumber(e.target.value)}
                aria-invalid={invalid("call", "number")} aria-describedby={invalid("call", "number") ? `${ids}-call-number-error` : undefined} />
            </label>
            {fieldError("call", "number")}
            {phone.consentRequired && <p className="muted"><small>{t("phoneConsentNeeded")}</small></p>}
            <p className="muted"><small>{t("phoneRecorded")}</small></p>
            {!calling && (
              <button type="submit" disabled={busy !== null || number.trim() === ""}>
                {busy === "call" ? t("phonePlacing") : t("phoneCall")}
              </button>
            )}
          </form>
          <p role="status" className={`call-state-${state}`}>{t(stateText[state])}</p>
          {calling && (
            <div className="phone-controls">
              <button type="button" className="secondary" onClick={toggleMute} aria-pressed={muted}>
                {muted ? t("phoneUnmute") : t("phoneMute")}
              </button>
              <button type="button" onClick={() => void hangUp()}>{t("hangUp")}</button>
            </div>
          )}
          {calling && phone.transferAllowed && (
            <form onSubmit={transfer} className="inline-form">
              <label className="field">
                <span>{t("phoneTransferTo")}</span>
                <input type="tel" inputMode="tel" autoComplete="off" value={transferTo} onChange={(e) => setTransferTo(e.target.value)}
                  aria-invalid={invalid("transfer", "to")} aria-describedby={invalid("transfer", "to") ? `${ids}-transfer-to-error` : undefined} />
              </label>
              <button type="submit" className="secondary" disabled={busy !== null || transferTo.trim() === ""}>
                {busy === "transfer" ? t("phoneTransferring") : t("phoneTransfer")}
              </button>
              {fieldError("transfer", "to")}
            </form>
          )}
        </>
      )}
      {callId && <CallWork callId={callId} />}
      <section aria-labelledby={`${ids}-mine`}>
        <h2 id={`${ids}-mine`}>{t("phoneMine")}</h2>
        {mine && mine.calls.length === 0 && <p className="muted">{t("phoneMineEmpty")}</p>}
        {mine && mine.calls.length > 0 && (
          <ul className="phone-mine">
            {mine.calls.map((c) => (
              <li key={c.id}>
                <Link to={`/console/calls/${c.id}`}>{c.to ?? "—"}</Link>{" "}
                <small className="muted">
                  {[c.startedAt ? new Date(c.startedAt).toLocaleString() : "", durationText(c.durationSec), outcomeText(c.outcome), c.wrapup ?? ""].filter((part) => part !== "").join(" · ")}
                </small>
              </li>
            ))}
          </ul>
        )}
        {mine && mine.total > mine.calls.length && <Link to="/console/calls?placedBy=me">{t("phoneMineAll")}</Link>}
      </section>
    </section>
  );
}
