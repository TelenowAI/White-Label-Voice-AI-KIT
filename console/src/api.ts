/**
 * The console's client for the kit API (contract/kit-api.yaml). The console talks to nothing else: never to
 * Telenow, and it never holds a Telenow key. Same origin in a release; proxied in development (vite.config.ts).
 *
 * Signing in uses the backend's session cookie; every write echoes the backend's XSRF-TOKEN cookie in an
 * X-XSRF-TOKEN header (contract/kit-api.yaml). A refusal is a KitApiError carrying the backend's message and, for
 * a 422, the refused fields.
 *
 * The types mirror kit-api.yaml's schemas; contract/tests holds every backend to that document.
 */

export interface Health {
  status: "ok";
  installed: boolean;
  version: string;
}

export interface Brand {
  name: string;
  logoUrl: string | null;
  supportEmail: string | null;
  /** CSS colour values by role; the set of roles is the operator's. */
  colors: Record<string, string>;
  /** Whether sign-in pages offer "Forgot your password?" (only when the platform can send email). */
  passwordReset: boolean;
  /** Whether people may sign up on their own now. */
  signUp: boolean;
  /** Whether customers may sign in with a password (staff always do). */
  signInPassword: boolean;
  /** Whether customers may sign in with an emailed link. */
  signInLink: boolean;
  /** Whether customers may sign in with an emailed code. */
  signInEmailCode: boolean;
  /** Whether customers may sign in with a code sent to their proven phone number. */
  signInCode: boolean;
  /** How codes reach a phone: by text, by WhatsApp, either (the operator lined up both); null when texts are off. */
  signInCodeChannel: "sms" | "whatsapp" | "mixed" | null;
  /** The OpenID Connect providers customers may sign in with (ssoStartUrl), in order. */
  signInWith: { id: string; name: string }[];
  /** The bot check the public forms show, or null when none. */
  captcha: BrandCaptcha | null;
}

/** A bot check widget: its script, the browser global whose render() draws it, the site key, and the forms it guards. */
export interface BrandCaptcha {
  provider: string;
  script: string;
  global: string;
  siteKey: string | null;
  forms: CaptchaForm[];
}

export type CaptchaForm = "sign_up" | "sign_in" | "password_reset" | "sign_in_link" | "sign_in_email_code" | "sign_in_code";

/** Admin → Bot check. The secret is never answered. */
export interface CaptchaSettings {
  provider: string | null;
  siteKey: string | null;
  secretSet: boolean;
  forms: string[];
  providers: Option[];
  formChoices: Option[];
}

/** Charges added up in one currency (Admin → Dashboard, Cost vs price): exact decimal strings. */
export interface MoneyTotals {
  currency: string;
  count: number;
  price: string;
  cost: string;
  costUsd: string;
  margin: string;
  marginPercent: string | null;
}

export interface ReportPeriod {
  from: string;
  to: string;
}

/** Admin → Dashboard. `telenow.account` is the operator's account as Telenow last gave it. */
export interface AdminDashboard {
  period: ReportPeriod;
  money: MoneyTotals[];
  topUps: { currency: string; count: number; amount: string; refunded: string }[];
  creditsByHand: { currency: string; count: number; amount: string }[];
  customers: { total: number; active: number; suspended: number; notReady: number; outOfCredit: number | null; new: number };
  calls: { count: number; seconds: number; live: number };
  telenow: {
    name: string;
    billingUrl: string;
    connected: boolean;
    keyRejected: boolean;
    error: string | null;
    checkedAt: string | null;
    account: {
      mode?: string | null;
      state?: string | null;
      canSpend?: boolean | null;
      stoppedBy?: string | null;
      balanceUsd?: string | null;
      monthlySpendLimitUsd?: string | null;
      periodSpendUsd?: string | null;
      periodStart?: string | null;
      headroomUsd?: string | null;
      lowThresholdUsd?: string | null;
    } | null;
  };
  attention: {
    callsNotCharged: number;
    failedWrites: number;
    paymentsToReview: number;
    customersNotSynced: number;
    eventsFailed: number;
    customersDrifting: number;
    jobsNotRunning: boolean;
    /** Providers that failed on as many of customers' recent calls as the alert level, or more, at call health's last sweep. */
    providersFailing: number;
  };
}

/** Admin → Staff: the team, its pending invites, and the roles with what each may do. */
export interface StaffList {
  members: { id: number; name: string; email: string; role: string; you: boolean }[];
  invites: StaffInvite[];
  roles: { value: string; label: string; about: string }[];
}

export interface StaffInvite {
  id: string;
  email: string;
  role: string;
  expiresAt: string;
  createdAt: string;
}

export interface StaffInviteInfo {
  email: string;
  role: string;
  roleLabel: string;
  expiresAt: string;
  accountExists: boolean;
}

/** Admin → Audit log: a page of it, newest first. */
export interface AuditPage {
  entries: {
    id: number;
    at: string;
    by: { id: number; name: string; email: string } | null;
    action: string;
    label: string;
    subject: { type: string; id: string; label: string | null } | null;
    details: Record<string, unknown>;
  }[];
  page: number;
  perPage: number;
  total: number;
}

/** Admin → Voice platform: how the link to the voice platform is doing. */
export interface VoicePlatformHealth {
  telenow: { name: string; connected: boolean; account: string | null; keyRejected: boolean; error: string | null; checkedAt: string | null };
  /** The providers that failed on customers' recent calls, added up by the last sweep; null before its first run. */
  callHealth: PlatformCallHealth | null;
  events: {
    registered: boolean;
    url: string | null;
    registeredAt: string | null;
    checkedAt: string | null;
    error: string | null;
    lastReceivedAt: string | null;
    recentHours: number;
    recent: number;
    waiting: number;
    failed: number;
    failures: { id: number; event: string; error: string | null; failedAt: string | null; attempts: number }[];
  };
  writes: {
    waiting: number;
    failed: number;
    failures: { id: number; resource: string; kitId: string; operation: string; error: string | null; failedAt: string | null; attempts: number }[];
  };
  jobs: {
    lastStartedAt: string | null;
    running: boolean;
    jobs: { command: string; expression: string; startedAt: string | null; finishedAt: string | null; succeeded: boolean | null; late: boolean }[];
  };
  reconciliation: {
    everyMinutes: number;
    toleranceUsd: string;
    checked: number;
    oldestCheckAt: string | null;
    drifting: number;
    customers: {
      customerId: string;
      name: string;
      telenowSpendUsd: string | null;
      chargedCostUsd: string | null;
      driftUsd: string | null;
      periodFrom: string | null;
      telenowPeriodCalls: number | null;
      kitPeriodCalls: number | null;
      unchargedCalls: number | null;
      checkedAt: string | null;
      error: string | null;
    }[];
  };
}

/** Call health's last sweep over every customer with calls in the window (VoicePlatformHealth `callHealth`). Admin only. */
export interface PlatformCallHealth {
  checkedAt: string;
  windowMinutes: number;
  /** Accounts with calls in the window, those read, and those whose failures could not be read. */
  accountsActive: number;
  accountsChecked: number;
  accountsUnread: number;
  /** A provider with this many calls failed or more is flagged on the dashboard. */
  alertCalls: number;
  providers: {
    part: CallHealthPart;
    providerId: string;
    provider: string;
    calls: number;
    customerCount: number;
    /** The customers with the most such calls first (at most so many). */
    customers: { id: string; name: string; calls: number }[];
    lastAt: string | null;
  }[];
}

/** Admin → Cost vs price: one page of groups, and the totals of the whole period; of every customer, or of the one in `customer`. */
export interface CostReport {
  period: ReportPeriod;
  by: string;
  customer: { id: string; name: string } | null;
  groups: Option[];
  rows: (MoneyTotals & { key: string; label: string })[];
  totals: MoneyTotals[];
  page: number;
  perPage: number;
  total: number;
}

/**
 * Admin → Platform billing: the voice platform's spend state for the operator's own account, as last read. Money is
 * USD as decimal strings. The document requires none of its fields, so each may be missing.
 */
export interface PlatformSpendState {
  state?: "ok" | "low" | "stopped";
  canSpend?: boolean;
  stoppedBy?: "suspended" | "out_of_credit" | "spend_cap" | null;
  mode?: "prepaid" | "postpaid";
  balanceUsd?: string;
  periodStart?: string;
  periodSpendUsd?: string;
  monthlySpendLimitUsd?: string | null;
  headroomUsd?: string | null;
  lowThresholdUsd?: string | null;
}

/** The spend state as last read (null before the first read) and when (setPlatformBalanceAlert answers this). */
export interface PlatformBillingState {
  state: PlatformSpendState | null;
  checkedAt: string | null;
}

/** One of the voice platform's invoices to the operator: its lines are net, `rest` is what the total holds beyond them (tax). */
export interface PlatformInvoice {
  id: string;
  periodStart: string | null;
  periodEnd: string | null;
  status: "draft" | "issued" | "paid" | "overdue" | "void" | "other";
  issuedAt: string | null;
  dueAt: string | null;
  paidAt: string | null;
  currency: string;
  total: string | null;
  totalUsd: string | null;
  lines: { kind: string; description: string; amount: string | null }[];
  rest: string | null;
}

/** Admin → Platform billing: the state, and the invoices newest first (null with `invoicesError` when they could not be read). */
export interface PlatformBilling extends PlatformBillingState {
  invoices: PlatformInvoice[] | null;
  invoicesError: string | null;
}

/** The public website's content (Admin → Website). Texts may hold `{brand}`, `{email}` and `{site}`. */
export interface WebsiteContent {
  tagline: string | null;
  metaDescription: string | null;
  heroTitle: string;
  heroText: string | null;
  heroImage: string | null;
  ctaTitle: string | null;
  ctaText: string | null;
  sections: { id: string; on: boolean }[];
  features: { title: string; text: string | null }[];
  pricingIntro: string | null;
  plans: { id: string; headline: string | null; note: string | null }[];
  contactText: string | null;
  legal: Record<string, string | null>;
}

/** Admin → Website: the content with the choices for each part. */
export interface WebsiteSettings {
  content: WebsiteContent;
  siteUrl: string;
  childTheme: string | null;
  maxFeatures: number;
  maxPlans: number;
  textMaxLength: number;
  longTextMaxLength: number;
  sectionChoices: Option[];
  legalPages: Option[];
  planChoices: Option[];
}

/** Admin → Sign-in providers. A client secret is never answered. */
export interface SsoSettings {
  redirectUri: string;
  maxProviders: number;
  presets: { value: string; label: string; issuer: string; help: string | null }[];
  providers: { id: string; preset: string | null; name: string; issuer: string; clientId: string; secretSet: boolean; enabled: boolean }[];
}

export interface SsoProviderInput {
  preset: string | null;
  issuer?: string | null;
  name: string;
  clientId: string;
  clientSecret?: string | null;
  enabled?: boolean;
}

export interface SetupField {
  name: string;
  label: string;
  /** A checkbox sends "1" ticked, "" unticked. */
  type: "text" | "email" | "password" | "secret" | "url" | "color" | "select" | "checkbox" | "textarea" | "tel" | "number";
  required: boolean;
  value: string | null;
  help: string | null;
  options: { value: string; label: string }[];
  showWhen: { field: string; values: string[] } | null;
  secretSet: boolean;
}

export interface SetupCheck {
  id: string;
  label: string;
  level: "ok" | "warning" | "blocking";
  detail: string | null;
}

export interface SetupStep {
  id: string;
  title: string;
  description: string;
  status: "done" | "todo" | "blocked";
  access: "anyone" | "owner";
  submittable: boolean;
  fields: SetupField[];
  checks: SetupCheck[];
  facts: { label: string; value: string }[];
}

export interface SetupState {
  installed: boolean;
  ownerExists: boolean;
  steps: SetupStep[];
}

export interface User {
  id: number;
  name: string;
  /** Null for a customer who signed up with their phone. */
  email: string | null;
  role: string | null;
  /** What this staff role may do in the admin panel; empty for a customer. */
  abilities?: string[];
}

/** Staff looking at a customer's console, read-only, until `until` (getSession). */
export interface ViewingAs {
  customerId: string;
  name: string;
  until: string;
}

export interface Customer {
  id: string;
  name: string;
  status: "active" | "suspended" | "closing" | "closed";
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  telenowSubAccountId: string | null;
  /** Its wallet balance, an exact decimal in the platform currency. */
  balance: string;
  /** Its plan; null: the default plan. */
  priceListId: string | null;
  createdAt: string;
  updatedAt: string;
  /** The most calls at once Telenow lets it have; null: no limit. */
  maxConcurrentCalls: number | null;
  /** A monthly cap on Telenow's charges for it, in USD; null: none. */
  monthlyCapUsd: string | null;
  closeRequestedAt: string | null;
  closedAt: string | null;
}

export interface CustomerPage {
  customers: Customer[];
  total: number;
  page: number;
  perPage: number;
}

export interface TeamMember {
  userId: number;
  name: string;
  /** Null for a member who signed up with their phone. */
  email: string | null;
  role: string;
  joinedAt: string;
}

export interface TeamInvite {
  id: string;
  email: string;
  role: string;
  expiresAt: string;
  createdAt: string;
}

export interface Team {
  members: TeamMember[];
  invites: TeamInvite[];
  roles: string[];
  canManage: boolean;
}

export interface InviteCreated {
  invite: TeamInvite;
  /** The invite was emailed to the person. */
  emailed: boolean;
  /** The link's secret, answered once and only when the invite was not emailed: the link is `<origin>/console/invite/<token>`. */
  token: string | null;
}

/** The public sign-up form, as the operator set it: the built-in fields, their own (`answers.<key>`), the terms box. */
export interface SignupForm {
  open: boolean;
  fields: SetupField[];
  terms: { url: string; version: string } | null;
  /** Whether a sign-up is confirmed by an emailed code (verifyEmailSignup) and not a link. */
  emailCode: boolean;
  /** The same form for signing up with a phone instead (a `phone` field, no email or password); null when not offered. */
  phoneFields: SetupField[] | null;
}

/** A confirmed sign-up: its account made and the person signed in, or waiting for staff (`message` says what next). */
export interface SignupConfirmed {
  state: "active" | "waiting";
  user: User | null;
  message: string | null;
}

/** Someone a sign-in provider vouched for, finishing their sign-up (getProviderSignup). */
export interface ProviderSignup {
  /** The provider's name. */
  provider: string;
  /** The address the provider verified; the account uses it. */
  email: string;
  fields: SetupField[];
  terms: { url: string; version: string } | null;
}

/** How people sign up (Admin → Sign-ups). */
export interface SignupSettings {
  mode: string;
  domains: string[];
  methods: string[];
  fields: { key: string; label: string; type: string; required: boolean; options: string[] }[];
  terms: { url: string; version: string } | null;
  /** In the pricing currency. */
  trialCredit: string | null;
}

export interface SignupSummary {
  id: string;
  /** Null for a sign-up with a phone instead. */
  email: string | null;
  /** E.164, for a sign-up with a phone. */
  phone: string | null;
  /** The sign-in provider it was made with, by name. */
  provider: string | null;
  name: string;
  accountName: string;
  status: "unverified" | "waiting" | "done" | "rejected";
  answers: Record<string, string>;
  termsVersion: string | null;
  reason: string | null;
  workspaceId: string | null;
  createdAt: string;
  verifiedAt: string | null;
  decidedAt: string | null;
}

export interface SignupAdmin {
  settings: SignupSettings;
  options: { modes: string[]; methods: string[]; fieldTypes: string[]; maxFields: number };
  emailOn: boolean;
  /** The pricing currency when pricing is in force (null: no trial credit possible). */
  currency: string | null;
  open: boolean;
  waiting: SignupSummary[];
  recent: SignupSummary[];
}

