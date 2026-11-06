<?php

namespace Tests\Feature\Kit;

use App\Kit\Outbox\Outbox;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Admin → Customers against recorded Telenow answers (contract/telenow-mock/cassettes): a customer is a Telenow
 * sub-account made through the outbox (SELF_HOSTED_KIT_PLAN.md §3.8 a, §5 C2, C6) — sent at once, retried with the
 * same Idempotency-Key, recovered when the answer was lost, given up with Telenow's reason; one row's writes in order,
 * never two runners on one write; the customer's key kept encrypted and never answered.
 */
class CustomersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        RecordedTelenow::fake();
    }

    /** Replace the recorded Telenow with answers of this test's own (each a recorded body unless it says otherwise). */
    private function telenow(\Closure $answer): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($answer);
    }

    private function create(string $name = 'Acme Dental'): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/kit/admin/customers', ['name' => $name]);
    }

    private function sentTo(string $method, string $path): array
    {
        return array_values(array_filter(
            Http::recorded()->all(),
            fn (array $pair) => $pair[0]->method() === $method && parse_url($pair[0]->url(), PHP_URL_PATH) === $path,
        ));
    }

    public function test_a_new_customer_is_a_telenow_sub_account_whose_key_is_kept_encrypted_and_never_answered(): void
    {
        $made = RecordedTelenow::body('createSubAccount');

        $response = $this->create()->assertCreated()
            ->assertJsonPath('syncState', 'synced')->assertJsonPath('status', 'active')
            ->assertJsonPath('telenowSubAccountId', $made['subAccount']['id']);

        $id = $response->json('id');
        [$request] = $this->sentTo('POST', '/api/v1/sub-accounts')[0];
        $this->assertSame([$id], $request->header('Idempotency-Key'));
        $this->assertSame(['name' => 'Acme Dental', 'externalId' => $id, 'keyName' => config('kit.customers.key_name'), 'keyRole' => 'admin'], $request->data());
        $raw = DB::table('workspaces')->where('id', $id)->value('telenow_key');
        $this->assertStringNotContainsString($made['apiKey']['key'], $raw);
        $this->assertSame($made['apiKey']['key'], Workspace::find($id)->telenow_key);
        $this->assertStringNotContainsString($made['apiKey']['key'], $response->getContent());
        $this->assertStringNotContainsString($made['apiKey']['key'], $this->actingAs($this->owner)->getJson('/api/kit/admin/customers')->getContent());
        $this->assertStringNotContainsString($made['apiKey']['key'], $this->actingAs($this->owner)->getJson("/api/kit/admin/customers/{$id}")->getContent());
    }

    public function test_telenow_unreachable_leaves_it_pending_and_the_outbox_finishes_it_with_the_same_idempotency_key(): void
    {
        $tries = 0;
        $this->telenow(function (Request $r) use (&$tries) {
            return ++$tries === 1 ? Http::failedConnection('Connection refused') : Http::response(RecordedTelenow::body('createSubAccount'), 201);
        });

        $id = $this->create()->assertCreated()->assertJsonPath('syncState', 'pending')
            ->assertJsonPath('syncError', fn (string $e) => str_contains($e, 'Could not reach'))->json('id');
        $entry = OutboxEntry::query()->firstWhere('kit_id', $id);
        $this->assertSame(1, $entry->attempts);
        $this->assertTrue($entry->next_attempt_at->isFuture());

        $this->artisan('kit:outbox')->assertSuccessful(); // not due yet
        $this->assertSame(1, $tries);
        $this->travel(config('kit.outbox.retry_base_seconds') + 1)->seconds();
        $this->artisan('kit:outbox')->expectsOutput('1 write(s) done')->assertSuccessful();

        $this->assertSame(Workspace::SYNC_SYNCED, Workspace::find($id)->sync_state);
        $keys = array_map(fn (array $p) => $p[0]->header('Idempotency-Key'), Http::recorded()->all());
        $this->assertSame([[$id], [$id]], $keys);
    }

    public function test_a_create_whose_answer_was_lost_too_long_ago_finds_its_sub_account_and_mints_a_new_key(): void
    {
        $this->telenow(fn (Request $r) => match ([$r->method(), parse_url($r->url(), PHP_URL_PATH)]) {
            ['POST', '/api/v1/sub-accounts'] => Http::response(RecordedTelenow::body('createSubAccount.external-id-taken'), 409),
            ['GET', '/api/v1/sub-accounts'] => Http::response(RecordedTelenow::body('listSubAccounts.by-external-id')),
            default => Http::response(RecordedTelenow::body('rotateSubAccountKey'), 201),
        });
        $found = RecordedTelenow::body('listSubAccounts.by-external-id')['subAccounts'][0];
        $rotated = RecordedTelenow::body('rotateSubAccountKey')['apiKey'];

        $id = $this->create()->assertCreated()->assertJsonPath('syncState', 'synced')
            ->assertJsonPath('telenowSubAccountId', $found['id'])->json('id');

        $this->assertSame($rotated['key'], Workspace::find($id)->telenow_key);
        [$list] = $this->sentTo('GET', '/api/v1/sub-accounts')[0];
        $this->assertSame($id, $list->data()['externalId'] ?? null);
        $this->assertCount(1, $this->sentTo('POST', "/api/v1/sub-accounts/{$found['id']}/keys"));
    }

    public function test_a_closed_sub_account_does_not_count_when_recovering(): void
    {
        // The recorded list, with its one sub-account closed (written for this case).
        $list = RecordedTelenow::body('listSubAccounts.by-external-id');
        $list['subAccounts'][0]['status'] = 'closed';
        $this->telenow(fn (Request $r) => $r->method() === 'POST'
            ? Http::response(RecordedTelenow::body('createSubAccount.external-id-taken'), 409)
            : Http::response($list));

        $this->create()->assertCreated()->assertJsonPath('syncState', 'failed')
            ->assertJsonPath('syncError', RecordedTelenow::body('createSubAccount.external-id-taken')['error']);
        $this->assertSame([], $this->sentTo('POST', "/api/v1/sub-accounts/{$list['subAccounts'][0]['id']}/keys"));
    }

    public function test_a_key_that_may_not_make_sub_accounts_is_given_up_at_once_with_telenows_reason_then_retried(): void
    {
        app(Settings::class)->put('telenow.api_key', 'vai_test_developer', secret: true);

        $id = $this->create()->assertCreated()->assertJsonPath('syncState', 'failed')
            ->assertJsonPath('syncError', RecordedTelenow::body('createSubAccount.developer')['error'])->json('id');
        $entry = OutboxEntry::query()->firstWhere('kit_id', $id);
        $this->assertSame(1, $entry->attempts);
        $this->assertNotNull($entry->failed_at);

        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->expectsOutput('0 write(s) done');
        app(Settings::class)->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$id}/retry")->assertOk()->assertJsonPath('syncState', 'synced');
        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$id}/retry")->assertStatus(409);
    }

    public function test_a_write_telenow_keeps_refusing_is_given_up_after_the_last_try(): void
    {
        config(['kit.outbox.max_attempts' => 2]);
        $this->telenow(fn () => Http::response(['success' => false, 'error' => 'upstream down'], 503)); // written for this case

        $id = $this->create()->assertJsonPath('syncState', 'pending')->json('id');
        $this->travel(config('kit.outbox.retry_base_seconds') + 1)->seconds();
        $this->artisan('kit:outbox');

        $this->assertSame(Workspace::SYNC_FAILED, Workspace::find($id)->sync_state);
        $this->assertSame('upstream down', Workspace::find($id)->sync_error);
        $this->assertSame(2, OutboxEntry::query()->firstWhere('kit_id', $id)->attempts);
    }

    public function test_suspend_and_resume_reach_telenow(): void
    {
        $id = $this->create()->json('id');
        $sub = RecordedTelenow::body('createSubAccount')['subAccount']['id'];

        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$id}/suspend", ['reason' => 'Unpaid'])
            ->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('syncState', 'synced');
        [$suspend] = $this->sentTo('POST', "/api/v1/sub-accounts/{$sub}/suspend")[0];
        $this->assertSame(['reason' => 'Unpaid'], $suspend->data());
        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$id}/suspend")->assertStatus(409);

        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$id}/resume")
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('syncState', 'synced');
        $this->assertCount(1, $this->sentTo('POST', "/api/v1/sub-accounts/{$sub}/resume"));
        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$id}/resume")->assertStatus(409);
    }

    public function test_a_rows_writes_go_in_order(): void
    {
        $up = false;
        $this->telenow(function (Request $r) use (&$up) {
            if (! $up) {
                return Http::failedConnection('Connection refused');
            }

            return $r->method() === 'POST' && str_ends_with($r->url(), '/suspend')
                ? Http::response(RecordedTelenow::body('suspendSubAccount'))
                : Http::response(RecordedTelenow::body('createSubAccount'), 201);
        });

        $id = $this->create()->json('id');
        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$id}/suspend")
            ->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('syncState', 'pending');
        $this->assertCount(1, Http::recorded()); // the create's first try only: the suspend waits behind it
        $suspend = OutboxEntry::query()->where('kit_id', $id)->where('operation', 'suspend')->first();
        $this->assertSame(0, $suspend->attempts, 'the suspend was never tried before the create was done');

        $up = true;
        $this->travel(1)->hours();
        $this->artisan('kit:outbox');

        $paths = array_map(fn (array $p) => parse_url($p[0]->url(), PHP_URL_PATH), Http::recorded()->all());
        $sub = RecordedTelenow::body('createSubAccount')['subAccount']['id'];
        $this->assertSame(['/api/v1/sub-accounts', '/api/v1/sub-accounts', "/api/v1/sub-accounts/{$sub}/suspend"], $paths);
        $this->assertSame(Workspace::SYNC_SYNCED, Workspace::find($id)->sync_state);
    }

    public function test_a_write_another_runner_holds_is_left_alone_until_its_hold_ends(): void
    {
        $workspace = Workspace::create(['name' => 'Held', 'status' => 'active', 'sync_state' => 'pending']);
        $entry = app(Outbox::class)->enqueue('workspace', $workspace->id, 'create', [
            'name' => 'Held', 'externalId' => $workspace->id, 'keyName' => 'k', 'keyRole' => 'admin',
        ], $workspace->id);
        $entry->forceFill(['locked_until' => now()->addMinute()])->save();

        $this->artisan('kit:outbox')->expectsOutput('0 write(s) done');
        Http::assertNothingSent();

        $this->travel(2)->minutes();
        $this->artisan('kit:outbox')->expectsOutput('1 write(s) done');
        $this->assertSame(Workspace::SYNC_SYNCED, $workspace->fresh()->sync_state);
    }

    public function test_a_replayed_create_answered_as_text_plain_is_still_read(): void
    {
        // Telenow 2446ecb answers a replay byte for byte but labels it text/plain (recorded by contract_k1customers.py).
        $this->telenow(fn () => Http::response(json_encode(RecordedTelenow::body('createSubAccount')), 201, ['Content-Type' => 'text/plain']));

        $this->create()->assertCreated()->assertJsonPath('syncState', 'synced');
    }

    public function test_the_list_is_read_from_the_kits_db_only_newest_first(): void
    {
        config(['kit.admin.per_page' => 2]);
        foreach (['First', 'Second', 'Third'] as $i => $name) {
            $w = Workspace::create(['name' => $name, 'status' => 'active', 'sync_state' => 'synced']);
            $w->forceFill(['created_at' => now()->addSeconds($i)])->save();
        }

        $this->actingAs($this->owner)->getJson('/api/kit/admin/customers?page=1')
            ->assertOk()->assertJsonPath('total', 3)->assertJsonPath('perPage', 2)
            ->assertJsonPath('customers.0.name', 'Third')->assertJsonPath('customers.1.name', 'Second');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/customers?page=2')->assertJsonPath('customers.0.name', 'First');
        Http::assertNothingSent();
    }

    public function test_an_unknown_customer_is_404(): void
    {
        $this->actingAs($this->owner)->getJson('/api/kit/admin/customers/not-a-uuid')->assertNotFound()->assertJsonStructure(['message']);
        $this->actingAs($this->owner)->postJson('/api/kit/admin/customers/00000000-0000-4000-8000-000000000000/suspend')->assertNotFound();
    }

    public function test_the_admin_panel_is_staff_only_and_only_after_the_install(): void
    {
        $this->getJson('/api/kit/admin/customers')->assertUnauthorized();
        $customer = User::create(['name' => 'C', 'email' => 'c@example.com', 'password' => 'a long password 2']);
        $this->actingAs($customer)->getJson('/api/kit/admin/customers')->assertForbidden();
        $this->actingAs($customer)->postJson('/api/kit/admin/customers', ['name' => 'X'])->assertForbidden();
        $this->assertSame(0, Workspace::count());

        unlink(config('kit.installed_marker'));
        $this->actingAs($this->owner)->getJson('/api/kit/admin/customers')->assertStatus(409);
    }

    public function test_a_name_is_required_and_kept_to_telenows_limit(): void
    {
        $this->create('')->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->create(str_repeat('a', config('kit.customers.name_max_length') + 1))->assertStatus(422)->assertJsonValidationErrors(['name']);
        Http::assertNothingSent();
    }
}
