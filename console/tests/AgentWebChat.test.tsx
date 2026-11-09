/**
 * Console → one agent → its chat box on the customer's website (agents/AgentWebChat.tsx): no form while the operator
 * does not offer web chat; what is saved, loaded and saved again (one website per line, blank lines dropped); the code
 * for each page, copied or, when the browser cannot, selected; refusals at their fields (a website's with its address),
 * a 409 as an alert; and the note while the plan has no price for a chat.
 *
 * Not here: the operator's switch (Voices.test.tsx, admin → catalog), a chat in the call history (Calls.test.tsx), what
 * the backend does (laravel/ tests).
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Agent, WebChat } from "../src/api";
import { AgentWebChat } from "../src/agents/AgentWebChat";
import { agentSettings } from "./agentSettings";
import { fakeKit } from "./fakeKit";

const agent = { id: "a1", name: "Front desk", settings: agentSettings } as unknown as Agent;
const path = "/api/kit/console/agents/a1/web-chat";
const snippet = '<script src="https://voice.acme.example/chat.js" data-chat="ck_123" async></script>';
const chat = (over: Partial<WebChat> = {}): WebChat => ({ allowed: true, priced: true, enabled: false, websites: [], title: null, greeting: null, snippet: null, ...over });

/** The reasons shown for a field, by the field's label: the element its aria-describedby names. */
function refusalAt(label: string): string | null | undefined {
  const id = screen.getByLabelText(label).getAttribute("aria-describedby");
  return id ? document.getElementById(id)?.textContent : null;
}

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  delete (navigator as { clipboard?: unknown }).clipboard;
});

