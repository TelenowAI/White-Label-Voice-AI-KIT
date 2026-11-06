<?php

namespace Tests\Feature\Kit;

use App\Kit\Outbox\Outbox;
use App\Kit\Settings\Settings;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Admin → a customer's actions (App\Kit\Customers, App\Kit\Console\ViewAs) against a faked Telenow:
 * - limits: the most calls at once and a monthly cap reach the sub-account as sent, null clears them, bad values are
 *   refused, and the change is in the audit log;
 * - closing: only with the customer's name typed; suspended at once, every number released, and the sub-account
 *   closed only once Telenow holds none of them — waiting (not given up) while a number is still being released or
 *   Telenow says it holds one; then `closed`, its key forgotten; its members see the account closed; nothing more can
 *   be done to it;
 * - viewing as: staff see that customer's console, read-only, until they stop, it runs out, or their role may not any
 *   more; only roles that may start it; both ends are in the audit log.
 */
class CustomerActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $acme;

    /** @var list<Request> what reached Telenow */
    private array $sent = [];

    /** Telenow's answers to change mid-test. */
    private int $numberStatus = 200;

    private int $closeStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $this->owner = User::create(['name' => 'Olu', 'email' => 'olu@op.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => 'vai_test_customer_acme'])->save();
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $this->sent[] = $r;
            $path = parse_url($r->url(), PHP_URL_PATH);
            $sub = ['id' => $this->acme->telenow_sub_account_id, 'name' => 'Acme Dental', 'status' => 'active', 'limits' => []];

            return match (true) {
                str_starts_with($path, '/api/voice/numbers/') => Http::response($this->numberStatus === 200 ? ['ok' => true] : ['error' => 'busy'], $this->numberStatus),
                $r->method() === 'DELETE' => Http::response(RecordedTelenow::body($this->closeStatus === 200 ? 'closeSubAccount' : 'closeSubAccount.holds-numbers'), $this->closeStatus),
                str_ends_with($path, '/usage') => Http::response(RecordedTelenow::body('getSubAccountUsage.operator')),
                default => Http::response($sub),
            };
        });
    }

    private function to(string $method, string $suffix = ''): array
    {
        return array_values(array_filter($this->sent, fn (Request $r) => $r->method() === $method && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $suffix)));
    }

    private function number(?string $releasedAt = null): PhoneNumber
    {
        return PhoneNumber::forceCreate(['workspace_id' => $this->acme->id, 'e164' => '+1415555'.random_int(1000, 9999), 'telenow_id' => (string) Str::uuid(),
            'sync_state' => 'synced', 'released_at' => $releasedAt]);
    }

    private function auditActions(): array
    {
        return DB::table('audit_events')->orderBy('id')->pluck('action')->all();
    }

    public function test_limits_reach_the_sub_account_as_sent(): void
    {
        $path = "/api/kit/admin/customers/{$this->acme->id}/limits";
        $this->actingAs($this->owner)->putJson($path, ['maxConcurrentCalls' => 3, 'monthlyCapUsd' => '50.00'])->assertOk()
            ->assertJsonPath('maxConcurrentCalls', 3)->assertJsonPath('monthlyCapUsd', '50.00');
        $patch = $this->to('PATCH');
        $sentLimits = end($patch)->data()['limits'];
        $this->assertSame(3, $sentLimits['maxConcurrentCalls']);
        $this->assertEquals(50, $sentLimits['monthlySpendLimitUsd'], 'a JSON number');

        $this->actingAs($this->owner)->putJson($path, ['maxConcurrentCalls' => null, 'monthlyCapUsd' => null])->assertOk();
        $patch = $this->to('PATCH');
        $this->assertSame(['limits' => ['maxConcurrentCalls' => null, 'monthlySpendLimitUsd' => null]], end($patch)->data(), 'null clears');

        $this->actingAs($this->owner)->putJson($path, ['maxConcurrentCalls' => -1, 'monthlyCapUsd' => '50.123'])->assertStatus(422)
            ->assertJsonValidationErrors(['maxConcurrentCalls', 'monthlyCapUsd']);
        $this->actingAs($this->owner)->putJson($path, ['monthlyCapUsd' => '5'])->assertStatus(422)->assertJsonValidationErrors(['maxConcurrentCalls']);
        $this->assertSame(['customer.limits_changed', 'customer.limits_changed'], $this->auditActions());
    }

    public function test_closing_releases_every_number_then_closes_the_sub_account(): void
    {
        $held = $this->number();
        $gone = $this->number(now()->subDay()->toDateTimeString());
        $member = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $member->id, 'role' => 'owner']);
        $close = "/api/kit/admin/customers/{$this->acme->id}/close";

        $this->actingAs($this->owner)->postJson($close, ['confirm' => 'acme dental'])->assertStatus(422)->assertJsonValidationErrors(['confirm']);
        $this->assertSame([], $this->sent);

        // A number Telenow cannot release yet: the customer is suspended, and the close waits for the number.
        $this->numberStatus = 503;
        $this->actingAs($this->owner)->postJson($close, ['confirm' => 'Acme Dental'])->assertOk()->assertJsonPath('status', Workspace::CLOSING);
        $this->assertCount(1, $this->to('POST', '/suspend'));
        $this->assertCount(1, $this->to('DELETE', "/numbers/{$held->telenow_id}"));
        $this->assertCount(0, $this->to('DELETE', "/numbers/{$gone->telenow_id}"), 'a released number is not released again');
        $this->assertCount(0, $this->to('DELETE', "/sub-accounts/{$this->acme->telenow_sub_account_id}"), 'not while a number is held');
        $acme = $this->acme->fresh();
        $this->assertSame(Workspace::CLOSING, $acme->status);
        $this->assertSame(__('customers.numbers_releasing', ['count' => 1]), $acme->sync_error);
        $this->assertNull(DB::table('telenow_outbox')->whereNotNull('failed_at')->first(), 'waiting, not given up');

        // Nothing more can be done to it; its members see it closed.
        foreach (['suspend', 'resume'] as $act) {
            $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$this->acme->id}/{$act}")->assertStatus(409);
        }
        $this->actingAs($this->owner)->putJson("/api/kit/admin/customers/{$this->acme->id}/limits", ['maxConcurrentCalls' => 1, 'monthlyCapUsd' => null])->assertStatus(409);
        $this->actingAs($this->owner)->postJson($close, ['confirm' => 'Acme Dental'])->assertStatus(409)->assertJsonPath('message', __('customers.already_closing'));
        $this->actingAs($member)->getJson('/api/kit/console/home')->assertJsonPath('paused.reason', 'account_closed');
        $this->actingAs($this->owner)->getJson("/api/kit/admin/customers/{$this->acme->id}/team")->assertJsonPath('canManage', false);
        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$this->acme->id}/team/invites", ['email' => 'new@dental.example', 'role' => 'member'])
            ->assertStatus(409)->assertJsonPath('message', __('customers.already_closing'));

        // Telenow says it still holds one (its recorded refusal): still waiting. Then it closes.
        $this->numberStatus = 200;
        $this->closeStatus = 409;
        // A run takes the due writes oldest first, so the close may be tried before the number's release: it waits,
        // and the next run closes.
        $this->travel(1)->hours();
        app(Outbox::class)->runDue();
        $this->assertSame('synced', $held->fresh()->sync_state);
        $this->travel(1)->hours();
        app(Outbox::class)->runDue();
        $this->assertNotEmpty($this->to('DELETE', "/sub-accounts/{$this->acme->telenow_sub_account_id}"));
        $this->assertSame(Workspace::CLOSING, $this->acme->fresh()->status);
        $this->assertNull(DB::table('telenow_outbox')->whereNotNull('failed_at')->first());
        $this->closeStatus = 200;
        $this->travel(1)->hours();
        app(Outbox::class)->runDue();
        $acme = $this->acme->fresh();
        $this->assertSame([Workspace::CLOSED, null], [$acme->status, $acme->telenow_key]);
        $this->assertNotNull($acme->closed_at);
        $this->actingAs($this->owner)->getJson("/api/kit/admin/customers/{$this->acme->id}")->assertJsonPath('status', Workspace::CLOSED)
            ->assertJsonPath('closeRequestedAt', fn ($v) => is_string($v));
        $this->assertSame(['customer.closing'], $this->auditActions());
    }

    public function test_a_customer_never_made_at_telenow_closes_at_once_and_one_made_late_is_closed(): void
    {
        $new = Workspace::create(['name' => 'New Co', 'status' => 'active', 'sync_state' => 'pending']);
        $create = app(Outbox::class)->enqueue('workspace', $new->id, 'create', ['name' => 'New Co', 'externalId' => $new->id, 'keyName' => 'kit', 'keyRole' => 'admin']);
        $this->actingAs($this->owner)->postJson("/api/kit/admin/customers/{$new->id}/close", ['confirm' => 'New Co'])->assertOk()
            ->assertJsonPath('status', Workspace::CLOSED)->assertJsonPath('closedAt', fn ($v) => is_string($v));
        $this->assertSame([], $this->sent, 'nothing to release or close at Telenow');
        $create->refresh();
        $this->assertNotNull($create->done_at);
        $this->assertSame(__('customers.closed_before_made'), $create->last_error);

        // A create already on its way lands after all: the sub-account it made is closed at once.
        $made = ['subAccount' => ['id' => (string) Str::uuid(), 'name' => 'New Co', 'status' => 'active'], 'apiKey' => ['key' => 'vai_test_late', 'lastFour' => 'late']];
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response($made, 201)]);
        app(\App\Kit\Customers\Telenow\CreateAtTelenow::class)->send($create);
        $new->refresh();
        $this->assertSame(Workspace::CLOSING, $new->status);
        $this->assertNull($new->closed_at);
        $this->assertSame(1, DB::table('telenow_outbox')->where('kit_id', $new->id)->where('operation', 'close')->whereNull('done_at')->count());
    }

    public function test_staff_view_a_customers_console_read_only_for_a_while(): void
    {
        $admin = User::create(['name' => 'Ada', 'email' => 'ada@op.example', 'password' => 'a long password 1', 'role' => 'admin']);
        $support = User::create(['name' => 'Sam', 'email' => 'sam@op.example', 'password' => 'a long password 1', 'role' => 'support']);
        $start = "/api/kit/admin/customers/{$this->acme->id}/view-as";
        $this->actingAs($support)->postJson($start)->assertForbidden();
        $this->actingAs($admin)->getJson('/api/kit/console/home')->assertForbidden()->assertJsonPath('message', __('console.no_account'));

        $until = $this->actingAs($admin)->postJson($start)->assertOk()->assertJsonPath('viewingAs.name', 'Acme Dental')->json('viewingAs.until');
        $this->actingAs($admin)->getJson('/api/kit/session')->assertJsonPath('viewingAs', ['customerId' => $this->acme->id, 'name' => 'Acme Dental', 'until' => $until]);
        $this->actingAs($admin)->getJson('/api/kit/console/home')->assertOk();
        $this->actingAs($admin)->getJson('/api/kit/console/workspaces')->assertJsonCount(1, 'workspaces')->assertJsonPath('workspaces.0.id', $this->acme->id);
        $this->actingAs($admin)->postJson('/api/kit/console/agents', ['name' => 'X'])->assertForbidden()->assertJsonPath('message', __('console.view_as_read_only'));
        $this->actingAs($admin)->postJson('/api/kit/console/team/invites', ['email' => 'x@y.example', 'role' => 'member'])->assertForbidden();

        $this->actingAs($admin)->postJson('/api/kit/session/view-as/stop')->assertOk()->assertJsonPath('customerId', $this->acme->id);
        $this->actingAs($admin)->getJson('/api/kit/session')->assertJsonPath('viewingAs', null);
        $this->actingAs($admin)->getJson('/api/kit/console/home')->assertForbidden();

        // It runs out by itself, and stops when the role may no longer do it.
        $this->actingAs($admin)->postJson($start)->assertOk();
        $this->travel(config('kit.admin.view_as_minutes') + 1)->minutes();
        $this->actingAs($admin)->getJson('/api/kit/console/home')->assertForbidden();
        $this->actingAs($admin)->postJson($start)->assertOk();
        $admin->forceFill(['role' => 'support'])->save();
        $this->actingAs($admin->fresh())->getJson('/api/kit/console/home')->assertForbidden();
        $this->actingAs($admin->fresh())->getJson('/api/kit/session')->assertJsonPath('viewingAs', null);

        $this->assertSame(['customer.viewed_as', 'customer.view_ended', 'customer.viewed_as', 'customer.viewed_as'], $this->auditActions());
    }
}
