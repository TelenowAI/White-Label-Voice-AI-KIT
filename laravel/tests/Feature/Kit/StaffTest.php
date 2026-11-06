<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route as Routes;
use Illuminate\Testing\TestResponse;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * Admin → Staff and what each role may do (App\Kit\Staff, EnsureAbility, App\Kit\Audit): every admin route names an
 * ability; an owner does everything, an admin everything but the team and payment gateways, support reads customers,
 * suspends and resumes them and sees the voice platform page, and nothing else — the session says which; the owner
 * invites by email (or a link answered once), the invitee joins and is signed in, an invite works once, can be
 * cancelled, is replaced by a newer one and runs out; a customer's address cannot be staff; there is always an owner;
 * someone removed loses the admin panel at once; every change is in the audit log, which support cannot read.
 */
class StaffTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        // Suspending writes to Telenow at once: here Telenow is down, so the write only waits.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);
        $this->owner = User::create(['name' => 'Olu', 'email' => 'olu@op.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
    }

    private function staff(string $role, string $email): User
    {
        return User::create(['name' => ucfirst($role), 'email' => $email, 'password' => 'a long password 1', 'role' => $role]);
    }

    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function invite(string $email, string $role, ?User $by = null): TestResponse
    {
        return $this->actingAs($by ?? $this->owner)->postJson('/api/kit/admin/staff/invites', ['email' => $email, 'role' => $role]);
    }

    public function test_every_admin_route_names_an_ability_that_exists(): void
    {
        $admin = collect(Routes::getRoutes()->getRoutes())->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/kit/admin'));
        $this->assertGreaterThan(60, $admin->count());
        foreach ($admin as $route) {
            $abilities = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, \App\Http\Middleware\EnsureAbility::class.':'))
                ->map(fn (string $m) => substr($m, strlen(\App\Http\Middleware\EnsureAbility::class) + 1))->values();
            $this->assertCount(1, $abilities, "{$route->uri()} names one ability");
            $this->assertArrayHasKey($abilities[0], config('kit.admin.abilities'), "{$route->uri()}: {$abilities[0]} exists");
        }
    }

    public function test_each_role_does_what_it_may_and_nothing_else(): void
    {
        $admin = $this->staff('admin', 'ada@op.example');
        $support = $this->staff('support', 'sam@op.example');
        $id = $this->acme->id;
        // [method, path, body, the roles that may]: a refusal for a role is exactly the ability's 403.
        $cases = [
            ['GET', '/api/kit/admin/dashboard', [], ['owner', 'admin']],
            ['GET', '/api/kit/admin/telenow', [], ['owner', 'admin', 'support']],
            ['GET', '/api/kit/admin/customers', [], ['owner', 'admin', 'support']],
            ['GET', "/api/kit/admin/customers/{$id}/wallet", [], ['owner', 'admin', 'support']],
            ['POST', '/api/kit/admin/customers', ['name' => ''], ['owner', 'admin']],
            ['POST', "/api/kit/admin/customers/{$id}/wallet/credits", [], ['owner', 'admin']],
            ['GET', '/api/kit/admin/pricing', [], ['owner', 'admin']],
            ['GET', '/api/kit/admin/payments', [], ['owner', 'admin']],
            ['PUT', '/api/kit/admin/payments/settings', [], ['owner']],
            ['POST', '/api/kit/admin/payments/gateways', [], ['owner']],
            ['GET', '/api/kit/admin/website', [], ['owner', 'admin']],
            ['GET', '/api/kit/admin/email', [], ['owner', 'admin']],
            ['PUT', "/api/kit/admin/customers/{$id}/limits", [], ['owner', 'admin']],
            ['POST', "/api/kit/admin/customers/{$id}/close", [], ['owner', 'admin']],
            ['GET', '/api/kit/admin/staff', [], ['owner']],
            ['GET', '/api/kit/admin/audit', [], ['owner', 'admin']],
        ];
        foreach (['owner' => $this->owner, 'admin' => $admin, 'support' => $support] as $role => $user) {
            foreach ($cases as [$method, $path, $body, $may]) {
                $response = $this->actingAs($user)->json($method, $path, $body);
                if (in_array($role, $may, true)) {
                    $this->assertNotSame(403, $response->status(), "{$role} may {$method} {$path}");
                } else {
                    $response->assertForbidden()->assertJsonPath('message', __('staff.not_allowed'));
                }
            }
        }
        // Suspending is support's too.
        $this->actingAs($support)->postJson("/api/kit/admin/customers/{$id}/suspend", [])->assertOk();

        $this->actingAs($support)->getJson('/api/kit/session')->assertJsonPath('user.abilities', ['health', 'customers_read', 'customers_suspend']);
        $this->assertSame(array_keys(config('kit.admin.abilities')), $this->actingAs($this->owner)->getJson('/api/kit/session')->json('user.abilities'));
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->actingAs($ana)->getJson('/api/kit/session')->assertJsonPath('user.abilities', []);
    }

    public function test_the_owner_invites_someone_who_joins_once_and_is_signed_in(): void
    {
        $made = $this->invite('Ada@Op.example', 'admin')->assertCreated()->assertJsonPath('invite.email', 'ada@op.example')
            ->assertJsonPath('emailed', false)->json();
        $token = $made['token'];
        $this->assertSame(hash('sha256', $token), DB::table('staff_invites')->value('token_hash'));
        $this->actingAs($this->owner)->getJson('/api/kit/admin/staff')->assertJsonPath('invites.0.email', 'ada@op.example')
            ->assertJsonMissingPath('invites.0.token');

        $this->signOut();
        $this->getJson("/api/kit/staff-invites/{$token}")->assertOk()->assertJsonPath('role', 'admin')->assertJsonPath('accountExists', false);
        $this->postJson("/api/kit/staff-invites/{$token}/accept", ['name' => 'Ada', 'password' => 'short'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson("/api/kit/staff-invites/{$token}/accept", ['name' => 'Ada', 'password' => 'a long password 2'])->assertOk()
            ->assertJsonPath('user.role', 'admin')->assertJsonPath('user.email', 'ada@op.example');
        $this->getJson('/api/kit/admin/pricing')->assertOk();
        $this->getJson('/api/kit/admin/staff')->assertForbidden();
        $this->signOut();
        $this->postJson("/api/kit/staff-invites/{$token}/accept", ['name' => 'X', 'password' => 'a long password 3'])->assertNotFound();
        $this->getJson("/api/kit/staff-invites/{$token}")->assertNotFound();

        // A newer invite replaces an older one; a cancelled one and one that ran out stop working.
        $first = $this->invite('bo@op.example', 'support')->json('token');
        $second = $this->invite('bo@op.example', 'admin')->json('token');
        $this->signOut();
        $this->getJson("/api/kit/staff-invites/{$first}")->assertNotFound();
        $this->getJson("/api/kit/staff-invites/{$second}")->assertJsonPath('role', 'admin');
        $inviteId = $this->actingAs($this->owner)->getJson('/api/kit/admin/staff')->json('invites.0.id');
        $this->actingAs($this->owner)->deleteJson("/api/kit/admin/staff/invites/{$inviteId}")->assertNoContent();
        $this->actingAs($this->owner)->deleteJson("/api/kit/admin/staff/invites/{$inviteId}")->assertNotFound();
        $this->signOut();
        $this->getJson("/api/kit/staff-invites/{$second}")->assertNotFound();
        $late = $this->invite('cy@op.example', 'support')->json('token');
        $this->travel(config('kit.admin.invite_hours') + 1)->hours();
        $this->signOut();
        $this->getJson("/api/kit/staff-invites/{$late}")->assertNotFound();
    }

    public function test_an_invite_is_emailed_when_email_is_on(): void
    {
        $sink = SmtpSink::start();
        try {
            $senders = app(Senders::class);
            $senders->putSiteUrl('https://voice.op.example');
            $senders->putFrom('hello@op.example', null);
            $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $sink->port, 'encryption' => 'none']]]);
            $made = $this->invite('ada@op.example', 'support')->assertCreated()->assertJsonPath('emailed', true)->assertJsonPath('token', null);
            [$m] = $sink->messages();
            $this->assertSame(['ada@op.example'], $m['to']);
            $this->assertStringContainsString(__('staff.roles.support'), $m['text']);
            $link = SmtpSink::link($m, 'https://voice.op.example');
            $token = substr((string) $link, strlen('https://voice.op.example/admin/invite/'));
            $this->assertSame(hash('sha256', $token), DB::table('staff_invites')->value('token_hash'));
            $this->assertStringNotContainsString($token, $made->getContent());
        } finally {
            $sink->stop();
        }
    }

    public function test_staff_and_customers_are_apart_and_there_is_always_an_owner(): void
    {
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $ana->id, 'role' => 'owner']);
        $this->invite('ana@dental.example', 'support')->assertStatus(409)->assertJsonPath('message', __('staff.customer_address'));
        $this->invite('olu@op.example', 'admin')->assertStatus(409)->assertJsonPath('message', __('staff.already_staff'));
        $this->invite('x@op.example', 'superuser')->assertStatus(422)->assertJsonValidationErrors(['role']);

        $this->actingAs($this->owner)->putJson("/api/kit/admin/staff/{$this->owner->id}", ['role' => 'admin'])->assertStatus(409)
            ->assertJsonPath('message', __('staff.last_owner'));
        $this->actingAs($this->owner)->deleteJson("/api/kit/admin/staff/{$this->owner->id}")->assertStatus(409);
        $second = $this->staff('owner', 'oto@op.example');
        $this->actingAs($this->owner)->putJson("/api/kit/admin/staff/{$second->id}", ['role' => 'support'])->assertOk()->assertJsonPath('role', 'support');

        // Removed: the admin panel is gone at once; the account stays (the log still names them).
        $this->actingAs($this->owner)->deleteJson("/api/kit/admin/staff/{$second->id}")->assertNoContent();
        $this->actingAs($second->fresh())->getJson('/api/kit/admin/customers')->assertForbidden()->assertJsonPath('message', __('customers.staff_only'));
        $this->assertNull($second->fresh()->role);
        $this->actingAs($this->owner)->deleteJson("/api/kit/admin/staff/{$ana->id}")->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/kit/admin/staff')->assertJsonCount(1, 'members')->assertJsonPath('members.0.you', true);
    }

    public function test_the_audit_log_names_who_did_what_and_support_cannot_read_it(): void
    {
        $admin = $this->staff('admin', 'ada@op.example');
        $this->invite('bo@op.example', 'support');
        $this->actingAs($admin)->postJson("/api/kit/admin/customers/{$this->acme->id}/suspend", ['reason' => 'unpaid'])->assertOk();
        $this->actingAs($admin)->putJson("/api/kit/admin/customers/{$this->acme->id}/plan", ['priceListId' => null])->assertOk();
        $this->actingAs($this->owner)->putJson("/api/kit/admin/staff/{$admin->id}", ['role' => 'support'])->assertOk();

        $log = $this->actingAs($this->owner)->getJson('/api/kit/admin/audit')->assertOk()->json();
        $this->assertSame(['staff.role_changed', 'customer.plan_changed', 'customer.suspended', 'staff.invited'], array_column($log['entries'], 'action'));
        $this->assertSame([__('audit.actions.staff.role_changed'), 'ada@op.example', 'Olu'], [$log['entries'][0]['label'], $log['entries'][0]['subject']['label'], $log['entries'][0]['by']['name']]);
        $this->assertSame(['type' => 'customer', 'id' => $this->acme->id, 'label' => 'Acme Dental'], $log['entries'][2]['subject']);
        $this->assertSame(['reason' => 'unpaid'], $log['entries'][2]['details']);
        $this->assertSame(['from' => null, 'to' => null], $log['entries'][1]['details']);
        $this->assertSame(['from' => 'admin', 'to' => 'support'], $log['entries'][0]['details']);
        $this->actingAs($admin->fresh())->getJson('/api/kit/admin/audit')->assertForbidden();
    }
}
