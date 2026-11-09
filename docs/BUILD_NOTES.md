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
  - Speaking into a call, to the agent alone or to both: Telenow had no such route then. Its 1.1.0 document has telling the agent (`addContextNote`): see "Telling the agent during a call", below.
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

**Web chat** (kit API 0.42.0, 229 operations, 11-08). A chat box a customer puts on their own website, answered by one of their agents (`App\Kit\Chat\WebChats`, `App\Kit\Chat\Conversations`, `public/chat/widget.js`).
- **Through this platform only.** Telenow's own web widget loads from Telenow's domains, so the kit has its own script, served from its public folder; it calls the kit's public chat API (`getChatBox`, `sendChatMessage`, `getChatMessages`, `endChatConversation`), and the kit passes each message to Telenow's chat API (`chatSend`, `chatMessages`, `chatEnd`) with the account's own key. A visitor never sees the platform's name, ids or domain; Telenow's refusals come back in the kit's words.
- **The operator's say** (Admin → Catalog → Web chat): off until offered; taking it back stops every chat box at once. The page names the price lists with no price for a chat while customers are charged.
- **On an agent** (its page → Web chat): on or off, the websites it may show on (each its address alone, as a browser names it in `Origin`: https, or http on `localhost` for testing), a title and a greeting, and the code for each page. Turning it on needs the operator's yes, a plan that prices a chat, and the agent on the voice platform.
- **A conversation** is answered only from the chat box's websites, and goes on only from the website it started on. Its token stays with the script; the kit keeps only its hash. It is saved, with its call, only once the agent has answered its first message. A message waits while the account is paused or its plan has no price for a chat; messages too fast from one address, new conversations too many from one, and messages to all of an account's chat boxes are limited (`KIT_CHAT_*`); a message is at most `KIT_CHAT_MESSAGE_MAX_LENGTH` (2000; Telenow takes any length, recorded).
- **Ending.** An open conversation holds one of the account's calls-at-once places at Telenow (recorded: a second one is refused at a limit of one), and Telenow ends one only after 30 minutes with no message. So `kit:chats` (every minute) ends one after `KIT_CHAT_IDLE_MINUTES` (10), every open one of a chat box that stops answering, one that reaches its most messages, and one the visitor ends; and tells Telenow until Telenow has it ended. Suspending an account at Telenow ends its open chats there (recorded).
- **In the call history** a conversation is a call with the channel `chat`: no number, no recording, its transcript. Telenow says a chat's recording is `pending` even after it ended and was rated (recorded), so a call on a channel that is never recorded (`kit.calls.unrecorded_channels`) is not kept waiting for one.
- **What it costs:** Telenow rates a chat as `callType: chat`, about 150 s after it ends (its settlement grace), from its language-model and knowledge look-up events and a per-turn platform fee (`chatTurnBlockUsd`, none set on the release) (recorded). An operator's price list prices it by components or by a `{item: call, channel: chat}` rule; a per-minute price would bill the time a conversation was open, and a per-message price is not in the pricing engine yet.
- **Recorded** on Telenow's live release (bfd2df4, deployed 11-06) with a stand-in language model behind `telenow_onprem` (the way Telenow's own end-to-end suite runs) and no provider key: 51 requests, 24 recordings. Two answers carry a field the released document does not declare yet, left out of the recordings: `getCall`'s `conversationParts` and `getCallCost`'s `featureSurchargeUsd` (Telenow's unreleased 1.2.0). The kit prices every `…Usd` of a call's cost, so a non-zero feature surcharge (WebRTC, SIP-direct) would need a price of its own: see the follow-ups.
- **Not proven:** a conversation answered by a real model vendor, or charged a non-zero amount: the release had no provider key and no chat turn price.

**Insights and exports** (kit API 0.43.0, 233 operations, 11-08). From the kit's own copy of an account's calls, not from the voice platform: Telenow's own exports carry the operator's wholesale USD charges, model names and its ids (its ZIP's `calls.csv`, `analysis/`, manifest), its calls CSV names the account's service identity, and its call trace holds full prompts and provider names, so none is exposed. No Telenow operation is new.
- **Console → Insights** (`getInsights`, `App\Kit\Reports\Insights`): a period of whole days in the viewer's own time zone (the browser's; an old name such as `Asia/Calcutta`, which some browsers report, is accepted), the last 30 when none is asked, at most 366: calls, answered (it lasted and no machine picked up), minutes, the average answered call, outcomes, sentiments, channels, spend in each currency the account was charged in, each day (days with no call too), and each agent, its name linking to its calls in that period. Read once, a page at a time, amounts added as decimals.
- **Calls list** (`listCalls`): new filters for days, outcome and channel, the same as the file and the insights (`App\Kit\Calls\CallFilters`). An outcome is Telenow's word, an agent's own too (recorded: `completed`, `no_answer`, `booked`), so the list answers the account's own `outcomes` and the filter offers those. An agent filter that is not an agent's id now finds no call (before, it found the calls with no agent).
- **Files** (`exportCalls`, `exportDnc`, `exportConsent`): each streamed a page at a time with a byte-order mark and every cell a spreadsheet would run as a formula written as text (`App\Kit\Exports\Csv`). The calls file: when each started in the viewer's time, agent, direction, channel, numbers, length, outcome, who answered, sentiment, summary, and what the account was charged in its currency; consent records: withdrawn ones too, not who recorded one nor from where.
- **Sentiment** is a column of its own (`calls.sentiment`), filled as a call's analysis is read, and for calls read before by its migration from the analysis already kept.
- **Not here yet:** the analysis' topics, keywords, objections and coaching (untyped in Telenow's released document, and empty in every recording so far); on-demand AI insights for a call; the call trace; bulk removal from the do-not-call list.

