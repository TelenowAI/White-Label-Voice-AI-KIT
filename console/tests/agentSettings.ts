/**
 * What the console's tests give an agent as its settings: a new agent's, as the backend answers them, and what the agent form
 * offers. Used wherever a test builds an agent. Not here: the tests of the settings themselves (AgentSettings.test.tsx).
 */
import type { AgentSettings, AgentSettingsForm, SetupField } from "../src/api";

export const agentSettings: AgentSettings = {
  llmSettings: { values: {} },
  sttSettings: { values: {} },
  ttsSettings: { values: {} },
  realtime: null,
  flow: null,
  greetingInbound: null,
  greetingOutbound: null,
  voicemailMessage: null,
  recording: true,
  maxDurationSecs: 600,
  maxDurationMessage: null,
  silenceHangupSecs: 15,
  silenceHangupMessage: null,
  silenceCheckinSecs: 10,
  silenceCheckinMessage: null,
  canEndCall: true,
  transfer: { enabled: false, destinations: [], message: null, noAnswerMessage: null },
  analysis: { enabled: false, minDurationSec: 20, minCustomerTurns: 2, customFields: [], qaCriteria: [] },
  tone: "professional",
  personaGender: null,
  naturalFillers: false,
  highEmpathy: false,
  echoVerification: false,
  natoPhonetic: false,
  speechNormalization: true,
  smartMatching: true,
  scopeBoundaries: true,
  aiDisclosure: true,
  interruptions: "normal",
  rememberInterruptions: false,
  noisyRoom: false,
  listeningSounds: true,
  ignoreSpeechBeforeGreeting: false,
  thinkingFillers: false,
  backgroundSound: null,
  backgroundVolume: 50,
  ringInbound: false,
  ringOutbound: false,
  ringSeconds: 4,
  contextMode: "summary",
  contextTurns: 10,
  callerMemory: false,
  callerMemoryDays: 90,
  keypad: false,
  keypadWaitMs: 2500,
  keypadEndKey: null,
  keypadDigits: 0,
  optOut: true,
  fastOpener: false,
  greetingDelaySecs: 0,
  ringDurationSecs: null,
  callScreen: false,
  callScreenMessage: null,
  ivrHangup: false,
  canAdjustVolume: false,
  sendCallerToTools: true,
  tools: [],
  lookups: [],
  handoff: { agents: [], message: null },
  emailAfterCall: { recipients: [], onlyAnswered: true },
};

const field = (name: string, label: string, type: SetupField["type"], over: Partial<SetupField> = {}): SetupField => ({
  name, label, type, required: false, value: null, help: null, options: [], showWhen: null, secretSet: false, ...over,
});

/** A few settings of each type: the form draws whatever the backend declares. */
export const settingsForm: AgentSettingsForm = {
  transferAllowed: true,
  emailOn: true,
  groups: [
    {
      id: "speaking", title: "How it speaks", description: "How the agent talks.",
      fields: [
        field("tone", "Tone", "select", { options: [{ value: "professional", label: "Professional" }, { value: "professional_conversational", label: "Conversational" }] }),
        field("personaGender", "Speaks as", "select", { options: [{ value: "", label: "Not set" }, { value: "female", label: "Female" }] }),
        field("highEmpathy", "High empathy", "checkbox", { help: "Acknowledges an upset caller." }),
        field("speechNormalization", "Say numbers naturally", "checkbox"),
      ],
    },
    {
      id: "keypad", title: "Keypad", description: "Keys a caller presses.",
      fields: [
        field("keypad", "Listen for keys pressed", "checkbox"),
        field("keypadDigits", "Stop after this many digits", "number", { showWhen: { field: "keypad", values: ["1"] } }),
      ],
    },
    {
      id: "answering", title: "Answering", description: "As a call connects.",
      fields: [
        field("ringDurationSecs", "How long to ring", "number"),
        field("callScreenMessage", "What it says when screened", "text"),
      ],
    },
  ],
};