/** One email the platform sent or is sending (Admin → Email); never its body. */
/** An email's words; each paragraph is its own string. */
export interface EmailTemplateTexts {
  subject: string;
  lines: string[];
  /** The button's words; null for an email without a button. */
  action: string | null;
  after: string[];
}

/** One email the platform sends (Admin → Email → Templates). */
export interface EmailTemplate {
  kind: string;
  name: string;
  hasButton: boolean;
  /** What it can fill, written `:name`; `brand` first. */
  placeholders: { name: string; about: string | null }[];
  /** The words it is sent with. */
  texts: EmailTemplateTexts;
  /** The kit's own words. */
  defaults: EmailTemplateTexts;
  /** It is sent with the operator's words. */
  edited: boolean;
}

export interface EmailPreview {
  subject: string;
  html: string;
  text: string;
}

export interface OutgoingEmail {
  id: string;
  kind: string;
  kindName: string;
  to: string;
  /** `queued`: waiting to be tried again; `failed`: given up. */
  status: "queued" | "sent" | "failed";
  attempts: number;
  /** Which sender failed and why, in the sender's own words. */
  lastError: string | null;
  createdAt: string;
  sentAt: string | null;
}

/** One text the platform sent or tried (Admin → SMS); never its words. */
export interface OutgoingSms {
  id: string;
  kind: string;
  kindName: string;
  to: string;
  status: "sent" | "failed";
  /** How many senders were tried. */
  attempts: number;
  /** Each failed sender's reason, in the provider's own words. */
  lastError: string | null;
  createdAt: string;
  sentAt: string | null;
}

/** How the platform sends texts (Admin → SMS). Each sender's form is its driver's own fields. */
export interface SmsSettings {
  on: boolean;
  maxSenders: number;
  drivers: { id: string; name: string; fields: SetupField[] }[];
  /** In the order they are tried. */
  senders: { id: string; driver: string; driverName: string; label: string; fields: SetupField[] }[];
  recent: OutgoingSms[];
}

/** The signed-in person's own account. */
export interface Account {
  name: string;
  /** Null for someone who signed up with their phone: it is how they sign in. */
  email: string | null;
  /** E.164; set only once proven with a code. */
  phone: string | null;
  /** The platform can send texts, so a number can be added. */
  texts: boolean;
}

/** How the platform sends email (Admin → Email). Each sender's form is its driver's own fields. */
export interface EmailSettings {
  on: boolean;
  siteUrl: string;
  fromAddress: string | null;
  fromName: string;
  maxSenders: number;
  drivers: { id: string; name: string; fields: SetupField[] }[];
  /** In the order they are tried. */
  senders: { id: string; driver: string; driverName: string; label: string; fields: SetupField[] }[];
  recent: OutgoingEmail[];
}

export interface InviteInfo {
  email: string;
  customerName: string;
  role: string;
  expiresAt: string;
  accountExists: boolean;
  /** Someone new chooses a password as well as a name (false with passwords off). */
  choosesPassword: boolean;
  /** Who is signed in on this browser. */
  signedInAs: string | null;
  /** This browser is signed in to the invited account: joining needs nothing more. */
  canJoinNow: boolean;
}

export interface WorkspaceList {
  workspaces: { id: string; name: string; role: string; current: boolean }[];
}

export interface ConsoleHome {
  workspace: { id: string; name: string; status: "active" | "suspended" };
  role: string;
  canManageTeam: boolean;
  paused: { reason: "account_suspended" | "account_not_ready" | "out_of_credit" | "service_paused"; message: string } | null;
}

export interface AgentTransferPlace {
  label: string | null;
  numbers: string[];
  mode: "serial" | "parallel";
}

export interface AgentTransfer {
  enabled: boolean;
  destinations: AgentTransferPlace[];
  message: string | null;
  noAnswerMessage: string | null;
}

export interface AgentAnalysisField {
  key: string;
  label: string | null;
  type: "string" | "number" | "boolean" | "date";
  description: string | null;
}

export interface AgentAnalysisCheck {
  key: string;
  description: string | null;
}

export interface AgentAnalysis {
  enabled: boolean;
  minDurationSec: number;
  minCustomerTurns: number;
  customFields: AgentAnalysisField[];
  qaCriteria: AgentAnalysisCheck[];
}

/** How an agent behaves on a call and after it (contract/kit-api.yaml AgentSettings). */
export interface AgentSettings {
  /** Each provider's own settings, as the provider's offered ones (an option by the kit's name). */
  llmSettings: { values: Record<string, ProviderSettingValue> };
  sttSettings: { values: Record<string, ProviderSettingValue> };
  ttsSettings: { values: Record<string, ProviderSettingValue> };
  /** Its realtime voice (one model that listens, thinks and speaks), or none: calls use the pipeline. */
  realtime: RealtimeNow | null;
  flow: AgentFlowGraph | null;
  greetingInbound: string | null;
  greetingOutbound: string | null;
  voicemailMessage: string | null;
  recording: boolean;
  /** Null: no limit is set on this agent. */
  maxDurationSecs: number | null;
  maxDurationMessage: string | null;
  silenceHangupSecs: number | null;
  silenceHangupMessage: string | null;
  silenceCheckinSecs: number | null;
  silenceCheckinMessage: string | null;
  canEndCall: boolean;
  transfer: AgentTransfer;
  analysis: AgentAnalysis;
  tone: string;
  personaGender: string | null;
  naturalFillers: boolean;
  highEmpathy: boolean;
  echoVerification: boolean;
  natoPhonetic: boolean;
  speechNormalization: boolean;
  smartMatching: boolean;
  scopeBoundaries: boolean;
  aiDisclosure: boolean;
  interruptions: string;
  rememberInterruptions: boolean;
  noisyRoom: boolean;
  listeningSounds: boolean;
  ignoreSpeechBeforeGreeting: boolean;
  thinkingFillers: boolean;
  backgroundSound: string | null;
  backgroundVolume: number | null;
  ringInbound: boolean;
  ringOutbound: boolean;
  ringSeconds: number | null;
  contextMode: string;
  contextTurns: number | null;
  callerMemory: boolean;
  callerMemoryDays: number | null;
  keypad: boolean;
  keypadWaitMs: number | null;
  keypadEndKey: string | null;
  keypadDigits: number | null;
  optOut: boolean;
  fastOpener: boolean;
  greetingDelaySecs: number | null;
  ringDurationSecs: number | null;
  callScreen: boolean;
  callScreenMessage: string | null;
  ivrHangup: boolean;
  canAdjustVolume: boolean;
  sendCallerToTools: boolean;
  tools: AgentTool[];
  lookups: AgentLookup[];
  handoff: AgentHandoff;
  emailAfterCall: AgentEmailAfterCall;
}

/** Who on the team is emailed after each call: people by their user ids. */
export interface AgentEmailAfterCall {
  recipients: number[];
  onlyAnswered: boolean;
}

export interface FlowCapture {
  name: string;
  type: string;
  description: string | null;
  values: string[];
  required: boolean;
  format: string | null;
}

/** A Function step's request. `token` goes only when typed (or null to take a saved one away). */
export interface FlowTool extends Omit<AgentTool, "description" | "url"> {
  description: string | null;
  url: string | null;
  token?: string | null;
}

export interface FlowStep {
  id: string;
  kind: string;
  name: string | null;
  x: number | null;
  y: number | null;
  instructions: string | null;
  replaceInstructions: boolean;
  line: string | null;
  continueAfterLine: boolean;
  capture: FlowCapture[];
  keypad: { routeOnly: boolean; only: boolean } | null;
  anywhere: { when: string; examples: string[]; goBack: boolean } | null;
  tool: FlowTool | null;
  results: { path: string; variable: string }[];
  sends: string[];
  code: { source: string | null; timeoutMs: number | null; keep: { field: string; variable: string }[] } | null;
  transfer: { destinations: { label: string | null; numbers: string[]; mode: string }[]; message: string | null; noAnswerMessage: string | null } | null;
  handoff: { agents: { agentId: string; label: string; mode: string }[]; message: string | null } | null;
}

export interface FlowExit {
  id: string;
  from: string;
  to: string;
  label: string | null;
  when: { kind: string; expr: string | null; describe: string | null; digit: string | null; match: string | null };
}

export interface AgentFlowGraph {
  start: string | null;
  steps: FlowStep[];
  exits: FlowExit[];
}

export interface FlowWarning {
  at: string;
  message: string;
}

export interface AgentFlowForm {
  kinds: string[];
  exitKinds: string[];
  captureTypes: string[];
  captureFormats: string[];
  otherAgents: { id: string; name: string }[];
}

export interface AgentFlowState extends AgentFlowForm {
  published: AgentFlowGraph | null;
  draft: AgentFlowGraph | null;
  draftSavedAt: string | null;
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  warnings: FlowWarning[];
}

export interface AgentVersion {
  version: number;
  at: string | null;
  name: string | null;
  kind: "created" | "changed" | "restored";
  restoredFrom: number | null;
  current: boolean;
}

export interface AgentVersionChange {
  label: string;
  from: string | null;
  to: string | null;
}

export interface AgentVersionWindow {
  calls: number;
  answeredRate: number | null;
  avgDurationSec: number | null;
  avgScore: number | null;
  sentiment: { positive: number; neutral: number; negative: number };
  from: string | null;
  to: string | null;
}

export interface AgentWebhook {
  id: string;
  url: string;
  events: string[];
  includeTranscript: boolean;
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  /** Shown this once, then gone. */
  signingSecret: string | null;
  lastOkAt: string | null;
  failing: boolean;
  stopped: boolean;
}

export interface AgentWebhookDelivery {
  id: string;
  event: string;
  status: string;
  attempt: number;
  responseStatus: number | null;
  problem: string | null;
  kind: string | null;
  at: string | null;
  canSendAgain: boolean;
}

/** An agent's chat box on the customer's own website (getAgentWebChat): visitors talk to this platform, never to the voice platform. */
export interface WebChat {
  /** Whether the operator offers web chat. */
  allowed: boolean;
  /** Whether the account's plan has a price for a chat (always true while customers are not charged). */
  priced: boolean;
  enabled: boolean;
  /** The websites it may show on, each by its address alone. */
  websites: string[];
  /** Its heading; null shows the agent's name. */
  title: string | null;
  /** The first message a visitor sees; never sent to the agent. */
  greeting: string | null;
  /** The code to put on each page, before `</body>`; null until it is first turned on. */
  snippet: string | null;
}

export interface WebChatInput {
  enabled: boolean;
  websites: string[];
  title?: string | null;
  greeting?: string | null;
}

export interface AgentRequestHeader {
  name: string;
  value: string;
}

export interface AgentToolParameter {
  name: string;
  type: "string" | "number" | "integer" | "boolean";
  description: string | null;
  required: boolean;
}

export interface AgentTool {
  name: string;
  description: string;
  url: string;
  /** Null: it is POSTed the tool's name and details as JSON. */
  request: { method: string; headers: AgentRequestHeader[]; body: string | null } | null;
  parameters: AgentToolParameter[];
  /** How a saved key is shown; the key itself is never answered. */
  tokenHint: string | null;
  sayWhileRunning: string | null;
  timeoutSecs: number | null;
  askFirst: boolean;
}

export interface AgentLookupFill {
  variable: string;
  path: string;
  fallback: string | null;
}

export interface AgentLookup {
  name: string;
  url: string;
  method: "GET" | "POST";
  headers: AgentRequestHeader[];
  body: string | null;
  tokenHint: string | null;
  fills: AgentLookupFill[];
}

export interface AgentHandoffTarget {
  agentId: string;
  label: string | null;
  mode: "transfer" | "connect";
}

export interface AgentHandoff {
  agents: AgentHandoffTarget[];
  message: string | null;
}

/** A tool or look-up as sent: `token` left out keeps a saved key, null removes it. */
export type AgentToolInput = Omit<AgentTool, "tokenHint"> & { token?: string | null };
export type AgentLookupInput = Omit<AgentLookup, "tokenHint"> & { token?: string | null };
export type AgentSettingsInput = Partial<Omit<AgentSettings, "tools" | "lookups" | "llmSettings" | "sttSettings" | "ttsSettings" | "realtime">> & { tools?: AgentToolInput[]; lookups?: AgentLookupInput[] };

export interface AgentSettingsGroup {
  id: string;
  title: string;
  description: string;
  fields: SetupField[];
}

/** What the agent form offers: how an agent speaks and listens, and whether the operator allows transfers. */
export interface AgentSettingsForm {
  transferAllowed: boolean;
  /** Whether the kit can send email at all. */
  emailOn: boolean;
  groups: AgentSettingsGroup[];
}

export interface Agent {
  id: string;
  name: string;
  description: string | null;
  systemPrompt: string | null;
  isActive: boolean;
  /** The voice setup the agent runs on, as the voice platform holds it (null until it is made there). */
  stack: AgentStack;
  /** What the operator calls each part (null for a part that is not offered). */
  stackNames: AgentStack;
  /** How it behaves on a call and after it. */
  settings: AgentSettings;
  /** The last check of its voice setup. */
  stackCheck: StackCheck | null;
  /** The knowledge bases it answers from. */
  knowledgeBases: KnowledgeBaseRef[];
  /** Where its logo is served (this platform's own address); null for none. */
  logoUrl: string | null;
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  createdAt: string;
  updatedAt: string;
}

/**
 * One thing left to do on a copy of an agent (duplicateAgent): `key`, type again the key of the tool or look-up `name`;
 * `flow`, its call steps are a draft to finish and publish; `voiceSetup`, it has the default voice setup; `webhooks`, each
 * address has its own new signing secret; `tools`, `lookups`, `handoff`, these are to be set again.
 */
export interface AgentCopyToDo {
  kind: "key" | "flow" | "voiceSetup" | "webhooks" | "tools" | "lookups" | "handoff";
  name: string | null;
}

export interface Campaign {
  id: string;
  name: string;
  agent: { id: string; name: string } | null;
  callerId: { id: string; number: string } | null;
  settings: {
    concurrency: number;
    windowStart: string | null;
    windowEnd: string | null;
    timezone: string;
    machineDetection: string;
    maxAttempts: number;
    retryGapMinutes: number;
    retryOnNoAnswer: boolean;
  };
  /** `saving`: not at the voice platform yet; `failed`: it refused it (see `syncError`). */
  status: "saving" | "failed" | "draft" | "queued" | "running" | "paused" | "completed" | "cancelled";
  /** Why a running campaign is not calling right now. */
  waiting: { reason: "window" | "other"; opensAt: string | null } | null;
  counts: { people: number; answered: number; notReached: number };
  /** What sending its call list met. */
  pushed: { added: number; duplicates: number; suppressed: number; skipped: number };
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  /** People from a link (setCampaignSource); null when it has none. */
  source: CampaignSource | null;
  startedAt: string | null;
  completedAt: string | null;
  createdAt: string;
}

/** A campaign's link: a CSV read again every `linkEveryMinutes` while the campaign is not finished, adding only new numbers. */
export interface CampaignSource {
  url: string;
  /** When it was last read; null before the first read. */
  checkedAt: string | null;
  /** Why it could not be read last time, in words for the customer. */
  problem: string | null;
  /** How many people it has added. */
  added: number;
}

/** What one read of a campaign's link added (setCampaignSource). `alreadyThere`: numbers the campaign has already; `skipped`: lines with no number. */
export interface CampaignLinkRead {
  added: number;
  alreadyThere: number;
  skipped: number;
  noCountryCode: number;
}

