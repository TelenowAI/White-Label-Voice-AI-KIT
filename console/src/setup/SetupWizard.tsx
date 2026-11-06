/**
 * The setup wizard (contract/kit-api.yaml getSetup, submitSetupStep): the steps the backend lists, each with its
 * status, and the step the operator is on — the first that is not done, or a done one they picked to change. An
 * owner step needs the signed-in owner; anyone else is asked to sign in first. When the `finish` step answers
 * `installed`, the shell is told (onInstalled) and opens the admin panel.
 *
 * Not here: a step's form (StepForm), the routing around the wizard (App.tsx).
 */
import { useCallback, useEffect, useState } from "react";
import { kitApi, type Brand, type SetupState, type User } from "../api";
import { t } from "../text";
import { SignIn } from "./SignIn";
import { StepForm } from "./StepForm";

export function SetupWizard({ brand, onChanged, onInstalled }: { brand: Brand; onChanged: () => void; onInstalled: () => void }) {
  const [state, setState] = useState<SetupState | null>(null);
  const [user, setUser] = useState<User | null>(null);
  const [picked, setPicked] = useState<string | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  const take = useCallback(async (next: SetupState) => {
    setState(next);
    if (next.ownerExists) setUser((await kitApi.session()).user);
  }, []);

  useEffect(() => {
    kitApi.setup().then(take, (e: unknown) => setFailure(e instanceof Error ? e.message : String(e)));
  }, [take]);

  if (failure) return <p role="alert" className="error">{failure}</p>;
  if (!state) return <p aria-busy="true">{t("loading")}</p>;

  const firstOpen = state.steps.find((s) => s.status !== "done") ?? state.steps[state.steps.length - 1];
  const current = state.steps.find((s) => s.id === picked) ?? firstOpen;
  const reachable = (i: number) => i <= state.steps.indexOf(firstOpen);
  const needsOwner = current.access === "owner" && state.ownerExists && user?.role !== "owner";

  return (
    <div className="wizard">
      <h1>{t("setupTitle", { name: brand.name })}</h1>
      <div className="wizard-body">
        <nav aria-label={t("setupSteps")}>
          <ol className="steps">
            {state.steps.map((s, i) => (
              <li key={s.id} className={`step-item status-${s.status}`} aria-current={s.id === current.id ? "step" : undefined}>
                <button type="button" disabled={!reachable(i)} onClick={() => setPicked(s.id)}>
                  <span>{s.title}</span>
                  <small>{t(s.status === "done" ? "statusDone" : s.status === "blocked" ? "statusBlocked" : "statusTodo")}</small>
                </button>
              </li>
            ))}
          </ol>
        </nav>
        {needsOwner ? (
          <SignIn
            offer={[]}
            onSignedIn={async (u) => {
              setUser(u);
              await take(await kitApi.setup());
            }}
          />
        ) : (
          <StepForm
            key={`${current.id}:${current.status}:${user?.id ?? ""}`}
            step={current}
            last={current.id === state.steps[state.steps.length - 1].id}
            onSubmitted={async (next) => {
              setPicked(null);
              if (next.installed) return onInstalled();
              await take(next);
              onChanged();
            }}
          />
        )}
      </div>
    </div>
  );
}
