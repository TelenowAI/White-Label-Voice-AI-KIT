/**
 * Admin → Catalog → models added beyond the catalog's (admin/OpenModels.tsx, in admin/Catalog.tsx) against a fake
 * backend: added models join the open-model provider's list after the catalog's, marked Added, with the offered switch,
 * own name and Remove; adding (from the provider's own list, never one In the catalog, none past the most), renaming,
 * switching off and removing them are exactly what Save sends as that provider's `extraModels` (every added id →
 * {offered, name}, `{}` for none, never among its catalog `models`, never on another provider); the list searched (Enter
 * searches, never saves) and paged by the query the backend reads; a price as the operator's own cost per million tokens
 * read and written, "No price yet" when there is none, and its failure at its row; the backend's refusals at the added
 * model's row, at its name, and the whole set's at the area, by keys that hold `/`, `.`, `:` and `~`; a list that cannot
 * be read (502, 409) a message in the area with the rest of the page kept; the new-agent default model follows the
 * added models' switches and names; and nothing of this, nor a request for the list, when no provider offers its list.
 */
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { CatalogCuration, CuratedProvider, CurationInput, OpenModelPage } from "../src/api";
import { Catalog } from "../src/admin/Catalog";
import { fakeKit, type Sent } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const LIST = "/api/kit/admin/catalog/open-models";
const PRICE = "/api/kit/admin/catalog/open-models/price";
const LLAMA = "meta-llama/llama-3.1-8b:free";
const CLAUDE = "~anthropic/claude.v2";
const MISTRAL = "mistralai/mistral-small";
const GPT = "openai/gpt-4o";
const none = { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null };

function provider(over: Partial<CuratedProvider> & { id: string; catalogName: string }): CuratedProvider {
  return {
    name: over.catalogName, ownName: null, namesPlatform: false, offered: true, enabled: true, status: "ga", recommended: false,
    models: [], openModels: false, voiceCount: 0, settings: [], ...over,
  };
}

const openai = provider({
  id: "openai", catalogName: "OpenAI",
  models: [{ id: "gpt-4o-mini", catalogName: "GPT-4o mini", name: "GPT-4o mini", ownName: null, namesPlatform: false, offered: true, added: false }],
});
const openrouter = provider({
  id: "openrouter", catalogName: "OpenRouter", openModels: true,
  models: [
    { id: GPT, catalogName: "GPT-4o", name: "GPT-4o", ownName: null, namesPlatform: false, offered: true, added: false },
    { id: LLAMA, catalogName: "Meta: Llama 3.1 8B (free)", name: "Fast Llama", ownName: "Fast Llama", namesPlatform: false, offered: true, added: true },
    { id: CLAUDE, catalogName: "Anthropic: Claude v2", name: "Anthropic: Claude v2", ownName: null, namesPlatform: false, offered: false, added: true },
  ],
});

function curation(llm: CuratedProvider[] = [openai, openrouter], over: Partial<CatalogCuration> = {}): CatalogCuration {
  return {
    fetchedAt: "2026-11-09T09:00:00Z", error: null, platformName: "Telenow", kinds: { llm, stt: [], tts: [] },
    defaults: none, recommended: none, voiceNames: {}, transferAllowed: false, transferStillOn: 0, browserCallsAllowed: false, browserCallsUnpriced: [],
    webChatAllowed: false, webChatUnpriced: [], campaignLinksAllowed: false, consentRequired: false, consentStillToTell: 0, cloningAllowed: false,
    clonesPerAccount: 10, clonesMost: 50, realtime: { enabled: false, providers: [] }, ...over,
  };
}

function listPage(over: Partial<OpenModelPage> = {}): OpenModelPage {
  return {
    provider: "openrouter",
    models: [
      { id: GPT, name: "OpenAI: GPT-4o", added: false, inCatalog: true },
      { id: LLAMA, name: "Meta: Llama 3.1 8B (free)", added: true, inCatalog: false },
      { id: MISTRAL, name: "Mistral: Small", added: false, inCatalog: false },
    ],
    page: 1, perPage: 3, total: 7, max: 3, ...over,
  };
}

