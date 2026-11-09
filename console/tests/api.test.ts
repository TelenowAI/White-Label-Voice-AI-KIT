// @vitest-environment node
/**
 * api.ts against contract/kit-api.yaml: every call the console makes is an operation the kit API document
 * declares (so every backend that passes contract/tests can serve it); the calls list, insights, forgetting a caller, a knowledge
 * table's page and the three CSV files ask only with the query parameters their operations declare; a table's column types go
 * in the PATCH body the document declares; duplicating an agent is a POST with no body; writes echo the XSRF-TOKEN cookie; a
 * refusal is a KitApiError with the backend's message and, for a 422, its refused fields. Starter agents: the preview asks by
 * its one declared query parameter; publishing (with `reviewed: true`) and changing one send the bodies the document
 * declares, and starting an agent from one is a POST with no body. Telling the agent during a call sends the body the document declares,
 * `sayNow` either way. A campaign's link is set by a PUT with the body the document declares and stopped by a DELETE with none. Call routing:
 * the page is a GET with no query; routing a number, a rule made, changed, deleted and ordered, a pretend call and the platform's test go to
 * the paths the document declares, each with a body of the properties it declares (and its required ones), ids encoded. Numbers: a number's
 * caller id and no-agent choice are PUTs with the body the document declares, getting a lost number back is a POST with no body, and the lost
 * numbers and the renewals are GETs with no query, ids encoded. Platform billing: read by a GET with no query; the low-balance level set by a
 * PUT with the body the document declares, a level or null for none; the cost report of one customer asks by the `customer` query parameter
 * the document declares, and every customer's never sends it. Customers' invoices: one invoice and the details are GETs with no query, ids
 * encoded; the two lists ask for a page by the `page` query parameter only past the first; billing details and invoice settings are PUTs
 * carrying every property the document declares (and only those, never the answered sequenceDigits), null for none, and the settings are
 * answered with the server's sequenceDigits. Models added beyond the catalog's: the provider's own list asks by the `search` and `page`
 * query parameters the document declares (search only when given, page only past the first), a price by its one `model` parameter, encoded
 * whole (ids hold `/`, `:`, `~`); the catalog's save sends `extraModels`, a property the document declares. Processing country: the
 * operator's and a customer's (id encoded) are GETs that send `fresh` only when asked to read it now. The prompt check (an agent's,
 * id encoded, and a new agent's) is a POST of a PromptCheckInput body, values and channel only when given; an agent's logo goes as
 * multipart/form-data with the one field `file` (no content type set by hand: the browser sets the boundary) echoing the XSRF-TOKEN
 * cookie, and is taken off by a DELETE with none; call health (the account's, and a customer's, id encoded) asks by the one `minutes`
 * query parameter the document declares, only when given.
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it, vi } from "vitest";
import { parse } from "yaml";
import { KitApiError, callRecordingUrl, callsExportUrl, consentExportUrl, dncExportUrl, kitApi } from "../src/api";

const kitApiDoc = parse(readFileSync(fileURLToPath(new URL("../../contract/kit-api.yaml", import.meta.url)), "utf8")) as {
  paths: Record<string, Record<string, unknown>>;
};

/** The query parameters an operation of the document declares, by name. */
function declaredQuery(tpl: string, method: string): string[] {
  const op = kitApiDoc.paths[tpl][method] as { parameters?: { name?: string; in?: string }[] };
  return (op.parameters ?? []).filter((p) => p.in === "query").map((p) => p.name ?? "");
}

/**
 * The document's path template a concrete path matches (`/api/kit/setup/steps/{step}` for `.../steps/brand`): one the document
 * names as it is first (`/api/kit/console/agents/prompt-check`, not `/api/kit/console/agents/{agentId}`).
 */
function template(path: string): string | undefined {
  return Object.keys(kitApiDoc.paths).find((tpl) => tpl === path) ?? Object.keys(kitApiDoc.paths).find((tpl) =>
    new RegExp(`^${tpl.replace(/\{[^}]+\}/g, "[^/]+")}$`).test(path),
  );
}

afterEach(() => {
  vi.unstubAllGlobals();
});

function stubFetch(status = 200, body: unknown = {}) {
  const calls: { url: string; method: string; headers: Record<string, string> }[] = [];
  vi.stubGlobal("document", { cookie: "other=1; XSRF-TOKEN=abc%3D%3D" });
  vi.stubGlobal(
    "fetch",
    vi.fn(async (url: string, init?: RequestInit) => {
      calls.push({ url, method: (init?.method ?? "GET").toLowerCase(), headers: (init?.headers ?? {}) as Record<string, string> });
      return status === 204 ? new Response(null, { status }) : Response.json(body, { status });
    }),
  );
  return calls;
}

