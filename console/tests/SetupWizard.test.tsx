/**
 * The setup wizard (setup/): it shows the first step that is not done, rendered only from what the backend
 * declares; it sends only the fields on show; it puts a refused field's reasons beside it; an owner step asks a
 * visitor who is not the signed-in owner to sign in; a blocked step shows its findings and no form; the last step
 * hands over to the shell.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { SetupState } from "../src/api";
import { SetupWizard } from "../src/setup/SetupWizard";
import { brand, fakeKit, setupState, step } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
  document.cookie = "XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT";
});

const environment = step({ id: "environment", title: "Server check", status: "done", access: "anyone", submittable: false });
const database = step({
  id: "database",
  title: "Database",
  access: "anyone",
  fields: [
    {
      name: "connection", label: "Database type", type: "select", required: true, value: "sqlite", help: null,
      options: [{ value: "sqlite", label: "sqlite" }, { value: "pgsql", label: "pgsql" }], showWhen: null, secretSet: false,
    },
    {
      name: "host", label: "Host", type: "text", required: true, value: null, help: null, options: [],
      showWhen: { field: "connection", values: ["pgsql"] }, secretSet: false,
    },
    {
      name: "database", label: "Database name", type: "text", required: true, value: "kit.sqlite", help: "For SQLite, the file.",
      options: [], showWhen: { field: "connection", values: ["sqlite", "pgsql"] }, secretSet: false,
    },
  ],
});
const telenow = step({
  id: "telenow",
  title: "Telenow account",
  fields: [{ name: "apiKey", label: "API key", type: "secret", required: true, value: null, help: "An owner key.", options: [], showWhen: null, secretSet: false }],
});
const finish = step({ id: "finish", title: "Finish" });

function wizard(props: Partial<{ onChanged: () => void; onInstalled: () => void }> = {}) {
  // Inside a router, as the app always renders it (main.tsx).
  render(
    <MemoryRouter initialEntries={["/setup"]}>
      <SetupWizard brand={brand} onChanged={props.onChanged ?? (() => {})} onInstalled={props.onInstalled ?? (() => {})} />
    </MemoryRouter>,
  );
}

describe("SetupWizard", () => {
  it("opens on the first step that is not done, with every step listed", async () => {
    fakeKit({ "GET /api/kit/setup": setupState([environment, database, telenow, finish]) });
    wizard();
    expect(await screen.findByRole("heading", { name: "Database" })).toBeTruthy();
    const nav = screen.getByRole("navigation", { name: "Setup steps" });
    expect(within(nav).getAllByRole("listitem").map((li) => li.textContent)).toEqual([
      "Server checkDone", "DatabaseTo do", "Telenow accountTo do", "FinishTo do",
    ]);
    expect(within(nav).getByRole("button", { name: /Telenow account/ }).hasAttribute("disabled")).toBe(true);
  });

  it("shows a field only while the field it depends on has one of its values, and sends only those", async () => {
    const sent = fakeKit({
      "GET /api/kit/setup": setupState([environment, database, telenow, finish]),
      "POST /api/kit/setup/steps/database": setupState([environment, { ...database, status: "done" }, telenow, finish]),
    });
    document.cookie = "XSRF-TOKEN=tok%3D";
    wizard();
    await screen.findByRole("heading", { name: "Database" });
    expect(screen.queryByLabelText("Host")).toBeNull();

    fireEvent.change(screen.getByLabelText("Database type"), { target: { value: "pgsql" } });
    fireEvent.change(screen.getByLabelText("Host"), { target: { value: "db.internal" } });
    fireEvent.change(screen.getByLabelText("Database type"), { target: { value: "sqlite" } });
    fireEvent.click(screen.getByRole("button", { name: "Save and continue" }));

    expect(await screen.findByRole("heading", { name: "Telenow account" })).toBeTruthy();
    const post = sent.find((s) => s.method === "POST")!;
    expect(post.body).toEqual({ connection: "sqlite", database: "kit.sqlite" });
    expect(post.headers["x-xsrf-token"]).toBe("tok=");
  });

  it("puts a refused field's reasons beside it", async () => {
    fakeKit({
      "GET /api/kit/setup": setupState([environment, database, telenow, finish]),
      "POST /api/kit/setup/steps/database": () =>
        Response.json({ message: "Could not connect.", errors: { database: ["Could not connect: refused"] } }, { status: 422 }),
    });
    wizard();
    await screen.findByRole("heading", { name: "Database" });
    fireEvent.click(screen.getByRole("button", { name: "Save and continue" }));

    const input = await screen.findByLabelText("Database name");
    await vi.waitFor(() => expect(input.getAttribute("aria-invalid")).toBe("true"));
    expect(document.getElementById(input.getAttribute("aria-describedby")!.split(" ").pop()!)?.textContent).toBe("Could not connect: refused");
  });

  it("shows a refusal that names no field as a message", async () => {
    fakeKit({
      "GET /api/kit/setup": setupState([environment, database, telenow, finish]),
      "POST /api/kit/setup/steps/database": () => Response.json({ message: "Finish \"Server check\" first." }, { status: 409 }),
    });
    wizard();
    await screen.findByRole("heading", { name: "Database" });
    fireEvent.click(screen.getByRole("button", { name: "Save and continue" }));
    expect((await screen.findByRole("alert")).textContent).toBe('Finish "Server check" first.');
  });

  it("asks a visitor who is not the signed-in owner to sign in before an owner step, then shows it", async () => {
    const state = setupState([environment, { ...database, status: "done" }, telenow, finish], { ownerExists: true });
    const owner = { id: 1, name: "Op", email: "op@example.com", role: "owner" };
    let signedIn: typeof owner | null = null;
    fakeKit({
      "GET /api/kit/setup": state,
      "GET /api/kit/session": () => Response.json({ user: signedIn }),
      "POST /api/kit/session": () => Response.json({ user: (signedIn = owner) }),
    });
    wizard();
    expect(await screen.findByRole("heading", { name: "Sign in to continue" })).toBeTruthy();
    expect(screen.queryByLabelText("API key")).toBeNull();

    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "op@example.com" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "pw" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByLabelText("API key")).toBeTruthy();
  });

  it("keeps a saved secret when its field is left empty, and says so", async () => {
    // Even a field declared required: a saved secret left empty is kept (contract/kit-api.yaml SetupField.secretSet).
    const saved = { ...telenow, status: "done" as const, fields: [{ ...telenow.fields[0], required: true, secretSet: true }] };
    fakeKit({
      "GET /api/kit/setup": setupState([environment, { ...database, status: "done" }, saved, finish], { ownerExists: true }),
      "GET /api/kit/session": { user: { id: 1, name: "Op", email: "op@example.com", role: "owner" } },
    });
    wizard();
    await screen.findByRole("heading", { name: "Finish" });
    fireEvent.click(screen.getByRole("button", { name: /Telenow account/ }));
    const input = await screen.findByLabelText("API key");
    expect(input.hasAttribute("required")).toBe(false);
    expect(screen.getByText("Saved. Leave empty to keep it.")).toBeTruthy();
  });

  it("shows a blocked step's findings and no form", async () => {
    const blocked = {
      ...environment,
      status: "blocked" as const,
      checks: [{ id: "writable:env", label: ".env cannot be written", level: "blocking" as const, detail: null }],
    };
    fakeKit({ "GET /api/kit/setup": setupState([blocked, database, telenow, finish]) });
    wizard();
    expect(await screen.findByText(".env cannot be written")).toBeTruthy();
    expect(screen.getByText(/Fix what is marked as blocking/)).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Save and continue" })).toBeNull();
  });

  it("hands over to the shell when the last step answers installed", async () => {
    const onInstalled = vi.fn();
    const done = (s: typeof telenow) => ({ ...s, status: "done" as const });
    const before: SetupState = setupState([environment, done(database), done(telenow), finish], { ownerExists: true });
    fakeKit({
      "GET /api/kit/setup": before,
      "GET /api/kit/session": { user: { id: 1, name: "Op", email: "op@example.com", role: "owner" } },
      "POST /api/kit/setup/steps/finish": { ...before, installed: true },
    });
    wizard({ onInstalled });
    fireEvent.click(await screen.findByRole("button", { name: "Finish setup" }));
    await vi.waitFor(() => expect(onInstalled).toHaveBeenCalledOnce());
  });
});
