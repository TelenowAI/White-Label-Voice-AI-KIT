<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvite;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * A customer account's team (App\Kit\Team): the operator invites an account's first user, an account's managers invite
 * and remove their own people; an invite link is emailed when the kit can send email (and then never answered), else
 * shown once; it is kept only as a hash, works once, and every dead link answers the same 404; accepting signs the person in, and someone who already has an account proves it, signed in
 * to it here or with its password while that works, never by the link alone; with passwords off someone new gives only a name; an account always keeps a manager.
 */
class TeamTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
    }

    private function adminInvite(string $email, string $role = 'owner', ?Workspace $w = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)->postJson('/api/kit/admin/customers/'.($w ?? $this->acme)->id.'/team/invites', ['email' => $email, 'role' => $role]);
    }

    private function signOut(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_the_operator_invites_an_accounts_first_user_who_joins_and_is_signed_in(): void
    {
        $made = $this->adminInvite('ana@example.com')->assertCreated()->assertJsonPath('invite.email', 'ana@example.com')->json();
        $token = $made['token'];
        $this->assertSame(1, preg_match('/^[A-Za-z0-9_-]{43}$/', $token));
        $this->assertSame(hash('sha256', $token), DB::table('workspace_invites')->value('token_hash'));
        $this->signOut();

        $this->getJson("/api/kit/invites/{$token}")->assertOk()->assertExactJson([
            'email' => 'ana@example.com', 'customerName' => 'Acme Dental', 'role' => 'owner',
            'expiresAt' => $made['invite']['expiresAt'], 'accountExists' => false, 'choosesPassword' => true, 'signedInAs' => null, 'canJoinNow' => false,
        ]);
        $this->postJson("/api/kit/invites/{$token}/accept", ['name' => 'Ana', 'password' => 'a long password 9'])
            ->assertOk()->assertJsonPath('user.email', 'ana@example.com')->assertJsonPath('user.role', null);

        $ana = User::query()->firstWhere('email', 'ana@example.com');
        $this->assertAuthenticatedAs($ana);
        $this->assertSame('owner', WorkspaceMember::query()->where('user_id', $ana->id)->value('role'));
        $this->getJson("/api/kit/invites/{$token}")->assertNotFound();
        $this->postJson("/api/kit/invites/{$token}/accept", ['name' => 'Again', 'password' => 'a long password 9'])->assertNotFound();
    }

    public function test_an_invite_is_emailed_when_email_is_on_and_then_its_link_is_never_answered(): void
    {
        $sink = SmtpSink::start();
        try {
            $senders = app(Senders::class);
            $senders->putSiteUrl('https://voice.acme.example');
            $senders->putFrom('hello@acme.example', null);
            $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $sink->port, 'encryption' => 'none']]]);

            $made = $this->adminInvite('ana@example.com')->assertCreated()->assertJsonPath('emailed', true)->assertJsonPath('token', null);
            [$m] = $sink->messages();
            $this->assertSame(['ana@example.com'], $m['to']);
            $this->assertStringContainsString('Acme Dental', $m['subject']);
            $link = SmtpSink::link($m, 'https://voice.acme.example');
            $token = substr((string) $link, strlen('https://voice.acme.example/console/invite/'));
            $this->assertSame(hash('sha256', $token), DB::table('workspace_invites')->value('token_hash'));
            $this->getJson("/api/kit/invites/{$token}")->assertOk()->assertJsonPath('customerName', 'Acme Dental');
            $this->assertStringNotContainsString($token, $made->getContent());

            $senders->put([['id' => 'down', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => SmtpSink::closedPort(), 'encryption' => 'none']]]);
            $fallback = $this->adminInvite('bo@example.com', 'member')->assertCreated()->assertJsonPath('emailed', false);
            $this->getJson('/api/kit/invites/'.$fallback->json('token'))->assertOk()->assertJsonPath('email', 'bo@example.com');
        } finally {
            $sink->stop();
        }
    }

    public function test_the_link_is_answered_once_and_never_listed(): void
    {
        $token = $this->adminInvite('ana@example.com')->json('token');

        $listed = $this->actingAs($this->staff)->getJson("/api/kit/admin/customers/{$this->acme->id}/team")->assertOk();
        $listed->assertJsonPath('invites.0.email', 'ana@example.com')->assertJsonPath('canManage', true)
            ->assertJsonPath('roles', config('kit.team.roles'));
        $this->assertStringNotContainsString($token, $listed->getContent());
    }

    public function test_every_dead_link_answers_the_same_404(): void
    {
        $expired = $this->adminInvite('a@example.com')->json('token');
        $revokedMade = $this->adminInvite('b@example.com')->json();
        $this->actingAs($this->staff)->deleteJson("/api/kit/admin/customers/{$this->acme->id}/team/invites/{$revokedMade['invite']['id']}")->assertNoContent();
        $this->travel(config('kit.team.invite_hours'))->hours();
        $this->travel(1)->seconds();

        $answers = array_map(fn (string $t) => $this->getJson("/api/kit/invites/{$t}")->assertNotFound()->json(),
            [$expired, $revokedMade['token'], 'never-made']);
        $this->assertCount(1, array_unique(array_map('json_encode', $answers)));
    }

    public function test_a_new_invite_for_an_email_replaces_its_earlier_link(): void
    {
        $first = $this->adminInvite('ana@example.com')->json('token');
        $second = $this->adminInvite('ANA@example.com', 'member')->json('token');

        $this->getJson("/api/kit/invites/{$first}")->assertNotFound();
        $this->getJson("/api/kit/invites/{$second}")->assertOk()->assertJsonPath('email', 'ana@example.com')->assertJsonPath('role', 'member');
        $this->assertSame(1, WorkspaceInvite::query()->pending()->count());
    }

    public function test_someone_who_has_an_account_joins_with_their_password(): void
    {
        $other = Workspace::create(['name' => 'Other Co', 'status' => 'active', 'sync_state' => 'synced']);
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'anas own password']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $ana->id, 'role' => 'owner']);
        $token = $this->adminInvite('Ana@Example.com', 'member')->json('token');
        $this->signOut();

        $this->getJson("/api/kit/invites/{$token}")->assertJsonPath('accountExists', true);
        $this->postJson("/api/kit/invites/{$token}/accept", ['password' => 'not it'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', 'These details do not match an account.');
        $this->assertSame(1, WorkspaceMember::query()->where('user_id', $ana->id)->count());
        $this->assertGuest();

        $this->postJson("/api/kit/invites/{$token}/accept", ['password' => 'anas own password'])->assertOk();
        $this->assertSame(['member', 'owner'], WorkspaceMember::query()->where('user_id', $ana->id)->orderBy('role')->pluck('role')->all());
        $this->assertSame(1, User::query()->where('email', 'ana@example.com')->count());
    }

    public function test_someone_signed_in_to_the_invited_account_joins_with_no_password(): void
    {
        $other = Workspace::create(['name' => 'Other Co', 'status' => 'active', 'sync_state' => 'synced']);
        $ana = User::create(['name' => 'Ana', 'email' => 'Ana@Example.com', 'password' => Str::random(64)]);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $ana->id, 'role' => 'owner']);
        $token = $this->adminInvite('ana@example.com', 'member')->json('token');
        $this->signOut();

        $this->getJson("/api/kit/invites/{$token}")->assertOk()
            ->assertJsonPath('accountExists', true)->assertJsonPath('choosesPassword', false)
            ->assertJsonPath('signedInAs', null)->assertJsonPath('canJoinNow', false);
        $this->postJson("/api/kit/invites/{$token}/accept", [])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->assertGuest();
        $this->assertSame(1, WorkspaceMember::query()->where('user_id', $ana->id)->count());

        $this->actingAs($ana);
        $this->getJson("/api/kit/invites/{$token}")->assertOk()
            ->assertJsonPath('signedInAs', 'Ana@Example.com')->assertJsonPath('canJoinNow', true);
        $this->postJson("/api/kit/invites/{$token}/accept", [])->assertOk()->assertJsonPath('user.email', 'Ana@Example.com');
        $this->assertSame(['member', 'owner'], WorkspaceMember::query()->where('user_id', $ana->id)->orderBy('role')->pluck('role')->all());
        $this->assertAuthenticatedAs($ana);
        $this->getJson("/api/kit/invites/{$token}")->assertNotFound();
    }

    public function test_someone_elses_session_does_not_join_an_account_that_exists(): void
    {
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'anas own password']);
        $ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'bens own password']);
        $token = $this->adminInvite('ana@example.com', 'member')->json('token');
        $this->signOut();

        $this->actingAs($ben);
        $this->getJson("/api/kit/invites/{$token}")->assertOk()->assertJsonPath('signedInAs', 'ben@example.com')->assertJsonPath('canJoinNow', false);
        $this->postJson("/api/kit/invites/{$token}/accept", [])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson("/api/kit/invites/{$token}/accept", ['password' => 'bens own password'])->assertStatus(422);
        $this->assertSame(0, WorkspaceMember::query()->whereIn('user_id', [$ana->id, $ben->id])->count());
        $this->assertAuthenticatedAs($ben);

        $byPhone = User::create(['name' => 'Cy', 'email' => null, 'password' => Str::random(64)]);
        $byPhone->forceFill(['phone' => '+919876543210'])->save();
        $this->actingAs($byPhone);
        $this->getJson("/api/kit/invites/{$token}")->assertOk()->assertJsonPath('signedInAs', '+919876543210')->assertJsonPath('canJoinNow', false);
    }

    public function test_with_passwords_off_a_customers_password_proves_nothing_and_staff_keep_theirs(): void
    {
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'anas own password']);
        $bo = User::create(['name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'bos own password', 'role' => User::ROLE_OWNER]);
        $forAna = $this->adminInvite('ana@example.com', 'member')->json('token');
        $forBo = $this->adminInvite('bo@example.com', 'member')->json('token');
        app(SignupSettings::class)->save(['methods' => ['magic_link']]);
        $this->signOut();

        $this->postJson("/api/kit/invites/{$forAna}/accept", ['password' => 'anas own password'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', __('team.sign_in_first'));
        $this->assertGuest();
        $this->assertSame(0, WorkspaceMember::query()->where('user_id', $ana->id)->count());

        $this->actingAs($ana);
        $this->postJson("/api/kit/invites/{$forAna}/accept", [])->assertOk();
        $this->assertSame(1, WorkspaceMember::query()->where('user_id', $ana->id)->count());
        $this->signOut();

        $this->postJson("/api/kit/invites/{$forBo}/accept", ['password' => 'bos own password'])->assertOk();
        $this->assertSame(1, WorkspaceMember::query()->where('user_id', $bo->id)->count());
    }

    public function test_with_passwords_off_someone_new_gives_only_a_name(): void
    {
        $token = $this->adminInvite('ana@example.com', 'member')->json('token');
        app(SignupSettings::class)->save(['methods' => ['magic_link']]);
        $this->signOut();

        $this->getJson("/api/kit/invites/{$token}")->assertOk()->assertJsonPath('accountExists', false)->assertJsonPath('choosesPassword', false);
        $this->postJson("/api/kit/invites/{$token}/accept", [])->assertStatus(422)->assertJsonValidationErrors(['name'])->assertJsonMissingValidationErrors(['password']);
        $this->postJson("/api/kit/invites/{$token}/accept", ['name' => 'Ana', 'password' => 'a password nobody asked for'])->assertOk();
        $ana = User::query()->firstWhere('email', 'ana@example.com');
        $this->assertAuthenticatedAs($ana);
        $this->assertFalse(Hash::check('a password nobody asked for', $ana->password));
        $this->assertSame(1, WorkspaceMember::query()->where('user_id', $ana->id)->count());
    }

    public function test_a_new_person_sets_a_name_and_a_long_enough_password(): void
    {
        $token = $this->adminInvite('ana@example.com')->json('token');
        $this->signOut();

        $this->postJson("/api/kit/invites/{$token}/accept", ['password' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'password']);
        $this->getJson("/api/kit/invites/{$token}")->assertOk(); // still usable
    }

    public function test_an_invite_names_a_role_the_team_has_and_nobody_already_in(): void
    {
        $this->adminInvite('ana@example.com', 'emperor')->assertStatus(422)->assertJsonValidationErrors(['role']);
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'anas own password']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $ana->id, 'role' => 'member']);
        $this->adminInvite('ANA@example.com')->assertStatus(409);
    }

    public function test_an_accounts_manager_runs_its_team_and_a_member_only_sees_it(): void
    {
        $owner = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $member = User::create(['name' => 'Bo', 'email' => 'bo@example.com', 'password' => 'a long password 8']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $owner->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($member)->getJson('/api/kit/console/team')->assertOk()->assertJsonPath('canManage', false)->assertJsonCount(2, 'members');
        $this->actingAs($member)->postJson('/api/kit/console/team/invites', ['email' => 'cy@example.com', 'role' => 'member'])->assertForbidden();
        $this->actingAs($member)->deleteJson("/api/kit/console/team/members/{$owner->id}")->assertForbidden();

        $made = $this->actingAs($owner)->postJson('/api/kit/console/team/invites', ['email' => 'cy@example.com', 'role' => 'member'])->assertCreated()->json();
        $this->actingAs($owner)->getJson('/api/kit/console/team')->assertJsonPath('canManage', true)->assertJsonPath('invites.0.email', 'cy@example.com');
        $this->actingAs($owner)->deleteJson("/api/kit/console/team/invites/{$made['invite']['id']}")->assertNoContent();
        $this->actingAs($owner)->deleteJson("/api/kit/console/team/invites/{$made['invite']['id']}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/kit/console/team/members/{$member->id}")->assertNoContent();
        $this->actingAs($owner)->deleteJson("/api/kit/console/team/members/{$owner->id}")->assertStatus(409)
            ->assertJsonPath('message', 'An account needs at least one person who manages it.');
        $this->assertSame(1, WorkspaceMember::query()->where('workspace_id', $this->acme->id)->count());
    }

    public function test_a_manager_cannot_touch_another_accounts_team(): void
    {
        $other = Workspace::create(['name' => 'Other Co', 'status' => 'active', 'sync_state' => 'synced']);
        $owner = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $stranger = User::create(['name' => 'Zed', 'email' => 'zed@example.com', 'password' => 'a long password 7']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $owner->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $stranger->id, 'role' => 'member']);
        $theirs = $this->adminInvite('x@example.com', 'member', $other)->json('invite.id');

        $this->actingAs($owner)->deleteJson("/api/kit/console/team/members/{$stranger->id}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/kit/console/team/invites/{$theirs}")->assertNotFound();
        $this->assertSame(1, WorkspaceMember::query()->where('workspace_id', $other->id)->count());
        $this->assertSame(1, WorkspaceInvite::query()->pending()->where('workspace_id', $other->id)->count());
    }

    public function test_accepting_is_slowed_after_too_many_tries(): void
    {
        config(['kit.team.accept_per_minute' => 2]);
        $try = fn () => $this->postJson('/api/kit/invites/never-made/accept', ['password' => 'x']);

        $try()->assertNotFound();
        $try()->assertNotFound();
        $try()->assertStatus(429)->assertExactJson(['message' => 'Too many tries. Wait a minute and try again.']);
    }
}
