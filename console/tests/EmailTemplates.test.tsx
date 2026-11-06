/**
 * Admin → Email → Templates (admin/EmailTemplates.tsx): every email with the subject it is sent with, marked when the
 * words are the operator's; editing one shows what each placeholder stands for and sends its paragraphs (a blank line
 * between two) with the button's words only for an email that has a button; the backend's words for a refused field
 * show at that field; the preview is the email's HTML in a frame where nothing runs; going back to the original words
 * refills the form and drops the mark.
 */
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import type { EmailTemplate, EmailTemplateTexts } from "../src/api";
import { EmailTemplates } from "../src/admin/EmailTemplates";
import { fakeKit } from "./fakeKit";

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const paidDefaults: EmailTemplateTexts = {
  subject: "Payment received: :amount", lines: ["Hi :name, we received :amount for :account."], action: "Open your wallet", after: [],
};

function template(over: Partial<EmailTemplate> & { kind: string }): EmailTemplate {
  return {
    name: over.kind, hasButton: true, edited: false, texts: paidDefaults, defaults: paidDefaults,
    placeholders: [{ name: "brand", about: "Your brand name" }, { name: "amount", about: "The amount paid, with its currency" }, { name: "name", about: null }],
    ...over,
  };
}

const paid = template({ kind: "payment_received", name: "Payment received" });
const test = template({
  kind: "test", name: "Test", hasButton: false, placeholders: [{ name: "brand", about: "Your brand name" }],
  texts: { subject: "Email from :brand works", lines: ["A test."], action: null, after: [] },
  defaults: { subject: "Email from :brand works", lines: ["A test."], action: null, after: [] },
});
const lowEdited = template({
  kind: "balance_low", name: "Low balance", edited: true, texts: { subject: "Top up soon", lines: ["Low."], action: "Add credit", after: [] },
});

function list(...templates: EmailTemplate[]) {
  return { "GET /api/kit/admin/email/templates": { templates } };
}

