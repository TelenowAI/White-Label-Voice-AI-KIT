/**
 * Console → one agent → how it speaks and listens: the groups of settings the backend declares (contract/kit-api.yaml
 * getAgentSettingsForm), drawn with no setting named here, so one the backend adds needs no change. Each group folds away, since
 * most agents only need a few of them, and opens by itself when a refusal is inside it.
 *
 * Not here: saving, which is AgentSettingsForm.tsx's one save; what they do (App\Kit\Agents\AgentBehaviour).
 */
import type { AgentSettings, AgentSettingsGroup, SetupField } from "../api";
import { FieldInput } from "../setup/StepForm";

const stored = (settings: AgentSettings, name: string) => (settings as unknown as Record<string, unknown>)[name];

const shows = (f: SetupField, values: Record<string, string>) => !f.showWhen || f.showWhen.values.includes(values[f.showWhen.field] ?? "");

/** What each field holds to begin with: a ticked box is "1", as FieldInput has it, and nothing set is empty. */
export function behaviourValues(groups: AgentSettingsGroup[], settings: AgentSettings): Record<string, string> {
  const values: Record<string, string> = {};
  for (const f of groups.flatMap((g) => g.fields)) {
    const value = stored(settings, f.name);
    values[f.name] = f.type === "checkbox" ? (value === true ? "1" : "") : value === null || value === undefined ? "" : String(value);
  }
  return values;
}

/** What to save: the fields on show, a hidden one keeping what it has. A number that is not one goes as typed, for the backend to refuse. */
export function behaviourSettings(groups: AgentSettingsGroup[], values: Record<string, string>): Record<string, unknown> {
  const settings: Record<string, unknown> = {};
  for (const f of groups.flatMap((g) => g.fields)) {
    if (!shows(f, values)) continue;
    const value = (values[f.name] ?? "").trim();
    settings[f.name] = f.type === "checkbox" ? value === "1" : value === "" ? null : f.type === "number" && /^\d+$/.test(value) ? Number(value) : value;
  }
  return settings;
}

export function AgentBehaviourFields({ groups, values, errors, onChange }: {
  groups: AgentSettingsGroup[];
  values: Record<string, string>;
  errors: Record<string, string[]>;
  onChange: (name: string, value: string) => void;
}) {
  return (
    <>
      {groups.map((g) => (
        <details key={g.id} className="settings-group" open={g.fields.some((f) => errors[`settings.${f.name}`]) ? true : undefined}>
          <summary>
            <span>{g.title}</span>
            <small className="muted">{g.description}</small>
          </summary>
          {g.fields.filter((f) => shows(f, values)).map((f) => (
            <FieldInput key={f.name} stepId="behaviour" field={f} value={values[f.name] ?? ""} errors={errors[`settings.${f.name}`] ?? []} onChange={(v) => onChange(f.name, v)} />
          ))}
        </details>
      ))}
    </>
  );
}
