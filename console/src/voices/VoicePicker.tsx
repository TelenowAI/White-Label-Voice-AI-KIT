/**
 * Choosing one voice of a provider (an agent's voice setup, Admin → Catalog's default): a select of the first page of its
 * voices (contract/kit-api.yaml listVoices, listCatalogVoices), and, when they do not all fit, words and a language to find
 * one by and a way to show more. A provider's list runs to a thousand voices or more, so it is never loaded whole. The voice
 * chosen stays in the select whatever is narrowed. The account's own voices cloned on the provider, when given, come first as a group of their own, then (when asked
 * for) the member's starred voices of the provider.
 *
 * Not here: browsing voices (VoicesPage.tsx), saving the choice (the form it sits in).
 */
import { useEffect, useRef, useState } from "react";
import type { Voice, VoiceFilters, VoicePage } from "../api";
import { t } from "../text";
import { languageName } from "./languages";

/** How long typing pauses before the words are looked for. */
const TYPING_PAUSE_MS = 300;

function voiceLabel(v: Voice): string {
  return [v.name, languageName(v.language), v.gender].filter(Boolean).join(" · ");
}

export function VoicePicker({ id, label, emptyLabel, provider, value, savedLabel, load, onChange, error, own = [], starredFirst = false }: {
  id: string;
  label: string;
  emptyLabel: string;
  /** Whose voices; null: none to choose from yet. */
  provider: string | null;
  value: string | null;
  /** What to call `value` when it is not among the voices shown and was not picked here. */
  savedLabel: string | null;
  load: (f: VoiceFilters) => Promise<VoicePage>;
  onChange: (voice: string | null) => void;
  error?: string[];
  /** The account's own voices ready on this provider (OwnVoice `voice` and its name). */
  own?: { value: string; label: string }[];
  /** Show the member's starred voices of the provider first (listVoices `starred`). */
  starredFirst?: boolean;
}) {
  const [search, setSearch] = useState("");
  const [language, setLanguage] = useState("");
  const [list, setList] = useState<VoicePage | null>(null);
  const [picked, setPicked] = useState<Voice | null>(null);
  /** Whether the provider's voices do not all fit, as its whole list said: narrowing them never takes the finder away. */
  const [findable, setFindable] = useState(false);
  const [failure, setFailure] = useState<string | null>(null);
  const [stars, setStars] = useState<Voice[]>([]);
  const latest = useRef(0);

  useEffect(() => {
    if (!starredFirst || !provider) {
      setStars([]);
      return;
    }
    let live = true;
    load({ provider, starred: true }).then((p) => live && setStars(p.voices), () => live && setStars([]));
    return () => {
      live = false;
    };
  }, [provider, load, starredFirst]);

  useEffect(() => {
    setSearch("");
    setLanguage("");
    setList(null);
    setFindable(false);
  }, [provider]);

  /** The answer to the latest ask only: an older one that lands late is dropped. */
  const ask = (filters: VoiceFilters, use: (answer: VoicePage) => void) => {
    const mine = ++latest.current;
    load(filters).then(
      (answer) => {
        if (mine === latest.current) {
          use(answer);
          setFailure(null);
        }
      },
      (err) => {
        if (mine === latest.current) setFailure(err instanceof Error ? err.message : String(err));
      },
    );
  };

  const filters = (): VoiceFilters => ({ provider: provider ?? undefined, search: search.trim() || undefined, language: language || undefined });

  useEffect(() => {
    if (!provider) {
      latest.current++;
      return;
    }
    const whole = !search.trim() && !language;
    const timer = setTimeout(() => ask(filters(), (answer) => {
      setList(answer);
      if (whole) setFindable(answer.total > answer.perPage);
    }), search ? TYPING_PAUSE_MS : 0);
    return () => clearTimeout(timer);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [provider, search, language, load]);

  const more = () => {
    if (list) ask({ ...filters(), page: list.page + 1 }, (next) => setList({ ...next, voices: [...list.voices, ...next.voices] }));
  };

  const starIds = new Set(stars.map((v) => v.id));
  const voices = (list?.voices ?? []).filter((v) => !starIds.has(v.id));
  const kept = value !== null && !voices.some((v) => v.id === value) && !stars.some((v) => v.id === value) && !own.some((o) => o.value === value);

  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {findable && list !== null && (
        <div className="voice-find">
          <input type="search" aria-label={t("voiceFind")} placeholder={t("voiceFind")} value={search}
            onChange={(e) => setSearch(e.target.value)} onKeyDown={(e) => e.key === "Enter" && e.preventDefault()} />
          {list.languages.length > 1 && (
            <select aria-label={t("voiceLanguage")} value={language} onChange={(e) => setLanguage(e.target.value)}>
              <option value="">{t("anyOption")}</option>
              {list.languages.map((l) => (
                <option key={l} value={l}>{languageName(l)}</option>
              ))}
            </select>
          )}
        </div>
      )}
      <select id={id} value={value ?? ""} aria-invalid={error ? true : undefined}
        onChange={(e) => {
          setPicked([...voices, ...stars].find((v) => v.id === e.target.value) ?? picked);
          onChange(e.target.value || null);
        }}>
        <option value="">{emptyLabel}</option>
        {kept && <option value={value}>{picked?.id === value ? voiceLabel(picked) : savedLabel ?? value}</option>}
        {own.length > 0 && (
          <optgroup label={t("ownVoiceInAgent")}>
            {own.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </optgroup>
        )}
        {stars.length > 0 && (
          <optgroup label={t("voicesStarredGroup")}>
            {stars.map((v) => (
              <option key={v.id} value={v.id}>{voiceLabel(v)}</option>
            ))}
          </optgroup>
        )}
        {voices.map((v) => (
          <option key={v.id} value={v.id}>{voiceLabel(v)}</option>
        ))}
      </select>
      {list !== null && list.total > voices.length && (
        <small className="muted">
          {t("voicesShown", { shown: String(voices.length), total: String(list.total) })}{" "}
          <button type="button" className="link" onClick={more}>{t("showMoreVoices")}</button>
        </small>
      )}
      {failure && <small role="alert" className="error">{failure}</small>}
      {error && <small className="error">{error.join(" ")}</small>}
    </div>
  );
}
