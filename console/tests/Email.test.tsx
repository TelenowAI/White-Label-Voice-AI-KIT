/**
 * Admin → Email (admin/EmailPage.tsx): whether email is on, in words; the site and from address saved (an empty name
 * sent as none); the senders in order, each form drawn from its driver's fields with a saved password never shown and
 * left empty to keep it; adding one (the backend's word when its test failed), moving one up, removing one only after
 * asking; a test through the line; the recent emails in the console's words; no add form at the cap.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { EmailSettings, SetupField } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const owner = { id: 1, name: "Op", email: "op@acme.example", role: "owner" };

function field(over: Partial<SetupField> & { name: string; label: string }): SetupField {
  return { type: "text", required: false, value: null, help: null, options: [], showWhen: null, secretSet: false, ...over };
}

function smtpFields(saved = false): SetupField[] {
  return [
    field({ name: "host", label: "Server", required: true, value: saved ? "smtp.acme.example" : null }),
    field({ name: "port", label: "Port", required: true, value: "587" }),
    field({ name: "encryption", label: "Encryption", type: "select", required: true, value: "tls", options: [{ value: "tls", label: "STARTTLS" }, { value: "none", label: "None" }] }),
    field({ name: "password", label: "Password", type: "secret", secretSet: saved }),
  ];
}

function settings(over: Partial<EmailSettings> = {}): EmailSettings {
  return {
    on: true, siteUrl: "https://voice.acme.example", fromAddress: "hello@acme.example", fromName: "Acme Voice", maxSenders: 5,
    drivers: [{ id: "smtp", name: "SMTP server", fields: smtpFields() }],
    senders: [
      { id: "s1", driver: "smtp", driverName: "SMTP server", label: "smtp.acme.example, port 587 (STARTTLS)", fields: smtpFields(true) },
      { id: "s2", driver: "smtp", driverName: "SMTP server", label: "backup.acme.example, port 465 (TLS)", fields: smtpFields(true) },
    ],
    recent: [
      { id: "e1", kind: "team_invite", kindName: "Team invite", to: "ana@acme.example", status: "sent", attempts: 1, lastError: null, createdAt: "2026-10-30T10:00:00Z", sentAt: "2026-10-30T10:00:01Z" },
      { id: "e2", kind: "password_reset", kindName: "Password reset", to: "ben@acme.example", status: "queued", attempts: 2, lastError: "smtp.acme.example, port 587: refused", createdAt: "2026-10-30T09:00:00Z", sentAt: null },
    ],
    ...over,
  };
}

function admin() {
  render(
    <MemoryRouter initialEntries={["/admin/email"]}>
      <Routes>
        <Route path="/admin/*" element={<AdminArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

const signedIn = { "GET /api/kit/session": { user: owner } };

describe("admin email", () => {
  it("says whether email is on, lists the senders in order and the recent emails in words", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/admin/email": settings() });
    admin();
    expect((await screen.findByText(/^Email is on/)).getAttribute("role")).toBe("status");
    expect(screen.getAllByRole("listitem").map((li) => li.querySelector("span")?.textContent)).toEqual([
      "SMTP server · smtp.acme.example, port 587 (STARTTLS)", "SMTP server · backup.acme.example, port 465 (TLS)",
    ]);
    const rows = within(screen.getByRole("table", { name: "Recent emails" })).getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(0, 3).map((c) => c.textContent))).toEqual([
      ["Team invite", "Sent", "ana@acme.example"],
      ["Password reset", "Waiting to try again · 2 attempts smtp.acme.example, port 587: refused", "ben@acme.example"],
    ]);
  });

  it("says email is off when there is no sender, and saves the site with an empty name as none", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/admin/email": settings({ on: false, senders: [], fromAddress: null, recent: [] }),
      "PUT /api/kit/admin/email": (body: unknown) => Response.json(settings({ on: false, senders: [], ...(body as object) })),
    });
    admin();
    expect(await screen.findByText(/^Email is off/)).toBeTruthy();
    expect(screen.getByText("No sender yet.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Send a test email" })).toBeNull();
    const site = screen.getByRole("form", { name: "Site and sender" });
    fireEvent.change(within(site).getByLabelText("From address"), { target: { value: " hello@acme.example " } });
    fireEvent.click(within(site).getByRole("button", { name: "Save" }));
    expect(await within(site).findByText("Saved.")).toBeTruthy();
    expect(sent.find((s) => s.method === "PUT")?.body).toEqual({ siteUrl: "https://voice.acme.example", fromAddress: "hello@acme.example", fromName: null });
  });

  it("adds a sender from its driver's fields and shows the backend's word when the test failed", async () => {
    let tries = 0;
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/admin/email": settings({ senders: [] }),
      "POST /api/kit/admin/email/senders": () => (++tries === 1
        ? Response.json({ message: "The test email could not be sent: Connection refused" }, { status: 422 })
        : Response.json(settings(), { status: 201 })),
    });
    admin();
    const form = await screen.findByRole("form", { name: "Add and send a test" });
    expect(within(form).getByLabelText("Port")).toHaveProperty("value", "587");
    fireEvent.change(within(form).getByLabelText("Server"), { target: { value: "smtp.acme.example" } });
    fireEvent.change(within(form).getByLabelText("Password"), { target: { value: "relay secret" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add and send a test" }));
    expect((await within(form).findByRole("alert")).textContent).toBe("The test email could not be sent: Connection refused");
    fireEvent.click(within(form).getByRole("button", { name: "Add and send a test" }));
    await waitFor(() => expect(screen.getAllByRole("listitem")).toHaveLength(2));
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({ driver: "smtp", host: "smtp.acme.example", port: "587", encryption: "tls", password: "relay secret" });
    const fresh = screen.getByRole("form", { name: "Add and send a test" });
    expect([within(fresh).getByLabelText("Server"), within(fresh).getByLabelText("Password")].map((i) => (i as HTMLInputElement).value)).toEqual(["", ""]);
  });

  it("edits a sender with its saved password kept when left empty, moves one up and removes one only after asking", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/admin/email": settings(),
      "PUT /api/kit/admin/email/senders/s1": () => Response.json(settings()),
      "PUT /api/kit/admin/email/order": () => Response.json(settings()),
      "DELETE /api/kit/admin/email/senders/s2": () => new Response(null, { status: 204 }),
    });
    admin();
    const [first, second] = await screen.findAllByRole("listitem");
    expect(within(first).queryByRole("button", { name: "Move up" })).toBeNull();
    fireEvent.click(within(first).getByRole("button", { name: "Edit" }));
    const edit = within(first).getByRole("form", { name: "Save and send a test" });
    expect(within(edit).getByLabelText("Password")).toHaveProperty("value", "");
    expect(within(edit).getByText("Saved. Leave empty to keep it.")).toBeTruthy();
    fireEvent.click(within(edit).getByRole("button", { name: "Save and send a test" }));
    await waitFor(() => expect(sent.find((s) => s.path === "/api/kit/admin/email/senders/s1")?.body).toEqual(
      { host: "smtp.acme.example", port: "587", encryption: "tls", password: "" }));

    fireEvent.click(within(second).getByRole("button", { name: "Move up" }));
    await waitFor(() => expect(sent.find((s) => s.path === "/api/kit/admin/email/order")?.body).toEqual({ ids: ["s2", "s1"] }));

    fireEvent.click(within(second).getByRole("button", { name: "Remove" }));
    expect(within(second).getByText("Remove backup.acme.example, port 465 (TLS)?")).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(within(second).getByRole("button", { name: "Yes, delete" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/admin/email/senders/s2"]));
  });

  it("sends a test through the line, and offers no new sender at the cap", async () => {
    fakeKit({
      ...signedIn,
      "GET /api/kit/admin/email": settings({ maxSenders: 2 }),
      "POST /api/kit/admin/email/test": settings().recent[0] && { ...settings().recent[0], kind: "test", kindName: "Test", to: "op@acme.example" },
    });
    admin();
    fireEvent.click(await screen.findByRole("button", { name: "Send a test email" }));
    expect(await screen.findByText("Test sent to op@acme.example.")).toBeTruthy();
    expect(screen.getByText("Up to 2 senders.")).toBeTruthy();
    expect(screen.queryByRole("form", { name: "Add and send a test" })).toBeNull();
  });
});