/** What a new campaign starts from and what this backend takes (listCampaigns). */
export interface CampaignLimits {
  /** The operator lets campaigns take people from a link (setCampaignSource). */
  linksAllowed: boolean;
  /** How often a campaign's link is read again. */
  linkEveryMinutes: number;
  nameMaxLength: number;
  concurrency: { default: number; max: number };
  maxAttempts: { default: number; max: number };
  retryGapMinutes: { default: number; min: number; max: number };
  machineDetection: string[];
  /** The CSV header names that hold the number; every other column is a variable. */
  phoneColumns: string[];
  importMaxBytes: number;
  importMaxRows: number;
}

export interface CampaignInput {
  name: string;
  agentId: string;
  callerId?: string | null;
  concurrency?: number;
  windowStart?: string | null;
  windowEnd?: string | null;
  timezone?: string | null;
  machineDetection?: string;
  maxAttempts?: number;
  retryGapMinutes?: number;
  retryOnNoAnswer?: boolean;
}

export interface CampaignTarget {
  id: string;
  phoneNumber: string;
  variables: Record<string, string>;
  /** `saving`: not sent yet; `waiting`: not called yet, or being called. */
  state: "saving" | "waiting" | "done";
  /** Once done: answered, no-answer, busy, machine or failed. */
  outcome: string | null;
  attempts: number | null;
  completedAt: string | null;
  /** Called again after a retry, waiting until the new call's result comes. */
  tryingAgain: boolean;
}

/** One group of a campaign's people. `unanswered`: nobody answered, it was busy, or a machine did. */
export type CampaignPeopleGroup = "waiting" | "answered" | "unanswered" | "failed";

export interface CampaignTargetPage {
  targets: CampaignTarget[];
  /** How many there are of those asked for. */
  total: number;
  page: number;
  perPage: number;
  /** How many of all its people stand where. */
  counts: Record<CampaignPeopleGroup, number>;
}

/** Who to call again: those nobody answered, or everyone whose call did not go through. */
export type CampaignRetryScope = "unanswered" | "all";

/** How a campaign went so far (getCampaignProgress). */
export interface CampaignProgress {
  people: number;
  called: number;
  reached: number;
  notReached: number;
  waiting: number;
  onDoNotCall: number;
  skipped: number;
  whyNotReached: { noAnswer: number; busy: number; voicemail: number; couldNotConnect: number };
  talk: { totalSeconds: number; averageSeconds: number | null; nobodySpoke: number; under15: number; under30: number; under60: number; under120: number; over120: number };
  reachedOnAttempt: { first: number; second: number; thirdOrLater: number };
  calledLastHour: number;
  /** Each hour of the day in the campaign's time zone. */
  byHour: { timezone: string; called: number[]; reached: number[] };
}

/** Someone's agreement to be called: never changed or removed, only withdrawn. */
export interface ConsentRecord {
  id: string;
  phoneNumber: string;
  consentType: string;
  scope: string;
  source: string;
  disclosureText: string | null;
  disclosureVersion: string | null;
  note: string | null;
  capturedAt: string;
  expiresAt: string | null;
  state: "live" | "expired" | "withdrawn";
  withdrawnAt: string | null;
  withdrawnNote: string | null;
  /** The number is not called, whatever its consent says. */
  onDoNotCall: boolean;
  recordedBy: string | null;
  /** `pending`: not counted yet when calls are placed. */
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  createdAt: string;
}

/** Whether the account's calls need consent on file, who says so, and how many waiting calls that touches. */
export interface ConsentRule {
  required: boolean;
  byOperator: boolean;
  byCustomer: boolean;
  byProvider: boolean;
  saving: boolean;
  /** None when it could not be read just now. */
  waiting: { total: number; covered: number; blocked: number } | null;
  canChange: boolean;
}

export interface ConsentLimits {
  types: string[];
  writtenType: string;
  scopes: string[];
  defaultScope: string;
  sources: string[];
  phoneColumns: string[];
  capturedColumn: string;
  expiresColumn: string;
  importMaxBytes: number;
  importMaxRows: number;
}

export interface ConsentPage {
  records: ConsentRecord[];
  total: number;
  page: number;
  perPage: number;
  rule: ConsentRule;
  limits: ConsentLimits;
}

export interface ConsentInput {
  phoneNumber: string;
  consentType: string;
  scope?: string | null;
  source: string;
  disclosureText?: string | null;
  disclosureVersion?: string | null;
  capturedAt?: string | null;
  expiresAt?: string | null;
  note?: string | null;
}

export interface ConsentImport {
  csv: string;
  consentType: string;
  scope?: string | null;
  disclosureText?: string | null;
  disclosureVersion?: string | null;
  capturedAt?: string | null;
  expiresAt?: string | null;
}

export interface CampaignReach {
  pending: number;
  dialable: number;
  onDoNotCall: number;
  withoutConsent: number;
  blocked: boolean;
}

export interface KnowledgeBaseRef {
  id: string;
  name: string;
}

export interface KnowledgeBase {
  id: string;
  name: string;
  description: string | null;
  /** `working`: being saved or read for the agents. */
  documentCounts: { total: number; ready: number; working: number; failed: number };
  /** The agents that answer from it. */
  agents: KnowledgeBaseRef[];
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  createdAt: string;
}

export interface KnowledgeDocument {
  id: string;
  title: string;
  kind: "text" | "file" | "page";
  /** A file's name, a page's address. */
  source: string | null;
  chars: number | null;
  /** `saving`: not at the voice platform yet; `working`: being read for the agents; `failed`: see `problem`. */
  state: "saving" | "working" | "ready" | "failed";
  problem: string | null;
  createdAt: string;
  /** A CSV, JSON or Excel file, kept as a table agents look rows up in (knowledgeTable). */
  table: boolean;
}

/** How agents read a table's column: `number` and `date` are compared (and numbers added up) as such; `text` and `bool` by their words. */
export type KnowledgeColumnType = "text" | "number" | "bool" | "date";

export interface KnowledgeTableColumn {
  /** How the column is named to agents, in `rows` and in setKnowledgeColumnTypes. */
  key: string;
  /** Its heading in the file. */
  name: string;
  type: KnowledgeColumnType;
}

/** One cell of a table's row: text, a number, true or false, or null (none). */
export type KnowledgeCell = string | number | boolean | null;

/** A spreadsheet document as the agents use it: its columns and a page of its rows. */
export interface KnowledgeTable {
  columns: KnowledgeTableColumn[];
  /** This page's rows, each its cells in the order of `columns`. */
  rows: KnowledgeCell[][];
  rowCount: number;
  offset: number;
  limit: number;
  format: "csv" | "json" | "xlsx";
  /** Small enough that agents read it whole on every call (and still look rows up in it). */
  inPrompt: boolean;
  /** For a table too big to read whole: finding rows by meaning covers only its first this many rows; null when it covers them all. */
  searchByMeaningRows: number | null;
}

export interface KnowledgeLimits {
  fileMaxBytes: number;
  fileTypes: string[];
  textMaxLength: number;
}

/** An agent's stack: each provider under the kit's own key (getStackChoices), the model and voice by their ids. */
export interface AgentStack {
  llmProvider: string | null;
  llmModel: string | null;
  sttProvider: string | null;
  ttsProvider: string | null;
  ttsVoice: string | null;
}

export interface AgentPage {
  agents: Agent[];
  total: number;
  page: number;
  perPage: number;
}

export interface AgentInput {
  name?: string;
  description?: string | null;
  systemPrompt?: string | null;
  isActive?: boolean;
  settings?: AgentSettingsInput;
  /** The stack is chosen whole: all five together, or none. */
  llmProvider?: string;
  llmModel?: string;
  sttProvider?: string;
  ttsProvider?: string;
  ttsVoice?: string;
  /** Each provider's own settings sent (only those offered; null back to the provider's default). */
  providerSettings?: Partial<Record<"llm" | "stt" | "tts", Record<string, ProviderSettingValue>>>;
  /** The realtime voice (a key and a model from getStackChoices `realtime`), or null for none. */
  realtime?: RealtimeInput | null;
}

/** A realtime voice to give an agent: no voice is the model's own default; fallback (default true) uses the pipeline when it cannot start. */
export interface RealtimeInput {
  provider: string;
  model: string;
  voice?: string | null;
  fallback?: boolean;
}

/** An agent's realtime voice as it is: what each part is called (null: no longer offered), and whether calls use it now. */
export interface RealtimeNow {
  provider: string;
  model: string;
  voice: string | null;
  fallback: boolean;
  names: { provider: string | null; model: string | null; voice: string | null };
  available: boolean;
}

export interface RealtimeVoice {
  id: string;
  name?: string;
  gender?: string;
  accent?: string;
  tags?: string[];
  recommended?: boolean;
}

/** A realtime voice provider customers may pick (getStackChoices `realtime`), its models and each model's voices. */
export interface RealtimeChoice {
  key: string;
  name: string;
  languages: string[];
  models: { id: string; name: string; voices: RealtimeVoice[] }[];
}

/** What an agent's stack can be: the providers the operator offers, under the kit's own keys and the operator's names. */
/** One of a provider's own settings customers may change (getStackChoices): an option named by the kit, never the voice platform's own value. */
export interface ProviderSetting {
  key: string;
  kind: "select" | "number" | "toggle" | "text" | "textarea";
  label: string;
  help?: string;
  options?: { value: string; label: string }[];
  min?: number;
  max?: number;
  step?: number;
  /** The provider's own default (an option's name for a select); null when none is offered. */
  default: string | number | boolean | null;
  advanced: boolean;
  /** It holds the voice's (or listening) model, whose voices listVoices `model` narrows to. */
  model: boolean;
}

export type ProviderSettingValue = string | number | boolean | null;

export interface StackChoices {
  llm: { key: string; name: string; models: { id: string; name: string }[]; settings: ProviderSetting[] }[];
  stt: { key: string; name: string; settings: ProviderSetting[] }[];
  tts: { key: string; name: string; settings: ProviderSetting[] }[];
  /** Realtime voices customers may pick; none while the platform runs none. */
  realtime: RealtimeChoice[];
  defaults: AgentStack;
}

/** The last check of an agent's voice setup (checkAgentStack), each part in the kit's words. */
export interface StackCheck {
  checkedAt: string;
  parts: { part: "llm" | "stt" | "tts" | "realtime"; status: "ok" | "failed" | "not_checked"; reason: string | null }[];
}

export interface Voice {
  /** Its provider's key. */
  provider: string;
  providerName: string;
  id: string;
  name: string;
  language: string | null;
  gender: string | null;
  accent: string | null;
  style: string | null;
  useCase: string | null;
  description: string | null;
  tags: string[];
  recommended: boolean;
  /** The member starred it. */
  starred: boolean;
}

export interface VoicePage {
  voices: Voice[];
  total: number;
  page: number;
  perPage: number;
  /** The language codes and genders there are to narrow by. */
  languages: string[];
  genders: string[];
}

export interface VoiceFilters {
  provider?: string;
  language?: string;
  gender?: string;
  search?: string;
  /** With `provider`: one of its models, as its model setting names it. */
  model?: string;
  starred?: boolean;
  page?: number;
}

/** Admin: a stack by the voice platform's ids. */
export type StackIds = AgentStack;

export interface CuratedModel {
  id: string;
  catalogName: string;
  name: string;
  ownName: string | null;
  namesPlatform: boolean;
  offered: boolean;
}

/** A language model of a provider in Admin → Catalog: the catalog's own, or one the operator added from the provider's own list. */
export interface CuratedLlmModel extends CuratedModel {
  /** Added by the operator from the provider's own list (CurationInput `extraModels`), listed after the catalog's own; its `catalogName` is the list's name when it was added. */
  added: boolean;
}

export interface CuratedProvider extends CuratedModel {
  enabled: boolean;
  status: string | null;
  recommended: boolean;
  /** Language models only. */
  models: CuratedLlmModel[];
  /** Models may be added to it from its own whole list (`openModels`, `openModelPrice`; CurationInput `extraModels`). Language models only. */
  openModels: boolean;
  /** Voice providers only: how many voices it has (a page of them: `catalogVoices`). */
  voiceCount: number;
  /** Its own settings, with the platform's values, and whether customers may change each (and which options). */
  settings: CuratedSetting[];
}

export interface CuratedSetting {
  key: string;
  kind: string;
  label: string;
  help: string | null;
  options?: { value: string | number | boolean; label: string; offered: boolean }[];
  model: boolean;
  offered: boolean;
  advanced?: boolean;
}

export type CatalogKind = "llm" | "stt" | "tts";

export interface CatalogCuration {
  fetchedAt: string | null;
  error: { at: string; message: string } | null;
  /** The name customers never see: a name that carries it is never offered. */
  platformName: string;
  kinds: Record<CatalogKind, CuratedProvider[]>;
  defaults: StackIds;
  recommended: StackIds;
  /** The names of the default and the recommended voice, by id: they may not be on the page of voices shown. */
  voiceNames: Record<string, string>;
  /** Whether customers may set up transfers to a person. */
  transferAllowed: boolean;
  /** How many agents have one now. */
  transferStillOn: number;
  /** Whether customers may place calls from the browser, from their own numbers. */
  browserCallsAllowed: boolean;
  /** The price lists with no price for such a call, while customers are charged: a customer on one cannot place them. */
  browserCallsUnpriced: { id: string; name: string }[];
  /** Whether customers may put a chat box on their websites, answered by their agents. */
  webChatAllowed: boolean;
  /** The price lists with no price for a chat, while customers are charged: a customer on one cannot turn a chat box on. */
  webChatUnpriced: { id: string; name: string }[];
  /** Whether campaigns may take their people from a link this server reads (only https links to public addresses). */
  campaignLinksAllowed: boolean;
  consentRequired: boolean;
  consentStillToTell: number;
  /** Whether customers may clone voices of their own. */
  cloningAllowed: boolean;
  /** Clones (and own voices) one account may have; at most `clonesMost`, the voice platform's own limit. */
  clonesPerAccount: number;
  clonesMost: number;
  /** Realtime voices: whether the platform runs them, and its providers with the operator's choices. */
  realtime: { enabled: boolean; providers: CuratedRealtimeProvider[] };
}

export interface CuratedRealtimeProvider {
  id: string;
  catalogName: string;
  name: string;
  ownName: string | null;
  namesPlatform: boolean;
  /** The operator's choice, kept while the platform runs none. */
  offered: boolean;
  recommended: boolean;
  languages: string[];
  models: { id: string; catalogName: string; name: string; ownName: string | null; namesPlatform: boolean; offered: boolean; voiceCount: number }[];
}

/** Admin → Catalog → Add models (listOpenModels): a page of the open-model provider's own list, searched. */
export interface OpenModelPage {
  /** The platform's id for the provider the models are added to. */
  provider: string;
  models: {
    /** The list's model id (may hold `/`, `.`, `:`, `~`). */
    id: string;
    name: string;
    /** Added by the operator, as saved. */
    added: boolean;
    /** In the catalog already: chosen there, never added. */
    inCatalog: boolean;
  }[];
  page: number;
  perPage: number;
  /** How many match the search. */
  total: number;
  /** How many models may be added in all. */
  max: number;
}

/** What the voice platform charges the operator for one of those models (getOpenModelPrice): US dollars per million tokens, an estimate; null when not found. */
export interface OpenModelPrice {
  model: string;
  found: boolean;
  inputPerM: number | null;
  outputPerM: number | null;
}

/** An account's processing country on the voice platform (getPlatformProcessingCountry, getCustomerProcessingCountry). Admin only. */
export interface ProcessingCountry {
  /** Two letters; null when the platform has none for the account. */
  country: string | null;
  /** The account's declared country, its sign-up time zone's, or neither. */
  source: "country" | "time_zone" | "unknown";
  checkedAt: string;
}

