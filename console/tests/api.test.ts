// @vitest-environment node
/**
 * api.ts against contract/kit-api.yaml: every call the console makes is an operation the kit API document
 * declares (so every backend that passes contract/tests can serve it); writes echo the XSRF-TOKEN cookie; a
 * refusal is a KitApiError with the backend's message and, for a 422, its refused fields.
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { afterEach, describe, expect, it, vi } from "vitest";
import { parse } from "yaml";
import { KitApiError, callRecordingUrl, kitApi } from "../src/api";

const kitApiDoc = parse(readFileSync(fileURLToPath(new URL("../../contract/kit-api.yaml", import.meta.url)), "utf8")) as {
  paths: Record<string, Record<string, unknown>>;
};

/** The document's path template a concrete path matches (`/api/kit/setup/steps/{step}` for `.../steps/brand`). */
function template(path: string): string | undefined {
  return Object.keys(kitApiDoc.paths).find((tpl) =>
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
  createAgent: () => kitApi.createAgent({ name: "Front desk" }),
  agent: () => kitApi.agent("00000000-0000-4000-8000-000000000000"),
  updateAgent: () => kitApi.updateAgent("00000000-0000-4000-8000-000000000000", { isActive: false }),
  deleteAgent: () => kitApi.deleteAgent("00000000-0000-4000-8000-000000000000"),
  retryAgent: () => kitApi.retryAgent("00000000-0000-4000-8000-000000000000"),
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
  setAgentKnowledge: () => kitApi.setAgentKnowledge("00000000-0000-4000-8000-000000000000", []),
  numbers: () => kitApi.numbers(),
  availableNumbers: () => kitApi.availableNumbers("IN", "8045"),
  buyNumber: () => kitApi.buyNumber("+918045671001", "IN"),
  setNumberAgent: () => kitApi.setNumberAgent("00000000-0000-4000-8000-000000000000", null),
  releaseNumber: () => kitApi.releaseNumber("00000000-0000-4000-8000-000000000000"),
  customerWallet: () => kitApi.customerWallet("00000000-0000-4000-8000-000000000000", 2),
  addCustomerCredit: () => kitApi.addCustomerCredit("00000000-0000-4000-8000-000000000000", { amount: "5", note: null, reference: "00000000-0000-4000-8000-000000000000" }),
  setCustomerPlan: () => kitApi.setCustomerPlan("00000000-0000-4000-8000-000000000000", "standard"),
  pricing: () => kitApi.pricing(),
  setLowBalanceDefault: () => kitApi.setLowBalanceDefault(null),
  updatePricing: () => kitApi.updatePricing({ currency: "INR", usdRate: "83", defaultPlan: null }),
  savePriceList: () => kitApi.savePriceList("standard", "Standard", {}),
  deletePriceList: () => kitApi.deletePriceList("standard"),
  calls: () => kitApi.calls(2, { agentId: "00000000-0000-4000-8000-000000000000", number: "+14155550101", placedBy: "me" }),
  call: () => kitApi.call("00000000-0000-4000-8000-000000000000"),
  liveCalls: () => kitApi.liveCalls(),
  agentCallForm: () => kitApi.agentCallForm("00000000-0000-4000-8000-000000000000"),
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
  receivePaymentEvent: "a payment gateway calls it, not the console",
  returnFromGateway: "a payment gateway sends the payer's browser there",
  returnFromGatewayByPost: "a payment gateway sends the payer's browser there, with a form",
  startSso: "the browser goes there to sign in (ssoStartUrl), a page load",
  finishSso: "a sign-in provider sends the browser there",
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
});
