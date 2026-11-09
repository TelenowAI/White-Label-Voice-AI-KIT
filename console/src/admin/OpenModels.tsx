/**
 * Admin → Catalog → the models the operator adds to the language model provider that offers its whole list
 * (CuratedProvider `openModels`; today OpenRouter), beyond the catalog's (contract/kit-api.yaml listOpenModels,
 * getOpenModelPrice; saved by saveCatalogCuration `extraModels`):
 * - AddedModels: the added models, in that provider's model list after the catalog's own, each marked Added with the
 *   same offered switch and own-name field the catalog's models have, its id, a Remove, and the backend's refusal at
 *   its row (`kinds.llm.<provider>.extraModels.<model id>`) or at its name (`….name`). Model ids hold `/`, `.`, `:`
 *   and `~`: a refusal's key is matched whole, never split.
 * - OpenModels: the "Add models" area — the provider's own list, searched and a page at a time, each row its name and
 *   id, marked Added (as the form stands) or In the catalog (chosen in the catalog's list, never added here), an Add
 *   (none once as many are added as may be), and its price on asking: what the voice platform charges the operator,
 *   in US dollars per million tokens, an estimate, never shown to customers ("No price yet" when it has none). How
 *   many are added of the most, and the refusal of the whole set (`kinds.llm.<provider>.extraModels`). A list that
 *   cannot be read (409, 502) is a message in the area; the rest of the page stays.
 * - extraFormOf / extraBody: the form's added models from the catalog as read, and as Save sends them (every added
 *   model id → {offered, name}, `{}` for none).
 *
 * Used by Catalog.tsx, which keeps the form and sends it with the page's one Save. Not here: the catalog's own models
 * and the rest of the page (Catalog.tsx); what customers are charged (Admin → Pricing).
 */
import { useEffect, useId, useState } from "react";
import { kitApi, type CuratedLlmModel, type OpenModelPage, type OpenModelPrice } from "../api";
import { t } from "../text";

/** One added model as the form holds it: the operator's choices, and the list's name for it (its label and placeholder). */
export type ExtraChoice = { offered: boolean; name: string; listName: string };

/** The added models by the list's model id, in the order added. */
export type ExtraForm = Record<string, ExtraChoice>;

/** A change to the added models, from the form as it stands when React applies it. */
export type ExtraUpdate = (update: (extra: ExtraForm) => ExtraForm) => void;

/** The provider's prices per million tokens run to fractions of a cent (0.075): shown to as many places as they carry, up to this many. */
const PRICE_PLACES = 6;

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

function perMillion(usd: number | null): string {
  return usd === null ? "—" : new Intl.NumberFormat(undefined, { style: "currency", currency: "USD", maximumFractionDigits: PRICE_PLACES }).format(usd);
}

function without(extra: ExtraForm, id: string): ExtraForm {
  const rest = { ...extra };
  delete rest[id];
  return rest;
}

/** The added models of a provider as the catalog answered them (`added`), for the form. */
export function extraFormOf(models: CuratedLlmModel[]): ExtraForm {
  return Object.fromEntries(models.filter((m) => m.added).map((m) => [m.id, { offered: m.offered, name: m.ownName ?? "", listName: m.catalogName }]));
}

/** The added models as Save sends them: every one, its own name trimmed or null. */
export function extraBody(extra: ExtraForm): Record<string, { offered: boolean; name: string | null }> {
  return Object.fromEntries(Object.entries(extra).map(([id, c]) => [id, { offered: c.offered, name: c.name.trim() || null }]));
}

/** The added models in the provider's model list, after the catalog's own. */
export function AddedModels({ providerId, extra, onChange, errors }: {
  providerId: string;
  extra: ExtraForm;
  onChange: ExtraUpdate;
  errors: Record<string, string[]>;
}) {
  const refusal = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  return (
    <>
      {Object.entries(extra).map(([id, c]) => {
        const at = `kinds.llm.${providerId}.extraModels.${id}`;
        return (
          <div key={id} className="model-choice" data-added-model={id}>
            <input type="checkbox" aria-label={t("offerName", { name: c.listName })} checked={c.offered}
              onChange={(e) => {
                const offered = e.target.checked;
                onChange((x) => ({ ...x, [id]: { ...x[id], offered } }));
              }} />
            <input aria-label={t("customerNameFor", { name: c.listName })} value={c.name} placeholder={c.listName}
              aria-invalid={errors[`${at}.name`] ? true : undefined}
              onChange={(e) => {
                const name = e.target.value;
                onChange((x) => ({ ...x, [id]: { ...x[id], name } }));
              }} />
            <span className="badge">{t("openModelAdded")}</span>
            <code className="muted wrap-anywhere">{id}</code>
            <button type="button" className="secondary" aria-label={t("openModelRemoveName", { name: c.listName })}
              onClick={() => onChange((x) => without(x, id))}>
              {t("remove")}
            </button>
            {refusal(at)}
            {refusal(`${at}.name`)}
          </div>
        );
      })}
    </>
  );
}

type PriceRead = { reading?: boolean; price?: OpenModelPrice; failure?: string };

