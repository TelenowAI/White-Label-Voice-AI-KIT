/**
 * An agent calling one person (agents/CallSomeone.tsx, and who asked on a call's page): the form read when opened, the agent's own number first and another pickable,
 * one picked when it has none, a field per detail with those it needs said, what is sent (only details filled, an opening line and a machine answer only when given),
 * a link to the call once placed, a refused field beside it, a paused account or no numbers said plainly.
 */
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { AgentCallForm } from "../src/api";
import { CallSomeone } from "../src/agents/CallSomeone";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const FORM = "GET /api/kit/console/agents/a1/call-form";
const PLACE = "POST /api/kit/console/agents/a1/calls";

function form(over: Partial<AgentCallForm> = {}): AgentCallForm {
  return {
    callerIds: [{ id: "n1", number: "+918045671001" }, { id: "n2", number: "+918045671002" }],
    ownNumber: "n1",
    variables: [{ name: "account_ref", required: true }, { name: "first_name", required: false }],
    machineAnswers: ["voicemail", "hang_up"],
    paused: null,
    limits: { numberMaxLength: 40, variableValueMaxLength: 500, openingLineMaxLength: 1000 },
    ...over,
  };
}

function open() {
  render(<MemoryRouter><CallSomeone agentId="a1" /></MemoryRouter>);
  fireEvent.click(screen.getByRole("button", { name: "Call someone" }));
}

describe("an agent calling one person", () => {
  it("reads the form when opened, sends what was given, and links to the call", async () => {
    const sent = fakeKit({ [FORM]: form(), [PLACE]: () => Response.json({ callId: "c9" }, { status: 201 }) });
    render(<MemoryRouter><CallSomeone agentId="a1" /></MemoryRouter>);
    expect(sent).toHaveLength(0);
    fireEvent.click(screen.getByRole("button", { name: "Call someone" }));
    const from = (await screen.findByLabelText("Call from")) as HTMLSelectElement;
    expect([...from.options].map((o) => o.textContent)).toEqual(["Its own number, +918045671001", "+918045671002"]);
    expect(from.value).toBe("");
    expect(screen.getByLabelText("account_ref (needed)")).toBeTruthy();
    expect((screen.getByLabelText("Number to call") as HTMLInputElement).maxLength).toBe(40);
    fireEvent.change(screen.getByLabelText("Number to call"), { target: { value: "+1 415 555 0131" } });
    fireEvent.change(screen.getByLabelText("account_ref (needed)"), { target: { value: "A-17" } });
    fireEvent.change(screen.getByLabelText("first_name"), { target: { value: "  " } });
    fireEvent.click(screen.getByRole("button", { name: "Call now" }));
    const link = await screen.findByRole("link", { name: "Calling. See the call" });
    expect(link.getAttribute("href")).toBe("/console/calls/c9");
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ number: "+1 415 555 0131", callerId: null, variables: { account_ref: "A-17" }, openingLine: null, machine: null });
  });

  it("sends another number, an opening line and a machine answer when given", async () => {
    const sent = fakeKit({ [FORM]: form(), [PLACE]: () => Response.json({ callId: "c9" }, { status: 201 }) });
    open();
    fireEvent.change(await screen.findByLabelText("Number to call"), { target: { value: "+14155550131" } });
    fireEvent.change(screen.getByLabelText("Call from"), { target: { value: "n2" } });
    fireEvent.change(screen.getByLabelText("account_ref (needed)"), { target: { value: "A-17" } });
    fireEvent.change(screen.getByLabelText("Opening line, instead of its own (optional)"), { target: { value: " Hi {first_name}. " } });
    fireEvent.change(screen.getByLabelText("If a machine answers"), { target: { value: "hang_up" } });
    expect([...(screen.getByLabelText("If a machine answers") as HTMLSelectElement).options].map((o) => o.textContent)).toEqual(["As the agent is set up", "Leave the voicemail message", "Hang up"]);
    fireEvent.click(screen.getByRole("button", { name: "Call now" }));
    await screen.findByRole("link", { name: "Calling. See the call" });
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ number: "+14155550131", callerId: "n2", variables: { account_ref: "A-17" }, openingLine: "Hi {first_name}.", machine: "hang_up" });
  });

  it("has a number picked when the agent has none of its own", async () => {
    fakeKit({ [FORM]: form({ ownNumber: null }) });
    open();
    const from = (await screen.findByLabelText("Call from")) as HTMLSelectElement;
    expect([...from.options].map((o) => [o.textContent, o.disabled])).toEqual([["Pick a number", true], ["+918045671001", false], ["+918045671002", false]]);
    expect(from.value).toBe("n1");
  });

  it("says why a field is refused beside it, and a refusal of the whole call", async () => {
    let n = 0;
    fakeKit({
      [FORM]: form(),
      [PLACE]: () => [
        () => Response.json({ callId: "c9" }, { status: 201 }),
        () => Response.json({ message: "The given data was invalid.", errors: { number: ["Write the number with + and its country code, like +14155550100."], "variables.account_ref": ["Fill in the details this agent needs for the call."] } }, { status: 422 }),
        () => Response.json({ message: "That number is already on as many calls as it can take at once." }, { status: 429 }),
      ][n++](),
    });
    open();
    const number = await screen.findByLabelText("Number to call");
    fireEvent.change(number, { target: { value: "+14155550131" } });
    fireEvent.change(screen.getByLabelText("account_ref (needed)"), { target: { value: "A-17" } });
    fireEvent.click(screen.getByRole("button", { name: "Call now" }));
    expect(await screen.findByRole("link", { name: "Calling. See the call" })).toBeTruthy();
    fireEvent.change(number, { target: { value: "call me" } });
    fireEvent.change(screen.getByLabelText("account_ref (needed)"), { target: { value: "A-17" } }); // the browser holds back a form with a needed detail empty
    fireEvent.click(screen.getByRole("button", { name: "Call now" }));
    expect(await screen.findByText("Write the number with + and its country code, like +14155550100.")).toBeTruthy();
    expect(number.getAttribute("aria-invalid")).toBe("true");
    expect(screen.getByLabelText("account_ref (needed)").getAttribute("aria-invalid")).toBe("true");
    expect(screen.getByText("Fill in the details this agent needs for the call.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Call now" }));
    expect((await screen.findByRole("alert")).textContent).toBe("That number is already on as many calls as it can take at once.");
    expect(screen.queryByRole("link", { name: "Calling. See the call" })).toBeNull();
  });

  it("says plainly when the account is paused or has no numbers", async () => {
    fakeKit({ [FORM]: form({ paused: "Your balance is used up. Calls are on hold until credit is added." }) });
    open();
    expect(await screen.findByText("Your balance is used up. Calls are on hold until credit is added.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Call now" })).toBeNull();
    cleanup();
    fakeKit({ [FORM]: form({ callerIds: [], ownNumber: null }) });
    open();
    expect(await screen.findByText(/this account has none yet/)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Numbers" }).getAttribute("href")).toBe("/console/numbers");
    await waitFor(() => expect(screen.queryByRole("button", { name: "Call now" })).toBeNull());
  });
});
