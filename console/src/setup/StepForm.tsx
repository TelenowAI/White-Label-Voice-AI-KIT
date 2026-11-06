/**
 * One setup step, rendered from what the backend declares (contract/kit-api.yaml SetupStep): its findings
 * (checks), what it has saved (facts) and its form (fields). It knows no step by name, so a step the backend adds
 * needs no change here. A field with `showWhen` is shown, and sent, only while the other field has one of its
 * values. Refused fields show the backend's reasons beside them.
 *
 * Not here: which step is shown, signing in (SetupWizard, SignIn). FieldInput is also used by admin/EmailPage.tsx,
 * admin/PaymentsPage.tsx and agents/AgentBehaviourFields.tsx.
 */
import { useState, type FormEvent } from "react";
import { KitApiError, kitApi, type SetupField, type SetupState, type SetupStep } from "../api";
import { t } from "../text";

function initialValues(fields: SetupField[]): Record<string, string> {
  const values: Record<string, string> = {};
  for (const f of fields) values[f.name] = f.value ?? (f.type === "select" ? (f.options[0]?.value ?? "") : "");
  return values;
}

export function StepForm({ step, last, onSubmitted }: { step: SetupStep; last: boolean; onSubmitted: (state: SetupState) => void }) {
  const [values, setValues] = useState(() => initialValues(step.fields));
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const visible = (f: SetupField) => !f.showWhen || f.showWhen.values.includes(values[f.showWhen.field] ?? "");

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setMessage(null);
    const sent: Record<string, string> = {};
    for (const f of step.fields) if (visible(f)) sent[f.name] = values[f.name] ?? "";
    try {
      onSubmitted(await kitApi.submitStep(step.id, sent));
    } catch (err) {
      if (err instanceof KitApiError) {
        setErrors(err.fields);
        setMessage(Object.keys(err.fields).length ? null : err.message);
      } else {
        setMessage(err instanceof Error ? err.message : String(err));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="step" aria-labelledby={`step-${step.id}-title`}>
      <h2 id={`step-${step.id}-title`}>{step.title}</h2>
      <p className="muted">{step.description}</p>

      {step.checks.length > 0 && (
        <ul className="checks">
          {step.checks.map((c) => (
            <li key={c.id} className={`check check-${c.level}`} data-level={c.level}>
              <span>{c.label}</span>
              {c.detail && <small>{c.detail}</small>}
            </li>
          ))}
        </ul>
      )}

      {step.facts.length > 0 && (
        <dl className="facts">
          {step.facts.map((f) => (
            <div key={f.label}>
              <dt>{f.label}</dt>
              <dd>{f.value}</dd>
            </div>
          ))}
        </dl>
      )}

      {step.status === "blocked" && (
        <p className="note">
          {t("blockedNote")} <button type="button" onClick={() => window.location.reload()}>{t("reload")}</button>
        </p>
      )}

      {step.submittable && step.status !== "blocked" && (
        <form onSubmit={submit} noValidate>
          {step.fields.filter(visible).map((f) => (
            <FieldInput
              key={f.name}
              stepId={step.id}
              field={f}
              value={values[f.name] ?? ""}
              errors={errors[f.name] ?? []}
              onChange={(v) => setValues((old) => ({ ...old, [f.name]: v }))}
            />
          ))}
          {message && <p role="alert" className="error">{message}</p>}
          <button type="submit" disabled={busy}>
            {busy ? t("saving") : last ? t("finish") : t("saveAndContinue")}
          </button>
        </form>
      )}
    </section>
  );
}

/** One declared field, as the wizard draws it (also Admin → Email's sender forms); `stepId` prefixes its element id. */
export function FieldInput(props: { stepId: string; field: SetupField; value: string; errors: string[]; onChange: (v: string) => void }) {
  const { stepId, field: f, value, errors, onChange } = props;
  const id = `field-${stepId}-${f.name}`;
  const help = f.secretSet ? t("secretKept") : f.help;
  const described = [help ? `${id}-help` : null, errors.length ? `${id}-error` : null].filter(Boolean).join(" ") || undefined;
  const common = {
    id,
    name: f.name,
    value,
    required: f.required && !f.secretSet,
    "aria-invalid": errors.length > 0 || undefined,
    "aria-describedby": described,
  };
  const inputType = { text: "text", email: "email", password: "password", secret: "password", url: "url", color: "text", tel: "tel", number: "text" }[
    f.type as Exclude<SetupField["type"], "select" | "checkbox" | "textarea">
  ];
  // A help that is an address (a sign-up form's terms) is a link to it.
  const helpText = help && /^https?:\/\//i.test(help) ? <a href={help} target="_blank" rel="noopener noreferrer">{help}</a> : help;

  if (f.type === "checkbox") {
    return (
      <div className="field">
        <label className="checkbox" htmlFor={id}>
          <input id={id} name={f.name} type="checkbox" checked={value === "1"} required={f.required} aria-invalid={errors.length > 0 || undefined}
            aria-describedby={described} onChange={(e) => onChange(e.target.checked ? "1" : "")} />
          {f.label}
        </label>
        {help && <small id={`${id}-help`} className="muted">{helpText}</small>}
        {errors.length > 0 && <small id={`${id}-error`} className="error">{errors.join(" ")}</small>}
      </div>
    );
  }
  return (
    <div className="field">
      <label htmlFor={id}>{f.label}</label>
      {f.type === "select" ? (
        <select {...common} onChange={(e) => onChange(e.target.value)}>
          {f.options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </select>
      ) : f.type === "textarea" ? (
        <textarea {...common} rows={4} onChange={(e) => onChange(e.target.value)} />
      ) : (
        <span className="input-row">
          {f.type === "color" && <span className="swatch" style={{ background: value }} aria-hidden="true" />}
          <input
            {...common}
            type={inputType}
            autoComplete={f.type === "secret" || f.type === "password" ? "new-password" : f.type === "tel" ? "tel" : undefined}
            inputMode={f.type === "number" ? "numeric" : undefined}
            spellCheck={f.type === "color" || f.type === "secret" ? false : undefined}
            onChange={(e) => onChange(e.target.value)}
          />
        </span>
      )}
      {help && <small id={`${id}-help`} className="muted">{helpText}</small>}
      {errors.length > 0 && (
        <small id={`${id}-error`} className="error">
          {errors.join(" ")}
        </small>
      )}
    </div>
  );
}
