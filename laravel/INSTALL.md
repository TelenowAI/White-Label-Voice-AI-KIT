# Installing

1. **Check the server.** PHP 8.4.1 or later (the release's packages refuse anything older, before any page loads), a
   database (MySQL or MariaDB, PostgreSQL or SQLite), HTTPS. The setup wizard's first step checks the PHP extensions and
   folders it needs.
2. **Upload and unzip** this folder, then point the site at it, one of two ways:
   - **The site's folder is `public/`** (the safest): point the domain's document root at this folder's `public/`.
   - **The site's folder must be `public_html`** (shared hosting): put this folder's contents (not the folder itself)
     in `public_html`. Its `.htaccess` sends every request into `public/`, so the platform's own files are never
     served. This needs Apache or LiteSpeed with `mod_rewrite`; on another web server use the first way.
3. **Open the site.** It sends you to the setup wizard (`/setup`): the database, your account, your Telenow key, your
   brand, email, sign-ups and payments.
4. **Add one cron line** (cPanel → Cron Jobs), every minute:
   `* * * * * php /path/to/this/folder/artisan schedule:run >/dev/null 2>&1`
5. **The website**: edit its words in Admin → Website. To change a page's layout, see `themes/README.md`.

## Updating

Replace every file with the new release's, except `.env`, `storage/` and your own folder in `themes/`. Then run
`php artisan migrate --force` once (cPanel → Terminal, or a one-off cron line): updating from the browser is not
built yet.