const page2 = listPage({ page: 2, models: [{ id: "qwen/qwen-2.5:free", name: "Qwen 2.5 (free)", added: false, inCatalog: false }] });

function catalog() {
  render(
    <MemoryRouter>
      <Catalog />
    </MemoryRouter>,
  );
}

type Choice = NonNullable<CurationInput["kinds"]["llm"]>[string];
const saved = (sent: Sent[]) => (sent.find((s) => s.method === "PUT")?.body as CurationInput | undefined)?.kinds.llm as Record<string, Choice>;

/** The model choices in a provider's row, in order: the label of each offered switch, and whether it is marked Added. */
function modelsOf(providerName: string) {
  const row = screen.getByLabelText(`Offer ${providerName}`).closest("tr") as HTMLElement;
  return [...row.querySelectorAll(".model-choice")].map((el) => {
    const [offer] = within(el as HTMLElement).getAllByRole("checkbox");
    return `${offer.getAttribute("aria-label")}${within(el as HTMLElement).queryByText("Added") ? " [Added]" : ""}`;
  });
}

const addedRow = (id: string) => document.querySelector(`[data-added-model="${id}"]`) as HTMLElement;
const listRow = (id: string) => document.querySelector(`[data-model="${id}"]`) as HTMLElement;
const area = () => screen.getByRole("region", { name: "Add models to OpenRouter" });

async function opened() {
  const form = await screen.findByRole("form", { name: "Catalog" });
  await screen.findByRole("table", { name: "OpenRouter's models" });
  return form;
}

