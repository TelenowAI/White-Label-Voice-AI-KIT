#!/usr/bin/env node
/**
 * Builds the Laravel stack's release zip (SELF_HOSTED_KIT_PLAN.md §3.3): what an operator uploads to shared hosting
 * and installs from the browser, with no shell or Composer on the server.
 *
 * 1. The console, type-checked and built for `/<build dir>/` (config('kit.console.build_dir'), read from
 *    laravel/config/kit.php) into the staged app's public/ folder — the backend answers the console's pages with it.
 * 2. The Laravel app, from a list of what a release holds (deny by default): never `.env`, a database file, logs,
 *    sessions, the install lock, tests or the stock front-end tooling; storage/ and bootstrap/cache/ are only their
 *    folders' .gitignore files.
 * 3. `composer install --no-dev` into the staged copy (set COMPOSER_DISABLE_NETWORK=1 to build from Composer's cache).
 * 4. For a site whose folder is the app's own (shared hosting's public_html): a root .htaccess that sends every request
 *    into public/, and a deny-all .htaccess in every other top-level folder.
 * 5. dist/voice-kit-<VERSION>.zip with its sha256, then scripts/check-release.mjs on the zip.
 *
 * Usage: node scripts/release.mjs [--out <dir>] (default dist/). Not here: what makes a release good
 * (check-release.mjs), installing it (the setup wizard).
 */
import { execFileSync } from "node:child_process";
import { createHash } from "node:crypto";
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from "node:fs";
import { basename, dirname, join, relative, resolve, sep } from "node:path";
import { fileURLToPath } from "node:url";
import { checkRelease } from "./check-release.mjs";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const laravel = join(root, "laravel");
const console_ = join(root, "console");

/** What a release holds of laravel/, top level: the folders and files the app runs from. */
const RELEASE_DIRS = ["app", "bootstrap", "config", "database", "lang", "public", "resources", "routes", "storage", "themes"];
const RELEASE_FILES = ["artisan", "composer.json", "composer.lock", "VERSION", "INSTALL.md"];

/** Folders never copied (relative to laravel/, `/` separated): the stock front-end tooling, builds, links. */
const NEVER_DIRS = [/(^|\/)node_modules$/, /^resources\/(css|js)$/, /^public\/(hot|storage|build)$/];
/** Files never copied, wherever they are. */
const NEVER_FILES = [/(^|\/)\.env($|\.)/, /(^|\/)\.DS_Store$/, /(^|\/)\._/, /(^|\/)\.phpunit/, /\.log$/, /\.sqlite($|-)/];
/** Folders a running install fills: a release has only their folders and .gitignore files. */
const SKELETONS = ["storage", "bootstrap/cache"];

const ROOT_HTACCESS = `# The site's folder is the platform's own folder (shared hosting's public_html): every request goes into public/,
# so the platform's own files are never served. Pointing the site at public/ instead is safer still.
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
<IfModule !mod_rewrite.c>
    Require all denied
</IfModule>
`;

const DENY_HTACCESS = `# The platform's own files: never served (the site's folder may be the platform's folder).
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Deny from all
</IfModule>
`;

function run(command, args, cwd, env = {}) {
  execFileSync(command, args, { cwd, stdio: "inherit", env: { ...process.env, ...env } });
}

function posix(path) {
  return path.split(sep).join("/");
}

/** The console's build folder, from laravel/config/kit.php (one place says it). */
function consoleBuildDir() {
  const config = readFileSync(join(laravel, "config/kit.php"), "utf8");
  const found = config.match(/'build_dir'\s*=>\s*'([a-z0-9][a-z0-9_-]*)'/);
  if (!found) throw new Error("laravel/config/kit.php: no kit.console.build_dir");
  return found[1];
}

function copyRelease(stage, buildDir) {
  const inSkeleton = (rel) => SKELETONS.some((k) => rel === k || rel.startsWith(`${k}/`));
  const neverDirs = [...NEVER_DIRS, new RegExp(`^public/${buildDir}$`)];
  for (const name of RELEASE_FILES) {
    if (existsSync(join(laravel, name))) cpSync(join(laravel, name), join(stage, name));
  }
  for (const dir of RELEASE_DIRS) {
    if (!existsSync(join(laravel, dir))) continue;
    cpSync(join(laravel, dir), join(stage, dir), {
      recursive: true,
      filter: (src) => {
        const rel = posix(relative(laravel, src));
        if (inSkeleton(rel)) return false;
        return statSync(src).isDirectory() ? !neverDirs.some((r) => r.test(rel)) : !NEVER_FILES.some((r) => r.test(rel));
      },
    });
  }
  for (const skeleton of SKELETONS) {
    mkdirSync(join(stage, skeleton), { recursive: true });
    for (const rel of walk(join(laravel, skeleton))) {
      if (basename(rel) !== ".gitignore") continue;
      mkdirSync(dirname(join(stage, skeleton, rel)), { recursive: true });
      cpSync(join(laravel, skeleton, rel), join(stage, skeleton, rel));
    }
  }
}

/** Every file under `dir`, relative to it. */
function walk(dir, prefix = "") {
  if (!existsSync(dir)) return [];
  return readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? walk(join(dir, e.name), `${prefix}${e.name}/`) : [`${prefix}${e.name}`]);
}

function sha256(file) {
  return createHash("sha256").update(readFileSync(file)).digest("hex");
}

function main() {
  const outArg = process.argv.indexOf("--out");
  const out = resolve(outArg > 0 ? process.argv[outArg + 1] : join(root, "dist"));
  const version = readFileSync(join(laravel, "VERSION"), "utf8").trim();
  const name = `voice-kit-${version}`;
  const stageRoot = join(out, "stage");
  const stage = join(stageRoot, name);
  const zip = join(out, `${name}.zip`);
  rmSync(stageRoot, { recursive: true, force: true });
  rmSync(zip, { force: true });
  mkdirSync(stage, { recursive: true });

  const buildDir = consoleBuildDir();
  console.log(`release ${name}: copying the app`);
  copyRelease(stage, buildDir);

  console.log(`release ${name}: building the console into public/${buildDir}/`);
  run(join(console_, "node_modules/.bin/tsc"), ["-b", "--force"], console_);
  run(join(console_, "node_modules/.bin/vite"), ["build", `--base=/${buildDir}/`, "--outDir", join(stage, "public", buildDir), "--emptyOutDir"], console_);

  console.log(`release ${name}: composer install --no-dev`);
  run("composer", ["install", "--no-dev", "--optimize-autoloader", "--no-scripts", "--no-interaction", "--prefer-dist", "--no-progress"], stage);

  writeFileSync(join(stage, ".htaccess"), ROOT_HTACCESS);
  for (const entry of readdirSync(stage, { withFileTypes: true })) {
    if (entry.isDirectory() && entry.name !== "public") writeFileSync(join(stage, entry.name, ".htaccess"), DENY_HTACCESS);
  }

  console.log(`release ${name}: zipping`);
  run("zip", ["-q", "-r", "-X", zip, name], stageRoot);
  const digest = sha256(zip);
  writeFileSync(`${zip}.sha256`, `${digest}  ${basename(zip)}\n`);

  const problems = checkRelease(zip);
  if (problems.length > 0) {
    console.error(`release ${name}: NOT a good release:\n- ${problems.join("\n- ")}`);
    process.exit(1);
  }
  const mb = (statSync(zip).size / 1024 / 1024).toFixed(1);
  console.log(`release ${name}: ${zip} (${mb} MB)\nsha256 ${digest}`);
}

main();
