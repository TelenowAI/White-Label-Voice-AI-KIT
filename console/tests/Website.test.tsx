/**
 * Admin → Website (admin/WebsitePage.tsx) against a fake backend: staff see the content with a link to the site; they
 * reorder the home page's sections and turn one off, add, edit, move and remove features (no more than the backend's
 * maximum), choose the plans the pricing page shows with their own words, edit a legal page, and save the whole
 * content at once; a refusal shows the backend's words by the field and in the list at the top, and nothing is lost.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { WebsiteContent, WebsiteSettings } from "../src/api";
import { WebsitePage } from "../src/admin/WebsitePage";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const content: WebsiteContent = {
  tagline: "Calls, answered",
  metaDescription: null,
  heroTitle: "Voice agents for every call",
  heroText: "{brand} answers.",
  heroImage: null,
  ctaTitle: "Ready",
  ctaText: "Start now.",
  sections: [{ id: "features", on: true }, { id: "pricing", on: true }, { id: "cta", on: true }],
  features: [{ title: "Answers", text: "Day and night." }, { title: "Calls out", text: "" }],
  pricingIntro: "Pay as you go.",
  plans: [],
  contactText: "Write to {email}.",
  legal: { terms: "Our terms.", privacy: "Our policy." },
};

function settings(over: Partial<WebsiteSettings> = {}): WebsiteSettings {
  return {
    content,
    siteUrl: "https://voice.acme.example",
    childTheme: null,
    maxFeatures: 3,
    maxPlans: 2,
    textMaxLength: 300,
    longTextMaxLength: 20000,
    sectionChoices: [{ value: "features", label: "Features" }, { value: "pricing", label: "Pricing" }, { value: "cta", label: "Call to action" }],
    legalPages: [{ value: "terms", label: "Terms of service" }, { value: "privacy", label: "Privacy policy" }],
    planChoices: [{ value: "base", label: "Base" }, { value: "starter", label: "Starter" }],
    ...over,
  };
}

function show() {
  render(
    <MemoryRouter>
      <WebsitePage />
    </MemoryRouter>,
  );
}

describe("Admin → Website", () => {
  it("edits the whole content and saves it at once", async () => {
    const sent = fakeKit({
      "GET /api/kit/admin/website": settings({ childTheme: "acme" }),
      "PUT /api/kit/admin/website": (body: unknown) => Response.json(settings({ content: body as WebsiteContent })),
    });
    show();
    const form = await screen.findByRole("form", { name: "Website" });
    expect(screen.getByRole("link", { name: "View the site" }).getAttribute("href")).toBe("https://voice.acme.example");
    expect(screen.getByText("Your child theme “acme” changes some pages.")).toBeTruthy();

    fireEvent.change(within(form).getByLabelText("Headline", { selector: "#website-heroTitle" }), { target: { value: "Never miss a call" } });
    fireEvent.change(within(form).getByLabelText("Image address (https://…, optional)"), { target: { value: "https://cdn.acme.example/a.png" } });

    // Sections: the call to action first, pricing off.
    const sections = within(form).getByRole("group", { name: "Home page sections, in order" });
    fireEvent.click(within(sections.querySelector('[data-section="cta"]') as HTMLElement).getByRole("button", { name: "Move up" }));
    fireEvent.click(within(sections.querySelector('[data-section="cta"]') as HTMLElement).getByRole("button", { name: "Move up" }));
    fireEvent.click(within(sections).getByLabelText("Pricing"));
    expect(within(sections).queryAllByRole("button", { name: "Move up" })).toHaveLength(2);

    // Features: a third one (the maximum), the second moved up (now first), then the one it passed removed.
    const features = within(form).getByRole("group", { name: "Features" });
    fireEvent.click(within(features).getByRole("button", { name: "Add a feature" }));
    expect(within(features).queryByRole("button", { name: "Add a feature" })).toBeNull();
    expect(within(features).getByText("Up to 3 features.")).toBeTruthy();
    fireEvent.change(within(features).getAllByLabelText("Title")[2], { target: { value: "Knows you" } });
    fireEvent.click(within(features.querySelector('[data-feature="1"]') as HTMLElement).getByRole("button", { name: "Move up" }));
    fireEvent.click(within(features.querySelector('[data-feature="1"]') as HTMLElement).getByRole("button", { name: "Remove" }));

    // Plans: Starter with its own words, then Base; the picker goes once both are shown.
    const pricing = within(form).getByRole("group", { name: "Pricing" });
    fireEvent.change(within(pricing).getByLabelText("Show another plan"), { target: { value: "starter" } });
    fireEvent.click(within(pricing).getByRole("button", { name: "Show it" }));
    fireEvent.change(within(pricing).getByLabelText("Headline"), { target: { value: "For starters" } });
    const offered = () => [...(within(pricing).getByLabelText("Show another plan") as HTMLSelectElement).options].map((o) => o.value);
    expect(offered(), "a plan already shown is not offered again").toEqual(["", "base"]);
    fireEvent.change(within(pricing).getByLabelText("Show another plan"), { target: { value: "base" } });
    fireEvent.click(within(pricing).getByRole("button", { name: "Show it" }));
    expect(within(pricing).queryByLabelText("Show another plan")).toBeNull();

    fireEvent.change(within(form).getByLabelText("Privacy policy"), { target: { value: "# Data\n\n- Names" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save the website" }));
    expect(await within(form).findByRole("status")).toBeTruthy();
    expect(sent.filter((s) => s.method === "PUT").pop()?.body).toEqual({
      ...content,
      heroTitle: "Never miss a call",
      heroImage: "https://cdn.acme.example/a.png",
      sections: [{ id: "cta", on: true }, { id: "features", on: true }, { id: "pricing", on: false }],
      features: [{ title: "Calls out", text: "" }, { title: "Knows you", text: "" }],
      plans: [{ id: "starter", headline: "For starters", note: "" }, { id: "base", headline: "", note: "" }],
      legal: { terms: "Our terms.", privacy: "# Data\n\n- Names" },
    });
  });

  it("shows a refusal by the field and at the top, keeping what was typed", async () => {
    fakeKit({
      "GET /api/kit/admin/website": settings(),
      "PUT /api/kit/admin/website": () => Response.json({ message: "Invalid.", errors: {
        heroImage: ["The Hero image address field must be a valid URL."], "features.1.title": ["The features.1.title field is required."],
      } }, { status: 422 }),
    });
    show();
    const form = await screen.findByRole("form", { name: "Website" });
    fireEvent.change(within(form).getByLabelText("Image address (https://…, optional)"), { target: { value: "javascript:alert(1)" } });
    fireEvent.change(within(form).getAllByLabelText("Title")[1], { target: { value: "" } });
    fireEvent.click(within(form).getByRole("button", { name: "Save the website" }));
    const alert = await within(form).findByRole("alert");
    expect(within(alert).getByText("The Hero image address field must be a valid URL.")).toBeTruthy();
    expect(within(alert).getByText("The features.1.title field is required.")).toBeTruthy();
    const feature = form.querySelector('[data-feature="1"]') as HTMLElement;
    expect(within(feature).getByText("The features.1.title field is required.")).toBeTruthy();
    expect((within(form).getByLabelText("Image address (https://…, optional)") as HTMLInputElement).value).toBe("javascript:alert(1)");
    expect(within(form).queryByRole("status")).toBeNull();
  });
});
