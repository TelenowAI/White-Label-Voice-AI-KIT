/*
 * The chat box a customer puts on their own website with one line:
 *   <script src="https://<kit host>/chat/widget.js" data-key="<chatKey>" async></script>
 * Visitors chat with the customer's agent; every message goes to the server this script came from (the origin of its
 * own src), which relays it. That server is this platform's: the chat box shows only its title, greeting, words and
 * colours, all from getChatBox, in the operator's language — this file holds no words of its own. The agent's logo, when
 * it has one (`logoUrl`, an http(s) address), is a small decorative image beside the title; one that does not load is
 * taken away, and the box goes on without it.
 *
 * What it is: one self-contained script (an IIFE; nothing left on window), drawn inside a Shadow DOM with its styles,
 * so it never touches the page's styles. What it is not: no framework, no build step, no cookies, and no state beyond
 * the conversation token and whether the box is open, kept in sessionStorage under one key per chat box.
 *
 * Served from the kit's public folder (laravel/public/chat/widget.js), copied into every release (scripts/release.mjs;
 * scripts/check-release.mjs requires it). Calls contract/kit-api.yaml: getChatBox (GET /api/kit/chat/{chatKey}),
 * sendChatMessage and getChatMessages (POST / GET .../messages), endChatConversation (POST .../end).
 * Tested by console/tests/ChatWidget.test.ts.
 */