describe("admin email templates", () => {
  it("lists every email with the subject it is sent with, marking the operator's words, and opens one with its placeholders", async () => {
    fakeKit(list(test, paid, lowEdited));
    render(<EmailTemplates />);
    const items = await screen.findAllByRole("listitem");
    expect(items.map((li) => li.querySelector("span")?.textContent?.trim())).toEqual([
      "Test · Email from :brand works", "Payment received · Payment received: :amount", "Low balance · Top up soon Your words",
    ]);

    fireEvent.click(screen.getByRole("button", { name: "Edit Payment received" }));
    const form = screen.getByRole("form", { name: "Edit Payment received" });
    const placeholders = within(form).getByLabelText("Placeholders");
    expect([...placeholders.querySelectorAll("dt")].map((t) => t.textContent)).toEqual([":brand", ":amount", ":name"]);
    expect([...placeholders.querySelectorAll("dd")].map((d) => d.textContent)).toEqual(["Your brand name", "The amount paid, with its currency", ""]);
    expect((within(form).getByLabelText("Subject") as HTMLInputElement).value).toBe("Payment received: :amount");
    expect((within(form).getByLabelText("Button") as HTMLInputElement).value).toBe("Open your wallet");
    expect(within(form).queryByRole("button", { name: "Use the original words" })).toBeNull();
  });

  it("saves the paragraphs as typed, a blank line between two, and marks the email as the operator's words", async () => {
    let saved: unknown = null;
    const sent = fakeKit({
      ...list(paid),
      "PUT /api/kit/admin/email/templates/payment_received": (body: unknown) => {
        saved = body;
        return Response.json({ ...paid, edited: true, texts: body });
      },
    });
    render(<EmailTemplates />);
    fireEvent.click(await screen.findByRole("button", { name: "Edit Payment received" }));
    fireEvent.change(screen.getByLabelText("Subject"), { target: { value: "Thanks :name" } });
    fireEvent.change(screen.getByLabelText("Before the button"), { target: { value: "  First line\nsame paragraph \n\n\n Second  \n  \n" } });
    fireEvent.change(screen.getByLabelText("Button"), { target: { value: "See it" } });
    fireEvent.change(screen.getByLabelText("After the button"), { target: { value: "Bye" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));

    expect(await screen.findByText("Saved. Emails go with these words from now on.")).toBeTruthy();
    expect(saved).toEqual({ subject: "Thanks :name", lines: ["First line\nsame paragraph", "Second"], action: "See it", after: ["Bye"] });
    expect(sent.filter((s) => s.method === "PUT")).toHaveLength(1);
    expect(screen.getByText("Your words")).toBeTruthy();
    expect((screen.getByLabelText("Subject") as HTMLInputElement).value).toBe("Thanks :name");
  });

  it("shows the backend's words at the field it refused", async () => {
    fakeKit({
      ...list(paid),
      "PUT /api/kit/admin/email/templates/payment_received": () =>
        Response.json({ message: "invalid", errors: { lines: ["This email cannot fill :nme. It can use: :brand, :amount, :name."] } }, { status: 422 }),
    });
    render(<EmailTemplates />);
    fireEvent.click(await screen.findByRole("button", { name: "Edit Payment received" }));
    fireEvent.change(screen.getByLabelText("Before the button"), { target: { value: "Hi :nme" } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    const said = await screen.findByText("This email cannot fill :nme. It can use: :brand, :amount, :name.");
    expect(said.closest(".field")?.querySelector("textarea")?.id).toBe("template-payment_received-lines");
    expect(screen.queryByText("Your words")).toBeNull();
  });

  it("previews the words before saving, in a frame where nothing runs", async () => {
    let previewed: unknown = null;
    const sent = fakeKit({
      ...list(paid),
      "POST /api/kit/admin/email/templates/payment_received/preview": (body: unknown) => {
        previewed = body;
        return Response.json({ subject: "Payment received: [amount]", html: "<!doctype html><p>Hi [name]</p>", text: "Hi [name]" });
      },
    });
    render(<EmailTemplates />);
    fireEvent.click(await screen.findByRole("button", { name: "Edit Payment received" }));
    fireEvent.click(screen.getByRole("button", { name: "Preview" }));
    const frame = (await screen.findByTitle("Preview of Payment received")) as HTMLIFrameElement;
    expect([frame.getAttribute("sandbox"), frame.getAttribute("srcdoc")]).toEqual(["", "<!doctype html><p>Hi [name]</p>"]);
    expect(screen.getByText("Payment received: [amount]")).toBeTruthy();
    expect(previewed).toEqual(paidDefaults);
    expect(sent.some((s) => s.method === "PUT")).toBe(false);
  });

  it("goes back to the original words, refilling the form", async () => {
    const sent = fakeKit({
      ...list(lowEdited),
      "DELETE /api/kit/admin/email/templates/balance_low": { ...lowEdited, edited: false, texts: paidDefaults },
    });
    render(<EmailTemplates />);
    fireEvent.click(await screen.findByRole("button", { name: "Edit Low balance" }));
    expect((screen.getByLabelText("Subject") as HTMLInputElement).value).toBe("Top up soon");
    fireEvent.click(screen.getByRole("button", { name: "Use the original words" }));

    expect(await screen.findByDisplayValue("Payment received: :amount")).toBeTruthy();
    expect((screen.getByLabelText("Before the button") as HTMLTextAreaElement).value).toBe("Hi :name, we received :amount for :account.");
    expect(screen.queryByText("Your words")).toBeNull();
    expect(screen.queryByRole("button", { name: "Use the original words" })).toBeNull();
    expect(sent.filter((s) => s.method === "DELETE").map((s) => s.path)).toEqual(["/api/kit/admin/email/templates/balance_low"]);
  });

  it("an email without a button has no button field and sends none", async () => {
    let saved: unknown = null;
    fakeKit({
      ...list(test),
      "PUT /api/kit/admin/email/templates/test": (body: unknown) => {
        saved = body;
        return Response.json({ ...test, edited: true, texts: body });
      },
    });
    render(<EmailTemplates />);
    fireEvent.click(await screen.findByRole("button", { name: "Edit Test" }));
    expect(screen.queryByLabelText("Button")).toBeNull();
    fireEvent.change(screen.getByLabelText("Text"), { target: { value: "It works." } });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await screen.findByText("Your words");
    expect(saved).toEqual({ subject: "Email from :brand works", lines: ["It works."], action: null, after: [] });
  });
});
