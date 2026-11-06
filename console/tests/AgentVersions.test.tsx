/**
 * Console → one agent → its versions (agents/AgentVersions.tsx): reading them only when asked, what changed, how calls went, and putting one back after asking. Not here: what the backend does (AgentVersionsTest.php).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, AgentVersion, AgentVersionWindow } from "../src/api";
import { AgentVersions } from "../src/agents/AgentVersions";
import { outcomeText } from "../src/calls/callText";
import { agentSettings } from "./agentSettings";

const agent = (over: Partial<Agent> = {}) => ({ id: "a1", name: "Front desk", settings: agentSettings, syncState: "synced", ...over }) as unknown as Agent;
const version = (over: Partial<AgentVersion> = {}): AgentVersion => ({ version: 1, at: "2026-11-04T05:39:00Z", name: "Front desk", kind: "created", restoredFrom: null, current: false, ...over });
const three = [version({ version: 3, kind: "restored", restoredFrom: 1, current: true }), version({ version: 2, kind: "changed" }), version()];
const window_ = (over: Partial<AgentVersionWindow> = {}): AgentVersionWindow => ({ calls: 4, answeredRate: 0.75, avgDurationSec: 125, avgScore: 86.6, sentiment: { positive: 3, neutral: 1, negative: 0 }, from: null, to: null, ...over });
const root = "/api/kit/console/agents/a1/versions";

/** The kit API by method and path, with what was sent kept. */
function fake(routes: Record<string, () => Response>) {
  const sent: { method: string; path: string }[] = [];
  vi.stubGlobal("fetch", vi.fn(async (url: string, init?: RequestInit) => {
    const method = init?.method ?? "GET";
    sent.push({ method, path: String(url) });
    const route = routes[`${method} ${url}`];
    return route ? route() : new Response(null, { status: 404 });
  }));
  return sent;
}
const listing = (versions: AgentVersion[], kept: string[] = []) => ({ [`GET ${root}`]: () => Response.json({ versions, lastRestore: kept.length ? { version: 1, kept } : null }) });
const open = async (versions: AgentVersion[] = three) => {
  const onRestored = vi.fn();
  render(<AgentVersions agent={agent()} onRestored={onRestored} />);
  fireEvent.click(screen.getByRole("button", { name: "See the versions" }));
  if (versions.length) await screen.findByText("Version 1");
  return onRestored;
};
const row = (n: number) => screen.getByText(`Version ${n}`).closest("li") as HTMLElement;

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("an agent's versions", () => {
  it("reads nothing until asked", () => {
    const sent = fake(listing(three));
    render(<AgentVersions agent={agent()} onRestored={vi.fn()} />);
    expect(sent).toEqual([]);
    expect(screen.queryByText("Version 1")).toBeNull();
  });

  it("lists them newest first saying what each was, and which is in use", async () => {
    fake(listing(three));
    await open();
    const items = screen.getAllByRole("listitem").map((li) => li.textContent ?? "");
    expect(items[0]).toContain("Version 3 (in use): Version 1 put back");
    expect(items[1]).toContain("Version 2: Changed");
    expect(items[2]).toContain("Version 1: Created");
    expect(items[1]).not.toContain("in use");
    expect(screen.queryByRole("button", { name: "See the versions" })).toBeNull();
  });

  it("offers putting back only what is not in use", async () => {
    fake(listing(three));
    await open();
    expect(within(row(3)).queryByRole("button", { name: "Put this version back" })).toBeNull();
    expect(within(row(2)).getByRole("button", { name: "Put this version back" })).toBeTruthy();
  });

  it("says when there are none", async () => {
    fake(listing([]));
    await open([]);
    expect(await screen.findByText("There are no versions yet.")).toBeTruthy();
  });

  it("says when they cannot be read", async () => {
    fake({ [`GET ${root}`]: () => Response.json({ message: "Try again shortly." }, { status: 409 }) });
    await open([]);
    expect((await screen.findByRole("alert")).textContent).toBe("Try again shortly.");
    expect(screen.getByRole("button", { name: "See the versions" })).toBeTruthy();
  });

  it("shows what a version changed, whichever sides it has", async () => {
    fake({ ...listing(three), [`GET ${root}/2/changes`]: () => Response.json({ changes: [{ label: "Name", from: "Old", to: "New" }, { label: "Voice", from: null, to: "Nova" }, { label: "Description", from: "Gone", to: null }, { label: "Another setting", from: null, to: null }] }) });
    await open();
    fireEvent.click(within(row(2)).getByRole("button", { name: "What changed" }));
    expect(await within(row(2)).findByText("Name")).toBeTruthy();
    const lines = within(row(2)).getAllByRole("listitem").map((li) => li.textContent);
    expect(lines).toEqual(["Name: Old → New", "Voice: Nova", "Description: was Gone", "Another setting: Changed"]);
  });

  it("says when nothing listed changed", async () => {
    fake({ ...listing(three), [`GET ${root}/2/changes`]: () => Response.json({ changes: [] }) });
    await open();
    fireEvent.click(within(row(2)).getByRole("button", { name: "What changed" }));
    expect(await within(row(2)).findByText(/Settings not listed here changed/)).toBeTruthy();
  });

  it("shows how calls went beside the version before", async () => {
    fake({ ...listing(three), [`GET ${root}/2/results`]: () => Response.json({ current: window_(), previous: window_({ calls: 0 }) }) });
    await open();
    fireEvent.click(within(row(2)).getByRole("button", { name: "How calls went" }));
    expect(await within(row(2)).findByText("While this version was in use")).toBeTruthy();
    const text = row(2).textContent ?? "";
    expect(text).toContain("Calls4");
    expect(text).toContain("Answered75%");
    expect(text).toContain("Average length2:05");
    expect(text).toContain("Average score87");
    expect(text).toContain("3 positive, 1 neutral, 0 negative");
    expect(within(row(2)).getByText("With the version before")).toBeTruthy();
    expect(within(row(2)).getByText("No calls")).toBeTruthy();
  });

  it("shows what it knows of a version's calls and nothing before the first", async () => {
    fake({ ...listing(three), [`GET ${root}/1/results`]: () => Response.json({ current: window_({ calls: 1, answeredRate: null, avgDurationSec: null, avgScore: null }), previous: null }) });
    await open();
    fireEvent.click(within(row(1)).getByRole("button", { name: "How calls went" }));
    await within(row(1)).findByText("While this version was in use");
    const text = row(1).textContent ?? "";
    expect(text).toContain("Calls1");
    expect(text).not.toContain("Answered");
    expect(text).not.toContain("Average");
    expect(within(row(1)).queryByText("With the version before")).toBeNull();
  });

  it("asks before putting one back, and sends nothing if told not to", async () => {
    const sent = fake(listing(three));
    await open();
    fireEvent.click(within(row(2)).getByRole("button", { name: "Put this version back" }));
    expect(within(row(2)).getByText(/Put version 2 back\? .*saved keys included/)).toBeTruthy();
    expect(sent.some((s) => s.method === "POST")).toBe(false);
    fireEvent.click(within(row(2)).getByRole("button", { name: "Cancel" }));
    expect(within(row(2)).queryByText(/Put version 2 back\?/)).toBeNull();
    expect(sent.some((s) => s.method === "POST")).toBe(false);
  });

  it("puts one back, tells the page, reads the list again and clears what it showed", async () => {
    const saved = agent({ name: "Older name" });
    let lists = 0;
    const after = [version({ version: 4, kind: "restored", restoredFrom: 2, current: true }), ...three.map((v) => ({ ...v, current: false }))];
    const sent = fake({
      [`GET ${root}`]: () => Response.json({ versions: lists++ === 0 ? three : after, lastRestore: lists > 1 ? { version: 2, kept: ["Transfers are not available just now, so it was put back without one."] } : null }),
      [`GET ${root}/2/changes`]: () => Response.json({ changes: [{ label: "Name", from: "Old", to: "New" }] }),
      [`POST ${root}/2/restore`]: () => Response.json(saved),
    });
    const onRestored = await open();
    fireEvent.click(within(row(2)).getByRole("button", { name: "What changed" }));
    await within(row(2)).findByText("Name");
    fireEvent.click(within(row(2)).getByRole("button", { name: "Put this version back" }));
    fireEvent.click(within(row(2)).getByRole("button", { name: "Put this version back" }));
    expect((await screen.findByRole("status")).textContent).toBe("Version 2 is back in use.");
    await screen.findByText("Version 4");
    expect(onRestored).toHaveBeenCalledWith(saved);
    expect(sent.filter((s) => s.method === "POST").map((s) => s.path)).toEqual([`${root}/2/restore`]);
    expect(screen.getByText("Transfers are not available just now, so it was put back without one.")).toBeTruthy();
    expect(within(row(2)).queryByText("Name")).toBeNull();
    expect(within(row(2)).queryByText(/Put version 2 back\?/)).toBeNull();
  });

  it("says when it is still going through", async () => {
    fake({ ...listing(three), [`POST ${root}/2/restore`]: () => Response.json(agent({ syncState: "pending" })) });
    await open();
    fireEvent.click(within(row(2)).getByRole("button", { name: "Put this version back" }));
    fireEvent.click(within(row(2)).getByRole("button", { name: "Put this version back" }));
    expect((await screen.findByRole("status")).textContent).toContain("shortly");
  });

  it("says why it was refused, and tells the page nothing", async () => {
    fake({ ...listing(three), [`POST ${root}/2/restore`]: () => Response.json({ message: "That version could not be put back." }, { status: 409 }) });
    const onRestored = await open();
    fireEvent.click(within(row(2)).getByRole("button", { name: "Put this version back" }));
    fireEvent.click(within(row(2)).getByRole("button", { name: "Put this version back" }));
    expect((await screen.findByRole("alert")).textContent).toBe("That version could not be put back.");
    expect(onRestored).not.toHaveBeenCalled();
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("says what the last putting back left as it was", async () => {
    fake(listing(three, ["It handed over to an agent that has since been deleted, so that was taken off."]));
    await open();
    await waitFor(() => expect(screen.getByText(/has since been deleted/)).toBeTruthy());
  });
});

describe("a call's outcome", () => {
  it("reads the words the voice platform really writes", () => {
    expect(outcomeText("answered")).toBe("Answered");
    expect(outcomeText("no-answer")).toBe("No answer");
    expect(outcomeText("busy")).toBe("Busy");
    expect(outcomeText("failed")).toBe("Failed");
    expect(outcomeText(null)).toBe("");
  });

  it("reads another word as words", () => {
    expect(outcomeText("call-back_later")).toBe("call back later");
  });
});