(() => {
  "use strict";
  const d = document;
  const OURS = /\/chat\/widget\.js([?#]|$)/;
  let me = d.currentScript;
  if (!me || !me.hasAttribute("data-key")) {
    me = [...d.querySelectorAll("script[data-key]")].filter((s) => OURS.test(s.src)).pop();
  }
  // Loaded twice (two snippets, or a page that re-adds it): the first one draws the box.
  if (!me || d.querySelector("[data-kit-chat]")) return;
  me.setAttribute("data-kit-chat", "");

  const warn = (status) => console.warn("chat/widget.js", status);
  const key = me.getAttribute("data-key") || "";
  let origin = "";
  try {
    origin = new URL(me.src, d.baseURI).origin;
  } catch {
    origin = "";
  }
  if (!key || !/^https?:/.test(origin)) return warn(0);
  const base = `${origin}/api/kit/chat/${encodeURIComponent(key)}`;

  /** One request; never throws: `{ s: status (0 for no answer), j: body or null }`. */
  async function call(method, path, body) {
    const init = { method, credentials: "omit", mode: "cors", headers: { Accept: "application/json" } };
    if (body) {
      init.headers["Content-Type"] = "application/json";
      init.body = JSON.stringify(body);
    }
    try {
      const r = await fetch(base + path, init);
      let j = null;
      try {
        j = await r.json();
      } catch {
        j = null;
      }
      return { s: r.status, j };
    } catch {
      return { s: 0, j: null };
    }
  }

  const STORE = `chat:${key}`;
  const saved = (() => {
    try {
      return JSON.parse(sessionStorage.getItem(STORE)) || {};
    } catch {
      return {};
    }
  })();
  let conv = typeof saved.c === "string" && saved.c ? saved.c : null;
  let isOpen = saved.o === true;
  function save() {
    try {
      sessionStorage.setItem(STORE, JSON.stringify({ c: conv, o: isOpen }));
    } catch {
      // Storage refused (private mode, a full quota): the box still works on this page.
    }
  }

  /** Colour roles (getBrand) → the variables the styles use, each with a fallback when the brand lacks it. */
  const COLORS = {
    primary: ["--p", "#4f46e5"],
    "primary-contrast": ["--pc", "#ffffff"],
    background: ["--bg", "#ffffff"],
    surface: ["--sf", "#f6f7f9"],
    text: ["--tx", "#111827"],
    muted: ["--mu", "#6b7280"],
  };

  const CSS_TEXT = `
.r{font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--tx);text-align:start}
*,*::before,*::after{box-sizing:border-box}
[hidden]{display:none!important}
button,textarea{font:inherit;color:inherit;margin:0}
button{cursor:pointer}
:focus-visible{outline:3px solid var(--p);outline-offset:2px}
.h :focus-visible{outline-color:var(--pc);outline-offset:1px}
.l{position:fixed;right:calc(20px + env(safe-area-inset-right,0px));bottom:calc(20px + env(safe-area-inset-bottom,0px));z-index:2147483000;display:flex;align-items:center;gap:8px;min-height:52px;max-width:calc(100vw - 40px);padding:0 18px;border:0;border-radius:26px;background:var(--p);color:var(--pc);font-weight:600;box-shadow:0 4px 16px rgba(0,0,0,.2)}
.l svg{width:22px;height:22px;flex:none;fill:currentColor}
.d{position:fixed;right:calc(20px + env(safe-area-inset-right,0px));bottom:calc(84px + env(safe-area-inset-bottom,0px));z-index:2147483001;display:flex;flex-direction:column;width:min(380px,calc(100vw - 40px));height:min(600px,calc(100vh - 110px));background:var(--bg);color:var(--tx);border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.25);overflow:hidden;animation:in .18s ease-out}
@keyframes in{from{opacity:0;transform:translateY(8px)}}
.h{display:flex;align-items:center;gap:8px;padding:10px 10px 10px 16px;background:var(--p);color:var(--pc)}
.h h2{flex:1;min-width:0;margin:0;font-size:16px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.h .g{flex:none;width:28px;height:28px;border-radius:6px;object-fit:contain}
.h button{background:transparent;border:1px solid currentColor;border-radius:8px;padding:4px 10px;font-size:13px;color:inherit}
.h .x{border:0;font-size:24px;line-height:1;padding:2px 8px}
.ms{flex:1;overflow-y:auto;overscroll-behavior:contain;padding:16px;display:flex;flex-direction:column;gap:8px}
.m{max-width:85%;padding:8px 12px;border-radius:14px;white-space:pre-wrap;overflow-wrap:anywhere}
.a{align-self:flex-start;background:var(--sf);color:var(--tx);border-end-start-radius:4px}
.v{align-self:flex-end;background:var(--p);color:var(--pc);border-end-end-radius:4px}
.v.f{opacity:.6}
.n{align-self:center;display:flex;flex-direction:column;align-items:center;gap:6px;max-width:90%;text-align:center;color:var(--mu);font-size:13px}
.n p{margin:0}
.w{align-self:flex-start;color:var(--mu);font-size:13px;animation:pulse 1.2s ease-in-out infinite}
@keyframes pulse{50%{opacity:.4}}
.sr{position:absolute;display:block;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}
.c{display:flex;gap:8px;padding:10px;border-top:1px solid var(--sf)}
textarea{flex:1;min-width:0;resize:none;min-height:42px;max-height:120px;padding:10px 12px;border:1px solid var(--mu);border-radius:10px;background:var(--bg);color:var(--tx)}
.s{border:0;border-radius:10px;padding:8px 16px;background:var(--p);color:var(--pc);font-weight:600}
button:disabled,textarea:disabled{opacity:.5;cursor:not-allowed}
@media (max-width:479.98px){.d{inset:0;width:auto;height:auto;border-radius:0;padding:env(safe-area-inset-top,0px) env(safe-area-inset-right,0px) env(safe-area-inset-bottom,0px) env(safe-area-inset-left,0px)}}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}`;

  const el = (tag, cls, text) => {
    const e = d.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  };
  const btn = (cls, text) => {
    const b = el("button", cls, text);
    b.type = "button";
    return b;
  };

  let box;
  const w = (k) => (typeof box.words[k] === "string" ? box.words[k] : "");

  function draw() {
    const host = el("kit-chat");
    host.setAttribute("data-kit-chat", "");
    host.style.cssText = "all:initial";
    const root = host.attachShadow({ mode: "open" });
    try {
      const sheet = new CSSStyleSheet();
      sheet.replaceSync(CSS_TEXT);
      root.adoptedStyleSheets = [sheet];
    } catch {
      root.appendChild(el("style", "", CSS_TEXT));
    }
    const r = el("div", "r");
    const colors = box.colors && typeof box.colors === "object" ? box.colors : {};
    const isColor = (v) => typeof v === "string" && v !== "" && (!window.CSS?.supports || window.CSS.supports("color", v));
    for (const [role, [name, fallback]] of Object.entries(COLORS)) {
      r.style.setProperty(name, isColor(colors[role]) ? colors[role] : fallback);
    }

    const launcher = btn("l");
    launcher.setAttribute("aria-expanded", "false");
    launcher.setAttribute("aria-controls", "p");
    const svg = d.createElementNS("http://www.w3.org/2000/svg", "svg");
    svg.setAttribute("viewBox", "0 0 24 24");
    svg.setAttribute("aria-hidden", "true");
    const path = d.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", "M4 3h16a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z");
    svg.appendChild(path);
    launcher.append(svg, el("span", "", w("open")));

    const panel = el("div", "d");
    panel.id = "p";
    panel.hidden = true;
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", box.title);
    const head = el("div", "h");
    const endBtn = btn("e", w("endChat"));
    const closeBtn = btn("x");
    closeBtn.setAttribute("aria-label", w("close"));
    closeBtn.title = w("close");
    const cross = el("span", "", "×");
    cross.setAttribute("aria-hidden", "true");
    closeBtn.appendChild(cross);
    // The agent's logo: decorative (the title names the box), and never in the way when it fails to load.
    if (typeof box.logoUrl === "string" && /^https?:\/\//i.test(box.logoUrl)) {
      const logo = el("img", "g");
      logo.alt = "";
      logo.width = 28;
      logo.height = 28;
      logo.addEventListener("error", () => logo.remove());
      logo.src = box.logoUrl;
      head.appendChild(logo);
    }
    head.append(el("h2", "", box.title), endBtn, closeBtn);
    const list = el("div", "ms");
    list.setAttribute("role", "log");
    list.setAttribute("aria-live", "polite");
    const form = el("form", "c");
    const ta = el("textarea");
    ta.rows = 1;
    ta.dir = "auto";
    ta.placeholder = w("placeholder");
    ta.setAttribute("aria-label", w("placeholder"));
    const max = Number.isInteger(box.messageMaxLength) && box.messageMaxLength > 0 ? box.messageMaxLength : 0;
    if (max) ta.maxLength = max;
    const sendBtn = el("button", "s", w("send"));
    sendBtn.type = "submit";
    form.append(ta, sendBtn);
    panel.append(head, list, form);
    r.append(launcher, panel);
    root.appendChild(r);
    d.body.appendChild(host);

    let busy = false;
    let ended = false;
    let lastNote = null;

    function sync() {
      sendBtn.disabled = busy || ended;
      ta.disabled = ended;
      endBtn.hidden = !conv || ended;
      endBtn.disabled = busy;
    }
    const scroll = () => {
      list.scrollTop = list.scrollHeight;
    };
    function bubble(who, text) {
      const m = el("div", `m ${who}`);
      m.dir = "auto";
      m.append(el("span", "sr", who === "v" ? w("you") : box.title), el("span", "t", text));
      list.appendChild(m);
      scroll();
      return m;
    }
    function note(text, again) {
      const n = el("div", "n");
      n.appendChild(el("p", "", text));
      let b = null;
      if (again) {
        b = btn("s", w("newChat"));
        b.addEventListener("click", fresh);
        n.appendChild(b);
      }
      list.appendChild(n);
      scroll();
      lastNote = again ? null : n;
      return b;
    }
    function greet() {
      if (typeof box.greeting === "string" && box.greeting) bubble("a", box.greeting);
    }
    function over() {
      conv = null;
      ended = true;
      save();
      sync();
      const b = note(w("ended"), true);
      if (isOpen) b.focus();
    }
    function fresh() {
      conv = null;
      ended = false;
      save();
      list.textContent = "";
      lastNote = null;
      greet();
      sync();
      ta.focus();
    }
    function grow() {
      ta.style.height = "auto";
      ta.style.height = `${Math.min(ta.scrollHeight, 120)}px`;
    }

    async function deliver(text, mine, waiting, retried) {
      const body = { message: text };
      if (conv) body.conversation = conv;
      const { s, j } = await call("POST", "/messages", body);
      if (s === 409 && !retried) {
        setTimeout(() => deliver(text, mine, waiting, true), 1500);
        return;
      }
      waiting.remove();
      busy = false;
      if (s === 200 && j && typeof j.conversation === "string") {
        conv = j.conversation;
        save();
        sync();
        if (typeof j.reply === "string" && j.reply) bubble("a", j.reply);
        return;
      }
      mine.classList.add("f");
      if (!ta.value) {
        ta.value = text;
        grow();
      }
      if (s === 410) return over();
      const word = s === 409 ? "busy" : s === 429 ? "tooFast" : s === 503 || s === 404 ? "unavailable" : s === 422 && max && text.length > max ? "tooLong" : "failed";
      note(w(word));
      sync();
    }
    function send() {
      const text = ta.value.trim();
      if (busy || ended || !text) return;
      busy = true;
      lastNote?.remove();
      lastNote = null;
      const mine = bubble("v", text);
      ta.value = "";
      grow();
      sync();
      const waiting = el("div", "w", w("waiting"));
      list.appendChild(waiting);
      scroll();
      deliver(text, mine, waiting, false);
    }
    async function end() {
      if (!conv || busy) return;
      busy = true;
      sync();
      const { s } = await call("POST", "/end", { conversation: conv });
      busy = false;
      // Any answer means this conversation is over for the visitor; with none, it is kept to try again.
      if (s > 0 && s < 500) return over();
      note(w("failed"));
      sync();
    }

    function open(focus) {
      isOpen = true;
      panel.hidden = false;
      launcher.setAttribute("aria-expanded", "true");
      save();
      scroll();
      if (focus) (ta.disabled ? closeBtn : ta).focus();
    }
    function close() {
      isOpen = false;
      panel.hidden = true;
      launcher.setAttribute("aria-expanded", "false");
      save();
      launcher.focus();
    }

    launcher.addEventListener("click", () => (isOpen ? close() : open(true)));
    closeBtn.addEventListener("click", close);
    endBtn.addEventListener("click", end);
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      send();
    });
    ta.addEventListener("input", grow);
    ta.addEventListener("keydown", (e) => {
      if (e.key === "Enter" && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
        e.preventDefault();
        send();
      }
    });
    panel.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && !e.isComposing) {
        e.preventDefault();
        close();
      }
    });

    greet();
    sync();
    if (isOpen) open(false);
    if (conv) restore();

    async function restore() {
      busy = true;
      sync();
      list.setAttribute("aria-busy", "true");
      const { s, j } = await call("GET", `/messages?conversation=${encodeURIComponent(conv)}`);
      busy = false;
      list.removeAttribute("aria-busy");
      if (s === 200 && j && Array.isArray(j.messages)) {
        for (const m of j.messages) {
          const who = m?.role === "visitor" ? "v" : m?.role === "agent" ? "a" : null;
          if (who && typeof m.text === "string") bubble(who, m.text);
        }
      } else if (s === 404 || s === 410) {
        conv = null;
        save();
      }
      sync();
    }
  }

  call("GET", "").then(({ s, j }) => {
    if (s !== 200 || !j || typeof j.title !== "string" || !j.words || typeof j.words !== "object") return warn(s);
    box = j;
    if (d.body) draw();
    else d.addEventListener("DOMContentLoaded", draw);
  });
})();
