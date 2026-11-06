/**
 * Console → Voices (`/console/voices`; contract/kit-api.yaml listVoices, previewVoice, getStackChoices): the voices of
 * every voice provider the operator offers — narrowed by provider, language, gender and words, a page at a time — each
 * with what it is like, a button to hear it (a clip made on demand) and a star (the member's own, starVoice), and the starred ones on their own; above them, the account's
 * own voices (OwnVoices.tsx).
 *
 * Not here: choosing an agent's voice (agents/StackForm.tsx), which voices are offered (the operator's Catalog page).
 */
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { kitApi, type StackChoices, type Voice, type VoiceFilters, type VoicePage } from "../api";
import { t } from "../text";
import { ClipButton } from "./ClipButton";
import { OwnVoices } from "./OwnVoices";
import { languageName } from "./languages";

function message(err: unknown): string {
  return err instanceof Error ? err.message : String(err);
}

export function VoicesPage() {
  const [providers, setProviders] = useState<StackChoices["tts"]>([]);
  const [draft, setDraft] = useState<VoiceFilters>({});
  const [filters, setFilters] = useState<VoiceFilters>({});
  const [page, setPage] = useState(1);
  const [data, setData] = useState<VoicePage | null>(null);
  const [facets, setFacets] = useState<{ languages: string[]; genders: string[] } | null>(null);
  const [failure, setFailure] = useState<string | null>(null);

  useEffect(() => {
    kitApi.stackChoices().then((c) => setProviders(c.tts), (err) => setFailure(message(err)));
  }, []);

  const load = useCallback(async () => {
    try {
      const answer = await kitApi.voices({ ...filters, page });
      setData(answer);
      setFacets((f) => f ?? { languages: answer.languages, genders: answer.genders });
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }, [filters, page]);

  useEffect(() => {
    void load();
  }, [load]);

  async function toggleStar(v: Voice) {
    try {
      await (v.starred ? kitApi.unstarVoice(v.provider, v.id) : kitApi.starVoice(v.provider, v.id));
      setData((d) => d && { ...d, voices: d.voices.map((x) => (x.provider === v.provider && x.id === v.id ? { ...x, starred: !v.starred } : x)) });
      setFailure(null);
    } catch (err) {
      setFailure(message(err));
    }
  }

  function narrow(e: FormEvent) {
    e.preventDefault();
    setPage(1);
    setFilters({ ...draft, search: draft.search?.trim() || undefined });
  }

  const set = (key: keyof VoiceFilters) => (e: { target: { value: string } }) => setDraft((d) => ({ ...d, [key]: e.target.value || undefined }));
  const pages = data ? Math.max(1, Math.ceil(data.total / data.perPage)) : 1;
  const narrowed = Object.values(filters).some(Boolean);

  return (
    <section aria-labelledby="voices-title">
      <h1 id="voices-title">{t("voicesTitle")}</h1>
      <p className="muted">{t("voicesHelp")}</p>
      {failure && <p role="alert" className="error">{failure}</p>}
      <OwnVoices />
      <form className="inline-form" role="search" aria-label={t("voicesFilter")} onSubmit={narrow}>
        <div className="field">
          <label htmlFor="voices-provider">{t("voiceProvider")}</label>
          <select id="voices-provider" value={draft.provider ?? ""} onChange={set("provider")}>
            <option value="">{t("anyOption")}</option>
            {providers.map((p) => (
              <option key={p.key} value={p.key}>{p.name}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="voices-language">{t("voiceLanguage")}</label>
          <select id="voices-language" value={draft.language ?? ""} onChange={set("language")}>
            <option value="">{t("anyOption")}</option>
            {(facets?.languages ?? []).map((l) => (
              <option key={l} value={l}>{languageName(l)}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="voices-gender">{t("voiceGender")}</label>
          <select id="voices-gender" value={draft.gender ?? ""} onChange={set("gender")}>
            <option value="">{t("anyOption")}</option>
            {(facets?.genders ?? []).map((g) => (
              <option key={g} value={g}>{g}</option>
            ))}
          </select>
        </div>
        <div className="field">
          <label htmlFor="voices-search">{t("voiceSearch")}</label>
          <input id="voices-search" type="search" value={draft.search ?? ""} onChange={set("search")} />
        </div>
        <label className="checkbox">
          <input type="checkbox" checked={draft.starred === true} onChange={(e) => setDraft((d) => ({ ...d, starred: e.target.checked || undefined }))} />
          {t("voicesStarredOnly")}
        </label>
        <button type="submit" className="secondary">{t("search")}</button>
      </form>
      {data && data.voices.length === 0 && <p className="muted">{t(narrowed ? "voicesNoMatch" : "voicesNone")}</p>}
      {data && data.voices.length > 0 && (
        <div className="table-scroll">
          <table className="list" aria-label={t("voicesTitle")}>
            <thead>
              <tr>
                <th scope="col">{t("colVoice")}</th>
                <th scope="col">{t("voiceProvider")}</th>
                <th scope="col">{t("colLanguage")}</th>
                <th scope="col">{t("colGender")}</th>
                <th scope="col">{t("colAbout")}</th>
                <th scope="col">{t("colActions")}</th>
              </tr>
            </thead>
            <tbody>
              {data.voices.map((v) => (
                <tr key={`${v.provider}/${v.id}`}>
                  <td>
                    {v.name}
                    {v.recommended && <small className="muted"> · {t("recommendedVoice")}</small>}
                  </td>
                  <td>{v.providerName}</td>
                  <td>{languageName(v.language)}</td>
                  <td>{v.gender ?? ""}</td>
                  <td className="about">{[v.accent, v.style, v.description].filter(Boolean).join(" · ")}</td>
                  <td>
                    <ClipButton label={t("playVoice", { name: v.name })} load={() => kitApi.previewVoice(v.provider, v.id)} />
                    <button type="button" className="link star" aria-pressed={v.starred} aria-label={t(v.starred ? "voiceUnstar" : "voiceStar", { name: v.name })} onClick={() => void toggleStar(v)}>
                      {v.starred ? "★" : "☆"}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {data && pages > 1 && (
        <nav className="pager">
          <button type="button" className="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>
            {t("previousPage")}
          </button>
          <span>{t("pageOf", { page: String(page), pages: String(pages) })}</span>
          <button type="button" className="secondary" disabled={page >= pages} onClick={() => setPage(page + 1)}>
            {t("nextPage")}
          </button>
        </nav>
      )}
    </section>
  );
}
