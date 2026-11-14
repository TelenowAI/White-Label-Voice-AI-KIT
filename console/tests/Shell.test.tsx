/**
 * The signed-in frame (shell/AppShell.tsx) in the console and the admin panel: the side menu's links in groups under their
 * titles, in the order given, the open page marked; the admin panel's groups keeping its pages' order for a role that sees
 * only some of them; the Menu button that opens the menu on a narrow screen, and how it closes again (its close button,
 * Escape, a link followed) with the focus back on Menu.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ConsoleHome, WorkspaceList } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "admin", canManageTeam: true, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "admin", current: true }] };
const signedIn = { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one };

function console_(path = "/console") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

function admin(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/admin/*" element={<AdminArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

/** Each group of a side menu: its title ("" for none) and its links' words, in order. */
const groupsOf = (nav: HTMLElement) =>
  [...nav.querySelectorAll(".nav-group")].map((g) => [
    g.querySelector(".nav-group-title")?.textContent ?? "",
    [...g.querySelectorAll("a")].map((a) => a.textContent),
  ]);

describe("the console's side menu", () => {
  it("has every page in groups under their titles, and marks the open one", async () => {
    fakeKit(signedIn);
    console_("/console/routing");
    const nav = await screen.findByRole("navigation", { name: "Console" });
    expect(groupsOf(nav)).toEqual([
      ["", ["Home", "Insights"]],
      ["Build", ["Agents", "Knowledge", "Voices"]],
      ["Conversations", ["Calls", "Phone", "Campaigns", "Follow-ups", "Caller memory"]],
      ["Phone numbers", ["Numbers", "Call routing"]],
      ["Compliance", ["Do not call", "Consent"]],
      ["Billing and team", ["Wallet", "Team", "Your account"]],
    ]);
    expect(within(nav).getAllByRole("link").filter((a) => a.getAttribute("aria-current") === "page").map((a) => a.textContent)).toEqual(["Call routing"]);
  });

  it("opens from the Menu button and closes with its close button, Escape or a link followed, the focus back on Menu", async () => {
    fakeKit(signedIn);
    console_();
    const menu = await screen.findByRole("button", { name: "Menu" });
    const nav = screen.getByRole("navigation", { name: "Console" });
    expect(document.getElementById(menu.getAttribute("aria-controls")!)!.contains(nav)).toBe(true);
    expect(menu.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByRole("button", { name: "Close the menu" })).toBeNull();

    fireEvent.click(menu);
    expect(menu.getAttribute("aria-expanded")).toBe("true");
    const close = screen.getByRole("button", { name: "Close the menu" });
    expect(document.activeElement).toBe(close);
    fireEvent.click(close);
    expect(menu.getAttribute("aria-expanded")).toBe("false");
    expect(screen.queryByRole("button", { name: "Close the menu" })).toBeNull();
    expect(document.activeElement).toBe(menu);

    fireEvent.click(menu);
    fireEvent.keyDown(document, { key: "Escape" });
    expect(menu.getAttribute("aria-expanded")).toBe("false");
    expect(document.activeElement).toBe(menu);

    fireEvent.click(menu);
    fireEvent.click(within(nav).getByRole("link", { name: "Team" }));
    await waitFor(() => expect(menu.getAttribute("aria-expanded")).toBe("false"));
    expect(within(nav).getByRole("link", { name: "Team" }).getAttribute("aria-current")).toBe("page");
  });
});

describe("the admin panel's side menu", () => {
  it("shows a role's pages in their groups, in the panel's order, a group with none of them left out", async () => {
    fakeKit({ "GET /api/kit/session": { user: { id: 1, name: "Olu", email: "olu@op.example", role: "admin", abilities: ["reports", "money", "settings", "starters", "audit"] } } });
    admin("/admin/costs");
    const nav = await screen.findByRole("navigation", { name: "Admin" });
    expect(groupsOf(nav)).toEqual([
      ["", ["Dashboard"]],
      ["Prices and payments", ["Cost vs price", "Pricing", "Payments", "Invoices", "Platform billing"]],
      ["What customers get", ["Catalog", "Starter agents"]],
      ["Sign-ups and messages", ["Email", "Texts and WhatsApp", "Sign-in providers", "Bot check"]],
      ["Site and staff", ["Website", "Audit log"]],
    ]);
    expect(screen.getByRole("button", { name: "Sign out" })).toBeTruthy();
  });
});
