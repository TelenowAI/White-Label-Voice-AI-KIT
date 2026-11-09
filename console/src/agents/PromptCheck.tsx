/**
 * The prompt check as it is typed (contract/kit-api.yaml checkPrompt, checkNewPrompt): an agent's instructions and opening
 * line checked as the voice platform checks them, a moment after typing stops — never on each keystroke, since a member may
 * check only so many a minute — and what it found shown at the field it is in, each with its line: errors (broken
 * conditional sections: a save of them is refused) and warnings (worth a look; they never stop a save). Too long to check is
 * said quietly; a check that cannot be made now (409, 429, 503) is said quietly too, and nothing else on the page changes.
 *
 * - usePromptCheck(): sends what it is given once typing stops, and keeps only the answer to the latest question.
 * - PromptFindings: one field's errors and warnings, or that nothing was found.
 *
 * Used by AgentPage.tsx (the instructions, and a new agent's opening line) and AgentSettingsForm.tsx (an agent's opening
 * lines). Not here: the preview with sample values (PromptPreview.tsx); the check itself (the backend's App\Kit\Agents\PromptCheck).
 */
import { useEffect, useRef, useState } from "react";
import type { PromptCheck, PromptCheckInput, PromptFinding } from "../api";
import { t } from "../text";

/** How long typing must stop before the check is sent. */
export const CHECK_AFTER_MS = 700;

export interface PromptCheckState {
  /** The latest answer; null before one, or once there is nothing to check. */
  check: PromptCheck | null;
  /** Why the latest check could not be made (the backend's message), said quietly. */
  failure: string | null;
}

/**
 * Checks `input` with `run` once it has stayed the same for CHECK_AFTER_MS; `input` null is nothing to check (not typed yet,
 * or emptied), which clears what was shown. An answer to an older question that lands late is dropped.
 */
export function usePromptCheck(run: (input: PromptCheckInput) => Promise<PromptCheck>, input: PromptCheckInput | null): PromptCheckState {
  const [state, setState] = useState<PromptCheckState>({ check: null, failure: null });
  const latest = useRef(0);
  const runner = useRef(run);
  runner.current = run;
  const asked = input === null ? null : JSON.stringify(input);

  useEffect(() => {
    const mine = ++latest.current;
    if (asked === null) {
      setState({ check: null, failure: null });
      return;
    }
    const timer = setTimeout(() => {
      runner.current(JSON.parse(asked) as PromptCheckInput).then(
        (check) => mine === latest.current && setState({ check, failure: null }),
        (err: unknown) => mine === latest.current && setState({ check: null, failure: err instanceof Error ? err.message : String(err) }),
      );
    }, CHECK_AFTER_MS);
    return () => clearTimeout(timer);
  }, [asked]);

  return state;
}

/** A finding with its line: the voice platform's own message already starts with it ("Line 2: …"), so it is not said twice. */
function withLine(f: PromptFinding): string {
  const label = t("promptCheckLine", { line: String(f.line), message: "" }).trim();
  return f.message.startsWith(label) ? f.message : t("promptCheckLine", { line: String(f.line), message: f.message });
}

/** What the check found in one field, errors first, each with its line; that nothing was found; or why it was not checked. */
export function PromptFindings({ state, field, id }: { state: PromptCheckState; field: PromptFinding["field"]; id: string }) {
  const { check, failure } = state;
  if (failure) return <p className="muted" id={id} data-testid={`${id}-failure`}>{failure}</p>;
  if (!check) return null;
  if (!check.checked) return field === "systemPrompt" ? <p className="muted" id={id}>{t("promptCheckTooLong")}</p> : null;
  const found = [
    ...check.errors.filter((f) => f.field === field).map((f) => ({ f, error: true })),
    ...check.warnings.filter((f) => f.field === field).map((f) => ({ f, error: false })),
  ];
  if (found.length === 0) return <p className="muted" id={id}>{t("promptCheckClean")}</p>;
  return (
    <ul className="checks" id={id} data-testid={id}>
      {found.map(({ f, error }, i) => (
        <li key={i} className={`check ${error ? "check-blocking" : "check-warning"}`} data-finding={error ? "error" : "warning"}>
          <span><span className="badge">{t(error ? "promptCheckError" : "promptCheckWarning")}</span> {withLine(f)}</span>
        </li>
      ))}
    </ul>
  );
}
