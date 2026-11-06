/**
 * Writes the resource manifest (resources.yaml) as JSON for the stacks that have no YAML reader: the Laravel engine
 * reads laravel/resources/kit/manifest.json. Run `pnpm --filter contract manifest` after changing resources.yaml;
 * tests/manifest.test.ts fails while the copy is out of date.
 */
import { readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { parse } from "yaml";

const root = join(import.meta.dirname, "..");
export const manifestJsonPath = join(root, "..", "laravel", "resources", "kit", "manifest.json");
export const manifestJson = (): string => `${JSON.stringify(parse(readFileSync(join(root, "resources.yaml"), "utf8")), null, 2)}\n`;

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
  writeFileSync(manifestJsonPath, manifestJson());
  console.log(`wrote ${manifestJsonPath}`);
}
