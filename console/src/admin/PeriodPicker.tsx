/**
 * The days an admin report covers (Admin → Dashboard, Cost vs price): from and to, both included, as the backend last
 * answered them; "Show" asks again. The backend's refusal (backwards, too long) shows by the field it names.
 *
 * Used by DashboardPage.tsx and CostsPage.tsx.
 */
import { useState, type FormEvent } from "react";
import type { ReportPeriod } from "../api";
import { t } from "../text";

export function PeriodPicker({ period, errors, onPick }: { period: ReportPeriod; errors: Record<string, string[]>; onPick: (from: string, to: string) => void }) {
  const [from, setFrom] = useState(period.from);
  const [to, setTo] = useState(period.to);

  // A period the backend answers anew replaces what is typed, here while rendering: an effect would also run once
  // after the picker first shows, and undo a date typed before it ran.
  const [answered, setAnswered] = useState(period);
  if (answered.from !== period.from || answered.to !== period.to) {
    setAnswered(period);
    setFrom(period.from);
    setTo(period.to);
  }

  function submit(e: FormEvent) {
    e.preventDefault();
    onPick(from, to);
  }

  return (
    <form className="period" onSubmit={submit} aria-label={t("periodLabel")}>
      <div className="field">
        <label htmlFor="period-from">{t("periodFrom")}</label>
        <input id="period-from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
        {errors.from && <small className="error">{errors.from.join(" ")}</small>}
      </div>
      <div className="field">
        <label htmlFor="period-to">{t("periodTo")}</label>
        <input id="period-to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        {errors.to && <small className="error">{errors.to.join(" ")}</small>}
      </div>
      <button type="submit" className="secondary">{t("periodShow")}</button>
    </form>
  );
}
