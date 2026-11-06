<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * The customer console's account routes: the accounts a user belongs to, working in one at a time (never one they are
 * not in), and its home — with the "service paused" notice and its reason, told without naming the voice platform or
 * the operator's own billing (white label).
 */
class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Workspace $acme;

    private Workspace $beta;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = $this->account('Acme Dental');
        $this->beta = $this->account('Beta Clinic');
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->beta->id, 'user_id' => $this->ana->id, 'role' => 'member']);
    }

    private function account(string $name, ?string $sub = null): Workspace
    {
        $w = Workspace::create(['name' => $name, 'status' => 'active', 'sync_state' => 'synced']);
        $w->forceFill(['telenow_sub_account_id' => $sub ?? (string) \Illuminate\Support\Str::uuid()])->save();

        return $w;
    }

    private function home(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->ana)->getJson('/api/kit/console/home');
    }

    public function test_a_member_works_in_their_first_account_and_sees_its_home(): void
    {
        $this->actingAs($this->ana)->getJson('/api/kit/console/workspaces')->assertOk()->assertExactJson(['workspaces' => [
            ['id' => $this->acme->id, 'name' => 'Acme Dental', 'role' => 'owner', 'current' => true],
            ['id' => $this->beta->id, 'name' => 'Beta Clinic', 'role' => 'member', 'current' => false],
        ]]);
        $this->home()->assertOk()->assertExactJson([
            'workspace' => ['id' => $this->acme->id, 'name' => 'Acme Dental', 'status' => 'active'],
            'role' => 'owner', 'canManageTeam' => true, 'paused' => null,
        ]);
    }

    public function test_picking_another_of_their_accounts_switches_and_one_not_theirs_is_refused(): void
    {
        $this->actingAs($this->ana)->postJson('/api/kit/console/workspace', ['id' => $this->beta->id])->assertOk()
            ->assertJsonPath('workspaces.1.current', true);
        $this->home()->assertJsonPath('workspace.name', 'Beta Clinic')->assertJsonPath('canManageTeam', false);

        $stranger = $this->account('Not Hers');
        $this->actingAs($this->ana)->postJson('/api/kit/console/workspace', ['id' => $stranger->id])->assertNotFound();
        $this->home()->assertJsonPath('workspace.name', 'Beta Clinic');
    }

    public function test_an_account_they_left_is_no_longer_where_they_work(): void
    {
        $this->actingAs($this->ana)->postJson('/api/kit/console/workspace', ['id' => $this->beta->id]);
        WorkspaceMember::query()->where('workspace_id', $this->beta->id)->delete();

        $this->home()->assertJsonPath('workspace.name', 'Acme Dental');
        WorkspaceMember::query()->where('user_id', $this->ana->id)->delete();
        $this->home()->assertForbidden()->assertJsonPath('message', 'You are not a member of any account yet. Ask for an invite.');
    }

    public function test_signed_out_staff_and_before_the_install(): void
    {
        $this->getJson('/api/kit/console/home')->assertUnauthorized();
        $this->getJson('/api/kit/console/workspaces')->assertUnauthorized();
        $staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->actingAs($staff)->getJson('/api/kit/console/home')->assertForbidden();

        unlink(config('kit.installed_marker'));
        $this->home()->assertStatus(409);
    }

    public function test_the_service_is_paused_for_a_suspended_or_unready_account(): void
    {
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->home()->assertJsonPath('paused.reason', 'account_suspended');

        $this->acme->forceFill(['status' => Workspace::ACTIVE, 'telenow_sub_account_id' => null])->save();
        $this->home()->assertJsonPath('paused.reason', 'account_not_ready');
    }

    public function test_the_service_is_paused_when_the_operators_platform_is_stopped_without_saying_why(): void
    {
        $settings = app(Settings::class);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'stopped', 'canSpend' => false, 'stoppedBy' => 'out_of_credit']);
        $stopped = $this->home()->assertJsonPath('paused.reason', 'service_paused')->json('paused.message');

        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $settings->put('telenow.key_rejected', true);
        $this->home()->assertJsonPath('paused.reason', 'service_paused')->assertJsonPath('paused.message', $stopped);

        foreach (['Telenow', 'credit', 'out_of_credit', 'key'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $stopped);
        }
        $settings->put('telenow.key_rejected', false);
        $this->home()->assertJsonPath('paused', null);
    }

    public function test_the_status_job_reads_whether_telenow_lets_the_operator_spend(): void
    {
        RecordedTelenow::fake();
        $settings = app(Settings::class);
        $this->operator('vai_test_operator_stopped');

        $this->artisan('kit:telenow-status')->assertSuccessful();
        $this->assertFalse($settings->get('telenow.billing')['canSpend']);
        $this->home()->assertJsonPath('paused.reason', 'service_paused');

        $this->operator('vai_test_operator_admin');
        $this->artisan('kit:telenow-status');
        $this->assertTrue($settings->get('telenow.billing')['canSpend']);
        $this->assertNotNull($settings->get('telenow.status_checked_at'));
        $this->home()->assertJsonPath('paused', null);
        // What the admin dashboard shows of the operator's account is kept too, and only that.
        $kept = $settings->get('telenow.billing');
        $this->assertEqualsCanonicalizing(config('kit.telenow.spend_state_fields'), array_keys($kept));
        $this->assertSame(RecordedTelenow::body('getBillingSummary.operator-admin')['data']['spendState']['balanceUsd'], $kept['balanceUsd']);
    }

    public function test_a_rejected_key_pauses_the_service_and_a_failed_read_keeps_the_last_state(): void
    {
        $settings = app(Settings::class);
        $this->operator('vai_test_operator_admin');
        // Telenow's answer to a revoked key, the same on every route (recorded on /api/v1/me).
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(RecordedTelenow::body('whoAmI.revoked'), 401)]);
        $this->artisan('kit:telenow-status');
        $this->assertTrue($settings->get('telenow.key_rejected'));
        $this->assertTrue($settings->get('telenow.billing')['canSpend'], 'the last known state is kept');
        $this->home()->assertJsonPath('paused.reason', 'service_paused');

        \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::failedConnection('Connection refused')]);
        $this->artisan('kit:telenow-status');
        $this->assertTrue($settings->get('telenow.key_rejected'), 'unreachable says nothing about the key');
        $this->assertStringContainsString('Could not reach', $settings->get('telenow.status_error'));
    }

    /** Save this key as the operator's, with the account Telenow names for it. */
    private function operator(string $key): void
    {
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', $key, secret: true);
        $settings->put('telenow.account', ['org_id' => RecordedTelenow::answer('whoAmI', $key)['org_id']]);
    }
}