const everyCall: Record<keyof typeof kitApi, () => Promise<unknown>> = {
  health: () => kitApi.health(),
  brand: () => kitApi.brand(),
  setup: () => kitApi.setup(),
  submitStep: () => kitApi.submitStep("brand", { name: "x" }),
  session: () => kitApi.session(),
  signIn: () => kitApi.signIn("a@example.com", "pw"),
  signOut: () => kitApi.signOut(),
  customers: () => kitApi.customers(2),
  createCustomer: () => kitApi.createCustomer("Acme"),
  customer: () => kitApi.customer("00000000-0000-4000-8000-000000000000"),
  suspendCustomer: () => kitApi.suspendCustomer("00000000-0000-4000-8000-000000000000", "Unpaid"),
  resumeCustomer: () => kitApi.resumeCustomer("00000000-0000-4000-8000-000000000000"),
  retryCustomer: () => kitApi.retryCustomer("00000000-0000-4000-8000-000000000000"),
  customerTeam: () => kitApi.customerTeam("00000000-0000-4000-8000-000000000000"),
  inviteCustomerMember: () => kitApi.inviteCustomerMember("00000000-0000-4000-8000-000000000000", "a@example.com", "owner"),
  revokeCustomerInvite: () => kitApi.revokeCustomerInvite("00000000-0000-4000-8000-000000000000", "00000000-0000-4000-8000-000000000000"),
  removeCustomerMember: () => kitApi.removeCustomerMember("00000000-0000-4000-8000-000000000000", 7),
  invite: () => kitApi.invite("tok"),
  acceptInvite: () => kitApi.acceptInvite("tok", { password: "pw" }),
  myWorkspaces: () => kitApi.myWorkspaces(),
  selectWorkspace: () => kitApi.selectWorkspace("00000000-0000-4000-8000-000000000000"),
  consoleHome: () => kitApi.consoleHome(),
  team: () => kitApi.team(),
  inviteTeamMember: () => kitApi.inviteTeamMember("a@example.com", "member"),
  revokeTeamInvite: () => kitApi.revokeTeamInvite("00000000-0000-4000-8000-000000000000"),
  removeTeamMember: () => kitApi.removeTeamMember(7),
  agents: () => kitApi.agents(2),
  agentSettingsForm: () => kitApi.agentSettingsForm(),
  agentFlow: () => kitApi.agentFlow("a1"),
  saveAgentFlowDraft: () => kitApi.saveAgentFlowDraft("a1", { start: null, steps: [], exits: [] }),
  discardAgentFlowDraft: () => kitApi.discardAgentFlowDraft("a1"),
  checkAgentFlow: () => kitApi.checkAgentFlow("a1", { start: null, steps: [], exits: [] }),
  publishAgentFlow: () => kitApi.publishAgentFlow("a1", { start: null, steps: [], exits: [] }),
  unpublishAgentFlow: () => kitApi.unpublishAgentFlow("a1"),
  agentVersions: () => kitApi.agentVersions("a1"),
  agentVersionChanges: () => kitApi.agentVersionChanges("a1", 2),
  agentVersionResults: () => kitApi.agentVersionResults("a1", 2),
  restoreAgentVersion: () => kitApi.restoreAgentVersion("a1", 2),
  agentWebhooks: () => kitApi.agentWebhooks("a1"),
  addAgentWebhook: () => kitApi.addAgentWebhook("a1", { url: "https://example.com", events: ["call.completed"], includeTranscript: false }),
  removeAgentWebhook: () => kitApi.removeAgentWebhook("a1", "w1"),
  testAgentWebhook: () => kitApi.testAgentWebhook("a1", "w1", "call.completed"),
  agentWebhookDeliveries: () => kitApi.agentWebhookDeliveries("a1", "w1"),
  replayAgentWebhookDelivery: () => kitApi.replayAgentWebhookDelivery("a1", "w1", "d1"),
  agentWebChat: () => kitApi.agentWebChat("a1"),
  updateAgentWebChat: () => kitApi.updateAgentWebChat("a1", { enabled: true, websites: ["https://www.example.com"], title: null, greeting: null }),
  createAgent: () => kitApi.createAgent({ name: "Front desk" }),
  agent: () => kitApi.agent("00000000-0000-4000-8000-000000000000"),
  updateAgent: () => kitApi.updateAgent("00000000-0000-4000-8000-000000000000", { isActive: false }),
  deleteAgent: () => kitApi.deleteAgent("00000000-0000-4000-8000-000000000000"),
  retryAgent: () => kitApi.retryAgent("00000000-0000-4000-8000-000000000000"),
  duplicateAgent: () => kitApi.duplicateAgent("00000000-0000-4000-8000-000000000000"),
  startTestCall: () => kitApi.startTestCall("00000000-0000-4000-8000-000000000000"),
  wallet: () => kitApi.wallet(2),
  setWalletAlert: () => kitApi.setWalletAlert("500"),
  verifyEmailSignup: () => kitApi.verifyEmailSignup("a@example.com", "123456"),
  requestEmailCode: () => kitApi.requestEmailCode("a@example.com"),
  useEmailCode: () => kitApi.useEmailCode("a@example.com", "123456"),
  requestSignInCode: () => kitApi.requestSignInCode("+919876543210"),
  useSignInCode: () => kitApi.useSignInCode("+919876543210", "123456"),
  account: () => kitApi.account(),
  requestPhoneCode: () => kitApi.requestPhoneCode("+919876543210"),
  verifyPhone: () => kitApi.verifyPhone("+919876543210", "123456"),
  removePhone: () => kitApi.removePhone(),
  captchaSettings: () => kitApi.captchaSettings(),
  dashboard: () => kitApi.dashboard("2026-10-01", "2026-10-31"),
  costReport: () => kitApi.costReport("customer", "2026-10-01", "2026-10-31", 2),
  platformBilling: () => kitApi.platformBilling(),
  setPlatformBalanceAlert: () => kitApi.setPlatformBalanceAlert("25.50"),
  accountInvoices: () => kitApi.accountInvoices(2),
  accountInvoice: () => kitApi.accountInvoice("00000000-0000-4000-8000-000000000000"),
  billingDetails: () => kitApi.billingDetails(),
  setBillingDetails: () => kitApi.setBillingDetails({ name: "Acme Dental Clinics LLP", address: null, taxNumber: null }),
  invoiceSettings: () => kitApi.invoiceSettings(),
  setInvoiceSettings: () => kitApi.setInvoiceSettings({ prefix: "INV-", name: null, address: null, taxNumber: null, email: null, taxName: "GST", taxRate: "18" }),
  customerInvoices: () => kitApi.customerInvoices("00000000-0000-4000-8000-000000000000", 2),
  customerInvoice: () => kitApi.customerInvoice("00000000-0000-4000-8000-000000000000"),
  setCustomerLimits: () => kitApi.setCustomerLimits("00000000-0000-4000-8000-000000000000", { maxConcurrentCalls: 3, monthlyCapUsd: null }),
  closeCustomer: () => kitApi.closeCustomer("00000000-0000-4000-8000-000000000000", "Acme"),
  viewAsCustomer: () => kitApi.viewAsCustomer("00000000-0000-4000-8000-000000000000"),
  stopViewingAs: () => kitApi.stopViewingAs(),
  staff: () => kitApi.staff(),
  inviteStaff: () => kitApi.inviteStaff("a@example.com", "support"),
  revokeStaffInvite: () => kitApi.revokeStaffInvite("inv-1"),
  updateStaffRole: () => kitApi.updateStaffRole(7, "admin"),
  removeStaff: () => kitApi.removeStaff(7),
  auditLog: () => kitApi.auditLog(2),
  staffInvite: () => kitApi.staffInvite("tok"),
  acceptStaffInvite: () => kitApi.acceptStaffInvite("tok", { password: "pw" }),
  voicePlatform: () => kitApi.voicePlatform(),
  retryVoicePlatformWrite: () => kitApi.retryVoicePlatformWrite(7),
  retryVoicePlatformEvent: () => kitApi.retryVoicePlatformEvent(7),
  reconcileCustomer: () => kitApi.reconcileCustomer("00000000-0000-4000-8000-000000000000"),
  platformProcessingCountry: () => kitApi.platformProcessingCountry(true),
  customerProcessingCountry: () => kitApi.customerProcessingCountry("00000000-0000-4000-8000-000000000000", true),
  websiteSettings: () => kitApi.websiteSettings(),
  saveWebsiteSettings: () => kitApi.saveWebsiteSettings({ tagline: null, metaDescription: null, heroTitle: "Hi", heroText: null, heroImage: null, ctaTitle: null, ctaText: null, sections: [], features: [], pricingIntro: null, plans: [], contactText: null, legal: {} }),
  saveCaptchaSettings: () => kitApi.saveCaptchaSettings({ provider: null, siteKey: null, secret: "", forms: [] }),
  ssoSettings: () => kitApi.ssoSettings(),
  addSsoProvider: () => kitApi.addSsoProvider({ preset: "google", name: "Google", clientId: "x", clientSecret: "y" }),
  updateSsoProvider: () => kitApi.updateSsoProvider("p1", { preset: "google", name: "Google", clientId: "x" }),
  deleteSsoProvider: () => kitApi.deleteSsoProvider("p1"),
  orderSsoProviders: () => kitApi.orderSsoProviders(["p1"]),
  smsSettings: () => kitApi.smsSettings(),
  addSmsSender: () => kitApi.addSmsSender("http", { url: "https://sms.example/send" }, "+919876543210"),
  updateSmsSender: () => kitApi.updateSmsSender("s1", { url: "https://sms.example/send" }, "+919876543210"),
  deleteSmsSender: () => kitApi.deleteSmsSender("s1"),
  orderSmsSenders: () => kitApi.orderSmsSenders(["s1"]),
  sendTestSms: () => kitApi.sendTestSms("+919876543210"),
  topUp: () => kitApi.topUp(),
  startTopUp: () => kitApi.startTopUp("00000000-0000-4000-8000-000000000000", "500"),
  payment: () => kitApi.payment("00000000-0000-4000-8000-000000000000"),
  paymentsAdmin: () => kitApi.paymentsAdmin(),
  savePaymentSettings: () => kitApi.savePaymentSettings({ minAmount: "100", maxAmount: null, disputeAction: "hold" }),
  orderPaymentGateways: () => kitApi.orderPaymentGateways(["00000000-0000-4000-8000-000000000000"]),
  addPaymentGateway: () => kitApi.addPaymentGateway("manual", { values: { instructions: "Pay to account 123" } }),
  updatePaymentGateway: () => kitApi.updatePaymentGateway("00000000-0000-4000-8000-000000000000", { enabled: true }),
  removePaymentGateway: () => kitApi.removePaymentGateway("00000000-0000-4000-8000-000000000000"),
  testPaymentGateway: () => kitApi.testPaymentGateway("00000000-0000-4000-8000-000000000000", "1"),
  confirmPayment: () => kitApi.confirmPayment("00000000-0000-4000-8000-000000000000", "500"),
  cancelPayment: () => kitApi.cancelPayment("00000000-0000-4000-8000-000000000000"),
  refundPayment: () => kitApi.refundPayment("00000000-0000-4000-8000-000000000000", "100", "00000000-0000-4000-8000-000000000001"),
  dnc: () => kitApi.dnc(2, "0123"),
  addDnc: () => kitApi.addDnc("+14155550123", null),
  importDnc: () => kitApi.importDnc("+14155550123"),
  removeDnc: () => kitApi.removeDnc("00000000-0000-4000-8000-000000000000"),
  catalogCuration: () => kitApi.catalogCuration(),
  saveCatalogCuration: () => kitApi.saveCatalogCuration({ kinds: {}, defaults: { llmProvider: null, llmModel: null, sttProvider: null, ttsProvider: null, ttsVoice: null } }),
  refreshCatalog: () => kitApi.refreshCatalog(),
  catalogVoices: () => kitApi.catalogVoices({ provider: "x" }),
  openModels: () => kitApi.openModels("gpt", 2),
  openModelPrice: () => kitApi.openModelPrice("openai/gpt-4o"),
  starterAgents: () => kitApi.starterAgents(),
  previewStarterAgent: () => kitApi.previewStarterAgent("00000000-0000-4000-8000-000000000000"),
  publishStarterAgent: () => kitApi.publishStarterAgent({ agentId: "00000000-0000-4000-8000-000000000000", name: "Front desk", description: null, reviewed: true }),
  updateStarterAgent: () => kitApi.updateStarterAgent("00000000-0000-4000-8000-000000000000", { offered: false }),
  offeredStarterAgents: () => kitApi.offeredStarterAgents(),
  createAgentFromStarter: () => kitApi.createAgentFromStarter("00000000-0000-4000-8000-000000000000"),
  forgotPassword: () => kitApi.forgotPassword("ana@example.com"),
  passwordReset: () => kitApi.passwordReset("t0"),
  resetPassword: () => kitApi.resetPassword("t0", "a long password 1"),
  emailSettings: () => kitApi.emailSettings(),
  saveEmailSite: () => kitApi.saveEmailSite({ siteUrl: "https://voice.example", fromAddress: "hello@example.com", fromName: null }),
  addEmailSender: () => kitApi.addEmailSender("smtp", { host: "smtp.example.com" }),
  updateEmailSender: () => kitApi.updateEmailSender("s0", { host: "smtp.example.com" }),
  deleteEmailSender: () => kitApi.deleteEmailSender("s0"),
  orderEmailSenders: () => kitApi.orderEmailSenders(["s0"]),
  sendTestEmail: () => kitApi.sendTestEmail(),
  emailTemplates: () => kitApi.emailTemplates(),
  saveEmailTemplate: () => kitApi.saveEmailTemplate("payment_received", { subject: "s", lines: ["l"], action: "a", after: [] }),
  resetEmailTemplate: () => kitApi.resetEmailTemplate("payment_received"),
  previewEmailTemplate: () => kitApi.previewEmailTemplate("payment_received", { subject: "s", lines: ["l"], action: "a", after: [] }),
  signupForm: () => kitApi.signupForm(),
  signUp: () => kitApi.signUp({ name: "Ana", email: "ana@example.com", accountName: "Acme" }),
  signupVerification: () => kitApi.signupVerification("t0"),
  verifySignup: () => kitApi.verifySignup("t0"),
  signUpByPhone: () => kitApi.signUpByPhone({ name: "Ana", phone: "+919876543210", accountName: "Acme" }),
  verifyPhoneSignup: () => kitApi.verifyPhoneSignup("+919876543210", "123456"),
  providerSignup: () => kitApi.providerSignup(),
  signUpWithProvider: () => kitApi.signUpWithProvider({ name: "Ana", accountName: "Acme" }),
  requestSignInLink: () => kitApi.requestSignInLink("ana@example.com"),
  useSignInLink: () => kitApi.useSignInLink("t0"),
  signups: () => kitApi.signups(),
  saveSignupSettings: () => kitApi.saveSignupSettings({ mode: "closed", domains: [], methods: ["password"], fields: [], terms: null, trialCredit: null }),
  approveSignup: () => kitApi.approveSignup("s0"),
  rejectSignup: () => kitApi.rejectSignup("s0", null),
  stackChoices: () => kitApi.stackChoices(),
  voices: () => kitApi.voices({ language: "hi", page: 2 }),
  previewVoice: () => kitApi.previewVoice("p0", "v0"),
  previewAgentVoice: () => kitApi.previewAgentVoice("00000000-0000-4000-8000-000000000000"),
  previewRealtimeVoice: () => kitApi.previewRealtimeVoice("p0", "m0", null),
  campaigns: () => kitApi.campaigns(),
  createCampaign: () => kitApi.createCampaign({ name: "Recall", agentId: "00000000-0000-4000-8000-000000000000" }),
  campaign: () => kitApi.campaign("00000000-0000-4000-8000-000000000000"),
  deleteCampaign: () => kitApi.deleteCampaign("00000000-0000-4000-8000-000000000000"),
  campaignTargets: () => kitApi.campaignTargets("00000000-0000-4000-8000-000000000000", 2),
  addCampaignTargets: () => kitApi.addCampaignTargets("00000000-0000-4000-8000-000000000000", "phone\n+14155550101\n"),
  setCampaignSource: () => kitApi.setCampaignSource("00000000-0000-4000-8000-000000000000", "https://example.com/people.csv"),
  removeCampaignSource: () => kitApi.removeCampaignSource("00000000-0000-4000-8000-000000000000"),
  startCampaign: () => kitApi.startCampaign("00000000-0000-4000-8000-000000000000"),
  pauseCampaign: () => kitApi.pauseCampaign("00000000-0000-4000-8000-000000000000"),
  cancelCampaign: () => kitApi.cancelCampaign("00000000-0000-4000-8000-000000000000"),
  updateCampaign: () => kitApi.updateCampaign("00000000-0000-4000-8000-000000000000", { maxAttempts: 2 }),
  retryCampaign: () => kitApi.retryCampaign("00000000-0000-4000-8000-000000000000", "unanswered"),
  campaignProgress: () => kitApi.campaignProgress("00000000-0000-4000-8000-000000000000"),
  consent: () => kitApi.consent(),
  recordConsent: () => kitApi.recordConsent({ phoneNumber: "+14155550101", consentType: "implied", source: "paper" }),
  importConsent: () => kitApi.importConsent({ csv: "phone_number\n+14155550101\n", consentType: "implied" }),
  withdrawConsent: () => kitApi.withdrawConsent("+14155550101", null),
  setConsentRule: () => kitApi.setConsentRule(true),
  campaignReach: () => kitApi.campaignReach("00000000-0000-4000-8000-000000000000"),
  campaignVariables: () => kitApi.campaignVariables("00000000-0000-4000-8000-000000000000"),
  knowledgeBases: () => kitApi.knowledgeBases(),
  createKnowledgeBase: () => kitApi.createKnowledgeBase("Clinic FAQ", null),
  knowledgeBase: () => kitApi.knowledgeBase("00000000-0000-4000-8000-000000000000"),
  deleteKnowledgeBase: () => kitApi.deleteKnowledgeBase("00000000-0000-4000-8000-000000000000"),
  addKnowledgeText: () => kitApi.addKnowledgeText("00000000-0000-4000-8000-000000000000", "Hours", "Nine to six."),
  uploadKnowledgeFile: () => kitApi.uploadKnowledgeFile("00000000-0000-4000-8000-000000000000", new File(["x"], "notes.txt")),
  addKnowledgePage: () => kitApi.addKnowledgePage("00000000-0000-4000-8000-000000000000", "https://example.com/"),
  retryKnowledgeDocument: () => kitApi.retryKnowledgeDocument("00000000-0000-4000-8000-000000000000", "00000000-0000-4000-8000-000000000000"),
  deleteKnowledgeDocument: () => kitApi.deleteKnowledgeDocument("00000000-0000-4000-8000-000000000000", "00000000-0000-4000-8000-000000000000"),
  knowledgeTable: () => kitApi.knowledgeTable("00000000-0000-4000-8000-000000000000", "00000000-0000-4000-8000-000000000000", { offset: 50, limit: 50 }),
  setKnowledgeColumnTypes: () => kitApi.setKnowledgeColumnTypes("00000000-0000-4000-8000-000000000000", "00000000-0000-4000-8000-000000000000", { price: "number" }),
  setAgentKnowledge: () => kitApi.setAgentKnowledge("00000000-0000-4000-8000-000000000000", []),
  numbers: () => kitApi.numbers(),
  availableNumbers: () => kitApi.availableNumbers("IN", "8045"),
  buyNumber: () => kitApi.buyNumber("+918045671001", "IN"),
  setNumberAgent: () => kitApi.setNumberAgent("00000000-0000-4000-8000-000000000000", null),
  releaseNumber: () => kitApi.releaseNumber("00000000-0000-4000-8000-000000000000"),
  setNumberRouting: () => kitApi.setNumberRouting("00000000-0000-4000-8000-000000000000", true),
  setNumberCallerId: () => kitApi.setNumberCallerId("00000000-0000-4000-8000-000000000000", true),
  setNumberUnanswered: () => kitApi.setNumberUnanswered("00000000-0000-4000-8000-000000000000", "busy"),
  lostNumbers: () => kitApi.lostNumbers(),
  getNumberBack: () => kitApi.getNumberBack("00000000-0000-4000-8000-000000000000"),
  numberRenewals: () => kitApi.numberRenewals(),
  routing: () => kitApi.routing(),
  createRoutingRule: () => kitApi.createRoutingRule({ name: "After hours", action: "answer" }),
  updateRoutingRule: () => kitApi.updateRoutingRule("00000000-0000-4000-8000-000000000000", { enabled: false }),
  deleteRoutingRule: () => kitApi.deleteRoutingRule("00000000-0000-4000-8000-000000000000"),
  orderRoutingRules: () => kitApi.orderRoutingRules(["00000000-0000-4000-8000-000000000000"]),
  tryRouting: () => kitApi.tryRouting({ numberId: "00000000-0000-4000-8000-000000000000" }),
  checkRouting: () => kitApi.checkRouting("00000000-0000-4000-8000-000000000000"),
  customerWallet: () => kitApi.customerWallet("00000000-0000-4000-8000-000000000000", 2),
  addCustomerCredit: () => kitApi.addCustomerCredit("00000000-0000-4000-8000-000000000000", { amount: "5", note: null, reference: "00000000-0000-4000-8000-000000000000" }),
  setCustomerPlan: () => kitApi.setCustomerPlan("00000000-0000-4000-8000-000000000000", "standard"),
  pricing: () => kitApi.pricing(),
  setLowBalanceDefault: () => kitApi.setLowBalanceDefault(null),
  updatePricing: () => kitApi.updatePricing({ currency: "INR", usdRate: "83", defaultPlan: null }),
  savePriceList: () => kitApi.savePriceList("standard", "Standard", {}),
  deletePriceList: () => kitApi.deletePriceList("standard"),
  calls: () => kitApi.calls(2, { agentId: "00000000-0000-4000-8000-000000000000", number: "+14155550101", placedBy: "me" }),
  insights: () => kitApi.insights({ from: "2026-11-01", to: "2026-11-07", timeZone: "Asia/Kolkata", agentId: "00000000-0000-4000-8000-000000000000" }),
  callerMemory: () => kitApi.callerMemory(),
  forgetCaller: () => kitApi.forgetCaller("+14155550101"),
  call: () => kitApi.call("00000000-0000-4000-8000-000000000000"),
  liveCalls: () => kitApi.liveCalls(),
  agentCallForm: () => kitApi.agentCallForm("00000000-0000-4000-8000-000000000000"),
  checkPrompt: () => kitApi.checkPrompt("00000000-0000-4000-8000-000000000000", { systemPrompt: "Hi" }),
  checkNewPrompt: () => kitApi.checkNewPrompt({ systemPrompt: "Hi" }),
  setAgentLogo: () => kitApi.setAgentLogo("00000000-0000-4000-8000-000000000000", new File(["x"], "logo.png", { type: "image/png" })),
  removeAgentLogo: () => kitApi.removeAgentLogo("00000000-0000-4000-8000-000000000000"),
  callHealth: () => kitApi.callHealth(360),
  customerCallHealth: () => kitApi.customerCallHealth("00000000-0000-4000-8000-000000000000", 60),
  placeAgentCall: () => kitApi.placeAgentCall("00000000-0000-4000-8000-000000000000", { number: "+14155550101" }),
  ownVoices: () => kitApi.ownVoices(),
  createOwnVoice: () => kitApi.createOwnVoice({ name: "Front desk", personName: "Asha Rao", relation: "employee", confirmed: true, language: null, script: null, source: "record" }),
  uploadOwnVoiceSample: () => kitApi.uploadOwnVoiceSample("00000000-0000-4000-8000-000000000000", new Blob(["RIFF"]), "take.webm", 4.2),
  cloneOwnVoice: () => kitApi.cloneOwnVoice("00000000-0000-4000-8000-000000000000", "tts-p0"),
  removeOwnVoiceClone: () => kitApi.removeOwnVoiceClone("00000000-0000-4000-8000-000000000000", "tts-p0"),
  deleteOwnVoice: () => kitApi.deleteOwnVoice("00000000-0000-4000-8000-000000000000"),
  starVoice: () => kitApi.starVoice("tts-p0", "v0"),
  unstarVoice: () => kitApi.unstarVoice("tts-p0", "v0"),
  checkAgentStack: () => kitApi.checkAgentStack("00000000-0000-4000-8000-000000000000"),
  listenToLiveCall: () => kitApi.listenToLiveCall("00000000-0000-4000-8000-000000000000"),
  tellAgent: () => kitApi.tellAgent("00000000-0000-4000-8000-000000000000", { text: "She is a returning patient.", sayNow: false }),
  callWork: () => kitApi.callWork("00000000-0000-4000-8000-000000000000"),
  addCallNote: () => kitApi.addCallNote("00000000-0000-4000-8000-000000000000", "Called back."),
  setCallWrapup: () => kitApi.setCallWrapup("00000000-0000-4000-8000-000000000000", "Booked"),
  addCallFollowUp: () => kitApi.addCallFollowUp("00000000-0000-4000-8000-000000000000", "Send the form.", "2030-01-01T09:00:00Z"),
  followUps: () => kitApi.followUps(2, true),
  setFollowUp: () => kitApi.setFollowUp("00000000-0000-4000-8000-000000000000", true),
  phone: () => kitApi.phone(),
  placeBrowserCall: () => kitApi.placeBrowserCall("00000000-0000-4000-8000-000000000000", "+14155550101"),
  hangUpBrowserCall: () => kitApi.hangUpBrowserCall("00000000-0000-4000-8000-000000000000"),
  transferBrowserCall: () => kitApi.transferBrowserCall("00000000-0000-4000-8000-000000000000", "+14155550102"),
};