/** The "Add models" area of the provider that offers its whole list. */
export function OpenModels({ provider, extra, onChange, refused }: {
  /** The provider's name, as the catalog has it. */
  provider: string;
  extra: ExtraForm;
  onChange: ExtraUpdate;
  /** The refusal of the whole set of added models. */
  refused?: string[];
}) {
  const titleId = useId();
  const searchId = useId();
  const [typed, setTyped] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [list, setList] = useState<OpenModelPage | null>(null);
  const [failure, setFailure] = useState<string | null>(null);
  const [prices, setPrices] = useState<Record<string, PriceRead>>({});

  useEffect(() => {
    let current = true;
    kitApi.openModels(search, page).then(
      (read) => {
        if (!current) return;
        setList(read);
        setFailure(null);
      },
      (err: unknown) => {
        if (!current) return;
        setList(null);
        setFailure(message(err));
      },
    );
    return () => {
      current = false;
    };
  }, [search, page]);

  const count = Object.keys(extra).length;
  const full = list !== null && count >= list.max;
  const pages = list ? Math.max(1, Math.ceil(list.total / list.perPage)) : 1;

  function find() {
    setSearch(typed.trim());
    setPage(1);
  }

  async function seePrice(id: string) {
    setPrices((p) => ({ ...p, [id]: { reading: true } }));
    try {
      const price = await kitApi.openModelPrice(id);
      setPrices((p) => ({ ...p, [id]: { price } }));
    } catch (err) {
      setPrices((p) => ({ ...p, [id]: { failure: message(err) } }));
    }
  }

  function priceOf(id: string, name: string) {
    const read = prices[id];
    if (read?.price) {
      return read.price.found
        ? t("openModelPrice", { input: perMillion(read.price.inputPerM), output: perMillion(read.price.outputPerM) })
        : t("openModelNoPrice");
    }
    return (
      <>
        <button type="button" className="secondary" disabled={read?.reading === true} aria-label={t("openModelSeePriceOf", { name })}
          onClick={() => void seePrice(id)}>
          {read?.reading ? t("loading") : t("openModelSeePrice")}
        </button>
        {read?.failure && <small className="error"> {read.failure}</small>}
      </>
    );
  }

  return (
    <section className="open-models" aria-labelledby={titleId}>
      <h3 id={titleId}>{t("openModelsTitle", { provider })}</h3>
      <p className="muted">{t("openModelsHelp", { provider })}</p>
      <p data-testid="open-models-count">
        {list ? t("openModelsCount", { n: String(count), max: String(list.max) }) : t("openModelsCountNoMax", { n: String(count) })}
      </p>
      {full && <p className="note">{t("openModelsFull")}</p>}
      {refused && <p className="error" data-testid="open-models-refused">{refused.join(" ")}</p>}
      <div className="field">
        <label htmlFor={searchId}>{t("openModelsSearch", { provider })}</label>
        <div className="actions">
          <input id={searchId} type="search" value={typed} onChange={(e) => setTyped(e.target.value)}
            onKeyDown={(e) => {
              // Enter searches; it never submits the catalog's form around this area.
              if (e.key === "Enter") {
                e.preventDefault();
                find();
              }
            }} />
          <button type="button" className="secondary" onClick={find}>{t("search")}</button>
        </div>
      </div>
      {failure && <p className="error" data-testid="open-models-failure">{failure}</p>}
      {list && list.models.length === 0 && <p className="muted">{t("openModelsNone")}</p>}
      {list && list.models.length > 0 && (
        <>
          <p className="muted">{t("openModelsPriceHelp")}</p>
          <div className="table-scroll">
            <table className="list" aria-label={t("openModelsList", { provider })}>
              <thead>
                <tr>
                  <th scope="col">{t("colName")}</th>
                  <th scope="col">{t("openModelsColId")}</th>
                  <th scope="col">{t("openModelsColPrice")}</th>
                  <th scope="col"><span className="visually-hidden">{t("openModelsColAdd")}</span></th>
                </tr>
              </thead>
              <tbody>
                {list.models.map((m) => (
                  <tr key={m.id} data-model={m.id}>
                    <th scope="row">{m.name}</th>
                    <td><code className="wrap-anywhere">{m.id}</code></td>
                    <td>{priceOf(m.id, m.name)}</td>
                    <td>
                      {m.inCatalog ? (
                        <small className="muted">{t("openModelInCatalog")}</small>
                      ) : Object.hasOwn(extra, m.id) ? (
                        <span className="badge">{t("openModelAdded")}</span>
                      ) : (
                        <button type="button" className="secondary" disabled={full} aria-label={t("openModelAddName", { name: m.name })}
                          onClick={() => onChange((x) => ({ ...x, [m.id]: { offered: true, name: "", listName: m.name } }))}>
                          {t("openModelAdd")}
                        </button>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
      {list && pages > 1 && (
        <nav className="pager" aria-label={t("openModelsPages", { provider })}>
          <button type="button" className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t("previousPage")}
          </button>
          <span>{t("pageOf", { page: String(list.page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>
            {t("nextPage")}
          </button>
        </nav>
      )}
    </section>
  );
}
