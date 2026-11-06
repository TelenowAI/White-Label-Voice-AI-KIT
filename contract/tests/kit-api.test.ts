/**
 * The kit API document (kit-api.yaml) is well formed, and a running backend serves it: every operation it
 * describes, sent to the backend at KIT_BASE_URL, answers a documented status with a valid body and no
 * undeclared field. This is the test both stacks (Laravel now, Node later) must pass.
 *
 * Without KIT_BASE_URL the live part is skipped and says so. Run it against Laravel with
 * `php artisan serve` and `KIT_BASE_URL=http://127.0.0.1:8000 pnpm --filter contract test`.
 */
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { Spec } from "../lib/openapi";

const kit = new Spec(join(__dirname, "..", "kit-api.yaml"));
const ops = kit.operations();
const BASE = process.env.KIT_BASE_URL?.replace(/\/$/, "");

describe("the kit API document", () => {
  it("describes operations, each with a unique operationId and a summary", () => {
    expect(ops.length).toBeGreaterThan(0);
    const ids = ops.map((o) => o.operationId);
    expect(ids.every(Boolean)).toBe(true);
    expect(new Set(ids).size).toBe(ids.length);
    expect(ops.filter((o) => !o.node.summary).map((o) => o.operationId)).toEqual([]);
  });

  it("describes every response, with a schema for every JSON body", () => {
    const problems: string[] = [];
    for (const op of ops) {
      for (const [status, resp] of Object.entries((op.node.responses ?? {}) as Record<string, any>)) {
        const r = kit.resolve(resp) as Record<string, any>;
        if (!r?.description) problems.push(`${op.operationId} ${status}: no description`);
        const json = r?.content?.["application/json"];
        if (json && !json.schema) problems.push(`${op.operationId} ${status}: JSON with no schema`);
      }
    }
    expect(problems).toEqual([]);
  });
});