/** Operations the console never fetches, and why. */
const notFetched: Record<string, string> = {
  receiveTelenowEvent: "Telenow calls it, not the console",
  getCallRecording: "an <audio> element loads it by URL (callRecordingUrl)",
  exportCampaignTargets: "a link downloads it by URL (campaignExportUrl)",
  exportCalls: "a link downloads it by URL (callsExportUrl)",
  exportDnc: "a link downloads it by URL (dncExportUrl)",
  exportConsent: "a link downloads it by URL (consentExportUrl)",
  receivePaymentEvent: "a payment gateway calls it, not the console",
  returnFromGateway: "a payment gateway sends the payer's browser there",
  returnFromGatewayByPost: "a payment gateway sends the payer's browser there, with a form",
  startSso: "the browser goes there to sign in (ssoStartUrl), a page load",
  finishSso: "a sign-in provider sends the browser there",
  getChatBox: "the chat box script on a customer's website calls it, not the console",
  getChatMessages: "the chat box script on a customer's website calls it, not the console",
  sendChatMessage: "the chat box script on a customer's website calls it, not the console",
  endChatConversation: "the chat box script on a customer's website calls it, not the console",
  answerRoutingRequest: "the voice platform calls it on each call to a routed number, not the console",
  getAgentLogo: "an <img> loads it by the agent's logoUrl",
};

