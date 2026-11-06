<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * The wizard's Telenow step against recorded Telenow answers (contract/telenow-mock/cassettes): it keeps only an
 * owner/admin key of a top-level account, stores it encrypted, never shows it again, and reports whether Telenow
 * lets the account spend.
 */
class TelenowStepTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        RecordedTelenow::fake();
        app(Settings::class)->put('setup.database.done_at', now()->toIso8601String());
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
    }

    private function send(?string $key): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/telenow', ['apiKey' => $key]);
    }

    private function step(\Illuminate\Testing\TestResponse $response): array
    {
        return collect($response->json('steps'))->firstWhere('id', 'telenow');
    }

    public function test_an_admin_key_of_a_top_level_account_is_kept_encrypted_with_what_telenow_said(): void
    {
        $step = $this->step($this->send('vai_test_operator_admin')->assertOk());

        $me = RecordedTelenow::answer('whoAmI', 'vai_test_operator_admin');
        $this->assertSame('done', $step['status']);
        $this->assertSame(
            ['Account' => $me['org_name'], 'Key' => "{$me['key_name']} (admin)", 'Billing' => 'Prepaid', 'Calls' => 'Telenow lets this account make calls now'],
            array_column($step['facts'], 'value', 'label'),
        );
        $this->assertSame('ok', $step['checks'][0]['level']);
        $raw = DB::table('settings')->where('key', 'telenow.api_key')->first();
        $this->assertTrue((bool) $raw->is_secret);
        $this->assertStringNotContainsString('vai_test_operator_admin', $raw->value);
        $this->assertSame('vai_test_operator_admin', app(Settings::class)->get('telenow.api_key'));
    }

    public function test_the_key_never_comes_back_in_an_answer(): void
    {
        $this->send('vai_test_operator_admin')->assertOk();

        $this->assertStringNotContainsString('vai_test_operator_admin', $this->actingAs($this->owner)->getJson('/api/kit/setup')->getContent());
    }

    public function test_an_account_telenow_will_not_let_spend_is_kept_with_a_warning(): void
    {
        $step = $this->step($this->send('vai_test_operator_stopped')->assertOk());

        $this->assertSame('done', $step['status']);
        $this->assertSame('warning', $step['checks'][0]['level']);
        $this->assertSame('the account has no credit left', $step['checks'][0]['detail']);
        $this->assertContains('Paused: the account has no credit left', array_column($step['facts'], 'value'));
    }

    public function test_a_key_whose_role_cannot_manage_sub_accounts_is_refused(): void
    {
        $this->send('vai_test_developer')->assertStatus(422)
            ->assertJsonPath('errors.apiKey.0', "This key's role is developer. The platform needs a key with one of these roles: owner, admin.");
        $this->send('vai_test_viewer')->assertStatus(422)->assertJsonValidationErrors(['apiKey']);
        $this->assertFalse(app(Settings::class)->has('telenow.api_key'));
    }

    public function test_a_sub_accounts_key_is_refused(): void
    {
        $me = RecordedTelenow::answer('whoAmI', 'vai_test_sub_account');

        $this->send('vai_test_sub_account')->assertStatus(422)
            ->assertJsonPath('errors.apiKey.0', "This key belongs to a sub-account ({$me['org_name']}). Use a key of your own top-level account.");
    }

    public function test_a_key_telenow_refuses_shows_telenows_reason(): void
    {
        $this->send('vai_test_revoked')->assertStatus(422)
            ->assertJsonPath('errors.apiKey.0', 'Telenow refused this key: Invalid or revoked API key');
    }

    public function test_an_unreachable_telenow_is_said_so(): void
    {
        Http::swap(new Factory);
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $this->send('vai_test_operator_admin')->assertStatus(422)
            ->assertJsonPath('errors.apiKey.0', fn (string $m) => str_starts_with($m, 'Telenow could not be reached: Could not reach https://telenow.test'));
    }

    public function test_leaving_the_key_empty_checks_the_saved_one_again(): void
    {
        $this->send('vai_test_operator_admin')->assertOk();

        $this->send(null)->assertOk();
        $this->send('')->assertOk();
        $this->assertSame('vai_test_operator_admin', app(Settings::class)->get('telenow.api_key'));
    }

    public function test_no_key_at_all_is_refused(): void
    {
        $this->send(null)->assertStatus(422)->assertJsonValidationErrors(['apiKey']);
    }
}
