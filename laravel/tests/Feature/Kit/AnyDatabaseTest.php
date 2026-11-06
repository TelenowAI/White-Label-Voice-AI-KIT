<?php

namespace Tests\Feature\Kit;

use App\Models\DncEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * What must hold on every database the kit runs on. The suite runs on SQLite, which enforces no column's length, keeps JSON
 * as it was written, takes any text as an id, and has no clock of its own to disagree with the kit's, so most of these only
 * bite elsewhere: with the host's time zone on a shared server, with a long message from elsewhere, with the keys of a stored
 * object, with a bad id in a request. Run them there (docs/BUILD_NOTES.md: "On other databases"), on a server in a time zone with a clock
 * change and on one ahead of UTC.
 */
class AnyDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    /** SpendLimit reads a path inside it, which only a json column allows on PostgreSQL. No screen shows its keys. */
    private const QUERIED_INSIDE = 'wallet_entries.details';

    public function test_a_row_written_in_the_hour_a_clock_change_skips_is_kept(): void
    {
        Carbon::setTestNow('2027-03-14 02:30:00'); // no such time in New York: the clocks go from 02:00 to 03:00
        $workspace = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->assertSame('2027-03-14 02:30:00', Workspace::query()->findOrFail($workspace->id)->created_at->format('Y-m-d H:i:s'));
    }

    public function test_the_databases_own_clock_is_the_kits(): void
    {
        DB::table('telenow_events')->insert(['delivery_id' => (string) Str::uuid(), 'event' => 'call.completed', 'body' => '{}']);
        $byDatabase = Carbon::parse(DB::table('telenow_events')->value('next_attempt_at'), 'UTC');
        $this->assertLessThan(config('kit.outbox.not_yet_seconds'), abs($byDatabase->diffInSeconds(now())), "a default of the database's clock is due now, whatever the host's time zone");
    }

    public function test_no_column_is_one_a_database_rewrites(): void
    {
        $rewritten = [];
        foreach (array_column(Schema::getTables(), 'name') as $table) {
            foreach (Schema::getColumns($table) as $column) {
                if ($column['type_name'] === 'json' && "{$table}.{$column['name']}" !== self::QUERIED_INSIDE) {
                    $rewritten[] = "{$table}.{$column['name']}";
                }
            }
        }
        $this->assertSame([], $rewritten, "MySQL's json type reorders an object's keys, and screens show them in the order they were written");
    }

    public function test_no_column_refuses_text_that_is_not_a_uuid(): void
    {
        $native = [];
        foreach (array_column(Schema::getTables(), 'name') as $table) {
            foreach (Schema::getColumns($table) as $column) {
                if ($column['type_name'] === 'uuid') {
                    $native[] = "{$table}.{$column['name']}";
                }
            }
        }
        $this->assertSame([], $native, 'PostgreSQL answers an error, not "no such row", for an id that is not a UUID');
    }

    public function test_a_malformed_id_on_any_route_is_never_a_server_error(): void
    {
        touch(config('kit.installed_marker'));
        RecordedTelenow::fake();
        $owner = User::create(['name' => 'Olu', 'email' => 'olu@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $acme->id, 'user_id' => $ana->id, 'role' => 'admin']);

        $tried = 0;
        $broken = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/kit/') || ! str_contains($route->uri(), '{')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                foreach (['not-an-id', str_repeat('9', 30)] as $bad) {
                    foreach ([$owner, $ana] as $who) {
                        $uri = '/'.preg_replace('/\{[^}]+\}/', $bad, $route->uri());
                        DB::beginTransaction(); // a savepoint: on PostgreSQL a failed statement stops everything after it until rolled back
                        $status = $this->actingAs($who)->json($method, $uri, [])->status();
                        DB::rollBack();
                        $tried++;
                        if ($status === 500) {
                            $broken[] = "{$method} {$uri} as {$who->name}";
                        }
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $tried);
        $this->assertSame([], $broken, 'an id that names nothing is "not found" or a refusal');
    }

    public function test_a_search_finds_words_in_any_case(): void
    {
        touch(config('kit.installed_marker'));
        RecordedTelenow::fake();
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $acme->id, 'user_id' => $ana->id, 'role' => 'member']);
        (new DncEntry)->forceFill(['workspace_id' => $acme->id, 'phone_number' => '+14155550123', 'reason' => 'Complained Twice', 'source' => 'kit', 'sync_state' => 'synced'])->save();
        $found = $this->actingAs($ana)->getJson('/api/kit/console/dnc?search=complained')->assertOk()->json('entries');
        $this->assertCount(1, $found, 'PostgreSQL\'s like minds the case; the others do not');
    }

    public function test_every_column_for_an_error_holds_a_long_message(): void
    {
        $short = [];
        foreach (array_column(Schema::getTables(), 'name') as $table) {
            foreach (Schema::getColumns($table) as $column) {
                if (str_ends_with($column['name'], 'error') && ! str_contains($column['type_name'], 'text')) {
                    $short[] = "{$table}.{$column['name']}";
                }
            }
        }
        $this->assertSame([], $short, 'a message comes from elsewhere, at any length');
    }

    public function test_a_long_refusal_from_telenow_is_kept_whole_and_stops_nothing(): void
    {
        touch(config('kit.installed_marker'));
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $acme->id, 'user_id' => $ana->id, 'role' => 'member']);
        $said = str_repeat('Telenow said no at some length. ', 40);
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $r->method() === 'POST' && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/dnc') ? Http::response(['error' => $said], 403) : $recorded($r));

        $this->actingAs($ana)->postJson('/api/kit/console/dnc', ['phoneNumber' => '+1 415 555 0123'])->assertCreated();
        $entry = DncEntry::query()->sole();
        $this->assertSame(['failed', $said], [$entry->sync_state, $entry->sync_error]);
    }

    public function test_what_a_gateway_sends_can_be_larger_than_a_text_column(): void
    {
        $large = str_repeat('x', 70_000); // MySQL's text column stops at 65,535 bytes
        $gateway = (string) Str::uuid();
        DB::table('payment_gateways')->insert(['id' => $gateway, 'driver' => 'manual', 'name' => 'Bank', 'mode' => 'test', 'config' => '', 'methods' => '[]', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payment_events')->insert(['gateway_id' => $gateway, 'kind' => 'x', 'payload' => $large, 'created_at' => now()]);
        $this->assertSame(strlen($large), strlen((string) DB::table('payment_events')->value('payload')));
    }
}
