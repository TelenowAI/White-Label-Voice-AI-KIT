/**
 * Calls on now (contract/kit-api.yaml getLiveCalls), above the calls list: how many, by agent, and those placed from the browser, then the calls themselves with who is on the account's side and
 * the other number, and for a manager a way to listen in to each (ListenIn.tsx). Read again after the
 * time the backend says its figures stand (and after a failed read, once that long has passed), and only while the page is in view; when they cannot be
 * read the line says so and the list below is unchanged.
 *
 * Used by the calls list (Calls.tsx).
 */
import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { kitApi, type LiveCall, type LiveCalls } from "../api";
import { t } from "../text";
import { otherNumber } from "./callText";
import { ListenIn } from "./ListenIn";

/** Who is on the account's side of a live call. */
function who(c: LiveCall): string {
  if (c.agent) return c.agent.name;
  if (c.placedBy) return c.placedBy.me ? t("callYou") : (c.placedBy.name ?? t("formerMember"));
  return t("unknownAgent");
}

export function LiveNow() {
  const [live, setLive] = useState<LiveCalls | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let stopped = false;
    let timer: ReturnType<typeof setTimeout> | undefined;
    /** How long the backend last said its figures stand: also how long to wait after a failed read. */
    let every: number | null = null;
    const read = async () => {
      if (document.visibilityState === "visible") {
        try {
          const next = await kitApi.liveCalls();
          if (stopped) return;
          every = next.refreshSeconds;
          setLive(next);
          setFailed(false);
          timer = setTimeout(read, every * 1000);
          return;
        } catch {
          if (stopped) return;
          setFailed(true);
          if (every !== null) {
            timer = setTimeout(read, every * 1000);
            return;
          }
        }
      }
      // Hidden, or never read: look again once it is back in view.
      document.addEventListener("visibilitychange", again, { once: true });
    };
    const again = () => {
      if (!stopped) void read();
    };
    void read();
    return () => {
      stopped = true;
      if (timer) clearTimeout(timer);
      document.removeEventListener("visibilitychange", again);
    };
  }, []);

  if (failed && !live) return <p className="muted" role="status">{t("liveUnavailable")}</p>;
  if (!live) return null;
  return (
    <>
      <p className="live-now" role="status">
        <strong>{t("liveNow", { n: String(live.total) })}</strong>
        {live.agents.map((a) => (
          <span key={a.id}> · {t("liveAgent", { name: a.name, n: String(a.count) })}</span>
        ))}
        {live.others > 0 && <span> · {t("liveOthers", { n: String(live.others) })}</span>}
        {failed && <small className="muted"> · {t("liveStale")}</small>}
      </p>
      {live.calls.length > 0 && (
        <ul className="live-calls" aria-label={t("liveCallsTitle")}>
          {live.calls.map((c) => (
            <li key={c.id} data-call={c.id}>
              <Link to={`/console/calls/${c.id}`}>{who(c)}</Link> {otherNumber(c) ?? ""}{" "}
              {c.startedAt && <small className="muted">{t("liveCallSince", { when: new Date(c.startedAt).toLocaleTimeString() })}</small>}
              {c.canListen && <ListenIn callId={c.id} />}
            </li>
          ))}
        </ul>
      )}
    </>
  );
}
