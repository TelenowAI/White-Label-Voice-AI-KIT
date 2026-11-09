/**
 * Console → a knowledge base's table (knowledge/KnowledgeTablePage.tsx, linked from KnowledgeBasePage.tsx): only a ready
 * table document links to it; the kind of file, its rows and columns counted; how agents use it (read whole when it is small,
 * otherwise looked up in, and how far finding rows by meaning reaches); each column with how it is read; a page of rows (text,
 * number, true/false and empty cells) and paging 50 at a time by offset (also when the backend names no page size); a kind of
 * file it does not know read as a file; saving only the changed columns' types in a PATCH and showing the columns as answered,
 * Save off while nothing changed; the backend's word on a column by the form, any other refusal as an alert; not a table, not
 * ready yet and the voice platform unreachable said in words; an empty table.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { ConsoleHome, KnowledgeBase, KnowledgeCell, KnowledgeDocument, KnowledgeTable, KnowledgeTableColumn, WorkspaceList } from "../src/api";
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
const limits = { fileMaxBytes: 1024 * 1024, fileTypes: ["pdf", "txt", "csv", "json", "xlsx"], textMaxLength: 500000 };

const faq: KnowledgeBase = {
  id: "k1", name: "Clinic FAQ", description: null, documentCounts: { total: 5, ready: 3, working: 1, failed: 1 }, agents: [],
  syncState: "synced", syncError: null, createdAt: "2026-11-08T09:00:00Z",
};

function doc(over: Partial<KnowledgeDocument> & { id: string; title: string }): KnowledgeDocument {
  return { kind: "file", source: null, chars: 40, state: "ready", problem: null, createdAt: "2026-11-08T09:00:00Z", table: false, ...over };
}

const docs = [
  doc({ id: "d1", title: "Price list", source: "prices.csv", table: true }),
  doc({ id: "d2", title: "Stock", source: "stock.xlsx", table: true, state: "working" }),
  doc({ id: "d3", title: "Old stock", source: "old.json", table: true, state: "failed", problem: "This document could not be read for your agents." }),
  doc({ id: "d4", title: "Parking", source: "parking.txt" }),
  doc({ id: "d5", title: "Hours", kind: "text" }),
];

const TABLE = "/api/kit/console/knowledge/k1/documents/d1/table";
const page = (offset: number) => `GET ${TABLE}?offset=${offset}&limit=50`;
const n = (x: number) => x.toLocaleString();

const columns: KnowledgeTableColumn[] = [
  { key: "item", name: "Item", type: "text" },
  { key: "unit_price", name: "Unit price", type: "text" },
  { key: "qty", name: "Qty", type: "number" },
  { key: "in_stock", name: "In stock", type: "bool" },
  { key: "added_on", name: "Added on", type: "date" },
];

function table(over: Partial<KnowledgeTable> = {}): KnowledgeTable {
  return {
    columns,
    rows: [
      ["Crown", "N/A", 2, true, "2026-01-05"],
      ["Filling", "1200", 0, false, null],
      ["Whitening", "3500.5", 1.5, null, ""],
    ],
    rowCount: 1250, offset: 0, limit: 50, format: "csv", inPrompt: false, searchByMeaningRows: 1000, ...over,
  };
}

/** `count` rows from row `from` (counted from 0), each telling where it is. */
function rowsFrom(from: number, count: number): KnowledgeCell[][] {
  return Array.from({ length: count }, (_, i) => [`Item ${from + i + 1}`, String(100 + from + i), from + i, (from + i) % 2 === 0, null]);
}

const withBase = (more: Record<string, unknown>) => ({
  ...signedIn,
  "GET /api/kit/console/knowledge": { knowledgeBases: [faq], limits },
  "GET /api/kit/console/knowledge/k1": { ...faq, documents: docs },
  ...more,
});

function Where() {
  return <span data-testid="where">{useLocation().pathname}</span>;
}

