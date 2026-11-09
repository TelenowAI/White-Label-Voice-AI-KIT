/**
 * The days of a period of insights as bars (Console → Insights): each day one bar, as tall as its calls beside the busiest day's,
 * with its answered calls shaded in it, the first and last day beneath. Each bar says its day and figures in words (a title for the
 * pointer, text for screen readers), and the same figures fold out as a table. Plain elements, no drawing library.
 *
 * Not here: the period and the other figures (InsightsPage.tsx).
 * Used by InsightsPage.tsx.
 */
import type { InsightDay } from "../api";
import { t } from "../text";
import { countText, dayText, minutesText } from "./insightText";

/** A share of the chart's height, as a CSS length. */
function percent(part: number, whole: number): string {
  return whole > 0 ? `${(part / whole) * 100}%` : "0%";
}

export function DaysChart({ days }: { days: InsightDay[] }) {
  const busiest = Math.max(0, ...days.map((d) => d.calls));
  const first = days[0];
  const last = days[days.length - 1];

  return (
    <section aria-labelledby="insights-days">
      <h2 id="insights-days">{t("insightsDays")}</h2>
      <p className="muted">{t("insightsDaysLegend")}</p>
      <ol className="day-bars" aria-labelledby="insights-days">
        {days.map((d) => {
          const label = t("insightsDayBar", { day: dayText(d.day), calls: countText(d.calls), answered: countText(d.answered) });
          return (
            <li key={d.day} title={label} data-day={d.day}>
              <span className="day-bar" aria-hidden="true" style={{ height: percent(d.calls, busiest) }}>
                <span className="day-bar-answered" style={{ height: percent(d.answered, d.calls) }} />
              </span>
              <span className="visually-hidden">{label}</span>
            </li>
          );
        })}
      </ol>
      {first && last && (
        <p className="muted day-bars-ends" aria-hidden="true">
          <span>{dayText(first.day)}</span>
          <span>{dayText(last.day)}</span>
        </p>
      )}
      <details>
        <summary>{t("insightsDaysTable")}</summary>
        <div className="table-scroll">
          <table className="list" aria-label={t("insightsDays")}>
            <thead>
              <tr>
                <th scope="col">{t("colDay")}</th>
                <th scope="col">{t("colCalls")}</th>
                <th scope="col">{t("colAnswered")}</th>
                <th scope="col">{t("colMinutes")}</th>
              </tr>
            </thead>
            <tbody>
              {days.map((d) => (
                <tr key={d.day}>
                  <th scope="row">{dayText(d.day)}</th>
                  <td>{countText(d.calls)}</td>
                  <td>{countText(d.answered)}</td>
                  <td>{minutesText(d.seconds)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </section>
  );
}
