/**
 * Admin → Website (`/admin/website`; contract/kit-api.yaml getWebsiteSettings, saveWebsiteSettings): the public
 * website's content, saved as a whole — the texts and hero image, the home page's sections in order (each on or off),
 * the features, the plans the pricing page shows with the operator's words for each (none: the default plan), the
 * contact text and the legal pages. The backend checks it and answers what is wrong, field by field; every message is
 * also listed at the top, so none is lost when its field is not on screen.
 *
 * Not here: the pages themselves (the backend's theme, laravel/resources/views/theme), the brand (the setup wizard).
 */
import { useEffect, useState, type FormEvent } from "react";
import { KitApiError, kitApi, type WebsiteContent, type WebsiteSettings } from "../api";
import { t } from "../text";

export function WebsitePage() {
  const [settings, setSettings] = useState<WebsiteSettings | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    kitApi.websiteSettings().then(setSettings, (err) => setFailure(err instanceof Error ? err.message : String(err)));
  }, []);

  return (
    <section aria-labelledby="website-title">
      <h1 id="website-title">{t("websiteTitle")}</h1>
      <p className="muted">{t("websiteHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      {settings && (
        <>
          <p>
            <a href={settings.siteUrl} target="_blank" rel="noreferrer" className="wrap-anywhere">{t("websiteView")}</a>
            {settings.childTheme && <> · <span className="muted">{t("websiteChildTheme", { name: settings.childTheme })}</span></>}
          </p>
          <WebsiteForm settings={settings} onSaved={setSettings} />
        </>
      )}
    </section>
  );
}

/** Move item `i` one place up. */
function up<T>(list: T[], i: number): T[] {
  return i <= 0 ? list : [...list.slice(0, i - 1), list[i], list[i - 1], ...list.slice(i + 1)];
}

