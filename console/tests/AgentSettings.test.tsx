/**
 * Console → one agent → how it behaves on a call and after it (agents/AgentSettingsForm.tsx): what it shows, what it sends, and how a
 * refusal comes back beside the setting it is about. Not here: what the backend does with them (AgentSettingsTest.php).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AgentHandoffTarget, AgentLookup, AgentSettings, AgentTool } from "../src/api";
import { AgentSettingsForm } from "../src/agents/AgentSettingsForm";
import { agentSettings, settingsForm } from "./agentSettings";

function agent(settings: Partial<AgentSettings> = {}): Agent {
  return {
    id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: { ...agentSettings, ...settings },
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [], syncState: "synced", syncError: null, createdAt: "2026-11-03T10:00:00Z", updatedAt: "2026-11-03T10:00:00Z",
  };
}

/** The kit API answering a save: `answer` for a PATCH of the agent, and what was sent kept. */
function fakeSave(answer: () => Response = () => Response.json(agent())) {
  const sent: { settings: Partial<AgentSettings> }[] = [];
  vi.stubGlobal("fetch", vi.fn(async (_url: string, init?: RequestInit) => {
    if (init?.method === "PATCH") sent.push(JSON.parse(String(init.body)));
    return answer();
  }));
  return sent;
}

function showing(a: Agent = agent(), onSaved: (a: Agent) => void = () => undefined, form = settingsForm) {
  render(<AgentSettingsForm agent={a} form={form} onSaved={onSaved} />);
}

