# Build notes

How each part of the White-Label Voice AI Kit was built and proven, feature by feature: what Telenow's API does (recorded
against a real Telenow release on a throwaway database), what the kit does about it, and what was left out and why. The
overview is the [README](../README.md).

## Status

**K1 First release**, started. Slice 1 is done: **customers**. Each customer is a workspace in the kit's
database and a Telenow sub-account with its own key, stored encrypted.
- Admin → Customers lists, adds, suspends and resumes customers, and retries a change Telenow refused.
- Every change goes through the outbox (`laravel/app/Kit/Outbox`; SELF_HOSTED_KIT_PLAN.md §3.8 a): the row
  and the write are saved together, then sent at once, and retried by `php artisan kit:outbox` (scheduled
  every minute) with the same Idempotency-Key.
- One row's writes go in order.
- A create whose answer was lost is recovered by its externalId and given a new key.

Slice 2 is done: **customer sign-in, team and console home**.
- The operator invites a customer's first user (Admin → a customer → Team), and an account's managers
  invite and remove their own people (Console → Team).
- An invite link is shown once and stored only as a hash. It works once, until it expires (72 hours) or is
  withdrawn, and every dead link answers the same way. Email delivery of links comes with the email drivers.
- Accepting signs the person in. Someone new chooses a name and password; someone with an account joins
  with theirs. An account always keeps a manager.
- The console shows the current account (with a switcher for people in several), and a "service paused"
  notice while the account is suspended, not set up yet, or the platform behind it cannot be used. The
  notice never says why the operator's own account stopped. `php artisan kit:telenow-status` (scheduled)
  keeps that last state current.

Slice 3 is done: **agents, with a browser test call** (kit API 0.5.0).
- Console → Agents lists an account's prompt agents; a member builds, changes, pauses ("takes calls") and
  deletes them. Each is made at Telenow with the account's own key, through the outbox, and only the fields
  that changed are sent. Which kit column is which Telenow field comes from the manifest
  (`contract/resources.yaml`), copied for Laravel by `pnpm --filter contract manifest`.
- A new agent's voice setup is the operator's choice (setting `agents.defaults`; choosing it in admin
  comes with the catalog), else Telenow's recommendation for the account. When neither names a part, the
  agent says so and is not sent.
- While Telenow cannot take a change, the agent shows the kit's own "will retry" words, never Telenow's
  error text. A refusal shows its reason and a retry button.
- The test call: the kit's backend starts the session with the account's key, and the console joins it with
  `@telenow/client`'s `session` option, so no key or Telenow id reaches the browser. No call starts while the
  service is paused. Leaving the page hangs up.

Slice 4 is done: **Telenow's events and calls** (kit API 0.6.0).
- **Events.** The kit registers ONE endpoint on the operator's org with the operator's key (`php artisan kit:events`,
  scheduled every minute). It is org-wide, so Telenow copies every customer's events to it, stamped `subAccountId`.
  It subscribes to exactly the events the kit has handlers for: so far `call.completed`, one per finished call,
  unanswered ones included.
- **Keeping it alive.** A disabled endpoint drops out of Telenow's list. The kit then registers a new one, and the
  secret before it still verifies deliveries already on their way. Until one works it tries on every run; then it
  checks every 15 minutes. Why the last try failed is kept for the admin (`telenow.events_error`).
- **The inbox** (`POST /api/kit/telenow/events`):
  - each delivery is checked against `X-VoiceAI-Signature`, stored raw once per `X-VoiceAI-Delivery`, answered 204
    at once, and applied right after;
  - it never answers 410, which would make Telenow stop sending for good;
  - a failed apply is tried again with backoff.
- **Calls.** An event names the call; the kit reads the call itself with the customer's own key. Which column is
  which Telenow field, plus the analysis, comes from the manifest. A call is read again until no part the console
  shows (recording, transcript, analysis) is still `pending` in Telenow's `completeness`.
- **The calls feed** (`php artisan kit:calls`) reads every call created or changed since its last cursor, so calls
  whose events were lost while the kit was away still arrive. The operator's own calls are never a customer's.
- **Console → Calls** lists the account's calls and opens one with its recording (streamed through the kit, no key
  in the browser), transcript and analysis. Each agent links to its calls.

Slice 5 is done: **wallet, prices and per-call charging** (kit API 0.7.0).
- **Prices** (Admin → Pricing): the currency the operator bills in, how much of it one US dollar is, the default
  plan, and the price lists — one per plan, in the pricing spec's format (`contract/pricing`). A list is checked
  before it is saved, by a check held to the format's schema through shared cases
  (`contract/pricing/list-cases.json`). Charging is on only once all three are set; until then nothing is charged,
  limited or paused.
- **The wallet** (Admin → a customer): its balance, its plan, the stop last set at Telenow, and every entry. Each
  charge sits beside what Telenow charged for the call and the margin. Credit is added by hand for now (a bank
  transfer); each credit happens once, even when the form is sent again.
- **Charging.** Once a call is final, `php artisan kit:wallet` (every minute) reads its cost with the customer's own
  key until Telenow has rated it. It prices the cost on the customer's plan with the pricing engine and charges the
  wallet once. A call Telenow never rates (an unanswered one) is left uncharged. A call the plan cannot price waits,
  and saving a price list tries it again.
- **The stop.** After every entry the kit sets the sub-account's `totalSpendLimitUsd` at Telenow: its lifetime total
  plus the balance in Telenow cost, minus room for the calls under way. At zero balance Telenow refuses new calls
  (403), and the console shows its own "balance used up" notice.
- **Console → Wallet** shows the balance and entries; each call shows what it was charged. A customer never sees
  Telenow's cost.

Slice 6 was built in five parts. DNC came first (fully recordable, and campaigns need it); campaigns came
last (they need numbers and DNC).

**6a, done: the do-not-call list** (kit API 0.8.0).
- Console → Do not call: an account adds a number (with a reason), imports a CSV, and removes a number. Removing a
  caller's own opt-out asks with a plainer warning.
- Numbers are put on the list at Telenow with the account's own key, through the outbox. Telenow writes each
  number its own way, and adding one that is already listed answers its existing entry. One it cannot dial says so
  in the kit's words.
- A CSV goes to Telenow whole (it alone knows which lines it can dial), and its counts come back.
- `php artisan kit:dnc` reads Telenow's DNC feed. That brings the imported numbers, Telenow's own entries (a
  caller's opt-out, linked to its call) and every removal. Telenow enforces the list: a number on it is never
  dialled.

**6b, done: phone numbers and number fees** (kit API 0.9.0).
- Console → Numbers: an account finds numbers for sale by country and digits. Each comes with its setup and monthly
  price on the account's plan, never the carrier's name or Telenow's prices. Buying asks first, with those prices.
- **Buying.** The kit finds the number again, so its carrier and prices are Telenow's listing, never what the browser
  sent. While charging is on, it refuses before Telenow when the plan cannot price the number or the balance does not
  cover the setup and first month. Then it buys with the account's own key through the outbox; the number row's id is
  the Idempotency-Key, so a retry cannot buy two. Telenow's refusals come back in the kit's words.
- **The agent that answers it** is chosen per number. Releasing a number asks first. One Telenow no longer has
  counts as released, and one whose release has not gone through yet stays listed until it has. Each number write
  states the whole outcome, so a new one replaces a write Telenow gave up on.
- **Fees.** `php artisan kit:numbers` (every minute; each account every 15 minutes, or right after a purchase) reads
  the numbers Telenow changed and the account's ledger. It keeps the number fees (setup and every month's rent), never
  a call's charge, which comes with the call. `kit:wallet` prices each fee on the plan and charges it once. Fees never
  move the spend limit's call figures. Console → Wallet shows a fee as "Number fee", linked to the numbers.
- Recorded against Telenow's real number API with a stand-in carrier behind it (`teleone_stub.py` on the build
  server), so no number was bought anywhere.

**6c, done: the catalog, voices and an agent's voice setup** (kit API 0.10.0).
- **The catalog copy.** `php artisan kit:catalog` (hourly; it reads again once the copy is a day old, or a voice list
  could not be read last time) and Admin → Catalog's "Read the catalog again" copy Telenow's catalog with the operator's
  key. They also copy its recommended new-agent stack and every voice provider's whole list. Only fields the kit names are
  kept (`config('kit.catalog.fields')`, `CatalogVoices::row`), so a price, a key's name, a sample's address or a field
  Telenow adds later is never stored.
- **Admin → Catalog.** The operator offers language model, speech recognition and voice providers, and switches off
  language models. Nothing is offered until chosen, and each provider can carry a name of the operator's.
  - A name that carries the platform's own (`KIT_TELENOW_NAME`) is flagged and cannot be offered. Telenow's own voices
    and speech recognition carry it until renamed.
  - The new-agent defaults are picked among what is offered, with the platform's recommendation one click away.
  - Everything is saved all or nothing.
- **Console → Voices.** Customers browse the offered providers' full voice lists (`catalog_voices`, each provider's
  default model; see "Telenow's 1.1.0 document" below). They narrow by provider, language (named by the browser), gender
  and words, a page at a time, and hear any voice in a short clip made on demand with their own key (never stored).
  Telenow's throttle, its spend check and an unreachable vendor come back in the kit's words, and a paused account asks
  for nothing paid.
- **An agent's voice setup** is chosen whole from what is offered, with "Hear this voice" and "Hear the agent".
  Customers see providers only under keys of the kit's own, never Telenow's ids.
- A voice model and each provider's other settings: see "Each provider's own settings" below; realtime voices: "Realtime voices" below.
  Voice cloning: see "An account's own voices" below.
- Recorded on Telenow main's voice library binary with a stand-in for Telenow's own TTS engine
  (`tts_stub.py` on the build server), so previews are real answers and no vendor was reached.

**Razorpay, Stripe and PayPal, built** (kit API 0.26.0, 173 operations, 11-03). The operator switches on any number of ways to pay
on Admin → Payments, or in the wizard, with their own keys: each form is drawn from its driver, with no page change.
- **Both send the payer to the gateway's own page**, with no script and no card data here, so the kit stays out of card
  rules. Which methods show (cards, UPI and so on) is set on the operator's gateway account.
- **Razorpay uses Payment Links** (`App\Kit\Payments\Drivers\RazorpayDriver`). Hosted Checkout's documents make the payer's
  phone mandatory, and a customer who signed up by email has none; a link asks for nothing. A refund or dispute event
  names only the payment's order, so it asks Razorpay for the order, whose receipt is the kit's payment id. Payments must
  capture automatically in Razorpay's settings.
- **Stripe uses hosted Checkout Sessions** (`App\Kit\Payments\Drivers\StripeDriver`), and reads snapshot events (a thin event
  carries no object and changes nothing). A delayed method counts only once `payment_status` is paid. A refund or dispute
  event names only the PaymentIntent, so it asks Stripe for its metadata. An inquiry closed or a dispute prevented gives a
  held amount back, as one won does.
- **PayPal uses Orders v2** (`App\Kit\Payments\Drivers\PayPalDriver`), for operators charging in one of the 24 currencies it
  takes (no rupees: Admin → Payments says so, and it is offered to no one). Approving moves no money, so the kit captures
  the order: on the payer's return, on the approved event, or at the next `kit:payments` if the payer never came back,
  and an order already captured is left alone. PayPal checks its own signatures, so an event's exact bytes go back to its
  verify call. HUF, JPY and TWD go without decimals, as PayPal wants them.
