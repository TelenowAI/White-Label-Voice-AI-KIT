/**
 * The embeddable chat box (laravel/public/chat/widget.js, the script a customer puts on their own website) run in
 * jsdom: its source is read from disk and run as a page would, with a <script data-key> as document.currentScript and
 * a fake fetch answering contract/kit-api.yaml's getChatBox, sendChatMessage, getChatMessages and endChatConversation.
 * Covers: nothing drawn when the chat box is refused; the launcher and panel in the brand's colours and the server's
 * words (and no words of its own); opening, Esc and focus; sending, storing and restoring a conversation; 409, 410,
 * 422, 429, 503 and no answer; ending; server and visitor text shown as text, never as HTML; loading twice; the agent's
 * logo beside the title (decorative), none when there is none or it is not an http(s) address, and taken away when it
 * does not load, the box going on without it.
 * Not here: the backend that answers (Laravel's tests) or the console screen that makes the snippet.
 */
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";

// jsdom's URL is not Node's, so the path is joined by hand.
const SOURCE = readFileSync(resolve(dirname(fileURLToPath(import.meta.url)), "../../laravel/public/chat/widget.js"), "utf8");

const KEY = "ck-7f3a9b";
const API = `https://kit.example.com/api/kit/chat/${KEY}`;
const STORE = `chat:${KEY}`;

/** A chat box in French, so a word the script made up itself would stand out. */
function chatBox() {
  return {
    title: "Acme <b>aide</b>",
    logoUrl: null as string | null,
    greeting: "Bonjour ! Comment puis-je aider ?",
    colors: {
      primary: "#0a7d5a",
      "primary-contrast": "#fefefe",
      background: "#fffdf8",
      surface: "#eef3f1",
      text: "#102030",
      muted: "#5a6470",
    },
    words: {
      open: "Discuter",
      close: "Fermer",
      placeholder: "Votre message",
      send: "Envoyer",
      waiting: "Un instant…",
      failed: "Échec de l'envoi",
      busy: "Occupé",
      tooFast: "Trop vite",
      ended: "Discussion terminée",
      newChat: "Nouvelle discussion",
      endChat: "Terminer",
      tooLong: "Message trop long",
      unavailable: "Indisponible",
      you: "Vous",
    },
    messageMaxLength: 20,
  };
}

type Answer = { status: number; body?: unknown; until?: Promise<unknown> } | "network";
type Call = { method: string; url: string; init: RequestInit; body: unknown };

let answers: Record<string, Answer[]>;
let calls: Call[];
let unanswered: string[];

/** Queue answers for `METHOD /path` (the path after /api/kit/chat/<key>; "" is the chat box). */
function answer(route: string, ...queue: Answer[]) {
  (answers[route] ??= []).push(...queue);
}

async function fakeFetch(url: string, init: RequestInit = {}) {
  const method = init.method ?? "GET";
  const path = url.startsWith(API) ? url.slice(API.length).split("?")[0] : url;
  calls.push({ method, url, init, body: typeof init.body === "string" ? JSON.parse(init.body) : undefined });
  const next = answers[`${method} ${path}`]?.shift();
  if (!next) {
    unanswered.push(`${method} ${url}`);
    throw new TypeError("no answer");
  }
  if (next === "network") throw new TypeError("Failed to fetch");
  await next.until;
  return new Response(JSON.stringify(next.body ?? {}), { status: next.status, headers: { "Content-Type": "application/json" } });
}

let warn: MockInstance<typeof console.warn>;
let log: MockInstance<typeof console.log>;
let error: MockInstance<typeof console.error>;

beforeEach(() => {
  answers = {};
  calls = [];
  unanswered = [];
  vi.stubGlobal("fetch", vi.fn(fakeFetch));
  warn = vi.spyOn(console, "warn").mockImplementation(() => {});
  log = vi.spyOn(console, "log").mockImplementation(() => {});
  error = vi.spyOn(console, "error").mockImplementation(() => {});
  sessionStorage.clear();
});

afterEach(() => {
  const logged = JSON.stringify([...warn.mock.calls, ...log.mock.calls, ...error.mock.calls]);
  vi.useRealTimers();
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.querySelectorAll("kit-chat, script").forEach((e) => e.remove());
  document.body.textContent = "";
  expect(logged).not.toContain("conv-");
  expect(logged).not.toContain(KEY);
  expect(unanswered).toEqual([]);
});

