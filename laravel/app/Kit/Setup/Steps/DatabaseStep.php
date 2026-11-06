<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Settings\Settings;
use App\Kit\Setup\Check;
use App\Kit\Setup\EnvironmentFile;
use App\Kit\Setup\Field;
use App\Kit\Setup\Installation;
use App\Kit\Setup\SetupStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Wizard step 2: the database (SELF_HOSTED_KIT_PLAN.md §4.1.2). The operator picks a connection and its settings;
 * the step connects with them, creates the tables (the migrations), and only then writes them to the environment
 * file, so a mistyped password never replaces a working one. The same step on shared hosting and a VPS: where the
 * environment already names a working database, its settings are filled in and the operator just confirms.
 *
 * Nothing here is a list of databases: the choices are config/database.php's connections whose PDO driver this PHP
 * has, and each asks only for the settings that connection has (config('kit.setup.database_fields')). It runs
 * before anyone has signed in, so an SQLite file can only be made inside config('kit.setup.sqlite_dir').
 *
 * Not here: the session and cache stores' fallback before the tables exist (PrepareInstallation).
 */
final class DatabaseStep extends SetupStep
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EnvironmentFile $env,
        private readonly Installation $installation,
    ) {}

    public function id(): string
    {
        return 'database';
    }

    public function access(): string
    {
        return self::ACCESS_ANYONE;
    }

    public function done(): bool
    {
        return $this->settings->has('setup.database.done_at');
    }

    public function fields(Request $request, bool $mayRead): array
    {
        $current = config('database.default');
        $currentConfig = config("database.connections.{$current}", []);
        $connections = $this->connections();
        $fields = [new Field(
            'connection', $this->text('connection'), 'select', true,
            $mayRead && isset($connections[$current]) ? $current : (array_key_first($connections) ?? null),
            null,
            array_map(fn (string $name) => ['value' => $name, 'label' => $name], array_keys($connections)),
        )];
        foreach (config('kit.setup.database_fields') as $name) {
            $having = array_keys(array_filter($connections, fn (array $c) => array_key_exists($name, $c)));
            if (! $having) {
                continue;
            }
            $value = $mayRead && array_key_exists($name, $currentConfig) ? $this->shown($current, $name, $currentConfig[$name]) : null;
            $fields[] = new Field(
                $name,
                $this->text("fields.{$name}"),
                $name === 'password' ? 'secret' : 'text',
                in_array($name, ['host', 'database', 'username'], true),
                $value,
                Lang::has("setup.steps.database.help.{$name}") ? $this->text("help.{$name}") : null,
                [],
                ['field' => 'connection', 'values' => $having],
                $name === 'password' && $mayRead && ($currentConfig['password'] ?? '') !== '',
            );
        }

        return $fields;
    }

    public function checks(Request $request, bool $mayRead): array
    {
        if (! $mayRead) {
            return [];
        }
        try {
            DB::connection()->getPdo();

            return [new Check('connection', $this->text('connected', ['connection' => config('database.default')]), Check::OK)];
        } catch (Throwable $e) {
            return [new Check('connection', $this->text('not_connected'), Check::WARNING, $e->getMessage())];
        }
    }

    public function submit(Request $request, array $input): void
    {
        $connections = $this->connections();
        $name = $input['connection'] ?? null;
        $chosen = is_string($name) ? ($connections[$name] ?? null) : null;
        $rules = ['connection' => ['required', 'string', Rule::in(array_keys($connections))]];
        $noControl = 'not_regex:/[\x00-\x1F\x7F]/';
        foreach (config('kit.setup.database_fields') as $field) {
            if ($chosen === null || ! array_key_exists($field, $chosen)) {
                continue;
            }
            $rules[$field] = match ($field) {
                'port' => ['nullable', 'integer', 'between:1,65535'],
                'password' => ['nullable', 'string', 'max:500', $noControl],
                'database' => $chosen['driver'] === 'sqlite'
                    ? ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-][A-Za-z0-9._-]*$/']
                    : ['required', 'string', 'max:200', $noControl],
                default => [in_array($field, ['host', 'username'], true) ? 'required' : 'nullable', 'string', 'max:255', $noControl],
            };
        }
        $values = Validator::make($input, $rules, [], $this->attributes())->validate();

        $config = $chosen;
        $config['url'] = null;
        $saved = [];
        foreach (config('kit.setup.database_fields') as $field) {
            if (! array_key_exists($field, $chosen)) {
                continue;
            }
            $value = isset($values[$field]) ? (string) $values[$field] : '';
            if ($field === 'password' && $value === '' && $name === config('database.default')) {
                $value = (string) ($chosen['password'] ?? ''); // left empty: keep the saved one
            }
            if ($field === 'database' && $chosen['driver'] === 'sqlite') {
                $value = $this->sqliteFile($value);
            }
            $config[$field] = $value;
            $saved['DB_'.strtoupper($field)] = $value;
        }

        $before = [config('database.default'), config("database.connections.{$name}")];
        config(["database.connections.{$name}" => $config, 'database.default' => $name]);
        DB::purge($name);
        $this->installation->forgetTables();
        try {
            DB::connection($name)->getPdo();
        } catch (Throwable $e) {
            config(['database.default' => $before[0], "database.connections.{$name}" => $before[1]]);
            DB::purge($name);
            throw ValidationException::withMessages(['connection' => $this->text('connect_failed', ['error' => $e->getMessage()])]);
        }
        if (Artisan::call('migrate', ['--database' => $name, '--force' => true]) !== 0) {
            throw ValidationException::withMessages(['connection' => $this->text('migrate_failed', ['error' => trim(Artisan::output())])]);
        }
        try {
            // A DB_URL would override every setting written here: clear it when the environment has one.
            $this->env->set(['DB_CONNECTION' => $name] + $saved + (empty($chosen['url']) ? [] : ['DB_URL' => '']));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['connection' => $this->text('env_failed', ['error' => $e->getMessage()])]);
        }
        $this->settings->put('setup.database.done_at', now()->toIso8601String());
    }

    /** @return array<string, array> config/database.php's connections this PHP has a driver for, by name. */
    private function connections(): array
    {
        $pdo = PDO::getAvailableDrivers();
        $aliases = config('kit.setup.pdo_drivers');

        return array_filter(
            config('database.connections'),
            fn (array $c) => in_array($aliases[$c['driver']] ?? $c['driver'], $pdo, true),
        );
    }

    /** An SQLite database is a file name inside the allowed folder; the file is made if it is not there. */
    private function sqliteFile(string $name): string
    {
        $dir = rtrim(config('kit.setup.sqlite_dir'), '/');
        $path = $dir.'/'.$name;
        if (! is_file($path) && @touch($path) === false) {
            throw ValidationException::withMessages(['database' => $this->text('sqlite_failed', ['path' => $path])]);
        }

        return $path;
    }

    /** A saved value as the form shows it: an SQLite file inside the allowed folder by its name alone. */
    private function shown(string $connection, string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        $dir = rtrim(config('kit.setup.sqlite_dir'), '/').'/';
        if ($field === 'database' && config("database.connections.{$connection}.driver") === 'sqlite' && str_starts_with($value, $dir)) {
            return substr($value, strlen($dir));
        }

        return $value;
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        $names = ['connection' => $this->text('connection')];
        foreach (config('kit.setup.database_fields') as $field) {
            $names[$field] = $this->text("fields.{$field}");
        }

        return $names;
    }
}