export interface CurationInput {
  kinds: Partial<Record<CatalogKind, Record<string, { offered: boolean; name: string | null; models?: Record<string, { offered: boolean; name: string | null }>;
    /** The provider with `openModels` only: its added models now, by the list's model id (`{}` for none); not sent, those added before stay. */
    extraModels?: Record<string, { offered: boolean; name: string | null }>;
    settings?: Record<string, { offered: boolean; options?: Record<string, boolean> }> }>>>;
  defaults: StackIds;
  transferAllowed?: boolean;
  browserCallsAllowed?: boolean;
  webChatAllowed?: boolean;
  campaignLinksAllowed?: boolean;
  consentRequired?: boolean;
  cloningAllowed?: boolean;
  clonesPerAccount?: number;
  realtime?: Record<string, { offered: boolean; name: string | null; models?: Record<string, { offered: boolean; name: string | null }> }>;
}

export type VoiceRelation = "employee" | "voice_actor" | "customer" | "other";

/** One of the account's own voices (listOwnVoices): whose it is and who confirmed their release, for a manager only. */
export interface OwnVoice {
  id: string;
  /** How an agent's voice setup and a preview name it. */
  voice: string;
  name: string;
  personName: string | null;
  relation: VoiceRelation | null;
  language: string | null;
  source: "upload" | "record";
  hasSample: boolean;
  sampleSeconds: number | null;
  confirmedBy: string | null;
  confirmedAt: string | null;
  releaseVersion: string | null;
  createdAt: string;
  /** Its clones, one a provider; `providerName` is null when that provider is no longer offered. */
  clones: { provider: string; providerName: string | null; status: string }[];
  /** The agents that speak with it. */
  usedBy: { id: string; name: string }[];
}

/** Console → Voices → Your voices (listOwnVoices). */
export interface OwnVoicesPage {
  allowed: boolean;
  manages: boolean;
  paused: string | null;
  clones: { used: number; limit: number };
  /** The offered voice providers that can clone for the account now. */
  targets: { key: string; name: string }[];
  relations: VoiceRelation[];
  releaseVersion: string;
  limits: { nameMaxLength: number; personMaxLength: number; languageMaxLength: number; scriptMaxLength: number; sampleMaxBytes: number; sampleMinSeconds: number; sampleTypes: string[] };
  voices: OwnVoice[];
}

export interface OwnVoiceInput {
  name: string;
  personName: string;
  relation: VoiceRelation;
  confirmed: boolean;
  language: string | null;
  script: string | null;
  source: "upload" | "record";
}

/** One thing the prompt check found (PromptFinding): the field and line it is at, and a message ready to show. */
export interface PromptFinding {
  field: "systemPrompt" | "greeting";
  code: string;
  line: number;
  column: number;
  message: string;
}

/** The call a preview of the instructions is for (PromptCheckInput `channel`); none is no channel sent. */
export type PromptChannel = "phone_inbound" | "phone_outbound" | "chat";

/** What the prompt check is sent (checkPrompt, checkNewPrompt): with `values`, even `{}`, a preview comes back. */
export interface PromptCheckInput {
  systemPrompt: string | null;
  greeting?: string | null;
  values?: Record<string, string | null> | null;
  channel?: PromptChannel | null;
}

/**
 * The prompt check's answer (PromptCheck). `checked` false: too long to check (saved unchecked). `valid` false: a save would be refused.
 * `preview` only when sample values were sent; each section's `branch` is counted from 1, null for none kept.
 */
export interface PromptCheck {
  checked: boolean;
  valid: boolean;
  errors: PromptFinding[];
  warnings: PromptFinding[];
  preview: { text: string; sections: { line: number; branch: number | null; branches: number }[] } | null;
}

/** The part of a call a provider served (CallHealth, VoicePlatformHealth `callHealth`). */
export type CallHealthPart = "stt" | "tts" | "llm" | "other";

/** One provider that failed on an account's recent calls (CallHealth `failures`); the last five fields are staff only. */
export interface CallHealthFailure {
  part: CallHealthPart;
  /** The operator's name for it, or its part when there is none to show. */
  provider: string;
  cause: "provider" | "setup" | "platform" | "unknown";
  calls: number;
  lastAt: string | null;
  agents: { id: string; name: string }[];
  providerId?: string;
  attribution?: string;
  keySource?: string;
  incidents?: number;
  error?: string | null;
}

/** An account's call health (getCallHealth; getCustomerCallHealth with the staff fields). */
export interface CallHealth {
  windowMinutes: number;
  /** Calls with a failure, each once however many parts failed. */
  callsAffected: number;
  checkedAt: string;
  failures: CallHealthFailure[];
}

/** What Call someone offers for an agent (getAgentCallForm). */
export interface AgentCallForm {
  callerIds: { id: string; number: string }[];
  /** The agent's own number among them; null: it has none. */
  ownNumber: string | null;
  /** The details the agent's words use. */
  variables: { name: string; required: boolean }[];
  machineAnswers: ("voicemail" | "hang_up")[];
  paused: string | null;
  limits: { numberMaxLength: number; variableValueMaxLength: number; openingLineMaxLength: number };
}

export interface AgentCallInput {
  number: string;
  callerId?: string | null;
  variables?: Record<string, string>;
  openingLine?: string | null;
  machine?: "voicemail" | "hang_up" | null;
}

/** What the browser joins a test call with: @telenow/client's TelenowSession. */
export interface TestSession {
  sessionId: string;
  transport?: "websocket" | "webrtc";
  websocketUrl?: string;
  livekitUrl?: string;
  token?: string;
  room?: string;
}

export interface CallSummary {
  id: string;
  /** The account's agent that took the call (null for one the kit does not know). */
  agent: { id: string; name: string } | null;
  /** The member who placed it from the browser (null for an agent's call); `name` is null once they are no longer a user here. */
  placedBy: { name: string | null; me: boolean } | null;
  /** The member who asked the agent to make it (null for any other call). */
  requestedBy: { name: string | null; me: boolean } | null;
  status: string | null;
  /** `completed`, `no_answer`, `busy`, `failed`, … as the voice platform reports it. */
  outcome: string | null;
  /** `inbound`, `outbound`, `web`, … */
  direction: string | null;
  /** `telephony`, `web_call`, `softphone` (placed from the browser), `chat` (a web chat: no phone number), … */
  channel: string | null;
  from: string | null;
  to: string | null;
  startedAt: string | null;
  endedAt: string | null;
  durationSec: number | null;
  /** False while the voice platform is still completing the call. */
  complete: boolean;
  /** What the call was charged to the wallet (null until it is, and for a call that cost nothing). */
  charge: { amount: string; currency: string } | null;
  /** How it went, in a member's words. */
  wrapup: string | null;
}

/**
 * Which calls a page of them holds: one agent's, one person's by their number, those the member placed from the browser, those of
 * whole days, one outcome, one channel. The file of calls (callsExportUrl) takes the same, but `placedBy`.
 */
export interface CallFilter {
  agentId?: string;
  number?: string;
  placedBy?: "me";
  /** The first day (YYYY-MM-DD), in `timeZone`. */
  from?: string;
  /** The last day (YYYY-MM-DD), in `timeZone`. */
  to?: string;
  /** The IANA time zone the days are in (the browser's); UTC when none. */
  timeZone?: string;
  /** The voice platform's word (`answered`, `no-answer`, `busy`, `failed`, `machine`, …). */
  outcome?: string;
  /** `telephony`, `web_call`, `softphone`, `chat`, … */
  channel?: string;
}

/** How many calls had one outcome, sentiment or channel (`key` as the voice platform words it; null for none). */
export interface InsightCount {
  key: string | null;
  count: number;
}

/** What was charged in one currency: an exact decimal string in the currency's own units. */
export interface InsightSpend {
  currency: string;
  amount: string;
}

/** One day of a period of insights (every day is there, those with no call too). */
export interface InsightDay {
  day: string;
  calls: number;
  answered: number;
  seconds: number;
}

/** One agent's calls in a period of insights; `agentId` null for calls with no agent, `name` null for an agent no longer here. */
export interface InsightAgent {
  agentId: string | null;
  name: string | null;
  calls: number;
  answered: number;
  seconds: number;
  spend: InsightSpend[];
}

/** How the account's calls went over whole days (getInsights); `answered`: calls that lasted and that no machine picked up. */
export interface Insights {
  from: string;
  to: string;
  timeZone: string;
  calls: number;
  answered: number;
  seconds: number;
  /** How long an answered call lasted on average; 0 with none. */
  averageSeconds: number;
  /** Most first. */
  outcomes: InsightCount[];
  /** Over the calls with an analysis; most first. */
  sentiments: InsightCount[];
  /** Most first. */
  channels: InsightCount[];
  spend: InsightSpend[];
  /** Oldest first. */
  days: InsightDay[];
  /** Most calls first. */
  agents: InsightAgent[];
}

/** The period insights cover (whole days in `timeZone`; the backend's default when none) and, if asked, one agent's calls only. */
export interface InsightsQuery {
  from?: string;
  to?: string;
  timeZone?: string;
  agentId?: string;
}

/** One caller the account's agents remember (getCallerMemory): a phone caller by their number, a web chat visitor by the website they chatted on. */
export interface RememberedCaller {
  /** What forgetCaller takes to forget them. */
  key: string;
  kind: "phone" | "chat" | "other";
  /** The number with its +, or the key for a caller that is not a phone. */
  caller: string;
  /** For a web chat visitor, the website they chatted on, when the kit still has the conversation. */
  website: string | null;
  summary: string | null;
  /** The facts kept about them, by name. */
  facts: { name: string; value: string }[];
  /** How many of their calls it was made from. */
  calls: number;
  updatedAt: string;
  /** When it stops being used on calls; null for never. */
  expiresAt: string | null;
  /** No longer used on calls. */
  expired: boolean;
  /** The kit's call it was last made from, when the kit has it. */
  lastCallId: string | null;
}

/** What the account's agents remember about callers, most recent first (getCallerMemory). */
export interface CallerMemory {
  callers: RememberedCaller[];
  /** The most this lists; any caller can still be forgotten, listed or not. */
  limit: number;
  /** Whether this member may forget a caller (one who manages the account). */
  canForget: boolean;
}

/** Calls on now (getLiveCalls): by the account's agents, and the rest, placed from the browser. */
export interface LiveCalls {
  total: number;
  agents: { id: string; name: string; count: number }[];
  others: number;
  /** The calls on now, newest first; `canListen` says the member may listen in to one. */
  calls: LiveCall[];
  /** The member manages the account, so may listen in. */
  canListen: boolean;
  /** How long these figures stand. */
  refreshSeconds: number;
}

export interface LiveCall {
  id: string;
  agent: { id: string; name: string } | null;
  placedBy: { name: string | null; me: boolean } | null;
  direction: string | null;
  channel: string | null;
  from: string | null;
  to: string | null;
  startedAt: string | null;
  canListen: boolean;
}

export interface CallNote {
  id: string;
  body: string;
  /** Null once they are no longer a user here. */
  by: string | null;
  mine: boolean;
  at: string;
}

export interface FollowUp {
  id: string;
  body: string;
  dueAt: string;
  /** Whose it is. */
  by: string | null;
  mine: boolean;
  doneAt: string | null;
  doneBy: string | null;
  createdAt: string;
  /** The call it is about (on listFollowUps). */
  call?: { id: string; direction: string | null; from: string | null; to: string | null; startedAt: string | null };
}

/** What the account's members wrote on a call: the kit's own. */
export interface CallWork {
  wrapup: { label: string; by: string | null; at: string | null } | null;
  notes: CallNote[];
  followUps: FollowUp[];
  /** The account's latest wrap-ups, each once, newest first. */
  suggestions: string[];
  limits: { noteMaxLength: number; wrapupMaxLength: number; followUpMaxLength: number };
}

export interface FollowUpPage {
  followUps: FollowUp[];
  total: number;
  page: number;
  perPage: number;
}

/** What Console → Phone offers (getPhone). */
export interface PhonePage {
  allowed: boolean;
  transferAllowed: boolean;
  /** Why calls cannot be placed now. */
  paused: string | null;
  /** The plan has a price for such a call. */
  priced: boolean;
  consentRequired: boolean;
  /** The account's numbers a call can come from. */
  callerIds: { id: string; number: string }[];
}

export interface CallPage {
  calls: CallSummary[];
  total: number;
  page: number;
  perPage: number;
  /** The outcomes the account's calls have, most calls first: what the outcome filter offers. */
  outcomes: string[];
}

/** Something a member told the agent during a call (tellAgent): background from its next reply, never the caller's words. */
export interface AgentNote {
  /** Who told it; null once they are no longer a user here. */
  by: string | null;
  at: string;
  text: string;
  /** The agent was asked to say it at once. */
  sayNow: boolean;
  /** `nextReply`: from the agent's next reply; `whenBack`: when the agent has the call again (on hold, a transfer under way); `sayingNow`: it is saying it now. */
  delivery: "nextReply" | "whenBack" | "sayingNow";
}

export interface CallDetail extends CallSummary {
  /** The member may listen in to it now. */
  canListen: boolean;
  /** Who listened in to it, newest first. */
  listens: { by: string | null; at: string }[];
  /** The member may tell the agent something now (tellAgent). */
  canTellAgent: boolean;
  /** What the agent was told during the call, newest first. */
  agentNotes: AgentNote[];
  answeredBy: string | null;
  /** `role`: `user` (the caller, or the visitor on a web chat) or `assistant` (the agent). */
  transcript: { role: string | null; text: string; at: string | null }[];
  recordings: { index: number; durationSec: number | null; createdAt: string | null }[];
  analysis: { summary: string | null; sentiment: string | null; disposition: string | null; actionItems: unknown; customData?: Record<string, unknown> | null; qa?: { key?: string; met?: boolean; evidence?: string }[] | null } | null;
}

export interface WalletEntry {
  id: number;
  /** `credit`: by staff, a trial or a payment; `refund`: a payment given back; `hold` / `release`: a disputed payment's amount taken off / given back. */
  kind: "credit" | "charge" | "refund" | "hold" | "release";
  /** Signed: a charge, refund or hold is negative. */
  amount: string;
  currency: string;
  balanceAfter: string;
  note: string | null;
  callId: string | null;
  /** A number fee's number (its setup or a month's rent). */
  numberId: string | null;
  /** The payment of a top-up, refund, hold or release. */
  paymentId: string | null;
  createdAt: string;
}

export interface Wallet {
  currency: string | null;
  balance: string;
  inForce: boolean;
  entries: WalletEntry[];
  total: number;
  page: number;
  perPage: number;
  alert: WalletAlert;
}

/** When the account's balance emails come; the out-of-credit one always comes, at zero. */
export interface WalletAlert {
  /** The account's own level; null: the operator's (`defaultAt`). Zero: no low-balance email. */
  lowBalanceAt: string | null;
  /** The operator's level; null: none. */
  defaultAt: string | null;
  /** The signed-in member may choose, and gets the emails. */
  mayChange: boolean;
}

/** `open`: waiting for the payer, the gateway or staff; `review`: paid, but not what was asked — staff decide. */
export type PaymentStatus = "open" | "paid" | "review" | "failed" | "canceled" | "expired";

/** One of the current account's payments (Console → Wallet). */
export interface ConsolePayment {
  id: string;
  amount: string;
  currency: string;
  status: PaymentStatus;
  gatewayName: string;
  refunded: string;
  /** An open bank transfer's instructions. */
  instructions: string | null;
  /** The reference to quote on a bank transfer; null for an online payment. */
  reference: string | null;
  createdAt: string;
  paidAt: string | null;
}