describe("kitApi", () => {
  for (const [name, call] of Object.entries(everyCall)) {
    it(`${name} calls an operation kit-api.yaml declares`, async () => {
      const calls = stubFetch();
      await call();
      expect(calls).toHaveLength(1);
      const tpl = template(calls[0].url.split("?")[0]);
      expect(tpl, calls[0].url).toBeDefined();
      expect(Object.keys(kitApiDoc.paths[tpl!])).toContain(calls[0].method);
    });
  }

  it("covers every operation the document declares, but the ones it never fetches", () => {
    const declared = Object.values(kitApiDoc.paths).flatMap((ops) => Object.entries(ops).filter(([k]) => k !== "parameters"));
    const ids = declared.map(([, op]) => (op as { operationId: string }).operationId);
    expect(Object.keys(notFetched).every((id) => ids.includes(id))).toBe(true);
    expect(Object.keys(everyCall)).toHaveLength(declared.length - Object.keys(notFetched).length);
  });

  it("plays a recording from the URL the document declares", () => {
    const url = callRecordingUrl("00000000-0000-4000-8000-000000000000", 3);
    const tpl = template(url);
    expect(tpl).toBe("/api/kit/console/calls/{callId}/recordings/{index}");
    expect(Object.keys(kitApiDoc.paths[tpl!])).toContain("get");
  });

  it("asks for calls and insights with every filter, each a query parameter the document declares", async () => {
    const calls = stubFetch();
    const period = { from: "2026-11-01", to: "2026-11-07", timeZone: "Asia/Kolkata" };
    await kitApi.calls(2, { agentId: "a1", number: "+14155550101", placedBy: "me", ...period, outcome: "no-answer", channel: "web_call" });
    await kitApi.insights({ ...period, agentId: "a1" });
    const asked = calls.map((c) => {
      const [path, q] = c.url.split("?");
      return { tpl: template(path)!, keys: [...new URLSearchParams(q).keys()] };
    });
    expect(asked.map((a) => a.tpl)).toEqual(["/api/kit/console/calls", "/api/kit/console/insights"]);
    expect(asked[0].keys).toEqual(["page", "agentId", "number", "placedBy", "from", "to", "timeZone", "outcome", "channel"]);
    expect(asked[1].keys).toEqual(["from", "to", "timeZone", "agentId"]);
    for (const a of asked) expect(declaredQuery(a.tpl, "get")).toEqual(expect.arrayContaining(a.keys));
  });

  it("forgets a caller by the one query parameter the document declares, encoded, echoing the XSRF-TOKEN cookie", async () => {
    const calls = stubFetch(200, { removed: true });
    await expect(kitApi.forgetCaller("+1 (415) 555-0101 & more")).resolves.toEqual({ removed: true });
    const [path, q] = calls[0].url.split("?");
    expect(calls[0].method).toBe("delete");
    expect(template(path)).toBe("/api/kit/console/memory");
    expect((kitApiDoc.paths["/api/kit/console/memory"].delete as { operationId: string }).operationId).toBe("forgetCaller");
    expect(q).toBe("caller=%2B1+%28415%29+555-0101+%26+more");
    expect([...new URLSearchParams(q).entries()]).toEqual([["caller", "+1 (415) 555-0101 & more"]]);
    expect(declaredQuery("/api/kit/console/memory", "delete")).toEqual(["caller"]);
    expect(calls[0].headers["x-xsrf-token"]).toBe("abc==");
  });

  it("asks for a page of a knowledge table by the query parameters the document declares, and sets column types in a PATCH body", async () => {
    const calls = stubFetch(200, { columns: [] });
    const bodies: unknown[] = [];
    const spy = vi.mocked(fetch);
    await kitApi.knowledgeTable("k 1", "d/2", { offset: 0, limit: 50 });
    await kitApi.setKnowledgeColumnTypes("k 1", "d/2", { unit_price: "number", sold_on: "date" });
    for (const [, init] of spy.mock.calls) bodies.push(typeof init?.body === "string" ? JSON.parse(init.body) : init?.body);
    const tpl = "/api/kit/console/knowledge/{knowledgeBaseId}/documents/{documentId}/table";
    const [path, q] = calls[0].url.split("?");
    expect(path).toBe("/api/kit/console/knowledge/k%201/documents/d%2F2/table");
    expect(template(path)).toBe(tpl);
    expect((kitApiDoc.paths[tpl].get as { operationId: string }).operationId).toBe("getKnowledgeTable");
    expect([...new URLSearchParams(q).entries()]).toEqual([["offset", "0"], ["limit", "50"]]);
    expect(declaredQuery(tpl, "get")).toEqual(["offset", "limit"]);
    expect(calls[0].headers["x-xsrf-token"]).toBeUndefined();
    expect(calls[1].method).toBe("patch");
    expect(calls[1].url).toBe(path);
    expect((kitApiDoc.paths[tpl].patch as { operationId: string }).operationId).toBe("setKnowledgeColumnTypes");
    expect(bodies).toEqual([undefined, { types: { unit_price: "number", sold_on: "date" } }]);
    expect(calls[1].headers["x-xsrf-token"]).toBe("abc==");
    expect(calls[1].headers["content-type"]).toBe("application/json");
  });

  it("duplicates an agent by a POST with no body to the operation the document declares, echoing the XSRF-TOKEN cookie", async () => {
    const copy = { agent: { id: "a2" }, toRedo: [{ kind: "flow", name: null }] };
    const calls = stubFetch(201, copy);
    await expect(kitApi.duplicateAgent("a 1")).resolves.toEqual(copy);
    const tpl = "/api/kit/console/agents/{agentId}/duplicate";
    expect(calls[0].url).toBe("/api/kit/console/agents/a%201/duplicate");
    expect(template(calls[0].url)).toBe(tpl);
    expect(calls[0].method).toBe("post");
    const op = kitApiDoc.paths[tpl].post as { operationId: string; requestBody?: unknown };
    expect(op.operationId).toBe("duplicateAgent");
    expect(op.requestBody).toBeUndefined();
    expect(vi.mocked(fetch).mock.calls[0][1]?.body).toBeUndefined();
    expect(calls[0].headers["content-type"]).toBeUndefined();
    expect(calls[0].headers["x-xsrf-token"]).toBe("abc==");
  });

  it.each([
    ["exportCalls", callsExportUrl({ agentId: "a1", number: "+14155550101", from: "2026-11-01", to: "2026-11-07", timeZone: "Asia/Kolkata", outcome: "busy", channel: "chat" }),
      "/api/kit/console/calls/export", ["agentId", "number", "from", "to", "timeZone", "outcome", "channel"]],
    ["exportDnc", dncExportUrl(), "/api/kit/console/dnc/export", []],
    ["exportConsent", consentExportUrl(), "/api/kit/console/consent/export", []],
  ])("downloads %s from the URL the document declares, with only the filters it declares", (id, url, path, keys) => {
    const [p, q] = url.split("?");
    const tpl = template(p);
    expect(tpl).toBe(path);
    expect((kitApiDoc.paths[tpl!].get as { operationId: string }).operationId).toBe(id);
    expect([...new URLSearchParams(q).keys()]).toEqual(keys);
    expect(declaredQuery(tpl!, "get")).toEqual(expect.arrayContaining(keys));
  });

  it("tells the agent during a call with the body the document declares, sayNow either way, echoing the XSRF-TOKEN cookie", async () => {
    const note = { by: "Ana", at: "2026-11-08T10:00:00Z", text: "x", sayNow: true, delivery: "sayingNow" };
    const calls = stubFetch(202, { note });
    await expect(kitApi.tellAgent("c 1", { text: "She is a returning patient.", sayNow: true })).resolves.toEqual({ note });
    await kitApi.tellAgent("c/2", { text: "Offer the 10 am slot.", sayNow: false });
    const bodies = vi.mocked(fetch).mock.calls.map(([, init]) => (typeof init?.body === "string" ? JSON.parse(init.body) : init?.body));
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "post /api/kit/console/calls/c%201/agent-notes",
      "post /api/kit/console/calls/c%2F2/agent-notes",
    ]);
    const tpl = "/api/kit/console/calls/{callId}/agent-notes";
    expect(calls.map((c) => template(c.url))).toEqual([tpl, tpl]);
    const op = kitApiDoc.paths[tpl].post as { operationId: string; requestBody: { content: { "application/json": { schema: { properties: Record<string, unknown>; required?: string[] } } } } };
    expect(op.operationId).toBe("tellAgent");
    expect(bodies).toEqual([{ text: "She is a returning patient.", sayNow: true }, { text: "Offer the 10 am slot.", sayNow: false }]);
    const schema = op.requestBody.content["application/json"].schema;
    for (const body of bodies) {
      expect(Object.keys(schema.properties)).toEqual(expect.arrayContaining(Object.keys(body)));
      expect(Object.keys(body)).toEqual(expect.arrayContaining(schema.required ?? []));
    }
    for (const c of calls) {
      expect(c.headers["x-xsrf-token"]).toBe("abc==");
      expect(c.headers["content-type"]).toBe("application/json");
    }
  });

  it("sets a campaign's link by a PUT with the body the document declares and stops it by a DELETE with none, echoing the XSRF-TOKEN cookie", async () => {
    const read = { added: { added: 2, alreadyThere: 1, skipped: 0, noCountryCode: 0 }, campaign: { id: "c 1" } };
    const setting = stubFetch(200, read);
    await expect(kitApi.setCampaignSource("c 1", "https://example.com/people.csv?a=1&b=2")).resolves.toEqual(read);
    const sentBody = vi.mocked(fetch).mock.calls[0][1]?.body;
    const stopping = stubFetch(204);
    await expect(kitApi.removeCampaignSource("c/2")).resolves.toBeUndefined();
    const calls = [...setting, ...stopping];
    const bodies = [sentBody, vi.mocked(fetch).mock.calls[0][1]?.body].map((body) => (typeof body === "string" ? JSON.parse(body) : body));
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "put /api/kit/console/campaigns/c%201/source",
      "delete /api/kit/console/campaigns/c%2F2/source",
    ]);
    const tpl = "/api/kit/console/campaigns/{campaignId}/source";
    expect(calls.map((c) => template(c.url))).toEqual([tpl, tpl]);
    const put = kitApiDoc.paths[tpl].put as { operationId: string; requestBody: { content: { "application/json": { schema: { properties: Record<string, unknown>; required?: string[] } } } } };
    const del = kitApiDoc.paths[tpl].delete as { operationId: string; requestBody?: unknown };
    expect([put.operationId, del.operationId]).toEqual(["setCampaignSource", "removeCampaignSource"]);
    expect(bodies).toEqual([{ url: "https://example.com/people.csv?a=1&b=2" }, undefined]);
    const schema = put.requestBody.content["application/json"].schema;
    expect(Object.keys(schema.properties)).toEqual(expect.arrayContaining(Object.keys(bodies[0])));
    expect(Object.keys(bodies[0])).toEqual(expect.arrayContaining(schema.required ?? []));
    expect(del.requestBody).toBeUndefined();
    expect(calls[0].headers["content-type"]).toBe("application/json");
    expect(calls[1].headers["content-type"]).toBeUndefined();
    for (const c of calls) expect(c.headers["x-xsrf-token"]).toBe("abc==");
  });

  it("reads call routing and changes it at the paths the document declares, with the bodies it declares, echoing the XSRF-TOKEN cookie", async () => {
    type Schema = { $ref?: string; allOf?: Schema[]; properties?: Record<string, unknown>; required?: string[] };
    const schemas = (kitApiDoc as unknown as { components: { schemas: Record<string, Schema> } }).components.schemas;
    /** A body schema's properties and required ones, through a $ref or an allOf. */
    const flat = (schema: Schema): { properties: string[]; required: string[] } => {
      if (schema.$ref) return flat(schemas[schema.$ref.split("/").pop()!]);
      const parts = (schema.allOf ?? []).map(flat);
      return {
        properties: [...Object.keys(schema.properties ?? {}), ...parts.flatMap((p) => p.properties)],
        required: [...(schema.required ?? []), ...parts.flatMap((p) => p.required)],
      };
    };
    const rule = { name: "After hours", enabled: true, numberIds: ["n1"], forwardedFrom: ["+14155550199"], callerStartsWith: ["+44"], days: [1, 5],
      from: "18:00", until: "09:00", timezone: "Europe/London", action: "answer" as const, agentId: null, language: "hi-IN", greeting: "Hello", maxMinutes: 30 };
    const calls = stubFetch(200, { rules: [] });
    await kitApi.routing();
    await kitApi.setNumberRouting("n 1", true);
    await kitApi.createRoutingRule(rule);
    await kitApi.updateRoutingRule("r/1", { enabled: false, action: "refuse" });
    await kitApi.orderRoutingRules(["r2", "r/1"]);
    await kitApi.tryRouting({ numberId: "n 1", callerNumber: "+447700900123", forwardedFrom: null, at: "2026-11-09T22:30:00.000Z" });
    await kitApi.checkRouting("n 1");
    const bodies = vi.mocked(fetch).mock.calls.map(([, init]) => (typeof init?.body === "string" ? JSON.parse(init.body) : init?.body));
    const deleting = stubFetch(204);
    await expect(kitApi.deleteRoutingRule("r/1")).resolves.toBeUndefined();
    const all = [...calls, ...deleting];
    expect(all.map((c) => `${c.method} ${c.url}`)).toEqual([
      "get /api/kit/console/routing",
      "put /api/kit/console/numbers/n%201/routing",
      "post /api/kit/console/routing/rules",
      "patch /api/kit/console/routing/rules/r%2F1",
      "put /api/kit/console/routing/order",
      "post /api/kit/console/routing/try",
      "post /api/kit/console/routing/check",
      "delete /api/kit/console/routing/rules/r%2F1",
    ]);
    const ops = all.map((c) => kitApiDoc.paths[template(c.url)!][c.method] as { operationId: string; requestBody?: { content: { "application/json": { schema: Schema } } } });
    expect(ops.map((o) => o.operationId)).toEqual(["getRouting", "setNumberRouting", "createRoutingRule", "updateRoutingRule", "orderRoutingRules",
      "tryRouting", "checkRouting", "deleteRoutingRule"]);
    expect(bodies).toEqual([
      undefined,
      { routed: true },
      rule,
      { enabled: false, action: "refuse" },
      { ids: ["r2", "r/1"] },
      { numberId: "n 1", callerNumber: "+447700900123", forwardedFrom: null, at: "2026-11-09T22:30:00.000Z" },
      { numberId: "n 1" },
    ]);
    for (const [i, body] of bodies.entries()) {
      if (body === undefined) continue;
      const schema = flat(ops[i].requestBody!.content["application/json"].schema);
      expect(schema.properties, ops[i].operationId).toEqual(expect.arrayContaining(Object.keys(body)));
      expect(Object.keys(body), ops[i].operationId).toEqual(expect.arrayContaining(schema.required));
    }
    expect(flat(ops[2].requestBody!.content["application/json"].schema).required).toEqual(["name", "action"]);
    expect([ops[0].requestBody, ops[7].requestBody]).toEqual([undefined, undefined]);
    expect(calls[0].url.includes("?")).toBe(false);
    expect(calls[0].headers["x-xsrf-token"]).toBeUndefined();
    for (const c of all.slice(1)) expect(c.headers["x-xsrf-token"]).toBe("abc==");
  });

  it("sets a number's caller id and no-agent choice, reads lost numbers and renewals, and gets one back, as the document declares", async () => {
    type Body = { content: { "application/json": { schema: { properties: Record<string, { enum?: string[] }>; required?: string[] } } } };
    const calls = stubFetch(200, { numbers: [] });
    await kitApi.setNumberCallerId("n 1", true);
    await kitApi.setNumberCallerId("n/2", false);
    await kitApi.setNumberUnanswered("n 1", "message");
    await kitApi.setNumberUnanswered("n 1", "busy");
    await kitApi.setNumberUnanswered("n 1", "unavailable");
    await kitApi.lostNumbers();
    await kitApi.getNumberBack("n 1");
    await kitApi.numberRenewals();
    const bodies = vi.mocked(fetch).mock.calls.map(([, init]) => (typeof init?.body === "string" ? JSON.parse(init.body) : init?.body));
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "put /api/kit/console/numbers/n%201/caller-id",
      "put /api/kit/console/numbers/n%2F2/caller-id",
      "put /api/kit/console/numbers/n%201/unanswered",
      "put /api/kit/console/numbers/n%201/unanswered",
      "put /api/kit/console/numbers/n%201/unanswered",
      "get /api/kit/console/numbers/lost",
      "post /api/kit/console/numbers/n%201/get-back",
      "get /api/kit/console/numbers/renewals",
    ]);
    const ops = calls.map((c) => kitApiDoc.paths[template(c.url)!][c.method] as { operationId: string; requestBody?: Body });
    expect(ops.map((o) => o.operationId)).toEqual(["setNumberCallerId", "setNumberCallerId", "setNumberUnanswered", "setNumberUnanswered",
      "setNumberUnanswered", "listLostNumbers", "getLostNumberBack", "getNumberRenewals"]);
    expect(bodies).toEqual([
      { agentCallerId: true }, { agentCallerId: false }, { unanswered: "message" }, { unanswered: "busy" }, { unanswered: "unavailable" },
      undefined, undefined, undefined,
    ]);
    for (const [i, body] of bodies.slice(0, 5).entries()) {
      const schema = ops[i].requestBody!.content["application/json"].schema;
      expect(Object.keys(schema.properties), ops[i].operationId).toEqual(expect.arrayContaining(Object.keys(body)));
      expect(Object.keys(body), ops[i].operationId).toEqual(expect.arrayContaining(schema.required ?? []));
    }
    expect(ops[2].requestBody!.content["application/json"].schema.properties.unanswered.enum).toEqual(["message", "busy", "unavailable"]);
    expect([ops[5].requestBody, ops[6].requestBody, ops[7].requestBody]).toEqual([undefined, undefined, undefined]);
    expect([5, 6, 7].map((i) => calls[i].headers["content-type"])).toEqual([undefined, undefined, undefined]);
    for (const i of [5, 7]) {
      expect(calls[i].url.includes("?")).toBe(false);
      expect(calls[i].headers["x-xsrf-token"]).toBeUndefined();
    }
    for (const i of [0, 1, 2, 3, 4, 6]) expect(calls[i].headers["x-xsrf-token"]).toBe("abc==");
  });

  it("reads platform billing, sets its low-balance level (or none) and asks for one customer's costs, as the document declares", async () => {
    type Body = { content: { "application/json": { schema: { properties: Record<string, { pattern?: string; nullable?: boolean }>; required?: string[] } } } };
    const state = { state: null, checkedAt: null };
    const calls = stubFetch(200, state);
    await kitApi.platformBilling();
    await expect(kitApi.setPlatformBalanceAlert("25.50")).resolves.toEqual(state);
    await kitApi.setPlatformBalanceAlert(null);
    await kitApi.costReport("component", "2026-10-01", "2026-10-31", 1, "00000000-0000-4000-8000-00000000000a");
    await kitApi.costReport("customer", "2026-10-01", "2026-10-31", 2);
    const bodies = vi.mocked(fetch).mock.calls.map(([, init]) => (typeof init?.body === "string" ? JSON.parse(init.body) : init?.body));
    expect(calls.map((c) => `${c.method} ${c.url.split("?")[0]}`)).toEqual([
      "get /api/kit/admin/platform-billing",
      "put /api/kit/admin/platform-billing/alert",
      "put /api/kit/admin/platform-billing/alert",
      "get /api/kit/admin/costs",
      "get /api/kit/admin/costs",
    ]);
    const ops = calls.map((c) => kitApiDoc.paths[template(c.url.split("?")[0])!][c.method] as { operationId: string; requestBody?: Body });
    expect(ops.map((o) => o.operationId)).toEqual(["getPlatformBilling", "setPlatformBalanceAlert", "setPlatformBalanceAlert", "getCostReport", "getCostReport"]);
    expect(bodies).toEqual([undefined, { thresholdUsd: "25.50" }, { thresholdUsd: null }, undefined, undefined]);
    const schema = ops[1].requestBody!.content["application/json"].schema;
    for (const body of bodies.slice(1, 3)) {
      expect(Object.keys(schema.properties)).toEqual(expect.arrayContaining(Object.keys(body)));
      expect(Object.keys(body)).toEqual(expect.arrayContaining(schema.required ?? []));
    }
    expect(new RegExp(schema.properties.thresholdUsd.pattern!).test("25.50")).toBe(true);
    expect(schema.properties.thresholdUsd.nullable).toBe(true);
    expect(calls[0].url.includes("?")).toBe(false);
    expect([...new URLSearchParams(calls[3].url.split("?")[1]).entries()]).toEqual([
      ["by", "component"], ["from", "2026-10-01"], ["to", "2026-10-31"], ["customer", "00000000-0000-4000-8000-00000000000a"],
    ]);
    expect([...new URLSearchParams(calls[4].url.split("?")[1]).keys()]).toEqual(["by", "from", "to", "page"]);
    expect(declaredQuery("/api/kit/admin/costs", "get")).toEqual(expect.arrayContaining(["by", "from", "to", "page", "customer"]));
    for (const i of [0, 3, 4]) expect(calls[i].headers["x-xsrf-token"]).toBeUndefined();
    for (const i of [1, 2]) {
      expect(calls[i].headers["x-xsrf-token"]).toBe("abc==");
      expect(calls[i].headers["content-type"]).toBe("application/json");
    }
  });

  it("reads invoices, billing details and invoice settings, and saves the details and settings with every field, as the document declares", async () => {
    type Body = { content: { "application/json": { schema: { $ref?: string } } } };
    const calls = stubFetch(200, { name: null, address: null, taxNumber: null, canEdit: true });
    await kitApi.accountInvoices();
    await kitApi.accountInvoice("a/1");
    await kitApi.billingDetails();
    await kitApi.setBillingDetails({ name: "Acme Dental Clinics LLP", address: "MG Road\nPune", taxNumber: null, canEdit: true } as never);
    await kitApi.invoiceSettings();
    // The settings as answered (with the server's sequenceDigits) sent back: only the seven settings go.
    await kitApi.setInvoiceSettings({ prefix: "AV/", name: null, address: "12 Residency Road\nBengaluru 560025", taxNumber: "29ABCDE1234F1Z5", email: null,
      taxName: "GST", taxRate: "18", sequenceDigits: 6 } as never);
    await kitApi.customerInvoices("w 1");
    await kitApi.customerInvoice("i?1");
    const bodies = vi.mocked(fetch).mock.calls.map(([, init]) => (typeof init?.body === "string" ? JSON.parse(init.body) : init?.body));
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "get /api/kit/console/invoices",
      "get /api/kit/console/invoices/a%2F1",
      "get /api/kit/console/billing-details",
      "put /api/kit/console/billing-details",
      "get /api/kit/admin/invoice-settings",
      "put /api/kit/admin/invoice-settings",
      "get /api/kit/admin/customers/w%201/invoices",
      "get /api/kit/admin/invoices/i%3F1",
    ]);
    const ops = calls.map((c) => kitApiDoc.paths[template(c.url)!][c.method] as { operationId: string; requestBody?: Body });
    expect(ops.map((o) => o.operationId)).toEqual([
      "listAccountInvoices", "getAccountInvoice", "getBillingDetails", "setBillingDetails",
      "getInvoiceSettings", "setInvoiceSettings", "listCustomerInvoices", "getCustomerInvoice",
    ]);
    expect(bodies).toEqual([
      undefined, undefined, undefined,
      { name: "Acme Dental Clinics LLP", address: "MG Road\nPune", taxNumber: null },
      undefined,
      { prefix: "AV/", name: null, address: "12 Residency Road\nBengaluru 560025", taxNumber: "29ABCDE1234F1Z5", email: null, taxName: "GST", taxRate: "18" },
      undefined, undefined,
    ]);
    const schemas = (kitApiDoc as unknown as { components: { schemas: Record<string, { properties: Record<string, unknown>; required: string[] }> } }).components.schemas;
    for (const [i, name] of [[3, "BillingDetails"], [5, "InvoiceSettings"]] as const) {
      expect(ops[i].requestBody!.content["application/json"].schema.$ref).toBe(`#/components/schemas/${name}`);
      expect(Object.keys(bodies[i]).sort(), `${name}: every property, and only those`).toEqual(Object.keys(schemas[name].properties).sort());
      expect(Object.keys(bodies[i])).toEqual(expect.arrayContaining(schemas[name].required));
      expect(calls[i].headers["x-xsrf-token"]).toBe("abc==");
      expect(calls[i].headers["content-type"]).toBe("application/json");
    }
    expect(Object.keys(schemas.InvoiceSettings.properties), "sequenceDigits is answered, never sent").not.toContain("sequenceDigits");
    for (const i of [0, 1, 2, 4, 6, 7]) {
      expect(calls[i].headers["x-xsrf-token"]).toBeUndefined();
      expect(ops[i].requestBody).toBeUndefined();
    }
    for (const i of [1, 2, 3, 4, 5, 7]) expect(declaredQuery(template(calls[i].url)!, calls[i].method)).toEqual([]);
  });

  it("asks for a page of invoices by the one query parameter the document declares, only past the first", async () => {
    const calls = stubFetch(200, { invoices: [], page: 1, perPage: 50, total: 0, billingDetails: { name: null, address: null, taxNumber: null }, canEdit: false });
    await kitApi.accountInvoices(1);
    await kitApi.accountInvoices(3);
    await kitApi.customerInvoices("w 1", 1);
    await kitApi.customerInvoices("w 1", 2);
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "get /api/kit/console/invoices",
      "get /api/kit/console/invoices?page=3",
      "get /api/kit/admin/customers/w%201/invoices",
      "get /api/kit/admin/customers/w%201/invoices?page=2",
    ]);
    for (const c of calls) {
      const tpl = template(c.url.split("?")[0])!;
      expect(declaredQuery(tpl, "get"), tpl).toEqual(["page"]);
      expect(c.headers["x-xsrf-token"]).toBeUndefined();
    }
    const listed = ["/api/kit/console/invoices", "/api/kit/admin/customers/{id}/invoices"].map((tpl) =>
      (kitApiDoc.paths[tpl].get as { responses: { 200: { content: { "application/json": { schema: { allOf: { $ref?: string }[] } } } } } })
        .responses[200].content["application/json"].schema.allOf[0].$ref);
    expect(listed, "both answer a page of invoices").toEqual(["#/components/schemas/InvoicePage", "#/components/schemas/InvoicePage"]);
  });

  it("reads the invoice settings with the server's sequenceDigits, as the document declares it answers them", async () => {
    const answer = { prefix: "INV-", name: null, address: null, taxNumber: null, email: null, taxName: null, taxRate: null, sequenceDigits: 6 };
    stubFetch(200, answer);
    await expect(kitApi.invoiceSettings()).resolves.toEqual(answer);
    await expect(kitApi.setInvoiceSettings(answer)).resolves.toEqual(answer);
    type Answered = { responses: { 200: { content: { "application/json": { schema: { $ref: string } } } } } };
    const ops = kitApiDoc.paths["/api/kit/admin/invoice-settings"] as Record<string, Answered>;
    expect(["get", "put"].map((m) => ops[m].responses[200].content["application/json"].schema.$ref)).toEqual([
      "#/components/schemas/InvoiceSettingsAnswer", "#/components/schemas/InvoiceSettingsAnswer",
    ]);
    const schema = (kitApiDoc as unknown as { components: { schemas: Record<string, { allOf: { required?: string[]; properties?: Record<string, { type: string }> }[] }> } })
      .components.schemas.InvoiceSettingsAnswer;
    expect(schema.allOf[1].required).toEqual(["sequenceDigits"]);
    expect(schema.allOf[1].properties!.sequenceDigits.type).toBe("integer");
  });

  it("echoes the XSRF-TOKEN cookie on a write, and not on a read", async () => {
    const calls = stubFetch();
    await kitApi.submitStep("brand", {});
    await kitApi.health();
    expect(calls[0].headers["x-xsrf-token"]).toBe("abc==");
    expect(calls[1].headers["x-xsrf-token"]).toBeUndefined();
  });

  it("turns a refusal into an error with the backend's message and refused fields", async () => {
    stubFetch(422, { message: "The name is required.", errors: { name: ["The name is required."] } });
    const err = await kitApi.submitStep("brand", {}).catch((e: unknown) => e);
    expect(err).toBeInstanceOf(KitApiError);
    expect((err as KitApiError).status).toBe(422);
    expect((err as KitApiError).message).toBe("The name is required.");
    expect((err as KitApiError).fields).toEqual({ name: ["The name is required."] });
  });

  it("names the path and status when the backend gives no message", async () => {
    stubFetch(503, null);
    await expect(kitApi.health()).rejects.toThrow("/api/kit/health answered 503");
  });

  it("answers nothing for a 204", async () => {
    stubFetch(204);
    await expect(kitApi.signOut()).resolves.toBeUndefined();
  });

  it("reads a starter's preview by the one query parameter the document declares, encoded, without the XSRF-TOKEN cookie", async () => {
    const calls = stubFetch(200, { agent: { id: "a 1", name: "Front desk", account: "Demo" }, texts: [], leftOut: [], steps: 0 });
    await kitApi.previewStarterAgent("a 1&x=y");
    const [path, q] = calls[0].url.split("?");
    expect(calls[0].method).toBe("get");
    expect(template(path)).toBe("/api/kit/admin/starters/preview");
    expect((kitApiDoc.paths["/api/kit/admin/starters/preview"].get as { operationId: string }).operationId).toBe("previewStarterAgent");
    expect([...new URLSearchParams(q).entries()]).toEqual([["agentId", "a 1&x=y"]]);
    expect(declaredQuery("/api/kit/admin/starters/preview", "get")).toEqual(["agentId"]);
    expect(calls[0].headers["x-xsrf-token"]).toBeUndefined();
  });

  it("publishes, changes and starts from a starter with the bodies the document declares, echoing the XSRF-TOKEN cookie", async () => {
    const calls = stubFetch(201, { agent: { id: "a2" }, toRedo: [] });
    await kitApi.publishStarterAgent({ agentId: "a1", name: "Front desk", description: "Books visits", reviewed: true });
    await kitApi.updateStarterAgent("s 1", { name: "Desk", description: null, offered: false });
    await kitApi.createAgentFromStarter("s/1");
    const bodies = vi.mocked(fetch).mock.calls.map(([, init]) => (typeof init?.body === "string" ? JSON.parse(init.body) : init?.body));
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "post /api/kit/admin/starters",
      "patch /api/kit/admin/starters/s%201",
      "post /api/kit/console/starters/s%2F1/agents",
    ]);
    const ops = calls.map((c) => kitApiDoc.paths[template(c.url)!][c.method] as { operationId: string; requestBody?: { content: { "application/json": { schema: { properties: Record<string, unknown>; required?: string[] } } } } });
    expect(ops.map((o) => o.operationId)).toEqual(["publishStarterAgent", "updateStarterAgent", "createAgentFromStarter"]);
    expect(bodies).toEqual([
      { agentId: "a1", name: "Front desk", description: "Books visits", reviewed: true },
      { name: "Desk", description: null, offered: false },
      undefined,
    ]);
    for (const [i, body] of bodies.slice(0, 2).entries()) {
      const schema = ops[i].requestBody!.content["application/json"].schema;
      expect(Object.keys(schema.properties)).toEqual(expect.arrayContaining(Object.keys(body)));
      expect(Object.keys(body)).toEqual(expect.arrayContaining(schema.required ?? []));
    }
    expect(ops[2].requestBody).toBeUndefined();
    expect(calls[2].headers["content-type"]).toBeUndefined();
    for (const c of calls) expect(c.headers["x-xsrf-token"]).toBe("abc==");
  });

  it("asks for the provider's own models and a price by the query parameters the document declares, ids encoded whole", async () => {
    const calls = stubFetch(200, { provider: "openrouter", models: [], page: 1, perPage: 20, total: 0, max: 20 });
    await kitApi.openModels();
    await kitApi.openModels("gpt 4o", 1);
    await kitApi.openModels("", 3);
    await kitApi.openModels("llama", 2);
    await kitApi.openModelPrice("meta-llama/llama-3.1-8b:free");
    await kitApi.openModelPrice("~anthropic/claude.v2");
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "get /api/kit/admin/catalog/open-models",
      "get /api/kit/admin/catalog/open-models?search=gpt+4o",
      "get /api/kit/admin/catalog/open-models?page=3",
      "get /api/kit/admin/catalog/open-models?search=llama&page=2",
      "get /api/kit/admin/catalog/open-models/price?model=meta-llama%2Fllama-3.1-8b%3Afree",
      "get /api/kit/admin/catalog/open-models/price?model=%7Eanthropic%2Fclaude.v2",
    ]);
    const ops = calls.map((c) => kitApiDoc.paths[template(c.url.split("?")[0])!][c.method] as { operationId: string });
    expect(ops.map((o) => o.operationId)).toEqual(["listOpenModels", "listOpenModels", "listOpenModels", "listOpenModels", "getOpenModelPrice", "getOpenModelPrice"]);
    expect([...new URLSearchParams(calls[4].url.split("?")[1]).entries()]).toEqual([["model", "meta-llama/llama-3.1-8b:free"]]);
    expect([...new URLSearchParams(calls[5].url.split("?")[1]).entries()]).toEqual([["model", "~anthropic/claude.v2"]]);
    expect(declaredQuery("/api/kit/admin/catalog/open-models", "get")).toEqual(["search", "page"]);
    expect(declaredQuery("/api/kit/admin/catalog/open-models/price", "get")).toEqual(["model"]);
    for (const c of calls) expect(c.headers["x-xsrf-token"]).toBeUndefined();

    type Props = Record<string, { properties?: Record<string, unknown>; additionalProperties?: Props[string] }>;
    const put = kitApiDoc.paths["/api/kit/admin/catalog"].put as { requestBody: { content: { "application/json": { schema: { properties: Props } } } } };
    const choice = put.requestBody.content["application/json"].schema.properties.kinds.additionalProperties!.additionalProperties!;
    expect(Object.keys(choice.properties!), "a provider's choice may carry its added models").toContain("extraModels");
  });

  it("reads the operator's and a customer's processing country, with fresh only when asked", async () => {
    const calls = stubFetch(200, { country: "IN", source: "country", checkedAt: "2026-11-09T10:00:00Z" });
    await expect(kitApi.platformProcessingCountry()).resolves.toEqual({ country: "IN", source: "country", checkedAt: "2026-11-09T10:00:00Z" });
    await kitApi.platformProcessingCountry(true);
    await kitApi.customerProcessingCountry("w 1");
    await kitApi.customerProcessingCountry("w/1", true);
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "get /api/kit/admin/telenow/processing-country",
      "get /api/kit/admin/telenow/processing-country?fresh=1",
      "get /api/kit/admin/customers/w%201/processing-country",
      "get /api/kit/admin/customers/w%2F1/processing-country?fresh=1",
    ]);
    const ops = calls.map((c) => kitApiDoc.paths[template(c.url.split("?")[0])!][c.method] as { operationId: string });
    expect(ops.map((o) => o.operationId)).toEqual(["getPlatformProcessingCountry", "getPlatformProcessingCountry", "getCustomerProcessingCountry", "getCustomerProcessingCountry"]);
    expect(declaredQuery("/api/kit/admin/telenow/processing-country", "get")).toEqual(["fresh"]);
    expect(declaredQuery("/api/kit/admin/customers/{id}/processing-country", "get")).toEqual(["fresh"]);
    for (const c of calls) expect(c.headers["x-xsrf-token"]).toBeUndefined();
  });

  it("checks a prompt with the body the document declares, sends a logo as multipart `file`, and asks for call health by `minutes` only", async () => {
    type Schema = { $ref?: string; properties?: Record<string, { enum?: string[] }>; required?: string[] };
    const schemas = (kitApiDoc as unknown as { components: { schemas: Record<string, Schema> } }).components.schemas;
    const calls = stubFetch(200, { checked: true, valid: true, errors: [], warnings: [], preview: null });
    const logo = new File(["\x89PNG"], "logo.png", { type: "image/png" });
    await kitApi.checkPrompt("a/1", { systemPrompt: "Hi {name}", greeting: "Hello", values: { name: "Ana" }, channel: "phone_outbound" });
    await kitApi.checkNewPrompt({ systemPrompt: null, values: {} });
    await kitApi.setAgentLogo("a/1", logo);
    await kitApi.removeAgentLogo("a/1");
    await kitApi.callHealth();
    await kitApi.callHealth(1440);
    await kitApi.customerCallHealth("w 1");
    await kitApi.customerCallHealth("w/1", 360);
    const sentBodies = vi.mocked(fetch).mock.calls.map(([, init]) => init?.body);
    expect(calls.map((c) => `${c.method} ${c.url}`)).toEqual([
      "post /api/kit/console/agents/a%2F1/prompt-check",
      "post /api/kit/console/agents/prompt-check",
      "post /api/kit/console/agents/a%2F1/logo",
      "delete /api/kit/console/agents/a%2F1/logo",
      "get /api/kit/console/call-health",
      "get /api/kit/console/call-health?minutes=1440",
      "get /api/kit/admin/customers/w%201/call-health",
      "get /api/kit/admin/customers/w%2F1/call-health?minutes=360",
    ]);
    const ops = calls.map((c) => kitApiDoc.paths[template(c.url.split("?")[0])!][c.method] as { operationId: string });
    expect(ops.map((o) => o.operationId)).toEqual(["checkPrompt", "checkNewPrompt", "setAgentLogo", "removeAgentLogo", "getCallHealth", "getCallHealth",
      "getCustomerCallHealth", "getCustomerCallHealth"]);

    const input = schemas.PromptCheckInput;
    const bodies = sentBodies.slice(0, 2).map((b) => JSON.parse(b as string) as Record<string, unknown>);
    expect(bodies).toEqual([
      { systemPrompt: "Hi {name}", greeting: "Hello", values: { name: "Ana" }, channel: "phone_outbound" },
      { systemPrompt: null, values: {} },
    ]);
    for (const body of bodies) {
      expect(Object.keys(input.properties!)).toEqual(expect.arrayContaining(Object.keys(body)));
      expect(Object.keys(body)).toEqual(expect.arrayContaining(input.required!));
    }
    expect(input.properties!.channel.enum).toContain("phone_outbound");
    for (const c of calls.slice(0, 2)) expect(c.headers["content-type"]).toBe("application/json");

    const form = sentBodies[2] as FormData;
    expect(form).toBeInstanceOf(FormData);
    expect([...form.keys()]).toEqual(["file"]);
    expect((form.get("file") as File).name).toBe("logo.png");
    expect(calls[2].headers["content-type"], "the browser sets the multipart boundary").toBeUndefined();
    const upload = (kitApiDoc.paths["/api/kit/console/agents/{agentId}/logo"].post as { requestBody: { content: Record<string, { schema: Schema }> } }).requestBody.content;
    expect(Object.keys(upload)).toEqual(["multipart/form-data"]);
    expect(upload["multipart/form-data"].schema.required).toEqual(["file"]);
    expect(sentBodies[3]).toBeUndefined();
    for (const c of calls.slice(0, 4)) expect(c.headers["x-xsrf-token"]).toBe("abc==");

    expect(declaredQuery("/api/kit/console/call-health", "get")).toEqual(["minutes"]);
    expect(declaredQuery("/api/kit/admin/customers/{id}/call-health", "get")).toEqual(["minutes"]);
    for (const c of calls.slice(4)) {
      expect([...new URLSearchParams(c.url.split("?")[1] ?? "").keys()].every((k) => k === "minutes")).toBe(true);
      expect(c.headers["x-xsrf-token"]).toBeUndefined();
    }
  });
});
