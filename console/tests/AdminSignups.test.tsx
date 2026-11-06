/**
 * Admin → Sign-ups (admin/SignupsPage.tsx): whether people can sign up now, and that email is needed; the sign-ups
 * waiting for approval with what they answered, approved, or refused only with a reason asked first; the settings
 * saved whole — the mode (domains only for that mode), the ways in, the operator's own fields (a choice with its
 * choices), the terms, the trial credit in the pricing currency (none without pricing) — with the backend's word on
 * each refused part; the recent sign-ups in words.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { SignupAdmin, SignupSummary } from "../src/api";
import { AdminArea } from "../src/admin/AdminArea";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const owner = { id: 1, name: "Op", email: "op@voice.example", role: "owner" };

function summary(over: Partial<SignupSummary> & { id: string; email: string | null }): SignupSummary {
  return { phone: null, provider: null, name: "Ana", accountName: "Acme Dental", status: "waiting", answers: {}, termsVersion: null, reason: null, workspaceId: null,
    createdAt: "2026-10-30T10:00:00Z", verifiedAt: "2026-10-30T10:05:00Z", decidedAt: null, ...over };
}

function data(over: Partial<SignupAdmin> = {}): SignupAdmin {
  return {
    settings: { mode: "approval", domains: [], methods: ["password"], fields: [], terms: null, trialCredit: null },
    options: { modes: ["closed", "open", "approval", "domains"], methods: ["password", "magic_link"], fieldTypes: ["text", "email", "url", "select"], maxFields: 10 },
    emailOn: true, currency: "INR", open: true,
    waiting: [summary({ id: "s1", email: "ana@dental.example", answers: { phone: "+14155550101" } })],
    recent: [
      summary({ id: "s2", email: "ben@dental.example", name: "Ben", accountName: "Ben Co", status: "rejected", reason: "Not a fit" }),
      summary({ id: "s3", email: "cy@dental.example", name: "Cy", accountName: "Cy Labs", status: "done" }),
    ],
    ...over,
  };
}

function admin() {
  render(
    <MemoryRouter initialEntries={["/admin/signups"]}>
      <Routes>
        <Route path="/admin/*" element={<AdminArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

const signedIn = { "GET /api/kit/session": { user: owner } };

describe("admin sign-ups", () => {
  it("lists those waiting with their answers, approves one, and refuses one only with a reason asked first", async () => {
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/admin/signups": data({ waiting: [summary({ id: "s1", email: "ana@dental.example", answers: { phone: "+14155550101" } }),
        summary({ id: "s4", email: "dee@dental.example", name: "Dee", accountName: "Dee Ltd" })] }),
      "POST /api/kit/admin/signups/s1/approve": () => Response.json(data({ waiting: [summary({ id: "s4", email: "dee@dental.example", name: "Dee", accountName: "Dee Ltd" })] })),
      "POST /api/kit/admin/signups/s4/reject": () => Response.json(data({ waiting: [] })),
    });
    admin();
    expect((await screen.findByText(/^People can sign up now/)).getAttribute("role")).toBe("status");
    const [ana] = screen.getAllByRole("listitem");
    expect(ana.querySelector("p")?.textContent).toBe("Acme Dental · Ana · ana@dental.example phone: +14155550101");
    fireEvent.click(within(ana).getByRole("button", { name: "Approve" }));
    await waitFor(() => expect(sent.some((s) => s.path === "/api/kit/admin/signups/s1/approve")).toBe(true));

    fireEvent.click(within(await screen.findByText(/Dee Ltd/).then((p) => p.closest("li")!)).getByRole("button", { name: "Refuse" }));
    expect(sent.some((s) => s.path.endsWith("/reject"))).toBe(false);
    const item = screen.getByText(/Dee Ltd/).closest("li")!;
    fireEvent.change(within(item).getByLabelText("Reason (only you see it)"), { target: { value: " Not a fit " } });
    fireEvent.click(within(item).getByRole("button", { name: "Yes, refuse" }));
    await waitFor(() => expect(sent.find((s) => s.path === "/api/kit/admin/signups/s4/reject")?.body).toEqual({ reason: "Not a fit" }));
  });

  it("saves the settings whole, with a field of the operator's own, and shows the backend's word on each part", async () => {
    let tries = 0;
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/admin/signups": data({ waiting: [], settings: { mode: "closed", domains: [], methods: ["password"], fields: [], terms: null, trialCredit: null } }),
      "PUT /api/kit/admin/signups/settings": () => (++tries === 1
        ? Response.json({ message: "x", errors: { "fields.0.options": ["A choice field needs its choices."], trialCredit: ["Set up pricing first."] } }, { status: 422 })
        : Response.json(data({ waiting: [] }))),
    });
    admin();
    const f = await screen.findByRole("form", { name: "How people sign up" });
    expect(within(f).queryByLabelText("Email domains")).toBeNull();
    fireEvent.change(within(f).getByLabelText("Who may sign up"), { target: { value: "domains" } });
    fireEvent.change(within(f).getByLabelText("Email domains"), { target: { value: "acme.com, acme.co.in" } });
    fireEvent.click(within(f).getByLabelText("A link emailed each time"));
    fireEvent.click(within(f).getByRole("button", { name: "Add a field" }));
    const row = within(f).getByRole("group", { name: "Field 1" });
    fireEvent.change(within(row).getByLabelText("Label"), { target: { value: " Team size " } });
    fireEvent.change(within(row).getByLabelText("Key"), { target: { value: "size" } });
    fireEvent.change(within(row).getByLabelText("Type"), { target: { value: "select" } });
    fireEvent.click(within(row).getByLabelText("Required"));
    fireEvent.change(within(f).getByLabelText("Trial credit (INR)"), { target: { value: "100" } });
    fireEvent.click(within(f).getByRole("button", { name: "Save" }));
    expect(await within(row).findByText("A choice field needs its choices.")).toBeTruthy();
    expect(within(f).getByText("Set up pricing first.")).toBeTruthy();

    fireEvent.change(within(row).getByLabelText("Choices (comma separated)"), { target: { value: "1-10, 11-50," } });
    fireEvent.change(within(f).getByLabelText("Terms address"), { target: { value: "https://voice.example/terms" } });
    fireEvent.change(within(f).getByLabelText("Version"), { target: { value: "2026-10" } });
    fireEvent.click(within(f).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(tries).toBe(2));
    expect(await screen.findByText("Saved.")).toBeTruthy();
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({
      mode: "domains", domains: ["acme.com", "acme.co.in"], methods: ["password", "magic_link"],
      fields: [{ key: "size", label: "Team size", type: "select", required: true, options: ["1-10", "11-50"] }],
      terms: { url: "https://voice.example/terms", version: "2026-10" }, trialCredit: "100",
    });
  });

  it("offers no trial credit without pricing, says email is needed, and words the recent sign-ups", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/admin/signups": data({ waiting: [], currency: null, emailOn: false, open: false }) });
    admin();
    expect((await screen.findByRole("status")).textContent).toBe(
      "Sign-ups are closed: only invited people can join. Turn on email first (Email): sign-ups are confirmed by email.");
    expect((screen.getByLabelText("Trial credit (set up pricing first)") as HTMLInputElement).disabled).toBe(true);
    const rows = within(screen.getByRole("table", { name: "Recent sign-ups" })).getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(0, 3).map((c) => c.textContent))).toEqual([
      ["Ben Co", "Refused · Not a fit", "ben@dental.example"],
      ["Cy Labs", "Account made", "cy@dental.example"],
    ]);
  });
});