function open(path = "/console/knowledge/k1/documents/d1/table") {
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/console/*" element={<><ConsoleArea /><Where /></>} />
      </Routes>
    </MemoryRouter>,
  );
}

const cells = (t: HTMLElement) => within(t).getAllByRole("row").slice(1).map((r) => within(r).getAllByRole("cell").map((c) => c.textContent));
const typeOf = (name: string) => (screen.getByLabelText(name) as HTMLSelectElement).value;
const pick = (name: string, type: string) => fireEvent.change(screen.getByLabelText(name), { target: { value: type } });

describe("a knowledge base's tables", () => {
  it("links only a ready table document to its table, which leads back to the base", async () => {
    fakeKit(withBase({ [page(0)]: table() }));
    open("/console/knowledge/k1");
    const rows = within(await screen.findByRole("table", { name: "Documents" })).getAllByRole("row").slice(1);
    expect(rows.map((r) => within(r).queryByRole("link", { name: "View table" })?.getAttribute("href") ?? null)).toEqual([
      "/console/knowledge/k1/documents/d1/table", null, null, null, null,
    ]);
    fireEvent.click(within(rows[0]).getByRole("link", { name: "View table" }));
    expect(await screen.findByRole("heading", { level: 1, name: "Price list" })).toBeTruthy();
    expect(screen.getByTestId("where").textContent).toBe("/console/knowledge/k1/documents/d1/table");
    fireEvent.click(screen.getByRole("link", { name: "Back to the knowledge base" }));
    await waitFor(() => expect(screen.getByTestId("where").textContent).toBe("/console/knowledge/k1"));
  });
});

describe("a knowledge table", () => {
  it("says what kind of file it is, its size, how agents use it, each column's type and a page of rows", async () => {
    const sent = fakeKit(withBase({ [page(0)]: table() }));
    open();
    const rows = await screen.findByRole("table", { name: "Rows" });
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Price list");
    expect(screen.getByText(`CSV file with ${n(1250)} rows and 5 columns.`)).toBeTruthy();
    expect(screen.getByText(/^Agents look rows up/).textContent).toBe(
      `Agents look rows up in this table during calls. Finding rows by meaning covers its first ${n(1000)} rows; exact look-ups cover all ${n(1250)}.`,
    );
    expect(screen.queryByText(/read this whole table/)).toBeNull();

    const form = screen.getByRole("form", { name: "Columns" });
    expect(within(form).getByText("Numbers and dates can be compared, and numbers added up. A cell that does not fit its column's type is left out of those.")).toBeTruthy();
    expect(columns.map((c) => typeOf(c.name))).toEqual(["text", "text", "number", "bool", "date"]);
    expect([...(screen.getByLabelText("Unit price") as HTMLSelectElement).options].map((o) => o.textContent)).toEqual(["Text", "Number", "Yes or no", "Date"]);
    expect((within(form).getByRole("button", { name: "Save" }) as HTMLButtonElement).disabled).toBe(true);

    expect(within(rows).getAllByRole("columnheader").map((h) => h.textContent)).toEqual(["Item", "Unit price", "Qty", "In stock", "Added on"]);
    expect(cells(rows)).toEqual([
      ["Crown", "N/A", "2", "true", "2026-01-05"],
      ["Filling", "1200", "0", "false", ""],
      ["Whitening", "3500.5", "1.5", "", ""],
    ]);
    expect(screen.getByText(`Rows 1–3 of ${n(1250)}`)).toBeTruthy();
    expect(sent.filter((s) => s.path.startsWith(TABLE)).map((s) => s.path)).toEqual([`${TABLE}?offset=0&limit=50`]);
  });

  it("says a small table is read whole on every call (an Excel file here)", async () => {
    fakeKit(withBase({ [page(0)]: table({ format: "xlsx", rowCount: 3, inPrompt: true, searchByMeaningRows: null }) }));
    open();
    expect(await screen.findByText("Small enough that agents read this whole table on every call, and look rows up in it.")).toBeTruthy();
    expect(screen.getByText("Excel file (its first sheet) with 3 rows and 5 columns.")).toBeTruthy();
    expect(screen.queryByText(/^Agents look rows up/)).toBeNull();
    expect(screen.queryByText(/by meaning/)).toBeNull();
  });

  it("says rows are looked up, with no limit to finding them by meaning when there is none (a JSON file here)", async () => {
    fakeKit(withBase({ [page(0)]: table({ format: "json", rowCount: 400, searchByMeaningRows: null }) }));
    open();
    expect((await screen.findByText(/^Agents look rows up/)).textContent).toBe("Agents look rows up in this table during calls.");
    expect(screen.getByText("JSON file with 400 rows and 5 columns.")).toBeTruthy();
    expect(screen.queryByText(/read this whole table/)).toBeNull();
  });

  it("names a kind of file it does not know as a file, rather than failing", async () => {
    fakeKit(withBase({ [page(0)]: table({ format: "" as KnowledgeTable["format"], rowCount: 3 }) }));
    open();
    expect(await screen.findByText("File with 3 rows and 5 columns.")).toBeTruthy();
    expect(screen.getByRole("table", { name: "Rows" })).toBeTruthy();
  });

  it("pages through the rows 50 at a time by offset", async () => {
    const sent = fakeKit(withBase({
      [page(0)]: table({ rowCount: 120, rows: rowsFrom(0, 50) }),
      [page(50)]: table({ rowCount: 120, offset: 50, rows: rowsFrom(50, 50) }),
      [page(100)]: table({ rowCount: 120, offset: 100, rows: rowsFrom(100, 20) }),
    }));
    open();
    const pager = await screen.findByRole("navigation", { name: "Rows" });
    const previous = within(pager).getByRole("button", { name: "Previous" }) as HTMLButtonElement;
    const next = within(pager).getByRole("button", { name: "Next" }) as HTMLButtonElement;
    const first = () => cells(screen.getByRole("table", { name: "Rows" }))[0];
    expect(within(pager).getByText("Rows 1–50 of 120")).toBeTruthy();
    expect(previous.disabled).toBe(true);
    expect(first()).toEqual(["Item 1", "100", "0", "true", ""]);

    fireEvent.click(next);
    expect(await within(pager).findByText("Rows 51–100 of 120")).toBeTruthy();
    expect(first()).toEqual(["Item 51", "150", "50", "true", ""]);
    await waitFor(() => expect(next.disabled).toBe(false));
    fireEvent.click(next);
    expect(await within(pager).findByText("Rows 101–120 of 120")).toBeTruthy();
    expect(cells(screen.getByRole("table", { name: "Rows" }))).toHaveLength(20);
    await waitFor(() => expect(previous.disabled).toBe(false));
    expect(next.disabled).toBe(true);

    fireEvent.click(previous);
    expect(await within(pager).findByText("Rows 51–100 of 120")).toBeTruthy();
    expect(sent.filter((s) => s.path.startsWith(TABLE)).map((s) => s.path.slice(TABLE.length))).toEqual([
      "?offset=0&limit=50", "?offset=50&limit=50", "?offset=100&limit=50", "?offset=50&limit=50",
    ]);
  });

  it("pages by 50 when the backend names no page size", async () => {
    const sent = fakeKit(withBase({
      [page(0)]: table({ rowCount: 120, limit: 0, rows: rowsFrom(0, 50) }),
      [page(50)]: table({ rowCount: 120, offset: 50, limit: 0, rows: rowsFrom(50, 50) }),
    }));
    open();
    const pager = await screen.findByRole("navigation", { name: "Rows" });
    fireEvent.click(within(pager).getByRole("button", { name: "Next" }));
    expect(await within(pager).findByText("Rows 51–100 of 120")).toBeTruthy();
    expect(sent.filter((s) => s.path.startsWith(TABLE)).map((s) => s.path.slice(TABLE.length))).toEqual(["?offset=0&limit=50", "?offset=50&limit=50"]);
  });

  it("saves only the columns changed, in a PATCH, then shows the columns as the backend answers them", async () => {
    const answered: KnowledgeTableColumn[] = columns.map((c) =>
      c.key === "unit_price" ? { ...c, type: "number" } : c.key === "qty" ? { ...c, type: "text" } : c);
    const sent = fakeKit(withBase({ [page(0)]: table(), [`PATCH ${TABLE}`]: { columns: answered } }));
    open();
    const form = await screen.findByRole("form", { name: "Columns" });
    const save = within(form).getByRole("button", { name: "Save" }) as HTMLButtonElement;
    expect(save.disabled).toBe(true);
    pick("In stock", "text");
    expect(save.disabled).toBe(false);
    pick("In stock", "bool");
    expect(save.disabled).toBe(true);
    pick("Unit price", "number");
    pick("Added on", "date");
    expect(save.disabled).toBe(false);
    fireEvent.click(save);
    expect((await within(form).findByRole("status")).textContent).toBe("Saved. Calls that start from now read the columns this way.");
    expect(sent.filter((s) => s.method === "PATCH").map((s) => [s.path, s.body])).toEqual([[TABLE, { types: { unit_price: "number" } }]]);
    expect(columns.map((c) => typeOf(c.name))).toEqual(["text", "number", "text", "bool", "date"]);
    expect(save.disabled).toBe(true);
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("shows the backend's word on a column by the form, and any other refusal as an alert", async () => {
    let answer = () => Response.json({ message: "x", errors: { "types.unit_price": ["Pick text, a number, yes or no, or a date."], types: ["Name a column."] } }, { status: 422 });
    const sent = fakeKit(withBase({ [page(0)]: table(), [`PATCH ${TABLE}`]: () => answer() }));
    open();
    const form = await screen.findByRole("form", { name: "Columns" });
    pick("Unit price", "number");
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    const said = await within(form).findByText("Pick text, a number, yes or no, or a date.");
    expect(said.closest(".field")?.querySelector("select")?.id).toBe(screen.getByLabelText("Unit price").id);
    expect(screen.getByLabelText("Unit price").getAttribute("aria-invalid")).toBe("true");
    expect(within(form).getByText("Name a column.")).toBeTruthy();
    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.queryByRole("status")).toBeNull();

    const unreachable = "The voice platform cannot be reached right now. Try again shortly.";
    answer = () => Response.json({ message: unreachable }, { status: 503 });
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect((await screen.findByRole("alert")).textContent).toBe(unreachable);
    expect(within(form).queryByText("Pick text, a number, yes or no, or a date.")).toBeNull();
    expect(typeOf("Unit price")).toBe("number");
    expect(sent.filter((s) => s.method === "PATCH").map((s) => s.body)).toEqual([{ types: { unit_price: "number" } }, { types: { unit_price: "number" } }]);
  });

  it.each([
    [404, "This document is not a table."],
    [409, "This document is still being read. Try again in a moment."],
    [503, "The voice platform cannot be reached right now. Try again shortly."],
  ])("says so when the table cannot be read (%i)", async (status, words) => {
    fakeKit(withBase({ [page(0)]: () => Response.json({ message: "The voice platform cannot be reached right now. Try again shortly." }, { status }) }));
    open();
    expect((await screen.findByRole("alert")).textContent).toBe(words);
    expect(screen.queryByRole("form", { name: "Columns" })).toBeNull();
    expect(screen.queryByRole("table")).toBeNull();
  });

  it("says an empty table has no rows, and still offers its columns", async () => {
    fakeKit(withBase({ [page(0)]: table({ rows: [], rowCount: 0, inPrompt: true, searchByMeaningRows: null }) }));
    open();
    expect(await screen.findByText("This table has no rows.")).toBeTruthy();
    expect(screen.getByText("CSV file with 0 rows and 5 columns.")).toBeTruthy();
    expect(screen.queryByRole("table", { name: "Rows" })).toBeNull();
    expect(screen.queryByRole("navigation", { name: "Rows" })).toBeNull();
    expect(screen.getByRole("form", { name: "Columns" })).toBeTruthy();
  });
});
