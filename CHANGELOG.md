# Changelog

What changed in each release of the White-Label Voice AI Kit. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).
How each feature was built and proven is in [docs/BUILD_NOTES.md](docs/BUILD_NOTES.md).

## [Unreleased]

### Changed

- **A new look for the console and the admin panel.** A side menu holds the pages in groups, each with an icon, beside the
  page, and stays in view while the page scrolls: in the console Build, Conversations, Phone numbers, Compliance and
  Billing and team; in the admin panel Prices and payments, What customers get, Sign-ups and messages, and Site and staff
  (each staff role still sees only its pages, in the same order). On a phone the menu folds behind a Menu button. Pages
  sit on the brand's surface colour with their parts as cards; tables, buttons, fields, notices and the label-and-value
  lists are restyled. Every colour is still the operator's brand, the shades in between mixed from it, so text on a
  brand tint stays readable whatever the colour. With no logo, the brand bar shows the brand's initial in its colour.

## [0.2.0] - 2026-11-14

Web chat, insights and exports, caller memory, call routing, number controls, customer invoices and the operator's own
billing at the voice platform, and more help building agents. Kit API 0.55.0 (282 operations).

### Added

- **Web chat agents.** A customer turns on a chat box for any agent (its page → Web chat), lists the websites it may show
  on, and puts one line of code on their pages. Visitors chat with the same agent that takes calls, through this platform
  only: the chat box is this platform's own script, and nothing of the voice platform reaches a visitor. Off until the
  operator offers it (Admin → Catalog → Web chat). Each conversation is a call with the channel `chat` in the call
  history, with its transcript, and is charged by the price lists like any call. Idle conversations end after 10 minutes
  (`KIT_CHAT_IDLE_MINUTES`). Kit API 0.42.0 (229 operations).
- **Insights and exports.** Console → Insights: calls, answered, minutes, outcomes, sentiment, channels and spend over a
  period, day by day and agent by agent, in the viewer's time zone. The calls list filters by days, outcome and channel,
  and downloads as a spreadsheet with the customer's own charges; the do-not-call list and consent records download too.
  All from the kit's own records: nothing of the voice platform is in them. Kit API 0.43.0 (233 operations).
- **Caller memory.** Console → Caller memory: what an account's agents remember about the people they talk to (after each
  call or chat, a short summary and some facts, used the next time that person calls), read live and searchable. Callers
  are named by their number, as a web chat visitor with the website they chatted on, or by an app's own identifier; ended
  memories are shown, marked no longer used. The account's owner forgets a caller from the list, or anyone by a phone
  number written any way. An agent's "remember callers" setting links to it. Kit API 0.44.0 (235 operations).
- **Knowledge tables.** A CSV, JSON or Excel file in a knowledge base is a table agents look rows up in on calls (a small
  one they also read whole). Its page now shows the columns, a page of rows at a time, and how agents use it; a column can
  be read as text, numbers, yes or no, or dates, so prices can be compared and added up even when a cell says "N/A". Kit
  API 0.45.0 (237 operations).
- **Duplicate an agent.** An agent's page → Duplicate makes a copy in the same account (prompt, call settings, voice setup,
  hand-overs, tools and look-ups, call steps, knowledge, webhook addresses, on or off), sent to the voice platform the way the
  customer's own saves are, and lists what is left to do: keys to type again (they are never copied), call steps to publish,
  webhook addresses' new signing secrets. Phone numbers and the web chat box stay with the source. Kit API 0.46.0 (238
  operations).
- **Starter agents.** The operator offers every customer ready-made agents (Admin → Starter agents), published only from an
  agent in an account the staff member owns, after reading every text it carries. A starter carries the prompt, call
  settings, voice setup, analysis fields and call steps, and nothing of that account: no keys, tools, look-ups, hand-overs,
  transfers, knowledge, webhooks, email recipients or own voice; tool, hand-over and transfer steps become endings.
  Customers start a new agent from one on the new-agent page. A new staff ability, `starters` (owner, admin). Kit API 0.47.0
  (244 operations).
- **Tell the agent during a call.** On a live call's page a manager can tell the agent something: it sees it from its next
  reply as background, never as the caller's words, and with "say it now" says it to the caller as soon as nobody is
  speaking. Every note is kept on the call's page with who wrote it. Kit API 0.48.0 (245 operations).
