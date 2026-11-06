/**
 * brand.ts: role names become safe CSS variable names, every role the operator sets is applied, and the brand
 * name becomes the page title.
 */
import { afterEach, describe, expect, it } from "vitest";
import { applyBrand, cssRole } from "../src/brand";

afterEach(() => {
  document.documentElement.removeAttribute("style");
  document.title = "";
});

describe("cssRole", () => {
  it("lower-cases and dashes a role name", () => {
    expect(cssRole("Primary Contrast")).toBe("primary-contrast");
  });

  it("drops characters a custom property cannot hold and trims the dashes they leave", () => {
    expect(cssRole("  --x__y;}  ")).toBe("x-y");
  });

  it("answers empty for a role with nothing usable", () => {
    expect(cssRole("!!!")).toBe("");
  });
});

describe("applyBrand", () => {
  it("sets a variable for every role, whatever the roles are", () => {
    applyBrand({ name: "Acme Voice", logoUrl: null, supportEmail: null, passwordReset: false, signUp: false, signInPassword: true, signInLink: false, signInEmailCode: false, signInCode: false, signInCodeChannel: null, signInWith: [], captcha: null, colors: { primary: "#112233", "Call Badge": "rgb(1, 2, 3)" } });
    const style = document.documentElement.style;
    expect(style.getPropertyValue("--color-primary")).toBe("#112233");
    expect(style.getPropertyValue("--color-call-badge")).toBe("rgb(1, 2, 3)");
  });

  it("skips a role whose name has nothing usable", () => {
    applyBrand({ name: "Acme Voice", logoUrl: null, supportEmail: null, passwordReset: false, signUp: false, signInPassword: true, signInLink: false, signInEmailCode: false, signInCode: false, signInCodeChannel: null, signInWith: [], captcha: null, colors: { "!!!": "#000000" } });
    expect(document.documentElement.getAttribute("style") ?? "").not.toContain("--color-");
  });

  it("titles the page with the brand name", () => {
    applyBrand({ name: "Acme Voice", logoUrl: null, supportEmail: null, passwordReset: false, signUp: false, signInPassword: true, signInLink: false, signInEmailCode: false, signInCode: false, signInCodeChannel: null, signInWith: [], captcha: null, colors: {} });
    expect(document.title).toBe("Acme Voice");
  });
});
