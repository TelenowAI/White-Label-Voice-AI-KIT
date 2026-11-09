/**
 * Telling the agent during a live call (calls/TellAgent.tsx and the call's page, CallPage.tsx; contract/kit-api.yaml tellAgent, and getCall's
 * canTellAgent and agentNotes): the box only where the backend says the member may; Send kept off while the box is empty and while sending; the
 * words and whether to say them now POSTed to the call's agent notes; the backend's word on how each note reaches the agent, the box cleared and the
 * note added at the top of the list; a refusal of the words shown by the box, any other refusal as an alert; what the agent was told listed on an
 * ended call, with who, when and whether it was asked to say it now.
 */
import { act, cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AgentNote, CallDetail, CallWork, ConsoleHome, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "owner", canManageTeam: true, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "owner", current: true }] };
const emptyWork: CallWork = { wrapup: null, notes: [], followUps: [], suggestions: [], limits: { noteMaxLength: 2000, wrapupMaxLength: 64, followUpMaxLength: 500 } };
const NOTES = "/api/kit/console/calls/c1/agent-notes";

function liveCall(over: Partial<CallDetail> = {}): CallDetail {
  return {
    id: "c1", agent: { id: "a1", name: "Front desk" }, placedBy: null, requestedBy: null, wrapup: null, status: "active", outcome: null, direction: "inbound",
    channel: "telephony", from: "+14155550123", to: "+918045671001", startedAt: "2026-11-08T10:00:00Z", endedAt: null, durationSec: null, complete: false,
    charge: null, canListen: false, listens: [], canTellAgent: true, agentNotes: [], answeredBy: null, transcript: [], recordings: [], analysis: null, ...over,
  };
}

function note(over: Partial<AgentNote> = {}): AgentNote {
  return { by: "Ana", at: "2026-11-08T10:03:00Z", text: "", sayNow: false, delivery: "nextReply", ...over };
}

/** The backend keeping a note as sent, with how it reaches the agent. */
const toldAs = (delivery: AgentNote["delivery"]) => (body: unknown) => Response.json({ note: note({ ...(body as { text: string; sayNow: boolean }), delivery }) }, { status: 202 });

