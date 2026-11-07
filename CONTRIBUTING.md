# Contributing

Thanks for helping. Bug reports, fixes, translations and new features are all welcome.

- **A bug:** open an issue with the bug form. Say what you expected, what happened, and how to make it happen again.
- **A feature or a bigger change:** open an issue first, so we can agree on the approach before you write the code.
- **A security problem:** never in a public issue. See [SECURITY.md](SECURITY.md).

Everyone taking part follows the [code of conduct](CODE_OF_CONDUCT.md).

## Set up

You need **Node 22** with **pnpm 10** (`corepack enable` gives you the version `package.json` names) and **PHP 8.4.1+**
with **Composer** and the extensions `bcmath`, `intl` and `pdo_sqlite` (plus `pdo_mysql` or `pdo_pgsql` for those
databases).

```bash
pnpm install
```

```bash
cd laravel && composer install && cp .env.example .env && php artisan key:generate
```

## Run the checks

These are the checks CI runs on every pull request:

```bash
cd laravel && php vendor/bin/phpunit
```

```bash
pnpm --filter console exec tsc -b --force
```

```bash
pnpm --filter console test
```

```bash
pnpm --filter contract test
```

```bash
pnpm test:release
```

CI also runs the Laravel suite on PostgreSQL 16, MariaDB 10.11 and MySQL 8.0, and builds the release zip. To run the
suite on another database yourself, use a throwaway empty database, never a real one: see "On other databases" in
[docs/BUILD_NOTES.md](docs/BUILD_NOTES.md).

## How the kit is built

A change keeps these true:

- **Contract first.** Every kit API route is in [`contract/kit-api.yaml`](contract/kit-api.yaml), and the tests hold
  every answer to it. Change the document in the same pull request as the route.
- **Tests never call the real Telenow.** `tests/TestCase.php` points the backend at `https://telenow.test`. What Telenow
  answers comes from recordings in `contract/telenow-mock/cassettes`, made from real Telenow answers with keys and
  personal data taken out, never written by hand.
- **Every write to Telenow goes through the outbox** (`laravel/app/Kit/Outbox`): saved with the change, sent, and
  retried with the same idempotency key, so nothing is lost or sent twice.
- **Customers never see Telenow.** No Telenow id, name or price reaches a customer's page or answer.
- **Secrets stay where they are.** A field Telenow treats as secret is never copied into the kit or shown back.
- **Every database.** Anything stored as JSON is `longText`, an id is `char(36)`, and the session is UTC (the reasons
  are in "On other databases" in [docs/BUILD_NOTES.md](docs/BUILD_NOTES.md)). A migration that has been released is
  never edited: add a new one.
- **Words live in one place.** Backend text is in `laravel/lang/en`, the console's in `console/src/text.ts`, so a second
  language is a translation, not a code change.
- **It runs on shared hosting.** No shell, queue worker or Node on the server: only PHP, a database and one cron line.
  A new runtime package needs a good reason.

## Pull requests

- One change per pull request, with tests that fail without it.
- Fill in the template: what changed, why, and how you checked it.
- Screenshots for any change you can see in the console or the website.
- By contributing, you agree that your work is published under the project's [MIT licence](LICENSE).
