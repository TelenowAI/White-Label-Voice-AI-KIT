# Security policy

## Reporting a vulnerability

Please do not report a security problem in a public issue, discussion or pull request. Report it privately on GitHub:
**Security → Report a vulnerability** on this repository
([open a private report](https://github.com/TelenowAI/White-Label-Voice-AI-KIT/security/advisories/new)).

Tell us:
- what is affected: a page, a kit API route, the setup wizard, a scheduled job, the release zip or its `.htaccess` rules;
- the version (`laravel/VERSION`, or the release you installed), the PHP version, the database and the web server;
- how to reproduce it, step by step, and what an attacker gains.

Never put a real API key, password, payment detail or a customer's data in a report: a placeholder is enough.

We answer on the report itself. A fix ships as a new release, and the advisory is published once operators can update.

## Scope

- **In scope:** everything in this repository: the Laravel backend (`laravel/`), the customer console and admin panel
  (`console/`), the setup wizard, the release zip that `scripts/release.mjs` builds, and the kit API (`contract/`).
- **Not in scope:** Telenow's own platform and API. Report those to Telenow ([telenow.ai](https://telenow.ai)).
- **Your own installation's settings** (an exposed `.env`, a document root that is not `public/`, a server without
  HTTPS) are covered in [laravel/INSTALL.md](laravel/INSTALL.md), not here.

## Supported versions

Only the latest release gets security fixes. To update, follow "Updating" in [laravel/INSTALL.md](laravel/INSTALL.md).