/** Runs the script as a page would: a <script src=… data-key> that is document.currentScript (unless `current` is false). */
function load({ current = true, src = "https://kit.example.com/chat/widget.js" } = {}) {
  const script = document.createElement("script");
  script.src = src;
  script.setAttribute("data-key", KEY);
  script.async = true;
  document.head.appendChild(script);
  Object.defineProperty(document, "currentScript", { configurable: true, get: () => (current ? script : null) });
  try {
    new Function(SOURCE)();
  } finally {
    delete (document as { currentScript?: unknown }).currentScript;
  }
  return script;
}

const host = () => document.querySelector("kit-chat");
const root = () => host()!.shadowRoot!;
function q<T extends Element = HTMLElement>(selector: string): T {
  const found = root().querySelector<T>(selector);
  if (!found) throw new Error(`no ${selector}`);
  return found;
}
const launcher = () => q<HTMLButtonElement>("button[aria-expanded]");
const panel = () => q('[role="dialog"]');
const textarea = () => q<HTMLTextAreaElement>("textarea");
const sendButton = () => q<HTMLButtonElement>('button[type="submit"]');
const texts = (selector: string) => [...root().querySelectorAll(selector)].map((e) => e.textContent);
const agentTexts = () => texts(".m.a .t");
const visitorTexts = () => texts(".m.v .t");
const notices = () => texts(".n p");
const stored = () => JSON.parse(sessionStorage.getItem(STORE) ?? "{}") as { c?: string | null; o?: boolean };
const sends = () => calls.filter((c) => c.method === "POST" && c.url === `${API}/messages`);

async function drawn() {
  await vi.waitFor(() => expect(host()?.shadowRoot?.querySelector("button[aria-expanded]")).toBeTruthy());
}

function type(text: string, { shift = false } = {}) {
  textarea().value = text;
  textarea().dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", shiftKey: shift, bubbles: true, cancelable: true }));
}

/** Lets pending answers through without moving the (fake) clock. */
async function until(check: () => boolean) {
  for (let i = 0; i < 500 && !check(); i++) await new Promise((r) => setImmediate(r));
  expect(check()).toBe(true);
}

/** A promise and its resolve, to hold an answer back. */
function gate() {
  let open!: () => void;
  const until = new Promise<void>((r) => (open = r));
  return { until, open };
}