- **Keys of the wrong mode are refused** before any call, and an event about a payment that is not the kit's changes nothing,
  so the operator's account can also serve something else.
- **A refund that fails later** (`refund_failed`, a new kit event). The bank can refuse a refund after it was counted, and
  the money then goes back to the operator. It now goes back on the customer's wallet, once, the payment can be refunded
  again, and Admin → Payments says so, since the customer has not been paid back. Told of the failure first, the refund
  never counts.
- Checked end to end on a copy, in a browser and at phone width, against a stand-in for their APIs with real signatures: adding
  each gateway, a top-up through each, staff refunds, and refunds, failed refunds and disputes from their webhooks.
- **Not run against a real account.** All three are written from the gateways' documents (read 11-03) with their sample bodies.
  First things to check with test keys: PayPal's capture and signature check, a Stripe session's refund and dispute events, and
  that a Razorpay link's order holds its `reference_id` as `receipt`, as Razorpay's sample shows.
- Not in this: subscriptions, and a currency list for Razorpay and Stripe (a currency either does not take is refused by it,
  and staff see its words). The first customers are outside India (owner, 11-03), so the India-only Cashfree, PhonePe and PayU
  are not built.

**Paystack, Flutterwave and Tap, built** (kit API 0.27.0, 173 operations, 11-03). The first customers are in the US, the UK,
Europe, the Middle East and Africa (owner, 11-03). Stripe's own list covers the first three and the UAE, and sends its African
countries to Paystack, so these three fill the rest. The operator switches on whichever suit their customers: Admin → Payments
draws each from its driver, with no page change.

| Gateway | Where, by its own pages (read 11-03) | Currencies |
| --- | --- | --- |
| Paystack | Nigeria, Ghana, South Africa, Kenya, Côte d'Ivoire | NGN, USD, GHS, ZAR, KES, XOF (`kit.payments.paystack.currencies`) |
| Flutterwave | Much of Africa; its own pages disagree on the list | Not listed here: it refuses one it will not take, and staff see its words |
| Tap | Saudi Arabia, UAE, Kuwait, Bahrain, Qatar, Oman; Egypt newer, Jordan unclear | SAR, AED, KWD, BHD, QAR, OMR, EGP, JOD, USD, EUR, GBP (`kit.payments.tap.currencies`) |

- **An email for this payment alone.** All three require the payer's email, and a customer who signed up by phone has none. The
  form asks for it only when the chosen gateway needs it and the account lacks it, sends it to the gateway, and neither keeps it
  on the payment nor puts it on the account. Staff are asked the same for a test payment.
- **Webhooks are answered with a plain 200, for every gateway** (it was a 204). Paystack and Flutterwave count anything else as
  failed and send the event again.
- **One refund of a payment at a time.** Paystack and Flutterwave cannot refuse the same refund twice, so `Payments::refund` takes
  a lock, and each carries the kit's reference on the refund and looks for it first, so a retry after a lost answer does not
  pay twice. Tap takes the reference as its own idempotency key.
- **Paystack** (`App\Kit\Payments\Drivers\PaystackDriver`). Every amount goes multiplied by 100, whatever the currency, also XOF,
  which has no subunit. Its refund events name the bank's reference, never the refund's id, so an event only makes the kit ask for
  the transaction's refunds, each counted once by its own id. A refund a dispute made is that dispute's money and is not counted
  again. `abandoned` stays open, since the payer can still pay. Disputes: accepted by anyone is lost, `declined` is won.
- **Flutterwave** (`App\Kit\Payments\Drivers\FlutterwaveDriver`). Its proof is a secret hash the operator chooses, which covers
  nothing in the body, so an event only says what to ask about and the money always comes from Flutterwave's answer. Its refund
  event has no name. A refund whose `disburse_status` is failed counts as failed although its status says `completed`. Chargeback
  webhooks stay off until the operator asks Flutterwave.
- **Tap** (`App\Kit\Payments\Drivers\TapDriver`). Each charge and refund names its own webhook address, so there is nothing to set
  up. Its `hashstring` signature leaves out the metadata and `reference.transaction`, so a charge's event finds the payment by the
  signed id and nothing unsigned decides anything. A refund's event can only name its charge in the unsigned `charge_id`. KWD, BHD,
  OMR and JOD have three decimals. Tap sends no dispute events: the operator handles disputes on its dashboard.
- A gateway's number (500, 500.5) is written with the currency's places when it arrives (`Money::written`). Places that are not
  zeros are kept, so a different amount waits for staff and is never rounded to what was asked.
- Checked end to end on a copy, in a browser and at phone width, against a stand-in for their APIs with real signatures: adding
  each gateway, a phone-only customer's top-up through each, staff refunds, refunds, failed refunds and disputes from their
  webhooks, forged events.
- **Not run against a real account.** All three are written from the gateways' documents (read 11-03) with their sample bodies.
  First things to check with test keys: Paystack's dispute `declined` meaning the operator won; whether Flutterwave's refund
  callback carries `verif-hash`, what its `disburse_status` really does, and whether `GET /v3/refunds?id=` filters by transaction
  as its page half says; Tap's refund event carrying `charge_id`, and that `sk_test_` is its test prefix.
- PayPal's coverage in the Middle East and Africa was not settled: no single official list was found and other sources conflict.

**Agent form, stage 1: how an agent behaves on a call** (kit API 0.28.0, 173 operations, 11-03). Telenow's builder has far more settings
than the kit's form had. This stage covers what decides whether an agent works and what it costs; the rest follows in stages (below).
- **A real gap it closes.** The kit made agents with no phone settings at all. Telenow's code and recordings show what that meant: on a
  phone call the agent said nothing until the caller spoke, although a browser test call greeted normally; no call limit was started; no
  silence hang-up; no way for the agent to hang up. A line left open would bill until someone hung up. A new agent now starts with
  `kit.agents.new_agent_settings`, and with no opening line the kit writes `none`, so a browser test call behaves as a phone call will.
- **The settings** (`App\Kit\Agents\AgentSettings`, console `agents/AgentSettingsForm.tsx`): opening lines for calls it answers and places;
  a voicemail message; recording; the longest call and the line before it runs out; hanging up and checking in on silence, each with its
  line; whether it can hang up; transfer to a person (places with names and numbers, one after another or all at once); post-call analysis
  (when to skip a call, details to find, quality checks). A call's page shows the details found and the checks met.
- **Telenow replaces a settings block whole when it is sent, and keeps whatever it is sent.** So a save reads Telenow's current block,
  changes only the keys that changed, sends only the blocks it touched, and saves only if the agent is still at the version it just read
  (`If-Match`). Someone saving in between refuses it with nothing written, and the next try starts from what they saved. Telenow would
  also store an over-limit value or an unusable number, so the kit checks them first.
- **Proven against the real thing.** The recordings come from Telenow's 11-03 release binary on a throwaway database
  (`contract_k1settings.py`), and the kit's write path ran against that binary through a tunnel, from a browser at phone width too: settings
  the kit does not manage survived every save, an empty `{}` included.
- **Not proven:** a real phone call with these settings: that the line plays, a real transfer rings and is charged, and a real analysis comes
  back with the details. Those need a carrier and a provider key.

**Agent form, stage 2: how an agent speaks and listens** (kit API 0.29.0, 174 operations, 11-04). 36 more settings, in six folding groups on
the same form and the same save: tone, gender for languages that need it, filler words, empathy, reading details back, spelling things out,
saying numbers naturally, near matches, staying on topic, saying it is an AI; how easily it can be interrupted, remembering what was cut off,
noisy-room mode, listening sounds, ignoring talk before the opening line; slow-reply lines, background sound, ring sounds; how much of a call
it keeps in mind, remembering callers between calls; keypad input and do-not-call requests; fast opening, a delay before it, how long to ring,
call screening, hanging up on machines.
- **One table** (`App\Kit\Agents\AgentBehaviour`): each setting's place in Telenow's blobs and its kind. The checks, the reading, the writing and
  the form all come from it, and the console draws the form from what the backend declares (`getAgentSettingsForm`,
  `agents/AgentBehaviourFields.tsx`). A new setting is a row here and its words in `lang/en/agents.php`, with no console change.
- **Exact types matter more here than anywhere.** Telenow parses `session_config` and `telephony_config` whole: one key of the wrong type makes it
  drop every setting of that blob for the call, with only a line in its own log. This was shown on the release binary: an agent with one wrong
  key logged `session_config failed to parse — ALL session knobs disabled for this call`, while an agent with all 36 settings logged none and went on to
  the provider step. So the kit refuses bad values and writes numbers, floats and booleans as their real types.
- **A new agent starts as Telenow's own builder starts one:** numbers said naturally, near matches, staying on topic, listening sounds, and a running
  summary with the last 10 turns (`kit.agents.new_agent_settings`). Without them an agent made through the kit was worse than one made in the builder.
- **An agent that can transfer always says it is an AI when asked**, whatever is stored: that is Telenow's rule, so the form shows it.
- **Left out on purpose:** `turnTaking` tiers and `defend`, which carry a per-minute surcharge; `transport` webrtc, also a surcharge; `delayedResponse`, gated
  on Telenow's own flag; `followup`; uploading a background sound; sending the caller's identity to tools, which waits for the tools stage. None of the 36
  carries a surcharge in Telenow's billing code, and its summary is marked not billable.
- **Not proven:** a real call with these settings, so how each one sounds. What is proven is that each is stored as sent and parsed without error.

**Agent form, stage 3: tools, look-ups and hand-overs** (kit API 0.30.0, 174 operations, 11-04). On the same form and the same save. Tools and look-ups call a customer's own web addresses
from Telenow during a call: the kit itself never calls them.
- **Tools** (`App\Kit\Agents\AgentTools`): a web address the agent may call during a call, the details it should get from the caller, what it says while it waits, how long it waits, and whether it
  asks first. Plain, it is POSTed the name and details as JSON; customised, it sends the method, headers and body written, with `{detail}` filled in.
- **Look-ups** (`AgentLookups`): before a call opens, Telenow asks the customer's address about the person and fills call variables, for the opening line and the instructions. It has 1.5 s on a
  call being answered and 4 s on one being placed; a slow or failed one fills nothing and the call goes on. A fallback covers the case where nothing is found.
- **Hand-overs** (`AgentHandoff`): the call moves to another of the account's agents, with the conversation so far. It is not a second phone call. Deleting an agent takes it off the others.
- **Two switches:** callers can ask for louder or softer, and whether the caller's number goes to tools. Look-ups use it either way, as recorded.
- **Saved keys** (`SavedKeys`): a typed key is sealed as soon as it is checked, sits sealed in the outbox until it is sent, and is in none of the kit's columns or answers. Telenow only ever answers a mask, because
  the kit never asks for `view=edit`, which would answer it in plain text. A mask handed back keeps the key, but only while everything it goes to reads as it did: its address, and any text with a web address in that row.
  So a customer who changes an address types the key again, and the kit says so beside the field before Telenow would refuse the whole save. A voicemail line gaining or losing a web address is refused the same way while keys
  are kept: it was recorded failing. Only `Authorization`, `X-Api-Key` and such are masked: any other header is stored and shown as typed, and the form says so.