**Caller memory** (kit API 0.44.0, 235 operations, 11-08). Console → Caller memory (`getCallerMemory`, `forgetCaller`, `App\Kit\Memory\CallerMemory`): what an account's agents remember about callers. An agent set to remember callers (its "remember callers" switch and days, already in `App\Kit\Agents\AgentBehaviour` as Telenow's `metadata.callerMemory`, now linking here) has Telenow keep, after each call or chat, a short summary and some facts about the person, used on their next call until it ends.
- **Read live** with the account's own key (`GET /api/orgs/{orgId}/memory`), never kept in the kit. Telenow answers at most 200, the most recent first, ended ones too (recorded: an ended memory is still listed); the page says when the list is full (`kit.memory.limit`). The same list through an agent (`/api/agents/{id}/analysis/memory`) is the account's whole memory, not the agent's (recorded), so the kit offers only the account's.
- **In the kit's terms.** A phone caller by their number with a `+`; a web chat visitor (the kit sends its conversation to Telenow as `kit-<id>`, `kit.memory.chat_prefix`) by the website they chatted on, from the kit's own conversation; any other caller (an app's own identifier) as Telenow keeps it. Facts are shown as text, a number too. The last call is the kit's own call when it has it; Telenow's org and session ids never leave the kit.
- **Forgetting** cannot be undone, so only someone who manages the account may (`kit.team.managers`, the owner by default); a member sees the list with no Forget. `DELETE /api/orgs/{orgId}/memory?caller=` erases one caller; forgetting someone not remembered answers `removed: false`. The kit follows Telenow's own rule for a caller (`normalize_caller` in its source): only digits, spaces and `+ ( ) - .` is a phone number and is sent as its digits; anything else is an identifier, sent lowercased (recorded: a chat key in capitals is found). Cutting an identifier down to its digits would forget someone else: `order-12345` would have erased the caller on the number 12345 (found in review; on the release only `order-12345` goes). Nothing to send (no digits, say `+ ( ) -`) is refused by the kit; a blank caller is Telenow's 400 (recorded), answered as 422.
- **Another account's memory** with this account's key is Telenow's 403 (recorded); the kit only ever asks with an account's own key for that account.
- **Recorded** on Telenow's live release (bfd2df4) with a sub-account's own key: 6 recordings. Telenow writes a memory with its own model after a call, which a throwaway release cannot run (no vendor is reached), so the rows were seeded into its `caller_memory` table: a phone caller, a web chat visitor, an ended one and another account's. Checked again on the release from the kit (11 checks, an app's identifier among them) and in a browser: the list, search, and forgetting from the list and by a typed number.
- **Not proven:** a memory written by a real model after a real call (no vendor on the release). **Not here:** changing a memory (Telenow has no such operation); what remembering costs (inside the call's charge).

**Knowledge tables** (kit API 0.45.0, 237 operations, 11-08). Console → Knowledge → a document → View table (`getKnowledgeTable`, `setKnowledgeColumnTypes`, `App\Kit\Knowledge\KnowledgeTables`). The kit already took CSV, JSON and Excel files; Telenow keeps each as a table the moment it is uploaded (`indexed` in the upload's own answer, recorded), and customers could not see it.
- **What agents do with one** (Telenow's source, `features/call/engine/mod.rs`): every table an agent answers from becomes a look-up the agent calls on its own (filters and totals over the rows, at most 20 rows back; never SQL it writes); a small one (Telenow's `inlined`: at most 300 rows and 8,000 characters) is also put in the agent's prompt whole. A table too big for that has up to its first 10,000 rows indexed for finding by meaning (billed as embedding; it fails quietly with no provider, recorded); exact look-ups cover every row. The page says which: `inPrompt`, `searchByMeaningRows`.
- **Read live** with the account's own key, a page of rows at a time (50, at most 200: Telenow clamps to 1–200, recorded); nothing kept in the kit. A column is named by its heading in plain letters (`key`, Telenow's `sql_name`) and its heading; each row's cells come in the order of the columns, as the file had them: text, a number, true or false, or null. A JSON file's nested value comes as its JSON text (recorded); were one to come as it is, the kit still makes it one cell of text.
- **A column's type** (`text`, `number`, `bool`, `date`) is how look-ups read it: number columns are compared and added up, date columns compared; a cell that does not fit is left out of those, never an error (Telenow's `dataset_query.rs` guards each cast). Telenow guesses types from the file, so one "N/A" makes a price column text (recorded). Changing one takes effect on calls that start afterwards and never changes the rows. The kit reads the table first, so a column it does not have is refused (422) before anything is sent, and answers that read with the change applied, as Telenow applies it (read back on the release). `bool` is matched by its words as text is, today.
- **Which documents**: an upload whose name ends `.csv`, `.json` or `.xlsx` (`kit.knowledge.table_types`), shown on the document as `table`, and only once it is ready (409 before). A document Telenow answers `null` for (a text one, one removed) is 404 "not a table"; another account's is Telenow's 403 (recorded), answered as unavailable since the kit never asks for one. Every member may, as with the rest of knowledge.
- **Recorded** on Telenow's live release (bfd2df4) with a sub-account's own key: 20 recordings — a CSV with messy cells, a JSON file, a hand-made Excel file, a CSV of 10,050 rows, a text document, another account's, a removed one, every refusal. Checked again on the release through the kit's own upload and outbox (14 checks: four files kept as tables at once, the price column changed and read back, the rows unchanged).
- **Not here:** a file's sheets other than the first, editing rows (Telenow has neither); proof of an agent's look-up on a real call (no model vendor on the release).

**Duplicating an agent** (kit API 0.46.0, 238 operations, 11-08). An agent's page → Duplicate (`duplicateAgent`, `App\Kit\Agents\AgentDuplicate`). Not Telenow's templates: those are one marketplace for every Telenow account (`listTemplates` answers Telenow's own built-ins and other accounts' approved ones, prompts included; Telenow's source `db/templates.rs`), and installing one bypasses the operator's catalog and makes an agent the kit did not write. So the copy is made in the kit, from the source as the console shows it.
- **Through the customer's own writes**: `Agents::create`, then one `Agents::update` (tools, look-ups, hand-overs, off), `AgentFlows::publish`, `AgentKnowledge::set`, `AgentWebhooks::add`. Every check is made again (the operator's offering, the transfer switch, hand-over targets, members for the after-call email), and every piece reaches Telenow the way it always does. What waits until the copy exists is checked first, as a save of it will be (every rule and limit), so the copy is never made half; a piece that no longer passes (say, more tools than the operator now allows) is left out and listed.
- **Copied**: name ("… (copy)", "… (copy 2)" when taken, cut to fit), description, prompt, every call setting, the voice setup (an own cloned voice too), each provider's settings, the realtime voice, after-call email, hand-overs, tools, look-ups, call steps (published when they hold no key; the source's own draft too), knowledge bases, webhook addresses, on or off. A transfer the operator has since taken back is left out.
- **Never copied: keys.** Telenow alone holds a tool's, a look-up's or a step's key; the kit never reads them back (`SavedKeys`), so a copy cannot carry one: a token and any secret header are left out and named in `toRedo` (`key`), and call steps that need a key stay a draft (`flow`). Each webhook address is a new endpoint at Telenow with its own signing secret (`webhooks`). Not copied: phone numbers, the web chat box, campaigns, calls, versions.
- **A voice setup the operator does not offer** (any more, or a default Telenow filled in that was never offered: recorded on the release, where the operator does not offer the model new agents get) is refused on the copy as on any save: the copy starts from the defaults, and `voiceSetup` is said only when that is not what the source has, or provider settings or a realtime voice were dropped.
- **Settings written out in full**: the kit keeps an agent's settings as Telenow answers them, defaults included, so a copy has each setting written as the source has it in effect, where the source may leave it to Telenow's default. They act the same (checked on the release, read as the kit reads them); a later change of a Telenow default reaches the source and not the copy.
- **Checked on Telenow's live release** (bfd2df4) through the kit's own code: a source with settings, two tools and a look-up with keys, a hand-over, published steps, knowledge, a webhook, off — duplicated, both read back at the release (14 checks: everything the same but the name, no key on the copy, the source keeps its own, a webhook endpoint of its own, the source untouched). No new Telenow operation, so no new recording.
- **Not here:** starter agents an operator offers every account: "Starter agents", below.

