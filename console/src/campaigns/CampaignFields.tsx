/**
 * The settings of a campaign, the same whether it is being made or changed after: its caller ID (one of the account's numbers), daily calling window and time zone, voicemail handling, attempts per person, how long
 * before trying again, how many calls at once. The choices, defaults and caps are the backend's (its limits), and the backend's word on a field shows beside it.
 *
 * Not here: making one (CampaignsPage.tsx), changing one (CampaignSettingsForm.tsx). Used by both.
 */
import type { Campaign, CampaignInput, CampaignLimits, PhoneNumber } from "../api";
import { t } from "../text";
import { voicemailWord } from "./words";

export interface CampaignFieldValues {
  callerId: string;
  windowStart: string;
  windowEnd: string;
  timezone: string;
  machineDetection: string;
  maxAttempts: string;
  retryGapMinutes: string;
  concurrency: string;
  retryOnNoAnswer: boolean;
}

/** What a new campaign starts from: the backend's defaults and the browser's own time zone. */
export function newCampaignFields(limits: CampaignLimits): CampaignFieldValues {
  return {
    callerId: "", windowStart: "", windowEnd: "", timezone: Intl.DateTimeFormat().resolvedOptions().timeZone, machineDetection: limits.machineDetection[0] ?? "",
    maxAttempts: String(limits.maxAttempts.default), retryGapMinutes: String(limits.retryGapMinutes.default), concurrency: String(limits.concurrency.default), retryOnNoAnswer: true,
  };
}

export function fieldsOf(c: Campaign): CampaignFieldValues {
  const s = c.settings;
  return {
    callerId: c.callerId?.id ?? "", windowStart: s.windowStart ?? "", windowEnd: s.windowEnd ?? "", timezone: s.timezone, machineDetection: s.machineDetection,
    maxAttempts: String(s.maxAttempts), retryGapMinutes: String(s.retryGapMinutes), concurrency: String(s.concurrency), retryOnNoAnswer: s.retryOnNoAnswer,
  };
}

export function fieldsToInput(v: CampaignFieldValues): Partial<CampaignInput> {
  return {
    callerId: v.callerId || null, windowStart: v.windowStart || null, windowEnd: v.windowEnd || null, timezone: v.timezone.trim() || null, machineDetection: v.machineDetection,
    maxAttempts: Number(v.maxAttempts), retryGapMinutes: Number(v.retryGapMinutes), concurrency: Number(v.concurrency), retryOnNoAnswer: v.retryOnNoAnswer,
  };
}

interface Props {
  values: CampaignFieldValues;
  onChange: (over: Partial<CampaignFieldValues>) => void;
  limits: CampaignLimits;
  numbers: PhoneNumber[];
  errors: Record<string, string[]>;
}

export function CampaignFields({ values, onChange, limits, numbers, errors }: Props) {
  const set = (key: keyof CampaignFieldValues) => (e: { target: { value: string } }) => onChange({ [key]: e.target.value });
  const error = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  return (
    <>
      <div className="field">
        <label htmlFor="campaign-caller">{t("campaignCallerId")}</label>
        <select id="campaign-caller" value={values.callerId} onChange={set("callerId")}>
          <option value="">{t("campaignNoCallerId")}</option>
          {numbers.map((n) => <option key={n.id} value={n.id}>{n.number}</option>)}
        </select>
        {error("callerId")}
      </div>
      <div className="inline-form">
        <div className="field">
          <label htmlFor="campaign-from">{t("campaignWindowStart")}</label>
          <input id="campaign-from" type="time" value={values.windowStart} onChange={set("windowStart")} />
          {error("windowStart")}
        </div>
        <div className="field">
          <label htmlFor="campaign-until">{t("campaignWindowEnd")}</label>
          <input id="campaign-until" type="time" value={values.windowEnd} onChange={set("windowEnd")} />
          {error("windowEnd")}
        </div>
        <div className="field">
          <label htmlFor="campaign-tz">{t("campaignTimezone")}</label>
          <input id="campaign-tz" value={values.timezone} onChange={set("timezone")} />
          {error("timezone")}
        </div>
      </div>
      <div className="field">
        <label htmlFor="campaign-voicemail">{t("campaignVoicemail")}</label>
        <select id="campaign-voicemail" value={values.machineDetection} onChange={set("machineDetection")}>
          {limits.machineDetection.map((v) => <option key={v} value={v}>{voicemailWord(v)}</option>)}
        </select>
        {error("machineDetection")}
      </div>
      <div className="inline-form">
        <div className="field field-narrow">
          <label htmlFor="campaign-attempts">{t("campaignAttempts")}</label>
          <input id="campaign-attempts" type="number" min={1} max={limits.maxAttempts.max} value={values.maxAttempts} onChange={set("maxAttempts")} />
          {error("maxAttempts")}
        </div>
        <div className="field field-narrow">
          <label htmlFor="campaign-gap">{t("campaignRetryGap")}</label>
          <input id="campaign-gap" type="number" min={limits.retryGapMinutes.min} max={limits.retryGapMinutes.max} aria-describedby="campaign-gap-help" value={values.retryGapMinutes} onChange={set("retryGapMinutes")} />
          {error("retryGapMinutes")}
        </div>
        <div className="field field-narrow">
          <label htmlFor="campaign-at-once">{t("campaignConcurrency")}</label>
          <input id="campaign-at-once" type="number" min={1} max={limits.concurrency.max} value={values.concurrency} onChange={set("concurrency")} />
          {error("concurrency")}
        </div>
      </div>
      <small id="campaign-gap-help" className="muted">{t("campaignRetryGapHelp")}</small>
      <label className="checkbox">
        <input type="checkbox" checked={values.retryOnNoAnswer} onChange={(e) => onChange({ retryOnNoAnswer: e.target.checked })} />
        {t("campaignRetry")}
      </label>
    </>
  );
}