describe("chat widget", () => {
  it.each([
    ["unknown or off (404)", { status: 404, body: { message: "Introuvable" } } as Answer],
    ["refused for this website (403)", { status: 403, body: { message: "Refusé" } } as Answer],
    ["unreachable", "network" as Answer],
  ])("draws nothing when the chat box is %s, with one warning naming no key", async (_, refusal) => {
    answer("GET ", refusal);
    load();
    await vi.waitFor(() => expect(warn).toHaveBeenCalledTimes(1));
    await new Promise((r) => setTimeout(r, 20));
    expect(host()).toBeNull();
    expect(document.body.children).toHaveLength(0);
    expect(warn).toHaveBeenCalledTimes(1);
  });

  it("asks the server the script came from, with no cookies", async () => {
    answer("GET ", { status: 200, body: chatBox() });
    load();
    await drawn();
    expect(calls).toHaveLength(1);
    expect(calls[0].url).toBe(API);
    expect(calls[0].init).toMatchObject({ method: "GET", credentials: "omit", mode: "cors" });
  });

  it("draws a launcher and a panel in the brand's colours and the server's words, inside its own shadow root", async () => {
    const stylesBefore = document.querySelectorAll("style, link").length;
    answer("GET ", { status: 200, body: chatBox() });
    load();
    await drawn();

    expect(launcher().textContent).toBe("Discuter");
    expect(launcher().getAttribute("aria-expanded")).toBe("false");
    expect(panel().hidden).toBe(true);
    expect(panel().getAttribute("aria-label")).toBe("Acme <b>aide</b>");
    expect(q("h2").textContent).toBe("Acme <b>aide</b>");
    expect(q('[aria-live="polite"]')).toBeTruthy();
    expect(q('button[aria-label="Fermer"]')).toBeTruthy();
    expect(texts("button")).toContain("Terminer");
    expect(textarea().placeholder).toBe("Votre message");
    expect(textarea().maxLength).toBe(20);
    expect(sendButton().textContent).toBe("Envoyer");
    // The greeting is the agent's first bubble, and is never sent.
    expect(agentTexts()).toEqual(["Bonjour ! Comment puis-je aider ?"]);
    expect(calls).toHaveLength(1);

    const vars = q(".r").style;
    expect(["--p", "--pc", "--bg", "--sf", "--tx", "--mu"].map((v) => vars.getPropertyValue(v))).toEqual([
      "#0a7d5a", "#fefefe", "#fffdf8", "#eef3f1", "#102030", "#5a6470",
    ]);
    expect(root().querySelector("style")?.textContent).toMatch(/z-index:\s*2147483/);
    expect(root().querySelector("style")?.textContent).toMatch(/prefers-reduced-motion/);
    expect(root().querySelector("style")?.textContent).toMatch(/max-width:\s*479/);
    expect(document.querySelectorAll("style, link")).toHaveLength(stylesBefore);
    expect(host()!.getAttribute("style")).toMatch(/all:\s*initial/);
  });

  it("falls back for missing colours but never for missing words", async () => {
    answer("GET ", { status: 200, body: { ...chatBox(), colors: {}, words: {}, greeting: null } });
    load();
    await drawn();
    expect(q(".r").style.getPropertyValue("--p")).toMatch(/^#[0-9a-f]{6}$/i);
    expect(launcher().textContent).toBe("");
    expect(sendButton().textContent).toBe("");
    expect(textarea().placeholder).toBe("");
    expect(agentTexts()).toEqual([]);
  });

  it("shows no words of its own: every text and label comes from the chat box", async () => {
    sessionStorage.setItem(STORE, JSON.stringify({ c: "conv-old", o: false }));
    answer("GET ", { status: 200, body: chatBox() });
    answer("GET /messages", { status: 200, body: { conversation: "conv-old", messages: [] } });
    answer("POST /messages", { status: 503, body: { message: "x" } }, { status: 410, body: { message: "x" } });
    load();
    await drawn();
    launcher().click();
    await vi.waitFor(() => expect(sendButton().disabled).toBe(false));
    type("un");
    await vi.waitFor(() => expect(notices()).toContain("Indisponible"));
    type("deux");
    await vi.waitFor(() => expect(notices()).toContain("Discussion terminée"));

    const box = chatBox();
    const allowed = new Set<string>([box.title, box.greeting, ...Object.values(box.words), "un", "deux", "×"]);
    const seen: string[] = [];
    const walker = document.createTreeWalker(root(), NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (n.parentElement?.closest("style")) continue;
      if (n.textContent?.trim()) seen.push(n.textContent);
    }
    for (const e of root().querySelectorAll("*")) {
      for (const a of ["aria-label", "placeholder", "title", "alt"]) if (e.getAttribute(a)) seen.push(e.getAttribute(a)!);
    }
    expect(seen.length).toBeGreaterThan(8);
    expect(seen.filter((s) => !allowed.has(s))).toEqual([]);
  });

  it("opens on the launcher with focus inside; Esc closes and gives focus back to the launcher", async () => {
    answer("GET ", { status: 200, body: chatBox() });
    load();
    await drawn();
    launcher().click();
    expect(panel().hidden).toBe(false);
    expect(launcher().getAttribute("aria-expanded")).toBe("true");
    expect(root().activeElement).toBe(textarea());
    expect(stored().o).toBe(true);

    textarea().dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true }));
    expect(panel().hidden).toBe(true);
    expect(launcher().getAttribute("aria-expanded")).toBe("false");
    expect(root().activeElement).toBe(launcher());
    expect(stored().o).toBe(false);

    launcher().click();
    q<HTMLButtonElement>('button[aria-label="Fermer"]').click();
    expect(panel().hidden).toBe(true);
    expect(root().activeElement).toBe(launcher());
  });

  it("sends a message, shows it at once, stores the conversation and shows the reply", async () => {
    const held = gate();
    answer("GET ", { status: 200, body: chatBox() });
    answer("POST /messages",
      { status: 200, body: { conversation: "conv-tok-1", reply: "Avec plaisir" }, until: held.until },
      { status: 200, body: { conversation: "conv-tok-1", reply: "Encore" } });
    load();
    await drawn();
    launcher().click();

    type("first line", { shift: true });
    expect(sends()).toHaveLength(0);

    type("Bonjour");
    expect(visitorTexts()).toEqual(["Bonjour"]);
    expect(texts(".w")).toEqual(["Un instant…"]);
    expect(textarea().value).toBe("");
    expect(sendButton().disabled).toBe(true);
    // Nothing more goes while one is being answered.
    type("again");
    await vi.waitFor(() => expect(sends()).toHaveLength(1));
    expect(visitorTexts()).toEqual(["Bonjour"]);

    expect(sends()[0].body).toEqual({ message: "Bonjour" });
    expect(sends()[0].init).toMatchObject({ credentials: "omit", mode: "cors" });
    expect(new Headers(sends()[0].init.headers).get("Content-Type")).toBe("application/json");

    held.open();
    await vi.waitFor(() => expect(agentTexts()).toEqual(["Bonjour ! Comment puis-je aider ?", "Avec plaisir"]));
    expect(texts(".w")).toEqual([]);
    expect(stored().c).toBe("conv-tok-1");
    expect(sendButton().disabled).toBe(false);
    expect(texts("button")).toContain("Terminer");
    expect(textarea().value).toBe("again");

    sendButton().click();
    await vi.waitFor(() => expect(agentTexts()).toHaveLength(3));
    expect(sends()[1].body).toEqual({ message: "again", conversation: "conv-tok-1" });
  });

  it("restores a stored conversation, open, without taking the page's focus", async () => {
    sessionStorage.setItem(STORE, JSON.stringify({ c: "conv-old", o: true }));
    answer("GET ", { status: 200, body: chatBox() });
    answer("GET /messages", {
      status: 200,
      body: {
        conversation: "conv-old",
        messages: [
          { role: "visitor", text: "Une question", at: "2026-11-08T10:00:00Z" },
          { role: "agent", text: "Une réponse", at: null },
        ],
      },
    });
    answer("POST /messages", { status: 200, body: { conversation: "conv-old", reply: "Suite" } });
    load();
    await drawn();
    await vi.waitFor(() => expect(agentTexts()).toEqual(["Bonjour ! Comment puis-je aider ?", "Une réponse"]));
    expect(visitorTexts()).toEqual(["Une question"]);
    expect(texts(".m.v .sr")).toEqual(["Vous"]);
    expect(calls[1].url).toBe(`${API}/messages?conversation=conv-old`);
    expect(panel().hidden).toBe(false);
    expect(document.activeElement).toBe(document.body);

    type("Et ensuite ?");
    await vi.waitFor(() => expect(agentTexts()).toContain("Suite"));
    expect(sends()[0].body).toEqual({ message: "Et ensuite ?", conversation: "conv-old" });
  });

  it.each([404, 410])("forgets a stored conversation the server answers %i for", async (status) => {
    sessionStorage.setItem(STORE, JSON.stringify({ c: "conv-gone", o: false }));
    answer("GET ", { status: 200, body: chatBox() });
    answer("GET /messages", { status, body: { message: "x" } });
    answer("POST /messages", { status: 200, body: { conversation: "conv-new", reply: "Nouveau" } });
    load();
    await drawn();
    await vi.waitFor(() => expect(stored().c ?? null).toBeNull());
    expect(agentTexts()).toEqual(["Bonjour ! Comment puis-je aider ?"]);
    type("Re");
    await vi.waitFor(() => expect(agentTexts()).toContain("Nouveau"));
    expect(sends()[0].body).toEqual({ message: "Re" });
  });

  it("on 410 says the chat ended and offers a new one that starts afresh", async () => {
    sessionStorage.setItem(STORE, JSON.stringify({ c: "conv-old", o: true }));
    answer("GET ", { status: 200, body: chatBox() });
    answer("GET /messages", { status: 200, body: { conversation: "conv-old", messages: [{ role: "agent", text: "Vieux", at: null }] } });
    answer("POST /messages", { status: 410, body: { message: "x" } }, { status: 200, body: { conversation: "conv-2", reply: "Neuf" } });
    load();
    await drawn();
    await vi.waitFor(() => expect(agentTexts()).toContain("Vieux"));
    type("Toujours là ?");
    await vi.waitFor(() => expect(notices()).toEqual(["Discussion terminée"]));
    expect(stored().c ?? null).toBeNull();
    expect(textarea().disabled).toBe(true);
    expect(textarea().value).toBe("Toujours là ?");
    const fresh = [...root().querySelectorAll<HTMLButtonElement>(".n button")].find((b) => b.textContent === "Nouvelle discussion")!;
    expect(root().activeElement).toBe(fresh);

    fresh.click();
    expect(agentTexts()).toEqual(["Bonjour ! Comment puis-je aider ?"]);
    expect(notices()).toEqual([]);
    expect(textarea().disabled).toBe(false);
    sendButton().click();
    await vi.waitFor(() => expect(agentTexts()).toContain("Neuf"));
    expect(sends()[1].body).toEqual({ message: "Toujours là ?" });
    expect(stored().c).toBe("conv-2");
  });

  it("on 409 tries once more after a pause, then says it is busy", async () => {
    answer("GET ", { status: 200, body: chatBox() });
    answer("POST /messages", { status: 409, body: { message: "x" } }, { status: 409, body: { message: "x" } });
    load();
    await drawn();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    type("Allô");
    await until(() => vi.getTimerCount() === 1);
    expect(sends()).toHaveLength(1);
    await vi.advanceTimersByTimeAsync(1400);
    expect(sends()).toHaveLength(1);
    expect(texts(".w")).toEqual(["Un instant…"]);
    expect(notices()).toEqual([]);
    await vi.advanceTimersByTimeAsync(200);
    expect(sends()).toHaveLength(2);
    await until(() => notices().length > 0);
    expect(notices()).toEqual(["Occupé"]);
    expect(texts(".w")).toEqual([]);
    expect(textarea().value).toBe("Allô");
    expect(vi.getTimerCount()).toBe(0);
    expect(sends()[1].body).toEqual(sends()[0].body);
  });

  it("on 409 shows the reply when the second try is answered", async () => {
    answer("GET ", { status: 200, body: chatBox() });
    answer("POST /messages", { status: 409, body: { message: "x" } }, { status: 200, body: { conversation: "conv-9", reply: "Enfin" } });
    load();
    await drawn();
    vi.useFakeTimers({ toFake: ["setTimeout", "clearTimeout"] });
    type("Allô");
    await until(() => vi.getTimerCount() === 1);
    await vi.advanceTimersByTimeAsync(1600);
    await until(() => agentTexts().includes("Enfin"));
    expect(notices()).toEqual([]);
    expect(stored().c).toBe("conv-9");
  });

  it.each([
    ["429", { status: 429, body: { message: "x" } } as Answer, "short", "Trop vite"],
    ["503", { status: 503, body: { message: "x" } } as Answer, "short", "Indisponible"],
    ["404 (the chat box turned off while open)", { status: 404, body: { message: "x" } } as Answer, "short", "Indisponible"],
    ["422 for a message over the limit", { status: 422, body: { message: "x", errors: { message: ["x"] } } } as Answer, "x".repeat(25), "Message trop long"],
    ["422 for anything else", { status: 422, body: { message: "x", errors: { message: ["x"] } } } as Answer, "short", "Échec de l'envoi"],
    ["500", { status: 500, body: { message: "x" } } as Answer, "short", "Échec de l'envoi"],
    ["no answer", "network" as Answer, "short", "Échec de l'envoi"],
  ])("on %s says so in the server's words and keeps the unsent text", async (_, refusal, text, said) => {
    answer("GET ", { status: 200, body: chatBox() });
    answer("POST /messages", refusal);
    load();
    await drawn();
    type(text);
    await vi.waitFor(() => expect(notices()).toEqual([said]));
    expect(textarea().value).toBe(text);
    expect(sendButton().disabled).toBe(false);
    expect(stored().c ?? null).toBeNull();
  });

  it("ends the conversation, forgets it and offers a new one", async () => {
    sessionStorage.setItem(STORE, JSON.stringify({ c: "conv-end", o: true }));
    answer("GET ", { status: 200, body: chatBox() });
    answer("GET /messages", { status: 200, body: { conversation: "conv-end", messages: [] } });
    answer("POST /end", { status: 200, body: { ended: true } });
    load();
    await drawn();
    await vi.waitFor(() => expect(sendButton().disabled).toBe(false));
    const endChat = [...root().querySelectorAll<HTMLButtonElement>("button")].find((b) => b.textContent === "Terminer")!;
    expect(endChat.hidden).toBe(false);
    endChat.click();
    await vi.waitFor(() => expect(notices()).toEqual(["Discussion terminée"]));
    expect(calls.at(-1)).toMatchObject({ method: "POST", url: `${API}/end`, body: { conversation: "conv-end" } });
    expect(stored().c ?? null).toBeNull();
    expect(endChat.hidden).toBe(true);
    expect(texts(".n button")).toEqual(["Nouvelle discussion"]);
  });

  it("shows what the server and the visitor write as text, never as HTML", async () => {
    const evil = '<img src=x onerror="globalThis.__chatPwned=1">';
    sessionStorage.setItem(STORE, JSON.stringify({ c: "conv-x", o: true }));
    answer("GET ", { status: 200, body: { ...chatBox(), greeting: evil, words: { ...chatBox().words, open: evil } } });
    answer("GET /messages", { status: 200, body: { conversation: "conv-x", messages: [{ role: "agent", text: evil, at: null }] } });
    answer("POST /messages", { status: 200, body: { conversation: "conv-x", reply: evil } });
    load();
    await drawn();
    await vi.waitFor(() => expect(agentTexts()).toHaveLength(2));
    type(evil);
    await vi.waitFor(() => expect(agentTexts()).toHaveLength(3));
    expect(agentTexts()).toEqual([evil, evil, evil]);
    expect(visitorTexts()).toEqual([evil]);
    expect(launcher().textContent).toBe(evil);
    expect(root().querySelector("img, b")).toBeNull();
    expect((globalThis as { __chatPwned?: number }).__chatPwned).toBeUndefined();
    expect(SOURCE).not.toMatch(/innerHTML|outerHTML|insertAdjacentHTML|document\.write|DOMParser/);
  });

  it("draws once when loaded twice, and leaves nothing on window", async () => {
    const before = new Set(Object.getOwnPropertyNames(window));
    answer("GET ", { status: 200, body: chatBox() });
    load();
    load();
    await drawn();
    await new Promise((r) => setTimeout(r, 20));
    expect(document.querySelectorAll("kit-chat")).toHaveLength(1);
    expect(calls).toHaveLength(1);
    expect(Object.getOwnPropertyNames(window).filter((n) => !before.has(n))).toEqual([]);
  });

  it("finds its own script by data-key when document.currentScript is not set", async () => {
    answer("GET ", { status: 200, body: chatBox() });
    load({ current: false, src: "https://kit.example.com/chat/widget.js?v=3" });
    await drawn();
    expect(calls[0].url).toBe(API);
  });
});