const save = () => fireEvent.click(screen.getByRole("button", { name: "Save these settings" }));
const type = (label: string | RegExp, value: string) => fireEvent.change(screen.getByLabelText(label), { target: { value } });

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("how an agent behaves on a call", () => {
  it("says a phone call opens in silence until there is an opening line", () => {
    showing();
    expect(screen.getByText("On phone calls this agent says nothing until the caller speaks.")).toBeTruthy();
  });

  it("says nothing of it once there is one", () => {
    showing(agent({ greetingInbound: "Hi." }));
    expect(screen.queryByText("On phone calls this agent says nothing until the caller speaks.")).toBeNull();
    expect((screen.getByLabelText("When it answers a call") as HTMLInputElement).value).toBe("Hi.");
  });

  it("warns of an agent with no limit, and keeps none until one is typed", async () => {
    const sent = fakeSave();
    showing(agent({ maxDurationSecs: null }));
    expect(screen.getByText(/no limit on how long a call can run/)).toBeTruthy();
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect("maxDurationSecs" in sent[0].settings).toBe(false);
  });

  it("does not warn of a limit that is set", () => {
    showing();
    expect(screen.queryByText(/no limit on how long a call can run/)).toBeNull();
  });

  it("sends lines tidied, and an empty one as none", async () => {
    const sent = fakeSave();
    showing(agent({ greetingOutbound: "Hello." }));
    type("When it answers a call", "  Hi there.  ");
    type("When it places a call", "   ");
    type("Message to leave on an answering machine", " Call us back. ");
    type("Longest call, in seconds", "300");
    type("What it says before the time runs out", " Out of time. ");
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).toMatchObject({ greetingInbound: "Hi there.", greetingOutbound: null, voicemailMessage: "Call us back.", maxDurationSecs: 300, maxDurationMessage: "Out of time." });
  });

  it("sends the switches as they are set", async () => {
    const sent = fakeSave();
    showing();
    fireEvent.click(screen.getByLabelText("Record calls"));
    fireEvent.click(screen.getByLabelText("Can hang up when the conversation is over"));
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).toMatchObject({ recording: false, canEndCall: false });
  });

  it("turns silence handling off, and shows its fields only while on", async () => {
    const sent = fakeSave();
    showing();
    expect(screen.getAllByLabelText("After how many seconds")).toHaveLength(2);
    fireEvent.click(screen.getByLabelText("Hang up when the line goes silent"));
    fireEvent.click(screen.getByLabelText("Check the caller is still there when the line goes silent"));
    expect(screen.queryByLabelText("After how many seconds")).toBeNull();
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).toMatchObject({ silenceHangupSecs: null, silenceCheckinSecs: null });
  });

  it("asks for the seconds when silence handling is on with none, and sends nothing", () => {
    const sent = fakeSave();
    showing(agent({ silenceHangupSecs: null }));
    fireEvent.click(screen.getByLabelText("Hang up when the line goes silent"));
    save();
    expect(screen.getAllByText("Enter how many seconds, or turn this off.").length).toBeGreaterThan(0);
    expect(sent).toHaveLength(0);
  });

  it("sends the silence lines with their seconds", async () => {
    const sent = fakeSave();
    showing();
    const seconds = screen.getAllByLabelText("After how many seconds");
    fireEvent.change(seconds[0], { target: { value: "20" } });
    fireEvent.change(seconds[1], { target: { value: "12" } });
    const lines = screen.getAllByLabelText("What it says");
    fireEvent.change(lines[0], { target: { value: "Bye." } });
    fireEvent.change(lines[1], { target: { value: "Still there?" } });
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).toMatchObject({ silenceHangupSecs: 20, silenceHangupMessage: "Bye.", silenceCheckinSecs: 12, silenceCheckinMessage: "Still there?" });
  });

  it("hands the saved agent back", async () => {
    fakeSave(() => Response.json(agent({ recording: false })));
    const onSaved = vi.fn();
    showing(agent(), onSaved);
    save();
    await waitFor(() => expect(onSaved).toHaveBeenCalled());
    expect(onSaved.mock.calls[0][0].settings.recording).toBe(false);
  });

  it("says it saved only when the agent it shows is the one this form saved", () => {
    render(<AgentSettingsForm agent={agent()} form={settingsForm} savedAt="2026-11-03T10:00:00Z" onSaved={() => undefined} />);
    expect(screen.getByRole("status").textContent).toBe("Saved.");
    cleanup();
    render(<AgentSettingsForm agent={agent()} form={settingsForm} savedAt="2026-11-03T09:00:00Z" onSaved={() => undefined} />);
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("stops saying so at the next change", () => {
    render(<AgentSettingsForm agent={agent()} form={settingsForm} savedAt="2026-11-03T10:00:00Z" onSaved={() => undefined} />);
    type("When it answers a call", "Hi.");
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("names a row by its place until it has a name of its own", () => {
    showing(agent({ transfer: { enabled: true, message: null, noAnswerMessage: null, destinations: [{ label: null, numbers: ["+15551234567"], mode: "serial" }] } }));
    expect(screen.getByText("Place 1", { selector: "legend" })).toBeTruthy();
    type("Name of this place", "Billing");
    expect(screen.getByText("Billing", { selector: "legend" })).toBeTruthy();
  });

  it("keeps a failure on the form", async () => {
    fakeSave(() => Response.json({ message: "The service is down." }, { status: 503 }));
    showing();
    save();
    expect((await screen.findByRole("alert")).textContent).toBeTruthy();
  });
});

describe("transfer to a person", () => {
  it("is hidden until it is on, then starts with one place", () => {
    showing();
    expect(screen.queryByLabelText("Numbers")).toBeNull();
    expect(screen.queryByRole("button", { name: "Add a place" })).toBeNull();
    fireEvent.click(screen.getByLabelText("Can transfer the call to a person"));
    expect(screen.getByLabelText("Numbers")).toBeTruthy();
  });

  it("sends each place with its numbers split, and its lines", async () => {
    const sent = fakeSave();
    showing();
    fireEvent.click(screen.getByLabelText("Can transfer the call to a person"));
    type("Name of this place", " Billing ");
    type("Numbers", "+1 555 123 4567\n+15551234568, +15551234569");
    fireEvent.change(screen.getByLabelText("When there is more than one number"), { target: { value: "parallel" } });
    type("What it says before transferring", "Connecting you.");
    type("What it says if nobody answers", "Nobody picked up.");
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.transfer).toEqual({
      enabled: true, message: "Connecting you.", noAnswerMessage: "Nobody picked up.",
      destinations: [{ label: "Billing", numbers: ["+1 555 123 4567", "+15551234568", "+15551234569"], mode: "parallel" }],
    });
  });

  it("adds and removes places, and sends none when off", async () => {
    const sent = fakeSave();
    showing(agent({ transfer: { enabled: true, message: null, noAnswerMessage: null, destinations: [{ label: "Billing", numbers: ["+15551234567"], mode: "serial" }] } }));
    fireEvent.click(screen.getByRole("button", { name: "Add a place" }));
    expect(screen.getAllByLabelText("Numbers")).toHaveLength(2);
    fireEvent.click(screen.getAllByRole("button", { name: "Remove this place" })[1]);
    expect(screen.getAllByLabelText("Numbers")).toHaveLength(1);
    fireEvent.click(screen.getByLabelText("Can transfer the call to a person"));
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.transfer).toEqual({ enabled: false, destinations: [], message: null, noAnswerMessage: null });
  });

  it("puts a refusal beside the place it is about", async () => {
    fakeSave(() => Response.json({ message: "x", errors: { "settings.transfer.destinations.0.numbers.0": ["Use a full number."] } }, { status: 422 }));
    showing();
    fireEvent.click(screen.getByLabelText("Can transfer the call to a person"));
    type("Numbers", "nope");
    save();
    const place = (await screen.findAllByText("Use a full number.")).map((n) => n.closest("fieldset")).find(Boolean) as HTMLElement;
    expect(within(place).getByLabelText("Numbers")).toBeTruthy();
  });
});

