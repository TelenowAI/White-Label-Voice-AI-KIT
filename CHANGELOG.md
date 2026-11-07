# Changelog

What changed in each release of the White-Label Voice AI Kit. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).
How each feature was built and proven is in [docs/BUILD_NOTES.md](docs/BUILD_NOTES.md).

## [Unreleased]

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

[Unreleased]: https://github.com/TelenowAI/White-Label-Voice-AI-KIT/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/TelenowAI/White-Label-Voice-AI-KIT/releases/tag/v0.1.0
