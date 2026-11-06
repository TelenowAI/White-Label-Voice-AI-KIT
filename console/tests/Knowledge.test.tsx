/**
 * Console → Knowledge (knowledge/) and an agent's knowledge: the bases in the console's words (counts, the agents using
 * them), making one; a base's documents (saving, being read, ready, failed and why, with a retry), adding text, a file
 * (too big refused before it is sent) or a web page, the backend's word on a field, deleting a document or the base only
 * after asking; and the bases an agent answers from, saved all at once.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, ConsoleHome, KnowledgeBase, KnowledgeDocument, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { agentSettings } from "./agentSettings";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const limits = { fileMaxBytes: 1024 * 1024, fileTypes: ["pdf", "txt", "csv"], textMaxLength: 500000 };
const nothingOffered = { llm: [], stt: [], tts: [], defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null } };

function base(over: Partial<KnowledgeBase> & { id: string }): KnowledgeBase {
  return { name: "Clinic FAQ", description: "What callers ask most", documentCounts: { total: 3, ready: 1, working: 0, failed: 2 },
    agents: [{ id: "a1", name: "Front desk" }], syncState: "synced", syncError: null, createdAt: "2026-10-30T14:00:00Z", ...over };
}

function doc(over: Partial<KnowledgeDocument> & { id: string; title: string }): KnowledgeDocument {
  return { kind: "text", source: null, chars: 40, state: "ready", problem: null, createdAt: "2026-10-30T14:00:00Z", ...over };
}

const faq = base({ id: "k1" });
const docs = [
  doc({ id: "d1", title: "Opening hours", state: "failed", problem: "This document could not be read for your agents. Try again, or ask support." }),
  doc({ id: "d2", title: "prices", kind: "file", source: "prices.csv" }),
  doc({ id: "d3", title: "https://example.com/", kind: "page", source: "https://example.com/", state: "working" }),
  doc({ id: "d4", title: "Parking", kind: "file", source: "parking.txt", state: "saving" }),
];
const signedIn = {
  "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/catalog": nothingOffered,
};

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function open(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<><ConsoleArea /><Where /></>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("the knowledge bases", () => {
  it("lists each base with its documents and the agents using it, and makes a new one", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/knowledge": { knowledgeBases: [faq, base({ id: "k2", name: "Pricing", description: null, documentCounts: { total: 0, ready: 0, working: 0, failed: 0 }, agents: [], syncState: "pending" })], limits },
      "POST /api/kit/console/knowledge": (body: unknown) => (body as { name: string }).name
        ? Response.json(base({ id: "k3", name: "Insurance" }), { status: 201 })
        : Response.json({ message: "x", errors: { name: ["The Name field is required."] } }, { status: 422 }),
      "GET /api/kit/console/knowledge/k3": { ...base({ id: "k3", name: "Insurance" }), documents: [] },
    });
    open("/console/knowledge");
    const rows = within(await screen.findByRole("table", { name: "Knowledge" })).getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").map((c) => c.textContent))).toEqual([
      ["Clinic FAQ · What callers ask most", "3 documents: 1 ready, 0 being read, 2 failed", "Front desk"],
      ["PricingSaving…", "0 documents: 0 ready, 0 being read, 0 failed", ""],
    ]);
    const form = screen.getByRole("form", { name: "New knowledge base" });
    fireEvent.click(within(form).getByRole("button", { name: "Make knowledge base" }));
    expect(await within(form).findByText("The Name field is required.")).toBeTruthy();
    fireEvent.change(within(form).getByLabelText("Name"), { target: { value: " Insurance " } });
    fireEvent.click(within(form).getByRole("button", { name: "Make knowledge base" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/knowledge/k3"));
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({ name: "Insurance", description: null });
  });
});

describe("a knowledge base", () => {
  const answers = (more: Record<string, unknown> = {}) => ({
    ...signedIn,
    "GET /api/kit/console/knowledge": { knowledgeBases: [faq], limits },
    "GET /api/kit/console/knowledge/k1": { ...faq, documents: docs },
    ...more,
  });

  it("shows each document in the console's words, retries a failed one and deletes one only after asking", async () => {
    const sent = fakeKit(answers({
      "POST /api/kit/console/knowledge/k1/documents/d1/retry": doc({ id: "d1", title: "Opening hours", state: "working" }),
      "DELETE /api/kit/console/knowledge/k1/documents/d2": () => new Response(null, { status: 204 }),
    }));
    open("/console/knowledge/k1");
    const rows = within(await screen.findByRole("table", { name: "Documents" })).getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(0, 3).map((c) => c.textContent))).toEqual([
      ["Opening hours", "Text", "FailedThis document could not be read for your agents. Try again, or ask support."],
      ["prices · prices.csv", "File", "Ready"],
      ["https://example.com/", "Web page", "Being read"],
      ["Parking · parking.txt", "File", "Saving…"],
    ]);
    expect(within(rows[1]).queryByRole("button", { name: "Retry" })).toBeNull();
    fireEvent.click(within(rows[0]).getByRole("button", { name: "Retry" }));
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/console/knowledge/k1/documents/d1/retry")).toBe(true));
    fireEvent.click(within(rows[1]).getByRole("button", { name: "Remove" }));
    expect(within(rows[1]).getByText("Delete prices? Your agents stop using it.")).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(within(rows[1]).getByRole("button", { name: "Yes, delete" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/console/knowledge/k1/documents/d2"]));
  });

  it("adds text, a file (one too big never sent) and a web page, with the backend's word on a field", async () => {
    const sent = fakeKit(answers({
      "POST /api/kit/console/knowledge/k1/documents": (body: unknown) => (body as { text: string }).text
        ? Response.json(doc({ id: "d5", title: "Hours", state: "working" }), { status: 201 })
        : Response.json({ message: "x", errors: { text: ["The Text field is required."] } }, { status: 422 }),
      "POST /api/kit/console/knowledge/k1/documents/upload": () => Response.json(doc({ id: "d6", title: "notes", kind: "file", source: "notes.txt", state: "working" }), { status: 201 }),
      "POST /api/kit/console/knowledge/k1/documents/page": () => Response.json({ message: "x", errors: { url: ["Use a page address that starts with https://."] } }, { status: 422 }),
    }));
    open("/console/knowledge/k1");
    const text = await screen.findByRole("form", { name: "Add text" });
    fireEvent.change(within(text).getByLabelText("Title"), { target: { value: " Hours " } });
    fireEvent.click(within(text).getByRole("button", { name: "Add text" }));
    expect(await within(text).findByText("The Text field is required.")).toBeTruthy();
    fireEvent.change(within(text).getByLabelText("Text"), { target: { value: "Nine to six." } });
    fireEvent.click(within(text).getByRole("button", { name: "Add text" }));
    await waitFor(() => expect(sent.filter((s) => s.path === "/api/kit/console/knowledge/k1/documents").pop()?.body).toEqual({ title: "Hours", text: "Nine to six." }));

    expect(screen.getByText("PDF, TXT, CSV, up to 1 MB.")).toBeTruthy();
    const file = screen.getByLabelText("File") as HTMLInputElement;
    fireEvent.change(file, { target: { files: [new File([new Uint8Array(limits.fileMaxBytes + 1)], "big.pdf")] } });
    expect((await screen.findByRole("alert")).textContent).toBe("That file is over 1 MB.");
    expect(sent.some((s) => s.path.endsWith("/upload"))).toBe(false);
    const notes = new File(["Parking is free."], "notes.txt", { type: "text/plain" });
    fireEvent.change(file, { target: { files: [notes] } });
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/console/knowledge/k1/documents/upload")).toBe(true));
    const upload = sent.find((s) => s.path.endsWith("/upload"))!;
    expect((upload.raw as FormData).get("file")).toBe(notes);
    expect(upload.headers["content-type"]).toBeUndefined(); // the browser sets the multipart boundary

    const page = screen.getByRole("form", { name: "Add a web page" });
    fireEvent.change(within(page).getByLabelText("Page address (https://…)"), { target: { value: " http://example.com " } });
    fireEvent.click(within(page).getByRole("button", { name: "Add page" }));
    expect(await within(page).findByText("Use a page address that starts with https://.")).toBeTruthy();
    expect(sent.filter((s) => s.path.endsWith("/page")).map((s) => s.body)).toEqual([{ url: "http://example.com" }]);
  });

  it("deletes the base only after asking, naming the agents that stop answering from it", async () => {
    const sent = fakeKit(answers({ "DELETE /api/kit/console/knowledge/k1": () => new Response(null, { status: 204 }) }));
    open("/console/knowledge/k1");
    fireEvent.click(await screen.findByRole("button", { name: "Delete knowledge base" }));
    expect(screen.getByText("Delete Clinic FAQ? Front desk stop answering from it.")).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    const ask = screen.getByText("Delete Clinic FAQ? Front desk stop answering from it.").parentElement!;
    fireEvent.click(within(ask).getByRole("button", { name: "Yes, delete" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/knowledge"));
    expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/console/knowledge/k1"]);
  });
});

describe("an agent's knowledge", () => {
  const agent: Agent = {
    id: "a1", name: "Front desk", description: null, systemPrompt: null, isActive: true, syncState: "synced", syncError: null,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: agentSettings,
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [{ id: "k1", name: "Clinic FAQ" }], createdAt: "2026-10-30T09:00:00Z", updatedAt: "2026-10-30T09:00:00Z",
  };

  it("ticks the bases it answers from and saves the whole set", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent,
      "GET /api/kit/console/knowledge": { knowledgeBases: [faq, base({ id: "k2", name: "Pricing", agents: [] })], limits },
      "PUT /api/kit/console/agents/a1/knowledge": (body: unknown) => Response.json({
        knowledgeBases: (body as { knowledgeBaseIds: string[] }).knowledgeBaseIds.map((id) => ({ id, name: id === "k1" ? "Clinic FAQ" : "Pricing" })),
        syncState: "pending", syncError: "Not saved yet. It will be tried again shortly.",
      }),
    });
    open("/console/agents/a1");
    const form = await screen.findByRole("form", { name: "Knowledge" });
    expect((within(form).getByLabelText("Answers from Clinic FAQ") as HTMLInputElement).checked).toBe(true);
    expect((within(form).getByLabelText("Answers from Pricing") as HTMLInputElement).checked).toBe(false);
    fireEvent.click(within(form).getByLabelText("Answers from Clinic FAQ"));
    fireEvent.click(within(form).getByLabelText("Answers from Pricing"));
    fireEvent.click(within(form).getByRole("button", { name: "Save knowledge" }));
    expect((await within(form).findByRole("status")).textContent).toBe("Not saved yet. It will be tried again shortly.");
    expect(sent.filter((s) => s.method === "PUT").map((s) => s.body)).toEqual([{ knowledgeBaseIds: ["k2"] }]);
  });
});
