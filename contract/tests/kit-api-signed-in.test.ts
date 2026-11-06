/**
 * The kit API's signed-in answers, held to kit-api.yaml on a running backend: a staff member and a customer member
 * sign in and read every GET the document gives them — each answers 200 with a valid body and no undeclared field.
 * kit-api.test.ts reaches every operation signed out; this is what stops a controller and the document drifting
 * apart on what they answer once signed in. It only reads, so it is safe on any backend.
 *
 * Needs KIT_BASE_URL and two logins: KIT_STAFF_EMAIL / KIT_STAFF_PASSWORD (the operator's staff) and
 * KIT_MEMBER_EMAIL / KIT_MEMBER_PASSWORD (a member of a customer account). The backend must hold at least one
 * customer, agent and call to read one of each; without the logins the file is skipped and says so.
 */
import { join } from "node:path";
import { beforeAll, describe, expect, it } from "vitest";
import { Spec } from "../lib/openapi";

const kit = new Spec(join(__dirname, "..", "kit-api.yaml"));
const BASE = process.env.KIT_BASE_URL?.replace(/\/$/, "");
const STAFF = [process.env.KIT_STAFF_EMAIL, process.env.KIT_STAFF_PASSWORD] as const;
const MEMBER = [process.env.KIT_MEMBER_EMAIL, process.env.KIT_MEMBER_PASSWORD] as const;
const READY = Boolean(BASE && STAFF.every(Boolean) && MEMBER.every(Boolean));

/** A browser-like client: keeps the backend's cookies and echoes XSRF-TOKEN on writes, as the console does. */
class Client {
  private jar = new Map<string, string>();

