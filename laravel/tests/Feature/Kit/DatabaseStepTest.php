<?php

namespace Tests\Feature\Kit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The wizard's database step on a real database: it connects, makes the tables, and only then writes the
 * connection to the environment file; a connection that fails changes nothing. Every file it makes is in the
 * test's scratch folder (Tests\TestCase), never this checkout's `database/` or `.env`.
 */
class DatabaseStepTest extends TestCase
{
    public function test_an_sqlite_file_is_made_migrated_and_saved(): void
    {
        file_put_contents(config('kit.setup.env_file'), "APP_NAME=Kit\nDB_CONNECTION=old\n");

        $state = $this->postJson('/api/kit/setup/steps/database', ['connection' => 'sqlite', 'database' => 'kit.sqlite'])->assertOk()->json();

        $file = $this->scratch.'/kit.sqlite';
        $this->assertFileExists($file);
        $this->assertSame("APP_NAME=Kit\nDB_CONNECTION=sqlite\nDB_DATABASE={$file}\n", file_get_contents(config('kit.setup.env_file')));
        $this->assertSame($file, DB::connection()->getDatabaseName());
        $this->assertTrue(Schema::hasTable('settings'));
        $this->assertTrue(Schema::hasColumn('users', 'role'));
        $this->assertSame('done', collect($state['steps'])->firstWhere('id', 'database')['status']);
        $this->assertSame('kit.sqlite', collect(collect($state['steps'])->firstWhere('id', 'database')['fields'])->firstWhere('name', 'database')['value']);
    }

    public function test_a_database_server_is_connected_migrated_and_saved(): void
    {
        $name = config('database.default');
        $server = config("database.connections.{$name}");
        if ($server['driver'] === 'sqlite' || Schema::hasTable('settings')) {
            $this->markTestSkipped('Needs a database server with no tables: run this test first on an empty one (docs/BUILD_NOTES.md: "On other databases").');
        }

        $state = $this->postJson('/api/kit/setup/steps/database', ['connection' => $name] + array_intersect_key($server, array_flip(config('kit.setup.database_fields'))))->assertOk()->json();

        $saved = file_get_contents(config('kit.setup.env_file'));
        foreach (['DB_CONNECTION' => $name, 'DB_HOST' => $server['host'], 'DB_DATABASE' => $server['database'], 'DB_USERNAME' => $server['username']] as $key => $value) {
            $this->assertStringContainsString("{$key}={$value}\n", $saved);
        }
        $this->assertTrue(Schema::hasTable('settings'));
        $this->assertTrue(Schema::hasTable('catalog_voices'));
        $this->assertSame('done', collect($state['steps'])->firstWhere('id', 'database')['status']);
    }

    public function test_an_sqlite_file_outside_its_folder_is_refused(): void
    {
        // A name of this run's own, so a file left by an earlier failing run cannot decide this one.
        $escape = 'escape-'.bin2hex(random_bytes(4)).'.sqlite';
        try {
            foreach (["../{$escape}", "/etc/{$escape}", '.hidden'] as $name) {
                $this->postJson('/api/kit/setup/steps/database', ['connection' => 'sqlite', 'database' => $name])
                    ->assertStatus(422)->assertJsonValidationErrors(['database']);
            }
            $this->assertFileDoesNotExist(dirname($this->scratch)."/{$escape}");
            $this->assertFileDoesNotExist(config('kit.setup.env_file'));
        } finally {
            @unlink(dirname($this->scratch)."/{$escape}");
        }
    }

    public function test_a_connection_that_fails_changes_nothing(): void
    {
        $before = config('database.default');

        $this->postJson('/api/kit/setup/steps/database', [
            'connection' => 'pgsql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'kit', 'username' => 'kit', 'password' => 'x',
        ])->assertStatus(422)->assertJsonPath('errors.connection.0', fn (string $m) => str_starts_with($m, 'Could not connect: '));

        $this->assertFileDoesNotExist(config('kit.setup.env_file'));
        $this->assertSame($before, config('database.default'));
    }

    public function test_only_the_connections_this_php_can_use_are_offered_and_taken(): void
    {
        $field = collect(collect($this->getJson('/api/kit/setup')->json('steps'))->firstWhere('id', 'database')['fields'])->firstWhere('name', 'connection');
        $offered = array_column($field['options'], 'value');

        $this->assertContains('sqlite', $offered);
        $aliases = config('kit.setup.pdo_drivers');
        foreach (config('database.connections') as $name => $c) {
            $usable = in_array($aliases[$c['driver']] ?? $c['driver'], \PDO::getAvailableDrivers(), true);
            $this->assertSame($usable, in_array($name, $offered, true), "{$name}: offered exactly when this PHP has its driver");
        }
        $this->postJson('/api/kit/setup/steps/database', ['connection' => 'nosuch', 'database' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['connection']);
    }

    public function test_the_saved_database_password_never_leaves_the_server(): void
    {
        config(['database.default' => 'pgsql', 'database.connections.pgsql.password' => 'db-secret-value', 'database.connections.pgsql.port' => 1]);

        $response = $this->getJson('/api/kit/setup')->assertOk();

        $this->assertStringNotContainsString('db-secret-value', $response->getContent());
        $password = collect(collect($response->json('steps'))->firstWhere('id', 'database')['fields'])->firstWhere('name', 'password');
        $this->assertNull($password['value']);
        $this->assertTrue($password['secretSet']);
    }

    public function test_a_network_connection_asks_for_its_host_and_user(): void
    {
        $this->postJson('/api/kit/setup/steps/database', ['connection' => 'pgsql', 'database' => 'kit'])
            ->assertStatus(422)->assertJsonValidationErrors(['host', 'username']);
    }
}