describe("after the call", () => {
  it("is hidden until it is on", () => {
    showing();
    expect(screen.queryByRole("button", { name: "Add a detail" })).toBeNull();
    fireEvent.click(screen.getByLabelText("Analyse each finished call"));
    expect(screen.getByRole("button", { name: "Add a detail" })).toBeTruthy();
  });

  it("makes a name for each new row from what was typed, whatever its language", async () => {
    const sent = fakeSave();
    showing();
    fireEvent.click(screen.getByLabelText("Analyse each finished call"));
    type("Skip calls shorter than this many seconds", "30");
    type("Skip calls where the caller said fewer than this many things", "3");
    fireEvent.click(screen.getByRole("button", { name: "Add a detail" }));
    fireEvent.click(screen.getByRole("button", { name: "Add a detail" }));
    fireEvent.click(screen.getByRole("button", { name: "Add a detail" }));
    const names = screen.getAllByLabelText("What to call it");
    fireEvent.change(names[0], { target: { value: "Appointment date" } });
    fireEvent.change(names[1], { target: { value: "अपॉइंटमेंट" } });
    fireEvent.change(names[2], { target: { value: "Appointment date!" } });
    fireEvent.change(screen.getAllByLabelText("Kind of answer")[0], { target: { value: "date" } });
    fireEvent.change(screen.getAllByLabelText("What to look for")[0], { target: { value: "The date booked." } });
    fireEvent.click(screen.getByRole("button", { name: "Add a check" }));
    fireEvent.change(screen.getAllByLabelText("What to call it")[3], { target: { value: "Greeted the caller" } });
    fireEvent.change(screen.getByLabelText("What to check"), { target: { value: "It said hello." } });
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.analysis).toEqual({
      enabled: true, minDurationSec: 30, minCustomerTurns: 3,
      customFields: [
        { key: "appointment_date", label: "Appointment date", type: "date", description: "The date booked." },
        { key: "detail_2", label: "अपॉइंटमेंट", type: "string", description: null },
        { key: "appointment_date_2", label: "Appointment date!", type: "string", description: null },
      ],
      qaCriteria: [{ key: "greeted_the_caller", description: "It said hello." }],
    });
  });

  it("keeps a row's name when what it is called changes, and removes rows", async () => {
    const sent = fakeSave();
    showing(agent({ analysis: { enabled: true, minDurationSec: 20, minCustomerTurns: 2, customFields: [{ key: "plan", label: "Plan", type: "string", description: null }, { key: "other", label: null, type: "number", description: null }], qaCriteria: [{ key: "greeted_them", description: null }] } }));
    expect((screen.getAllByLabelText("What to call it")[1] as HTMLInputElement).value).toBe("Other");
    expect((screen.getAllByLabelText("What to call it")[2] as HTMLInputElement).value).toBe("Greeted them");
    fireEvent.change(screen.getAllByLabelText("What to call it")[0], { target: { value: "Their plan" } });
    fireEvent.click(screen.getAllByRole("button", { name: "Remove" })[1]);
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.analysis?.customFields).toEqual([{ key: "plan", label: "Their plan", type: "string", description: null }]);
    expect(sent[0].settings.analysis?.qaCriteria).toEqual([{ key: "greeted_them", description: null }]);
  });

  it("sends it off with what was set up kept", async () => {
    const sent = fakeSave();
    showing(agent({ analysis: { enabled: true, minDurationSec: 20, minCustomerTurns: 2, customFields: [{ key: "plan", label: "Plan", type: "string", description: null }], qaCriteria: [] } }));
    fireEvent.click(screen.getByLabelText("Analyse each finished call"));
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.analysis).toMatchObject({ enabled: false, customFields: [{ key: "plan" }] });
  });
});

describe("transfers while the operator has them off", () => {
  const off = { ...settingsForm, transferAllowed: false };
  const withOne = () => agent({ transfer: { enabled: true, message: null, noAnswerMessage: null, destinations: [{ label: null, numbers: ["+15551234567"], mode: "serial" }] } });

  it("does not offer them, and a save leaves them to the backend", async () => {
    const sent = fakeSave();
    showing(withOne(), undefined, off);
    expect(screen.queryByText("Transfer to a person")).toBeNull();
    expect(screen.queryByLabelText("Can transfer the call to a person")).toBeNull();
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).not.toHaveProperty("transfer");
    expect(sent[0].settings).toHaveProperty("recording");
  });

  it("offers them once they are on", () => {
    showing(withOne());
    expect((screen.getByLabelText("Can transfer the call to a person") as HTMLInputElement).checked).toBe(true);
  });

  it("shows the backend's refusal if they were turned off while the page was open", async () => {
    fakeSave(() => Response.json({ message: "x", errors: { "settings.transfer.enabled": ["Transfers are not available on this service."] } }, { status: 422 }));
    showing();
    fireEvent.click(screen.getByLabelText("Can transfer the call to a person"));
    type(/Numbers/, "+15551234567");
    save();
    expect((await screen.findByRole("alert")).textContent).toBe("Transfers are not available on this service.");
  });
});