/** How the current account can add credit (getTopUp). */
export interface TopUp {
  inForce: boolean;
  currency: string | null;
  decimals: number;
  minAmount: string | null;
  maxAmount: string | null;
  /** Why a top-up cannot be started now. */
  paused: string | null;
  /** `asks`: what this gateway needs of this person that their account lacks, for this payment only. */
  gateways: { id: string; name: string; online: boolean; methods: string[]; asks: PayerField[] }[];
  payments: ConsolePayment[];
  pollSeconds: number;
}

export type PayerField = "email";

export type PayerDetails = Partial<Record<PayerField, string>>;

/** Where the payer goes: open `url`, post `fields` to `url`, or follow `instructions` quoting `reference`. */
export interface Checkout {
  kind: "redirect" | "post" | "instructions";
  url: string | null;
  fields: Record<string, string>;
  instructions: string | null;
  reference: string | null;
}

export interface PaymentRules {
  minAmount: string | null;
  maxAmount: string | null;
  disputeAction: string;
}

export interface Option {
  value: string;
  label: string;
}

/** A way to pay, as Admin → Payments shows it. */
export interface PaymentGateway {
  id: string;
  driver: string;
  driverName: string;
  name: string;
  mode: "test" | "live";
  online: boolean;
  /** What it needs of the signed-in person that their account lacks, for a test payment. */
  asks: PayerField[];
  enabled: boolean;
  methods: string[];
  plans: string[] | null;
  /** False: it does not take the kit's currency, so it is offered to no one. */
  takesCurrency: boolean;
  webhookUrl: string | null;
  webhookEvents: string[];
  /** Null: waiting for its first verified event. */
  lastEventAt: string | null;
  fields: SetupField[];
}

export interface PaymentGatewayInput {
  name?: string;
  mode?: "test" | "live";
  methods?: string[];
  plans?: string[] | null;
  enabled?: boolean;
  values?: Record<string, string>;
}

/** A payment, as Admin → Payments shows it (the gateway's own reference and error included). */
export interface AdminPayment {
  id: string;
  /** Null for a staff test payment. */
  customer: { id: string; name: string } | null;
  gatewayName: string;
  amount: string;
  currency: string;
  status: PaymentStatus;
  received: string | null;
  receivedCurrency: string | null;
  refunded: string;
  /** A refund failed at the bank after it was counted: its amount went back on the wallet, and the payer still has not got it. */
  refundFailed: boolean;
  dispute: "open" | "won" | "lost" | null;
  reference: string | null;
  error: string | null;
  createdAt: string;
  paidAt: string | null;
  canConfirm: boolean;
  canCancel: boolean;
  canRefund: boolean;
  partialRefunds: boolean;
}

export interface PaymentsAdmin {
  currency: string | null;
  inForce: boolean;
  settings: PaymentRules;
  disputeActions: Option[];
  maxGateways: number;
  plans: { id: string; name: string }[];
  drivers: { id: string; name: string; online: boolean; fields: SetupField[]; methods: Option[]; modes: Option[]; partialRefunds: boolean }[];
  /** In the order customers see them. */
  gateways: PaymentGateway[];
  /** Bank transfers still open, and payments to review, oldest first. */
  waiting: AdminPayment[];
  recent: AdminPayment[];
}

export interface AdminWalletEntry extends WalletEntry {
  /** What the voice platform charged the operator for the call (USD). */
  telenowCostUsd: string | null;
  /** The charge minus that cost at the charge's rate. */
  margin: string | null;
}

export interface CustomerWallet {
  currency: string | null;
  balance: string;
  plan: string | null;
  effectivePlan: string | null;
  inForce: boolean;
  spendLimitUsd: string | null;
  spendLimitAt: string | null;
  calls: { waiting: number; unpriced: number };
  entries: AdminWalletEntry[];
  total: number;
  page: number;
  perPage: number;
}

/** An account's details printed on its invoices as the customer (null: none; the account's name stands for a missing name). */
export interface BillingDetails {
  name: string | null;
  address: string | null;
  taxNumber: string | null;
}

/** Console → Invoices → billing details: every member reads them, `canEdit` (its owners and admins) may change them. */
export interface AccountBillingDetails extends BillingDetails {
  canEdit: boolean;
}

/** Admin → Invoices: what the operator prints on customers' invoices from now on. Null: none (the brand's name and support email for those two). */
export interface InvoiceSettings {
  /** Before the year and the number: "INV-" gives INV-2026-000001. */
  prefix: string | null;
  name: string | null;
  address: string | null;
  taxNumber: string | null;
  email: string | null;
  /** GST, VAT…; a tax needs both its name and its rate. */
  taxName: string | null;
  /** A percentage from 0 to 100, at most two decimals; 0: no tax. */
  taxRate: string | null;
}

/** The settings as the backend answers them, with how many digits the year's number has (the server's setting, not the operator's). */
export interface InvoiceSettingsAnswer extends InvoiceSettings {
  sequenceDigits: number;
}

/** One side of an invoice (the operator selling, the customer billed) as it was when the invoice was issued. */
export interface InvoiceParty {
  name: string;
  address: string | null;
  taxNumber: string | null;
  email: string | null;
}

/** An invoice in a list: one per payment credited to a wallet. Amounts are exact decimals in its currency, tax included. */
export interface InvoiceSummary {
  id: string;
  number: string;
  issuedAt: string;
  currency: string;
  /** The amount paid, tax included. */
  amount: string;
  /** What has been refunded of the payment since (the invoice itself never changes); null: nothing. */
  refunded: string | null;
}

/** An invoice as its printable page shows it: `net` + `tax.amount` = `amount`; no tax: `net` is the amount. */
export interface Invoice extends InvoiceSummary {
  description: string;
  net: string;
  tax: { name: string; rate: string; amount: string } | null;
  seller: InvoiceParty;
  buyer: InvoiceParty;
  /** The way to pay it was paid with. */
  paidWith: string | null;
}

/** An invoice as staff read it, with the customer it is for. */
export interface AdminInvoice extends Invoice {
  customerId: string;
}

/** A page of an account's invoices, newest first. */
export interface InvoicePage {
  invoices: InvoiceSummary[];
  page: number;
  perPage: number;
  total: number;
}

/** Console → Invoices: a page of the account's invoices, its billing details, and whether this member may change them. */
export interface AccountInvoices extends InvoicePage {
  billingDetails: BillingDetails;
  canEdit: boolean;
}

/** Admin → a customer → a page of its invoices and its billing details. */
export interface CustomerInvoiceList extends InvoicePage {
  billingDetails: BillingDetails;
}

export interface PriceListRow {
  id: string;
  name: string;
  /** In the pricing spec's format (contract/pricing/price-list.schema.json). */
  definition: Record<string, unknown>;
  customers: number;
  updatedAt: string;
}

export interface Pricing {
  currency: string | null;
  usdRate: string | null;
  defaultPlan: string | null;
  /** The balance customers get a low-balance email at unless they chose their own; null: none. */
  lowBalanceAt: string | null;
  inForce: boolean;
  lists: PriceListRow[];
}

export interface DncEntry {
  id: string;
  /** As the voice platform writes it once saved (as typed until then). */
  phoneNumber: string;
  reason: string | null;
  /** `manual`, `csv`, `opt_out` (the caller asked, on `callId`), … */
  source: string | null;
  callId: string | null;
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  createdAt: string;
}

export interface DncPage {
  entries: DncEntry[];
  total: number;
  page: number;
  perPage: number;
}

/** An exact decimal amount in the operator's currency. */
export interface Price {
  amount: string;
  currency: string;
}

export interface AvailableNumber {
  /** E.164, with its +. */
  number: string;
  country: string | null;
  region: string | null;
  type: string | null;
  voice: boolean;
  sms: boolean;
  /** Charged once, when it is bought (null: not priced, or customers are not charged). */
  setupPrice: Price | null;
  /** Charged when it is bought, then every month. */
  monthlyPrice: Price | null;
}

export interface PhoneNumber {
  id: string;
  number: string;
  country: string | null;
  region: string | null;
  type: string | null;
  /** The agent that answers calls to it. */
  agent: { id: string; name: string } | null;
  monthlyPrice: Price | null;
  nextRenewalAt: string | null;
  /** Released; listed until the voice platform has it back. */
  releasing: boolean;
  /** Calls to it follow the account's call routing rules (setNumberRouting). */
  routed: boolean;
  /** Its agent's calls come from it when they name no number (setNumberCallerId). */
  agentCallerId: boolean;
  /** What a call to it does while no agent answers it (setNumberUnanswered). */
  unanswered: Unanswered;
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
  createdAt: string;
}

/**
 * What a call to a number does while no agent answers it: `message`, the caller hears that no one is available, then the call ends (the call
 * is answered); `busy`, a busy line; `unavailable`, the line is unavailable. Neither of the last two answers the call.
 */
export type Unanswered = "message" | "busy" | "unavailable";

/** A number the voice platform gave up for the account that can still be got back (contract LostNumber); never one the account released. */
export interface LostNumber {
  /** The released number (getLostNumberBack). */
  id: string;
  /** E.164, with its +. */
  number: string;
  releasedAt: string | null;
  /** It can be got back until then. */
  until: string | null;
  /** When the account asked for it back, while it is being got back by hand. */
  askedAt: string | null;
  /** `unpaid`: given up when its rent was not paid; `other`: for another reason of the platform's. */
  reason: "unpaid" | "other";
  /** The agent that answered it then (null when none, or since deleted). */
  agent: { id: string; name: string } | null;
  /** It was that agent's caller id. */
  wasCallerId: boolean;
  /** Getting it back costs its setup and a month's rent on the account's plan (null while charging is off, or when the plan cannot price it). */
  setupPrice: Price | null;
  monthlyPrice: Price | null;
}

/** What getting a lost number back did: back at once as a new number set up as the old one, or being got back by hand until `until`. */
export type GetBackOutcome = { outcome: "restored"; number: PhoneNumber } | { outcome: "requested"; until: string | null };

/** One number's next rent (NumberRenewals `numbers`). */
export interface NumberRenewal {
  id: string;
  number: string;
  renewsAt: string;
  /** Null while charging is off, or when the plan cannot price it. */
  rent: Price | null;
  /** `short`: the balance does not cover it once every renewal before it is charged too; `soon`: it renews within `soonDays`. */
  status: "ok" | "soon" | "short";
  /** With `short`: the running shortfall up to this renewal — what to add to cover it and every one before it. */
  shortBy: Price | null;
}

/** Whether the balance covers each number's next rent, in renewal order (contract NumberRenewals). Rent, balance and `short` only while `charging`. */
export interface NumberRenewals {
  charging: boolean;
  balance: Price | null;
  soonDays: number;
  numbers: NumberRenewal[];
}

/**
 * What a call routing rule matches (a list left empty: any call) and what it does (contract RoutingRuleInput). Days are ISO (1 = Monday); hours
 * run from `from` until `until` (not included), past midnight when `until` comes first. Days or hours need a timezone. A rule that refuses keeps
 * no agent, language, greeting or cap.
 */
export interface RoutingRuleInput {
  name?: string;
  enabled?: boolean;
  numberIds?: string[];
  /** Lines that forwarded the call, E.164. */
  forwardedFrom?: string[];
  /** How the caller's number starts: + and digits (+44), or a whole number. */
  callerStartsWith?: string[];
  days?: number[];
  from?: string | null;
  until?: string | null;
  /** An IANA timezone. */
  timezone?: string | null;
  action?: "answer" | "refuse";
  /** One of the account's saved agents; null: the number's own. */
  agentId?: string | null;
  /** A language code (en-US, hi-IN) for the call. */
  language?: string | null;
  /** The agent's first line on the call. */
  greeting?: string | null;
  /** The call's longest, when shorter than the agent's own. */
  maxMinutes?: number | null;
}

/** A call routing rule as saved (contract RoutingRule). */
export interface RoutingRule {
  id: string;
  name: string;
  enabled: boolean;
  numberIds: string[];
  forwardedFrom: string[];
  callerStartsWith: string[];
  days: number[];
  from: string | null;
  until: string | null;
  timezone: string | null;
  action: "answer" | "refuse";
  /** The agent that takes the call; null: the number's own. */
  agent: { id: string; name: string } | null;
  /** Its agent was deleted: the number's own takes the call. */
  agentGone: boolean;
  language: string | null;
  greeting: string | null;
  maxMinutes: number | null;
}

/** Why the voice platform had no answer from this server (contract RoutingProblem), in the kit's words, never the platform's. */
export type RoutingProblem = "off" | "not_connected" | "slow" | "refused" | "unreachable" | "bad_answer" | "address" | "other" | null;

/** One of the account's numbers that can take calls, as call routing shows it. */
export interface RoutingNumber {
  id: string;
  number: string;
  agent: { id: string; name: string } | null;
  routed: boolean;
  syncState: "pending" | "synced" | "failed";
  syncError: string | null;
}

/** Whether the voice platform asks this server about calls to the account's routed numbers. */
export interface RoutingConnection {
  /** `elsewhere`: it asks another address; `unknown`: the platform was not reached just now. */
  state: "connected" | "off" | "elsewhere" | "not_connected" | "unknown";
  lastAnsweredAt: string | null;
  problem: RoutingProblem;
  problemAt: string | null;
}

/** The backend's limits on call routing rules. */
export interface RoutingLimits {
  rulesMax: number;
  /** The most numbers, forwarded-from lines or caller starts one rule may list (each). */
  numbersPerRule: number;
  nameMaxLength: number;
  greetingMaxLength: number;
  languageMaxLength: number;
  maxMinutes: number;
}

/** Console → Call routing (getRouting). */
export interface RoutingPage {
  /** In the order they are tried. */
  rules: RoutingRule[];
  numbers: RoutingNumber[];
  /** Null until a number was first routed. */
  connection: RoutingConnection | null;
  /** Whether this member may change call routing (the account's managers). */
  canManage: boolean;
  limits: RoutingLimits;
}

/** A pretend call to try against the rules as saved (tryRouting); `at` an ISO date-time, now when left out. */
export interface RoutingTryInput {
  numberId: string;
  callerNumber?: string | null;
  forwardedFrom?: string | null;
  at?: string | null;
}

/** What would happen to a pretend call (tryRouting). */
export interface RoutingTry {
  /** The rule that takes it; null: none does, the number's own agent answers. */
  rule: { id: string; name: string } | null;
  outcome: "answer" | "refuse" | "own_agent";
  /** Who takes the call (none for a refusal). */
  agent: { id: string; name: string } | null;
  language: string | null;
  greeting: string | null;
  maxMinutes: number | null;
  /** Whether calls to the number follow the rules yet. */
  routed: boolean;
}

/** The voice platform's own test of a routed number, through this server (checkRouting). */
export interface RoutingCheck {
  answered: boolean;
  elapsedMs: number;
  action: "answer" | "refuse" | null;
  problem: RoutingProblem;
}

/** A ready-made agent the operator offers every account (Admin → Starter agents; listStarterAgents). */
export interface StarterAgent {
  id: string;
  name: string;
  description: string | null;
  offered: boolean;
  /** How many call steps it carries. */
  steps: number;
  /** The agent and account it was made from, as they were named then. */
  from: { agent: string | null; account: string | null };
  publishedBy: string | null;
  publishedAt: string;
}

/** An account the signed-in staff member owns, with its agents: what a starter may be published from. */
export interface StarterSource {
  id: string;
  name: string;
  agents: { id: string; name: string }[];
}

export interface StarterAgentList {
  starters: StarterAgent[];
  sources: StarterSource[];
}

export type StarterTextWhere = "description" | "prompt" | "setting" | "analysis" | "step" | "exit";

