/**
 * scripts/check-release.mjs against small zips made here (the system `zip`): a release shaped like a good one passes,
 * and each way a release can be wrong — something private or made by a running install inside, the tests or a dev
 * package inside, a runtime package, the built console or the chat box script missing, the console loading what is
 * not there, a folder left without its deny-all .htaccess, a root .htaccess that does not send requests into public/,
 * a public/.htaccess that serves dotfiles, a VERSION that is not the zip's — is named. Run with `node --test scripts/*.test.mjs` (the root package's `test:release`).
 */
import { execFileSync } from "node:child_process";
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { after, describe, it } from "node:test";
import assert from "node:assert/strict";
import { checkRelease } from "./check-release.mjs";

const work = mkdtempSync(join(tmpdir(), "check-release-"));
after(() => rmSync(work, { recursive: true, force: true }));

const DENY = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n";
const ROOT = "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^(.*)$ public/$1 [L]\n</IfModule>\n";

/** A good release's files, by path under its top folder. */
function good() {
  return {
    VERSION: "9.9.9\n",
    artisan: "<?php\n",
    "composer.json": "{}",
    "composer.lock": JSON.stringify({ packages: [{ name: "acme/runtime" }], "packages-dev": [{ name: "acme/dev-tool" }] }),
    ".htaccess": ROOT,
    "config/kit.php": "<?php return ['console' => ['build_dir' => 'console-app']];\n",
    "config/.htaccess": DENY,
    "vendor/autoload.php": "<?php\n",
    "vendor/acme/runtime/src/A.php": "<?php\n",
    "vendor/.htaccess": DENY,
    "storage/.htaccess": DENY,
    "storage/logs/.gitignore": "*\n!.gitignore\n",
    "bootstrap/cache/.gitignore": "*\n!.gitignore\n",
    "bootstrap/app.php": "<?php\n",
    "bootstrap/.htaccess": DENY,
    "public/index.php": "<?php\n",
    "public/.htaccess": `RewriteEngine On\n<FilesMatch "^\\.">\n    ${DENY}</FilesMatch>\n`,
    "public/theme/default.css": "body{}\n",
    "public/chat/widget.js": "(() => {})();\n",
    "public/console-app/index.html": '<script type="module" src="/console-app/assets/a.js"></script><link rel="stylesheet" href="/console-app/assets/a.css">',
    "public/console-app/assets/a.js": "",
    "public/console-app/assets/a.css": "",
  };
}

let n = 0;
/** A zip of `files` (null drops one) in a top folder `top`. */
function zipOf(files, { top = "voice-kit-9.9.9", name = top } = {}) {
  const tree = join(work, `z${++n}`, "tree");
  for (const [path, body] of Object.entries(files)) {
    if (body === null) continue;
    mkdirSync(dirname(join(tree, top, path)), { recursive: true });
    writeFileSync(join(tree, top, path), body);
  }
  const zip = join(work, `z${n}`, `${name}.zip`);
  execFileSync("zip", ["-q", "-r", "-X", zip, top], { cwd: tree });
  return zip;
}

describe("check-release", () => {
  it("passes a good release", () => {
    assert.deepEqual(checkRelease(zipOf(good())), []);
  });

  /** Each wrong release, and the problem it must be named for. */
  const wrong = {
    "an environment file": [{ ".env": "APP_KEY=x" }, /^an environment file: \.env$/],
    "an environment file (a copy)": [{ ".env.backup": "APP_KEY=x" }, /^an environment file: \.env\.backup$/],
    "a database file": [{ "database/database.sqlite": "x" }, /^a database file: database\/database\.sqlite$/],
    "a log": [{ "storage/logs/laravel.log": "x" }, /^a log: storage\/logs\/laravel\.log$/],
    "a session": [{ "storage/framework/sessions/abc": "x" }, /^a file a running install makes: storage\/framework\/sessions\/abc$/],
    "the install lock": [{ "storage/app/kit/installed": "{}" }, /^a file a running install makes: storage\/app\/kit\/installed$/],
    "a cached manifest": [{ "bootstrap/cache/packages.php": "<?php" }, /^a file a running install makes: bootstrap\/cache\/packages\.php$/],
    "macOS metadata": [{ "app/._Foo.php": "x" }, /^macOS metadata: app\/\._Foo\.php$/],
    "the tests": [{ "tests/Feature/ATest.php": "<?php" }, /^the tests: tests\/Feature\/ATest\.php$/],
    "a dev package": [{ "vendor/acme/dev-tool/src/B.php": "<?php" }, /^a dev package: vendor\/acme\/dev-tool$/],
    "a runtime package missing": [{ "vendor/acme/runtime/src/A.php": null }, /^missing the package vendor\/acme\/runtime$/],
    "no autoloader": [{ "vendor/autoload.php": null }, /^missing vendor\/autoload\.php$/],
    "no chat box script": [{ "public/chat/widget.js": null }, /^missing public\/chat\/widget\.js$/],
    "no built console": [{ "public/console-app/index.html": null }, /^missing the built console \(public\/console-app\/index\.html\)$/],
    "the console loading what is not there": [{ "public/console-app/assets/a.js": null }, /loads \/console-app\/assets\/a\.js, not in the zip$/],
    "the console loading from outside its folder": [{ "public/console-app/index.html": '<script src="/assets/a.js"></script>' }, /loads \/assets\/a\.js, outside \/console-app\/$/],
    "a folder with no deny-all .htaccess": [{ "config/.htaccess": null }, /^config\/ has no deny-all \.htaccess$/],
    "a folder whose .htaccess does not deny": [{ "storage/.htaccess": "Options -Indexes\n" }, /^storage\/ has no deny-all \.htaccess$/],
    "a root .htaccess that does not send requests into public/": [{ ".htaccess": "Options -Indexes\n" }, /^the root \.htaccess does not send every request into public\/$/],
    "a public/.htaccess that serves dotfiles": [{ "public/.htaccess": "RewriteEngine On\n" }, /^public\/\.htaccess serves files whose name starts with a dot$/],
    "a VERSION that is not the zip's": [{ VERSION: "9.9.8\n" }, /^VERSION says 9\.9\.8, the zip is voice-kit-9\.9\.9$/],
  };
  for (const [what, [change, named]] of Object.entries(wrong)) {
    it(`names ${what}`, () => {
      const problems = checkRelease(zipOf({ ...good(), ...change }));
      assert.ok(problems.some((p) => named.test(p)), `${what}: not named; said ${JSON.stringify(problems)}`);
    });
  }

  it("names a zip whose folder is not the release's", () => {
    assert.match(checkRelease(zipOf(good(), { top: "kit", name: "voice-kit-9.9.9" }))[0], /one top folder voice-kit-9\.9\.9\//);
  });
});