describe("how it speaks and listens", () => {
  const group = (title: string) => screen.getByText(title).closest("details") as HTMLDetailsElement;
  const tick = (label: string) => fireEvent.click(screen.getByLabelText(label));

  it("shows what the backend declares, each with what the agent has now", () => {
    showing(agent({ tone: "professional_conversational", personaGender: "female", highEmpathy: true, speechNormalization: false, ringDurationSecs: 45, callScreenMessage: "Who is it?" }));
    expect(screen.getByText("How it speaks")).toBeTruthy();
    expect(screen.getByText("How the agent talks.")).toBeTruthy();
    expect((screen.getByLabelText("Tone") as HTMLSelectElement).value).toBe("professional_conversational");
    expect((screen.getByLabelText("Speaks as") as HTMLSelectElement).value).toBe("female");
    expect((screen.getByLabelText("High empathy") as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText("Say numbers naturally") as HTMLInputElement).checked).toBe(false);
    expect((screen.getByLabelText("How long to ring") as HTMLInputElement).value).toBe("45");
    expect((screen.getByLabelText("What it says when screened") as HTMLInputElement).value).toBe("Who is it?");
    expect(screen.getByText("Acknowledges an upset caller.")).toBeTruthy();
  });

  it("shows a setting with nothing set as empty, and saves it as nothing", async () => {
    const sent = fakeSave();
    showing();
    expect((screen.getByLabelText("How long to ring") as HTMLInputElement).value).toBe("");
    expect((screen.getByLabelText("What it says when screened") as HTMLInputElement).value).toBe("");
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).toMatchObject({ ringDurationSecs: null, callScreenMessage: null, personaGender: null });
  });

  it("types a number on a phone's number pad", () => {
    showing();
    expect((screen.getByLabelText("How long to ring") as HTMLInputElement).inputMode).toBe("numeric");
  });

  it("is folded until wanted", () => {
    showing();
    expect(group("How it speaks").open).toBe(false);
  });

  it("saves in the same save as the rest, each as the kind of value it is", async () => {
    const sent = fakeSave();
    showing(agent({ personaGender: "female", callScreenMessage: "Who is it?" }));
    tick("High empathy");
    tick("Say numbers naturally");
    fireEvent.change(screen.getByLabelText("Tone"), { target: { value: "professional_conversational" } });
    fireEvent.change(screen.getByLabelText("Speaks as"), { target: { value: "" } });
    type("How long to ring", " 45 ");
    type("What it says when screened", "  ");
    type("When it answers a call", "Hi.");
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).toMatchObject({
      greetingInbound: "Hi.", highEmpathy: true, speechNormalization: false, tone: "professional_conversational", personaGender: null, ringDurationSecs: 45, callScreenMessage: null,
    });
  });

  it("sends an empty number as nothing, and one that is not a number as typed for the backend to refuse", async () => {
    const sent = fakeSave();
    showing(agent({ ringDurationSecs: 45 }));
    type("How long to ring", "");
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).toMatchObject({ ringDurationSecs: null });
    cleanup();
    const again = fakeSave();
    showing();
    type("How long to ring", "soon");
    save();
    await waitFor(() => expect(again).toHaveLength(1));
    expect(again[0].settings).toMatchObject({ ringDurationSecs: "soon" });
  });

  it("shows a setting only while what it depends on is on, and sends it only then", async () => {
    const sent = fakeSave();
    showing(agent({ keypadDigits: 8 }));
    expect(screen.queryByLabelText("Stop after this many digits")).toBeNull();
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).not.toHaveProperty("keypadDigits");
    expect(sent[0].settings).toMatchObject({ keypad: false });
    cleanup();
    const again = fakeSave();
    showing(agent({ keypadDigits: 8 }));
    tick("Listen for keys pressed");
    expect((screen.getByLabelText("Stop after this many digits") as HTMLInputElement).value).toBe("8");
    type("Stop after this many digits", "4");
    save();
    await waitFor(() => expect(again).toHaveLength(1));
    expect(again[0].settings).toMatchObject({ keypad: true, keypadDigits: 4 });
  });

  it("opens the group a refusal is in and shows it beside the setting", async () => {
    fakeSave(() => Response.json({ message: "x", errors: { "settings.ringDurationSecs": ["At most 120 seconds."] } }, { status: 422 }));
    showing();
    expect(group("Answering").open).toBe(false);
    type("How long to ring", "999");
    save();
    expect((await screen.findByRole("alert")).textContent).toBe("At most 120 seconds.");
    expect(group("Answering").open).toBe(true);
    expect(group("How it speaks").open).toBe(false);
    expect(within(group("Answering")).getByText("At most 120 seconds.")).toBeTruthy();
  });

  it("stops saying it saved at a change here too", () => {
    render(<AgentSettingsForm agent={agent()} form={settingsForm} savedAt="2026-11-03T10:00:00Z" onSaved={() => undefined} />);
    expect(screen.getByRole("status")).toBeTruthy();
    tick("High empathy");
    expect(screen.queryByRole("status")).toBeNull();
  });
});

