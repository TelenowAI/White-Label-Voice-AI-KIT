/**
 * One provider's own settings on an agent's voice setup (getStackChoices `settings`, updateAgent `providerSettings`): each drawn from what the backend offers for that
 * provider, so a setting the voice platform adds needs no change here: a choice, a number within its bounds, a switch, or text. Left empty, a setting is the provider's own
 * default, which it shows; the less common ones (`advanced`) sit under More settings. The backend's word on a setting shows beside it.
 *
 * Used by agents/StackForm.tsx. Not here: saving (the form it sits in), which settings are offered (the operator's Catalog page).
 */
import { useId } from "react";
import type { ProviderSetting, ProviderSettingValue } from "../api";
import { t } from "../text";

function shownDefault(s: ProviderSetting): string {
  if (s.default === null) return t("settingProviderDefault");
  if (s.kind === "select") return s.options?.find((o) => o.value === s.default)?.label ?? t("settingProviderDefault");
  if (s.kind === "toggle") return s.default ? t("settingOn") : t("settingOff");
  return String(s.default);
}

export function ProviderSettingsFields({ title, settings, values, onChange, errors, at }: {
  title: string;
  settings: ProviderSetting[];
  values: Record<string, ProviderSettingValue>;
  onChange: (key: string, value: ProviderSettingValue) => void;
  /** The backend's field errors, by path. */
  errors: Record<string, string[]>;
  /** The paths' start (providerSettings.tts). */
  at: string;
}) {
  const ids = useId();
  if (settings.length === 0) return null;
  const field = (s: ProviderSetting) => {
    const id = `${ids}-${s.key}`;
    const said = errors[`${at}.${s.key}`];
    const value = values[s.key] ?? null;
    const invalid = { "aria-invalid": said ? true : undefined, "aria-describedby": said ? `${id}-error` : s.help ? `${id}-help` : undefined };
    let control;
    if (s.kind === "select") {
      control = (
        <select id={id} value={typeof value === "string" ? value : ""} onChange={(e) => onChange(s.key, e.target.value || null)} {...invalid}>
          <option value="">{t("settingDefaultIs", { value: shownDefault(s) })}</option>
          {(s.options ?? []).map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
      );
    } else if (s.kind === "number") {
      control = (
        <input id={id} type="number" inputMode="decimal" min={s.min} max={s.max} step={s.step ?? "any"} placeholder={shownDefault(s)}
          value={typeof value === "number" ? value : ""} onChange={(e) => onChange(s.key, e.target.value === "" ? null : Number(e.target.value))} {...invalid} />
      );
    } else if (s.kind === "toggle") {
      control = (
        <select id={id} value={value === null ? "" : value ? "on" : "off"} onChange={(e) => onChange(s.key, e.target.value === "" ? null : e.target.value === "on")} {...invalid}>
          <option value="">{t("settingDefaultIs", { value: shownDefault(s) })}</option>
          <option value="on">{t("settingOn")}</option>
          <option value="off">{t("settingOff")}</option>
        </select>
      );
    } else if (s.kind === "textarea") {
      control = <textarea id={id} rows={3} value={typeof value === "string" ? value : ""} onChange={(e) => onChange(s.key, e.target.value === "" ? null : e.target.value)} {...invalid} />;
    } else {
      control = <input id={id} value={typeof value === "string" ? value : ""} onChange={(e) => onChange(s.key, e.target.value === "" ? null : e.target.value)} {...invalid} />;
    }

    return (
      <div className="field" key={s.key}>
        <label htmlFor={id}>{s.label}</label>
        {control}
        {s.help && <small className="muted" id={`${id}-help`}>{s.help}</small>}
        {said && <small className="error" id={`${id}-error`}>{said.join(" ")}</small>}
      </div>
    );
  };
  const common = settings.filter((s) => !s.advanced);
  const more = settings.filter((s) => s.advanced);

  return (
    <fieldset className="provider-settings">
      <legend>{title}</legend>
      {common.map(field)}
      {more.length > 0 && (
        <details>
          <summary>{t("settingsMore")}</summary>
          {more.map(field)}
        </details>
      )}
    </fieldset>
  );
}