export type StarterLeftOutKind = "ownVoice" | "tools" | "lookups" | "handoff" | "transfer" | "emailAfterCall" | "knowledge" | "webhooks" | "steps";

/**
 * What a starter made from an agent would carry (previewStarterAgent): every piece of its free text in full, where it is
 * (`key`: a setting's or analysis field's key, a step's `name` / `instructions` / `line`, an exit's `label` / `when`),
 * and what of the agent it leaves out (`steps`: the tool, hand-over and transfer steps made endings).
 */
export interface StarterAgentPreview {
  agent: { id: string; name: string; account: string };
  texts: { where: StarterTextWhere; key: string | null; of: string | null; text: string }[];
  leftOut: { kind: StarterLeftOutKind; count: number; names: string[] }[];
  steps: number;
}

/** A starter as a customer's account is offered it (listOfferedStarterAgents). */
export interface OfferedStarter {
  id: string;
  name: string;
  description: string | null;
}

export class KitApiError extends Error {
  constructor(
    readonly status: number,
    message: string,
    /** For a 422: the refused fields, each with its reasons. */
    readonly fields: Record<string, string[]> = {},
  ) {
    super(message);
  }
}

function cookie(name: string): string | null {
  const hit = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
}

async function call<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { accept: "application/json" };
  if (body !== undefined) headers["content-type"] = "application/json";
  const xsrf = method === "GET" ? null : cookie("XSRF-TOKEN");
  if (xsrf) headers["x-xsrf-token"] = xsrf;
  const r = await fetch(path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  if (r.status === 204) return undefined as T;
  const data = (await r.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
  if (!r.ok) throw new KitApiError(r.status, data?.message ?? `${path} answered ${r.status}`, data?.errors ?? {});
  return data as T;
}

/** A multipart write (a file): the browser sets the boundary; a refusal is a KitApiError like any call's. */
async function send<T>(path: string, form: FormData): Promise<T> {
  const headers: Record<string, string> = { accept: "application/json" };
  const xsrf = cookie("XSRF-TOKEN");
  if (xsrf) headers["x-xsrf-token"] = xsrf;
  const r = await fetch(path, { method: "POST", headers, body: form });
  const data = (await r.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
  if (!r.ok) throw new KitApiError(r.status, data?.message ?? `${path} answered ${r.status}`, data?.errors ?? {});
  return data as T;
}

/** A short audio clip the backend makes on demand (a voice or an agent heard); a refusal is a KitApiError like any call's. */
async function clip(path: string, body: unknown): Promise<Blob> {
  const headers: Record<string, string> = { accept: "audio/*, application/json", "content-type": "application/json" };
  const xsrf = cookie("XSRF-TOKEN");
  if (xsrf) headers["x-xsrf-token"] = xsrf;
  const r = await fetch(path, { method: "POST", headers, body: JSON.stringify(body) });
  if (!r.ok) {
    const data = (await r.json().catch(() => null)) as { message?: string; errors?: Record<string, string[]> } | null;
    throw new KitApiError(r.status, data?.message ?? `${path} answered ${r.status}`, data?.errors ?? {});
  }
  return r.blob();
}

/** A query string of the values given, empty ones left out, with its `?`; "" when nothing is left. */
function query(values: Record<string, string | undefined>): string {
  const text = new URLSearchParams(Object.entries(values).filter((e): e is [string, string] => !!e[1])).toString();
  return text ? `?${text}` : "";
}

/** A bot check's token, sent only when there is one (contract/kit-api.yaml `captchaToken`). */
function withToken(captchaToken?: string | null): { captchaToken?: string } {
  return captchaToken ? { captchaToken } : {};
}

function voiceQuery(f: VoiceFilters): string {
  // A switch as 1 or 0: the backend's boolean rule takes those, not the words.
  return new URLSearchParams(Object.entries(f).filter(([, v]) => v !== undefined && v !== "" && v !== false).map(([k, v]) => [k, typeof v === "boolean" ? (v ? "1" : "0") : String(v)])).toString();
}

export const kitApi = {
  health: () => call<Health>("GET", "/api/kit/health"),
  brand: () => call<Brand>("GET", "/api/kit/brand"),
  setup: () => call<SetupState>("GET", "/api/kit/setup"),
  submitStep: (id: string, values: Record<string, string>) =>
    call<SetupState>("POST", `/api/kit/setup/steps/${encodeURIComponent(id)}`, values),
  session: () => call<{ user: User | null; viewingAs?: ViewingAs | null }>("GET", "/api/kit/session"),
  stopViewingAs: () => call<{ customerId: string | null }>("POST", "/api/kit/session/view-as/stop", {}),
  signIn: (email: string, password: string, captchaToken?: string | null) =>
    call<{ user: User | null }>("POST", "/api/kit/session", { email, password, ...withToken(captchaToken) }),
  signOut: () => call<void>("DELETE", "/api/kit/session"),
  forgotPassword: (email: string, captchaToken?: string | null) =>
    call<{ message: string }>("POST", "/api/kit/password/forgot", { email, ...withToken(captchaToken) }),
  passwordReset: (token: string) => call<{ email: string; expiresAt: string }>("GET", `/api/kit/password/reset/${encodeURIComponent(token)}`),
  signupForm: () => call<SignupForm>("GET", "/api/kit/signup"),
  signUp: (values: Record<string, unknown>, captchaToken?: string | null) =>
    call<{ message: string }>("POST", "/api/kit/signup", { ...values, ...withToken(captchaToken) }),
  signupVerification: (token: string) =>
    call<{ email: string; name: string; accountName: string }>("GET", `/api/kit/signup/verify/${encodeURIComponent(token)}`),
  verifySignup: (token: string) => call<SignupConfirmed>("POST", `/api/kit/signup/verify/${encodeURIComponent(token)}`, {}),
  verifyEmailSignup: (email: string, code: string) => call<SignupConfirmed>("POST", "/api/kit/signup/email/verify", { email, code }),
  signUpByPhone: (values: Record<string, unknown>, captchaToken?: string | null) =>
    call<{ message: string }>("POST", "/api/kit/signup/phone", { ...values, ...withToken(captchaToken) }),
  verifyPhoneSignup: (phone: string, code: string) => call<SignupConfirmed>("POST", "/api/kit/signup/phone/verify", { phone, code }),
  providerSignup: () => call<ProviderSignup>("GET", "/api/kit/signup/provider"),
  signUpWithProvider: (values: Record<string, unknown>, captchaToken?: string | null) =>
    call<SignupConfirmed>("POST", "/api/kit/signup/provider", { ...values, ...withToken(captchaToken) }),
  requestSignInLink: (email: string, captchaToken?: string | null) =>
    call<{ message: string }>("POST", "/api/kit/session/link", { email, ...withToken(captchaToken) }),
  useSignInLink: (token: string) => call<{ user: User | null }>("POST", `/api/kit/session/link/${encodeURIComponent(token)}`, {}),
  requestEmailCode: (email: string, captchaToken?: string | null) =>
    call<{ message: string }>("POST", "/api/kit/session/email-code", { email, ...withToken(captchaToken) }),
  useEmailCode: (email: string, code: string) => call<{ user: User | null }>("POST", "/api/kit/session/email-code/verify", { email, code }),
  requestSignInCode: (phone: string, captchaToken?: string | null) =>
    call<{ message: string }>("POST", "/api/kit/session/code", { phone, ...withToken(captchaToken) }),
  useSignInCode: (phone: string, code: string) => call<{ user: User | null }>("POST", "/api/kit/session/code/verify", { phone, code }),
  account: () => call<Account>("GET", "/api/kit/account"),
  requestPhoneCode: (phone: string) => call<{ message: string }>("POST", "/api/kit/account/phone", { phone }),
  verifyPhone: (phone: string, code: string) => call<Account>("POST", "/api/kit/account/phone/verify", { phone, code }),
  removePhone: () => call<Account>("DELETE", "/api/kit/account/phone"),
  resetPassword: (token: string, password: string) =>
    call<{ user: User | null }>("POST", `/api/kit/password/reset/${encodeURIComponent(token)}`, { password }),
  customers: (page = 1) => call<CustomerPage>("GET", `/api/kit/admin/customers?page=${page}`),
  createCustomer: (name: string) => call<Customer>("POST", "/api/kit/admin/customers", { name }),
  customer: (id: string) => call<Customer>("GET", `/api/kit/admin/customers/${encodeURIComponent(id)}`),
  suspendCustomer: (id: string, reason?: string) =>
    call<Customer>("POST", `/api/kit/admin/customers/${encodeURIComponent(id)}/suspend`, reason ? { reason } : {}),
  resumeCustomer: (id: string) => call<Customer>("POST", `/api/kit/admin/customers/${encodeURIComponent(id)}/resume`, {}),
  retryCustomer: (id: string) => call<Customer>("POST", `/api/kit/admin/customers/${encodeURIComponent(id)}/retry`, {}),
  customerTeam: (id: string) => call<Team>("GET", `/api/kit/admin/customers/${encodeURIComponent(id)}/team`),
  inviteCustomerMember: (id: string, email: string, role: string) =>
    call<InviteCreated>("POST", `/api/kit/admin/customers/${encodeURIComponent(id)}/team/invites`, { email, role }),
  revokeCustomerInvite: (id: string, inviteId: string) =>
    call<void>("DELETE", `/api/kit/admin/customers/${encodeURIComponent(id)}/team/invites/${encodeURIComponent(inviteId)}`),
  removeCustomerMember: (id: string, userId: number) =>
    call<void>("DELETE", `/api/kit/admin/customers/${encodeURIComponent(id)}/team/members/${userId}`),
  customerWallet: (id: string, page = 1) => call<CustomerWallet>("GET", `/api/kit/admin/customers/${encodeURIComponent(id)}/wallet?page=${page}`),
  addCustomerCredit: (id: string, body: { amount: string; note: string | null; reference: string }) =>
    call<AdminWalletEntry>("POST", `/api/kit/admin/customers/${encodeURIComponent(id)}/wallet/credits`, body),
  setCustomerPlan: (id: string, priceListId: string | null) =>
    call<CustomerWallet>("PUT", `/api/kit/admin/customers/${encodeURIComponent(id)}/plan`, { priceListId }),
  pricing: () => call<Pricing>("GET", "/api/kit/admin/pricing"),
  setLowBalanceDefault: (lowBalanceAt: string | null) => call<Pricing>("PUT", "/api/kit/admin/pricing/low-balance", { lowBalanceAt }),
  updatePricing: (body: { currency: string; usdRate: string; defaultPlan: string | null }) => call<Pricing>("PUT", "/api/kit/admin/pricing", body),
  savePriceList: (id: string, name: string, definition: unknown) =>
    call<PriceListRow>("PUT", `/api/kit/admin/pricing/lists/${encodeURIComponent(id)}`, { name, definition }),
  deletePriceList: (id: string) => call<void>("DELETE", `/api/kit/admin/pricing/lists/${encodeURIComponent(id)}`),
  invite: (token: string) => call<InviteInfo>("GET", `/api/kit/invites/${encodeURIComponent(token)}`),
  acceptInvite: (token: string, body: { name?: string; password?: string }) =>
    call<{ user: User | null }>("POST", `/api/kit/invites/${encodeURIComponent(token)}/accept`, body),
  myWorkspaces: () => call<WorkspaceList>("GET", "/api/kit/console/workspaces"),
  selectWorkspace: (id: string) => call<WorkspaceList>("POST", "/api/kit/console/workspace", { id }),
  consoleHome: () => call<ConsoleHome>("GET", "/api/kit/console/home"),
  team: () => call<Team>("GET", "/api/kit/console/team"),
  inviteTeamMember: (email: string, role: string) => call<InviteCreated>("POST", "/api/kit/console/team/invites", { email, role }),
  revokeTeamInvite: (inviteId: string) => call<void>("DELETE", `/api/kit/console/team/invites/${encodeURIComponent(inviteId)}`),
  removeTeamMember: (userId: number) => call<void>("DELETE", `/api/kit/console/team/members/${userId}`),
  agents: (page = 1) => call<AgentPage>("GET", `/api/kit/console/agents?page=${page}`),
  createAgent: (input: AgentInput & { name: string }) => call<Agent>("POST", "/api/kit/console/agents", input),
  offeredStarterAgents: () => call<{ starters: OfferedStarter[] }>("GET", "/api/kit/console/starters"),
  createAgentFromStarter: (starterId: string) =>
    call<{ agent: Agent; toRedo: AgentCopyToDo[] }>("POST", `/api/kit/console/starters/${encodeURIComponent(starterId)}/agents`),
  agent: (id: string) => call<Agent>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}`),
  agentSettingsForm: () => call<AgentSettingsForm>("GET", "/api/kit/console/agents/settings-form"),
  agentFlow: (id: string) => call<AgentFlowState>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/flow`),
  saveAgentFlowDraft: (id: string, flow: AgentFlowGraph) => call<AgentFlowState>("PUT", `/api/kit/console/agents/${encodeURIComponent(id)}/flow/draft`, { flow }),
  discardAgentFlowDraft: (id: string) => call<AgentFlowState>("DELETE", `/api/kit/console/agents/${encodeURIComponent(id)}/flow/draft`),
  checkAgentFlow: (id: string, flow: AgentFlowGraph) => call<{ warnings: FlowWarning[] }>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/flow/check`, { flow }),
  publishAgentFlow: (id: string, flow: AgentFlowGraph) => call<AgentFlowState>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/flow/publish`, { flow }),
  unpublishAgentFlow: (id: string) => call<AgentFlowState>("DELETE", `/api/kit/console/agents/${encodeURIComponent(id)}/flow`),
  agentVersions: (id: string) => call<{ versions: AgentVersion[]; lastRestore: { version: number; kept: string[] } | null }>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/versions`),
  agentVersionChanges: (id: string, version: number) => call<{ changes: AgentVersionChange[] }>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/versions/${version}/changes`),
  agentVersionResults: (id: string, version: number) => call<{ current: AgentVersionWindow; previous: AgentVersionWindow | null }>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/versions/${version}/results`),
  restoreAgentVersion: (id: string, version: number) => call<Agent>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/versions/${version}/restore`),
  agentWebhooks: (id: string) => call<{ webhooks: AgentWebhook[]; events: string[] }>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/webhooks`),
  addAgentWebhook: (id: string, input: { url: string; events: string[]; includeTranscript: boolean }) => call<AgentWebhook>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/webhooks`, input),
  removeAgentWebhook: (id: string, webhookId: string) => call<void>("DELETE", `/api/kit/console/agents/${encodeURIComponent(id)}/webhooks/${encodeURIComponent(webhookId)}`),
  testAgentWebhook: (id: string, webhookId: string, event: string) => call<{ deliveries: AgentWebhookDelivery[] }>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/webhooks/${encodeURIComponent(webhookId)}/test`, { event }),
  agentWebhookDeliveries: (id: string, webhookId: string) => call<{ deliveries: AgentWebhookDelivery[] }>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/webhooks/${encodeURIComponent(webhookId)}/deliveries`),
  replayAgentWebhookDelivery: (id: string, webhookId: string, deliveryId: string) => call<{ deliveries: AgentWebhookDelivery[] }>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/webhooks/${encodeURIComponent(webhookId)}/deliveries/${encodeURIComponent(deliveryId)}/replay`, {}),
  agentWebChat: (id: string) => call<WebChat>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/web-chat`),
  updateAgentWebChat: (id: string, input: WebChatInput) => call<WebChat>("PUT", `/api/kit/console/agents/${encodeURIComponent(id)}/web-chat`, input),
  updateAgent: (id: string, input: AgentInput) => call<Agent>("PATCH", `/api/kit/console/agents/${encodeURIComponent(id)}`, input),
  deleteAgent: (id: string) => call<void>("DELETE", `/api/kit/console/agents/${encodeURIComponent(id)}`),
  retryAgent: (id: string) => call<Agent>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/retry`, {}),
  duplicateAgent: (id: string) => call<{ agent: Agent; toRedo: AgentCopyToDo[] }>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/duplicate`),
  startTestCall: (id: string) => call<{ session: TestSession }>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/test-call`, {}),
  agentCallForm: (id: string) => call<AgentCallForm>("GET", `/api/kit/console/agents/${encodeURIComponent(id)}/call-form`),
  checkPrompt: (id: string, input: PromptCheckInput) => call<PromptCheck>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/prompt-check`, input),
  checkNewPrompt: (input: PromptCheckInput) => call<PromptCheck>("POST", "/api/kit/console/agents/prompt-check", input),
  setAgentLogo: (id: string, file: File) => {
    const form = new FormData();
    form.append("file", file);
    return send<Agent>(`/api/kit/console/agents/${encodeURIComponent(id)}/logo`, form);
  },
  removeAgentLogo: (id: string) => call<Agent>("DELETE", `/api/kit/console/agents/${encodeURIComponent(id)}/logo`),
  callHealth: (minutes?: number) => call<CallHealth>("GET", `/api/kit/console/call-health${query({ minutes: minutes === undefined ? undefined : String(minutes) })}`),
  placeAgentCall: (id: string, input: AgentCallInput) => call<{ callId: string }>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/calls`, input),
  wallet: (page = 1) => call<Wallet>("GET", `/api/kit/console/wallet?page=${page}`),
  setWalletAlert: (lowBalanceAt: string | null) => call<Wallet>("PUT", "/api/kit/console/wallet/alert", { lowBalanceAt }),
  topUp: () => call<TopUp>("GET", "/api/kit/console/payments"),
  startTopUp: (gatewayId: string, amount: string, payer?: PayerDetails) =>
    call<{ payment: ConsolePayment; checkout: Checkout }>("POST", "/api/kit/console/payments", { gatewayId, amount, ...(payer ? { payer } : {}) }),
  payment: (id: string) => call<ConsolePayment>("GET", `/api/kit/console/payments/${encodeURIComponent(id)}`),
  accountInvoices: (page = 1) => call<AccountInvoices>("GET", `/api/kit/console/invoices${query({ page: page > 1 ? String(page) : undefined })}`),
  accountInvoice: (id: string) => call<Invoice>("GET", `/api/kit/console/invoices/${encodeURIComponent(id)}`),
  billingDetails: () => call<AccountBillingDetails>("GET", "/api/kit/console/billing-details"),
  setBillingDetails: (d: BillingDetails) =>
    call<AccountBillingDetails>("PUT", "/api/kit/console/billing-details", { name: d.name, address: d.address, taxNumber: d.taxNumber }),
  dnc: (page = 1, search = "") =>
    call<DncPage>("GET", `/api/kit/console/dnc?${new URLSearchParams({ page: String(page), ...(search ? { search } : {}) })}`),
  addDnc: (phoneNumber: string, reason: string | null) => call<DncEntry>("POST", "/api/kit/console/dnc", { phoneNumber, reason }),
  importDnc: (csv: string) => call<{ added: number; skipped: number }>("POST", "/api/kit/console/dnc/import", { csv }),
  removeDnc: (id: string) => call<void>("DELETE", `/api/kit/console/dnc/${encodeURIComponent(id)}`),
  catalogCuration: () => call<CatalogCuration>("GET", "/api/kit/admin/catalog"),
  saveCatalogCuration: (body: CurationInput) => call<CatalogCuration>("PUT", "/api/kit/admin/catalog", body),
  refreshCatalog: () => call<CatalogCuration>("POST", "/api/kit/admin/catalog/refresh", {}),
  catalogVoices: (f: VoiceFilters = {}) => call<VoicePage>("GET", `/api/kit/admin/catalog/voices?${voiceQuery(f)}`),
  openModels: (search = "", page = 1) =>
    call<OpenModelPage>("GET", `/api/kit/admin/catalog/open-models${query({ search, page: page > 1 ? String(page) : undefined })}`),
  openModelPrice: (model: string) => call<OpenModelPrice>("GET", `/api/kit/admin/catalog/open-models/price${query({ model })}`),
  starterAgents: () => call<StarterAgentList>("GET", "/api/kit/admin/starters"),
  previewStarterAgent: (agentId: string) => call<StarterAgentPreview>("GET", `/api/kit/admin/starters/preview${query({ agentId })}`),
  publishStarterAgent: (input: { agentId: string; name: string; description: string | null; reviewed: true }) =>
    call<StarterAgent>("POST", "/api/kit/admin/starters", input),
  updateStarterAgent: (starterId: string, input: { name?: string; description?: string | null; offered?: boolean }) =>
    call<StarterAgent>("PATCH", `/api/kit/admin/starters/${encodeURIComponent(starterId)}`, input),
  emailSettings: () => call<EmailSettings>("GET", "/api/kit/admin/email"),
  saveEmailSite: (body: { siteUrl: string; fromAddress: string; fromName: string | null }) => call<EmailSettings>("PUT", "/api/kit/admin/email", body),
  addEmailSender: (driver: string, fields: Record<string, string>) => call<EmailSettings>("POST", "/api/kit/admin/email/senders", { ...fields, driver }),
  updateEmailSender: (id: string, fields: Record<string, string>) =>
    call<EmailSettings>("PUT", `/api/kit/admin/email/senders/${encodeURIComponent(id)}`, fields),
  deleteEmailSender: (id: string) => call<void>("DELETE", `/api/kit/admin/email/senders/${encodeURIComponent(id)}`),
  orderEmailSenders: (ids: string[]) => call<EmailSettings>("PUT", "/api/kit/admin/email/order", { ids }),
  sendTestEmail: () => call<OutgoingEmail>("POST", "/api/kit/admin/email/test", {}),
  emailTemplates: () => call<{ templates: EmailTemplate[] }>("GET", "/api/kit/admin/email/templates"),
  saveEmailTemplate: (kind: string, texts: EmailTemplateTexts) =>
    call<EmailTemplate>("PUT", `/api/kit/admin/email/templates/${encodeURIComponent(kind)}`, texts),
  resetEmailTemplate: (kind: string) => call<EmailTemplate>("DELETE", `/api/kit/admin/email/templates/${encodeURIComponent(kind)}`),
  previewEmailTemplate: (kind: string, texts: EmailTemplateTexts) =>
    call<EmailPreview>("POST", `/api/kit/admin/email/templates/${encodeURIComponent(kind)}/preview`, texts),
  captchaSettings: () => call<CaptchaSettings>("GET", "/api/kit/admin/captcha"),
  saveCaptchaSettings: (body: { provider: string | null; siteKey: string | null; secret: string; forms: string[] }) =>
    call<CaptchaSettings>("PUT", "/api/kit/admin/captcha", body),
  dashboard: (from?: string, to?: string) => call<AdminDashboard>("GET", `/api/kit/admin/dashboard${query({ from, to })}`),
  costReport: (by: string, from?: string, to?: string, page = 1, customer?: string) =>
    call<CostReport>("GET", `/api/kit/admin/costs${query({ by, from, to, page: page > 1 ? String(page) : undefined, customer })}`),
  platformBilling: () => call<PlatformBilling>("GET", "/api/kit/admin/platform-billing"),
  setPlatformBalanceAlert: (thresholdUsd: string | null) =>
    call<PlatformBillingState>("PUT", "/api/kit/admin/platform-billing/alert", { thresholdUsd }),
  invoiceSettings: () => call<InvoiceSettingsAnswer>("GET", "/api/kit/admin/invoice-settings"),
  setInvoiceSettings: (s: InvoiceSettings) =>
    call<InvoiceSettingsAnswer>("PUT", "/api/kit/admin/invoice-settings", {
      prefix: s.prefix, name: s.name, address: s.address, taxNumber: s.taxNumber, email: s.email, taxName: s.taxName, taxRate: s.taxRate,
    }),
  customerInvoices: (id: string, page = 1) =>
    call<CustomerInvoiceList>("GET", `/api/kit/admin/customers/${encodeURIComponent(id)}/invoices${query({ page: page > 1 ? String(page) : undefined })}`),
  customerInvoice: (id: string) => call<AdminInvoice>("GET", `/api/kit/admin/invoices/${encodeURIComponent(id)}`),
  setCustomerLimits: (id: string, body: { maxConcurrentCalls: number | null; monthlyCapUsd: string | null }) =>
    call<Customer>("PUT", `/api/kit/admin/customers/${encodeURIComponent(id)}/limits`, body),
  closeCustomer: (id: string, confirm: string) => call<Customer>("POST", `/api/kit/admin/customers/${encodeURIComponent(id)}/close`, { confirm }),
  viewAsCustomer: (id: string) => call<{ viewingAs: ViewingAs }>("POST", `/api/kit/admin/customers/${encodeURIComponent(id)}/view-as`, {}),
  staff: () => call<StaffList>("GET", "/api/kit/admin/staff"),
  inviteStaff: (email: string, role: string) =>
    call<{ invite: StaffInvite; token: string | null; emailed: boolean }>("POST", "/api/kit/admin/staff/invites", { email, role }),
  revokeStaffInvite: (id: string) => call<void>("DELETE", `/api/kit/admin/staff/invites/${encodeURIComponent(id)}`),
  updateStaffRole: (userId: number, role: string) => call<User>("PUT", `/api/kit/admin/staff/${userId}`, { role }),
  removeStaff: (userId: number) => call<void>("DELETE", `/api/kit/admin/staff/${userId}`),
  auditLog: (page = 1) => call<AuditPage>("GET", `/api/kit/admin/audit${query({ page: page > 1 ? String(page) : undefined })}`),
  staffInvite: (token: string) => call<StaffInviteInfo>("GET", `/api/kit/staff-invites/${encodeURIComponent(token)}`),
  acceptStaffInvite: (token: string, body: { name?: string; password: string }) =>
    call<{ user: User | null }>("POST", `/api/kit/staff-invites/${encodeURIComponent(token)}/accept`, body),
  voicePlatform: () => call<VoicePlatformHealth>("GET", "/api/kit/admin/telenow"),
  retryVoicePlatformWrite: (id: number) => call<VoicePlatformHealth>("POST", `/api/kit/admin/telenow/writes/${id}/retry`, {}),
  retryVoicePlatformEvent: (id: number) => call<VoicePlatformHealth>("POST", `/api/kit/admin/telenow/events/${id}/retry`, {}),
  reconcileCustomer: (customerId: string) => call<VoicePlatformHealth>("POST", `/api/kit/admin/telenow/reconcile/${encodeURIComponent(customerId)}`, {}),
  platformProcessingCountry: (fresh = false) =>
    call<ProcessingCountry>("GET", `/api/kit/admin/telenow/processing-country${query({ fresh: fresh ? "1" : undefined })}`),
  customerProcessingCountry: (id: string, fresh = false) =>
    call<ProcessingCountry>("GET", `/api/kit/admin/customers/${encodeURIComponent(id)}/processing-country${query({ fresh: fresh ? "1" : undefined })}`),
  customerCallHealth: (id: string, minutes?: number) =>
    call<CallHealth>("GET", `/api/kit/admin/customers/${encodeURIComponent(id)}/call-health${query({ minutes: minutes === undefined ? undefined : String(minutes) })}`),
  websiteSettings: () => call<WebsiteSettings>("GET", "/api/kit/admin/website"),
  saveWebsiteSettings: (content: WebsiteContent) => call<WebsiteSettings>("PUT", "/api/kit/admin/website", content),
  ssoSettings: () => call<SsoSettings>("GET", "/api/kit/admin/sso"),
  addSsoProvider: (input: SsoProviderInput) => call<SsoSettings>("POST", "/api/kit/admin/sso/providers", input),
  updateSsoProvider: (id: string, input: SsoProviderInput) => call<SsoSettings>("PUT", `/api/kit/admin/sso/providers/${encodeURIComponent(id)}`, input),
  deleteSsoProvider: (id: string) => call<void>("DELETE", `/api/kit/admin/sso/providers/${encodeURIComponent(id)}`),
  orderSsoProviders: (ids: string[]) => call<SsoSettings>("PUT", "/api/kit/admin/sso/order", { ids }),
  smsSettings: () => call<SmsSettings>("GET", "/api/kit/admin/sms"),
  addSmsSender: (driver: string, fields: Record<string, string>, testNumber: string) =>
    call<SmsSettings>("POST", "/api/kit/admin/sms/senders", { ...fields, driver, testNumber }),
  updateSmsSender: (id: string, fields: Record<string, string>, testNumber: string) =>
    call<SmsSettings>("PUT", `/api/kit/admin/sms/senders/${encodeURIComponent(id)}`, { ...fields, testNumber }),
  deleteSmsSender: (id: string) => call<void>("DELETE", `/api/kit/admin/sms/senders/${encodeURIComponent(id)}`),
  orderSmsSenders: (ids: string[]) => call<SmsSettings>("PUT", "/api/kit/admin/sms/order", { ids }),
  sendTestSms: (testNumber: string) => call<OutgoingSms>("POST", "/api/kit/admin/sms/test", { testNumber }),
  signups: () => call<SignupAdmin>("GET", "/api/kit/admin/signups"),
  saveSignupSettings: (settings: SignupSettings) => call<SignupAdmin>("PUT", "/api/kit/admin/signups/settings", settings),
  approveSignup: (id: string) => call<SignupAdmin>("POST", `/api/kit/admin/signups/${encodeURIComponent(id)}/approve`, {}),
  rejectSignup: (id: string, reason: string | null) => call<SignupAdmin>("POST", `/api/kit/admin/signups/${encodeURIComponent(id)}/reject`, { reason }),
  paymentsAdmin: () => call<PaymentsAdmin>("GET", "/api/kit/admin/payments"),
  savePaymentSettings: (rules: PaymentRules) => call<PaymentsAdmin>("PUT", "/api/kit/admin/payments/settings", rules),
  orderPaymentGateways: (ids: string[]) => call<PaymentsAdmin>("PUT", "/api/kit/admin/payments/order", { ids }),
  addPaymentGateway: (driver: string, input: PaymentGatewayInput) => call<PaymentsAdmin>("POST", "/api/kit/admin/payments/gateways", { ...input, driver }),
  updatePaymentGateway: (id: string, input: PaymentGatewayInput) =>
    call<PaymentsAdmin>("PUT", `/api/kit/admin/payments/gateways/${encodeURIComponent(id)}`, input),
  removePaymentGateway: (id: string) => call<void>("DELETE", `/api/kit/admin/payments/gateways/${encodeURIComponent(id)}`),
  testPaymentGateway: (id: string, amount: string, payer?: PayerDetails) =>
    call<{ payment: AdminPayment; checkout: Checkout }>("POST", `/api/kit/admin/payments/gateways/${encodeURIComponent(id)}/test`, { amount, ...(payer ? { payer } : {}) }),
  confirmPayment: (id: string, amount: string) => call<PaymentsAdmin>("POST", `/api/kit/admin/payments/${encodeURIComponent(id)}/confirm`, { amount }),
  cancelPayment: (id: string) => call<PaymentsAdmin>("POST", `/api/kit/admin/payments/${encodeURIComponent(id)}/cancel`, {}),
  refundPayment: (id: string, amount: string, reference: string) =>
    call<PaymentsAdmin>("POST", `/api/kit/admin/payments/${encodeURIComponent(id)}/refunds`, { amount, reference }),
  stackChoices: () => call<StackChoices>("GET", "/api/kit/console/catalog"),
  voices: (f: VoiceFilters = {}) => call<VoicePage>("GET", `/api/kit/console/voices?${voiceQuery(f)}`),
  starVoice: (provider: string, voice: string) => call<void>("PUT", "/api/kit/console/voices/stars", { provider, voice }),
  unstarVoice: (provider: string, voice: string) => call<void>("DELETE", `/api/kit/console/voices/stars?${new URLSearchParams({ provider, voice }).toString()}`),
  checkAgentStack: (id: string) => call<StackCheck>("POST", `/api/kit/console/agents/${encodeURIComponent(id)}/check`, {}),
  previewVoice: (provider: string, voice: string, text?: string) => clip("/api/kit/console/voices/preview", { provider, voice, ...(text ? { text } : {}) }),
  ownVoices: () => call<OwnVoicesPage>("GET", "/api/kit/console/own-voices"),
  createOwnVoice: (input: OwnVoiceInput) => call<OwnVoice>("POST", "/api/kit/console/own-voices", input),
  uploadOwnVoiceSample: (id: string, file: Blob, filename: string, seconds: number | null) => {
    const form = new FormData();
    form.append("file", file, filename);
    if (seconds !== null) form.append("seconds", String(Math.round(seconds * 10) / 10));
    return send<OwnVoice>(`/api/kit/console/own-voices/${encodeURIComponent(id)}/sample`, form);
  },
  cloneOwnVoice: (id: string, provider: string) => call<OwnVoice>("POST", `/api/kit/console/own-voices/${encodeURIComponent(id)}/clones`, { provider }),
  removeOwnVoiceClone: (id: string, provider: string) => call<OwnVoice>("DELETE", `/api/kit/console/own-voices/${encodeURIComponent(id)}/clones/${encodeURIComponent(provider)}`),
  deleteOwnVoice: (id: string) => call<void>("DELETE", `/api/kit/console/own-voices/${encodeURIComponent(id)}`),
  previewRealtimeVoice: (provider: string, model: string, voice: string | null) => clip("/api/kit/console/voices/realtime-preview", { provider, model, ...(voice ? { voice } : {}) }),
  previewAgentVoice: (agentId: string, text?: string) => clip(`/api/kit/console/agents/${encodeURIComponent(agentId)}/voice-preview`, text ? { text } : {}),
  campaigns: () => call<{ campaigns: Campaign[]; limits: CampaignLimits }>("GET", "/api/kit/console/campaigns"),
  createCampaign: (input: CampaignInput) => call<Campaign>("POST", "/api/kit/console/campaigns", input),
  campaign: (id: string) => call<Campaign>("GET", `/api/kit/console/campaigns/${encodeURIComponent(id)}`),
  deleteCampaign: (id: string) => call<void>("DELETE", `/api/kit/console/campaigns/${encodeURIComponent(id)}`),
  campaignTargets: (id: string, page = 1, show?: CampaignPeopleGroup) =>
    call<CampaignTargetPage>("GET", `/api/kit/console/campaigns/${encodeURIComponent(id)}/targets${query({ page: String(page), show })}`),
  updateCampaign: (id: string, input: Partial<Omit<CampaignInput, "name" | "agentId">>) => call<Campaign>("PATCH", `/api/kit/console/campaigns/${encodeURIComponent(id)}`, input),
  retryCampaign: (id: string, scope: CampaignRetryScope) => call<{ retried: number; campaign: Campaign }>("POST", `/api/kit/console/campaigns/${encodeURIComponent(id)}/retry`, { scope }),
  campaignProgress: (id: string) => call<CampaignProgress>("GET", `/api/kit/console/campaigns/${encodeURIComponent(id)}/progress`),
  addCampaignTargets: (id: string, csv: string) =>
    call<{ added: number; skipped: number; noCountryCode: number }>("POST", `/api/kit/console/campaigns/${encodeURIComponent(id)}/targets`, { csv }),
  setCampaignSource: (id: string, url: string) =>
    call<{ added: CampaignLinkRead; campaign: Campaign }>("PUT", `/api/kit/console/campaigns/${encodeURIComponent(id)}/source`, { url }),
  removeCampaignSource: (id: string) => call<void>("DELETE", `/api/kit/console/campaigns/${encodeURIComponent(id)}/source`),
  startCampaign: (id: string) => call<Campaign>("POST", `/api/kit/console/campaigns/${encodeURIComponent(id)}/start`, {}),
  pauseCampaign: (id: string) => call<Campaign>("POST", `/api/kit/console/campaigns/${encodeURIComponent(id)}/pause`, {}),
  cancelCampaign: (id: string) => call<Campaign>("POST", `/api/kit/console/campaigns/${encodeURIComponent(id)}/cancel`, {}),
  consent: (page = 1, search = "") => call<ConsentPage>("GET", `/api/kit/console/consent${query({ page: String(page), search: search || undefined })}`),
  recordConsent: (input: ConsentInput) => call<ConsentRecord>("POST", "/api/kit/console/consent", input),
  importConsent: (input: ConsentImport) => call<{ added: number; skipped: number; duplicates: number }>("POST", "/api/kit/console/consent/import", input),
  withdrawConsent: (phoneNumber: string, note: string | null) => call<void>("POST", "/api/kit/console/consent/withdraw", { phoneNumber, note }),
  setConsentRule: (required: boolean) => call<ConsentRule>("PUT", "/api/kit/console/consent/rule", { required }),
  campaignReach: (id: string) => call<CampaignReach>("GET", `/api/kit/console/campaigns/${encodeURIComponent(id)}/reach`),
  campaignVariables: (id: string) =>
    call<{ variables: { name: string; required: boolean }[] }>("GET", `/api/kit/console/campaigns/${encodeURIComponent(id)}/variables`),
  knowledgeBases: () => call<{ knowledgeBases: KnowledgeBase[]; limits: KnowledgeLimits }>("GET", "/api/kit/console/knowledge"),
  createKnowledgeBase: (name: string, description: string | null) => call<KnowledgeBase>("POST", "/api/kit/console/knowledge", { name, description }),
  knowledgeBase: (id: string) => call<KnowledgeBase & { documents: KnowledgeDocument[] }>("GET", `/api/kit/console/knowledge/${encodeURIComponent(id)}`),
  deleteKnowledgeBase: (id: string) => call<void>("DELETE", `/api/kit/console/knowledge/${encodeURIComponent(id)}`),
  addKnowledgeText: (id: string, title: string, text: string) =>
    call<KnowledgeDocument>("POST", `/api/kit/console/knowledge/${encodeURIComponent(id)}/documents`, { title, text }),
  uploadKnowledgeFile: (id: string, file: File, title?: string) => {
    const form = new FormData();
    form.append("file", file);
    if (title) form.append("title", title);
    return send<KnowledgeDocument>(`/api/kit/console/knowledge/${encodeURIComponent(id)}/documents/upload`, form);
  },
  addKnowledgePage: (id: string, url: string, title?: string) =>
    call<KnowledgeDocument>("POST", `/api/kit/console/knowledge/${encodeURIComponent(id)}/documents/page`, { url, ...(title ? { title } : {}) }),
  retryKnowledgeDocument: (id: string, documentId: string) =>
    call<KnowledgeDocument>("POST", `/api/kit/console/knowledge/${encodeURIComponent(id)}/documents/${encodeURIComponent(documentId)}/retry`, {}),
  deleteKnowledgeDocument: (id: string, documentId: string) =>
    call<void>("DELETE", `/api/kit/console/knowledge/${encodeURIComponent(id)}/documents/${encodeURIComponent(documentId)}`),
  knowledgeTable: (id: string, documentId: string, page: { offset: number; limit: number }) =>
    call<KnowledgeTable>("GET", `/api/kit/console/knowledge/${encodeURIComponent(id)}/documents/${encodeURIComponent(documentId)}/table${query({ offset: String(page.offset), limit: String(page.limit) })}`),
  setKnowledgeColumnTypes: (id: string, documentId: string, types: Record<string, KnowledgeColumnType>) =>
    call<{ columns: KnowledgeTableColumn[] }>("PATCH", `/api/kit/console/knowledge/${encodeURIComponent(id)}/documents/${encodeURIComponent(documentId)}/table`, { types }),
  setAgentKnowledge: (agentId: string, knowledgeBaseIds: string[]) =>
    call<{ knowledgeBases: KnowledgeBaseRef[]; syncState: Agent["syncState"]; syncError: string | null }>(
      "PUT", `/api/kit/console/agents/${encodeURIComponent(agentId)}/knowledge`, { knowledgeBaseIds }),
  numbers: () => call<{ numbers: PhoneNumber[] }>("GET", "/api/kit/console/numbers"),
  availableNumbers: (country: string, pattern = "") =>
    call<{ numbers: AvailableNumber[] }>("GET", `/api/kit/console/numbers/available?${new URLSearchParams({ country, ...(pattern ? { pattern } : {}) })}`),
  buyNumber: (number: string, country: string) => call<PhoneNumber>("POST", "/api/kit/console/numbers", { number, country }),
  setNumberAgent: (id: string, agentId: string | null) => call<PhoneNumber>("PUT", `/api/kit/console/numbers/${encodeURIComponent(id)}/agent`, { agentId }),
  releaseNumber: (id: string) => call<void>("DELETE", `/api/kit/console/numbers/${encodeURIComponent(id)}`),
  setNumberRouting: (id: string, routed: boolean) => call<PhoneNumber>("PUT", `/api/kit/console/numbers/${encodeURIComponent(id)}/routing`, { routed }),
  setNumberCallerId: (id: string, agentCallerId: boolean) =>
    call<PhoneNumber>("PUT", `/api/kit/console/numbers/${encodeURIComponent(id)}/caller-id`, { agentCallerId }),
  setNumberUnanswered: (id: string, unanswered: Unanswered) =>
    call<PhoneNumber>("PUT", `/api/kit/console/numbers/${encodeURIComponent(id)}/unanswered`, { unanswered }),
  lostNumbers: () => call<{ numbers: LostNumber[] }>("GET", "/api/kit/console/numbers/lost"),
  getNumberBack: (id: string) => call<GetBackOutcome>("POST", `/api/kit/console/numbers/${encodeURIComponent(id)}/get-back`),
  numberRenewals: () => call<NumberRenewals>("GET", "/api/kit/console/numbers/renewals"),
  routing: () => call<RoutingPage>("GET", "/api/kit/console/routing"),
  createRoutingRule: (input: RoutingRuleInput & { name: string; action: "answer" | "refuse" }) =>
    call<RoutingRule>("POST", "/api/kit/console/routing/rules", input),
  updateRoutingRule: (id: string, input: RoutingRuleInput) => call<RoutingRule>("PATCH", `/api/kit/console/routing/rules/${encodeURIComponent(id)}`, input),
  deleteRoutingRule: (id: string) => call<void>("DELETE", `/api/kit/console/routing/rules/${encodeURIComponent(id)}`),
  orderRoutingRules: (ids: string[]) => call<{ rules: RoutingRule[] }>("PUT", "/api/kit/console/routing/order", { ids }),
  tryRouting: (input: RoutingTryInput) => call<RoutingTry>("POST", "/api/kit/console/routing/try", input),
  checkRouting: (numberId: string) => call<RoutingCheck>("POST", "/api/kit/console/routing/check", { numberId }),
  calls: (page = 1, filter: CallFilter = {}) =>
    call<CallPage>("GET", `/api/kit/console/calls${query({ page: String(page), agentId: filter.agentId, number: filter.number, placedBy: filter.placedBy,
      from: filter.from, to: filter.to, timeZone: filter.timeZone, outcome: filter.outcome, channel: filter.channel })}`),
  insights: (q: InsightsQuery = {}) =>
    call<Insights>("GET", `/api/kit/console/insights${query({ from: q.from, to: q.to, timeZone: q.timeZone, agentId: q.agentId })}`),
  callerMemory: () => call<CallerMemory>("GET", "/api/kit/console/memory"),
  forgetCaller: (caller: string) => call<{ removed: boolean }>("DELETE", `/api/kit/console/memory?${new URLSearchParams({ caller }).toString()}`),
  call: (id: string) => call<CallDetail>("GET", `/api/kit/console/calls/${encodeURIComponent(id)}`),
  liveCalls: () => call<LiveCalls>("GET", "/api/kit/console/calls/live"),
  listenToLiveCall: (id: string) => call<{ wsUrl: string }>("POST", `/api/kit/console/calls/${encodeURIComponent(id)}/listen`, {}),
  tellAgent: (id: string, note: { text: string; sayNow: boolean }) =>
    call<{ note: AgentNote }>("POST", `/api/kit/console/calls/${encodeURIComponent(id)}/agent-notes`, { text: note.text, sayNow: note.sayNow }),
  callWork: (id: string) => call<CallWork>("GET", `/api/kit/console/calls/${encodeURIComponent(id)}/work`),
  addCallNote: (id: string, body: string) => call<CallWork>("POST", `/api/kit/console/calls/${encodeURIComponent(id)}/notes`, { body }),
  setCallWrapup: (id: string, wrapup: string | null) => call<CallWork>("PUT", `/api/kit/console/calls/${encodeURIComponent(id)}/wrapup`, { wrapup }),
  addCallFollowUp: (id: string, body: string, dueAt: string) =>
    call<CallWork>("POST", `/api/kit/console/calls/${encodeURIComponent(id)}/followups`, { body, dueAt }),
  followUps: (page = 1, done = false) => call<FollowUpPage>("GET", `/api/kit/console/followups${query({ page: String(page), done: done ? "true" : undefined })}`),
  setFollowUp: (id: string, done: boolean) => call<FollowUp>("PATCH", `/api/kit/console/followups/${encodeURIComponent(id)}`, { done }),
  phone: () => call<PhonePage>("GET", "/api/kit/console/phone"),
  placeBrowserCall: (callerId: string, number: string) =>
    call<{ callId: string; session: TestSession }>("POST", "/api/kit/console/phone/calls", { callerId, number }),
  hangUpBrowserCall: (id: string) => call<void>("DELETE", `/api/kit/console/phone/calls/${encodeURIComponent(id)}`),
  transferBrowserCall: (id: string, to: string) => call<void>("POST", `/api/kit/console/phone/calls/${encodeURIComponent(id)}/transfer`, { to }),
};

/** Where the browser goes to sign in with an OpenID Connect provider (startSso): a page load, not a fetch. */
export function ssoStartUrl(providerId: string): string {
  return `/api/kit/oidc/${encodeURIComponent(providerId)}/start`;
}

/** Where a campaign's people, or one group of them, download from as a CSV file. */
export function campaignExportUrl(id: string, show?: CampaignPeopleGroup): string {
  return `/api/kit/console/campaigns/${encodeURIComponent(id)}/targets/export${query({ show })}`;
}

/** Where the calls a filter picks download from as a CSV file (exportCalls), started times in `timeZone`; a link, with the session cookie. */
export function callsExportUrl(filter: Omit<CallFilter, "placedBy"> = {}): string {
  return `/api/kit/console/calls/export${query({ agentId: filter.agentId, number: filter.number, from: filter.from, to: filter.to,
    timeZone: filter.timeZone, outcome: filter.outcome, channel: filter.channel })}`;
}

/** Where the account's do-not-call list downloads from as a CSV file (exportDnc). */
export function dncExportUrl(): string {
  return "/api/kit/console/dnc/export";
}

/** Where the account's consent records, withdrawn ones too, download from as a CSV file (exportConsent). */
export function consentExportUrl(): string {
  return "/api/kit/console/consent/export";
}

/** Where a call's recording plays from (getCallRecording): an `<audio>` element loads it, with the session cookie. */
export function callRecordingUrl(callId: string, index: number): string {
  return `/api/kit/console/calls/${encodeURIComponent(callId)}/recordings/${index}`;
}
