/**
 * Applying the operator's brand to the page: every colour role becomes a CSS variable `--color-<role>` on the
 * document, and the brand name becomes the page title. Whatever roles the operator sets are applied — the console
 * styles read the variables, so a brand change needs no rebuild (plan §3.1).
 *
 * Not here: fetching the brand (api.ts), the styles that use the variables (styles.css).
 */
import type { Brand } from "./api";

/** A role name made safe for a CSS custom property: lower case letters, digits and dashes. */
export function cssRole(role: string): string {
  return role.trim().toLowerCase().replace(/[^a-z0-9-]+/g, "-").replace(/^-+|-+$/g, "");
}

export function applyBrand(brand: Brand, root: HTMLElement = document.documentElement, doc: Document = document): void {
  for (const [role, value] of Object.entries(brand.colors)) {
    const name = cssRole(role);
    if (name) root.style.setProperty(`--color-${name}`, value);
  }
  doc.title = brand.name;
}
