<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Setup\Check;
use App\Kit\Setup\EnvironmentFile;
use App\Kit\Setup\SetupStep;
use Illuminate\Http\Request;

/**
 * Wizard step 1: can this server run the kit? (SELF_HOSTED_KIT_PLAN.md §4.1.1.) It only reports:
 * - the PHP extensions the installed packages declare (vendor/composer/installed.json, dev packages left out) —
 *   read from the release itself, never a list written here; a missing one blocks;
 * - the folders and the environment file the kit writes; one it cannot write blocks;
 * - HTTPS (a warning: Telenow sends events only to https addresses);
 * - the upload limit PHP sets;
 * - the site's folder: public/ (the safest), or the platform's own folder (shared hosting's public_html), whose
 *   .htaccess (scripts/release.mjs) sends every request into public/ — private only where the web server reads
 *   .htaccess files (config('kit.setup.htaccess_servers')); elsewhere that blocks, as the platform's own files could
 *   be read. Which it is shows in the address public/index.php was reached by (SCRIPT_NAME), which a visitor cannot
 *   set.
 * The PHP version needs no check here: Composer's platform check stops the app from starting on a PHP its
 * packages cannot run on.
 */
final class EnvironmentStep extends SetupStep
{
    public function __construct(private readonly EnvironmentFile $env) {}

    public function id(): string
    {
        return 'environment';
    }

    public function access(): string
    {
        return self::ACCESS_ANYONE;
    }

    public function submittable(): bool
    {
        return false;
    }

    public function done(): bool
    {
        return true;
    }

    public function checks(Request $request, bool $mayRead): array
    {
        $checks = [new Check('php', $this->text('php', ['version' => PHP_VERSION]), Check::OK)];

        $missing = array_values(array_filter($this->requiredExtensions(), fn (string $ext) => ! extension_loaded($ext)));
        $checks[] = $missing
            ? new Check('extensions', $this->text('extensions_missing'), Check::BLOCKING, implode(', ', $missing))
            : new Check('extensions', $this->text('extensions_ok'), Check::OK);

        $paths = [storage_path(), base_path('bootstrap/cache')];
        foreach ($paths as $path) {
            $shown = ltrim(str_replace(base_path(), '', $path), '/').'/';
            $checks[] = is_dir($path) && is_writable($path)
                ? new Check("writable:{$shown}", $this->text('writable', ['path' => $shown]), Check::OK)
                : new Check("writable:{$shown}", $this->text('not_writable', ['path' => $shown]), Check::BLOCKING);
        }
        $envShown = basename($this->env->path());
        $checks[] = $this->env->writable()
            ? new Check('writable:env', $this->text('writable', ['path' => $envShown]), Check::OK)
            : new Check('writable:env', $this->text('not_writable', ['path' => $envShown]), Check::BLOCKING, $mayRead ? $this->env->path() : null);

        $checks[] = $request->isSecure()
            ? new Check('https', $this->text('https'), Check::OK)
            : new Check('https', $this->text('no_https'), Check::WARNING, $this->text('no_https_detail'));

        // 0 means no limit, for either setting.
        $limits = array_filter([$this->bytes((string) ini_get('upload_max_filesize')), $this->bytes((string) ini_get('post_max_size'))]);
        $checks[] = $limits
            ? new Check('uploads', $this->text('uploads', ['size' => $this->human(min($limits))]), Check::OK)
            : new Check('uploads', $this->text('uploads_unlimited'), Check::OK);

        $checks = array_merge($checks, $this->siteFolder($request));

        return $checks;
    }

    /** @return list<Check> none when the kit is served some other way (a development server, a test) */
    private function siteFolder(Request $request): array
    {
        $script = realpath((string) $request->server('SCRIPT_FILENAME'));
        if ($script === false || $script !== realpath(public_path('index.php'))) {
            return [];
        }
        if (! str_ends_with(dirname((string) $request->server('SCRIPT_NAME')), '/'.basename(public_path()))) {
            return [new Check('site_folder', $this->text('site_folder_public'), Check::OK)];
        }
        $software = (string) $request->server('SERVER_SOFTWARE');
        $reads = collect(config('kit.setup.htaccess_servers'))->contains(fn (string $name) => stripos($software, $name) !== false);

        return [$reads
            ? new Check('site_folder', $this->text('site_folder_root'), Check::OK)
            : new Check('site_folder', $this->text('site_folder_root_unsafe'), Check::BLOCKING, $this->text('site_folder_root_unsafe_detail'))];
    }

    /** @return list<string> The extension names (without `ext-`) the installed, non-dev packages and the app require. */
    private function requiredExtensions(): array
    {
        $required = [];
        $installed = json_decode((string) @file_get_contents(base_path('vendor/composer/installed.json')), true) ?: [];
        $dev = array_flip($installed['dev-package-names'] ?? []);
        $requires = [json_decode((string) @file_get_contents(base_path('composer.json')), true)['require'] ?? []];
        foreach ($installed['packages'] ?? [] as $package) {
            if (! isset($dev[$package['name'] ?? ''])) {
                $requires[] = $package['require'] ?? [];
            }
        }
        foreach ($requires as $list) {
            foreach (array_keys($list) as $name) {
                if (str_starts_with($name, 'ext-')) {
                    $required[strtolower(substr($name, 4))] = true;
                }
            }
        }
        ksort($required);

        return array_keys($required);
    }

    /** PHP's shorthand size (`2M`) in bytes. */
    private function bytes(string $size): int
    {
        $n = (int) $size;
        $unit = strtolower(substr(trim($size), -1));
        $power = ['k' => 1, 'm' => 2, 'g' => 3][$unit] ?? 0;

        return $n * (1024 ** $power);
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1 && $bytes % 1024 === 0) {
            $bytes = intdiv($bytes, 1024);
            $i++;
        }

        return $bytes.' '.$units[$i];
    }
}
