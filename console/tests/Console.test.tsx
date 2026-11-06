/**
 * The customer console (console/) and the team panel (team/): an invite link lets someone new choose a name and
 * password, or someone known join with theirs, and a dead link says so; a signed-out visitor signs in; the home shows
 * the account and, when the backend says so, the "service paused" notice; several accounts get a switcher; the team
 * page lets only a manager invite (showing the one-time link), withdraw and remove; admin runs any account's team.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ConsoleHome, Team, WorkspaceList } from "../src/api";
import { CustomerPage } from "../src/admin/CustomerPage";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { brand, fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };

function home(over: Partial<ConsoleHome> = {}): ConsoleHome {
  return { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "owner", canManageTeam: true, paused: null, ...over };
}

const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "owner", current: true }] };

function team(over: Partial<Team> = {}): Team {
  return {
    members: [{ userId: 2, name: "Ana", email: "ana@example.com", role: "owner", joinedAt: "2026-10-29T10:00:00Z" }],
    invites: [{ id: "i1", email: "bo@example.com", role: "member", expiresAt: "2026-11-01T10:00:00Z", createdAt: "2026-10-29T10:00:00Z" }],
    roles: ["owner", "member"],
    canManage: true,
    ...over,
  };
}

function Where() {
  // A span, not an <output>: that one's role is "status", the paused notice's.
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function console_(path: string) {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
      <Where />
    </MemoryRouter>,
  );
}

describe("an invite link", () => {
  it("lets someone new choose a name and password, then opens the console signed in", async () => {
    let user: typeof ana | null = null;
    const sent = fakeKit({
      "GET /api/kit/invites/tok": { email: "ana@example.com", customerName: "Acme Dental", role: "owner", expiresAt: "2026-11-01T10:00:00Z", accountExists: false, choosesPassword: true, signedInAs: null, canJoinNow: false },
      "POST /api/kit/invites/tok/accept": (body: unknown) =>
        (body as { password: string }).password.length < 12
          ? Response.json({ message: "x", errors: { password: ["The Password field must be at least 12 characters."] } }, { status: 422 })
          : Response.json({ user: (user = ana) }),
      "GET /api/kit/session": () => Response.json({ user }),
      "GET /api/kit/console/home": home(),
      "GET /api/kit/console/workspaces": one,
    });
    console_("/console/invite/tok");

    expect(await screen.findByRole("heading", { name: "Join Acme Dental" })).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Your name"), { target: { value: "Ana" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "short" } });
    fireEvent.click(screen.getByRole("button", { name: "Join" }));
    expect(await screen.findByText("The Password field must be at least 12 characters.")).toBeTruthy();

    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "a long password 9" } });
    fireEvent.click(screen.getByRole("button", { name: "Join" }));
    expect(await screen.findByRole("heading", { name: "Welcome to Acme Dental" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console");
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({ name: "Ana", password: "a long password 9" });
  });

  const beta = { email: "ana@example.com", customerName: "Beta Clinic", role: "member", expiresAt: "2026-11-01T10:00:00Z", accountExists: true, choosesPassword: false };

  it("has someone with an account sign in to it, then lets them join with nothing more", async () => {
    let signedIn = false;
    const sent = fakeKit({
      "GET /api/kit/brand": brand,
      "GET /api/kit/invites/tok": () => Response.json({ ...beta, signedInAs: signedIn ? "ana@example.com" : null, canJoinNow: signedIn }),
      "POST /api/kit/session": () => { signedIn = true; return Response.json({ user: ana }); },
      "POST /api/kit/invites/tok/accept": { user: ana },
      "GET /api/kit/session": () => Response.json({ user: signedIn ? ana : null }),
      "GET /api/kit/console/home": home(),
      "GET /api/kit/console/workspaces": one,
    });
    console_("/console/invite/tok");
    await screen.findByRole("heading", { name: "Join Beta Clinic" });
    expect(screen.getByText("You are invited as ana@example.com, which already has an account. Sign in to it to join.")).toBeTruthy();
    expect(screen.queryByLabelText("Your name")).toBeNull();
    expect(screen.queryByRole("button", { name: "Join" })).toBeNull();

    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "ana@example.com" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "anas own password" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));
    fireEvent.click(await screen.findByRole("button", { name: "Join" }));
    expect(screen.queryByLabelText("Password")).toBeNull();
    await screen.findByRole("heading", { name: "Welcome to Acme Dental" });
    expect(sent.find((s) => s.path === "/api/kit/invites/tok/accept")?.body).toEqual({});
  });

  it("offers every way in but signing up, and says when one ends elsewhere", async () => {
    fakeKit({
      "GET /api/kit/brand": { ...brand, signUp: true, signInEmailCode: true, signInLink: true },
      "GET /api/kit/invites/tok": { ...beta, signedInAs: null, canJoinNow: false },
      "GET /api/kit/session": { user: null },
    });
    console_("/console/invite/tok");
    expect(await screen.findByRole("button", { name: "Email me a sign-in code" })).toBeTruthy();
    expect(screen.getByRole("button", { name: "Email me a sign-in link" })).toBeTruthy();
    expect(screen.getByText(/open this invite link again to join/)).toBeTruthy();
    expect(screen.queryByRole("link", { name: "Create an account" })).toBeNull();
  });

  it("does not say so when every way stays on the page", async () => {
    fakeKit({
      "GET /api/kit/brand": { ...brand, signInEmailCode: true },
      "GET /api/kit/invites/tok": { ...beta, signedInAs: null, canJoinNow: false },
      "GET /api/kit/session": { user: null },
    });
    console_("/console/invite/tok");
    expect(await screen.findByRole("button", { name: "Email me a sign-in code" })).toBeTruthy();
    expect(screen.queryByText(/open this invite link again to join/)).toBeNull();
  });

  it("tells someone signed in as another person, and lets them sign out", async () => {
    let signedIn = true;
    const sent = fakeKit({
      "GET /api/kit/brand": brand,
      "GET /api/kit/invites/tok": () => Response.json({ ...beta, signedInAs: signedIn ? "ben@example.com" : null, canJoinNow: false }),
      "DELETE /api/kit/session": () => { signedIn = false; return new Response(null, { status: 204 }); },
      "GET /api/kit/session": () => Response.json({ user: signedIn ? { ...ana, email: "ben@example.com" } : null }),
      "GET /api/kit/console/home": home(),
      "GET /api/kit/console/workspaces": one,
    });
    console_("/console/invite/tok");
    expect(await screen.findByText("You are signed in as ben@example.com, but this invite is for ana@example.com. Sign out, then sign in to that account to join.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Join" })).toBeNull();
    expect(screen.queryByLabelText("Password")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Sign out" }));
    expect(await screen.findByLabelText("Password")).toBeTruthy();
    expect(sent.some((s) => s.method === "POST")).toBe(false);
  });

  it("goes back to signing in when the session ended before joining", async () => {
    let signedIn = true;
    fakeKit({
      "GET /api/kit/brand": brand,
      "GET /api/kit/invites/tok": () => Response.json({ ...beta, signedInAs: signedIn ? "ana@example.com" : null, canJoinNow: signedIn }),
      "POST /api/kit/invites/tok/accept": () => { signedIn = false; return Response.json({ message: "x", errors: { password: ["Sign in first."] } }, { status: 422 }); },
      "GET /api/kit/session": () => Response.json({ user: signedIn ? ana : null }),
    });
    console_("/console/invite/tok");
    fireEvent.click(await screen.findByRole("button", { name: "Join" }));
    expect(await screen.findByLabelText("Password")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Join" })).toBeNull();
  });

  it("asks someone new only for a name when passwords are off", async () => {
    let user: typeof ana | null = null;
    const sent = fakeKit({
      "GET /api/kit/invites/tok": { email: "ana@example.com", customerName: "Acme Dental", role: "owner", expiresAt: "2026-11-01T10:00:00Z", accountExists: false, choosesPassword: false, signedInAs: null, canJoinNow: false },
      "POST /api/kit/invites/tok/accept": () => Response.json({ user: (user = ana) }),
      "GET /api/kit/session": () => Response.json({ user }),
      "GET /api/kit/console/home": home(),
      "GET /api/kit/console/workspaces": one,
    });
    console_("/console/invite/tok");
    expect(await screen.findByText("You are invited as ana@example.com. Choose your name.")).toBeTruthy();
    expect(screen.queryByLabelText("Password")).toBeNull();
    fireEvent.change(screen.getByLabelText("Your name"), { target: { value: "Ana" } });
    fireEvent.click(screen.getByRole("button", { name: "Join" }));
    await screen.findByRole("heading", { name: "Welcome to Acme Dental" });
    expect(sent.find((s) => s.path === "/api/kit/invites/tok/accept")?.body).toEqual({ name: "Ana" });
  });

  it("says when the link is dead", async () => {
    fakeKit({
      "GET /api/kit/invites/old": () => Response.json({ message: "This invite link is not valid." }, { status: 404 }),
      "GET /api/kit/session": { user: null },
    });
    console_("/console/invite/old");
    expect((await screen.findByRole("alert")).textContent).toBe("This invite link is not valid.");
  });
});

describe("the console", () => {
  it("asks a signed-out visitor to sign in, then shows the account's home", async () => {
    let user: typeof ana | null = null;
    fakeKit({
      "GET /api/kit/session": () => Response.json({ user }),
      "POST /api/kit/session": () => Response.json({ user: (user = ana) }),
      "GET /api/kit/console/home": home({ role: "member" }),
      "GET /api/kit/console/workspaces": one,
    });
    console_("/console");
    expect(await screen.findByText("Sign in to your account.")).toBeTruthy();
    fireEvent.change(screen.getByLabelText("Email"), { target: { value: "ana@example.com" } });
    fireEvent.change(screen.getByLabelText("Password"), { target: { value: "pw" } });
    fireEvent.click(screen.getByRole("button", { name: "Sign in" }));

    expect(await screen.findByRole("heading", { name: "Welcome to Acme Dental" })).toBeTruthy();
    expect(screen.getByText("You are member here.")).toBeTruthy();
    expect(screen.queryByRole("status")).toBeNull();
    expect(screen.queryByLabelText("Account")).toBeNull();
  });

  it("shows the backend's notice while the service is paused", async () => {
    fakeKit({
      "GET /api/kit/session": { user: ana },
      "GET /api/kit/console/home": home({ paused: { reason: "service_paused", message: "The service is paused for now." } }),
      "GET /api/kit/console/workspaces": one,
    });
    console_("/console");
    expect((await screen.findByRole("status")).textContent).toBe("The service is paused for now.");
  });

  it("switches between the user's accounts", async () => {
    let current = "w1";
    const list = () => ({
      workspaces: [
        { id: "w1", name: "Acme Dental", role: "owner", current: current === "w1" },
        { id: "w2", name: "Beta Clinic", role: "member", current: current === "w2" },
      ],
    });
    const sent = fakeKit({
      "GET /api/kit/session": { user: ana },
      "GET /api/kit/console/home": () => Response.json(home({ workspace: { id: current, name: current === "w1" ? "Acme Dental" : "Beta Clinic", status: "active" } })),
      "GET /api/kit/console/workspaces": () => Response.json(list()),
      "POST /api/kit/console/workspace": (body: unknown) => {
        current = (body as { id: string }).id;
        return Response.json(list());
      },
    });
    console_("/console");
    await screen.findByRole("heading", { name: "Welcome to Acme Dental" });

    fireEvent.change(screen.getByLabelText("Account"), { target: { value: "w2" } });
    expect(await screen.findByRole("heading", { name: "Welcome to Beta Clinic" })).toBeTruthy();
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ id: "w2" });
  });

  it("tells a user in no account to ask for an invite", async () => {
    fakeKit({
      "GET /api/kit/session": { user: ana },
      "GET /api/kit/console/home": () => Response.json({ message: "nope" }, { status: 403 }),
      "GET /api/kit/console/workspaces": { workspaces: [] },
    });
    console_("/console");
    expect((await screen.findByRole("alert")).textContent).toBe("You are not a member of any account yet. Ask for an invite.");
  });
});

describe("the team panel", () => {
  it("shows a member the team, with nothing to change", async () => {
    fakeKit({
      "GET /api/kit/session": { user: ana },
      "GET /api/kit/console/home": home({ role: "member", canManageTeam: false }),
      "GET /api/kit/console/workspaces": one,
      "GET /api/kit/console/team": team({ canManage: false }),
    });
    console_("/console/team");
    expect(await screen.findByText("bo@example.com")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Remove" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Withdraw" })).toBeNull();
    expect(screen.queryByLabelText("Their email")).toBeNull();
  });

  it("lets a manager invite — the link shown once, with this page's origin — withdraw and remove", async () => {
    const sent = fakeKit({
      "GET /api/kit/session": { user: ana },
      "GET /api/kit/console/home": home(),
      "GET /api/kit/console/workspaces": one,
      "GET /api/kit/console/team": team({ members: [...team().members, { userId: 3, name: "Cy", email: "cy@example.com", role: "member", joinedAt: "2026-10-29T11:00:00Z" }] }),
      "POST /api/kit/console/team/invites": () =>
        Response.json({ invite: { id: "i2", email: "dee@example.com", role: "member", expiresAt: "2026-11-01T10:00:00Z", createdAt: "2026-10-29T12:00:00Z" }, token: "the-secret", emailed: false }, { status: 201 }),
      "DELETE /api/kit/console/team/invites/i1": () => new Response(null, { status: 204 }),
      "DELETE /api/kit/console/team/members/3": () => new Response(null, { status: 204 }),
    });
    console_("/console/team");
    await screen.findByText("cy@example.com");

    expect(Array.from((screen.getByLabelText("Role") as HTMLSelectElement).options).map((o) => o.value)).toEqual(["owner", "member"]);
    fireEvent.change(screen.getByLabelText("Their email"), { target: { value: "dee@example.com" } });
    fireEvent.change(screen.getByLabelText("Role"), { target: { value: "member" } });
    fireEvent.click(screen.getByRole("button", { name: "Create invite link" }));
    const link = (await screen.findByDisplayValue(/\/console\/invite\/the-secret$/)) as HTMLInputElement;
    expect(link.value).toBe(`${window.location.origin}/console/invite/the-secret`);
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ email: "dee@example.com", role: "member" });

    fireEvent.click(within(screen.getByText("bo@example.com").closest("tr")!).getByRole("button", { name: "Withdraw" }));
    fireEvent.click(within(screen.getByText("cy@example.com").closest("tr")!).getByRole("button", { name: "Remove" }));
    await vi.waitFor(() =>
      expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path).sort()).toEqual([
        "/api/kit/console/team/invites/i1",
        "/api/kit/console/team/members/3",
      ]),
    );
  });

  it("says an emailed invite went to the person, and shows no link", async () => {
    fakeKit({
      "GET /api/kit/session": { user: ana },
      "GET /api/kit/console/home": home(),
      "GET /api/kit/console/workspaces": one,
      "GET /api/kit/console/team": team(),
      "POST /api/kit/console/team/invites": () =>
        Response.json({ invite: { id: "i2", email: "dee@example.com", role: "member", expiresAt: "2026-11-01T10:00:00Z", createdAt: "2026-10-29T12:00:00Z" }, token: null, emailed: true }, { status: 201 }),
    });
    console_("/console/team");
    fireEvent.change(await screen.findByLabelText("Their email"), { target: { value: "dee@example.com" } });
    fireEvent.click(screen.getByRole("button", { name: "Create invite link" }));
    expect((await screen.findByText(/^Invite emailed to dee@example\.com\./)).textContent).toBe(
      `Invite emailed to dee@example.com. Its link works once, until ${new Date("2026-11-01T10:00:00Z").toLocaleString()}.`);
    expect(screen.queryByDisplayValue(/\/console\/invite\//)).toBeNull();
    expect(screen.queryByRole("button", { name: "Copy link" })).toBeNull();
  });

  it("says on a customer's own page why it is still waiting", async () => {
    const closing = { id: "w9", name: "Acme Dental", status: "closing", syncState: "pending", syncError: "Waiting for the last calls to be read and charged.",
      telenowSubAccountId: null, createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-29T10:00:00Z" };
    fakeKit({ "GET /api/kit/admin/customers/w9": closing, "GET /api/kit/admin/customers/w9/team": team({ members: [], invites: [] }) });
    render(
      <MemoryRouter initialEntries={["/admin/customers/w9"]}>
        <Routes>
          <Route path="/admin/customers/:id" element={<CustomerPage />} />
        </Routes>
      </MemoryRouter>,
    );
    expect((await screen.findByTestId("sync-error")).textContent).toBe("Waiting for the last calls to be read and charged.");
    expect(screen.getByText(/^Closing ·/)).toBeTruthy();
  });

  it("serves admin for any account, through the admin's own calls", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/customers/w9": { id: "w9", name: "Acme Dental", status: "active", syncState: "synced", syncError: null, telenowSubAccountId: null, createdAt: "2026-10-29T10:00:00Z", updatedAt: "2026-10-29T10:00:00Z" },
      "GET /api/kit/admin/customers/w9/team": team({ members: [], invites: [] }),
      "POST /api/kit/admin/customers/w9/team/invites": () =>
        Response.json({ invite: { id: "i3", email: "ana@example.com", role: "owner", expiresAt: "2026-11-01T10:00:00Z", createdAt: "2026-10-29T12:00:00Z" }, token: "first-user", emailed: false }, { status: 201 }),
    });
    render(
      <MemoryRouter initialEntries={["/admin/customers/w9"]}>
        <Routes>
          <Route path="/admin/customers/:id" element={<CustomerPage />} />
        </Routes>
      </MemoryRouter>,
    );
    expect(await screen.findByRole("heading", { name: "Acme Dental" })).toBeTruthy();
    expect(await screen.findByText("Nobody has joined yet.")).toBeTruthy();
    expect((screen.getByLabelText("Role") as HTMLSelectElement).value).toBe("owner");

    fireEvent.change(screen.getByLabelText("Their email"), { target: { value: "ana@example.com" } });
    fireEvent.click(screen.getByRole("button", { name: "Create invite link" }));
    expect(await screen.findByDisplayValue(/\/console\/invite\/first-user$/)).toBeTruthy();
    expect(sent.find((s) => s.method === "POST")?.path).toBe("/api/kit/admin/customers/w9/team/invites");
  });
});