describe("an agent's tools", () => {
  const saved: AgentTool = {
    name: "book_visit", description: "Book a visit.", url: "https://example.com/hooks/book", request: null,
    parameters: [{ name: "date", type: "string", description: "The day.", required: true }], tokenHint: "••••1234", sayWhileRunning: "One moment.", timeoutSecs: 20, askFirst: true,
  };
  const custom: AgentTool = { ...saved, name: "get_order", tokenHint: null, parameters: [], sayWhileRunning: null, timeoutSecs: null, askFirst: false,
    url: "https://example.com/orders", request: { method: "POST", headers: [{ name: "Authorization", value: "••••5678" }], body: '{"a": "{b}"}' } };
  const addTool = () => fireEvent.click(screen.getByRole("button", { name: "Add a tool" }));
  const firstSaved = async (sent: { settings: Partial<AgentSettings> }[]) => {
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    return sent[0].settings as unknown as { tools: Record<string, unknown>[]; lookups: Record<string, unknown>[]; handoff: { agents: AgentHandoffTarget[]; message: string | null }; canAdjustVolume: boolean; sendCallerToTools: boolean };
  };

  it("sends a new plain tool with a name made from what was typed", async () => {
    const sent = fakeSave();
    showing();
    addTool();
    type("Name", " Book a visit ");
    type("What it is for", " When they ask. ");
    type("Web address", " https://example.com/x ");
    type("Key", " tok_1 ");
    type("What it says while it waits", " One moment. ");
    type(/How long to wait/, "20");
    fireEvent.click(screen.getByLabelText("Ask the caller first"));
    const { tools } = await firstSaved(sent);
    expect(tools).toEqual([{ name: "book_a_visit", description: "When they ask.", url: "https://example.com/x", request: null, parameters: [], sayWhileRunning: "One moment.", timeoutSecs: 20, askFirst: true, token: "tok_1" }]);
  });

  it("shows a saved tool as it stands and never the key, and a save leaves the key out so it stays", async () => {
    const sent = fakeSave();
    showing(agent({ tools: [saved] }));
    expect((screen.getByLabelText("Key") as HTMLInputElement).value).toBe("");
    expect((screen.getByLabelText("Key") as HTMLInputElement).type).toBe("password");
    expect(screen.getByText(/A key is saved \(••••1234\)/)).toBeTruthy();
    expect((screen.getByLabelText("What it is for") as HTMLTextAreaElement).value).toBe("Book a visit.");
    expect((screen.getByLabelText("How long to wait (seconds)") as HTMLInputElement).value).toBe("20");
    const { tools } = await firstSaved(sent);
    expect(tools[0].name).toBe("book_visit");
    expect(tools[0]).not.toHaveProperty("token");
    expect(tools[0].parameters).toEqual(saved.parameters);
  });

  it("does not offer to remove a key when none is saved", () => {
    showing();
    addTool();
    expect(screen.queryByLabelText("Remove the saved key")).toBeNull();
    expect(screen.getByText("Sent as a bearer token. Leave this empty if it needs none.")).toBeTruthy();
  });

  it("takes a saved key away or replaces it", async () => {
    const sent = fakeSave();
    showing(agent({ tools: [saved] }));
    fireEvent.click(screen.getByLabelText("Remove the saved key"));
    const { tools } = await firstSaved(sent);
    expect(tools[0].token).toBeNull();
    cleanup();
    const again = fakeSave();
    showing(agent({ tools: [saved] }));
    type("Key", "tok_new");
    const next = await firstSaved(again);
    expect(next.tools[0].token).toBe("tok_new");
  });

  it("keeps a tool's name when what is typed for it changes, so its saved key stays", async () => {
    const sent = fakeSave();
    showing(agent({ tools: [saved] }));
    type("Name", "Something else");
    const { tools } = await firstSaved(sent);
    expect(tools[0].name).toBe("book_visit");
  });

  it("gives tools with nothing latin in their name a name of their own, none twice", async () => {
    const sent = fakeSave();
    showing();
    addTool();
    addTool();
    const names = screen.getAllByLabelText("Name");
    fireEvent.change(names[0], { target: { value: "बुक" } });
    fireEvent.change(names[1], { target: { value: "Book" } });
    const { tools } = await firstSaved(sent);
    expect(tools.map((x) => x.name)).toEqual(["tool_1", "book"]);
  });

  it("adds, changes and removes the details it asks for", async () => {
    const sent = fakeSave();
    showing(agent({ tools: [saved] }));
    fireEvent.click(screen.getByRole("button", { name: "Add a detail" }));
    const names = screen.getAllByLabelText("Name of the detail");
    fireEvent.change(names[1], { target: { value: "people" } });
    fireEvent.change(screen.getAllByLabelText("Kind")[1], { target: { value: "integer" } });
    fireEvent.change(screen.getAllByLabelText("What it is")[1], { target: { value: "How many." } });
    fireEvent.click(screen.getAllByLabelText("The agent must have this first")[1]);
    fireEvent.click(screen.getAllByRole("button", { name: "Remove" })[0]);
    const { tools } = await firstSaved(sent);
    expect(tools[0].parameters).toEqual([{ name: "people", type: "integer", description: "How many.", required: true }]);
  });

  it("only shows the request while it is customised, and hands a header's mask back as it was", async () => {
    const sent = fakeSave();
    showing(agent({ tools: [saved] }));
    expect(screen.queryByLabelText("Method")).toBeNull();
    fireEvent.click(screen.getByLabelText("Customise the request"));
    fireEvent.change(screen.getByLabelText("Method"), { target: { value: "PUT" } });
    fireEvent.click(screen.getByRole("button", { name: "Add a header" }));
    type("Header name 1", "X-Tenant");
    type("Header value 1", "acme");
    type("Body", ' {"d": "{date}"} ');
    const { tools } = await firstSaved(sent);
    expect(tools[0].request).toEqual({ method: "PUT", headers: [{ name: "X-Tenant", value: "acme" }], body: '{"d": "{date}"}' });
    cleanup();
    const again = fakeSave();
    showing(agent({ tools: [custom] }));
    expect((screen.getByLabelText("Header value 1") as HTMLInputElement).value).toBe("••••5678");
    const next = await firstSaved(again);
    expect(next.tools[0].request).toEqual(custom.request);
  });

  it("offers no body on a GET", () => {
    showing(agent({ tools: [{ ...custom, request: { method: "GET", headers: [], body: null } }] }));
    expect(screen.queryByLabelText("Body")).toBeNull();
  });

  it("sends an unticked customise as plain, whatever was typed", async () => {
    const sent = fakeSave();
    showing(agent({ tools: [custom] }));
    fireEvent.click(screen.getByLabelText("Customise the request"));
    const { tools } = await firstSaved(sent);
    expect(tools[0].request).toBeNull();
  });

  it("removes a tool", async () => {
    const sent = fakeSave();
    showing(agent({ tools: [saved, custom] }));
    fireEvent.click(screen.getAllByRole("button", { name: "Remove this tool" })[0]);
    const { tools } = await firstSaved(sent);
    expect(tools.map((x) => x.name)).toEqual(["get_order"]);
  });

  it("puts a refusal beside the field it is about, in the right tool", async () => {
    fakeSave(() => Response.json({ message: "x", errors: { "settings.tools.1.url": ["Use https."], "settings.tools.1.token": ["Type the key again."], "settings.tools.1.parameters.0.name": ["Twice."], "settings.tools.1.request.headers.0.name": ["Bad header."], "settings.tools.1.request.body": ["Bad body."] } }, { status: 422 }));
    showing(agent({ tools: [saved, { ...custom, parameters: saved.parameters }] }));
    save();
    const second = (await screen.findAllByText("Use https.")).map((e) => e.closest("fieldset")).find(Boolean) as HTMLElement;
    expect(within(second).getByText("Type the key again.")).toBeTruthy();
    expect(within(second).getByText("Twice.")).toBeTruthy();
    expect(within(second).getByText("Bad header.")).toBeTruthy();
    expect(within(second).getByText("Bad body.")).toBeTruthy();
    expect(within(second).getByLabelText("Web address").getAttribute("aria-invalid")).toBe("true");
    expect(within(screen.getAllByRole("group")[0]).queryByText("Use https.")).toBeNull();
  });

  it("sends the two switches", async () => {
    const sent = fakeSave();
    showing(agent({ canAdjustVolume: false, sendCallerToTools: true }));
    fireEvent.click(screen.getByLabelText("Callers can ask it to speak louder or softer"));
    fireEvent.click(screen.getByLabelText("Send the caller's number to tools"));
    const settings = await firstSaved(sent);
    expect([settings.canAdjustVolume, settings.sendCallerToTools]).toEqual([true, false]);
  });

  it("stops saying it saved at a change to a tool", () => {
    render(<AgentSettingsForm agent={agent()} form={settingsForm} savedAt="2026-11-03T10:00:00Z" onSaved={() => undefined} />);
    addTool();
    expect(screen.queryByRole("status")).toBeNull();
  });
});