**Starter agents** (kit API 0.47.0, 244 operations, 11-08). Admin → Starter agents (`listStarterAgents`, `previewStarterAgent`, `publishStarterAgent`, `updateStarterAgent`) and console → New agent (`listOfferedStarterAgents`, `createAgentFromStarter`); `App\Kit\Agents\StarterAgents`, table `starter_agents`. Telenow's templates are not used, for the reasons in "Duplicating an agent".
- **Where they come from** (the owner's choice, 11-08): only an agent in an account the publishing staff member owns (a `WorkspaceMember` with role `owner`) — their demo account, say — never a customer's: a starter goes to every account. Any other agent is 404, whatever account it is in. A new staff ability `starters` (owner, admin).
- **Read before it goes out**: the preview shows every piece of free text it would carry, in full — description, prompt, every setting whose key starts `greeting` or ends `Message` (so a line added later is shown too), analysis fields, each step's name, instructions and line, each exit's label and condition — and what it leaves out, with names. Publishing needs `reviewed: true`. Published, renamed, withdrawn, offered again: each in the audit log.
- **What a starter carries** (the owner's choice): the prompt, description, every call setting, the voice setup, provider settings, the realtime voice, analysis fields and the published call steps. Never anything of the account: keys, tools, look-ups, hand-overs, transfers (numbers), knowledge, webhooks, after-call email recipients, an own cloned voice (the starter takes the defaults); every tool, hand-over and transfer step becomes an ending, with no exit from it. Taken once, at publishing, as a snapshot: the stored row holds no key, address, number or id of that account (checked on the release).
- **A customer's agent from one** goes the duplicate's own way (`AgentDuplicate::made`, `::steps`): the same create, the defaults when the voice setup is not offered (said), steps published or left as a draft (said). Named as the starter, "… (2)" when taken. Withdrawn starters are no longer offered; agents made from them stay.
- **Checked on Telenow's live release** (bfd2df4) across two sub-accounts (a second minted on the release for the customer): an operator agent with keys, tools, a look-up, a hand-over, steps with a tool and a hand-over step, knowledge and a webhook — previewed, published, an agent made from it in the customer's account and read back at the release (12 checks: prompt, greeting, limit and voice carried; no tools, look-ups, hand-overs, knowledge or webhook; the steps published with the two endings; nothing of the source; the source not reachable with the customer's key).
- **Not here:** a starter's own preview call (test it in the operator's account before publishing); per-plan starters (every account gets every offered starter).

**Telling the agent during a call** (kit API 0.48.0, 245 operations, 11-08). A live call's page → Tell the agent (`tellAgent`; the call's `canTellAgent` and `agentNotes`; `App\Kit\Calls\AgentNotes`, table `call_agent_notes`). Telenow's `addContextNote` (1.1.0), with the account's own key, on the session id the kit already holds.
- **What the agent does with it** (recorded on the live release with a stand-in model that logs what it is sent, and read in Telenow's source `engine/live_context/*`): every later reply's prompt carries the note in one late system message, "Notes from systems around this call — not said by the caller", as background; it is never a caller turn and moves nothing in call steps. "Say it now" (`respond: when_idle`): on a phone call the agent's model writes one line in its own words and speaks it as soon as nobody is speaking (`speaking_now`; the model was sent the note marked `tell_caller`); in a chat it waits for the next reply. While the agent is away (the caller on hold, a transfer that may come back) Telenow holds it (`held`); the kit says "when it has the call again". Notes add up for the call (the kit sends no `key`, so none replaces another).
- **Refused** (recorded): a call that is over (`not_live`), one a person has for good — a call placed from the Phone page, a takeover, a transfer that went through (`no_agent`), an agent that cannot be told (`engine_unsupported`: a Custom API agent, some speech-to-speech models), an empty note, and one longer than fits beside the call so far (`too_large` with `maxChars`: how much depends on the agent's model; the stand-in's was 24,787). The kit keeps Telenow's refusal body now (`TelenowError::body`) to say how many characters fit; its own limit is 2,000 (`kit.calls.agent_note_max_length`). A call over at Telenow is read again soon, as with listening in. Nothing is kept for a refused note.
- **Who may:** the account's managers, as with listening in; on any live call the kit has (web calls and chats too, which have no sound to listen to). Sent at once, never queued. Every member sees what the agent was told on the call's page, newest first, with who and when. It costs only the model reading it (in the call's own charge), and one more model request and its speech for "say it now".
- **Not exposed:** Telenow records the sender as its API key's id in its event stream and call trace; the kit relays neither.
- **Checked on Telenow's live release** (bfd2df4, with the stand-in carrier, voice and model) through the kit's own code: 8 checks — an agent call with media relayed, told something (next reply) and asked to say something now (saying it now), the model sent both, never as the caller's words; kept newest first; refused once the call ended (read again soon); a manual call refused as "a person has this call". In the browser, a fresh call's page: two notes sent and listed; on that call Telenow answered `held` for both (shown as "when it has the call again") — why that fresh call was held at the release was not looked into.
- **Not here:** playing a sound into a call (`playAudio`: only an https WAV the platform fetches each time); the call's live words (`streamCallEvents`); other managers' notes appear on a page only after a reload.

**Campaign contacts from a link** (kit API 0.49.0, 247 operations, 11-08). A campaign's page → Contacts from a link (`setCampaignSource`, `removeCampaignSource`; the campaign's `source`; listCampaigns' `linksAllowed`, `linkEveryMinutes`; `App\Kit\Campaigns\CampaignSources`, `App\Kit\Net\PublicLinks`, `kit:campaign-links`). Telenow's own campaign sources were not used: they connect a spreadsheet through Telenow's Google sign-in (its app name and consent screen, broad Gmail and Calendar scopes, a return to Telenow's dashboard) and add rows at Telenow past the kit's copy. So the kit reads a CSV link itself.
- **What it does:** the link is read now and again every `link_every_minutes` (15) while the campaign is not finished; each read is parsed as a CSV upload is (`Campaigns::parse`, now shared) and adds only numbers the campaign does not have yet, by their digits (`+1 (415) 555-0102` is `+14155550102`), never taking anyone off; added the same way (`Campaigns::insert`, the push, which keeps do-not-call numbers out). A link that cannot be read says why on its campaign and is tried again next time; stopping it keeps the people it added.
- **This server reads it, so only when every step is public** (`PublicLinks`, owner's choice 11-08): https only, no login in it, port 443; the host resolved here (`HostResolver`) and every address it stands for public — not private, loopback, link-local, shared (100.64/10), benchmarking, documentation or reserved space, an IPv4 address inside IPv6 (mapped or NAT64) judged as IPv4 — and the connection pinned to the checked address (curl `RESOLVE`), so a name cannot be pointed elsewhere between the check and the connection; when a checked address cannot be reached the next one is tried, pinned the same way, at most `links.addresses_tried` (3) of them, as any client falls back between a name's addresses (found in a browser check: one of the four addresses of GitHub's raw-file host timed out from this Mac, and pinning only the first made the link unreachable); redirects followed by hand, each checked again, at most 3; at most 2 MB (the transfer stopped as it passes it), 20 s; a web page (HTML — an unpublished sheet's sign-in page) is not a file. Never through Guzzle's stream handler, which cannot pin: the real network found that, the fakes could not (a test now pins it down).
- **Off until the operator allows it** (Admin → Catalog → Campaign contacts from a link, audited): turned off, links stay on their campaigns but are not read.
- **Checked on the real internet** (from the build server, the kit's own code in PHP's official image, as this Mac's network refuses most hosts): 12 checks — a real public CSV read; a real redirect to it followed; loopback, the cloud metadata address and a public name resolving to 127.0.0.1 refused before connecting; real redirects to a 127.0.0.1 name and to the metadata address refused at the second hop; a download stopped at the size allowed; http, a web page and an unknown name refused; a real file with no number column refused as a call list. In a browser on the throwaway copy: the metadata address refused as private; a real public CSV reached on its next address and refused as having no number column; a followed link shown with when it was last read, how many it added and its problem, then stopped. No new Telenow operation: people from a link take the CSV upload's path to Telenow.
- **Not proven:** a real published Google Sheet (none was made, as that publishes content); rows from a link reaching the voice platform on the live release (the same insert and push as a CSV upload, whose tests cover it).

**Call routing** (kit API 0.50.0, 256 operations, 11-08). Console → Call routing (`getRouting`, `createRoutingRule`, `updateRoutingRule`, `deleteRoutingRule`, `orderRoutingRules`, `tryRouting`, `checkRouting`; Numbers' `setNumberRouting` and a number's `routed`; the voice platform's `answerRoutingRequest`; `App\Kit\Routing\RoutingRules`, `RoutingMatch`, `RoutingConnection`, `App\Http\Controllers\Kit\TelenowRoutingController`). Telenow's inbound routing (released: `setInboundRouting`, `getInboundRouting`, `testInboundRouting`, `setNumberPool`) asks one endpoint per account, on every call to a routed ("pool") number, who takes it. Its request names its carrier (`"carrier": "plivo"`) and the carrier's call id under `X-Telenow-*` headers, and the answer names Telenow's agent, voice and provider ids — so the endpoint is this kit, and customers get rules (owner's choice 11-08: rules in the console, not their own server; all four ways to match, all four things a rule does).
- **A rule:** a name, on or off, and conditions that must all hold (a list left empty holds for any call) — the numbers called, the lines that forwarded the call, how the caller's number starts (`+44`, or a whole number), ISO days and from–until hours in the rule's own timezone (accounts have none; a window past midnight is one, and its hours after midnight belong to the day it began). It answers — with one of the account's agents or the number's own, a language code, a greeting (the agent's first line, up to 500 characters) and a cap in minutes (used when shorter than the agent's own; Telenow takes 15 s to 4 h) — or refuses: the caller hears a busy line and nothing is spent. First match wins, in the account's order; at most `KIT_ROUTING_RULES_MAX` (100) rules of `KIT_ROUTING_NUMBERS_PER_RULE` (500) numbers each. The account's managers change them; every member sees them and can try a pretend call (which rule, what happens, at a time given — asks no one).
- **The forwarded-from line** comes as the carrier reported it — `+919876543210`, `919876543210`, `09876543210` and `9876543210` are all seen (Telenow's own notes) — so a line is the same when the reported digits, leading zeros dropped, end the rule's and leave out at most 3 digits (a country code's most, ITU-T E.164). No country tables: a trunk prefix other than 0 (Russia's 8) is not read as the same line.
- **The endpoint:** one per account (`routing_endpoints`): a token in the address's path (Telenow signs the address without its query), and the secret Telenow signs with, kept encrypted — Telenow shows it only on the answer that made it. Registered when the account's first number is routed (`setInboundRouting` with the address, `timeoutMs` — always sent: Telenow resets it to 1200 when not, recorded —, `enabled`, and `rotateSecret` when the kit holds no secret), again when this kit's address changes. A request counts only with Telenow's timed signature (`X-Telenow-Signature-V2: t=…,v2=…`, HMAC-SHA256 of "v2", t, the address and the body, a line each) for that account's address and secret, t within `KIT_ROUTING_SIGNATURE_TOLERANCE` (300 s) — checked against Telenow's own test vector and its cases for the address it signs; the untimed signature is refused (a copy of a request would answer for ever). The answer is always a JSON object (`{}` for the number's own agent: Telenow refuses anything else), and the carrier's name and call id are not kept.
- **Routed numbers:** `setNumberPool` through the outbox (`numbers.route`), `routed` read back from Telenow's `is_pool` by the manifest. Routing needs the number to have an agent, and a routed number keeps one — it takes the calls no rule does (Telenow refuses both otherwise, recorded; the kit says so first, in its words).
- **Failing open:** past `KIT_ROUTING_TIMEOUT_MS` (1200 ms) or on any failure Telenow gives the call to the number's own agent. The page says whether Telenow asks this kit (connected, off, another address, not connected, not reached just now), when it last had an answer, and its last problem in the kit's words — never Telenow's `lastError`, which can hold another server's page. Managers can run Telenow's own test of a routed number (a few a minute).
- **Needs this server at a public https address:** Telenow refuses http, private and metadata addresses and names that do not resolve (recorded), so a kit behind a firewall cannot route calls; it says so when a number is routed.
- **Recorded on Telenow's live release** (bfd2df4) with a sub-account's own key (contract_k26routing.py, 32 recordings): the endpoint set, set again (no secret), with no timeout (1200), its secret rotated, turned off and on, refused (http, private, metadata, unresolvable, timeout out of range, another account); Telenow's test answered, refused by status (405, 404), timed out, off, not set, a number not routed, unknown or another account's; routed numbers (no agent, another account's, unknown; the agent kept); a routed number released. A simulated inbound call to a routed number whose endpoint failed went to the number's own agent.
- **Checked on the live release, through the kit's own code** (owner's choice 11-08: a temporary Cloudflare quick tunnel to the throwaway copy — cloudflared 2026.10.7 from its GitHub release, both checksums checked, deleted after): 17 checks — the number routed and the endpoint registered with the account's key; Telenow's own test answered by this kit (413 ms, the first request); calls on the stand-in carrier's answer hook: forwarded from the rule's line written with a leading 0 → the rule's agent, with its cap (Telenow logged `max_duration=Some(600)`); a caller the rules refuse → a busy line and no call; no rule → the number's own agent; this kit refusing (a wrong secret) → the number's own agent all the same, the problem shown as "refused"; routing off → the number's own agent. Telenow logged each answer from this kit in 58–79 ms.
- **In a browser** (built-in pane, an account's owner on the throwaway copy; the copy's stand-in platform does not serve routing, so the platform's side shows its "not reached" words): rules added (an answering one with agent, language, greeting and cap; a refusing one, whose answer fields hide), moved, turned off, edited with one end of the hours (the server's words at the field) then a window past midnight, deleted after a confirm step; pretend calls — a line written with a leading 0 → its rule, a refused caller → busy, none → the number's own agent; the platform's test failing in the kit's words; a number with no agent not routable; the Numbers page's "Routed" marker. Found there: Chrome gives its own timezone as `Asia/Calcutta`, an older name the framework's plain timezone rule refuses — rules now take older names too, as campaigns do (a test and a break hold it).
- **A rule's numbers:** the account's bought numbers; one released after a rule took it stays on the rule until taken off (an edit is not refused for it). A number whose agent was deleted cannot be routed.
- **Not here:** a customer's own routing server (relaying Telenow's request); variables in an answer; a voice or provider per call; the carrier's call records (`listCarrierCalls`: the carrier's name and call ids); a cap on calls at once per routed number (`maxConcurrent`).


**Number controls** (kit API 0.51.0, 261 operations, 11-08). Console → Numbers (`setNumberCallerId`, `setNumberUnanswered`, `listLostNumbers`, `getLostNumberBack`, `getNumberRenewals`; a number's `agentCallerId` and `unanswered`; `App\Kit\Numbers\Numbers`, `LostNumbers`, `NumberRenewals`; outbox writes `numbers.caller_id`, `numbers.unanswered`). Owner's choice 11-08: all four pieces, and renewal warnings on the page and by email. Two of the remaining number controls are not in Telenow's released API and are not built: forwarding a number's calls (its per-number `forward` needs the account's forwarding number, which no released operation sets — recorded: it speaks the message instead) and bringing a customer's own carrier account.
- **Caller id:** Telenow's `is_default_outbound` (`setDefaultOutboundNumber`), read back as `agent_caller_id` by the manifest: when a call names no number, Telenow dials from the agent's caller id, else a number the agent answers (its source `resolve_agent_outbound`). Only a number with an agent (Telenow refuses either way otherwise, recorded); setting one clears the agent's other — at Telenow, where the other's `updated_at` moves too (recorded), and in the kit at once. Telenow clears it whenever the number's agent is set, even to the same agent, and when it is taken off (recorded): the kit does the same. A call from an agent's page with no number chosen now takes the caller id first.
- **When no agent answers:** Telenow's `inbound_action` (`setNumberInboundAction`): `speak` (the account's message: a sub-account has none, so Telenow's own — "Sorry, no one is available to take your call right now. Please try again later.", which names no one), `reject` (a busy line) or `no-answer` (unavailable); the kit says message, busy, unavailable (`config('kit.numbers.unanswered')`). Recorded on the stand-in carrier's answer hook: `<Speak>` and a missed call logged at Telenow; `<Hangup reason="busy"/>` and `<Hangup reason="rejected"/>`, neither answered nor logged; `forward` with no number to forward to speaks. A sub-account's own default is `speak`. Only for a number with no agent (Telenow 409, recorded); setting an agent clears it.
- **Getting back a lost number:** Telenow lists the numbers it gave up for an account (not those the account released) while the carrier may still hold them (`listReclaimableNumbers`: the deadline, the release reason, what it was set up for, its last prices), and `reclaimNumber` buys one again at once. A customer's numbers renew on the operator's account at Telenow (a sub-account's is paid by its parent), so they are lost when THAT runs short. The kit lists them at the plan's price (setup and a month's rent, from the last prices in USD), checks the customer's balance first while charging is on, and asks Telenow. `restored`: a NEW number at Telenow, set up as the old one (its agent and caller id, recorded; campaigns relinked; and, on a number no agent answers, what a call to it does — seen in the browser check, `inbound_action_restored`) — the reclaim answer's own row is the one before that set-up (recorded: no agent), so the kit reads it again, keeps it as a new number, and puts it in the old one's place in the account's campaigns and routing rules. Its rent reaches the wallet from Telenow's ledger as a purchase's does (recorded: the rent on the new number, in the sub-account's ledger, about 2 s later — Telenow's change feeds hold back what is newer than their horizon). `requested`: the carrier would not sell it back, so Telenow files it for its staff (recorded: an ops item) to finish by hand before the deadline; asked again, Telenow says so without buying. When it comes back, the numbers feed brings a new number with its digits, which takes the asked-for one's place. Refusals in the kit's words: the operator's account cannot pay (Telenow 403, naming "the account this workspace is billed to"), too late or released by the account (400), back already (409), not the account's (404).
- **Renewal warnings:** Telenow's `getRenewalRisk` with a customer's key says `billed_to_parent`, every number `not_applicable` (recorded): the risk is the operator's. The customer's is the kit's own: each month's rent is charged to their wallet at their plan's price whatever the balance, and calls stop at zero. So the kit keeps each number's rent in USD (`rent_usd`, from the search listing and the numbers feed's `monthly_rent_usd`; never shown) and reckons, in renewal order, the running total of the next rents on the plan against the balance — `short` with that running shortfall (Telenow reckons its own the same way: topping up one number's gap alone would still lose a later one), else `soon` within `KIT_NUMBERS_RENEWAL_WARNING_DAYS` (7), else `ok`. `kit:numbers` emails the account's owners once for each renewal within those days that will be short (`number_rent_short`; Admin → Email templates).
- **Recorded on Telenow's live release** (bfd2df4) with a sub-account's own key (contract_k27numbers.py, 38 recordings): the caller id set, again, swapped to the agent's other number (the first cleared), off, with an empty body, cleared by the same agent again, by another agent, by taking the agent off, refused (no agent, another account's, unknown); what unanswered calls do set four ways and cleared three ways (null, blank, empty body), refused (an agent answers it, unknown action, another account's, unknown), cleared by giving the number an agent, with a simulated inbound call each time; renewal risk for a sub-account; lost numbers — none, one the account released (not listed, refused 400), one given up for unpaid rent (the release through Telenow's own route, then its reason and a five-day deadline set by SQL on the throwaway database: the stand-in carrier has no hold window, so Telenow never offers its numbers back by itself), refused with the parent's wallet emptied by SQL (403), restored, twice (409), one the stand-in carrier would not sell back (renamed by SQL) requested and asked again, past its deadline (400, not listed); the ledger and the numbers feed after. Telenow answers 415 for no body and 422 for a wrong type, statuses its document does not list (the kit sends neither).
- **Checked on the live release, through the kit's own code** (no tunnel needed: the release only answers): 25 checks — two numbers and two agents bought and made with the account's key; the numbers feed keeping both, their agent and their rent in USD; the caller id set, swapped (the other cleared, here and at the release), the agent's call form starting on it, cleared by the same agent again; no agent: refused as a caller id before the release; a call to a number no agent answers heard on the stand-in carrier's answer hook as the message, a busy line, the line refused, the message again (`speak`, `reject`, `no-answer`, `speak` at the release), refused for a number with an agent; renewals on a plan (the second not covered once the first is); a number released through the kit not offered back; given up for unpaid rent: offered at the plan's price with its agent, refused while the operator's account is empty (in the kit's words), refused before the release while the customer's balance is short, got back as a new number in the old one's place (its agent, the routing rule re-pointed, its rent on the plan), back already the second time, one row for it after the numbers feed and its rent among the fees from the ledger; asked for when the carrier will not sell it back, asked again with no balance, past its deadline no longer offered.
- **In a browser** (built-in pane, a test account's owner on the throwaway copy pointed at the live release through the SSH forward, put back after): the renewal warning (the balance short of one rent: "Add ₹222.90 to cover it", the row "Not covered"); the caller id switch saved at the release; the agent taken off, the "When no agent answers" choice in its place, a busy line saved and heard; the number released, then (by SQL on the release's database) given up for unpaid rent; the lost section with its price; getting it back refused while the balance was short (the kit's words), then by the release itself while the operator's account there held $3.25 against a $5.25 rent ("cannot be got back right now"), then back once the operator's account was topped up — listed again with its busy line kept (Telenow sets it again); then released, renamed by SQL to a number the stand-in carrier does not sell, and asked for: "being got back by hand before 13/11/2026", still shown as asked for after a reload. Found there: nothing to fix; a number bought outside the kit shows "Not priced" as its monthly rent beside the plan's rent in its renewal (as before for such numbers).


**Platform billing, and one customer's costs** (kit API 0.52.0, 263 operations, 11-09). Admin → Platform billing (`getPlatformBilling`, `setPlatformBalanceAlert`; ability `money`; `App\Kit\Telenow\PlatformBilling`) and Admin → Cost vs price's `customer` (`getCostReport`; `App\Kit\Reports\CostReport`). Owner's choices 11-09: the low-balance alert, the platform's invoices, and customer invoices (the next part); "cost per customer" already existed (Cost vs price by customer, by component, by plan, by day, from the kit's own records), so the owner chose one customer's figures by component over a second report from Telenow's own breakdown (`getChargeBreakdown`: Telenow's settled call charges of the operator and every sub-account by component; not used). Number compliance, on the list before this, is set aside: Telenow's release offers it only for Plivo and Twilio numbers and its Plivo address is fixed to the real carrier, so recording it would mean real regulatory submissions (owner's choice 11-09: skip until there is a carrier test account).
- **The state:** Telenow's spend state of the operator's account as `kit:telenow-status` reads it every `KIT_TELENOW_STATUS_MINUTES` (5) — ok, low (what it can still spend is at or below its level) or stopped (out of credit, at its monthly cap, suspended), mode, balance, headroom, the period's spend, the cap. A sub-account's own key reads none of it: its wallet is its parent's (recorded).
- **The low-balance level:** `setSpendAlert` with the operator's key, in USD (whole dollars of up to `KIT_PLATFORM_ALERT_MAX_DIGITS` (7) digits and cents; empty for none), sent as a JSON number. Telenow's answer — the state it gives at once — is kept for the page, so a level already passed shows as low and sends no email. Telenow calls the state low while `headroomUsd` is at or below the level (recorded: 40 with 40 left is low), so a postpaid account with no cap (no headroom) never is; stopped outranks low. Telenow refuses a negative level, text, an unknown field (400, kept in the kit's words) and any account but a top-level one (403).
- **The email:** after each state read, when the state got worse — ok → low (`platform_balance_low`), ok or low → stopped (`platform_stopped`, with why) — one email to each staff member whose role holds `money` (owner, admin) and has an email address, linking to the page. Once per change, as Telenow sends its own `billing.spend_state_changed` (not used: the kit's events endpoint would need to subscribe to it, and the state read already says the same); the first state ever read sends nothing. Both are templates in Admin → Email.
- **Telenow's invoices:** `listInvoices` with the operator's key, read when the page opens (out of reach: the rest of the page, with the reason): newest period first, drafts too; only a postpaid account gets any (recorded: none while prepaid). Each with its period, status, dates, its total in its currency and in USD, and its lines; the lines are net and the tax (GST for an Indian account, `billing_cycle_worker.rs invoice_composition`) has no field of its own in the answer, so the page shows what the total holds beyond the lines as tax.
- **One customer's costs:** `customer` limits Cost vs price — its rows and its totals — to one customer, so a customer's row opens their figures by what was charged (a call's components, number fees).
- **Recorded on Telenow's live release** (bfd2df4) with the operator's (top-level) key and a sub-account's own (contract_k29billing.py, 21 recordings): the billing summary with a level set (ok below the balance, low at or above it, with cents, at zero, cleared), stopped with a level, postpaid (no headroom: ok), a sub-account's (no state); the level refused (negative, text, missing, another field, too large; a sub-account's key, the operator's key on a sub-account, another org); invoices none while prepaid, then — the account made postpaid and invoices written by SQL on the throwaway database as Telenow's monthly billing run writes them (a paid one, an issued one with GST and four lines, a draft) — listed with their lines; a sub-account's none; another org's refused.
- **Checked on the live release, through the kit's own code** (the release's operator test key; the copy's own operator settings put back after): 16 checks — the state read (prepaid, fine, its balance); a level below the balance set (fine), above it (low at once, no email), cleared, a negative one refused before the release; a level set at the release itself, so the next state read found it low and emailed the copy's money staff once (not again on the next read); the balance emptied by SQL: stopped, out of credit, emailed with why; put back, nothing more sent; invoices none while prepaid, then one written by SQL with its lines and its tax as the rest of its total.
- **In a browser** (built-in pane, the copy's staff owner, the copy pointed at the live release with its operator test key, put back after): the account's state, balance and period; a level with three decimals refused at the field in the kit's words, then 50 saved and the state "Running low" at once; the platform's invoices (a draft "Not issued yet", an issued one with its lines and "Tax ₹149.40"); Cost vs price by customer, then one customer by what was charged with that customer's totals and the way back. Found there and fixed: "Last checked" did not move when a level was saved, though the platform's answer is a fresh read (a test and a break hold it). Built by the console's builder and fixed before the gate: Telenow refusing a level answered 422 with no field errors; invoices kept Telenow's order (now the kit's own, newest period first); the contract allowed for nothing in the state and refused an empty level.


**Customer invoices** (kit API 0.53.0, 271 operations, 11-09). Console → Wallet → Invoices and `/console/invoices/{id}` (`listAccountInvoices`, `getAccountInvoice`, `getBillingDetails`, `setBillingDetails`), Admin → Invoices and a customer's page (`getInvoiceSettings`, `setInvoiceSettings` — ability `money`; `listCustomerInvoices`, `getCustomerInvoice` — `customers_read`); `App\Kit\Invoices\CustomerInvoices`, tables `customer_invoices`, `invoice_counters`, a workspace's `billing_*`. Owner's choices 11-09: one invoice per payment, a page made for printing (no PDF library: the browser saves it as PDF), a tax line when the operator sets one. The kit's own: no Telenow operation is used, and nothing of the voice platform is on an invoice.
- **When:** a payment is credited to a customer's wallet (`App\Kit\Payments\Payments::paid`: a gateway's, or a bank transfer staff confirmed); never a staff member's test payment (no account). Once per payment (unique). A failure to issue never stops the payment: `kit:payments` issues the invoices of payments credited without one (`KIT_INVOICE_CATCH_UP` a run). The payment email links to it.
- **Its number:** the operator's prefix (`KIT_INVOICE_PREFIX` until they set one; letters, digits, `/ _ -`, empty allowed), the year (UTC) and the year's next number (`KIT_INVOICE_SEQUENCE_DIGITS`, 6), without gaps: the year's counter row is taken with the invoice in one transaction, so a failed invoice gives its number back.
- **Its amounts:** the amount credited, in the payment's currency. While the operator charges a tax (a name and a rate up to 100 %, two decimals; 0 is none), the amount paid includes it: net = amount × 100 ÷ (100 + rate) to the currency's places, half up; the tax is the rest (1000.00 at 18 % → 847.46 + 152.54).
- **Its parties, kept as they were:** the seller — the operator's name, address, tax number and email (Admin → Invoices; their brand's name and support email when none); the buyer — the customer's billing details (set by their owners and admins; the account's name when none) and the payer's email. A later change applies to the next invoices only; an invoice is never changed — a refund since is shown beside it, not taken from it (credit notes are not here).
- **The pages:** every member sees the account's invoices (as they see its wallet), newest first, a page at a time; one invoice is a page for printing, with a Print button and print styles that leave the console out, its date shown as the UTC day its number's year counts by. The payment email's link names no account, so an invoice opens from any account the person belongs to (or the one staff are looking at), not only the one open. Admin → Invoices shows the number's shape from the server's digit count.
- **In a browser** (built-in pane, on the throwaway copy, put back after): the operator's details, GST 18 % and prefix `AV/`, and a customer's billing details set; three bank transfers confirmed through the kit's own `Payments::confirm` — invoices `AV/2026-000001` to `000003`; the customer's list newest first with the UTC date; the printable invoice (both parties with their line breaks; ₹1,180.00 = ₹1,000.00 before tax + GST 18 % ₹180.00; paid with the bank transfer), every navigation element and button outside the invoice under the print rules; Admin → Invoices with the number's shape; the customer's admin page with their invoices and billing details, one invoice opened there with the way back. Nothing to fix was found there. Built by the console's builder and changed before the gate: the lists page (older invoices were out of reach), the number's digit count comes from the server, an invoice's date is its UTC day, and an invoice opens from any account the person belongs to.

**More OpenRouter models, and the processing country** (kit API 0.54.0, 275 operations, 11-09). Admin → Catalog → OpenRouter → Add models (`listOpenModels`, `getOpenModelPrice`; `saveCatalogCuration`'s `extraModels`; a curated provider's `openModels` and a model's `added`; ability `settings`; `App\Kit\Catalog\OpenModels`, `Catalog::extraChoices`) and the processing country on Admin → Voice platform and a customer's page (`getPlatformProcessingCountry` — `health`; `getCustomerProcessingCountry` — `customers_read`; `App\Kit\Catalog\ProcessingCountry`). Owner's choices 11-09: OpenRouter models and the processing country (Admin only); the voice library set aside — adding a voice from Telenow's library needs the caller's own ElevenLabs key (`library_own_key_required`, Telenow's owner decision 11-05), so it waits until agents carry their own provider keys.
- **What Telenow does** (recorded on its live release bfd2df4, contract_k31openrouter.py, 14 recordings, the release reaching only openrouter.ai's public list): `listOpenRouterModels` is OpenRouter's own list (461 models, all text to text, `~` aliases among them; empty when OpenRouter is out of reach); `getOpenRouterPrice` is Telenow's price to the operator per million tokens, its margin in (found, or `{found: false}` for an unknown or empty id); an agent takes ANY OpenRouter id — `createAgent` answered 201 for `nobody/no-such-model` — and Telenow bills what OpenRouter reports per request plus its margin. `getProcessingCountry` answers `{country, source}` (`OrgCountry`, `OrgTimezone`, …): the account's declared country, else its sign-up time zone's; a sub-account copies the parent's country when it is made and keeps it after the parent's changes (recorded: the operator moved to DE, an older sub-account still IN, a newer one DE).
- **Adding models:** only the provider `KIT_OPEN_MODELS_PROVIDER` (openrouter; empty turns it off) carries `extraModels` (id → offered, own name, and OpenRouter's name when it was added), listed after the catalog's own, offered until switched off, named and checked against the platform's name as the catalog's models are. Because Telenow takes any id, the kit takes only a model of OpenRouter's list as Telenow reads it, text to text, of a model id's shape (`kit.catalog.open_models.id_pattern`), never one the catalog has, at most `KIT_OPEN_MODELS_MAX` (100). The ids sent are the added ones now (`{}` none; not sent, kept); one added before stays under its name of then without the list being read again, so a save works while OpenRouter is out of reach; a new one then is refused as "could not be read". A model the catalog takes in later shows once, as the catalog's. Customers pick an added model on their agents and as the new-agent default as any other (`Catalog::stack`, `presentStack`).
- **The list and the price:** the list is kept `KIT_OPEN_MODELS_LIST_MINUTES` (30), never when empty; searched by id or name in any case, `KIT_OPEN_MODELS_PER_PAGE` (50) a page, each marked added or in the catalog. The price is the operator's own cost (input and output per million tokens, US dollars; Telenow's margin percent is not passed on), on Admin only.
- **The processing country:** read with the operator's key, or the customer's own, kept `KIT_PROCESSING_COUNTRY_MINUTES` (60) each apart, read afresh on `fresh`; the source in the kit's words — `country`, `time_zone`, else `unknown` (a country that is not two capital letters too). Shown, not set: there is no call to set it, and the operator's account country is Telenow's setting.
- **Checked on the live release, through the kit's own code** (its operator test key and a sub-account's own, the release reaching only openrouter.ai; the copy's own settings, cache and catalog put back after): 21/21 — the release's catalog has OpenRouter with models of its own; the list as the release answers it, every text model, the catalog's three among them; a search; kept; the release's price for `openai/gpt-4o-mini` and none for an unknown id; two models of the list added (one named, one under OpenRouter's name) and one not in the list refused with nothing saved; an agent on a model not added refused before the release; an agent made at the release on an added model with the sub-account's key, read back there as `openrouter` and that model, named as the operator named it; moved to the other added model, at the release too; deleted there (404 after); the operator's processing country and the sub-account's (the same, from the account's country), kept until asked afresh, the operator's moved to DE by SQL while the sub-account kept its own, then from the time zone with no country; the account put back.
- **The console:** Admin → Catalog, under Language models, "Add models to OpenRouter" (`console/src/admin/OpenModels.tsx`): the list searched (Enter or Search, never submitting the page) a page at a time, each row's price on asking ("your cost", an estimate, customers never see it; "No price yet"), Add (In the catalog rows cannot be added; none once the most are added), how many of the most; added models join OpenRouter's models in the table with the catalog's switch and name field, marked Added, with Remove; Save sends them as `extraModels` (`{}` for none); each refusal at its row, its name or the area; a list that cannot be read is said in the area while the page and its Save work on. The new-agent default model lists the form's models as they stand, an added one included before it is saved. The processing country (`ProcessingCountry.tsx`) on Admin → Voice platform and a customer's page: the country's name in the viewer's language with its code, where it comes from, when checked, Check again; a failure said in the panel only (a 409 quietly).
- **In a browser** (built-in pane, the copy's staff owner, the copy pointed at the live release with its operator test key and a sub-account's own, put back after): OpenRouter's real list searched for "llama" (11, the catalog's Llama 3.3 70B marked In the catalog); the release's prices (Llama 4 Maverick US$0.4025 read / US$1.15 written per million tokens; Llama 3.1 8B US$0.023 / US$0.046); two added, one named "Maverick for long calls"; OpenRouter offered and that model made the new-agent default, saved. Found here and changed before the gate: the default-model list showed only models already saved, so a model added in the same visit could not be the default until a second save. A customer's console (looked at as staff) then offered OpenRouter's three and the two added, the named one under its name and as the default, with no platform name or price in the answer. The processing country on Admin → Voice platform and the customer's page: India, from the account's country; the release's operator moved to Germany by SQL — the page kept India until Check again, then Germany; the customer, made before, still India after its Check again; put back.
- **Breaks, in sibling clones:** backend 67/68 caught; the one left is equivalent (the client's own filter of rows that are not objects: the next step's checks drop them the same way). Console 105/105 (written by the console's builder, 102/102; the default-model list's rewritten after the browser check and run again here, one more test added for a catalog model switched off).

**Agent helpers: the prompt check, call health, agent logos** (kit API 0.55.0, 282 operations, 11-09). The prompt check on an agent's page and the new-agent page (`checkPrompt`, `checkNewPrompt`; `App\Kit\Agents\PromptCheck`, and `Agents::create`/`update` refusing a broken save); call health on Console → Home and an agent's page (`getCallHealth`), a customer's page (`getCustomerCallHealth` — `customers_read`), Admin → Voice platform (`callHealth`) and the dashboard (`providersFailing`) (`App\Kit\Calls\CallHealth`, `kit:call-health`); agent logos (`setAgentLogo`, `removeAgentLogo`, `getAgentLogo`; `App\Kit\Agents\AgentLogos`, a column `agents.logo`). Owner's choices 11-09: all three; the check with a preview and a save with errors refused; call health for customers and staff, staff in detail; logos kept in the kit. Left out, as they would not work in the kit yet: Telenow's language suggestion (it only pre-fills a multi-language agent list — `metadata.languages`, which turns every spoken line into a paid translation run — that the kit does not have) and app tools (from Telenow's installed apps, which the kit cannot install).
- **What Telenow does** (recorded on its live release bfd2df4, contract_k32helpers.py, 31 recordings; the failures written by SQL as its call engine writes them, no call placed): `validatePrompt` checks conditional sections — `{#if {plan} == "gold"}` … `{#elif …}` … `{#else}` … `{/if}`, the variable in braces — and lints them: an unclosed section is an error; a near-miss tag (`{{#if`), a condition on a bare name (`plan` without braces), on a variable nothing declares, a value none of a choice's, a flow base reading a step's capture, a section in the greeting are warnings, each with its line and a message that names no platform; with `values` it renders the prompt (`decisions`: branches from 0). `createAgent`/`updateAgent` refuse an unclosed section (400 `prompt_sections_malformed`). `getProviderHealth` groups the key's own account's `voice_stack_incidents` in a window (1–1440 minutes) by part, provider, key source and cause, with the provider's raw error (which can name the platform) and Telenow's agent ids; an operator's key reads its own account only, never its sub-accounts'.
- **The prompt check:** the agent's own declared variables, its tools' names and, with call steps, `flowBase` and what its steps capture go with the text; a finding's field is the kit's (`systemPrompt`, `greeting`), a branch counted from 1. Instructions longer than Telenow checks (`KIT_PROMPT_CHECK_MAX`, 12000) are saved unchecked (Telenow's own save still refuses a broken section). On a save that changes the instructions and holds a brace, the kit asks Telenow first and refuses an unclosed section with Telenow's messages (each says its line); out of reach, the save goes on. The console checks 700 ms after typing stops (`KIT_PROMPT_CHECKS_PER_MINUTE`, 30, a member); the preview takes the call form's variables and names a member adds, and a channel.
- **Call health:** each failure under the operator's name for the provider (Admin → Catalog; a name carrying the platform's, or none, said by its part), the cause in the kit's words (`provider`, `setup`, `platform`, `unknown`), the account's own live agents; never the raw error or Telenow's ids for customers; staff get them, the error cut at `kit.health.error_length`. A read is kept `KIT_CALL_HEALTH_CACHE_SECONDS` (60). `kit:call-health` every `KIT_CALL_HEALTH_SWEEP_MINUTES` (15) reads the accounts with calls in `KIT_CALL_HEALTH_MINUTES` (60), the busiest first, at most `KIT_CALL_HEALTH_SWEEP_MAX` (200), and adds them up by provider; a provider with `KIT_CALL_HEALTH_ALERT_CALLS` (3) calls or more is flagged (the level set now, on the page and the dashboard alike).
- **Agent logos:** a PNG, JPEG or WebP judged by its bytes (never an SVG), `KIT_AGENT_LOGO_MAX_KB` (512) and 16–`KIT_AGENT_LOGO_MAX_PX` (1024) pixels a side, kept on `KIT_AGENT_LOGO_DISK` (local) under its content's hash, the image before removed; served to anyone at `/api/kit/media/agent-logos/{agent}/{file}` — only the agent's current image — cached a year (`immutable`), `nosniff`, a policy allowing nothing, no session. A duplicate gets its own file; deleting an agent removes it; starter agents carry none. A removed image stays in browsers that cached it, though nothing names it any more.
- **Checked on the live release, through the kit's own code** (a sub-account's key and the operator's; everything put back): 14/14 — a clean prompt; an unclosed section an error at line 2; a section in the opening line a warning; a preview keeping the gold branch, and the other with no values (Telenow keeps the newline before `{/if}`); an agent with an unclosed section refused in the kit with nothing made at the release, one with closed sections made, then changed to an unclosed one refused with the release keeping the closed; call health none, then two failures written there — under the operator's names, the kit's causes, its own agent, no raw error or id for the customer, both for staff; the sweep adding the account up; the agent deleted there.
- **In a browser** (built-in pane, on the throwaway copy pointed at the live release, a test account's member and the staff owner, put back after): Console → Home's call health (Deepgram, OpenAI TTS, OpenAI; plain causes; no platform name or error); the agent list's logo; on the agent's page an unclosed and a near-miss section found as typed, Save refused at the field, the preview with `plan` = gold keeping branch 1 of 2, the agent's own failures only; the logo beside the title in the chat box on a test website; Remove the logo (unsaved typing kept; the server answers 404 after). Admin → Voice platform's summary (with the copy's level at 2: Deepgram flagged), the dashboard's item linking to it, a customer's page with the errors and ids. Found there and changed before the gate: a finding's line was said twice ("Line 2: Line 2: …": Telenow's message starts with it); the Voice platform page named the level of the last sweep while the dashboard counted by the level set now.
- **Found by the four-database run and changed before the gate:** the logo tests made their images with GD (Laravel's `UploadedFile::fake()->image()`), which the CI's PHP and the box's do not have — the kit itself needs none, reading an image with PHP's own `getimagesize`; they now use `Tests\Support\TestImages` (a PNG built from zlib and a CRC, and a tiny JPEG, WebP and GIF kept as bytes).
- **Breaks, in sibling clones:** backend 86/87 caught; the one left is equivalent (`valid` read when Telenow's answer has none: its document requires it). Console 155/155 (written by the console's builder, the prompt check's run again here after the fix, with three more).

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
