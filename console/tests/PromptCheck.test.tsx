/**
 * The prompt check on an agent's page and the new-agent page (agents/PromptCheck.tsx, PromptPreview.tsx, AgentPage.tsx,
 * AgentSettingsForm.tsx), against a fake backend: the instructions are checked once typing stops — never before the pause, once
 * for a burst of typing, with only the last text — an agent's by checkPrompt with its instructions alone, a new agent's by
 * checkNewPrompt with its opening line too; errors and warnings are shown at the field they are in, each with its line, and never
 * at the other field; nothing found, too long to check and a check that cannot be made now are said quietly; an emptied field
 * clears what was shown and sends nothing. An agent's opening lines are checked each with its own line and the saved instructions.
 * A save refused for broken sections says why at the instructions. The preview, only on asking: the agent's values from its call
 * form and names the member adds, the call chosen, empty values left out (`{}` when none), and each section's branch counted from 1.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AgentCallForm, ConsoleHome, PromptCheck, PromptFinding, WorkspaceList } from "../src/api";
import { CHECK_AFTER_MS } from "../src/agents/PromptCheck";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit, type Sent } from "./fakeKit";
import { agentSettings, settingsForm } from "./agentSettings";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const nothingOffered = { llm: [], stt: [], tts: [], defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null } };
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/catalog": nothingOffered,
  "GET /api/kit/console/agents/settings-form": settingsForm,
  "GET /api/kit/console/agents/a1/webhooks": { webhooks: [], events: ["call.completed"] },
  "GET /api/kit/console/agents/a1/web-chat": { allowed: false, priced: true, enabled: false, websites: [], title: null, greeting: null, snippet: null },
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
  "GET /api/kit/console/starters": { starters: [] },
  "GET /api/kit/console/call-health?minutes=60": { windowMinutes: 60, callsAffected: 0, checkedAt: "2026-11-09T10:00:00Z", failures: [] },
};

const SAVED = 'You are the front desk.\n{#if {plan} == "gold"}\nOffer the lounge.\n{/if}';

function agent(over: Partial<Agent> = {}): Agent {
  return {
    id: "a1", name: "Front desk", description: null, systemPrompt: SAVED, isActive: true,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: agentSettings,
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [], logoUrl: null, syncState: "synced", syncError: null,
    createdAt: "2026-11-09T09:00:00Z", updatedAt: "2026-11-09T09:00:00Z", ...over,
  };
}

const finding = (field: PromptFinding["field"], line: number, message: string, code = "section_unclosed"): PromptFinding => ({ field, code, line, column: 1, message });

function check(over: Partial<PromptCheck> = {}): PromptCheck {
  return { checked: true, valid: true, errors: [], warnings: [], preview: null, ...over };
}

function open(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

const AGENT_CHECK = "/api/kit/console/agents/a1/prompt-check";
const NEW_CHECK = "/api/kit/console/agents/prompt-check";
const checks = (sent: Sent[]) => sent.filter((s) => s.method === "POST" && s.path.endsWith("/prompt-check"));
const pause = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
/** Long enough for a check to have gone after typing stopped, and its answer to be shown. */
const settled = { timeout: CHECK_AFTER_MS * 4 };
const typeIn = (label: string, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } });
const items = (id: string) => [...(document.getElementById(id)?.querySelectorAll("li") ?? [])].map((li) => li.textContent);

