/**
 * Console → one agent → who on the team is emailed a summary of each finished call (contract/kit-api.yaml AgentSettings `emailAfterCall`). Only the account's own members can be, so the choices are its team.
 *
 * Not here: saving, which is AgentSettingsForm.tsx's one save; sending (App\Kit\Agents\AfterCallEmail).
 */
import { useEffect, useState } from "react";
import { kitApi, type TeamMember } from "../api";
import { t } from "../text";
import { refusals } from "./settingsRows";

export function AgentEmailFields({ recipients, onlyAnswered, errors, onRecipients, onOnlyAnswered }: {
  recipients: number[];
  onlyAnswered: boolean;
  errors: Record<string, string[]>;
  onRecipients: (ids: number[]) => void;
  onOnlyAnswered: (only: boolean) => void;
}) {
  const [members, setMembers] = useState<TeamMember[] | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    kitApi.team().then((team) => setMembers(team.members ?? []), (err: unknown) => setFailure(err instanceof Error ? err.message : String(err)));
  }, []);

  const said = refusals(errors, "settings.emailAfterCall");
  return (
    <>
      <h3>{t("settingsEmailAfterCall")}</h3>
      <small className="muted">{t("settingsEmailAfterCallHelp")}</small>
      {failure && <small className="error">{failure}</small>}
      {(members ?? []).filter((m) => m.email).map((m) => (
        <label className="checkbox" key={m.userId}>
          <input type="checkbox" checked={recipients.includes(m.userId)} onChange={(e) => onRecipients(e.target.checked ? [...recipients, m.userId] : recipients.filter((id) => id !== m.userId))} />
          {m.name} ({m.email})
        </label>
      ))}
      {recipients.length > 0 && (
        <label className="checkbox">
          <input type="checkbox" checked={onlyAnswered} onChange={(e) => onOnlyAnswered(e.target.checked)} />
          {t("settingsEmailOnlyAnswered")}
        </label>
      )}
      {said.length > 0 && <small className="error">{said.join(" ")}</small>}
    </>
  );
}
