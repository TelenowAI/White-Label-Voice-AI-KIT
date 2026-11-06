/**
 * A language code as people read it, in the viewer's own language (`hi` → "Hindi"), from the browser's own names
 * (Intl.DisplayNames) — the console keeps no list of languages; an unknown code shows as it is.
 * Used by: voices/VoicesPage.tsx, agents/StackForm.tsx.
 */
export function languageName(code: string | null): string {
  if (!code) return "";
  try {
    return new Intl.DisplayNames(undefined, { type: "language" }).of(code) ?? code;
  } catch {
    return code;
  }
}