describe("the agent's logo in the chat box", () => {
  const LOGO = "https://kit.example.com/api/kit/media/agent-logos/0b7c/5f0e2a9c4d3b1a0e9f8d7c6b5a4f3e2d.png";

  it("shows it small beside the title, as decoration", async () => {
    answer("GET ", { status: 200, body: { ...chatBox(), logoUrl: LOGO } });
    load();
    await drawn();
    const imgs = root().querySelectorAll("img");
    expect(imgs).toHaveLength(1);
    const logo = imgs[0];
    expect(logo.getAttribute("src")).toBe(LOGO);
    expect(logo.hasAttribute("alt")).toBe(true);
    expect(logo.getAttribute("alt")).toBe("");
    expect(logo.parentElement).toBe(q("h2").parentElement);
    expect(logo.nextElementSibling).toBe(q("h2"));
    expect(launcher().querySelector("img")).toBeNull();
    expect(q("h2").textContent).toBe("Acme <b>aide</b>");
    expect(root().querySelector("style")?.textContent).toMatch(/\.h \.g\{[^}]*width:28px/);
    expect(calls).toHaveLength(1);
  });

  it.each([
    ["none", null],
    ["not an http(s) address", "javascript:globalThis.__chatPwned=1"],
    ["not a string", 42],
  ])("shows no image when it is %s", async (_, logoUrl) => {
    answer("GET ", { status: 200, body: { ...chatBox(), logoUrl } });
    load();
    await drawn();
    expect(root().querySelector("img")).toBeNull();
    expect(q("h2").textContent).toBe("Acme <b>aide</b>");
  });

  it("takes it away when it does not load, and the box goes on", async () => {
    answer("GET ", { status: 200, body: { ...chatBox(), logoUrl: LOGO } });
    answer("POST /messages", { status: 200, body: { conversation: "conv-logo", reply: "Avec plaisir" } });
    load();
    await drawn();
    root().querySelector("img")!.dispatchEvent(new Event("error"));
    expect(root().querySelector("img")).toBeNull();
    expect(q("h2").textContent).toBe("Acme <b>aide</b>");
    launcher().click();
    expect(panel().hidden).toBe(false);
    type("Bonjour");
    await vi.waitFor(() => expect(agentTexts()).toEqual(["Bonjour ! Comment puis-je aider ?", "Avec plaisir"]));
  });
});
