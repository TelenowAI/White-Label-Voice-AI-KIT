#!/usr/bin/env node
/**
 * What makes a release zip good (scripts/release.mjs runs it on every zip it makes; run it alone on any zip):
 * - one top folder, `voice-kit-<VERSION>/`, whose VERSION file says the same version;
 * - nothing private or made by a running install: no `.env`, database file, log, macOS metadata, install lock, and
 *   storage/ and bootstrap/cache/ holding only .gitignore files;
 * - no tests, no Composer dev package (composer.lock's `packages-dev`), and every runtime package (`packages`);
 * - what runs it: vendor/autoload.php, artisan, public/index.php and its .htaccess, the theme's style sheet, the chat
 *   box script customers put on their websites (public/chat/widget.js), and the built console
 *   (public/<build dir>/index.html) with every script and style sheet it loads;
 * - a root .htaccess that sends every request into public/, a deny-all .htaccess in every other top-level folder, and
 *   a public/.htaccess that never serves a file whose name starts with a dot.
 *
 * Usage: node scripts/check-release.mjs <zip>. Answers the problems, one per line; exits 1 when there is any.
 * Uses the system `unzip`. Tested by scripts/check-release.test.mjs.
 */
import { execFileSync } from "node:child_process";
import { basename } from "node:path";
import { fileURLToPath } from "node:url";

const NEVER = [
  [/(^|\/)\.env($|\.)/, "an environment file"],
  [/\.sqlite($|-)/, "a database file"],
  [/\.log$/, "a log"],
  [/(^|\/)(\.DS_Store|__MACOSX|\._[^/]*)(\/|$)/, "macOS metadata"],
  [/^tests\//, "the tests"],
  [/^phpunit\.xml$/, "the test settings"],
  [/(^|\/)node_modules\//, "node_modules"],
  // The release's own deny-all storage/.htaccess aside.
  [/^(storage|bootstrap\/cache)\/(?!\.htaccess$)(.+\/)?(?!\.gitignore$)[^/]+$/, "a file a running install makes"],
];

function entriesOf(zip) {
  return execFileSync("unzip", ["-Z1", zip], { encoding: "utf8", maxBuffer: 256 * 1024 * 1024 }).split("\n").filter(Boolean);
}

function read(zip, entry) {
  return execFileSync("unzip", ["-p", zip, entry], { encoding: "utf8", maxBuffer: 64 * 1024 * 1024 });
}

/** @returns {string[]} what is wrong with the release at `zip`; none for a good one */
export function checkRelease(zip) {
  const problems = [];
  const all = entriesOf(zip);
  const tops = [...new Set(all.map((e) => e.split("/")[0]))];
  const expected = basename(zip).replace(/\.zip$/, "");
  if (tops.length !== 1 || tops[0] !== expected) return [`one top folder ${expected}/, not ${tops.join(", ")}`];
  const top = `${expected}/`;
  const files = new Set(all.filter((e) => !e.endsWith("/")).map((e) => e.slice(top.length)));
  const dirs = new Set(all.filter((e) => e.endsWith("/")).map((e) => e.slice(top.length, -1)).filter(Boolean));
  for (const f of files) for (const d of f.split("/").slice(0, -1).map((_, i, parts) => parts.slice(0, i + 1).join("/"))) dirs.add(d);

  for (const f of files) {
    for (const [rule, what] of NEVER) if (rule.test(f)) problems.push(`${what}: ${f}`);
  }

  const version = files.has("VERSION") ? read(zip, `${top}VERSION`).trim() : null;
  if (`voice-kit-${version}` !== expected) problems.push(`VERSION says ${version}, the zip is ${expected}`);

  for (const need of ["artisan", "composer.json", "composer.lock", "vendor/autoload.php", "public/index.php", "public/.htaccess", "public/theme/default.css", "public/chat/widget.js"]) {
    if (!files.has(need)) problems.push(`missing ${need}`);
  }

  if (files.has("composer.lock")) {
    const lock = JSON.parse(read(zip, `${top}composer.lock`));
    for (const p of lock["packages-dev"] ?? []) if (dirs.has(`vendor/${p.name}`)) problems.push(`a dev package: vendor/${p.name}`);
    for (const p of lock.packages ?? []) if (!dirs.has(`vendor/${p.name}`)) problems.push(`missing the package vendor/${p.name}`);
  }

  if (files.has("config/kit.php")) {
    const buildDir = read(zip, `${top}config/kit.php`).match(/'build_dir'\s*=>\s*'([^']+)'/)?.[1];
    const index = `public/${buildDir}/index.html`;
    if (!buildDir || !files.has(index)) {
      problems.push(`missing the built console (${index})`);
    } else {
      const html = read(zip, `${top}${index}`);
      const loads = [...html.matchAll(/(?:src|href)="([^"]+)"/g)].map((m) => m[1]).filter((u) => !/^[a-z]+:|^\/\//i.test(u));
      if (loads.length === 0) problems.push(`${index} loads nothing`);
      for (const url of loads) {
        if (!url.startsWith(`/${buildDir}/`)) problems.push(`${index} loads ${url}, outside /${buildDir}/`);
        else if (!files.has(`public${url.split(/[?#]/)[0]}`)) problems.push(`${index} loads ${url}, not in the zip`);
      }
    }
  } else {
    problems.push("missing config/kit.php");
  }

  const publicHtaccess = files.has("public/.htaccess") ? read(zip, `${top}public/.htaccess`) : "";
  if (!/<FilesMatch "\^\\\.">[\s\S]*?Require all denied[\s\S]*?<\/FilesMatch>/.test(publicHtaccess)) problems.push("public/.htaccess serves files whose name starts with a dot");
  const rootHtaccess = files.has(".htaccess") ? read(zip, `${top}.htaccess`) : "";
  if (!/RewriteRule \^\(\.\*\)\$ public\/\$1/.test(rootHtaccess)) problems.push("the root .htaccess does not send every request into public/");
  const topDirs = [...dirs].filter((d) => !d.includes("/") && d !== "public");
  for (const d of topDirs) {
    if (!files.has(`${d}/.htaccess`) || !/Require all denied/.test(read(zip, `${top}${d}/.htaccess`))) problems.push(`${d}/ has no deny-all .htaccess`);
  }

  return problems;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const zip = process.argv[2];
  if (!zip) {
    console.error("usage: node scripts/check-release.mjs <zip>");
    process.exit(2);
  }
  const problems = checkRelease(zip);
  if (problems.length > 0) {
    console.error(problems.join("\n"));
    process.exit(1);
  }
  console.log(`${basename(zip)}: a good release`);
}