- **Campaign contacts from a link.** A campaign can take its people from a CSV at a web address (a spreadsheet published to
  the web as CSV, say): read at once and again every 15 minutes until the campaign finishes, adding only new numbers and never
  taking anyone off. This server reads the link only when every step is public (https, every address the name resolves to,
  each redirect) and pins the connection to the address it checked, trying the next checked address when one cannot be reached.
  Off until the operator allows it (Admin → Catalog).
  Kit API 0.49.0 (247 operations).
- **Call routing.** Calls to a number can follow the account's own rules (Console → Call routing): the first rule that
  matches takes the call — by the number called, the line that forwarded the call, how the caller's number starts, and
  days and hours in a timezone — and has a chosen agent answer (with a language, a greeting and a cap on the call's
  length) or refuses the call with a busy line. When no rule matches, or this server cannot answer within about a second,
  the number's own agent answers. The account's managers set them; every member can try a pretend call against them, and
  the voice platform's own test checks a routed number through this server. Needs this server at a public https address.
  Kit API 0.50.0 (256 operations).
- **Number controls.** On Console → Numbers: a number can be its agent's caller id (the number the agent's calls come from
  when a call names none, a campaign with no caller id included); a number no agent answers plays a short "no one is
  available" message, gives a busy line, or says the line is unavailable (the last two never answer the call); numbers
  the voice platform gave up when their rent was not paid can be got back for a few days at the plan's price (at once, as
  a new number set up as the old one — its agent, caller id, campaigns and routing rules — or by hand before a date); and
  each number's next rent shows against the balance, with a warning (and one email to the account's owners) when the
  balance will not cover it. Kit API 0.51.0 (261 operations).
- **Platform billing.** Admin → Platform billing (staff who handle money): the operator's own account at the voice
  platform — its state (fine, low, stopped and why), balance, what it can still spend and this month's spend; a low-balance
  level set at the platform, with one email to those staff when the balance falls to it and one when the platform stops the
  account (customers then see that the service is paused); and the platform's invoices to the operator with their lines and
  tax (a postpaid account's only). Admin → Cost vs price opens one customer's figures by what was charged. Kit API 0.52.0
  (263 operations).
- **Customer invoices.** Every payment credited to a customer's wallet (through a gateway, or a bank transfer staff
  confirmed) gets an invoice, numbered without gaps per year (prefix, year, number: `INV-2026-000001`), with the
  operator's business details and the customer's billing details as they were when it was issued. When the operator
  sets a tax (Admin → Invoices: its name and rate), the amount paid is shown as including it, split into the amount before
  tax and the tax. Customers see their invoices from the wallet, each on a page made for printing or saving as PDF; the
  payment email links to it; their owners and admins set the business name, address and tax number printed on the next
  ones. Staff see a customer's invoices from the customer's page. Kit API 0.53.0 (271 operations).
