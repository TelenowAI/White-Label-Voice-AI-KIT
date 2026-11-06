/**
 * Admin → Catalog → Realtime voices (contract/kit-api.yaml getCatalogCuration `realtime`, saveCatalogCuration `realtime`): which realtime voice providers customers may
 * pick, and which of their models, each under a name of the operator's (one that carries the platform's own is flagged and cannot be offered), with how many voices each
 * model has. While the voice platform has realtime voices off the page says so: customers pick none then, and the choices are kept for when it turns them on. A realtime
 * call's engine is charged as its language model, which the help says.
 *
 * Used by admin/Catalog.tsx, which saves it with the rest. Not here: an agent's realtime voice (agents/RealtimeFields.tsx).
 */
import type { CatalogCuration, CurationInput } from "../api";
import { t } from "../text";

type Named = { offered: boolean; name: string };
export type RealtimeForm = Record<string, Named & { models: Record<string, Named> }>;

export function realtimeFormOf(c: CatalogCuration): RealtimeForm {
  return Object.fromEntries(c.realtime.providers.map((p) => [p.id, {
    offered: p.offered,
    name: p.ownName ?? "",
    models: Object.fromEntries(p.models.map((m) => [m.id, { offered: m.offered, name: m.ownName ?? "" }])),
  }]));
}

export function realtimeBody(form: RealtimeForm): NonNullable<CurationInput["realtime"]> {
  return Object.fromEntries(Object.entries(form).map(([id, c]) => [id, {
    offered: c.offered,
    name: c.name.trim() || null,
    models: Object.fromEntries(Object.entries(c.models).map(([m, mc]) => [m, { offered: mc.offered, name: mc.name.trim() || null }])),
  }]));
}

export function RealtimeCuration({ data, form, onChange, errors }: {
  data: CatalogCuration;
  form: RealtimeForm;
  onChange: (next: RealtimeForm) => void;
  errors: Record<string, string[]>;
}) {
  const carries = (name: string) => name.toLowerCase().includes(data.platformName.toLowerCase());
  const error = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  const choose = (id: string, change: Partial<Named>) => onChange({ ...form, [id]: { ...form[id], ...change } });
  const chooseModel = (id: string, model: string, change: Partial<Named>) =>
    onChange({ ...form, [id]: { ...form[id], models: { ...form[id].models, [model]: { ...form[id].models[model], ...change } } } });

  return (
    <fieldset>
      <legend>{t("catalogRealtime")}</legend>
      <small className="muted">{t("catalogRealtimeHelp")}</small>
      {!data.realtime.enabled && <p className="note">{t("catalogRealtimeOff")}</p>}
      {data.realtime.providers.length > 0 && (
        <div className="table-scroll">
          <table className="list">
            <thead>
              <tr>
                <th scope="col">{t("colOffered")}</th>
                <th scope="col">{t("colProvider")}</th>
                <th scope="col">{t("colCustomerName")}</th>
                <th scope="col">{t("colModels")}</th>
              </tr>
            </thead>
            <tbody>
              {data.realtime.providers.map((p) => {
                const c = form[p.id];
                return (
                  <tr key={p.id}>
                    <td>
                      <input type="checkbox" aria-label={t("offerName", { name: p.catalogName })} checked={c.offered} onChange={(e) => choose(p.id, { offered: e.target.checked })} />
                    </td>
                    <td>{p.catalogName}</td>
                    <td>
                      <input aria-label={t("customerNameFor", { name: p.catalogName })} value={c.name} placeholder={p.catalogName} onChange={(e) => choose(p.id, { name: e.target.value })} />
                      {carries(c.name.trim() || p.catalogName) && <small className="error">{t("catalogNamesPlatform", { platform: data.platformName })}</small>}
                      {error(`realtime.${p.id}.name`)}
                    </td>
                    <td>
                      {p.models.map((m) => (
                        <div key={m.id} className="model-choice">
                          <input type="checkbox" aria-label={t("offerName", { name: m.catalogName })} checked={c.models[m.id]?.offered ?? true}
                            onChange={(e) => chooseModel(p.id, m.id, { offered: e.target.checked })} />
                          <input aria-label={t("customerNameFor", { name: m.catalogName })} value={c.models[m.id]?.name ?? ""} placeholder={m.catalogName}
                            onChange={(e) => chooseModel(p.id, m.id, { name: e.target.value })} />
                          <small className="muted">{t("catalogRealtimeVoices", { count: String(m.voiceCount) })}</small>
                          {carries(c.models[m.id]?.name.trim() || m.catalogName) && <small className="error">{t("catalogNamesPlatform", { platform: data.platformName })}</small>}
                          {error(`realtime.${p.id}.models.${m.id}.name`)}
                        </div>
                      ))}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </fieldset>
  );
}