describe("the prompt check as the instructions are typed", () => {
  it("checks an agent's instructions only once typing stops, with the last text, and shows its errors and warnings there with their lines", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      [`POST ${AGENT_CHECK}`]: () => Response.json(check({
        valid: false,
        errors: [finding("systemPrompt", 3, "This section is never closed: end it with {/if}.")],
        warnings: [finding("systemPrompt", 1, "Is {tier} meant to be a value? Nothing sets it.", "section_unknown_var"),
          finding("greeting", 1, "An opening line is spoken as it is.", "greeting_section")],
      })),
    });
    open("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk" });
    expect(screen.getByText('To say something on some calls only: {#if {plan} == "gold"} … {#elif {plan} == "silver"} … {#else} … {/if}')).toBeTruthy();

    typeIn("Instructions", "You are");
    await pause(CHECK_AFTER_MS / 4);
    typeIn("Instructions", "You are the front desk.\n{#if {tier}");
    await pause(CHECK_AFTER_MS / 4);
    const last = 'You are the front desk.\n{#if {tier} == "gold"}\nOffer the lounge.';
    typeIn("Instructions", last);
    await pause(CHECK_AFTER_MS / 2);
    expect(checks(sent), "nothing goes before typing has stopped a moment").toHaveLength(0);

    await waitFor(() => expect(items("agent-systemPrompt-check")).toHaveLength(2), settled);
    expect(checks(sent).map((s) => [s.path, s.body])).toEqual([[AGENT_CHECK, { systemPrompt: last }]]);
    expect(items("agent-systemPrompt-check")).toEqual([
      "Fix before saving Line 3: This section is never closed: end it with {/if}.",
      "Worth a look Line 1: Is {tier} meant to be a value? Nothing sets it.",
    ]);
    expect(document.getElementById("agent-systemPrompt-check")?.querySelector('[data-finding="error"]')?.className).toContain("check-blocking");
    expect(screen.queryByText(/An opening line is spoken as it is/), "an opening line's finding is not the instructions'").toBeNull();
    await pause(CHECK_AFTER_MS * 1.5);
    expect(checks(sent), "one check for one burst of typing").toHaveLength(1);
  });

  it("says quietly that nothing was found, that it is too long to check, or that it cannot be checked now, and clears when emptied", async () => {
    const answers = [
      () => Response.json(check()),
      () => Response.json(check({ checked: false })),
      () => Response.json({ message: "Too many checks in a minute: wait a moment." }, { status: 429 }),
    ];
    const sent = fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent(), [`POST ${AGENT_CHECK}`]: () => answers[checks(sent).length - 1]() });
    open("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk" });

    typeIn("Instructions", "Be kind.");
    expect(await screen.findByText("No problems found.", undefined, settled)).toBeTruthy();
    typeIn("Instructions", "Be kind. ".repeat(3));
    expect(await screen.findByText("Too long to check as you type: it is checked when saved.", undefined, settled)).toBeTruthy();
    expect(screen.queryByText("No problems found.")).toBeNull();
    typeIn("Instructions", "Be kind, always.");
    const failure = await screen.findByTestId("agent-systemPrompt-check-failure", undefined, settled);
    expect(failure.textContent).toBe("Too many checks in a minute: wait a moment.");
    expect(failure.className).toBe("muted");
    expect(screen.queryByRole("alert"), "a check that cannot be made is no alarm").toBeNull();
    expect(screen.queryByText("Too long to check as you type: it is checked when saved.")).toBeNull();

    typeIn("Instructions", "   ");
    await waitFor(() => expect(screen.queryByTestId("agent-systemPrompt-check-failure")).toBeNull());
    await pause(CHECK_AFTER_MS * 1.5);
    expect(checks(sent).map((s) => (s.body as { systemPrompt: string }).systemPrompt)).toEqual(["Be kind.", "Be kind. ".repeat(3), "Be kind, always."]);
  });

  it("keeps only the answer to the latest text when an older one lands late", async () => {
    let release: () => void = () => undefined;
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      [`POST ${AGENT_CHECK}`]: (body: unknown) => {
        const text = (body as { systemPrompt: string }).systemPrompt;
        const answer = Response.json(check({ warnings: [finding("systemPrompt", 1, `About: ${text}`)] }));
        return text === "First." ? new Promise<Response>((resolve) => { release = () => resolve(answer); }) : answer;
      },
    });
    open("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk" });
    typeIn("Instructions", "First.");
    await waitFor(() => expect(checks(sent)).toHaveLength(1), settled);
    typeIn("Instructions", "Second.");
    await waitFor(() => expect(items("agent-systemPrompt-check")).toEqual(["Worth a look Line 1: About: Second."]), settled);
    release();
    await pause(50);
    expect(items("agent-systemPrompt-check")).toEqual(["Worth a look Line 1: About: Second."]);
  });

  it("checks a new agent's instructions and opening line together, as they would be saved, and shows each finding at its own field", async () => {
    const sent = fakeKit({
      ...signedIn,
      [`POST ${NEW_CHECK}`]: () => Response.json(check({
        valid: false,
        errors: [finding("systemPrompt", 2, "This {#else} has no {#if} before it.")],
        warnings: [finding("greeting", 1, "{name} is said as it is when the call has no name.", "missing_value")],
      })),
    });
    open("/console/agents/new");
    await screen.findByRole("heading", { name: "New agent" });
    typeIn("Instructions", "Hello.\n{#else}\nBye.");
    typeIn("Opening line", "  Hi {name}!  ");
    await waitFor(() => expect(items("agent-greeting-check")).toHaveLength(1), settled);
    expect(checks(sent).map((s) => [s.path, s.body])).toEqual([[NEW_CHECK, { systemPrompt: "Hello.\n{#else}\nBye.", greeting: "Hi {name}!" }]]);
    expect(items("agent-systemPrompt-check")).toEqual(["Fix before saving Line 2: This {#else} has no {#if} before it."]);
    expect(items("agent-greeting-check")).toEqual(["Worth a look Line 1: {name} is said as it is when the call has no name."]);
  });

  it("says a finding's line once when the platform's message already starts with it", async () => {
    // As the voice platform writes them (recorded: validatePrompt.unclosed, validatePrompt.near-miss).
    fakeKit({
      ...signedIn,
      [`POST ${NEW_CHECK}`]: () => Response.json(check({
        valid: false,
        errors: [finding("systemPrompt", 2, "Line 2: `{#if` is never closed — add `{/if}` where the section should end.")],
        warnings: [finding("systemPrompt", 4, "Line 4: `{{/if}}` is Handlebars syntax. Did you mean `{/if}`?", "section_near_miss"),
          finding("systemPrompt", 5, "Line breaks inside a section are kept as written.", "kept_breaks")],
      })),
    });
    open("/console/agents/new");
    await screen.findByRole("heading", { name: "New agent" });
    typeIn("Instructions", "Hello.\n{#if {plan} == \"gold\"}\nGold.\n{{/if}}");
    await waitFor(() => expect(items("agent-systemPrompt-check")).toHaveLength(3), settled);
    expect(items("agent-systemPrompt-check")).toEqual([
      "Fix before saving Line 2: `{#if` is never closed — add `{/if}` where the section should end.",
      "Worth a look Line 4: `{{/if}}` is Handlebars syntax. Did you mean `{/if}`?",
      "Worth a look Line 5: Line breaks inside a section are kept as written.",
    ]);
  });

  it("checks a new agent's opening line alone, with no instructions yet", async () => {
    const sent = fakeKit({ ...signedIn, [`POST ${NEW_CHECK}`]: () => Response.json(check()) });
    open("/console/agents/new");
    await screen.findByRole("heading", { name: "New agent" });
    typeIn("Opening line", "Hi there.");
    await waitFor(() => expect(checks(sent)).toHaveLength(1), settled);
    expect(checks(sent)[0].body).toEqual({ systemPrompt: null, greeting: "Hi there." });
  });

  it("checks each of an agent's opening lines with its own line and the saved instructions, showing only what is in that line", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      [`POST ${AGENT_CHECK}`]: (body: unknown) => {
        const greeting = (body as { greeting: string }).greeting;
        return Response.json(check({ warnings: [finding("greeting", 1, `Spoken as it is: ${greeting}`), finding("systemPrompt", 2, "In the saved instructions.")] }));
      },
    });
    open("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk" });
    typeIn("When it answers a call", "  Hello {#if {vip}}friend{/if}  ");
    await waitFor(() => expect(items("settings-greetingInbound-check")).toHaveLength(1), settled);
    expect(items("settings-greetingInbound-check")).toEqual(["Worth a look Line 1: Spoken as it is: Hello {#if {vip}}friend{/if}"]);
    expect(document.getElementById("settings-greetingOutbound-check"), "an untouched line is not checked").toBeNull();
    expect(screen.queryByText(/In the saved instructions/)).toBeNull();

    typeIn("When it places a call", "Calling from Acme.");
    await waitFor(() => expect(items("settings-greetingOutbound-check")).toHaveLength(1), settled);
    expect(items("settings-greetingOutbound-check")).toEqual(["Worth a look Line 1: Spoken as it is: Calling from Acme."]);
    expect(checks(sent).map((s) => s.body)).toEqual([
      { systemPrompt: SAVED, greeting: "Hello {#if {vip}}friend{/if}" },
      { systemPrompt: SAVED, greeting: "Calling from Acme." },
    ]);
  });
});