describe("an agent's look-ups", () => {
  const saved: AgentLookup = { name: "Who is calling", url: "https://example.com/c?p={contact_number}", method: "GET", headers: [], body: null, tokenHint: "••••3456", fills: [{ variable: "customer_name", path: "data.name", fallback: "there" }] };
  const firstSaved = async (sent: { settings: Partial<AgentSettings> }[]) => {
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    return (sent[0].settings as unknown as { lookups: Record<string, unknown>[] }).lookups;
  };
  const inSection = () => within(screen.getByRole("heading", { name: "Look-ups before a call" }).closest("section") as HTMLElement);

  it("sends a new one tidied, starting with one thing to fill", async () => {
    const sent = fakeSave();
    showing();
    fireEvent.click(screen.getByRole("button", { name: "Add a look-up" }));
    fireEvent.change(inSection().getByLabelText("Name"), { target: { value: " Who " } });
    fireEvent.change(inSection().getByLabelText("Web address"), { target: { value: " https://example.com/c?p={contact_number} " } });
    fireEvent.change(inSection().getByLabelText("Name to use"), { target: { value: "customer_name" } });
    fireEvent.change(inSection().getByLabelText("Where in the answer"), { target: { value: "data.name" } });
    fireEvent.change(inSection().getByLabelText("If nothing is found"), { target: { value: "there" } });
    fireEvent.change(inSection().getByLabelText("Key"), { target: { value: " tok_2 " } });
    const lookups = await firstSaved(sent);
    expect(lookups).toEqual([{ name: "Who", url: "https://example.com/c?p={contact_number}", method: "GET", headers: [], body: null, fills: [{ variable: "customer_name", path: "data.name", fallback: "there" }], token: "tok_2" }]);
  });

  it("shows a saved one and never its key, and a save leaves the key out", async () => {
    const sent = fakeSave();
    showing(agent({ lookups: [saved] }));
    expect((inSection().getByLabelText("Key") as HTMLInputElement).value).toBe("");
    expect(inSection().getByText(/A key is saved \(••••3456\)/)).toBeTruthy();
    expect((inSection().getByLabelText("If nothing is found") as HTMLInputElement).value).toBe("there");
    const lookups = await firstSaved(sent);
    expect(lookups[0]).not.toHaveProperty("token");
    expect(lookups[0].fills).toEqual(saved.fills);
  });

  it("takes a saved key away", async () => {
    const sent = fakeSave();
    showing(agent({ lookups: [saved] }));
    fireEvent.click(inSection().getByLabelText("Remove the saved key"));
    const lookups = await firstSaved(sent);
    expect(lookups[0].token).toBeNull();
  });

  it("only has a body on a POST, and an emptied fallback goes as nothing", async () => {
    const sent = fakeSave();
    showing(agent({ lookups: [saved] }));
    expect(inSection().queryByLabelText("Body")).toBeNull();
    fireEvent.change(inSection().getByLabelText("Method"), { target: { value: "POST" } });
    fireEvent.change(inSection().getByLabelText("Body"), { target: { value: ' {"p": "{contact_number}"} ' } });
    fireEvent.change(inSection().getByLabelText("If nothing is found"), { target: { value: "" } });
    const lookups = await firstSaved(sent);
    expect(lookups[0].method).toBe("POST");
    expect(lookups[0].body).toBe('{"p": "{contact_number}"}');
    expect((lookups[0].fills as { fallback: string | null }[])[0].fallback).toBeNull();
    cleanup();
    const again = fakeSave();
    showing(agent({ lookups: [{ ...saved, method: "POST", body: "x" }] }));
    fireEvent.change(inSection().getByLabelText("Method"), { target: { value: "GET" } });
    const next = await firstSaved(again);
    expect(next[0].body).toBeNull();
  });

  it("adds and removes what it fills, and removes the look-up", async () => {
    const sent = fakeSave();
    showing(agent({ lookups: [saved] }));
    fireEvent.click(inSection().getByRole("button", { name: "Add another" }));
    fireEvent.change(inSection().getAllByLabelText("Name to use")[1], { target: { value: "plan" } });
    fireEvent.change(inSection().getAllByLabelText("Where in the answer")[1], { target: { value: "plan" } });
    fireEvent.click(inSection().getAllByRole("button", { name: "Remove" })[0]);
    const lookups = await firstSaved(sent);
    expect(lookups[0].fills).toEqual([{ variable: "plan", path: "plan", fallback: null }]);
    cleanup();
    const again = fakeSave();
    showing(agent({ lookups: [saved] }));
    fireEvent.click(inSection().getByRole("button", { name: "Remove this look-up" }));
    expect(await firstSaved(again)).toEqual([]);
  });

  it("puts a refusal beside the field it is about", async () => {
    fakeSave(() => Response.json({ message: "x", errors: { "settings.lookups.0.url": ["Say who."], "settings.lookups.0.fills.0.variable": ["Reserved."], "settings.lookups.0.token": ["Again."] } }, { status: 422 }));
    showing(agent({ lookups: [saved] }));
    save();
    const row = (await inSection().findByText("Say who.")).closest("fieldset") as HTMLElement;
    expect(within(row).getByText("Reserved.")).toBeTruthy();
    expect(within(row).getByText("Again.")).toBeTruthy();
  });

  it("says a refusal about a look-up it cannot show beside one", async () => {
    fakeSave(() => Response.json({ message: "x", errors: { "settings.lookups": ["Too many."] } }, { status: 422 }));
    showing();
    save();
    await waitFor(() => expect(inSection().getByText("Too many.")).toBeTruthy());
  });
});