- **Checked here because Telenow stores anything** (`AgentRequest`, recorded on the release): plain `http`, private addresses, a bad `map` that silently drops every look-up of the agent, headers sent as a list that silently drop the tool. A
  `{detail}` the tool does not have, one in the host name, which could send a key where a caller chose, and a number or filter it cannot use are all refused beside the field. Telenow checks the address again when it sends, resolving the name.
- **Not possible yet:** a "test this tool" button and connecting MCP servers. Telenow has both (`tool-dry-run`, `mcp/verify`) but they answer only a signed-in person's token, 401 to a kit's key, and are not in its API document. Apps and integrations
  (`kind: connector`) need the connections stage. Tools and look-ups of those kinds on an agent are left as they are.
- **Not proven:** a tool called on a live call, and a look-up filling a variable from a real answer. On the release, a look-up parsed, filled in its identifier, was skipped when that was blank, and tried to send; the test boot blocks outside network on purpose.
- **Next:** stage 4 below, then versions, then the flow builder.

**Agent form, stage 4: what is sent after a call** (kit API 0.31.0, 180 operations, 11-04). Two ways out, both on the agent's page.
- **To a customer's own web address** (`App\Kit\Agents\AgentWebhooks`). Telenow does the sending, with its signing and retries, under the customer's key, so the kit never calls a customer's address. An agent has up to `kit.agents.limits.webhooks_per_agent_max` addresses, each with its events and, if chosen, the transcript. It saves as it goes, apart from the form, because a secret comes back once: it is answered once and wiped, and is encrypted only while waiting for a customer to see it. The page says how each address is doing, offers a test and the latest tries (how each went, never what was in it), and can send a finished one again.
- **Only `call.completed` and `call.analyzed` are offered** (`kit.agents.webhook_events`). Telenow's `call.ended` names the platform in its body, and a recording's address would be the platform's. Bodies carry Telenow's opaque ids. The signature header, `X-VoiceAI-Signature`, is neutral.
- **Telenow has no update and no idempotency key on a create** (recorded). A change is a removal and a new address, and a create tried again first removes what a lost answer made: same address, same agent. An address outlives its agent at Telenow, so deleting an agent removes its addresses. Telenow stores an unknown event and the same address twice, so both are refused here.
- **Test sends and sending again need `WEBHOOK_MANUAL_SENDS_PER_HOUR` set on the Telenow server.** Without it Telenow answers 503, and the customer is told test sends are not available. It is commented out in Telenow's `.env.example`.
- **To people on the customer's team** (`AfterCallEmail`). The kit sends this one, through the operator's sender, worded in Admin → Email → Templates as "After a call". It goes only to members of the account who have an email address: never to a typed address, which would let anyone send mail from the operator's domain. Each call is told once, optionally only answered calls, and a call older than `kit.agents.after_call_email_max_age_hours` tells nobody. A call counts as answered when it lasted and no machine picked up: Telenow's outcome is an open set of a carrier's words, and a browser call has none. It goes once the summary has come (`kit.agents.after_call_email_waits_for`), not waiting for the recording.
- **A recording can read `pending` for hours.** Telenow says `pending` for as long as a call that had media has no recording, and its integrity worker never finds one missing on an agent with recording off, on a call with no carrier id (a browser call) or a call with no usage rows. So an agent with recording off has no recording to wait for, and a call the kit gives up reading (about 6 hours with the default limits) is still handed on to be charged. Before this, such a call was never charged.
- **Left out, and why:** Telenow's own after-call email (signing in to Google or Microsoft returns the user to Telenow's dashboard), WhatsApp after a call (needs a channel, and the hub answered 404 and is not in the document), the call log, sheets and CRM destinations (a signed-in person's token only, 401 to a kit's key).
- **Not proven:** a delivery reaching a real address (the test boot blocks outside network on purpose, so tries fail with "refused the connection"), and a real phone call. A finished call was seeded on the release's database, so its delivery and read were the release's own, and its email reached a test mail sink.
- **Next:** stage 5 below, then the flow builder.

**Agent form, stage 5: versions** (kit API 0.32.0, 184 operations, 11-04). Telenow keeps a version for each save that changes something, and none for one that does not. The agent's page lists them, says what each changed and how calls went while it was in use beside the one before, and can put an earlier one back after asking. Each is read only when asked for.
- **What is answered** (`App\Kit\Agents\AgentVersions`). Telenow's rows name who saved by a made-up address, and its results hold its charge to the operator: neither reaches a customer. Its change rows are its own words and settings' names, so a table in `lang/en/agents.php` turns them into the kit's. A row the table does not know says only "Another setting", with no values, so a row Telenow adds later cannot show a customer anything. A whole settings block written out as JSON shows only that it changed. Telenow masks keys in these rows (recorded).
- **Its "what changed" is not complete.** Telenow lists only some settings: a save that only turned recording off makes a version with no rows, and the page says so.
- **Putting one back writes the version back whole** (recorded), whatever has happened since. So it goes through the outbox like any write, a try after a lost answer changes nothing further, and right after it the kit takes off what may not come back: a transfer while the operator has them off (`kit:agents` would not look again), a hand-over to an agent since deleted, and a voice setup no longer offered, where the current one stays. The page says what it left out for `kit.agents.restore_note_hours`.
- **Saved keys come back as they were in that version.** The kit never sees them and cannot tell, so the question before putting one back says so. Someone who changed a key because it leaked should not put an older version back without changing it again.
- **Not part of a version:** the knowledge it answers from (recorded), its web addresses and who is emailed. They stay as they are.
- **Refused here first:** a version that is not there, the one in use, and any while the agent's last change is still on its way, which would otherwise land on top. If Telenow refuses, nothing changed and the agent goes on as it was. If it refuses a write taking something off afterwards, the agent shows that write as failed with its retry, and the operator's page goes on counting a transfer it still holds.
- **Who saved a version is not shown:** Telenow only knows the kit's key, and the kit does not keep who pressed save.
- **Outcome words:** the release writes `answered`, `no-answer`, `busy` and `failed` on real calls. `completed` and `no_answer` in some older recordings came from a test script's own seed. Both read the same in the console and the email.
- **Not proven:** a real phone call, so per-version results come from seeded calls on the release's database.
- **Next:** stage 6 below.