- **More OpenRouter models.** On Admin → Catalog the operator adds language models to OpenRouter beyond the catalog's own,
  picked from OpenRouter's own list (text models only), searched a page at a time, each with what the voice platform
  charges the operator for it per million tokens (the operator's own cost, an estimate; customers never see it). An
  added model is offered, named and switched off like the catalog's, may be the new-agent default, and customers pick it
  on their agents as any other. At most 100 (`KIT_OPEN_MODELS_MAX`); a model the catalog takes in later shows once, as
  the catalog's. Kit API 0.54.0 (275 operations).
- **Processing country.** Admin → Voice platform shows the country the voice platform serves the operator's account's
  language models for (when an agent leaves the region to it), and where it comes from (the account's country, else its
  time zone's); a customer's page shows the customer's, which is the operator's when the customer was made — set on the
  voice platform, not here. Admin only.
- **Prompt check.** An agent's instructions and opening line are checked as the voice platform checks them, a moment after
  typing stops: conditional sections (`{#if {plan} == "gold"}` … `{#else}` … `{/if}`) that do not open and close are errors,
  and the rest (a near-miss tag, a condition on a name without braces or on a variable nothing sets, a value that is none of a
  choice's, a section in the opening line) warnings, each with its line. A save with errors is refused before anything is
  sent, where the platform would refuse it anyway. With sample values, a preview of the instructions as a call would get
  them, and which branch each section took. Kit API 0.55.0.
- **Call health.** Which speech recognition, voice and language model providers failed on recent calls, how many calls,
  and on which agents: customers on their home page and each agent's page, under the operator's names and without the
  provider's own error; staff on a customer's page with the error, and Admin → Voice platform adds every customer's up
  every 15 minutes (`kit:call-health`, the accounts with calls first), with the dashboard flagging a failing provider.
- **Agent logos.** An agent can have a logo (PNG, JPEG or WebP), kept by the kit itself and shown in the console and at the
  top of the agent's chat box on customers' websites. Kit API 0.55.0 (282 operations).

### Changed

- The calls list's agent filter with something that is not an agent's id finds no call; it used to find the calls with no
  agent.

### Known limits

- Needs PHP 8.4.1 or later, as 0.1.0 did.
- English only. Every word is in `laravel/lang/en` and `console/src/text.ts`, ready for a translation.
- Updating from 0.1.0 means replacing the files and running `php artisan migrate --force` once
  ([INSTALL](laravel/INSTALL.md)): updating from the browser is not built yet.
- Not in the kit yet, although Telenow has them: integrations, number compliance documents and Telenow's shared voice
  library.
- Tested against recorded Telenow answers and a real Telenow release with a stand-in voice engine; this release's
  testing did not include a call on a real phone line, a real AI vendor or a payment gateway's real sandbox account.

## [0.1.0] - 2026-11-07

The first public release: the whole platform an operator installs on their own server and runs under their own brand,
with Telenow's voice infrastructure behind it. Kit API 0.41.0 (223 operations).

### Added

- **Install from the browser:** a release zip for shared hosting or a VPS, and a setup wizard for the database (MySQL 8,
  MariaDB 10.11, PostgreSQL 16 or SQLite), the operator's account, the Telenow key, the brand, email, sign-ups and
  payments. One cron line runs every scheduled job.
- **AI voice agents:** instructions and greetings, call settings, voicemail, post-call analysis and QA criteria; the
  language model, speech recognition and voice, each with its provider's settings; realtime voices (OpenAI Realtime,
  Gemini Live) while the platform runs them; voice cloning with the speaker's signed consent; tools, look-ups before the
  call and hand-overs; a visual call-flow builder; versions that can be compared and put back; knowledge bases; hearing
  a voice or the agent, a browser test call and a voice setup check.
- **Calls, campaigns and numbers:** inbound and outbound calls on numbers customers buy, outbound campaigns from a CSV
  with a calling window and attempts per person, call history with recordings and transcripts, listening in, human
  agents taking calls in the browser, an agent calling one person on demand, a do-not-call list and a consent ledger,
  webhooks and after-call email.
- **Billing:** price lists per plan (a markup or a per-minute price for each part of a call), customer wallets with
  top-ups, credits, low-balance emails and limits, and Stripe, PayPal, Razorpay, Paystack, Flutterwave and Tap.
- **Brand and sign-in:** the operator's name, logo, colours and domain on the website, the console and every email; a
  public website with themes; sign-in by password, email link or code, a code by text or WhatsApp, Google, Microsoft
  or any OpenID Connect provider; Cloudflare Turnstile, hCaptcha or Google reCAPTCHA; SMTP and HTTP SMS senders with
  fallbacks.
- **Running the platform:** each customer a workspace and a Telenow sub-account with its own encrypted key; curating
  which providers, models and voices customers see, under the operator's own names; staff roles and an audit log;
  platform health; opening a customer's console read-only.

### Known limits

- Needs PHP 8.4.1 or later: the release's packages (Symfony 8.1) do not run on PHP 8.3.
- English only. Every word is in `laravel/lang/en` and `console/src/text.ts`, ready for a translation.
- Updating means replacing the files and running `php artisan migrate --force` once ([INSTALL](laravel/INSTALL.md)):
  updating from the browser is not built yet.
- Not in the kit yet, although Telenow has them: chat agents, integrations, call insights and exports, inbound routing
  rules, number compliance documents, caller memory, knowledge tables and invoices.
- Tested against recorded Telenow answers and a real Telenow release with a stand-in voice engine; this release's
  testing did not include a call on a real phone line or a real AI vendor.

[Unreleased]: https://github.com/TelenowAI/White-Label-Voice-AI-KIT/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/TelenowAI/White-Label-Voice-AI-KIT/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/TelenowAI/White-Label-Voice-AI-KIT/releases/tag/v0.1.0
