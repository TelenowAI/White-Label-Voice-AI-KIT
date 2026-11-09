/**
 * An agent's logo (agents/AgentLogo.tsx on its page; agents/Agents.tsx in the list), against a fake backend: the logo shown, or
 * that there is none; choosing an image offers only PNG, JPEG and WebP and sends it as it is chosen, multipart/form-data with the
 * one field `file`, no content type set by hand and the XSRF-TOKEN cookie echoed, and the logo answered is shown while what is
 * being typed in the agent's form stays; a refused image (422 `errors.file`) says why beside the chooser and keeps the logo; any
 * other failure is said; removing it is a DELETE and leaves none. In the list each agent's logo is small beside its name,
 * decorative, and hidden when it does not load.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { Agent, ConsoleHome, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";
import { agentSettings, settingsForm } from "./agentSettings";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

beforeEach(() => {
  document.cookie = "XSRF-TOKEN=tok%3D%3D; path=/";
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/";
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const signedIn = {
  "GET /api/kit/session": { user: ana },
  "GET /api/kit/console/home": home,
  "GET /api/kit/console/workspaces": one,
  "GET /api/kit/console/catalog": { llm: [], stt: [], tts: [], defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null } },
  "GET /api/kit/console/agents/settings-form": settingsForm,
  "GET /api/kit/console/agents/a1/webhooks": { webhooks: [], events: [] },
  "GET /api/kit/console/agents/a1/web-chat": { allowed: false, priced: true, enabled: false, websites: [], title: null, greeting: null, snippet: null },
  "GET /api/kit/console/knowledge": { knowledgeBases: [], limits: { fileMaxBytes: 20971520, fileTypes: ["pdf", "txt"], textMaxLength: 500000 } },
  "GET /api/kit/console/call-health?minutes=60": { windowMinutes: 60, callsAffected: 0, checkedAt: "2026-11-09T10:00:00Z", failures: [] },
};

const OLD = "https://kit.example.com/api/kit/media/agent-logos/a1/0123456789abcdef0123456789abcdef.png";
const NEW = "https://kit.example.com/api/kit/media/agent-logos/a1/fedcba9876543210fedcba9876543210.webp";
const LOGO = "/api/kit/console/agents/a1/logo";

function agent(over: Partial<Agent> = {}): Agent {
  return {
    id: "a1", name: "Front desk", description: null, systemPrompt: "You are the front desk.", isActive: true,
    stack: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    settings: agentSettings,
    stackNames: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null },
    stackCheck: null, knowledgeBases: [], logoUrl: OLD, syncState: "synced", syncError: null,
    createdAt: "2026-11-09T09:00:00Z", updatedAt: "2026-11-09T09:00:00Z", ...over,
  };
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

const logoRegion = () => screen.findByRole("region", { name: "Logo" });
const image = new File([new Uint8Array([0x52, 0x49, 0x46, 0x46])], "front-desk.webp", { type: "image/webp" });

describe("an agent's logo on its page", () => {
  it("shows it, sends a chosen image as multipart `file`, shows the one answered, keeps what is typed, and removes it", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents/a1": agent(),
      [`POST ${LOGO}`]: () => Response.json(agent({ logoUrl: NEW, updatedAt: "2026-11-09T11:00:00Z" })),
      [`DELETE ${LOGO}`]: () => Response.json(agent({ logoUrl: null, updatedAt: "2026-11-09T11:05:00Z" })),
    });
    open("/console/agents/a1");
    const region = await logoRegion();
    expect(within(region).getByRole("img", { name: "The logo of Front desk" }).getAttribute("src")).toBe(OLD);
    fireEvent.change(screen.getByLabelText("Instructions"), { target: { value: "Being typed, not saved." } });

    const chooser = within(region).getByLabelText("Choose an image") as HTMLInputElement;
    expect(chooser.type).toBe("file");
    expect(chooser.accept).toBe("image/png,image/jpeg,image/webp");
    fireEvent.change(chooser, { target: { files: [image] } });
    await waitFor(() => expect(within(region).getByRole("img", { name: "The logo of Front desk" }).getAttribute("src")).toBe(NEW));
    const upload = sent.find((s) => s.method === "POST" && s.path === LOGO)!;
    const form = upload.raw as FormData;
    expect(form).toBeInstanceOf(FormData);
    expect([...form.keys()]).toEqual(["file"]);
    expect(form.get("file")).toBe(image);
    expect(upload.headers["content-type"], "the browser sets the boundary").toBeUndefined();
    expect(upload.headers["x-xsrf-token"]).toBe("tok==");
    expect((screen.getByLabelText("Instructions") as HTMLTextAreaElement).value, "the form keeps what is being typed").toBe("Being typed, not saved.");

    fireEvent.click(within(region).getByRole("button", { name: "Remove the logo" }));
    expect(await within(region).findByText("No logo yet.")).toBeTruthy();
    expect(within(region).queryByRole("img")).toBeNull();
    expect(within(region).queryByRole("button", { name: "Remove the logo" })).toBeNull();
    const removal = sent.find((s) => s.method === "DELETE" && s.path === LOGO)!;
    expect(removal.body).toBeUndefined();
    expect(removal.headers["x-xsrf-token"]).toBe("tok==");
    expect(sent.filter((s) => s.path === LOGO).map((s) => s.method)).toEqual(["POST", "DELETE"]);
  });

  it("says why an image was refused beside the chooser and keeps the logo; says any other failure", async () => {
    let answer = () => Response.json({ message: "The logo is not taken.", errors: { file: ["The logo must be a PNG, JPEG or WebP image."] } }, { status: 422 });
    fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent(), [`POST ${LOGO}`]: () => answer() });
    open("/console/agents/a1");
    const region = await logoRegion();
    const chooser = within(region).getByLabelText("Choose an image");
    fireEvent.change(chooser, { target: { files: [new File(["<svg/>"], "logo.svg", { type: "image/svg+xml" })] } });
    const said = await within(region).findByText("The logo must be a PNG, JPEG or WebP image.");
    expect(said.className).toBe("error");
    expect(chooser.getAttribute("aria-invalid")).toBe("true");
    expect(chooser.getAttribute("aria-describedby")).toBe(said.id);
    expect(within(region).getByRole("img", { name: "The logo of Front desk" }).getAttribute("src")).toBe(OLD);

    answer = () => Response.json({ message: "The logo could not be kept now." }, { status: 503 });
    fireEvent.change(within(region).getByLabelText("Choose an image"), { target: { files: [image] } });
    expect((await within(region).findByRole("alert")).textContent).toBe("The logo could not be kept now.");
    expect(within(region).queryByText("The logo must be a PNG, JPEG or WebP image.")).toBeNull();
  });

  it("says there is none, and offers no removal", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/agents/a1": agent({ logoUrl: null }) });
    open("/console/agents/a1");
    const region = await logoRegion();
    expect(within(region).getByText("No logo yet.")).toBeTruthy();
    expect(within(region).queryByRole("img")).toBeNull();
    expect(within(region).queryByRole("button", { name: "Remove the logo" })).toBeNull();
  });
});

describe("logos in the agents list", () => {
  it("shows each agent's logo small beside its name, decorative, and hides one that does not load", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/console/agents?page=1": { agents: [agent(), agent({ id: "a2", name: "Night desk", logoUrl: null })], total: 2, page: 1, perPage: 50 },
    });
    open("/console/agents");
    const front = (await screen.findByRole("link", { name: "Front desk" })).closest("td")!;
    const night = screen.getByRole("link", { name: "Night desk" }).closest("td")!;
    const thumb = front.querySelector("img")!;
    expect(thumb.getAttribute("src")).toBe(OLD);
    expect(thumb.getAttribute("alt")).toBe("");
    expect(thumb.className).toBe("agent-logo-thumb");
    expect(night.querySelector("img")).toBeNull();
    fireEvent.error(thumb);
    expect(thumb.hidden).toBe(true);
    expect(within(front).getByRole("link", { name: "Front desk" })).toBeTruthy();
  });
});