**Agent form, stage 6: call steps** (kit API 0.33.0, 190 operations, 11-04). An agent can follow steps instead of one set of instructions. A customer draws them on a map, or works from the list of steps on a phone, and each step has its own form (`console/src/agents/flow`; the map's library loads only with this page).
- **Offered:** a conversation, gathering details, keypad choices, saying a line, choosing a route, a web function, a code step, a transfer (behind the operator's switch), handing over to another agent, ending the call, and notes on the map. Exits go by what the AI decides, a rule, a key pressed, how a function went, or anything else, in the order the customer sets.
- **The kit has its own shape** (`App\Kit\Agents\AgentFlow`): `{start, steps, exits}`, turned into Telenow's graph on the way out and read back through the same list of what it knows. A step's own model, tools, guards and so on are never written and never read, whatever a stored graph holds.
- **A draft stays in the kit** (`agents.flow_draft`). It need not be finished, makes no version, and calls go on using what was published. A key typed into a draft is sealed at once and read back only as its mask.
- **Telenow stores any graph as sent, and a bad one is dropped on every call** (recorded). So nothing is published without passing the kit's checks and then Telenow's own check (`POST /api/agents/flow/validate`, called with no key in the graph). What would lose the graph is refused beside its step. What may misbehave is answered with a way to the step and does not stop it. If Telenow cannot check, it is not published. Its words go through a tidy-up, and any naming the platform are replaced with the kit's own. If Telenow refuses the save, what calls use stays as it was and the attempt is kept as the draft.
- **Keys in a step follow Telenow's rule for keys anywhere in an agent:** a key is matched by name, address and mask, so a step can be renamed, moved or rewritten. If the address changes, or a web address appears in that step's words or in the voicemail line, Telenow refuses the whole save (recorded), so the kit refuses it first, beside the field. A step's name cannot hold a web address for the same reason. Someone without the key cannot send a sealed one to an address of their own, in a draft or out of it.
- **What may not stay is taken off:** with the operator's transfer switch off, a transfer step cannot be published, and turning the switch off ends each transfer step with its no-answer line, keeping the graph good. Deleting an agent takes it off every hand-over; a hand-over left with nobody ends the same way. A version put back goes through the same check, and the page says what it left out.
- **Costs an operator should know:** choosing an exit by AI uses a platform model and is billed like other AI, so a flat price per minute does not pass it on. A markup does.
- **Left out, and why:** an audio step (the kit has no audio library), a sub-agent step (a conversation with its own tools, which needs per-step tools first), a step's own model, voice, knowledge, tools and behaviour, silence guards and multi-digit keypad collection, wait modes, the routing model, line translations, templates, Telenow's AI helper, testing a draft before publishing, and the capture formats that only suit India (`phone`, `pincode`, `vehicle`).
- **Not proven:** a real phone call through steps. A browser call on the release failed before reaching a step, so parsing is proven only by the release's own check, which says it reads a graph "exactly as calls will". Not run live: a function step reaching a real address (the test boot blocks outside network), a code step, a transfer or hand-over during a call.
- **Next:** campaign extras below.

**Campaign extras** (kit API 0.34.0, 194 operations, 11-04). What a campaign can do after it is made.
- **Its settings can change** (`Campaigns::update`, through the outbox): caller ID, calling window and time zone, voicemail handling, attempts per person, how long before trying again, how many calls at once. Telenow clamps what is out of range without saying and lets a cancelled campaign be changed (recorded), so both are refused here, with the kit's own limits in `kit.campaigns`. It replaces the window each time and clears it if none is sent, so every setting goes each time. If it refuses, what the campaign had stays. Changes apply to the next calls.
- **Calling people again** (`CampaignRetries`): those nobody answered, or everyone whose call failed. A customer is asked first, with how many and that each call is charged. A finished campaign reopens; a paused one stays paused. It is asked once and answered there and then, not queued: Telenow has no idempotency key here, so an automatic second try could call people again whose new call has already failed. If the answer is lost, the customer is told to look before asking again.
- **Telenow only says how many came back, and takes a number back once** (recorded), so the kit marks people the same way (`campaign_targets.retried_at`). They wait until a newer result comes for that number. `kit:campaigns` keeps reading while anyone waits, on a finished campaign too. Its own count of who did not answer is two where a retry takes three (it leaves out voicemail), so the page counts from the kit's copy.
- **Its people** (`CampaignPeople`): one group at a time (waiting, answered, not answered, failed), how many stand where, and a CSV download of what is showing. The first column is `kit.campaigns.phone_columns[0]`, so the file can be a new campaign's list. A cell a spreadsheet would run as a formula is written as text, a phone number stays a number. It is read a page at a time.
- **A carrier's words never reach a customer.** Telenow's outcome can hold them (recorded: "insufficient balance", which a customer would read as being about their own balance). Only the outcomes in `kit.campaigns` are shown and any other reads as failed. The kit keeps what Telenow said.
- **How it went** (`CampaignProgress`), read only when asked for: who was reached and why the rest were not, time on calls, which attempt reached them, which hours worked. Only what is named there is answered, so nothing Telenow adds later reaches a customer. Its figures hold what it charges the operator (`chargeUsdTotal`, `talkedChargeUsd`) and how long each part of its service took: neither is shown.
- **A write that was given up no longer holds up the next ones for good:** a campaign has no retry button, so the next action sends it again.
- **Left out, and why:** a list from a sheet, a CRM or a database (it reads through a connection, and making one takes a signed-in person's token: a kit's key gets 401, recorded), WhatsApp campaigns (not in Telenow's released document), what a campaign cost (Telenow's figure is the operator's cost, and the kit's wallet goes call by call with nothing naming the campaign), each person's call length, replies and a link to their call (Telenow lists them only as pages of the whole campaign, with no way to look up one person).
- **The consent ledger is the next part below.**
- **Not proven:** a real phone call. People were settled on the release's database with the words its own finalisers write, with the calling window kept shut so nothing dialled, so time on calls read zero on the real release. Those figures come from the recording, which had seeded calls.

**Consent ledger** (kit API 0.35.0, 199 operations, 11-04). Console → Consent: a customer's record of who agreed to be called, and whether their calls need one. It matters for the US, UK and Europe. Off by default.
- **A record is never changed or removed, only withdrawn** (`App\Kit\Consent\ConsentLedger`, `consent_records`). It is what they show after a complaint. Telenow has no list of them, only one number's history, so the page reads the kit's copy.
- **Recording one or a file.** Written consent needs the exact words agreed to. Telenow stores a date in the future and an end before the start without saying (recorded), so both are refused here. A file names one kind of consent and one set of words, and each line may have its own `consent_date` and `expires_on`. Bad lines are skipped and counted, and one already on file is not added again, a withdrawn one included, so an old file sent again cannot bring back someone who has since said no.
- **A number needs its `+` and country code.** Telenow's consent check compares numbers exactly, so `+1415…` and `1415…` are two numbers to it, either way round (recorded), while its do-not-call check compares digits. Consent numbers are always written with the `+`, and adding people to a campaign says how many have none, since consent on file can never match them.
- **Telenow has no bulk route and no idempotency key,** and keeps the same record twice (recorded). So a file is saved at once and sent by `kit:outbox`, `kit.consent.push_per_run` a go, and the kit's id travels in the evidence: a try after a lost answer looks for it first. Until a record arrives it says so and does not count.
- **Withdrawing** goes by number, as Telenow does: all of that number's records, onto the do-not-call list in the person's own name, with its waiting calls dropped (recorded). It has its own place in the outbox, so a long import cannot hold it up, and records of that number not yet sent are never sent. Telenow's change feed holds back what has only just changed (found on the real release), so the kit reads that one number straight after and the list shows it now.
- **Whether calls need it** (`ConsentRule`): the customer's choice, for someone who manages the account, after being told how many waiting calls it stops. The operator can require it for everyone on Admin → Catalog, and `kit:consent` tells each account within minutes, new ones too. The change goes in the audit log. If the operator's own Telenow account requires it, a sub-account made after that starts required, and none can turn it off (403, recorded): the kit keeps that as an answer and says who holds it, and does not keep trying.
- **Who sent it, as proof.** Telenow's own evidence names only the kit's server and key, so who recorded it, from which address and browser, goes beside it with the customer's note. None of Telenow's part is shown to a customer.
- **Left out, and why:** a download of the ledger (the search and each record's words answer a complaint for now), a consent column on a campaign's list (a tick for a whole file is too easy to misuse), reading records back from Telenow (only the kit writes them with a customer's key).
- **Worth knowing:** Telenow's code applies the same check to a single call, the softphone and a live transfer, so a transfer's destination number needs consent too while it is required. For a call from the browser this was then seen on the release (People on the phone, below); for a transfer it is still only from reading its code.
- **Not proven:** a real phone call: the waiting and held-back counts are the release's own and its calling window was kept shut. The admin switch was tested in the suites and on the release through its code, not clicked in a browser.

**People on the phone** (kit API 0.36.0, 210 operations, 11-04). A customer's team calls people from the console, writes on calls, and sees who is on a call now.
- **Console → Phone: calls from the browser, from the account's own numbers** (`App\Kit\Softphone\BrowserCalls`). The kit's server places the call with the account's own key (Telenow's `manual` mode) and answers the browser only what it joins with, as a test call does. Mute, hang up, and transfer to another number. None is placed until the operator allows them on Admin → Catalog, which goes in the audit log. Each is a phone call the operator pays the carrier for.
- **What the kit checks before Telenow is asked.** Telenow dials whatever it is given, words included (recorded), so only a number with its `+` and country code goes. The kit's copy of the do-not-call list, and consent on file for that exact number while calls need it, both say no first. Telenow checks both again by its own lists (403, recorded), and the kit says so in its own words. Telenow takes a caller ID only as it stores it, with no `+` (recorded). A number takes two calls at once (429, recorded), and the kit says to try another.
- **Charged like any call.** Telenow rates a browser call as `callType: manual`, channel `softphone`, with only a telephony part (recorded). While customers are charged, a plan with no price for that places none, so no call goes uncharged. Admin → Catalog names those plans. A plan priced by the minute charges nothing for a call that never connected (0 s).
- **Hanging up ends it at Telenow too**, not just in the browser, and so does leaving the page or the browser's side failing, so nobody is left on a line nobody is on. Ending and transferring are for whoever placed it, or someone who manages the account. A transfer also needs the operator's yes to transfers, and the number it goes to is checked as a call to it would be. One member places at most `kit.softphone.calls_per_minute` a minute.
- **Kept at once.** The call is in the kit's calls the moment it is placed, with who placed it, and read like any other once it ends. Telenow's change feed lists it again and keeps who placed it. A browser call has no recording, transcript or analysis to wait for (all `not_applicable`, recorded).
- **On calls now** (`App\Kit\Calls\LiveCalls`), above the calls list: by the account's agents, and the rest, which Telenow counts under a stand-in agent of its own for browser calls (recorded). Telenow is asked at most once per account every `kit.calls.live_cache_seconds`, however many pages are open.
- **Wrap-up, notes and follow-ups on any call** (`App\Kit\Calls\CallWork`), an agent's or a browser call. A wrap-up is a few words, from the account's recent ones or new. Notes are kept as written. A follow-up is something to do by when, on its writer's own list (Console → Follow-ups), and a colleague can tick it off. These are the kit's own, never sent to Telenow: its comments work with a key but name the key's stand-in user, never the member (recorded).
- **One person's calls by number.** The calls list finds every call from or to a number however either end was written: digits are kept beside each number, and calls already kept get theirs when the migration runs. There is also "only calls I placed".
- **The operator's Telenow server needs `PUBLIC_BASE_URL` set**, or it refuses every browser call (recorded). The kit then says it could not be placed right now.
- **Left out, and why:**
  - Listening in: added after, with its own player (Listening in, below).
  - Incoming calls ringing in the browser, presence, a wallboard and departments: Telenow serves those only to its own signed-in users, not to a key.
  - Keypad tones and hold: `@telenow/client` has neither.
  - Follow-up reminders by email.
  - Whether Telenow records a browser call is its own setting for the key's user (on by default) and cannot be set with a key, so the page says calls may be recorded.
- **Not proven:**
  - A real phone call with sound. Calls went through a stand-in carrier, and the browser pane blocks the microphone, so the browser joined no audio.
  - The charge used a rating seeded on the release's database the way its own rater writes one.

**Listening in** (kit API 0.37.0, 211 operations, 11-04). A manager hears both sides of any live call of their account, from the calls list or the call's page, and cannot be heard. Telenow has no conference calling (no way to add a third person or merge calls; its transfers hand the caller off and drop out), so this is the part of a call centre's phone tools it can give now.
- **Calls on now are listed** (`App\Kit\Calls\LiveCalls`): Telenow's live calls (`listCalls` with status active, recorded) kept in the kit's copy as the feed keeps them, so each has the kit's id, beside the counts by agent. Telenow is still asked at most once per account every `kit.calls.live_cache_seconds`.
- **Listening** (`App\Kit\Calls\ListenIn`): the kit asks Telenow with the account's own key for a one-time ticket, good for 30 seconds, and answers the browser only the address of Telenow's listening socket. The browser plays both sides as they come (`console/src/calls/monitorAudio.ts`): each 20 ms of each side is a byte for the side and 8 kHz μ-law (recorded with a stand-in carrier playing a known sound: the exact frames came through, in order). The socket says when the call ends, and a ticket used twice or late is refused (recorded).
- **Who may:** only the account's managers, as Telenow lets only an account's owner or admin. Not for calls in a browser or a chat, which Telenow sends no sound for (`kit.calls.listen_unheard_channels`, from its code). Who listened, and when, is kept and shown on the call's page. Listening opens no phone leg, so it costs the operator nothing and needs no switch of theirs.
- **The listening address names Telenow's host,** as a test call's does, since the browser connects to it directly.
- **Left out, and why:**
  - Speaking into a call, to the agent alone or to both: Telenow has no such route.
  - The call's words as they are said: Telenow streams them (`stream-ticket`), not added here.
- **Not proven:** a real phone call. A stand-in carrier played the customer's side, so the agent's side was never heard. In the browser check the throwaway release had no TLS, so the page's socket was pointed at the same address over `ws://` from the debugging console. Nothing in the kit was changed for that.

**An agent calling one person** (kit API 0.38.0, 213 operations, 11-04). On an agent's page, Call someone: the agent calls this person now (`App\Kit\Agents\AgentCalls`, Telenow's `initiateCall` with the account's own key). Every member may, as they may start a campaign.
- **The form:**
  - the number;
  - which of the account's numbers to call from (the agent's own unless another is picked, and one must be picked when it has none);
  - a field for each detail the agent's words use: those declared on it, then each `{placeholder}` of its instructions and outbound opening line, but those Telenow or a look-up fills;
  - if wanted, another opening line, and whether to leave the voicemail message or hang up when a machine answers.
- **What the kit checks first:**
  - **The account and the agent:** the account can use the service now, and the agent is saved.
  - **The number:** checked as a call from the browser is (`App\Kit\Calls\DialCheck`, moved out of `BrowserCalls` unchanged). Telenow dials words and numbers with no `+` (recorded).
  - **The caller ID:** one of the account's own live numbers, sent by its row.
  - **The details:** only ones the agent uses, and those it needs filled.
  - **Telenow's own refusals** (its do-not-call list, consent, a stopped account: all 403, told apart only by their English) come back in the kit's own words.
- **Nobody is called twice:** each call goes with its own Idempotency-Key, and a lost answer is asked again once with the same key, which Telenow answers with the first call (recorded). The same key with another body is refused (422).
- **Kept at once** with who asked for it (`calls.requested_by`), shown on the calls list and the call's page, and read like any other once it ends. One member asks for at most `kit.agent_calls.calls_per_minute` a minute. Charged like any call; one the plan cannot price waits on the operator's price list, as a campaign's would.
- **Left out, and why:**
  - Telenow's queued form of the call (`queue: true`): it answers no call to follow, and campaigns already call many people.
  - Ending an agent's call from the console.
- **Not proven:** a real phone call. On the release a stand-in carrier took the dial, and, so the agent's AI providers could be set up, the release had fake keys and every request it sent outward went to a dead proxy. No call ever connected.

**Realtime voices** (kit API 0.41.0, 223 operations, 11-05). One model that listens, thinks and speaks on a call, in place of the voice pipeline (a language model, listening and a voice): OpenAI Realtime and Gemini Live at Telenow (`App\Kit\Catalog\RealtimeCatalog`, `App\Kit\Agents\AgentRealtime`).
- **Only while the platform runs them.** Telenow runs realtime voices only where its operator switched its engine on (`S2S_ENGINE_ENABLED`; its `getRealtimeCatalog` says `enabled`, and lists none while off). It saves a realtime voice on an agent either way, and then runs every call on the pipeline (recorded). So customers pick one only while the platform runs them; an agent left on one says its calls use its voice setup, and can always be taken off it.
- **The copy.** `kit:catalog` reads the roster with the catalog: each provider, its models and each model's voices, keeping only the fields the kit names (`kit.catalog.realtime.fields`: never a price, a key's name or a description). A voice whose name names the platform is left out. A roster that cannot be read stays as it was and is read again at the next run.
- **The operator's say** (Admin → Catalog → Realtime voices): which providers and which of their models customers may pick, under names of the operator's own; none until offered, every model of an offered one until switched off; a name that names the platform is flagged and cannot be offered. While the platform runs none, the page says so and the choices are kept.
- **On an agent** (its voice setup → Realtime voice): a provider, a model and one of its voices (or the model's own), and whether a call falls back to the voice setup when the realtime voice cannot start (on unless switched off). The voice setup stays whole beside it: calls fall back to it, and Telenow requires it (recorded).
  - Kept in Telenow's `s2s_config`, replaced whole when sent (recorded): while the engine stays, the kit changes only its own keys and leaves the rest, a key Telenow shows masked (`••••1234`) included, which Telenow keeps (recorded); another engine starts from nothing, as Telenow refuses a mask when the engine changes (recorded). None is `{}`.
  - Checked in the kit: an offered provider and model, one of that model's voices, a switch.
- **Hearing one** (`previewRealtimeVoice`): a short clip from one short realtime session, with the account's own key; at most `KIT_REALTIME_PREVIEWS_PER_MINUTE` a member (default 6), and one every 6 s an account at Telenow (recorded). Telenow's refusals come back in the kit's words.
- **Check the voice setup** on an agent with a realtime voice adds that part, never checked: Telenow's check probes the pipeline only, whatever the engine (recorded).
- **What it costs:** Telenow meters a realtime engine as language-model tokens (audio and text), so a realtime call's engine is in its language-model charge (read from its code, `usage_metering.rs`). An operator's price list prices it there; a per-minute language-model price would not follow its cost.
- **Not proven:** a realtime call, or hearing a realtime voice, on a real vendor: no provider had a key on the release, so each attempt came back "could not be previewed".

**Each provider's own settings, voices by model, a check of the voice setup, and stars** (kit API 0.40.0, 222 operations, 11-05). An agent's voice setup now carries each chosen provider's own settings: the voice's model, quality, speed or language, the listening model and its language, the language model's temperature.
- **Drawn from Telenow's catalog** (`configFields`, `App\Kit\Catalog\ProviderSettings`), so a setting Telenow adds is offered with no change in the kit.
  - The copy keeps only the fields the kit names (`kit.catalog.settings.field`; never a placeholder, which can hold an example address). It never keeps one Telenow marks sensitive (a key, a token) nor a json one (a vendor descriptor).
  - Nor one Telenow itself keeps as a secret or reads as where a call is sent, whatever the catalog says (`kit.catalog.settings.never_keys`, `never_key_endings`, mirrored from Telenow's `src/utils/secrets.rs`): an access key id (the catalog does not mark Bedrock's or Polly's sensitive), a base URL, an endpoint, a region. A customer's value there would send the platform's requests, or a key, where they choose. Nor one whose key names the platform: customers name a setting by its key.
  - The field that holds a provider's model is marked as such (Telenow's `models[].configKey`; Polly calls it `engine`).
- **The operator's say** (Admin → Catalog → a provider → "Settings customers may change"):
  - which settings, and of one with options, which options;
  - until chosen, a choice, a number and a switch are offered, and free text is not (it can carry an address or an id);
  - a label or help that names the platform is never shown: a setting's key, an option's value or, when that names it too, "Option 2" stands in.
- **What customers see:**
  - an option by a name of the kit's own (`o` + 16 hex), never Telenow's value: a model's id can carry the platform's name (`telenow-v1`);
  - a default shown beside each setting;
  - the less common ones under "More settings".
- **Checked in the kit:** Telenow keeps any value and any key it is sent, a temperature of 7 or a speed of 9 included (recorded). So the kit checks:
  - each key is an offered setting;
  - each option is an offered one;
  - each number is within its bounds;
  - each switch is a switch;
  - text is at most `kit.catalog.settings.text_max_length` characters (trimmed, as every request's text is);
  - null takes a setting back to the provider's default.
  - Settings come only through `providerSettings`; one sent inside an agent's other settings is dropped.
- **Written into Telenow's blob** (`llm_config`, `stt_config`, `tts_config`, `App\Kit\Agents\AgentProviderSettings`):
  - read as Telenow holds it and only the keys sent changed, so a setting the customer is not offered stays as it is, and a key Telenow shows masked (`••••1234`) goes back as it came: Telenow puts the stored key back for it (read from its code, `unmask_sensitive_in_place`, in the 11-03 release; not recorded);
  - a provider changed starts from nothing: Telenow keeps some of the last provider's settings when the blob is not sent (recorded), so the kit always sends it, with no mask (Telenow refuses every mask when the provider changes, read from its code).
- **Voices by model:** each voice provider's list is read once for each model its model setting offers, default first (`catalog_voices.models`).
  - A voice a list tags for another model, or one that names its models without this one, is not this model's.
  - An agent's voice must be one of its chosen model's voices (the field's default when none is chosen). Telenow takes a voice of another model (recorded); the kit refuses it with `catalog.not_for_model`. An account's own voice speaks with any.
  - The voice picker asks for the chosen model's voices.
- **Check the voice setup** (an agent's page; `checkAgentStack`, Telenow's preflight; `App\Kit\Agents\StackCheck`): Telenow connects to each part of the saved setup with the account's key.
  - Each part shows as working, not working (whose problem: the account's key, the account's settings, the provider, the service, or unknown) or not checked, in the kit's words: never a provider's message nor which key was used.
  - The last check is kept on the agent and shown before anyone checks again.
  - Telenow takes one check an account every 3 s (recorded) and the kit `KIT_STACK_CHECKS_PER_MINUTE` a member (default 6). None while the account is paused: a check spends a few tokens of a language model.
- **Stars** (Console → Voices; `App\Kit\Voices\VoiceStars`, `voice_stars`): each member's own, at most `KIT_VOICE_STARS_PER_MEMBER` (default 500).
  - "Starred only" lists them, and an agent's voice picker shows them first.
  - Telenow's stars belong to the key's creator, one for a whole sub-account (recorded), so the kit keeps its own.
- **Not proven:**
  - a check that reaches a real vendor: on the release no provider had a key, so the language model and listening came back "not set up on the service", and Telenow has no check for its own voices;
  - a real phone call speaking with a chosen model and its settings. On the release a stand-in for Telenow's own voice engine took the requests.

**An account's own voices, cloned from people who agreed** (kit API 0.39.0, 219 operations, 11-05). Console → Voices → Your voices (`App\Kit\Voices\OwnVoices`): a voice of a real person, made at Telenow as a voice profile with the account's own key, recorded, and cloned into the voice providers the operator offers that can clone. Agents then speak with it.
- **The operator's say** (Admin → Catalog): off until allowed (`App\Kit\Voices\Cloning`). At most so many clones an account: `KIT_CLONES_PER_ACCOUNT`, default 10, never past Telenow's own 50 an account. Off stops new voices and clones; those made keep working and can always be removed or deleted.
- **Who:**
  - **Managers (the account's owner)** add, record, clone, remove and delete.
  - **Every member** sees the voices, hears their clones and puts a ready one on an agent.
  - The person's name, who confirmed their release and when are shown to managers only.
- **The person:**
  - Always named, with how they relate to the account (employee, voice actor, customer, someone else).
  - A manager confirms the account holds their signed release (`KIT_VOICE_RELEASE_VERSION`).
  - Telenow keeps that as its consent record. The kit keeps who confirmed it and when, after the voice is deleted too, and the audit log has each step.
  - Telenow refuses "this is my own voice" from a key (recorded), so a member's own voice is named like anyone's.
- **The recording:**
  - Made in the browser (MediaRecorder; the microphone is let go as soon as it stops) or uploaded.
  - Sent to Telenow as it came and **never kept in the kit**.
  - Telenow reads it with ffmpeg and takes at least 3 s, up to 25 MB, or what PHP takes, if less (`upload_max_filesize`; 2 MB on a stock PHP, about a minute of WAV).
  - `KIT_VOICE_RETENTION_DAYS` asks Telenow to delete the voiceprint after so many days; unset, Telenow keeps it until it is deleted.
- **Cloning:**
  - Into a provider Telenow says can clone for the account (`checkVoiceCloning`, read again after `KIT_CLONE_TARGETS_CACHE_SECONDS`).
  - Once a provider for each voice, one at a time for an account. Telenow makes a second clone on the same provider without removing the first; that one then belongs to no account and any account can use it by its id (recorded). So the kit asks for the first to be removed.
  - Free at Telenow: nothing is charged to the wallet (read from its code).
- **On an agent:**
  - Customers name an own voice `own:<id>`. The agent holds Telenow's `profile:<id>`, which Telenow resolves inside the account only.
  - The provider's own id for a clone is never kept or shown: Telenow lets any account hear a clone by that id (recorded).
  - A voice an agent speaks with can be neither removed from that provider nor deleted (the agents are named). Telenow would quietly speak the provider's default voice instead (recorded).
- **Left out, and why:**
  - **ElevenLabs' shared voice library:** with no customer key each add takes a slot on Telenow's own ElevenLabs account for good, with no cap, tracking or removal. Once those slots fill, cloning fails for every organisation on Telenow.
  - **Customers' own provider keys and speech endpoints:** providers are the operator's choice.
  - **Voice models, a check of the voice setup, stars:** see "Each provider's own settings" above.
- **Not proven:** a clone on a real vendor (ElevenLabs, Cartesia and others) or a real phone call in a cloned voice. On the release a stand-in for Telenow's own voice engine took the clones and spoke for them.

**Transfers are the operator's choice, and off until turned on** (11-04). A transfer is a second phone call, charged to the operator at the carrier's real
price. A markup on phone charges passes that on to the customer; a flat price per minute does not, so a transfer to a dear country can cost more than
it earns.
- **The switch** is on Admin → Catalog (`transferAllowed` on `saveCatalogCuration`), with what it costs beside it, and each change goes in the audit log.
- **While it is off** a customer sees no transfer section and the API refuses turning one on (422 on `settings.transfer.enabled`). Turning one off always works.
- **Turning it off takes transfer off every agent that has it** (`App\Kit\Agents\AgentTransfers`, `php artisan kit:agents` every minute): through the same outbox
  as a customer's save, only that tool, at most `kit.agents.transfer_sweep_per_run` a run. It reads the table only after the switch was saved off, and stops once none are
  left. The admin page shows how many still have one. A write Telenow gave up on is tried once per save of that page, then stays counted.
  Customers would set their transfers up again if it is turned back on. A closed customer's agents are left alone: its key is gone.

**Sign-in options, done** (kit API 0.25.0, 173 operations, 11-03). The operator picks how customers sign in and sign up
on Admin → Sign-ups: a password, an emailed link, an emailed code, a code by text or WhatsApp, Google, Microsoft or any
OpenID Connect provider, in any mix.
- **An emailed code** (`requestEmailCode`, `useEmailCode`, `verifyEmailSignup`; the `email_code` method, `kit.signup.email_code`):
  - Signing in: asking answers the same whether or not a customer uses that address, and only customers get a code. A
    code is 6 digits, kept only as a keyed hash, works once, for 10 minutes and 5 tries, and a newer one replaces it.
    The try is spent before the code is compared, so tries sent at once cannot share it.
  - Signing up: with this method on, the sign-up confirms its address by a code and not a link. It is still held until
    then, and still waits for staff in approval mode.
  - It needs email on; the settings refuse it otherwise, and the bot check can guard asking (`sign_in_email_code`).
- **WhatsApp as a sender** (Admin → Texts and WhatsApp, `App\Kit\Sms\Drivers\WhatsAppCloudDriver`), in the same ordered
  line as SMS, so either can be the other's fallback:
  - Meta's Cloud API with the operator's own phone number id, access token (stored encrypted, never answered back) and
    approved Authentication template. `kit.sms.whatsapp_cloud` holds the address and version.
  - WhatsApp sends business messages only through approved templates, so a notice that is not a code (such as "this
    number already has an account") needs a second, optional template. Without one, it passes to the next sender.
  - Pages say where to look (`signInCodeChannel`): text, WhatsApp, or "your phone" when both are lined up, since the
    kit cannot know which will succeed.
- **Microsoft** is a preset (`oidc.presets.microsoft`), for work, school and personal accounts on one app:
  - Microsoft's issuer names `{tenantid}`, so the token's `iss` is checked against its own `tid` (a UUID), and the
    discovery document against the template.
  - It has no `email_verified`, and its `email` claim is not proof of ownership. An address links to an existing
    customer only from a personal account (the tenant in `verified_email.tenants`) or with `xms_edov` true. A person who
    signed in before is known by their `sub`, which needs neither.
  - Admin → Sign-in providers says what to register, since a work account needs the optional claims `email` and
    `xms_edov`.
- **A password that is off is off.** Before this, turning passwords off only hid the box: a customer's password still
  worked, and so did "Forgot your password?", which signs the person in. Now the server gives a customer the same
  refusal as a wrong password, sends them no reset link and stops an earlier one, and the page shows no box or reset
  link when it has another way to offer. Staff keep theirs, and with nothing else to offer the box stays rather than
  leave a page with no way in.
- Checked end to end on a copy, in a browser and at phone width, with stand-ins for Meta and for email: adding the
  WhatsApp sender, turning passwords off, signing in with an emailed code and a WhatsApp code, signing up with an emailed
  code, and adding Microsoft, which read Microsoft's real configuration.
- Not in this: a real send through Meta and a real Microsoft sign-in, which need the operator's token, approved template
  and app registration; they are built from Meta's and Microsoft's documents and proved against stand-ins, with
  Microsoft's real discovery document. Apple, GitHub and Facebook are not OpenID Connect presets here. A single-tenant
  Microsoft app added by its issuer would fail on `email_verified`: use the preset.
- **Invites follow the same choice** (`getInvite`, `acceptInvite`; `App\Kit\Team\Team`). Someone whose email already has an
  account signs in to it on the invite page, any way the operator allows, and then joins with one press; a person who
  only ever used codes or a provider can now join a second account. The link alone never joins an account that exists,
  since it may have been forwarded, and someone signed in as another person is told so and can sign out. With passwords
  off someone new gives only a name, and a customer's password proves nothing. A provider or an emailed link comes back to
  the console, so the page says to open the invite again. Staff invites are unchanged: staff always use a password.

**Telenow's 1.1.0 document, done** (kit API 0.23.0, 170 operations, 11-03). The kit now builds on Telenow's released
1.1.0, whose `listProviderVoices` gives each provider's full list instead of the catalog's hand-picked voices (measured on
production's open route 11-03: Cartesia 1,050, Smallest 269, Soniox 200, Sarvam 38 on its default model, Google 30, xAI
28, ElevenLabs 21).
- **The lists live in rows** (`catalog_voices`, `App\Kit\Catalog\CatalogVoices`), narrowed and paged by the database, and
  no longer in the catalog's one setting.
- **What Telenow lists is what customers get.** A hand-picked voice adds its recommended mark (those come first) and its
  details to the same voice, and stands in only until a list has been read. A hand-picked voice the list has dropped is
  gone: Telenow's own sync removes what a vendor stops listing.
- **A failed read loses nothing.** A list that cannot be read, or that says nothing (`origin: none`), leaves what the kit
  has, tells the operator which provider on Admin → Catalog, and is tried again at the next hourly run. Only `offered: false`
  empties a provider. A list is replaced in one transaction.
- **Picking one voice among a thousand** (`console/src/voices/VoicePicker.tsx`, on an agent's voice setup and Admin →
  Catalog's default): the first page in a select; words, a language and "Show more" when they do not all fit. A saved voice
  off the page shown keeps its name. The admin's list is its own operation (`listCatalogVoices`), and Admin → Catalog sends
  each provider's count (`voiceCount`) and not its voices.
- Not used yet from 1.1.0: `listProviderModels` (a voice's model is chosen from the catalog's own model setting instead; see "Each provider's own settings"), `offered` on `browseVoiceLibrary`,
  and the `geo` ranking (the lists keep the order Telenow gives the kit's server). `filledByLookup` needs nothing: the kit
  already reads `required`, which is false for a variable a lookup fills.
- A name carrying `KIT_TELENOW_NAME` is checked when a list is copied, so changing that setting applies from the next copy.
- Recorded on Telenow's real binary (`contract_k1voices.py` on the build server, strict against 1.1.0, 29/29) with six
  real lists from production's open route in its throwaway database, the others its hand-picked lists.

**On other databases, checked** (MySQL 8.0, MariaDB 10.11, PostgreSQL 16, 11-03). The suite runs on SQLite, which enforces no
column's length, keeps JSON as it was written, takes any text as an id and has no clock of its own. So it also runs on each
database the setup wizard offers, on a throwaway empty database and never a real one (it wipes what it runs on):

```bash
DB_CONNECTION=mysql DB_HOST=<host> DB_DATABASE=<empty> DB_USERNAME=<user> DB_PASSWORD=<password> php vendor/bin/phpunit
```

`DB_CONNECTION` is `mysql`, `mariadb` or `pgsql`. Three tests need a database with no tables and skip themselves otherwise, so
on the empty database first run `--filter test_a_database_server_is_connected_migrated_and_saved`, which is the setup wizard
on that server, then on another empty one `--filter 'test_after_the_install_a_failed_read_is_an_error|test_a_database_session_store_waits_for_its_table'`.
Try it with the server in a time zone that changes its clocks, and in one ahead of UTC. `tests/Feature/Kit/AnyDatabaseTest.php`
holds what only shows there:
- the kit's session is UTC (`DB_TIMEZONE`), or MySQL refuses every write in the hour a host's clock change skips;
- anything stored as JSON is `longText`, because MySQL's json type reorders keys and screens show them as written. The one
  exception is `wallet_entries.details`, which `SpendLimit` reads a path inside, and only a json column allows that on PostgreSQL;
- an id is `char(36)`, not the native uuid type of PostgreSQL and MariaDB, so a bad id in a request finds nothing and is not an
  error (`foreignUlid(..., 36)` is the foreign key's way to say it);
- an error column is `text` and a gateway's payload `longText`, because a message or event from elsewhere can be any length;
- a malformed id on any route is never a server error, whichever database it reaches.

**6d, done: knowledge** (kit API 0.11.0).
- **Console → Knowledge:** customers make knowledge bases and add text, files or public web pages.
  - Files: PDF, Word, text, Markdown, CSV, JSON or Excel, up to Telenow's 20 MB and never more than this host lets
    PHP receive. A file is sent on through the outbox and then not kept.
  - Web pages: https only. Telenow fetches a page when it is added, so a page it cannot read fails at once, in the
    kit's words.
  - Documents and bases are deleted only after asking. Deleting a base names the agents that stop answering from it.
- **Whether a document was read.** Telenow reads text in the background (a table at once).
  - `php artisan kit:knowledge` (every minute) reads each base's change feed: every run while a document is still
    being read, else every 15 minutes. It brings the outcome, deletions included.
  - Customers see "Saving", "Being read", "Ready" or "Failed" with the kit's own reason, never Telenow's error text,
    which names the vendor's address. A failed document can be tried again.
  - The same run removes a base Telenow no longer has.
- **An agent's knowledge** is the set of bases ticked on its page, sent to Telenow whole. It waits while one of
  them is still being saved.
- Recorded on Telenow main's binary. The build server reaches no embedding vendor, so text failed to embed there;
  that is the failure path the kit shows. A CSV table was indexed, which is the success path.

**6e, done: campaigns** (kit API 0.12.0).
- **Console → Campaigns:** customers make a campaign and pick:
  - one of their agents;
  - a caller ID (one of their numbers);
  - a daily calling window and time zone (the browser's own to start with; older names like `Asia/Calcutta` are
    taken, as Telenow takes them);
  - voicemail handling;
  - attempts per person.
  The choices, defaults and caps come from the backend (`limits` on `listCampaigns`, from `config/kit.php`).
- **People come from a CSV:**
  - a header row, with the number in one of `kit.campaigns.phone_columns`;
  - every other column is a variable the agent can use;
  - size and row caps come from config.
  Each person keeps the kit's own id, which travels to Telenow and comes back on the call's result. The page shows
  what sending the list met: added, on the do-not-call list, without a number, already on it.
- **Running it:**
  - start, pause and resume;
  - cancel and delete happen only after asking;
  - a running campaign that is not calling says why (its window, and when it opens);
  - who can be called now and the columns the agent uses are read live from Telenow.
- **Results.** Telenow sends no campaign events, so `php artisan kit:campaigns` (every minute) re-reads each
  campaign still in play, and each finished one with people still waiting for a result, every
  `read_minutes`. It brings the status, counts and each call's outcome by the kit's id. Outcomes show in the
  console's words.
- **Recorded on Telenow main's binary,** with the carrier stand-in, so calls really were placed and failed. A
  second campaign, outside its window, gave the "waiting for its window" answer and the pause, cancel and delete
  answers.

Slice 7 is being built in parts, one per request:
- **7a:** email;
- **7b:** signups (email and password with verification, magic link, approval modes, configured fields, terms,
  trial credit; the sub-account is made only after verification);
- **7c:** payments (the gateway module, with manual payment built in; a real gateway driver needs the operator's
  sandbox keys to record from);
- **7d:** SMS, and phone sign-in by one-time code;
- **7e:** Google/OIDC sign-in, and captcha (bot check).

**7a, done: email** (kit API 0.13.0).
- **Senders.** The kit sends email through senders the operator lines up (the first sends, the next ones are
  fallbacks).
  - Each sender is a driver from `kit.email.drivers`; its form is drawn from the driver's own fields. SMTP is built in.
  - A sender is saved only after it has sent the person saving it a test email.
  - STARTTLS refuses a server that cannot upgrade, so a password is never sent in the clear by mistake.
  - The list is stored encrypted, and a password is never answered back; left empty on an edit, it is kept.
- **Where to set it.**
  - The wizard has a new Email step: the site's address, who emails come from, and the first sender, or "not now".
  - Admin → Email does the rest: more senders, their order, a test through the whole line, and the recent emails.
- **Sending.** Every email is kept with its body encrypted.
  - It is sent at once; one no sender could send is tried again by `php artisan kit:emails` after each of
    `retry_minutes`, then given up.
  - The body is wiped once the email is sent or given up.
  - Emails are in the brand (name, logo, colours, support address), and values people typed are escaped.
- **Links.** Every link in an email opens the site address the operator confirmed (else `APP_URL`), never a
  request's Host, so a forged Host cannot point a reset link elsewhere.
- **Uses so far:**
  - **Team invites** are emailed when email is on (the link is then never shown); otherwise the link is shown once,
    as before.
  - **"Forgot your password?"** for customers and staff alike:
    - it answers the same whether or not an account uses the address, and the email is made after the answer;
    - the link works once, for `reset_minutes`, and a newer one replaces it;
    - the new password signs the person in and ends their other sessions;
    - asking is slowed;
    - with no email, sign-in pages do not offer it.

**7b, done: signups** (kit API 0.14.0).
- **Who may sign up**, set in the wizard's new Sign-ups step or Admin → Sign-ups:
  - closed (invites only; where a new install starts);
  - open (anyone who confirms their email);
  - approval (anyone, then staff approve each one);
  - only addresses at listed email domains.
  Every mode but closed needs email.
- **The form** is declared by the backend and drawn by the console:
  - the built-in fields (name, email, password, account name);
  - the operator's own fields (text, email, address or a choice; required or not);
  - a terms box, whose version is recorded with the time it was ticked.
- **Ways in** for customers: a password, a link or a code emailed each time they sign in, and a code sent to their
  phone, in any mix. Staff always use their password.
- **Held until confirmed.** A sign-up is held in its own table, not as a user, and a link to confirm the address is
  emailed. Confirming is a click on the page, not the page opening, so a mail scanner that fetches the link changes
  nothing. Only then is the account made:
  - the user (address verified);
  - the customer account and, through the outbox, its Telenow sub-account;
  - the user as its first manager;
  - the trial credit, once.
  In approval mode the confirmed sign-up waits for staff, who approve it (the account is made, and the person is
  emailed) or refuse it (its password is wiped).
- **Nothing leaks about who has an account.**
  - A sign-up with an address that already has an account answers the same, and emails that person instead.
  - What happens is decided after the answer is sent.
- **Housekeeping.** A newer sign-up replaces the older link. Unverified sign-ups are deleted `purge_days` after their
  link expires (`php artisan kit:signups`, daily). Signing up and asking for links are slowed.

**7c, done: payments** (kit API 0.15.0, 118 operations).
- **Ways to pay** (Admin → Payments, or the wizard's new Payments step). The operator switches on any number of
  gateways, each a driver from `kit.payments.drivers` whose form is drawn from the driver's own fields:
  - keys are stored encrypted and never answered back; an online gateway's keys are tried with one harmless call
    before it is saved, and the gateway's refusal is shown in its own words;
  - each has a name customers see, the payment methods it shows, the plans it is offered to, and a place in the
    order;
  - test or live mode: a gateway in test mode moves no money, so it cannot be enabled and is never offered; staff
    run a test payment through it end to end (checkout, return and webhook), which credits no one;
  - an online one shows the webhook address to register, the events to tick, and when the last verified event came;
  - a removed gateway stays in the table, so a late refund or dispute still lands.
- **Built in: bank transfer.** The customer sees the operator's instructions and a short reference to quote. Staff
  confirm with the amount that actually arrived (also one given up on, if the money comes after all), or give it up.
- **Topping up** (Console → Wallet → Add credit): the ways to pay the account is offered (enabled, live, taking the
  kit's currency, serving its plan), within the operator's smallest and largest top-up. Not while the account is
  suspended or the operator's Telenow account is stopped (`kit.payments.blocked_when`); out of credit is exactly
  when it is needed. The customer goes on to the gateway (a redirect, or a form posted there) and comes back to a
  page that reads the payment until it settles.
- **The money rules:**
  - the wallet is credited only by what the gateway says: a signed webhook, or the gateway asked when the payer
    comes back (the return's own query is never trusted; a cross-site POST return needs no session) or again by
    `php artisan kit:payments` (every minute) while it is open;
  - once only, from any state (money taken is money credited); another amount or currency waits for staff (review);
  - a refund (staff's, with a reference so sending it twice refunds once; or one made at the gateway's own
    dashboard) and a dispute (`nothing`, `hold` or `suspend`, the operator's rule) come off once each by the
    gateway's own id; a dispute won gives a held amount back;
  - the payer is emailed when the credit lands; gateway events are kept encrypted for `events_keep_days`.
- **A real gateway driver** is one class and a line in `kit.payments.drivers`. Razorpay, Stripe, PayPal, Paystack, Flutterwave
  and Tap are built (see "Razorpay, Stripe and PayPal" and "Paystack, Flutterwave and Tap" above).
  The module's money rules are proven against `tests/Support/StandInGateway.php`, a test-only driver that does
  everything a real one does over HTTP (keys, hosted checkout, signed webhooks with a timestamp, refunds with an
  idempotency key, disputes); `tests/Support/standin_gateway.php` is its server for the end-to-end copy.

  - a gateway whose driver is later taken out of `kit.payments.drivers` keeps its payments listed; nothing more is
    done through it (no refunds, checks or confirmations), and nothing crashes.

**7d, done: texts and phone sign-in** (kit API 0.16.0, 130 operations).
- **Senders** (Admin → SMS), like email's: drivers from `kit.sms.drivers`, each form drawn from the driver's own
  fields, keys stored encrypted and never answered back. A sender is saved only after it has texted a number the
  staff member gives (not kept). The first in the order sends, the next ones are fallbacks.
- **Built in: any SMS provider with an HTTP API**, described rather than coded:
  - the send address, GET or POST (JSON or a form);
  - the names of its number and text parameters, and the number with or without its `+`;
  - an optional sender id (a DLT header in India);
  - the API key as a parameter and/or a header;
  - for providers that answer 200 even when they fail, a word only their success answer has.
  A provider's own driver (Twilio, MSG91, …) is one class and a line in `kit.sms.drivers`, recorded from its
  sandbox when one is built.
- **Texts are sent at once** through the line and never tried again later (a code would be stale). Each is recorded
  with how it went and every failed sender's reason, never its words.
- **Phone numbers** (Console → Your account): a person adds a number only by proving it with a texted code; proving
  it moves it off any account that held it (numbers are reissued). Numbers are E.164; spaces, dashes, brackets and a
  leading `00` are taken.
- **Signing in with a texted code**, a third way in for customers beside a password and an emailed link (Admin →
  Sign-ups; it needs texts on). Staff never sign in by code.
  - Asking answers the same whether or not a customer has proven that number, and texts only a customer's proven
    number.
  - A code is 6 digits, kept only as a keyed hash; it works once, for 10 minutes and 5 tries, and a newer one
    replaces it (`kit.phone`).
  - Asking is slowed per number and per address, using a code per address.
- Not in 7d: two-step sign-in, and texts for alerts. (Signing up with a phone came in 9f.)

**7e, done: bot check and sign-in providers** (kit API 0.17.0, 139 operations).
- **Bot check** (Admin → Bot check): Cloudflare Turnstile, hCaptcha or Google reCAPTCHA (v2). All three draw a widget
  the same way and confirm a token with the same `siteverify` call, so each is data in `kit.captcha.providers` (its
  script, its browser global, its verify address), not code.
  - The operator gives the site key and the secret (stored encrypted, never answered back); the secret is checked
    with the provider before it is saved.
  - They pick the forms it guards: signing up, signing in with a password, asking to reset a password, asking for a
    sign-in link, asking for a sign-in code.
  - A guarded form shows the widget and sends its token; the backend confirms it with the provider for every
    request. Without a confirmed token the request is refused, also when the provider cannot be reached (it fails
    closed). A token works once, so the widget starts over after a refused send.
- **Signing in with Google or any OpenID Connect provider** (Admin → Sign-in providers), with the operator's own OAuth
  client there:
  - Google is a preset; any other provider is added by its issuer (https). Its OpenID configuration is read before
    it is saved. The page shows the one redirect address to register at every provider.
  - The sign-in page shows "Continue with <name>" for each enabled one (customers only; the admin panel never does).
  - The authorization code flow with `state`, `nonce` and PKCE: the state must be one this browser session started,
    and works once. The code is exchanged with the client secret and the verifier.
  - The ID token is believed only when it is signed RS256 by a key the provider publishes (its keys are read again
    when a token names a new one), by the provider's own issuer, for this client, not expired, and with this
    sign-in's nonce. No library: PHP's own OpenSSL checks it.
  - Who it is: the person this provider's subject signed in as before, else the customer with the email the provider
    says it verified (then remembered in `user_identities`). Staff never sign in this way.
  - When it does not work, the person is sent back with a reason the sign-in page says in its own words (expired,
    cancelled, unavailable, failed, not verified, no account, staff); text from the address is never shown.
- Not in 7e: other social logins that are not OpenID Connect. (Signing up with a provider came in 9f.)

**8, done: the website and the release zip** (kit API 0.18.0, 141 operations).
- **The public website** (`laravel/resources/views/theme`, `routes/site.php`): home, features, pricing, contact and
  the legal pages, in the operator's brand. The brand's colours become CSS variables; only values that are colours
  get in. Before the install every page sends the visitor to `/setup`. The pages set no cookie and run no session.
- **Admin → Website** (`getWebsiteSettings`, `saveWebsiteSettings`): the content, saved whole.
  - The texts, an optional hero image (http or https only), and the home page's sections in order, each on or off.
  - The features, the plans the pricing page shows (none: the default plan) with the operator's own words for each,
    the contact text, and the legal pages.
  - Texts may hold `{brand}`, `{email}` and `{site}`; the longer ones take `# ` headings and `- ` lists. Everything is
    escaped first.
- **The pricing page shows only numbers the pricing engine would really charge.**
  - It shows per-minute call rates and fixed fees from each plan's price list, walking its `extends` chain.
  - A rule is shown only when no earlier rule can match the same item, so a number is never wrong for part of what
    its label describes.
  - A markup on the platform's cost is never shown.
- **Child themes** (`themes/README.md`): `KIT_THEME=<folder>` puts `themes/<folder>/views` ahead of the default
  theme. A copied template wins, and every other template still comes from the default theme. Only a plain folder
  name is followed.
- **The console in a release**: `/setup`, `/console`, `/admin`, `/signup`, `/signin` and `/reset` answer the built
  console (`public/console-app/`, `kit.console.paths`).
- **The release zip** (`pnpm release`, `scripts/release.mjs`), what shared hosting is given:
  - Type-checks and builds the console, then copies the Laravel app from a list of what a release holds (deny by
    default; `storage/` and `bootstrap/cache/` are only their folders).
  - Runs `composer install --no-dev`; set `COMPOSER_DISABLE_NETWORK=1` to build from Composer's cache.
  - Adds a root `.htaccess` that sends every request into `public/`, and a deny-all `.htaccess` in every other
    top-level folder.
  - Writes `dist/voice-kit-<VERSION>.zip` and its sha256.
- **The release check** (`scripts/check-release.mjs`, run on every zip; its own tests are `pnpm test:release`) fails
  a zip that has any of these:
  - private or install-made files: `.env`, a database, logs, sessions, the install lock;
  - the tests or a Composer dev package;
  - a missing runtime package, or a console whose scripts are not all there;
  - a top-level folder without its deny-all `.htaccess`;
  - a `public/.htaccess` that serves dotfiles.
- **The server check says where the site is served from**: `public/`, or the platform's own folder (shared
  hosting's `public_html`). The second is fine on Apache or LiteSpeed and blocks elsewhere, since the platform's
  files could then be read.
- Checked end to end: the zip was installed under Apache 2.4 with PHP-FPM in both layouts, and the wizard was run
  at phone width. In the `public_html` layout:
  - `.env`, logs, the database, `vendor/`, `config/` and `composer.json` all answer 403 or 404, and so do traversal
    tricks;
  - without `mod_rewrite`, everything answers 403.
- Not in 8: an in-browser updater (`INSTALL.md` says to run `php artisan migrate --force` after replacing the
  files); installing in a subfolder (the console's files are served from `/console-app/`); menus and media for the
  website.

**9a, done: the admin dashboard and cost vs price** (kit API 0.19.0).
- **Admin → Dashboard**, now the admin's first page. Over the days picked (this month so far by default) it shows:
  - what customers were charged, beside what the voice platform charged the operator, and the margin;
  - top-ups and credits added by staff;
  - customers (active, suspended, not set up, out of credit, new) and calls (count, minutes, live now);
  - the operator's own Telenow account: billing mode, spending state with the reason when stopped, balance or this
    month's spend against the cap, the low-balance alert, and a link to Telenow's Billing page;
  - what needs a look, each linking to where it is handled.
- **Admin → Cost vs price**: the same comparison grouped by customer, by what was charged (a call all in, each
  component, number rent and setup), by plan or by day, a page at a time, with the totals.
- Both read **daily totals** (`charge_rollups`), written with each charge in the same transaction. A report costs
  days × customers, whatever the number of calls. `php artisan kit:rollups-rebuild` writes them again from the ledger.
- Money is added exactly. Each charge's cost is converted at that charge's own rate. Telenow's dollar figures are
  shown to the cent.

**9b, done: the voice platform's health** (kit API 0.19.0, 147 operations).
- **Admin → Voice platform** shows:
  - **scheduled jobs**: when each last ran and which are late, and a loud warning with the cron line when none run
    (nothing is charged, synced or sent without it). Laravel's own scheduler events record each run (`job_runs`).
  - **the key**: connected, rejected, last checked;
  - **events from Telenow**: the endpoint, the last event received, and how many came lately, wait or failed. A failed
    one can be applied again.
  - **changes sent to Telenow**: how many wait, and the refused ones with Telenow's reason and "Try again".
  - **the money check** (`kit:reconcile`, a few customers every five minutes, each again hourly): for each customer,
    Telenow's lifetime spend beside the cost the kit has charged for, the calls each side counts this period, and the
    calls not charged yet. Customers whose gap is over the tolerance, whose calls differ, or whose check failed are
    listed, biggest gap first, each with "Check now".
- The dashboard's "needs a look" adds failed events, customers with a gap, and jobs not running.
**9c, done: staff, roles and the audit log** (kit API 0.20.0, 159 operations).
- **Roles** (`kit.admin.staff_roles`, owner's choices 10-31):
  - *owner*: everything;
  - *admin*: everything but the team and payment gateways;
  - *support*: customers (read, suspend, resume) and the Voice platform page, and no money.
  - What each role may do is data: `kit.admin.abilities` maps each ability to the roles that have it.
  - Every admin route names one ability (`EnsureAbility`); a test fails if a route names none.
  - The session answers the signed-in user's `abilities`, and the console shows only those pages and actions. The
    backend refuses the rest whatever the console shows.
- **Admin → Staff** (the owner's page):
  - Invite by email. The link is emailed when email is set up, else shown once to pass on. It works once and runs
    out, and a newer invite replaces an older one.
  - Change a role, or remove someone (after asking). A removed person loses the admin panel at once; their account
    stays, so the log still names them.
  - A customer's address cannot be staff. There is always an owner.
- **Admin → Audit log** (owner, admin): staff invited, joining, role changes and removals; customers suspended,
  resumed, credited, plan or limits changed, closed, and viewed as. Each entry says who did it and when, newest
  first.

**9d, done: a customer's limits, looking as the customer, closing** (kit API 0.20.0).
- **Limits** at Telenow: the most calls at once, and a monthly cap on Telenow's charges in USD. Empty means no
  limit. Telenow keeps the fields it is not sent, so the wallet's own stop is untouched.
- **Look at their console** (owner, admin): the customer's console as they see it, read-only, for
  `kit.admin.view_as_minutes`. A banner says so, with Stop. Every write is refused. It ends by itself, or as soon as
  the role may not do it any more. Starting and stopping are logged.
- **Close** (owner, admin), after typing the customer's name:
  - The customer is suspended at once and every number is released.
  - Then the kit waits, and never gives up, because Telenow's close revokes the key that reads the customer's calls: a
    moment for a call still landing (`kit.customers.close_settle_seconds`), then until Telenow counts no live call, and
    every call and number fee is read and charged. Usually minutes; at most a few hours, since a cost Telenow never rates
    runs out of reads. Telenow's own 409 while it still holds a number waits too.
  - Then Telenow closes the sub-account. A close whose answer was lost finishes when tried again.
  - Once closed: the key is forgotten, members see "account closed", nothing more can be done to it, and its wallet
    stays as it is to settle by hand. What is left of a closed customer asks nothing of Telenow and holds up nobody else.
  - A customer never made at Telenow closes at once, its waiting writes dropped. A create that lands afterwards is
    closed straight away.
- Telenow's answers are recorded from its real binary with a stand-in carrier (`closeSubAccount.*` and the `*.closed`
  recordings): the 409 while a number is held, a suspended sub-account's key still reading and releasing numbers, the close
  and the close again (the same `closedAt`), the key refused afterwards, and what a closed sub-account answers.
- Not in 9: logs and backups in admin, and an in-browser updater.

**9e, done: balance emails and email templates** (kit API 0.21.0, 165 operations).
- **Low balance** (`balance_low`): emailed once when a customer's balance falls to its warning level, and again only
  after a top-up takes it back above.
  - The level is the customer's own, chosen on its wallet page (by the roles in `kit.wallet.alert_roles`, the owner
    by default). Otherwise it is the operator's default, set in Admin → Pricing.
  - Zero means no low-balance email; empty means the operator's default.
- **Out of credit** (`balance_empty`): emailed once when the balance reaches zero and calls stop. A charge that passes
  both levels at once sends only this one.
- Only an entry that lowers the balance sends either (a charge, a refund, a disputed amount held). A top-up never
  does, even one that leaves the balance at or below a level (owner 10-31): the next fall does.
- Both go to the roles in `kit.wallet.alert_roles`, and nothing is sent:
  - for a suspended, closing or closed customer;
  - while charging is off;
  - when email is not set up.
- Each entry decides whether an email goes in the same transaction that writes the balance, with the account's row
  locked, so two charges at once send one email.
- **Admin → Email → Templates** (staff who may change settings): every email the platform sends, each with:
  - its subject, its paragraphs, its button's words and what follows the button;
  - the placeholders it can use and what each stands for.
- Editing a template:
  - The operator's words are kept per language and replace the kit's. Going back to the kit's words is one button.
  - Both are in the audit log.
  - A placeholder the email cannot fill is refused, as are an empty subject, a missing button text, and a button on
    an email that has none.
  - The preview shows the email in the brand before saving, in a sandboxed frame; each placeholder shows as
    `[name]`.
  - The layout, logo and colours stay the brand's.

**9f, done: signing up with a phone or a sign-in provider** (kit API 0.22.0, 169 operations; owner's choices 11-01).
- **With a phone** (`signUpByPhone`, `verifyPhoneSignup`): the sign-up form with a mobile number instead of an email
  and a password (`phoneFields` on `getSignupForm`).
  - Offered while texts and the texted-code way in are on, in every open mode but domains (which is about email
    addresses).
  - The sign-up is held, not a user, and a code is texted. It works once, for `kit.phone`'s minutes and tries, and a
    newer sign-up's code replaces it. Only a keyed hash of it is kept.
  - A number someone already proved answers the same, and is texted that it has an account. One waiting for approval
    changes nothing.
  - Confirmed: the account is made as by email, with the number proven and **no email** (owner 11-01). Its person signs
    in with a texted code; Your account says there is no email, and the number can be changed but not removed.
  - Emails (payment receipts, balance emails) go only to members who have an address. Approving or refusing a phone
    sign-up is texted.
- **With a sign-in provider** (`getProviderSignup`, `signUpWithProvider`; it follows the sign-up mode, owner 11-01): the
  sign-up page offers "Continue with <provider>".
  - Someone the provider vouches for (a verified email) with no account is sent to `/signup?with=provider` while
    sign-ups are open to their address, and kept in their session for `kit.signup.provider_minutes`.
  - They fill the form without an email or a password (the name the provider gave is suggested). The address counts as
    confirmed, and the provider's identity is remembered, so they sign in with it again.
  - Closed sign-ups send them back as before (`no_account`); an address outside the listed domains says so
    (`not_allowed`). The offer dies with its provider or with sign-ups. Approval mode holds it for staff.
- Admin → Sign-ups shows a phone sign-up by its number and a provider's by the provider's name. Both new forms take the
  bot check that guards signing up.
- Checked end to end on a copy, with the stand-in SMS and sign-in providers: a phone sign-up (a wrong code, then the
  right one), signing in again by code, a provider sign-up and its next sign-in, Admin → Sign-ups, and a domain not
  allowed. The recorded Telenow answers every create with the same sub-account, so a second customer's create waits
  there, which is the mock's limit.

Next: running the gateways against real sandbox accounts, once the operator's test keys are in hand; then the release gate
(a real Telenow account, a real shared host, one live call charged end to end).

**K0 Foundations**, done:

- the kit API's first two operations, `GET /api/kit/health` and `GET /api/kit/brand` (`contract/kit-api.yaml`),
  served by Laravel;
- the resource manifest (`contract/resources.yaml`) with its first resource, agents, checked against
  Telenow's released document;
- a recorded Telenow mock (`contract/telenow-mock/`) that replays real answers, each checked against that document;
- the console shell: `/setup` until the kit is installed, then `/console/*` and `/admin/*`, with the brand
  applied as CSS variables;
- the setup wizard (kit API 0.2.0: `getSetup`, `submitSetupStep`, and signing in): server check, database,
  the owner account, the Telenow key, brand, finish. Each step declares its own form and the console renders
  it, so adding a step needs no console change. Finishing locks the wizard (its routes answer 404) and opens
  `/admin`. It runs on a fresh release with no `.env` and no database.

- the pricing engine (`contract/pricing`): the spec, price lists as data, and 35 fixtures. Each fixture
  takes a call or number fee, a plan and a rate, and gives the exact lines and total, or the exact refusal.
  The expected answers are computed by `contract/pricing/oracle.py`. The reference engine
  (`contract/pricing/engine.ts`) and the Laravel engine (`laravel/app/Kit/Pricing`) both answer every
  fixture exactly.

The wizard's remaining steps (domain, email, signups, payments, pricing, catalog, webhook) come with K1, as
their drivers do. Nothing here talks to a real Telenow account yet: the tests use
recorded Telenow answers (`contract/telenow-mock/cassettes`).
