/**
 * Console → Do not call (dnc/): the list in the console's words (how each number got there, a caller's opt-out linked
 * to its call, one not saved yet or refused), searching it, adding a number (the backend's word on a field), importing
 * a CSV (the backend's counts), downloading the list as a CSV file (exportDnc), and removing a number only after asking — a caller's
 * own opt-out with a plainer warning.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ConsoleHome, DncEntry, DncPage, WorkspaceList } from "../src/api";
import { ConsoleArea } from "../src/console/ConsoleArea";
import { fakeKit } from "./fakeKit";

vi.mock("@telenow/client", () => ({ TelenowCall: class {} }));

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const ana = { id: 2, name: "Ana", email: "ana@example.com", role: null };
const home: ConsoleHome = { workspace: { id: "w1", name: "Acme Dental", status: "active" }, role: "member", canManageTeam: false, paused: null };
const one: WorkspaceList = { workspaces: [{ id: "w1", name: "Acme Dental", role: "member", current: true }] };
const signedIn = { "GET /api/kit/session": { user: ana }, "GET /api/kit/console/home": home, "GET /api/kit/console/workspaces": one };

function entry(over: Partial<DncEntry> & { id: string }): DncEntry {
  return { phoneNumber: "+14155550123", reason: null, source: "manual", callId: null, syncState: "synced", syncError: null, createdAt: "2026-10-30T12:00:00Z", ...over };
}

const list: DncPage = {
  entries: [
    entry({ id: "d1", phoneNumber: "+14155550126", reason: "The caller asked not to be called again", source: "opt_out", callId: "c9" }),
    entry({ id: "d2", phoneNumber: "+14155550124", reason: "From the CRM", source: "csv" }),
    entry({ id: "d3", phoneNumber: "call me maybe", source: "manual", syncState: "failed", syncError: "This is not a phone number that can be dialled." }),
    entry({ id: "d4", phoneNumber: "+14155550199", source: "client" }),
  ],
  total: 4, page: 1, perPage: 50,
};

function open() {
  render(
    <MemoryRouter initialEntries={["/console/dnc"]}>
      <Routes>
        <Route path="/console/*" element={<ConsoleArea />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe("the do-not-call list", () => {
  it("says how each number got there, links an opt-out to its call, and shows one the platform refused", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/dnc?page=1": list });
    open();
    const rows = (await screen.findAllByRole("row")).slice(1);
    expect(rows.map((r) => within(r).getAllByRole("cell").slice(0, 3).map((c) => c.textContent))).toEqual([
      ["+14155550126", "The caller asked not to be called again", "The caller asked"],
      ["+14155550124", "From the CRM", "Imported"],
      ["call me maybeFailedThis is not a phone number that can be dialled.", "", "Added by hand"],
      ["+14155550199", "", "client"],
    ]);
    expect(within(rows[0]).getByRole("link", { name: "The caller asked" }).getAttribute("href")).toBe("/console/calls/c9");
  });

  it("downloads the whole list as a CSV file", async () => {
    fakeKit({ ...signedIn, "GET /api/kit/console/dnc?page=1": list });
    open();
    await screen.findAllByRole("row");
    const link = screen.getByRole("link", { name: "Download CSV" });
    expect(link.getAttribute("href")).toBe("/api/kit/console/dnc/export");
    expect(link.hasAttribute("download")).toBe(true);
  });

  it("searches the list", async () => {
    const sent = fakeKit({ ...signedIn, "GET /api/kit/console/dnc?page=1": list, "GET /api/kit/console/dnc?page=1&search=0124": { ...list, entries: [list.entries[1]], total: 1 } });
    open();
    await screen.findAllByRole("row");
    fireEvent.change(screen.getByLabelText("Search numbers or reasons"), { target: { value: " 0124 " } });
    fireEvent.click(screen.getByRole("button", { name: "Search" }));
    await waitFor(() => expect(screen.getAllByRole("row")).toHaveLength(2));
    expect(sent.map((s) => s.path)).toContain("/api/kit/console/dnc?page=1&search=0124");
  });

  it("adds a number, with the backend's word on a field", async () => {
    let added = false;
    const sent = fakeKit({
      ...signedIn,
      "GET /api/kit/console/dnc?page=1": () => Response.json(added ? { ...list, entries: [entry({ id: "d5" }), ...list.entries] } : list),
      "POST /api/kit/console/dnc": (body: unknown) => {
        if (!(body as { phoneNumber: string }).phoneNumber) {
          return Response.json({ message: "x", errors: { phoneNumber: ["The Number field is required."] } }, { status: 422 });
        }
        added = true;
        return Response.json(entry({ id: "d5" }), { status: 201 });
      },
    });
    open();
    const form = await screen.findByRole("form", { name: "Add to the list" });
    fireEvent.click(within(form).getByRole("button", { name: "Add to the list" }));
    expect(await within(form).findByText("The Number field is required.")).toBeTruthy();
    fireEvent.change(within(form).getByLabelText("Number"), { target: { value: "+1 (415) 555-0123" } });
    fireEvent.change(within(form).getByLabelText("Reason"), { target: { value: "Asked not to be called" } });
    fireEvent.click(within(form).getByRole("button", { name: "Add to the list" }));
    await waitFor(() => expect(screen.getAllByRole("row")).toHaveLength(6));
    expect(sent.filter((s) => s.method === "POST").pop()?.body).toEqual({ phoneNumber: "+1 (415) 555-0123", reason: "Asked not to be called" });
  });

  it("imports a CSV and says what the backend added and skipped", async () => {
    const sent = fakeKit({ ...signedIn, "GET /api/kit/console/dnc?page=1": list, "POST /api/kit/console/dnc/import": { added: 2, skipped: 1 } });
    open();
    await screen.findAllByRole("row");
    const csv = "+14155550124,From the CRM\nnot a number\n+14155550125\n";
    fireEvent.change(screen.getByLabelText("Import a CSV"), { target: { files: [new File([csv], "dnc.csv", { type: "text/csv" })] } });
    expect((await screen.findByRole("status")).textContent).toBe("2 added, 1 skipped (no number that can be dialled).");
    expect(sent.find((s) => s.method === "POST")?.body).toEqual({ csv });
    expect(sent.filter((s) => s.path === "/api/kit/console/dnc?page=1")).toHaveLength(2);
  });

  it("removes a number only after asking, a caller's own opt-out with a plainer warning", async () => {
    const sent = fakeKit({ ...signedIn, "GET /api/kit/console/dnc?page=1": list, "DELETE /api/kit/console/dnc/d1": () => new Response(null, { status: 204 }) });
    open();
    const row = (await screen.findAllByRole("row"))[1];
    fireEvent.click(within(row).getByRole("button", { name: "Remove" }));
    expect(within(row).getByText("+14155550126 asked not to be called. Take it off the list anyway?")).toBeTruthy();
    expect(sent.some((s) => s.method === "DELETE")).toBe(false);
    fireEvent.click(within(row).getByRole("button", { name: "Yes, remove" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/console/dnc/d1"]));

    const other = screen.getAllByRole("row")[2];
    fireEvent.click(within(other).getByRole("button", { name: "Remove" }));
    expect(within(other).getByText("Take +14155550124 off the list?")).toBeTruthy();
  });
});