  async send(method: string, path: string, body?: unknown): Promise<Response> {
    const headers: Record<string, string> = { accept: "application/json" };
    if (this.jar.size) headers.cookie = [...this.jar].map(([k, v]) => `${k}=${v}`).join("; ");
    const xsrf = this.jar.get("XSRF-TOKEN");
    if (method !== "GET" && xsrf) headers["x-xsrf-token"] = decodeURIComponent(xsrf);
    if (body !== undefined) headers["content-type"] = "application/json";
    const r = await fetch(`${BASE}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    for (const c of r.headers.getSetCookie()) {
      const [pair] = c.split(";");
      const at = pair.indexOf("=");
      this.jar.set(pair.slice(0, at), pair.slice(at + 1));
    }
    return r;
  }

  async signIn([email, password]: readonly [string | undefined, string | undefined]): Promise<void> {
    await this.send("GET", "/api/kit/session");
    const r = await this.send("POST", "/api/kit/session", { email, password });
    expect(r.status, "signed in").toBe(200);
  }

  /** GET `path` as `operationId` documents it: 200, a valid body, no undeclared field. Answers the body. */
  async read(operationId: string, path: string): Promise<any> {
    const op = kit.byOperationId(operationId);
    expect(op, operationId).toBeDefined();
    const r = await this.send("GET", path);
    const text = await r.text();
    expect(r.status, `${operationId} ${path}: ${text.slice(0, 200)}`).toBe(200);
    const schema = kit.responseSchema(op!, 200);
    const body = JSON.parse(text);
    expect(kit.validate(schema.pointer!, body), operationId).toEqual([]);
    expect(kit.undeclared(schema.pointer!, body), operationId).toEqual([]);
    return body;
  }
}

describe.skipIf(!READY)(`signed in on a running backend (${READY ? BASE : "KIT_BASE_URL and the two logins not set"})`, () => {
  const staff = new Client();
  const member = new Client();
  beforeAll(async () => {
    await staff.signIn(STAFF);
    await member.signIn(MEMBER);
  });

  it("staff: session, customers, a customer, its team and wallet, pricing, the catalog, sign-ups, email and its templates, payments, texts, bot check, sign-in providers", async () => {
    await staff.read("getSession", "/api/kit/session");
    const { customers } = await staff.read("listCustomers", "/api/kit/admin/customers");
    expect(customers.length, "a customer to read").toBeGreaterThan(0);
    const id = customers[0].id;
    await staff.read("getCustomer", `/api/kit/admin/customers/${id}`);
    await staff.read("listCustomerTeam", `/api/kit/admin/customers/${id}/team`);
    await staff.read("getCustomerWallet", `/api/kit/admin/customers/${id}/wallet`);
    await staff.read("getCatalogCuration", "/api/kit/admin/catalog");
    await staff.read("listCatalogVoices", "/api/kit/admin/catalog/voices?provider=x");
    await staff.read("getPricing", "/api/kit/admin/pricing");
    await staff.read("getSignups", "/api/kit/admin/signups");
    const email = await staff.read("getEmailSettings", "/api/kit/admin/email");
    for (const s of email.senders) expect(s.fields.filter((f: { type: string; value: unknown }) => f.type === "secret" && f.value !== null), "no secret answered").toEqual([]);
    const payments = await staff.read("getPayments", "/api/kit/admin/payments");
    for (const g of payments.gateways) expect(g.fields.filter((f: { type: string; value: unknown }) => f.type === "secret" && f.value !== null), "no secret answered").toEqual([]);
    const sms = await staff.read("getSmsSettings", "/api/kit/admin/sms");
    for (const s of sms.senders) expect(s.fields.filter((f: { type: string; value: unknown }) => f.type === "secret" && f.value !== null), "no secret answered").toEqual([]);
    await staff.read("getAccount", "/api/kit/account");
    await staff.read("getCaptchaSettings", "/api/kit/admin/captcha");
    await staff.read("getSsoSettings", "/api/kit/admin/sso");
    await staff.read("getAdminDashboard", "/api/kit/admin/dashboard");
    await staff.read("getVoicePlatformHealth", "/api/kit/admin/telenow");
    await staff.read("listStaff", "/api/kit/admin/staff");
    await staff.read("listAuditEvents", "/api/kit/admin/audit");
    await staff.read("listEmailTemplates", "/api/kit/admin/email/templates");
    const costs = await staff.read("getCostReport", "/api/kit/admin/costs");
    for (const g of costs.groups as { value: string }[]) await staff.read("getCostReport", `/api/kit/admin/costs?by=${encodeURIComponent(g.value)}`);
    const website = await staff.read("getWebsiteSettings", "/api/kit/admin/website");
    expect(website.content.sections.map((s: { id: string }) => s.id).sort(), "every section once").toEqual(website.sectionChoices.map((c: { value: string }) => c.value).sort());
  });

  it("member: session, own account, accounts, home, team, agents and one, calls and one, wallet and payments, do-not-call list, numbers and numbers for sale, stack choices and voices, knowledge, campaigns", async () => {
    await member.read("getSession", "/api/kit/session");
    await member.read("getAccount", "/api/kit/account");
    await member.read("listMyWorkspaces", "/api/kit/console/workspaces");
    await member.read("getConsoleHome", "/api/kit/console/home");
    await member.read("getTeam", "/api/kit/console/team");
    const { agents } = await member.read("listAgents", "/api/kit/console/agents");
    expect(agents.length, "an agent to read").toBeGreaterThan(0);
    await member.read("getAgent", `/api/kit/console/agents/${agents[0].id}`);
    await member.read("getAgentSettingsForm", "/api/kit/console/agents/settings-form");
    for (const a of agents as { id: string }[]) {
      await member.read("getAgentFlow", `/api/kit/console/agents/${a.id}/flow`);
      await member.read("getAgentCallForm", `/api/kit/console/agents/${a.id}/call-form`);
      await member.read("listAgentVersions", `/api/kit/console/agents/${a.id}/versions`);
      const { webhooks } = await member.read("listAgentWebhooks", `/api/kit/console/agents/${a.id}/webhooks`);
      for (const w of webhooks as { id: string }[]) await member.read("listAgentWebhookDeliveries", `/api/kit/console/agents/${a.id}/webhooks/${w.id}/deliveries`);
    }
    const { calls } = await member.read("listCalls", "/api/kit/console/calls");
    expect(calls.length, "a call to read").toBeGreaterThan(0);
    for (const c of calls) {
      await member.read("getCall", `/api/kit/console/calls/${c.id}`);
      await member.read("getCallWork", `/api/kit/console/calls/${c.id}/work`);
    }
    await member.read("listFollowUps", "/api/kit/console/followups");
    await member.read("listOwnVoices", "/api/kit/console/own-voices");
    await member.read("getPhone", "/api/kit/console/phone");
    await member.read("getWallet", "/api/kit/console/wallet");
    const topUp = await member.read("getTopUp", "/api/kit/console/payments");
    for (const p of topUp.payments) await member.read("getPayment", `/api/kit/console/payments/${p.id}`);
    await member.read("listDncEntries", "/api/kit/console/dnc");
    await member.read("listPhoneNumbers", "/api/kit/console/numbers");
    await member.read("searchAvailableNumbers", "/api/kit/console/numbers/available?country=IN");
    await member.read("getStackChoices", "/api/kit/console/catalog");
    await member.read("listVoices", "/api/kit/console/voices");
    const { knowledgeBases } = await member.read("listKnowledgeBases", "/api/kit/console/knowledge");
    for (const kb of knowledgeBases) await member.read("getKnowledgeBase", `/api/kit/console/knowledge/${kb.id}`);
    await member.read("listConsent", "/api/kit/console/consent");
    const { campaigns } = await member.read("listCampaigns", "/api/kit/console/campaigns");
    for (const c of campaigns) {
      await member.read("getCampaign", `/api/kit/console/campaigns/${c.id}`);
      await member.read("listCampaignTargets", `/api/kit/console/campaigns/${c.id}/targets`);
    }
  });
});
