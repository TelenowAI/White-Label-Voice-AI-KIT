/**
 * A consent record's kind, scope and how it was given, in the console's words. A value the console has no word for shows as the backend sends it, underscores as spaces. Used by: ConsentPage.tsx, ConsentForms.tsx.
 */
import { t, type TextKey } from "../text";

const kinds: Record<string, TextKey> = { express_written: "consentKind_express_written", express_oral: "consentKind_express_oral", implied: "consentKind_implied", existing_business_relationship: "consentKind_existing_business_relationship" };
const scopes: Record<string, TextKey> = { marketing: "consentScope_marketing", transactional: "consentScope_transactional", service: "consentScope_service" };
const hows: Record<string, TextKey> = { web_form: "consentHow_web_form", verbal_recorded: "consentHow_verbal_recorded", ivr_dtmf: "consentHow_ivr_dtmf", paper: "consentHow_paper", import: "consentHow_import" };

function word(words: Record<string, TextKey>, value: string): string {
  return Object.hasOwn(words, value) ? t(words[value]) : value.replace(/_/g, " ");
}

export function kindWord(value: string): string {
  return word(kinds, value);
}

export function scopeWord(value: string): string {
  return word(scopes, value);
}

export function howWord(value: string): string {
  return word(hows, value);
}
