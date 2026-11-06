/**
 * Changing a campaign's settings after it is made (updateCampaign): opened from its page, sends them all (the backend sends on only what changed), the backend's word beside a field, and says when it has not gone through yet.
 *
 * Not here: the fields (CampaignFields.tsx), the page (CampaignPage.tsx).
 */
import { useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type Campaign, type CampaignLimits, type PhoneNumber } from "../api";
import { t } from "../text";
import { CampaignFields, fieldsOf, fieldsToInput } from "./CampaignFields";

interface Props {
  campaign: Campaign;
  limits: CampaignLimits;
  onSaved: (c: Campaign) => void;
  onClose: () => void;
}

export function CampaignSettingsForm({ campaign, limits, onSaved, onClose }: Props) {
  const [values, setValues] = useState(() => fieldsOf(campaign));
  const [numbers, setNumbers] = useState<PhoneNumber[]>([]);
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    kitApi.numbers().then((n) => setNumbers(n.numbers.filter((x) => !x.releasing)), () => undefined);
  }, []);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setFailure(null);
    try {
      onSaved(await kitApi.updateCampaign(campaign.id, fieldsToInput(values)));
    } catch (err) {
      if (err instanceof KitApiError && Object.keys(err.fields).length > 0) setErrors(err.fields);
      else setFailure(err instanceof Error ? err.message : String(err));
      setBusy(false);
    }
  }

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("campaignSettingsTitle")}>
      <h2>{t("campaignSettingsTitle")}</h2>
      <p className="muted">{t("campaignSettingsHelp")}</p>
      <CampaignFields values={values} onChange={(over) => setValues((v) => ({ ...v, ...over }))} limits={limits} numbers={numbers} errors={errors} />
      {failure && <p role="alert" className="error">{failure}</p>}
      <div className="actions">
        <button type="submit" disabled={busy}>{busy ? t("saving") : t("campaignSave")}</button>
        <button type="button" className="secondary" disabled={busy} onClick={onClose}>{t("cancel")}</button>
      </div>
    </form>
  );
}