describe("handing over to another agent", () => {
  const other = (id: string, name: string) => ({ ...agent(), id, name });
  /** The kit's list of agents, a page at a time, with what was asked kept. */
  function fakeAgents(pages: Agent[][]) {
    const asked: number[] = [];
    const sent: { settings: Record<string, unknown> }[] = [];
    vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
      if (init?.method === "PATCH") {
        sent.push(JSON.parse(String(init.body)));
        return Response.json(agent());
      }
      if (url.includes("/console/team")) return Response.json({ members: [], invites: [] });
      const page = Number(new URL(url, "http://x").searchParams.get("page") ?? 1);
      asked.push(page);
      return Response.json({ agents: pages[page - 1] ?? [], total: pages.flat().length, page, perPage: 2 });
    }));
    return { asked, sent };
  }
  const inSection = () => within(screen.getByRole("heading", { name: "Hand over to another agent" }).closest("section") as HTMLElement);
  const add = () => fireEvent.click(inSection().getByRole("button", { name: "Add an agent" }));

  it("reads no agents until one is wanted", async () => {
    const { asked } = fakeAgents([[other("b", "Billing")]]);
    showing();
    await new Promise((r) => setTimeout(r, 20));
    expect(asked).toEqual([]);
    add();
    await waitFor(() => expect(asked).toEqual([1]));
  });

  it("offers every other agent of the account across all its pages, not this one", async () => {
    const { asked } = fakeAgents([[agent(), other("b", "Billing")], [other("c", "Sales")]]);
    showing();
    add();
    await inSection().findByRole("option", { name: "Sales" });
    expect(asked).toEqual([1, 2]);
    const options = within(inSection().getByLabelText("Agent")).getAllByRole("option").map((o) => o.textContent);
    expect(options).toEqual(["Choose an agent", "Billing", "Sales"]);
  });

  it("sends who it hands to, how, and what it says", async () => {
    const { sent } = fakeAgents([[other("b", "Billing")]]);
    showing();
    add();
    await inSection().findByRole("option", { name: "Billing" });
    fireEvent.change(inSection().getByLabelText("Agent"), { target: { value: "b" } });
    fireEvent.change(inSection().getByLabelText("Name it goes by"), { target: { value: "Accounts" } });
    fireEvent.change(inSection().getByLabelText("How it hands over"), { target: { value: "connect" } });
    fireEvent.change(inSection().getByLabelText("What it says before handing over"), { target: { value: " One moment. " } });
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.handoff).toEqual({ agents: [{ agentId: "b", label: "Accounts", mode: "connect" }], message: "One moment." });
  });

  it("shows a saved one at once by its name, and reads the rest", async () => {
    const { asked } = fakeAgents([[other("b", "Billing desk"), other("c", "Sales")]]);
    showing(agent({ handoff: { agents: [{ agentId: "z", label: "Old desk", mode: "transfer" }], message: "Hold on." } }));
    expect((within(inSection().getByLabelText("Agent")).getByRole("option", { name: "Old desk" }) as HTMLOptionElement).selected).toBe(true);
    expect((inSection().getByLabelText("What it says before handing over") as HTMLInputElement).value).toBe("Hold on.");
    await waitFor(() => expect(asked).toEqual([1]));
  });

  it("asks what it says only once there is someone to hand to", () => {
    fakeAgents([]);
    showing();
    expect(inSection().queryByLabelText("What it says before handing over")).toBeNull();
  });

  it("sends an emptied name as nothing, so it goes by the other agent's own", async () => {
    const { sent } = fakeAgents([[other("b", "Billing")]]);
    showing(agent({ handoff: { agents: [{ agentId: "b", label: "Accounts", mode: "transfer" }], message: null } }));
    fireEvent.change(inSection().getByLabelText("Name it goes by"), { target: { value: "" } });
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.handoff).toEqual({ agents: [{ agentId: "b", label: null, mode: "transfer" }], message: null });
  });

  it("removes one", async () => {
    const { sent } = fakeAgents([[other("b", "Billing")]]);
    showing(agent({ handoff: { agents: [{ agentId: "b", label: "Billing", mode: "transfer" }], message: null } }));
    fireEvent.click(inSection().getByRole("button", { name: "Remove" }));
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.handoff).toEqual({ agents: [], message: null });
  });

  it("says when the agents cannot be read, and a refusal beside the agent", async () => {
    vi.stubGlobal("fetch", vi.fn(async (_url: string, init?: RequestInit) => init?.method === "PATCH"
      ? Response.json({ message: "x", errors: { "settings.handoff.agents.0.agentId": ["Not this one."] } }, { status: 422 })
      : Response.json({ message: "Try again shortly." }, { status: 500 })));
    showing(agent({ handoff: { agents: [{ agentId: "b", label: "Billing", mode: "transfer" }], message: null } }));
    expect(await inSection().findByText("Try again shortly.")).toBeTruthy();
    save();
    expect(await inSection().findByText("Not this one.")).toBeTruthy();
    expect(inSection().getByLabelText("Agent").getAttribute("aria-invalid")).toBe("true");
  });
});

