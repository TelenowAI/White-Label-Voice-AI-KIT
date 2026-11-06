<?php

namespace App\Kit\Setup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Whether this install is finished, and what it can rely on before it is: the lock the setup wizard writes when it
 * finishes (the `kit.installed_marker` file; SELF_HOSTED_KIT_PLAN.md §3.6 "the installer locks itself"), and
 * whether a database table can be used yet.
 *
 * Not here: the wizard's steps (Steps/*), the `setup.finished_at` setting the finish step also records.
 * Called by: the health route, the wizard and its controller, Settings (to fail soft before the install),
 * PrepareInstallation.
 */
final class Installation
{
    /** @var array<string, bool> */
    private array $tables = [];

    public function installed(): bool
    {
        return is_file(config('kit.installed_marker'));
    }

    /** Write the lock: from now on the wizard's routes answer 404. */
    public function lock(): void
    {
        $marker = config('kit.installed_marker');
        $dir = dirname($marker);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        $tmp = tempnam($dir, '.installed-');
        file_put_contents($tmp, json_encode(['installedAt' => now()->toIso8601String(), 'version' => config('kit.version')])."\n");
        rename($tmp, $marker);
    }

    /** The default connection answers and has `$table`. False before the database is set up — never an error. */
    public function tableUsable(string $table): bool
    {
        return $this->tables[$table] ??= (function () use ($table) {
            try {
                DB::connection()->getPdo();

                return Schema::hasTable($table);
            } catch (Throwable) {
                return false;
            }
        })();
    }

    /** Forget what tableUsable() learned (the database step has just changed the connection). */
    public function forgetTables(): void
    {
        $this->tables = [];
    }
}