function page(call: CallDetail, answers: Record<string, unknown> = {}) {
  const sent = fakeKit({
    "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one,
    "GET /api/kit/console/calls/c1": call, "GET /api/kit/console/calls/c1/work": emptyWork, ...answers,
  });
  render(
    <MemoryRouter initialEntries={["/console/calls/c1"]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
  return sent;
}

const tellBox = () => screen.findByRole("region", { name: "Tell the agent" });
const field = (box: HTMLElement) => within(box).getByRole("textbox", { name: "What should the agent know?" }) as HTMLTextAreaElement;
const sayNow = (box: HTMLElement) => within(box).getByRole("checkbox", { name: "Ask the agent to say it to the caller now" }) as HTMLInputElement;
const type = (box: HTMLElement, text: string) => fireEvent.change(field(box), { target: { value: text } });
const posted = (sent: { method: string; path: string; body: unknown }[]) => sent.filter((s) => s.method === "POST").map((s) => [s.path, s.body]);
const told = () => within(screen.getByRole("list", { name: "Told the agent" })).getAllByRole("listitem");

describe("telling the agent", () => {
  it("is on a live call's page only where the backend says the member may", async () => {
    page(liveCall());
    const box = await tellBox();
    expect(field(box).value).toBe("");
    expect(sayNow(box).checked).toBe(false);
    expect(within(box).getByText("The agent sees it from its next reply, as background, never as the caller's words.")).toBeTruthy();
    expect(screen.queryByRole("list", { name: "Told the agent" })).toBeNull();
    cleanup();
    page(liveCall({ canTellAgent: false }));
    expect(await screen.findByText("Front desk")).toBeTruthy();
    expect(screen.queryByRole("region", { name: "Tell the agent" })).toBeNull();
    expect(screen.queryByRole("textbox", { name: "What should the agent know?" })).toBeNull();
  });

  it("keeps Send off while the box is empty or only spaces, and while sending", async () => {
    let release!: () => void;
    const held = new Promise<void>((r) => (release = r));
    page(liveCall(), { [`POST ${NOTES}`]: (body: unknown) => held.then(() => toldAs("nextReply")(body)) });
    const box = await tellBox();
    const send = () => within(box).getByRole("button", { name: /^(Send|Sending…)$/ }) as HTMLButtonElement;
    expect(send().disabled).toBe(true);
    type(box, "  \n ");
    expect(send().disabled).toBe(true);
    type(box, "She is a returning patient.");
    expect(send().disabled).toBe(false);
    fireEvent.click(send());
    await waitFor(() => expect(send().textContent).toBe("Sending…"));
    expect(send().disabled).toBe(true);
    await act(async () => release());
    await waitFor(() => expect(send().textContent).toBe("Send"));
    expect(send().disabled).toBe(true);
  });

  it("sends the words, and whether to say them now, to the call's agent notes", async () => {
    const sent = page(liveCall(), { [`POST ${NOTES}`]: toldAs("nextReply") });
    const box = await tellBox();
    type(box, "She is a returning patient.");
    fireEvent.click(within(box).getByRole("button", { name: "Send" }));
    await waitFor(() => expect(field(box).value).toBe(""));
    type(box, "Offer her the 10 am slot.");
    fireEvent.click(sayNow(box));
    expect(sayNow(box).checked).toBe(true);
    fireEvent.click(within(box).getByRole("button", { name: "Send" }));
    await waitFor(() => expect(field(box).value).toBe(""));
    expect(sayNow(box).checked).toBe(false);
    expect(posted(sent)).toEqual([
      [NOTES, { text: "She is a returning patient.", sayNow: false }],
      [NOTES, { text: "Offer her the 10 am slot.", sayNow: true }],
    ]);
  });

  it("says how each note reaches the agent, clears the box and adds the note at the top of the list", async () => {
    const ways: AgentNote["delivery"][] = ["nextReply", "sayingNow", "whenBack"];
    const older = note({ by: "Ben", at: "2026-11-08T10:01:00Z", text: "Speak slowly." });
    page(liveCall({ agentNotes: [older] }), { [`POST ${NOTES}`]: (body: unknown) => toldAs(ways.shift()!)(body) });
    const box = await tellBox();
    const said = [
      ["First note.", "Told. The agent sees it from its next reply."],
      ["Second note.", "Told. The agent is saying it now."],
      ["Third note.", "Told. The agent sees it when it has the call again."],
    ];
    for (const [text, words] of said) {
      type(box, text);
      fireEvent.click(within(box).getByRole("button", { name: "Send" }));
      await waitFor(() => expect(within(box).getByRole("status").textContent?.trim()).toBe(words));
      expect(field(box).value).toBe("");
      expect(told()[0].querySelector(".note-body")?.textContent).toBe(text);
    }
    expect(told().map((li) => li.querySelector(".note-body")?.textContent)).toEqual(["Third note.", "Second note.", "First note.", "Speak slowly."]);
    expect(told()[3].textContent).toContain("Ben");
  });

  it("shows the backend's reason for the words by the box, and keeps them", async () => {
    const reason = "This is longer than the agent can take in now: up to 1200 characters fit.";
    const sent = page(liveCall(), {
      [`POST ${NOTES}`]: () => Response.json({ message: "The given data was invalid.", errors: { text: [reason] } }, { status: 422 }),
    });
    const box = await tellBox();
    type(box, "A very long note.");
    fireEvent.click(within(box).getByRole("button", { name: "Send" }));
    const shown = await within(box).findByText(reason);
    expect(field(box).getAttribute("aria-invalid")).toBe("true");
    expect(field(box).getAttribute("aria-describedby")?.split(" ")).toContain(shown.id);
    expect(field(box).value).toBe("A very long note.");
    expect(within(box).queryByRole("alert")).toBeNull();
    expect(within(box).queryByRole("status")).toBeNull();
    expect(screen.queryByRole("list", { name: "Told the agent" })).toBeNull();
    expect(posted(sent)).toHaveLength(1);
  });

  it.each([
    [403, "Only someone who manages the account can tell the agent anything."],
    [404, "There is no such call."],
    [409, "This call is no longer on."],
    [503, "The agent cannot be told anything just now."],
  ])("shows a %i refusal as an alert, and keeps the words", async (status, message) => {
    page(liveCall(), { [`POST ${NOTES}`]: () => Response.json({ message }, { status }) });
    const box = await tellBox();
    type(box, "She is a returning patient.");
    fireEvent.click(within(box).getByRole("button", { name: "Send" }));
    expect((await within(box).findByRole("alert")).textContent).toBe(message);
    expect(field(box).getAttribute("aria-invalid")).toBe("false");
    expect(field(box).value).toBe("She is a returning patient.");
    expect(within(box).queryByRole("status")).toBeNull();
    expect(screen.queryByRole("list", { name: "Told the agent" })).toBeNull();
  });

  it("lists what the agent was told on an ended call, with who, when and whether it was asked to say it now", async () => {
    const asked = note({ by: null, at: "2026-11-08T10:05:00Z", text: "Tell her the clinic closes at 6 today.", sayNow: true, delivery: "sayingNow" });
    const background = note({ by: "Ana", at: "2026-11-08T10:02:00Z", text: "She is a returning patient.", sayNow: false });
    page(liveCall({ status: "ended", endedAt: "2026-11-08T10:09:00Z", durationSec: 540, complete: true, canTellAgent: false, agentNotes: [asked, background] }));
    expect(await screen.findByRole("heading", { name: "Told the agent" })).toBeTruthy();
    const [first, second] = told();
    expect(first.querySelector(".note-body")?.textContent).toBe("Tell her the clinic closes at 6 today.");
    expect(first.querySelector("small")?.textContent).toBe(`Someone no longer on the team · ${new Date(asked.at).toLocaleString()} · asked to say it now`);
    expect(second.querySelector(".note-body")?.textContent).toBe("She is a returning patient.");
    expect(second.querySelector("small")?.textContent).toBe(`Ana · ${new Date(background.at).toLocaleString()}`);
    expect(told()).toHaveLength(2);
    expect(screen.queryByRole("region", { name: "Tell the agent" })).toBeNull();
  });
});