describe("who is emailed after a call", () => {
  const team = { members: [
    { userId: 1, name: "Ana", email: "ana@example.com", role: "owner", joinedAt: "2026-11-01T10:00:00Z" },
    { userId: 2, name: "Ben", email: "ben@example.com", role: "member", joinedAt: "2026-11-01T10:00:00Z" },
    { userId: 3, name: "Dee", email: null, role: "member", joinedAt: "2026-11-01T10:00:00Z" },
  ], invites: [] };
  function fakeTeam() {
    const sent: { settings: { emailAfterCall?: unknown } }[] = [];
    vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
      if (init?.method === "PATCH") {
        sent.push(JSON.parse(String(init.body)));
        return Response.json(agent());
      }
      return Response.json(url.includes("/console/team") ? team : {});
    }));
    return sent;
  }

  it("offers the team's members with an address and sends who is ticked", async () => {
    const sent = fakeTeam();
    showing(agent({ emailAfterCall: { recipients: [2], onlyAnswered: true } }));
    const ben = await screen.findByLabelText("Ben (ben@example.com)") as HTMLInputElement;
    expect(ben.checked).toBe(true);
    expect(screen.queryByText(/Dee/)).toBeNull();
    fireEvent.click(screen.getByLabelText("Ana (ana@example.com)"));
    fireEvent.click(ben);
    fireEvent.click(screen.getByLabelText("Only calls that were answered"));
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings.emailAfterCall).toEqual({ recipients: [1], onlyAnswered: false });
  });

  it("asks about answered calls only once someone is ticked", async () => {
    fakeTeam();
    showing();
    await screen.findByLabelText("Ben (ben@example.com)");
    expect(screen.queryByLabelText("Only calls that were answered")).toBeNull();
    fireEvent.click(screen.getByLabelText("Ben (ben@example.com)"));
    expect(screen.getByLabelText("Only calls that were answered")).toBeTruthy();
  });

  it("is neither offered nor sent when the kit cannot send email", async () => {
    const sent = fakeTeam();
    showing(agent(), undefined, { ...settingsForm, emailOn: false });
    expect(screen.queryByText("Email a summary of each call to")).toBeNull();
    save();
    await waitFor(() => expect(sent).toHaveLength(1));
    expect(sent[0].settings).not.toHaveProperty("emailAfterCall");
  });

  it("says why when someone is refused", async () => {
    vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => init?.method === "PATCH"
      ? Response.json({ message: "x", errors: { "settings.emailAfterCall.recipients.0": ["Not on this team."] } }, { status: 422 })
      : Response.json(url.includes("/console/team") ? team : {})));
    showing();
    await screen.findByLabelText("Ben (ben@example.com)");
    save();
    const said = await screen.findAllByText("Not on this team.");
    expect(said.some((e) => e.tagName === "SMALL")).toBe(true);
  });
});