describe.skipIf(!BASE)(`a running backend (${BASE ?? "KIT_BASE_URL not set"})`, () => {
  // One browser-like client: it keeps the backend's cookies (the session, XSRF-TOKEN) and echoes XSRF-TOKEN on
  // writes, as the console does. Every case is safe on a backend nobody has installed yet and on one in use: it
  // reads, or sends a write the backend refuses (an unknown step, an empty sign-in), or signs out.
  const jar = new Map<string, string>();
  async function send(method: string, path: string, body?: unknown) {
    const headers: Record<string, string> = { accept: "application/json" };
    if (jar.size) headers.cookie = [...jar].map(([k, v]) => `${k}=${v}`).join("; ");
    const xsrf = jar.get("XSRF-TOKEN");
    if (method !== "GET" && xsrf) headers["x-xsrf-token"] = decodeURIComponent(xsrf);
    if (body !== undefined) headers["content-type"] = "application/json";
    // Redirects are answers too (a sign-in provider's start and callback): checked as they come, never followed.
    const r = await fetch(`${BASE}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), redirect: "manual" });
    for (const c of r.headers.getSetCookie()) {
      const [pair] = c.split(";");
      const at = pair.indexOf("=");
      jar.set(pair.slice(0, at), pair.slice(at + 1));
    }
    return r;
  }

  // Parameterless GETs need no case of their own (an admin one is listed below, after signing out); everything
  // else is listed here. The session is read first so the writes after it carry its XSRF-TOKEN.
  const cases: Array<{ operationId: string; name: string; path?: string; body?: unknown }> = [
    ...ops.filter((o) => o.method === "GET" && !o.path.includes("{") && !o.path.startsWith("/api/kit/admin/"))
      .map((o) => ({ operationId: o.operationId!, name: "read" })),
    { operationId: "submitSetupStep", name: "an unknown step", path: "/api/kit/setup/steps/no-such-step", body: {} },
    { operationId: "submitSetupStep", name: "the last step, with nothing before it done", path: "/api/kit/setup/steps/finish", body: {} },
    { operationId: "signIn", name: "an empty sign-in", body: {} },
    { operationId: "signIn", name: "a sign-in nobody can pass", body: { email: "nobody@example.invalid", password: "not the password" } },
    { operationId: "signOut", name: "signing out" },
    { operationId: "forgotPassword", name: "an address nobody uses", body: { email: "nobody@example.invalid" } },
    { operationId: "getPasswordReset", name: "a link nobody made", path: "/api/kit/password/reset/never-made" },
    { operationId: "resetPassword", name: "a link nobody made", path: "/api/kit/password/reset/never-made", body: { password: "a long enough password 1" } },
    { operationId: "signUp", name: "an empty sign-up", body: {} },
    { operationId: "getSignupVerification", name: "a link nobody made", path: "/api/kit/signup/verify/never-made" },
    { operationId: "verifySignup", name: "a link nobody made", path: "/api/kit/signup/verify/never-made", body: {} },
    { operationId: "signUpByPhone", name: "an empty sign-up", body: {} },
    { operationId: "verifyPhoneSignup", name: "a code nobody was sent", body: { phone: "+14155550123", code: "000000" } },
    { operationId: "verifyEmailSignup", name: "a code nobody was sent", body: { email: "nobody@example.invalid", code: "000000" } },
    { operationId: "signUpWithProvider", name: "with no offer waiting", body: { name: "Ana", accountName: "Acme" } },
    { operationId: "requestSignInLink", name: "an address nobody uses", body: { email: "nobody@example.invalid" } },
    { operationId: "useSignInLink", name: "a link nobody made", path: "/api/kit/session/link/never-made", body: {} },
    // Signed out by now: the admin panel refuses (401), or answers 409 before the install.
    { operationId: "listCustomers", name: "signed out", path: "/api/kit/admin/customers" },
    { operationId: "createCustomer", name: "signed out", path: "/api/kit/admin/customers", body: { name: "Acme" } },
    { operationId: "getCustomer", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000" },
    { operationId: "suspendCustomer", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/suspend", body: {} },
    { operationId: "resumeCustomer", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/resume", body: {} },
    { operationId: "retryCustomer", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/retry", body: {} },
    { operationId: "listCustomerTeam", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/team" },
    { operationId: "inviteCustomerMember", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/team/invites", body: { email: "a@example.com", role: "owner" } },
    { operationId: "revokeCustomerInvite", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/team/invites/00000000-0000-4000-8000-000000000000" },
    { operationId: "removeCustomerMember", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/team/members/1" },
    { operationId: "getPricing", name: "signed out", path: "/api/kit/admin/pricing" },
    { operationId: "updatePricing", name: "signed out", path: "/api/kit/admin/pricing", body: { currency: "INR", usdRate: "83" } },
    { operationId: "savePriceList", name: "signed out", path: "/api/kit/admin/pricing/lists/standard", body: { name: "Standard", definition: {} } },
    { operationId: "deletePriceList", name: "signed out", path: "/api/kit/admin/pricing/lists/standard" },
    { operationId: "getCustomerWallet", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/wallet" },
    { operationId: "addCustomerCredit", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/wallet/credits", body: { amount: "1", reference: "00000000-0000-4000-8000-000000000000" } },
    { operationId: "setCustomerPlan", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/plan", body: { priceListId: null } },
    { operationId: "getInvite", name: "a link nobody made", path: "/api/kit/invites/never-made" },
    { operationId: "acceptInvite", name: "a link nobody made", path: "/api/kit/invites/never-made/accept", body: { password: "x" } },
    { operationId: "selectWorkspace", name: "signed out", body: { id: "00000000-0000-4000-8000-000000000000" } },
    { operationId: "inviteTeamMember", name: "signed out", body: { email: "a@example.com", role: "member" } },
    { operationId: "revokeTeamInvite", name: "signed out", path: "/api/kit/console/team/invites/00000000-0000-4000-8000-000000000000" },
    { operationId: "removeTeamMember", name: "signed out", path: "/api/kit/console/team/members/1" },
    { operationId: "createAgent", name: "signed out", body: { name: "Front desk" } },
    { operationId: "getAgent", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000" },
    { operationId: "updateAgent", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000", body: { name: "x" } },
    { operationId: "deleteAgent", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000" },
    { operationId: "retryAgent", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/retry", body: {} },
    { operationId: "startTestCall", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/test-call", body: {} },
    { operationId: "getCall", name: "signed out", path: "/api/kit/console/calls/00000000-0000-4000-8000-000000000000" },
    { operationId: "getCallRecording", name: "signed out", path: "/api/kit/console/calls/00000000-0000-4000-8000-000000000000/recordings/0" },
    { operationId: "receiveTelenowEvent", name: "an unsigned delivery", body: { event: "call.completed" } },
    { operationId: "addDncEntry", name: "signed out", body: { phoneNumber: "+14155550123" } },
    { operationId: "importDncEntries", name: "signed out", body: { csv: "+14155550123" } },
    { operationId: "removeDncEntry", name: "signed out", path: "/api/kit/console/dnc/00000000-0000-4000-8000-000000000000" },
    { operationId: "buyPhoneNumber", name: "signed out", body: { number: "+918045671001", country: "IN" } },
    { operationId: "setPhoneNumberAgent", name: "signed out", path: "/api/kit/console/numbers/00000000-0000-4000-8000-000000000000/agent", body: { agentId: null } },
    { operationId: "releasePhoneNumber", name: "signed out", path: "/api/kit/console/numbers/00000000-0000-4000-8000-000000000000" },
    { operationId: "getCatalogCuration", name: "signed out", path: "/api/kit/admin/catalog" },
    { operationId: "saveCatalogCuration", name: "signed out", path: "/api/kit/admin/catalog", body: { kinds: {}, defaults: {} } },
    { operationId: "refreshCatalog", name: "signed out", path: "/api/kit/admin/catalog/refresh" },
    { operationId: "listCatalogVoices", name: "signed out", path: "/api/kit/admin/catalog/voices?provider=x" },
    { operationId: "getEmailSettings", name: "signed out", path: "/api/kit/admin/email" },
    { operationId: "saveEmailSite", name: "signed out", path: "/api/kit/admin/email", body: { siteUrl: "https://voice.example", fromAddress: "hello@example.com" } },
    { operationId: "addEmailSender", name: "signed out", body: { driver: "smtp" } },
    { operationId: "updateEmailSender", name: "signed out", path: "/api/kit/admin/email/senders/no-such", body: {} },
    { operationId: "deleteEmailSender", name: "signed out", path: "/api/kit/admin/email/senders/no-such" },
    { operationId: "orderEmailSenders", name: "signed out", body: { ids: [] } },
    { operationId: "sendTestEmail", name: "signed out", body: {} },
    { operationId: "getSignups", name: "signed out", path: "/api/kit/admin/signups" },
    { operationId: "saveSignupSettings", name: "signed out", body: { mode: "closed" } },
    { operationId: "approveSignup", name: "signed out", path: "/api/kit/admin/signups/no-such/approve", body: {} },
    { operationId: "rejectSignup", name: "signed out", path: "/api/kit/admin/signups/no-such/reject", body: {} },
    { operationId: "getPayments", name: "signed out", path: "/api/kit/admin/payments" },
    { operationId: "savePaymentSettings", name: "signed out", body: { minAmount: null, maxAmount: null, disputeAction: "hold" } },
    { operationId: "orderPaymentGateways", name: "signed out", body: { ids: [] } },
    { operationId: "addPaymentGateway", name: "signed out", body: { driver: "manual", values: {} } },
    { operationId: "updatePaymentGateway", name: "signed out", path: "/api/kit/admin/payments/gateways/00000000-0000-4000-8000-000000000000", body: { enabled: false } },
    { operationId: "removePaymentGateway", name: "signed out", path: "/api/kit/admin/payments/gateways/00000000-0000-4000-8000-000000000000" },
    { operationId: "testPaymentGateway", name: "signed out", path: "/api/kit/admin/payments/gateways/00000000-0000-4000-8000-000000000000/test", body: { amount: "1" } },
    { operationId: "confirmPayment", name: "signed out", path: "/api/kit/admin/payments/00000000-0000-4000-8000-000000000000/confirm", body: { amount: "1" } },
    { operationId: "cancelPayment", name: "signed out", path: "/api/kit/admin/payments/00000000-0000-4000-8000-000000000000/cancel", body: {} },
    { operationId: "refundPayment", name: "signed out", path: "/api/kit/admin/payments/00000000-0000-4000-8000-000000000000/refunds", body: { amount: "1", reference: "00000000-0000-4000-8000-000000000000" } },
    { operationId: "startTopUp", name: "signed out", body: { gatewayId: "00000000-0000-4000-8000-000000000000", amount: "100" } },
    { operationId: "getPayment", name: "signed out", path: "/api/kit/console/payments/00000000-0000-4000-8000-000000000000" },
    { operationId: "returnFromGateway", name: "a payment nobody made", path: "/api/kit/payments/00000000-0000-4000-8000-000000000000/return" },
    { operationId: "returnFromGatewayByPost", name: "a payment nobody made", path: "/api/kit/payments/00000000-0000-4000-8000-000000000000/return", body: {} },
    { operationId: "getSsoSettings", name: "signed out", path: "/api/kit/admin/sso" },
    { operationId: "addSsoProvider", name: "signed out", body: { preset: "google", name: "Google", clientId: "x", clientSecret: "y" } },
    { operationId: "updateSsoProvider", name: "signed out", path: "/api/kit/admin/sso/providers/no-such", body: { preset: "google", name: "Google", clientId: "x" } },
    { operationId: "deleteSsoProvider", name: "signed out", path: "/api/kit/admin/sso/providers/no-such" },
    { operationId: "orderSsoProviders", name: "signed out", body: { ids: [] } },
    { operationId: "getCaptchaSettings", name: "signed out", path: "/api/kit/admin/captcha" },
    { operationId: "saveCaptchaSettings", name: "signed out", body: { provider: null, forms: [] } },
    { operationId: "getWebsiteSettings", name: "signed out", path: "/api/kit/admin/website" },
    { operationId: "getAdminDashboard", name: "signed out", path: "/api/kit/admin/dashboard" },
    { operationId: "getCostReport", name: "signed out", path: "/api/kit/admin/costs" },
    { operationId: "getVoicePlatformHealth", name: "signed out", path: "/api/kit/admin/telenow" },
    { operationId: "setCustomerLimits", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/limits", body: { maxConcurrentCalls: null, monthlyCapUsd: null } },
    { operationId: "closeCustomer", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/close", body: { confirm: "x" } },
    { operationId: "viewAsCustomer", name: "signed out", path: "/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/view-as", body: {} },
    { operationId: "listStaff", name: "signed out", path: "/api/kit/admin/staff" },
    { operationId: "inviteStaff", name: "signed out", body: { email: "x@example.com", role: "support" } },
    { operationId: "revokeStaffInvite", name: "signed out", path: "/api/kit/admin/staff/invites/no-such" },
    { operationId: "updateStaffRole", name: "signed out", path: "/api/kit/admin/staff/1", body: { role: "support" } },
    { operationId: "removeStaff", name: "signed out", path: "/api/kit/admin/staff/1" },
    { operationId: "listAuditEvents", name: "signed out", path: "/api/kit/admin/audit" },
    { operationId: "listEmailTemplates", name: "signed out", path: "/api/kit/admin/email/templates" },
    { operationId: "saveEmailTemplate", name: "signed out", path: "/api/kit/admin/email/templates/payment_received", body: { subject: "Paid", lines: ["Thanks."], action: "Open", after: [] } },
    { operationId: "resetEmailTemplate", name: "signed out", path: "/api/kit/admin/email/templates/payment_received" },
    { operationId: "previewEmailTemplate", name: "signed out", path: "/api/kit/admin/email/templates/payment_received/preview", body: { subject: "Paid", lines: ["Thanks."], action: "Open", after: [] } },
    { operationId: "setLowBalanceDefault", name: "signed out", body: { lowBalanceAt: null } },
    { operationId: "setWalletAlert", name: "signed out", body: { lowBalanceAt: "500" } },
    { operationId: "getStaffInvite", name: "a link nobody made", path: "/api/kit/staff-invites/never-made" },
    { operationId: "acceptStaffInvite", name: "a link nobody made", path: "/api/kit/staff-invites/never-made/accept", body: { name: "X", password: "a long password 1" } },
    { operationId: "stopViewingAs", name: "signed out", body: {} },
    { operationId: "retryVoicePlatformWrite", name: "signed out", path: "/api/kit/admin/telenow/writes/1/retry", body: {} },
    { operationId: "retryVoicePlatformEvent", name: "signed out", path: "/api/kit/admin/telenow/events/1/retry", body: {} },
    { operationId: "reconcileCustomer", name: "signed out", path: "/api/kit/admin/telenow/reconcile/00000000-0000-4000-8000-000000000000", body: {} },
    { operationId: "saveWebsiteSettings", name: "signed out", body: { heroTitle: "Hi", sections: [], features: [], plans: [], legal: {} } },
    { operationId: "startSso", name: "a provider nobody added", path: "/api/kit/oidc/no-such/start" },
    { operationId: "finishSso", name: "a state nobody started", path: "/api/kit/oidc/callback?state=never&code=x" },
    { operationId: "getSmsSettings", name: "signed out", path: "/api/kit/admin/sms" },
    { operationId: "addSmsSender", name: "signed out", body: { driver: "http", testNumber: "+14155550123" } },
    { operationId: "updateSmsSender", name: "signed out", path: "/api/kit/admin/sms/senders/no-such", body: { testNumber: "+14155550123" } },
    { operationId: "deleteSmsSender", name: "signed out", path: "/api/kit/admin/sms/senders/no-such" },
    { operationId: "orderSmsSenders", name: "signed out", body: { ids: [] } },
    { operationId: "sendTestSms", name: "signed out", body: { testNumber: "+14155550123" } },
    { operationId: "requestSignInCode", name: "a number nobody has proven", body: { phone: "+14155550123" } },
    { operationId: "useSignInCode", name: "a code nobody was sent", body: { phone: "+14155550123", code: "000000" } },
    { operationId: "requestEmailCode", name: "an address nobody uses", body: { email: "nobody@example.invalid" } },
    { operationId: "useEmailCode", name: "a code nobody was sent", body: { email: "nobody@example.invalid", code: "000000" } },
    { operationId: "requestPhoneCode", name: "signed out", body: { phone: "+14155550123" } },
    { operationId: "verifyPhone", name: "signed out", body: { phone: "+14155550123", code: "000000" } },
    { operationId: "removePhone", name: "signed out" },
    { operationId: "receivePaymentEvent", name: "a gateway nobody added", path: "/api/kit/payments/webhooks/00000000-0000-4000-8000-000000000000", body: { type: "payment.paid" } },
    { operationId: "previewVoice", name: "signed out", body: { provider: "p0", voice: "v0" } },
    { operationId: "previewAgentVoice", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/voice-preview", body: {} },
    { operationId: "getAgentFlow", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/flow" },
    { operationId: "unpublishAgentFlow", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/flow" },
    { operationId: "saveAgentFlowDraft", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/flow/draft", body: { flow: { start: null, steps: [], exits: [] } } },
    { operationId: "discardAgentFlowDraft", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/flow/draft" },
    { operationId: "checkAgentFlow", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/flow/check", body: { flow: { start: null, steps: [], exits: [] } } },
    { operationId: "publishAgentFlow", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/flow/publish", body: { flow: { start: null, steps: [], exits: [] } } },
    { operationId: "listAgentVersions", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/versions" },
    { operationId: "getAgentVersionChanges", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/versions/1/changes" },
    { operationId: "getAgentVersionResults", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/versions/1/results" },
    { operationId: "restoreAgentVersion", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/versions/1/restore", body: {} },
    { operationId: "listAgentWebhooks", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/webhooks" },
    { operationId: "addAgentWebhook", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/webhooks", body: { url: "https://example.com/x", events: ["call.completed"] } },
    { operationId: "removeAgentWebhook", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/webhooks/00000000-0000-4000-8000-000000000000" },
    { operationId: "testAgentWebhook", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/webhooks/00000000-0000-4000-8000-000000000000/test", body: { event: "call.completed" } },
    { operationId: "listAgentWebhookDeliveries", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/webhooks/00000000-0000-4000-8000-000000000000/deliveries" },
    { operationId: "replayAgentWebhookDelivery", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/webhooks/00000000-0000-4000-8000-000000000000/deliveries/00000000-0000-4000-8000-000000000000/replay", body: {} },
    { operationId: "setAgentKnowledge", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/knowledge", body: { knowledgeBaseIds: [] } },
    { operationId: "createKnowledgeBase", name: "signed out", body: { name: "Clinic FAQ" } },
    { operationId: "getKnowledgeBase", name: "signed out", path: "/api/kit/console/knowledge/00000000-0000-4000-8000-000000000000" },
    { operationId: "deleteKnowledgeBase", name: "signed out", path: "/api/kit/console/knowledge/00000000-0000-4000-8000-000000000000" },
    { operationId: "addKnowledgeText", name: "signed out", path: "/api/kit/console/knowledge/00000000-0000-4000-8000-000000000000/documents", body: { title: "Hours", text: "Nine to six." } },
    { operationId: "uploadKnowledgeFile", name: "signed out", path: "/api/kit/console/knowledge/00000000-0000-4000-8000-000000000000/documents/upload", body: {} },
    { operationId: "addKnowledgePage", name: "signed out", path: "/api/kit/console/knowledge/00000000-0000-4000-8000-000000000000/documents/page", body: { url: "https://example.com/" } },
    { operationId: "retryKnowledgeDocument", name: "signed out", path: "/api/kit/console/knowledge/00000000-0000-4000-8000-000000000000/documents/00000000-0000-4000-8000-000000000000/retry", body: {} },
    { operationId: "deleteKnowledgeDocument", name: "signed out", path: "/api/kit/console/knowledge/00000000-0000-4000-8000-000000000000/documents/00000000-0000-4000-8000-000000000000" },
    { operationId: "createCampaign", name: "signed out", body: { name: "Recall", agentId: "00000000-0000-4000-8000-000000000000" } },
    { operationId: "listConsent", name: "signed out", path: "/api/kit/console/consent" },
    { operationId: "recordConsent", name: "signed out", path: "/api/kit/console/consent", body: { phoneNumber: "+14155550101", consentType: "implied", source: "paper" } },
    { operationId: "importConsent", name: "signed out", path: "/api/kit/console/consent/import", body: { csv: "phone_number\n+14155550101\n", consentType: "implied" } },
    { operationId: "withdrawConsent", name: "signed out", path: "/api/kit/console/consent/withdraw", body: { phoneNumber: "+14155550101" } },
    { operationId: "setConsentRule", name: "signed out", path: "/api/kit/console/consent/rule", body: { required: true } },
    { operationId: "getCampaign", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000" },
    { operationId: "updateCampaign", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000", body: {} },
    { operationId: "retryCampaign", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/retry", body: { scope: "unanswered" } },
    { operationId: "getCampaignProgress", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/progress" },
    { operationId: "exportCampaignTargets", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/targets/export" },
    { operationId: "deleteCampaign", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000" },
    { operationId: "listCampaignTargets", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/targets" },
    { operationId: "addCampaignTargets", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/targets", body: { csv: "phone\n+14155550101\n" } },
    { operationId: "startCampaign", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/start", body: {} },
    { operationId: "pauseCampaign", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/pause", body: {} },
    { operationId: "cancelCampaign", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/cancel", body: {} },
    { operationId: "getCampaignReach", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/reach" },
    { operationId: "getCampaignVariables", name: "signed out", path: "/api/kit/console/campaigns/00000000-0000-4000-8000-000000000000/variables" },
    { operationId: "getLiveCalls", name: "signed out", path: "/api/kit/console/calls/live" },
    { operationId: "getCallWork", name: "signed out", path: "/api/kit/console/calls/00000000-0000-4000-8000-000000000000/work" },
    { operationId: "listenToLiveCall", name: "signed out", path: "/api/kit/console/calls/00000000-0000-4000-8000-000000000000/listen", body: {} },
    { operationId: "getAgentCallForm", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/call-form" },
    { operationId: "placeAgentCall", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/calls", body: { number: "+14155550101" } },
    { operationId: "listOwnVoices", name: "signed out", path: "/api/kit/console/own-voices" },
    { operationId: "checkAgentStack", name: "signed out", path: "/api/kit/console/agents/00000000-0000-4000-8000-000000000000/check", body: {} },
    { operationId: "starVoice", name: "signed out", path: "/api/kit/console/voices/stars", body: { provider: "tts-provider", voice: "a-voice" } },
    { operationId: "previewRealtimeVoice", name: "signed out", body: { provider: "realtime-provider", model: "a-model" } },
    { operationId: "unstarVoice", name: "signed out", path: "/api/kit/console/voices/stars?provider=tts-provider&voice=a-voice" },
    { operationId: "createOwnVoice", name: "signed out", path: "/api/kit/console/own-voices", body: { name: "Front desk", personName: "Asha Rao", relation: "employee", confirmed: true, source: "upload" } },
    { operationId: "uploadOwnVoiceSample", name: "signed out", path: "/api/kit/console/own-voices/00000000-0000-4000-8000-000000000000/sample", body: {} },
    { operationId: "cloneOwnVoice", name: "signed out", path: "/api/kit/console/own-voices/00000000-0000-4000-8000-000000000000/clones", body: { provider: "tts-provider" } },
    { operationId: "removeOwnVoiceClone", name: "signed out", path: "/api/kit/console/own-voices/00000000-0000-4000-8000-000000000000/clones/tts-provider" },
    { operationId: "deleteOwnVoice", name: "signed out", path: "/api/kit/console/own-voices/00000000-0000-4000-8000-000000000000" },
    { operationId: "addCallNote", name: "signed out", path: "/api/kit/console/calls/00000000-0000-4000-8000-000000000000/notes", body: { body: "Called back." } },
    { operationId: "setCallWrapup", name: "signed out", path: "/api/kit/console/calls/00000000-0000-4000-8000-000000000000/wrapup", body: { wrapup: "Booked" } },
    { operationId: "addCallFollowUp", name: "signed out", path: "/api/kit/console/calls/00000000-0000-4000-8000-000000000000/followups", body: { body: "Send the form.", dueAt: "2030-01-01T09:00:00Z" } },
    { operationId: "listFollowUps", name: "signed out", path: "/api/kit/console/followups" },
    { operationId: "setFollowUp", name: "signed out", path: "/api/kit/console/followups/00000000-0000-4000-8000-000000000000", body: { done: true } },
    { operationId: "getPhone", name: "signed out", path: "/api/kit/console/phone" },
    { operationId: "placeBrowserCall", name: "signed out", path: "/api/kit/console/phone/calls", body: { callerId: "00000000-0000-4000-8000-000000000000", number: "+14155550101" } },
    { operationId: "hangUpBrowserCall", name: "signed out", path: "/api/kit/console/phone/calls/00000000-0000-4000-8000-000000000000" },
    { operationId: "transferBrowserCall", name: "signed out", path: "/api/kit/console/phone/calls/00000000-0000-4000-8000-000000000000/transfer", body: { to: "+14155550102" } },
  ];

  it("is reached on every operation the document describes", () => {
    expect([...new Set(cases.map((c) => c.operationId))].sort()).toEqual(ops.map((o) => o.operationId!).sort());
  });

  it.each(cases.map((c) => [`${c.operationId}: ${c.name}`, c] as const))("%s answers as documented", async (_n, c) => {
    const op = kit.byOperationId(c.operationId)!;
    const r = await send(op.method, c.path ?? op.path, c.body);
    const schema = kit.responseSchema(op, r.status);
    expect(schema.error).toBeUndefined();
    const text = await r.text();
    if (!schema.content) {
      expect(text, `status ${r.status} documents no body`).toBe("");
      return;
    }
    expect(r.headers.get("content-type") ?? "").toMatch(/^application\/json/);
    const body = JSON.parse(text);
    expect(kit.validate(schema.pointer!, body)).toEqual([]);
    expect(kit.undeclared(schema.pointer!, body)).toEqual([]);
  });
});