describe("a save refused for broken sections", () => {
  const refused = () => Response.json({
    message: "The instructions cannot be saved.",
    errors: { systemPrompt: ["Line 2: this section is never closed.", "Line 5: {/if} closes nothing."] },
  }, { status: 422 });

  it("says why at the instructions, for an agent and for a new one", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent(), "PATCH /api/kit/console/agents/a1": refused, "POST /api/kit/console/agents": refused });
    open("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk" });
    fireEvent.click(screen.getByRole("button", { name: "Save changes" }));
    const said = await screen.findByText("Line 2: this section is never closed. Line 5: {/if} closes nothing.");
    expect(said.id).toBe("agent-systemPrompt-error");
    expect(screen.getByLabelText("Instructions").getAttribute("aria-invalid")).toBe("true");
    cleanup();

    open("/console/agents/new");
    await screen.findByRole("heading", { name: "New agent" });
    typeIn("Name", "Night desk");
    fireEvent.click(screen.getByRole("button", { name: "Create agent" }));
    expect((await screen.findByText("Line 2: this section is never closed. Line 5: {/if} closes nothing.")).id).toBe("agent-systemPrompt-error");
  });
});

describe("the preview with sample values", () => {
  const form: AgentCallForm = {
    callerIds: [], ownNumber: null, variables: [{ name: "plan", required: true }, { name: "city", required: false }],
    machineAnswers: [], paused: null, limits: { numberMaxLength: 32, variableValueMaxLength: 500, openingLineMaxLength: 500 },
  };
  const preview = check({ preview: { text: "You are the front desk.\nOffer the lounge.", sections: [{ line: 2, branch: 1, branches: 2 }, { line: 6, branch: null, branches: 3 }] } });

  it("sends the agent's values given, names added, the call chosen, and shows the text and each section's branch counted from 1", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      "GET /api/kit/console/agents/a1/call-form": form,
      [`POST ${AGENT_CHECK}`]: () => Response.json(preview),
    });
    open("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk" });
    expect(sent.some((s) => s.path.endsWith("/call-form")), "nothing is read before asking").toBe(false);
    fireEvent.click(screen.getByRole("button", { name: "Preview with sample values" }));
    const region = await screen.findByRole("region", { name: "Preview" });
    const plan = await within(region).findByLabelText("plan");
    expect(within(region).getByText("needed on every call")).toBeTruthy();
    fireEvent.change(plan, { target: { value: "gold" } });
    fireEvent.change(within(region).getByLabelText("Another value, by name"), { target: { value: " {tier} " } });
    fireEvent.click(within(region).getByRole("button", { name: "Add" }));
    fireEvent.change(within(region).getByLabelText("tier"), { target: { value: "vip" } });
    fireEvent.change(within(region).getByLabelText("The call"), { target: { value: "phone_outbound" } });
    expect([...(within(region).getByLabelText("The call") as HTMLSelectElement).options].map((o) => o.textContent)).toEqual([
      "Any call", "A call it answers", "A call it places", "A web chat",
    ]);
    fireEvent.click(within(region).getByRole("button", { name: "Show the preview" }));
    expect((await within(region).findByTestId("prompt-preview-text")).textContent).toBe("You are the front desk.\nOffer the lounge.");
    expect([...within(region).getByTestId("prompt-preview-sections").querySelectorAll("li")].map((li) => li.textContent)).toEqual([
      "The section at line 2 kept branch 1 of 2.",
      "The section at line 6 kept none of its 3 branches.",
    ]);

    fireEvent.change(plan, { target: { value: "" } });
    fireEvent.change(within(region).getByLabelText("tier"), { target: { value: "  " } });
    fireEvent.change(within(region).getByLabelText("The call"), { target: { value: "" } });
    fireEvent.click(within(region).getByRole("button", { name: "Show the preview" }));
    await waitFor(() => expect(checks(sent)).toHaveLength(2));
    expect(checks(sent).map((s) => s.body)).toEqual([
      { systemPrompt: SAVED, values: { plan: "gold", tier: "vip" }, channel: "phone_outbound" },
      { systemPrompt: SAVED, values: {} },
    ]);
    expect(sent.filter((s) => s.path.endsWith("/call-form"))).toHaveLength(1);
  });

  it("says quietly when the preview cannot be made now", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      "GET /api/kit/console/agents/a1/call-form": form,
      [`POST ${AGENT_CHECK}`]: () => Response.json({ message: "The instructions cannot be checked now." }, { status: 503 }),
    });
    open("/console/agents/a1");
    await screen.findByRole("heading", { name: "Front desk" });
    fireEvent.click(screen.getByRole("button", { name: "Preview with sample values" }));
    const region = await screen.findByRole("region", { name: "Preview" });
    fireEvent.click(await within(region).findByRole("button", { name: "Show the preview" }));
    const said = await within(region).findByTestId("prompt-preview-failure");
    expect(said.textContent).toBe("The instructions cannot be checked now.");
    expect(said.className).toBe("muted");
    expect(screen.queryByRole("alert")).toBeNull();
    expect(within(region).queryByTestId("prompt-preview-text")).toBeNull();
  });

  it("previews a new agent's instructions with the names the member adds, and says when there is no preview", async () => {
    const sent = fakeKit({ ...signedIn, [`POST ${NEW_CHECK}`]: () => Response.json(check()) });
    open("/console/agents/new");
    await screen.findByRole("heading", { name: "New agent" });
    typeIn("Instructions", '{#if {plan} == "gold"}Gold{/if}');
    fireEvent.click(screen.getByRole("button", { name: "Preview with sample values" }));
    const region = await screen.findByRole("region", { name: "Preview" });
    expect(within(region).getByText("These instructions use no values the agent knows of yet. Add one by name to try it.")).toBeTruthy();
    fireEvent.change(within(region).getByLabelText("Another value, by name"), { target: { value: "plan" } });
    expect(fireEvent.keyDown(within(region).getByLabelText("Another value, by name"), { key: "Enter" }), "Enter's own job, sending the agent's form, is cancelled").toBe(false);
    fireEvent.change(within(region).getByLabelText("plan"), { target: { value: "gold" } });
    expect(fireEvent.keyDown(within(region).getByLabelText("plan"), { key: "Enter" }), "in a value too").toBe(false);
    fireEvent.change(within(region).getByLabelText("The call"), { target: { value: "chat" } });
    fireEvent.click(within(region).getByRole("button", { name: "Show the preview" }));
    expect(await within(region).findByText("No preview: these instructions are too long to check here.")).toBeTruthy();
    const previews = checks(sent).filter((s) => (s.body as { values?: unknown }).values !== undefined);
    expect(previews.map((s) => [s.path, s.body])).toEqual([[NEW_CHECK, { systemPrompt: '{#if {plan} == "gold"}Gold{/if}', values: { plan: "gold" }, channel: "chat" }]]);
    expect(sent.some((s) => s.path.includes("/call-form")), "a new agent has no call form").toBe(false);
    expect(sent.some((s) => s.method === "POST" && s.path === "/api/kit/console/agents"), "Enter adds the name, never creates the agent").toBe(false);
  });
});
