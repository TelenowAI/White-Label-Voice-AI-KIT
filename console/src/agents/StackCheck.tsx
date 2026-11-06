/**
 * Checking an agent's voice setup (contract/kit-api.yaml checkAgentStack): each part (language model, listening, voice) connected to now, said as working, not working
 * (and why, in the kit's words) or not checked, with when it was last checked. The last check comes with the agent, so it shows before anyone checks again.
 *
 * Used by agents/AgentPage.tsx. Not here: the setup itself (StackForm.tsx), a test call (TestCall.tsx).
 */
import { useState } from "react";
import { kitApi, type Agent, type StackCheck as Check } from "../api";
import { t, type TextKey } from "../text";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function StackCheck({ agent }: { agent: Agent }) {
  const [check, setCheck] = useState<Check | null>(agent.stackCheck);
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);

  async function run() {
    setBusy(true);
    setFailure(null);
    try {
      setCheck(await kitApi.checkAgentStack(agent.id));
    } catch (err) {
      setFailure(message(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <section aria-labelledby="stack-check-title">
      <h2 id="stack-check-title">{t("stackCheckTitle")}</h2>
      <p className="muted">{t("stackCheckHelp")}</p>
      <button type="button" className="secondary" disabled={busy} onClick={() => void run()}>{busy ? t("stackChecking") : t("stackCheckRun")}</button>
      {failure && <p role="alert" className="error">{failure}</p>}
      {check && (
        <>
          <p className="muted">{t("stackCheckedAt", { when: new Date(check.checkedAt).toLocaleString() })}</p>
          <ul className="plain stack-check" aria-label={t("stackCheckTitle")}>
            {check.parts.map((p) => (
              <li key={p.part} className={`stack-check-${p.status}`}>
                <strong>{t(`stackPart_${p.part}` as TextKey)}</strong>: {t(`stackStatus_${p.status}` as TextKey)}
                {p.reason && <span className="muted"> · {p.reason}</span>}
              </li>
            ))}
          </ul>
        </>
      )}
    </section>
  );
}