describe("Admin → Catalog → models added from OpenRouter's list", () => {
  it("adds, renames, switches off and removes added models, and Save sends exactly them as that provider's extraModels", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/catalog": curation(),
      [`GET ${LIST}`]: listPage(),
      "PUT /api/kit/admin/catalog": curation(),
    });
    catalog();
    const form = await opened();
    expect(modelsOf("OpenRouter")).toEqual(["Offer GPT-4o", "Offer Meta: Llama 3.1 8B (free) [Added]", "Offer Anthropic: Claude v2 [Added]"]);
    expect(modelsOf("OpenAI")).toEqual(["Offer GPT-4o mini"]);
    expect((within(addedRow(LLAMA)).getByLabelText("Name customers see for Meta: Llama 3.1 8B (free)") as HTMLInputElement).value).toBe("Fast Llama");
    expect((within(addedRow(CLAUDE)).getByRole("checkbox") as HTMLInputElement).checked).toBe(false);
    expect(within(addedRow(CLAUDE)).getByText(CLAUDE)).toBeTruthy();
    expect(screen.getByTestId("open-models-count").textContent).toBe("2 of 3 added");
    expect(within(area()).queryByText(/as many as can be added/)).toBeNull();
    expect(area().closest("fieldset")?.querySelector("legend")?.textContent, "in the language models' part of the page").toBe("Language models");

    fireEvent.click(within(listRow(MISTRAL)).getByRole("button", { name: "Add Mistral: Small" }));
    expect(modelsOf("OpenRouter")).toEqual(["Offer GPT-4o", "Offer Meta: Llama 3.1 8B (free) [Added]", "Offer Anthropic: Claude v2 [Added]", "Offer Mistral: Small [Added]"]);
    expect((within(addedRow(MISTRAL)).getByRole("checkbox") as HTMLInputElement).checked, "added offered").toBe(true);
    expect(within(listRow(MISTRAL)).getByText("Added")).toBeTruthy();
    expect(within(listRow(MISTRAL)).queryByRole("button", { name: /^Add/ })).toBeNull();
    expect(screen.getByTestId("open-models-count").textContent).toBe("3 of 3 added");
    expect(within(area()).getByText("That is as many as can be added: remove one to add another.")).toBeTruthy();

    fireEvent.change(within(addedRow(LLAMA)).getByLabelText("Name customers see for Meta: Llama 3.1 8B (free)"), { target: { value: "  Quick Llama  " } });
    fireEvent.click(within(addedRow(MISTRAL)).getByLabelText("Offer Mistral: Small"));
    fireEvent.click(within(addedRow(CLAUDE)).getByRole("button", { name: "Remove Anthropic: Claude v2" }));
    expect(addedRow(CLAUDE)).toBeNull();
    expect(screen.getByTestId("open-models-count").textContent).toBe("2 of 3 added");

    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT")).toHaveLength(1));
    const llm = saved(sent);
    expect(llm.openrouter.extraModels).toEqual({
      [LLAMA]: { offered: true, name: "Quick Llama" },
      [MISTRAL]: { offered: false, name: null },
    });
    expect(llm.openrouter.models, "the catalog's own models only").toEqual({ [GPT]: { offered: true, name: null } });
    expect(Object.keys(llm.openai), "another provider never carries extraModels").not.toContain("extraModels");
    expect(llm.openai.models).toEqual({ "gpt-4o-mini": { offered: true, name: null } });
  });

  it("sends {} when every added model is removed, and the list offers them to add again", async () => {
    const sent = fakeKit({ "GET /api/kit/admin/catalog": curation(), [`GET ${LIST}`]: listPage(), "PUT /api/kit/admin/catalog": curation() });
    catalog();
    const form = await opened();
    expect(within(listRow(LLAMA)).getByText("Added")).toBeTruthy();
    expect(within(listRow(LLAMA)).queryByRole("button", { name: /^Add/ })).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Remove Meta: Llama 3.1 8B (free)" }));
    fireEvent.click(screen.getByRole("button", { name: "Remove Anthropic: Claude v2" }));
    expect(modelsOf("OpenRouter")).toEqual(["Offer GPT-4o"]);
    expect(screen.getByTestId("open-models-count").textContent).toBe("0 of 3 added");
    expect(within(listRow(LLAMA)).getByRole("button", { name: "Add Meta: Llama 3.1 8B (free)" })).toBeTruthy();

    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT")).toHaveLength(1));
    expect(Object.keys(saved(sent).openrouter)).toContain("extraModels");
    expect(saved(sent).openrouter.extraModels).toEqual({});
  });

  it("never adds a model the catalog has, nor more than the most", async () => {
    fakeKit({
      "GET /api/kit/admin/catalog": curation(),
      [`GET ${LIST}`]: listPage(),
      [`GET ${LIST}?page=2`]: page2,
    });
    catalog();
    await opened();
    const gpt = listRow(GPT);
    expect(within(gpt).getByText("In the catalog: choose it in the list above")).toBeTruthy();
    expect(within(gpt).queryByRole("button", { name: /^Add/ })).toBeNull();
    expect(within(gpt).queryByText("Added")).toBeNull();
    expect((within(listRow(MISTRAL)).getByRole("button", { name: "Add Mistral: Small" }) as HTMLButtonElement).disabled).toBe(false);

    fireEvent.click(within(listRow(MISTRAL)).getByRole("button", { name: "Add Mistral: Small" }));
    fireEvent.click(screen.getByRole("button", { name: "Next" }));
    const qwen = await screen.findByRole("button", { name: "Add Qwen 2.5 (free)" });
    expect((qwen as HTMLButtonElement).disabled, "3 of 3 added: none more").toBe(true);
    fireEvent.click(screen.getByRole("button", { name: "Remove Mistral: Small" }));
    expect((screen.getByRole("button", { name: "Add Qwen 2.5 (free)" }) as HTMLButtonElement).disabled).toBe(false);
  });

  it("pages and searches OpenRouter's list by the query the backend reads; Enter searches and never saves", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/catalog": curation(),
      [`GET ${LIST}`]: listPage(),
      [`GET ${LIST}?page=2`]: page2,
      [`GET ${LIST}?search=llama+3`]: listPage({ models: [listPage().models[1]], total: 1 }),
      [`GET ${LIST}?search=mistral`]: listPage({ models: [listPage().models[2]], total: 4, perPage: 1 }),
      [`GET ${LIST}?search=mistral&page=2`]: listPage({ models: [], page: 2, total: 4, perPage: 1 }),
      "PUT /api/kit/admin/catalog": curation(),
    });
    catalog();
    await opened();
    const pager = () => within(area()).getByRole("navigation", { name: "Pages of OpenRouter's models" });
    expect(within(pager()).getByText("Page 1 of 3")).toBeTruthy();
    expect((within(pager()).getByRole("button", { name: "Previous" }) as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(within(pager()).getByRole("button", { name: "Next" }));
    expect(await screen.findByText("Qwen 2.5 (free)")).toBeTruthy();
    expect(within(pager()).getByText("Page 2 of 3")).toBeTruthy();
    expect(screen.queryByText("Mistral: Small")).toBeNull();

    const find = within(area()).getByLabelText("Search OpenRouter's models");
    fireEvent.change(find, { target: { value: "llama 3" } });
    // A browser submits the form around a field on Enter; cancelling the key is what stops that (jsdom never submits).
    expect(fireEvent.keyDown(find, { key: "Enter" }), "Enter's default (submitting the catalog) is cancelled").toBe(false);
    await waitFor(() => expect(within(area()).getAllByRole("row")).toHaveLength(2));
    expect(within(area()).getByText("Meta: Llama 3.1 8B (free)")).toBeTruthy();
    expect(within(area()).queryByRole("navigation"), "one page: no pager").toBeNull();

    fireEvent.change(find, { target: { value: "  mistral " } });
    fireEvent.click(within(area()).getByRole("button", { name: "Search" }));
    expect(await within(area()).findByText("Mistral: Small")).toBeTruthy();
    expect(within(pager()).getByText("Page 1 of 4")).toBeTruthy();
    fireEvent.click(within(pager()).getByRole("button", { name: "Next" }));
    expect(await within(area()).findByText("No model matches.")).toBeTruthy();

    expect(sent.filter((s) => s.path.startsWith(LIST)).map((s) => s.path)).toEqual([
      LIST, `${LIST}?page=2`, `${LIST}?search=llama+3`, `${LIST}?search=mistral`, `${LIST}?search=mistral&page=2`,
    ]);
    expect(sent.filter((s) => s.method === "PUT"), "Enter in the search never saves the page").toHaveLength(0);
  });

  it("shows a price as the operator's own cost per million tokens, says when there is none, and a failure at its row", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/catalog": curation(),
      [`GET ${LIST}`]: listPage(),
      [`GET ${PRICE}?model=mistralai%2Fmistral-small`]: { model: MISTRAL, found: true, inputPerM: 0.075, outputPerM: 15 },
      [`GET ${PRICE}?model=meta-llama%2Fllama-3.1-8b%3Afree`]: { model: LLAMA, found: false, inputPerM: null, outputPerM: null },
      [`GET ${PRICE}?model=openai%2Fgpt-4o`]: () => Response.json({ message: "The price could not be read just now. Try again shortly." }, { status: 502 }),
    });
    catalog();
    await opened();
    expect(within(area()).getByText(/what the voice platform charges you for a model, in US dollars per million tokens, as it stands now: an estimate\. Customers never see it/)).toBeTruthy();
    expect(within(area()).getByRole("columnheader", { name: "Your cost" })).toBeTruthy();

    fireEvent.click(within(listRow(MISTRAL)).getByRole("button", { name: "See price of Mistral: Small" }));
    expect(await within(listRow(MISTRAL)).findByText("$0.075 per million tokens read, $15.00 per million written")).toBeTruthy();
    expect(within(listRow(MISTRAL)).queryByRole("button", { name: /See price/ })).toBeNull();

    fireEvent.click(within(listRow(LLAMA)).getByRole("button", { name: "See price of Meta: Llama 3.1 8B (free)" }));
    expect(await within(listRow(LLAMA)).findByText("No price yet")).toBeTruthy();

    fireEvent.click(within(listRow(GPT)).getByRole("button", { name: "See price of OpenAI: GPT-4o" }));
    expect(await within(listRow(GPT)).findByText("The price could not be read just now. Try again shortly.")).toBeTruthy();
    expect((within(listRow(GPT)).getByRole("button", { name: "See price of OpenAI: GPT-4o" }) as HTMLButtonElement).disabled, "can be asked again").toBe(false);
    expect(within(listRow(MISTRAL)).getByText("$0.075 per million tokens read, $15.00 per million written"), "other rows keep theirs").toBeTruthy();

    expect(sent.filter((s) => s.path.startsWith(PRICE)).map((s) => s.path)).toEqual([
      `${PRICE}?model=mistralai%2Fmistral-small`, `${PRICE}?model=meta-llama%2Fllama-3.1-8b%3Afree`, `${PRICE}?model=openai%2Fgpt-4o`,
    ]);
  });

  it("shows each refusal at the added model's row, at its name, and the whole set's at the area, by keys holding / . : ~", async () => {
    fakeKit({
      "GET /api/kit/admin/catalog": curation(),
      [`GET ${LIST}`]: listPage(),
      "PUT /api/kit/admin/catalog": () => Response.json({
        message: "The given data was invalid.",
        errors: {
          "kinds.llm.openrouter.extraModels": ["At most 3 models can be added."],
          [`kinds.llm.openrouter.extraModels.${LLAMA}.name`]: ["The name may be at most 120 characters."],
          [`kinds.llm.openrouter.extraModels.${CLAUDE}`]: ["This is not a model in OpenRouter's list."],
        },
      }, { status: 422 }),
    });
    catalog();
    const form = await opened();
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    expect(await within(area()).findByText("At most 3 models can be added.")).toBeTruthy();

    const llama = addedRow(LLAMA);
    expect(within(llama).getByText("The name may be at most 120 characters.")).toBeTruthy();
    expect(within(llama).getByLabelText("Name customers see for Meta: Llama 3.1 8B (free)").getAttribute("aria-invalid")).toBe("true");
    expect(within(llama).queryByText("This is not a model in OpenRouter's list.")).toBeNull();

    const claude = addedRow(CLAUDE);
    expect(within(claude).getByText("This is not a model in OpenRouter's list.")).toBeTruthy();
    expect(within(claude).getByLabelText("Name customers see for Anthropic: Claude v2").getAttribute("aria-invalid"), "the row's refusal is not the name's").toBeNull();
    expect(within(claude).queryByText("The name may be at most 120 characters.")).toBeNull();

    for (const row of [llama, claude]) expect(within(row).queryByText("At most 3 models can be added.")).toBeNull();
    expect(screen.getAllByText("At most 3 models can be added.")).toHaveLength(1);
    expect(screen.queryByRole("alert"), "a refusal by field, not the page's failure").toBeNull();
  });

  it("says when OpenRouter's list cannot be read, in the area, and keeps the rest of the page and its Save", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/catalog": curation(),
      [`GET ${LIST}`]: () => Response.json({ message: "OpenRouter's model list could not be read just now. Try again shortly." }, { status: 502 }),
      "PUT /api/kit/admin/catalog": curation(),
    });
    catalog();
    const form = await screen.findByRole("form", { name: "Catalog" });
    expect((await within(area()).findByTestId("open-models-failure")).textContent).toBe("OpenRouter's model list could not be read just now. Try again shortly.");
    expect(within(area()).queryByRole("table")).toBeNull();
    expect(screen.getByTestId("open-models-count").textContent).toBe("2 added");
    expect(screen.queryByRole("alert")).toBeNull();
    expect(modelsOf("OpenRouter")).toEqual(["Offer GPT-4o", "Offer Meta: Llama 3.1 8B (free) [Added]", "Offer Anthropic: Claude v2 [Added]"]);
    expect(within(form).getByLabelText("Customers may set up transfers to a person")).toBeTruthy();

    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT")).toHaveLength(1));
    expect(saved(sent).openrouter.extraModels).toEqual({ [LLAMA]: { offered: true, name: "Fast Llama" }, [CLAUDE]: { offered: false, name: null } });
    cleanup();

    fakeKit({
      "GET /api/kit/admin/catalog": curation(),
      [`GET ${LIST}`]: () => Response.json({ message: "Set the voice platform's key first." }, { status: 409 }),
    });
    catalog();
    expect((await within(await screen.findByRole("region", { name: "Add models to OpenRouter" })).findByTestId("open-models-failure")).textContent)
      .toBe("Set the voice platform's key first.");
    expect(screen.getByRole("form", { name: "Catalog" })).toBeTruthy();
  });

  it("offers an added model as the new-agent default as the form has it: by its own name, not once switched off or removed", async () => {
    fakeKit({ "GET /api/kit/admin/catalog": curation(undefined, { defaults: { ...none, llmProvider: "openrouter" } }), [`GET ${LIST}`]: listPage() });
    catalog();
    await opened();
    const options = () => [...(screen.getByLabelText("Model") as HTMLSelectElement).options].map((o) => o.textContent);
    expect(options()).toEqual(["None", "GPT-4o", "Fast Llama"]);
    fireEvent.click(within(addedRow(CLAUDE)).getByLabelText("Offer Anthropic: Claude v2"));
    fireEvent.change(within(addedRow(LLAMA)).getByLabelText("Name customers see for Meta: Llama 3.1 8B (free)"), { target: { value: "Llama" } });
    expect(options()).toEqual(["None", "GPT-4o", "Llama", "Anthropic: Claude v2"]);
    fireEvent.click(within(addedRow(LLAMA)).getByLabelText("Offer Meta: Llama 3.1 8B (free)"));
    fireEvent.click(screen.getByRole("button", { name: "Remove Anthropic: Claude v2" }));
    expect(options()).toEqual(["None", "GPT-4o"]);
    fireEvent.click(within(listRow(MISTRAL)).getByRole("button", { name: "Add Mistral: Small" }));
    expect(options(), "one added just now, not saved yet, may be the default at once").toEqual(["None", "GPT-4o", "Mistral: Small"]);
    fireEvent.change(screen.getByLabelText("Model"), { target: { value: MISTRAL } });
    expect((screen.getByLabelText("Model") as HTMLSelectElement).value).toBe(MISTRAL);
    fireEvent.click(screen.getByLabelText("Offer GPT-4o"));
    expect(options(), "a catalog model switched off is no default either").toEqual(["None", "Mistral: Small"]);
  });

  it("shows nothing of this, and never asks for the list, when no provider offers its whole list", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/catalog": curation([openai, { ...openrouter, openModels: false, models: [openrouter.models[0]] }]),
      "PUT /api/kit/admin/catalog": curation(),
    });
    catalog();
    const form = await screen.findByRole("form", { name: "Catalog" });
    expect(screen.queryByRole("region", { name: /^Add models/ })).toBeNull();
    expect(screen.queryByText("Added")).toBeNull();
    fireEvent.click(within(form).getByRole("button", { name: "Save" }));
    await waitFor(() => expect(sent.filter((s) => s.method === "PUT")).toHaveLength(1));
    for (const choice of Object.values(saved(sent))) expect(Object.keys(choice)).not.toContain("extraModels");
    expect(sent.some((s) => s.path.startsWith(LIST))).toBe(false);
  });
});
