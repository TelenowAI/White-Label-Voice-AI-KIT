/**
 * Console → one agent → sending its finished calls to a web address of the customer's (contract/kit-api.yaml listAgentWebhooks and the rest): adding and removing an address, its secret the one time
 * it can be seen, sending a test, and the latest tries. It saves as it goes, so it sits apart from the agent's settings form.
 *
 * Not here: who is emailed after a call (AgentEmailFields.tsx), what the backend does (App\Kit\Agents\AgentWebhooks).
 */
import { useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Agent, type AgentWebhook, type AgentWebhookDelivery } from "../api";
import { t, type TextKey } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

const eventWords: Record<string, TextKey> = { "call.completed": "webhookEventCompleted", "call.analyzed": "webhookEventAnalyzed" };

export function AgentWebhooks({ agent }: { agent: Agent }) {
  const [hooks, setHooks] = useState<AgentWebhook[] | null>(null);
  const [events, setEvents] = useState<string[]>([]);
  const [url, setUrl] = useState("");
  const [chosen, setChosen] = useState<string[]>([]);
  const [transcript, setTranscript] = useState(false);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [secret, setSecret] = useState<{ id: string; value: string } | null>(null);
  const [tries, setTries] = useState<Record<string, AgentWebhookDelivery[]>>({});

  function take(list: AgentWebhook[]) {
    setHooks(list);
    const shown = list.find((h) => h.signingSecret);
    if (shown?.signingSecret) setSecret({ id: shown.id, value: shown.signingSecret });
  }

  useEffect(() => {
    kitApi.agentWebhooks(agent.id).then((found) => {
      take(found.webhooks);
      setEvents(found.events);
      setChosen(found.events);
    }, (err: unknown) => setFailure(message(err)));
  }, [agent.id]);

  async function add(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      const made = await kitApi.addAgentWebhook(agent.id, { url: url.trim(), events: chosen, includeTranscript: transcript });
      take([...(hooks ?? []), made]);
      setUrl("");
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  const act = async (work: () => Promise<void>) => {
    setBusy(true);
    setFailure(null);
    try {
      await work();
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  };
  const remove = (hook: AgentWebhook) => act(async () => {
    await kitApi.removeAgentWebhook(agent.id, hook.id);
    setHooks((hooks ?? []).filter((h) => h.id !== hook.id));
    if (secret?.id === hook.id) setSecret(null);
  });
  const test = (hook: AgentWebhook) => act(async () => {
    const { deliveries } = await kitApi.testAgentWebhook(agent.id, hook.id, hook.events[0]);
    setTries({ ...tries, [hook.id]: deliveries });
  });
  const read = (hook: AgentWebhook) => act(async () => {
    const { deliveries } = await kitApi.agentWebhookDeliveries(agent.id, hook.id);
    setTries({ ...tries, [hook.id]: deliveries });
  });
  const again = (hook: AgentWebhook, delivery: AgentWebhookDelivery) => act(async () => {
    const { deliveries } = await kitApi.replayAgentWebhookDelivery(agent.id, hook.id, delivery.id);
    setTries({ ...tries, [hook.id]: deliveries });
  });

  return (
    <section aria-labelledby="agent-webhooks">
      <h2 id="agent-webhooks">{t("webhooks")}</h2>
      <p className="muted">{t("webhooksHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {(hooks ?? []).map((hook) => (
        <fieldset className="field" key={hook.id}>
          <legend className="wrap-anywhere">{hook.url}</legend>
          <p className="muted">{hook.events.map((e) => (eventWords[e] ? t(eventWords[e]) : e)).join(", ")}{hook.includeTranscript ? `, ${t("webhookWithTranscript")}` : ""}</p>
          {secret?.id === hook.id && (
            <div role="status">
              <p>{t("webhookSecretOnce")}</p>
              <code className="wrap-anywhere">{secret.value}</code>
            </div>
          )}
          {hook.syncState === "failed" && <p className="error">{hook.syncError}</p>}
          {hook.syncState === "pending" && <p className="muted">{hook.syncError ?? t("syncPending")}</p>}
          {hook.stopped && <p className="error">{t("webhookStopped")}</p>}
          {!hook.stopped && hook.failing && <p className="note">{t("webhookFailing")}</p>}
          {hook.lastOkAt && <p className="muted">{t("webhookLastOk", { when: new Date(hook.lastOkAt).toLocaleString() })}</p>}
          {hook.syncState === "synced" && (
            <>
              <button type="button" className="secondary" disabled={busy} onClick={() => void test(hook)}>{t("webhookTest")}</button>
              <button type="button" className="secondary" disabled={busy} onClick={() => void read(hook)}>{t("webhookTries")}</button>
            </>
          )}
          <button type="button" className="secondary" disabled={busy} onClick={() => void remove(hook)}>{t("webhookRemove")}</button>
          {tries[hook.id] && (tries[hook.id].length === 0
            ? <p className="muted">{t("webhookNoTries")}</p>
            : (
              <ul>
                {tries[hook.id].map((d) => (
                  <li key={d.id}>
                    <span>{d.at ? new Date(d.at).toLocaleString() : ""} {eventWords[d.event] ? t(eventWords[d.event]) : d.event}{d.kind === "test" ? ` (${t("webhookIsTest")})` : ""}: {d.status === "delivered" ? t("webhookDelivered") : d.problem ?? t("webhookWaiting")}</span>
                    {d.canSendAgain && <button type="button" className="secondary" disabled={busy} onClick={() => void again(hook, d)}>{t("webhookSendAgain")}</button>}
                  </li>
                ))}
              </ul>
            ))}
        </fieldset>
      ))}
      {hooks !== null && (
        <form onSubmit={add} noValidate aria-label={t("webhookAdd")}>
          <div className="field">
            <label htmlFor="webhook-url">{t("settingsToolUrl")}</label>
            <small className="muted">{t("settingsToolUrlHelp")}</small>
            <input id="webhook-url" value={url} onChange={(e) => setUrl(e.target.value)} aria-invalid={errors.url ? true : undefined} />
            {errors.url && <small className="error">{errors.url.join(" ")}</small>}
          </div>
          {events.map((event) => (
            <label className="checkbox" key={event}>
              <input type="checkbox" checked={chosen.includes(event)} onChange={(e) => setChosen(e.target.checked ? [...chosen, event] : chosen.filter((x) => x !== event))} />
              {eventWords[event] ? t(eventWords[event]) : event}
            </label>
          ))}
          {errors.events && <small className="error">{errors.events.join(" ")}</small>}
          <label className="checkbox">
            <input type="checkbox" checked={transcript} onChange={(e) => setTranscript(e.target.checked)} />
            {t("webhookIncludeTranscript")}
          </label>
          <button type="submit" disabled={busy}>{t("webhookAdd")}</button>
        </form>
      )}
    </section>
  );
}
