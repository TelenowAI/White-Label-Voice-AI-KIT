/**
 * App.tsx, the shell: visitors go to /setup until the kit is installed, and /setup opens the admin panel once it
 * is; the console and admin trees both render; unknown paths land on the console; the brand is applied and shown;
 * an unreachable backend says so; the console asks nothing but the kit API.
 */
import { cleanup, render, screen } from "@testing-library/react";
import { MemoryRouter, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { Brand, Health } from "../src/api";
import { App } from "../src/App";
import { brand, fakeKit, setupState, step } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  document.documentElement.removeAttribute("style");
  document.title = "";
});

const installed: Health = { status: "ok", installed: true, version: "0.1.0-dev" };
const notInstalled: Health = { ...installed, installed: false };

function serve(health: Health | number, brandAnswer: Brand = brand) {
  return fakeKit({
    "GET /api/kit/health": typeof health === "number" ? () => Response.json({}, { status: health }) : health,
    "GET /api/kit/brand": brandAnswer,
    "GET /api/kit/setup": setupState([step({ id: "environment", status: "done", access: "anyone", submittable: false }), step({ id: "database", access: "anyone" })]),
    "GET /api/kit/session": { user: null },
  });
}

function Where() {
  return <output data-testid="where">{useLocation().pathname}</output>;
}

function open(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <Where />
    </MemoryRouter>,
  );
}

describe("App", () => {
  it("sends every visitor to the setup wizard while the kit is not installed", async () => {
    serve(notInstalled);
    open("/admin/users");
    expect(await screen.findByRole("heading", { name: "Set up Acme Voice" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/setup");
  });

  it("opens the admin panel from /setup once the kit is installed", async () => {
    serve(installed);
    open("/setup");
    expect(await screen.findByText("The admin panel is for the platform's staff.")).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/admin");
  });

  it("renders the admin tree for /admin paths", async () => {
    serve(installed);
    open("/admin/brand");
    expect(await screen.findByRole("heading", { name: "Sign in to continue" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/admin/brand");
  });

  it("lands an unknown path on the console", async () => {
    serve(installed);
    open("/nowhere");
    expect(await screen.findByText("Sign in to your account.")).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console");
  });

  it("applies the brand and shows its name when there is no logo", async () => {
    serve(installed);
    open("/console");
    await screen.findByText("Sign in to your account.");
    expect(document.documentElement.style.getPropertyValue("--color-primary")).toBe("#112233");
    expect(document.title).toBe("Acme Voice");
    expect(screen.getByText("Acme Voice")).toBeTruthy();
  });

  it("shows the logo when the brand has one", async () => {
    serve(installed, { ...brand, logoUrl: "/brand/logo.svg" });
    open("/console");
    const logo = await screen.findByRole("img", { name: "Acme Voice" });
    expect(logo.getAttribute("src")).toBe("/brand/logo.svg");
  });

  it("says the platform is unreachable when the backend fails", async () => {
    serve(500);
    open("/console");
    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toContain("/api/kit/health answered 500");
  });

  it("asks only the kit API", async () => {
    const sent = serve(notInstalled);
    open("/setup");
    await screen.findByRole("heading", { name: "Set up Acme Voice" });
    expect(sent.length).toBeGreaterThan(0);
    expect(sent.every((s) => s.path.startsWith("/api/kit/"))).toBe(true);
  });
});