function WebsiteForm({ settings, onSaved }: { settings: WebsiteSettings; onSaved: (s: WebsiteSettings) => void }) {
  const [content, setContent] = useState<WebsiteContent>(settings.content);
  const [adding, setAdding] = useState("");
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [saved, setSaved] = useState(false);
  const [busy, setBusy] = useState(false);
  const set = (over: Partial<WebsiteContent>) => {
    setContent((c) => ({ ...c, ...over }));
    setSaved(false);
  };
  const short = settings.textMaxLength;
  const long = settings.longTextMaxLength;
  const labelOf = (choices: { value: string; label: string }[], value: string) => choices.find((c) => c.value === value)?.label ?? value;
  const unchosen = settings.planChoices.filter((p) => !content.plans.some((c) => c.id === p.value));

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setErrors({});
    setSaved(false);
    try {
      const next = await kitApi.saveWebsiteSettings(content);
      onSaved(next);
      setContent(next.content);
      setSaved(true);
    } catch (err) {
      setErrors(err instanceof KitApiError && Object.keys(err.fields).length > 0 ? err.fields : { form: [err instanceof Error ? err.message : String(err)] });
    } finally {
      setBusy(false);
    }
  }

  const said = (key: string) => errors[key] && <small className="error">{errors[key].join(" ")}</small>;
  const text = (key: "tagline" | "metaDescription" | "heroTitle" | "ctaTitle", label: Parameters<typeof t>[0]) => (
    <div className="field">
      <label htmlFor={`website-${key}`}>{t(label)}</label>
      <input id={`website-${key}`} value={content[key] ?? ""} maxLength={short} onChange={(e) => set({ [key]: e.target.value })} />
      {said(key)}
    </div>
  );
  const area = (key: "heroText" | "ctaText" | "pricingIntro" | "contactText", label: Parameters<typeof t>[0]) => (
    <div className="field">
      <label htmlFor={`website-${key}`}>{t(label)}</label>
      <textarea id={`website-${key}`} rows={3} value={content[key] ?? ""} maxLength={long} onChange={(e) => set({ [key]: e.target.value })} />
      {said(key)}
    </div>
  );
  const messages = Object.values(errors).flat();

  return (
    <form className="agent-form" onSubmit={submit} noValidate aria-label={t("websiteTitle")}>
      {messages.length > 0 && (
        <div role="alert" className="error">
          <p>{t("websiteNotSaved")}</p>
          <ul>{messages.map((m, i) => <li key={i}>{m}</li>)}</ul>
        </div>
      )}
      <p className="muted">{t("websitePlaceholders")}</p>

      <fieldset className="field">
        <legend>{t("websiteHero")}</legend>
        {text("heroTitle", "websiteHeroTitle")}
        {area("heroText", "websiteHeroText")}
        <div className="field">
          <label htmlFor="website-heroImage">{t("websiteHeroImage")}</label>
          <input id="website-heroImage" type="url" inputMode="url" spellCheck={false} value={content.heroImage ?? ""} maxLength={2048}
            onChange={(e) => set({ heroImage: e.target.value === "" ? null : e.target.value })} />
          {said("heroImage")}
        </div>
        {text("tagline", "websiteTagline")}
        {text("metaDescription", "websiteMetaDescription")}
      </fieldset>

      <fieldset className="field">
        <legend>{t("websiteSections")}</legend>
        <ol className="senders">
          {content.sections.map((s, i) => (
            <li key={s.id} data-section={s.id}>
              <div className="actions">
                <label className="checkbox">
                  <input type="checkbox" checked={s.on}
                    onChange={() => set({ sections: content.sections.map((x) => (x.id === s.id ? { ...x, on: !x.on } : x)) })} />
                  {labelOf(settings.sectionChoices, s.id)}
                </label>
                {i > 0 && <button type="button" className="secondary" onClick={() => set({ sections: up(content.sections, i) })}>{t("moveUp")}</button>}
              </div>
            </li>
          ))}
        </ol>
        {said("sections")}
      </fieldset>

      <fieldset className="field">
        <legend>{t("websiteFeatures")}</legend>
        <ol className="senders">
          {content.features.map((f, i) => (
            <li key={i} data-feature={i}>
              <div className="field">
                <label htmlFor={`website-feature-${i}-title`}>{t("websiteFeatureTitle")}</label>
                <input id={`website-feature-${i}-title`} value={f.title} maxLength={short}
                  onChange={(e) => set({ features: content.features.map((x, j) => (j === i ? { ...x, title: e.target.value } : x)) })} />
                {said(`features.${i}.title`)}
              </div>
              <div className="field">
                <label htmlFor={`website-feature-${i}-text`}>{t("websiteFeatureText")}</label>
                <textarea id={`website-feature-${i}-text`} rows={2} value={f.text ?? ""} maxLength={long}
                  onChange={(e) => set({ features: content.features.map((x, j) => (j === i ? { ...x, text: e.target.value } : x)) })} />
              </div>
              <div className="actions">
                {i > 0 && <button type="button" className="secondary" onClick={() => set({ features: up(content.features, i) })}>{t("moveUp")}</button>}
                <button type="button" className="secondary" onClick={() => set({ features: content.features.filter((_, j) => j !== i) })}>{t("remove")}</button>
              </div>
            </li>
          ))}
        </ol>
        {content.features.length < settings.maxFeatures ? (
          <button type="button" className="secondary" onClick={() => set({ features: [...content.features, { title: "", text: "" }] })}>{t("websiteAddFeature")}</button>
        ) : (
          <small className="muted">{t("websiteFeaturesFull", { max: String(settings.maxFeatures) })}</small>
        )}
        {said("features")}
      </fieldset>

      <fieldset className="field">
        <legend>{t("websitePricing")}</legend>
        {area("pricingIntro", "websitePricingIntro")}
        <p className="muted">{t("websitePlansHelp")}</p>
        <ol className="senders">
          {content.plans.map((p, i) => (
            <li key={p.id} data-plan={p.id}>
              <strong>{labelOf(settings.planChoices, p.id)}</strong>
              <div className="field">
                <label htmlFor={`website-plan-${i}-headline`}>{t("websitePlanHeadline")}</label>
                <input id={`website-plan-${i}-headline`} value={p.headline ?? ""} maxLength={short}
                  onChange={(e) => set({ plans: content.plans.map((x, j) => (j === i ? { ...x, headline: e.target.value } : x)) })} />
              </div>
              <div className="field">
                <label htmlFor={`website-plan-${i}-note`}>{t("websitePlanNote")}</label>
                <textarea id={`website-plan-${i}-note`} rows={2} value={p.note ?? ""} maxLength={long}
                  onChange={(e) => set({ plans: content.plans.map((x, j) => (j === i ? { ...x, note: e.target.value } : x)) })} />
              </div>
              {said(`plans.${i}.id`)}
              <div className="actions">
                {i > 0 && <button type="button" className="secondary" onClick={() => set({ plans: up(content.plans, i) })}>{t("moveUp")}</button>}
                <button type="button" className="secondary" onClick={() => set({ plans: content.plans.filter((_, j) => j !== i) })}>{t("remove")}</button>
              </div>
            </li>
          ))}
        </ol>
        {unchosen.length > 0 && content.plans.length < settings.maxPlans && (
          <div className="field">
            <label htmlFor="website-plan-add">{t("websiteAddPlan")}</label>
            <div className="actions">
              <select id="website-plan-add" value={adding} onChange={(e) => setAdding(e.target.value)}>
                <option value="">{t("websitePickPlan")}</option>
                {unchosen.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
              </select>
              <button type="button" className="secondary" disabled={adding === ""}
                onClick={() => {
                  set({ plans: [...content.plans, { id: adding, headline: "", note: "" }] });
                  setAdding("");
                }}>
                {t("websiteShowPlan")}
              </button>
            </div>
          </div>
        )}
        {said("plans")}
      </fieldset>

      <fieldset className="field">
        <legend>{t("websiteCta")}</legend>
        {text("ctaTitle", "websiteCtaTitle")}
        {area("ctaText", "websiteCtaText")}
      </fieldset>
      {area("contactText", "websiteContactText")}

      <fieldset className="field">
        <legend>{t("websiteLegal")}</legend>
        <p className="muted">{t("websiteLegalHelp")}</p>
        {settings.legalPages.map((page) => (
          <div className="field" key={page.value}>
            <label htmlFor={`website-legal-${page.value}`}>{page.label}</label>
            <textarea id={`website-legal-${page.value}`} rows={8} value={content.legal[page.value] ?? ""} maxLength={long}
              onChange={(e) => set({ legal: { ...content.legal, [page.value]: e.target.value } })} />
            {said(`legal.${page.value}`)}
          </div>
        ))}
        {said("legal")}
      </fieldset>

      {saved && <p role="status">{t("siteSaved")}</p>}
      <button type="submit" disabled={busy}>{busy ? t("saving") : t("websiteSave")}</button>
    </form>
  );
}