describe("an agent's chat box for the customer's website", () => {
  it("says the platform does not offer web chat yet, and offers no form", async () => {
    fakeKit({ [`GET ${path}`]: chat({ allowed: false, snippet }) });
    render(<AgentWebChat agent={agent} />);
    expect(await screen.findByText("Web chat is not offered on this service yet.")).toBeTruthy();
    expect(screen.queryByRole("form", { name: "Web chat" })).toBeNull();
    expect(screen.queryByLabelText("Code for your website")).toBeNull();
  });

  it("loads what is saved, and saves it with one website per line, blank lines dropped", async () => {
    const sent = fakeKit({
      [`GET ${path}`]: chat({ websites: ["https://old.example.com"], greeting: "Hello! Ask us anything." }),
      [`PUT ${path}`]: (body: unknown) => Response.json(chat({ ...(body as Partial<WebChat>), snippet })),
    });
    render(<AgentWebChat agent={agent} />);
    const form = await screen.findByRole("form", { name: "Web chat" });
    const websites = within(form).getByLabelText("Websites") as HTMLTextAreaElement;
    expect(websites.value).toBe("https://old.example.com");
    expect((within(form).getByLabelText("Greeting") as HTMLTextAreaElement).value).toBe("Hello! Ask us anything.");
    expect((within(form).getByLabelText("Chat box on") as HTMLInputElement).checked).toBe(false);
    expect((within(form).getByLabelText("Title") as HTMLInputElement).placeholder).toBe("Empty: the agent's name, Front desk");
    expect(screen.queryByLabelText("Code for your website"), "no code until it is first turned on").toBeNull();
    expect(screen.queryByText(/no price for a chat/)).toBeNull();

    fireEvent.click(within(form).getByLabelText("Chat box on"));
    fireEvent.change(websites, { target: { value: " https://www.example.com \n\n  \nhttps://shop.example.com\n" } });
    fireEvent.change(within(form).getByLabelText("Title"), { target: { value: " Ask Acme " } });
    fireEvent.change(within(form).getByLabelText("Greeting"), { target: { value: "  " } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));

    expect((await screen.findByRole("status")).textContent).toBe("Saved.");
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ enabled: true, websites: ["https://www.example.com", "https://shop.example.com"], title: "Ask Acme", greeting: null });
    expect(websites.value).toBe("https://www.example.com\nhttps://shop.example.com");
    expect((screen.getByLabelText("Code for your website") as HTMLTextAreaElement).value).toBe(snippet);
  });

  it("shows the code to put on each page, read-only, and copies it", async () => {
    const writeText = vi.fn(async () => undefined);
    Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
    fakeKit({ [`GET ${path}`]: chat({ enabled: true, websites: ["https://www.example.com"], snippet }) });
    render(<AgentWebChat agent={agent} />);
    const code = await screen.findByLabelText("Code for your website") as HTMLTextAreaElement;
    expect(code.value).toBe(snippet);
    expect(code.readOnly).toBe(true);
    expect(screen.getByText("Put this before </body> on each page of those websites.")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Copy" }));
    expect(await screen.findByRole("button", { name: "Copied" })).toBeTruthy();
    expect(writeText).toHaveBeenCalledWith(snippet);
  });

  it("selects the code when the browser cannot copy it", async () => {
    Object.defineProperty(navigator, "clipboard", { value: undefined, configurable: true });
    fakeKit({ [`GET ${path}`]: chat({ enabled: true, websites: ["https://www.example.com"], snippet }) });
    render(<AgentWebChat agent={agent} />);
    const code = await screen.findByLabelText("Code for your website") as HTMLTextAreaElement;
    fireEvent.click(screen.getByRole("button", { name: "Copy" }));
    expect(await screen.findByText("Selected: copy it with your keyboard.")).toBeTruthy();
    expect(document.activeElement).toBe(code);
    expect([code.selectionStart, code.selectionEnd]).toEqual([0, snippet.length]);
    expect(screen.queryByRole("button", { name: "Copied" })).toBeNull();
  });

  it("puts each refusal at its field, a website's with its address, and keeps what was typed", async () => {
    fakeKit({
      [`GET ${path}`]: chat(),
      [`PUT ${path}`]: () => Response.json({ message: "The given data was invalid.", errors: {
        enabled: ["Add a website to turn it on."],
        websites: ["At most 20 websites."],
        "websites.1": ["This is not a website address."],
        title: ["The title may be at most 80 characters."],
        greeting: ["The greeting may be at most 500 characters."],
      } }, { status: 422 }),
    });
    render(<AgentWebChat agent={agent} />);
    const form = await screen.findByRole("form", { name: "Web chat" });
    fireEvent.change(within(form).getByLabelText("Websites"), { target: { value: "https://www.example.com\n\nexample dot com" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(refusalAt("Websites")).toBe("At most 20 websites. example dot com: This is not a website address."));
    expect(refusalAt("Chat box on")).toBe("Add a website to turn it on.");
    expect(refusalAt("Title")).toBe("The title may be at most 80 characters.");
    expect(refusalAt("Greeting")).toBe("The greeting may be at most 500 characters.");
    expect(within(form).getByLabelText("Websites").getAttribute("aria-invalid")).toBe("true");
    expect((within(form).getByLabelText("Websites") as HTMLTextAreaElement).value).toBe("https://www.example.com\n\nexample dot com");
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("says why turning it on was refused", async () => {
    fakeKit({
      [`GET ${path}`]: chat({ websites: ["https://www.example.com"] }),
      [`PUT ${path}`]: () => Response.json({ message: "This agent is not on the voice platform yet." }, { status: 409 }),
    });
    render(<AgentWebChat agent={agent} />);
    const form = await screen.findByRole("form", { name: "Web chat" });
    fireEvent.click(within(form).getByLabelText("Chat box on"));
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect((await screen.findByRole("alert")).textContent).toBe("This agent is not on the voice platform yet.");
    expect(screen.queryByRole("status")).toBeNull();
  });

  it("says when the plan has no price for a chat, and shows the backend's refusal to turn it on", async () => {
    fakeKit({
      [`GET ${path}`]: chat({ priced: false, websites: ["https://www.example.com"] }),
      [`PUT ${path}`]: () => Response.json({ message: "Your plan has no price for a chat." }, { status: 409 }),
    });
    render(<AgentWebChat agent={agent} />);
    const form = await screen.findByRole("form", { name: "Web chat" });
    expect(screen.getByText("Your plan has no price for a chat yet, so the chat box cannot be turned on, and one that is on does not answer.")).toBeTruthy();
    fireEvent.click(within(form).getByLabelText("Chat box on"));
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect((await screen.findByRole("alert")).textContent).toBe("Your plan has no price for a chat.");
  });

  it("says when it cannot be read, and offers no form", async () => {
    fakeKit({ [`GET ${path}`]: () => Response.json({ message: "Try again shortly." }, { status: 500 }) });
    render(<AgentWebChat agent={agent} />);
    expect((await screen.findByRole("alert")).textContent).toBe("Try again shortly.");
    expect(screen.queryByRole("form", { name: "Web chat" })).toBeNull();
  });
});
