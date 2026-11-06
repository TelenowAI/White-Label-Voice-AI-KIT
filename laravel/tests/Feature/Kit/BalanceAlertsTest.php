<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Kit\Wallet\Wallet;
use App\Models\Call;
use App\Models\OutgoingEmail;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The balance emails (App\Kit\Wallet\BalanceAlerts): the account's owners — not its other members — get one
 * low-balance email when the balance falls to its level, and one out-of-credit email when it reaches zero (only that
 * one when a charge passes both), each again only after the balance is back above its level; the level is the
 * account's own (set by its owner on its wallet page), else the operator's default (Admin → Pricing), zero meaning
 * only the out-of-credit email; a top-up never sends one, even one that leaves the balance low (owner 10-31); nothing
 * goes for a suspended account or while charging is off. The emails are read
 * from the kept outbox (the sender is a closed port: each email waits for a retry with its subject and body).
 */
class BalanceAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private User $staff;

    private User $ana;

    private User $ben;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => [
            'format' => 1, 'id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half_up',
            'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']]],
        ]]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        $senders = app(Senders::class);
        $senders->putSiteUrl(self::SITE);
        $senders->putFrom('hello@acme.example', null);
        $senders->put([['id' => 'down', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']]]);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 8']);
        // No sub-account: the spend limit at Telenow is not written (SpendLimit), so only the wallet is under test.
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
    }

    private function credit(string $amount): void
    {
        app(Wallet::class)->credit($this->acme, $amount, 'INR', null, $this->staff, (string) Str::uuid());
    }

    private function charge(string $amount): void
    {
        $call = Call::forceCreate(['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'started_at' => now(), 'duration_sec' => 60]);
        app(Wallet::class)->charge($this->acme, $call, ['total' => $amount, 'currency' => 'INR', 'plan' => 'standard', 'usdRate' => '83.00', 'lines' => []], ['totalChargeUsd' => '0.10']);
    }

    private function defaultLevel(?string $level): void
    {
        $this->actingAs($this->staff)->putJson('/api/kit/admin/pricing/low-balance', ['lowBalanceAt' => $level])->assertOk()->assertJsonPath('lowBalanceAt', $level);
    }

    /** @return list<array{kind: string, to: string, subject: string}> the emails sent so far, oldest first */
    private function sent(): array
    {
        return OutgoingEmail::query()->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (OutgoingEmail $e) => ['kind' => $e->kind, 'to' => $e->to_address, 'subject' => $e->subject])->all();
    }

    private function low(string $balance): array
    {
        return ['kind' => 'balance_low', 'to' => 'ana@example.com', 'subject' => __('emails.kinds.balance_low.subject', ['brand' => config('kit.brand.name'), 'balance' => $balance])];
    }

    private function empty(): array
    {
        return ['kind' => 'balance_empty', 'to' => 'ana@example.com', 'subject' => __('emails.kinds.balance_empty.subject', ['brand' => config('kit.brand.name')])];
    }

    public function test_the_owners_get_one_low_balance_email_at_the_level_and_another_only_after_a_top_up(): void
    {
        $this->defaultLevel('100');
        $this->credit('150');
        $this->charge('40');
        $this->assertSame([], $this->sent(), '110 is above the level');

        $this->charge('10');
        $this->assertSame([$this->low('100.00 INR')], $this->sent(), 'at the level itself; the owner only, not the member');

        $this->charge('5');
        $this->assertCount(1, $this->sent(), 'once while it stays low');

        $this->credit('100');
        $this->charge('105');
        $this->assertSame([$this->low('100.00 INR'), $this->low('90.00 INR')], $this->sent(), 'armed again by the top-up');
        $text = OutgoingEmail::query()->orderByDesc('created_at')->orderByDesc('id')->first()->body['text'];
        $this->assertStringContainsString('down to 90.00 INR', $text);
        $this->assertStringContainsString('warning level of 100.00 INR', $text, 'the level, in the currency');
        $this->assertStringContainsString(self::SITE.'/console/wallet', $text);
    }

    public function test_an_owner_who_signed_up_with_a_phone_gets_no_email_and_the_others_still_do(): void
    {
        $cy = User::create(['name' => 'Cy', 'email' => null, 'password' => 'a long password 7']);
        $cy->forceFill(['phone' => '+919876543210', 'phone_verified_at' => now()])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $cy->id, 'role' => 'owner']);
        $this->defaultLevel('100');
        $this->credit('150');
        $this->charge('60');
        $this->assertSame([$this->low('90.00 INR')], $this->sent(), 'Ana only: Cy has no email');
    }

    public function test_running_out_sends_the_out_of_credit_email_once_and_not_the_low_one_too(): void
    {
        $this->defaultLevel('100');
        $this->credit('150');
        $this->charge('200');
        $this->assertSame([$this->empty()], $this->sent(), 'past both levels at once: only the out-of-credit email');
        $this->assertStringContainsString('-50.00 INR', OutgoingEmail::query()->sole()->body['text']);

        $this->charge('1');
        $this->credit('30');
        $this->assertCount(1, $this->sent(), 'still at or below zero');

        $this->credit('50');
        $this->assertCount(1, $this->sent(), 'a top-up to below the level is not a new low-balance warning');
        $this->charge('40');
        $this->assertSame([$this->empty(), $this->empty()], $this->sent(), 'above zero again, then out again');
    }

    public function test_a_top_up_never_warns_even_one_that_leaves_the_balance_low_and_the_next_fall_does(): void
    {
        $this->defaultLevel('100');
        $this->credit('50');
        $this->credit('30');
        $this->assertSame([], $this->sent(), 'a first top-up to below the level, and another: no low-balance email');
        $this->charge('10');
        $this->assertSame([$this->low('70.00 INR')], $this->sent(), 'the next fall');

        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->charge('100');
        $this->acme->forceFill(['status' => Workspace::ACTIVE])->save();
        $this->credit('20');
        $this->assertCount(1, $this->sent(), 'a top-up that leaves it at or below zero: no out-of-credit email');
        $this->charge('1');
        $this->assertSame([$this->low('70.00 INR'), $this->empty()], $this->sent(), 'the next fall');
    }

    public function test_the_owner_chooses_the_account_level_and_zero_means_only_when_it_runs_out(): void
    {
        $this->defaultLevel('100');
        $this->actingAs($this->ana)->getJson('/api/kit/console/wallet')->assertOk()
            ->assertJsonPath('alert', ['lowBalanceAt' => null, 'defaultAt' => '100', 'mayChange' => true]);
        $this->actingAs($this->ben)->getJson('/api/kit/console/wallet')->assertOk()->assertJsonPath('alert.mayChange', false);
        $this->actingAs($this->ben)->putJson('/api/kit/console/wallet/alert', ['lowBalanceAt' => '500'])->assertForbidden()
            ->assertExactJson(['message' => __('wallet.not_allowed')]);
        foreach (['1.234', '-5', 'ten', 7] as $wrong) {
            $this->actingAs($this->ana)->putJson('/api/kit/console/wallet/alert', ['lowBalanceAt' => $wrong])->assertStatus(422)
                ->assertJsonPath('errors.lowBalanceAt.0', __('wallet.level_shape', ['places' => 2]));
        }

        $this->actingAs($this->ana)->putJson('/api/kit/console/wallet/alert', ['lowBalanceAt' => '500'])->assertOk()
            ->assertJsonPath('alert.lowBalanceAt', '500');
        $this->credit('600');
        $this->charge('150');
        $this->assertSame([$this->low('450.00 INR')], $this->sent(), 'its own level, not the default');

        $this->actingAs($this->ana)->putJson('/api/kit/console/wallet/alert', ['lowBalanceAt' => '0'])->assertOk();
        $this->credit('100');
        $this->charge('500');
        $this->assertCount(1, $this->sent(), 'zero: no low-balance email at 50');
        $this->charge('50');
        $this->assertSame([$this->low('450.00 INR'), $this->empty()], $this->sent(), 'the out-of-credit one still goes');

        $this->actingAs($this->ana)->putJson('/api/kit/console/wallet/alert', ['lowBalanceAt' => null])->assertOk()
            ->assertJsonPath('alert.lowBalanceAt', null);
        $this->assertNull($this->acme->refresh()->low_balance_at, 'back to the default');
    }

    public function test_nothing_is_sent_for_a_suspended_account_while_charging_is_off_or_with_no_level_until_it_runs_out(): void
    {
        $this->credit('150');
        $this->charge('100');
        $this->assertSame([], $this->sent(), 'no level anywhere: no low-balance email');

        $this->defaultLevel('100');
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->charge('10');
        $this->charge('100');
        $this->assertSame([], $this->sent(), 'suspended: nothing');
        $this->assertTrue(BigDecimal::of($this->acme->refresh()->balance)->isEqualTo('-60'), 'charged all the same');

        $this->acme->forceFill(['status' => Workspace::ACTIVE])->save();
        $this->charge('1');
        $this->assertSame([$this->empty()], $this->sent(), 'active again: the email it had not had');

        $this->credit('200');
        app(Settings::class)->forget('pricing.default_plan');
        $this->charge('200');
        $this->assertCount(1, $this->sent(), 'charging switched off: nothing');
        $this->assertSame(5, WalletEntry::query()->where('kind', 'charge')->count());
    }

    public function test_the_default_level_is_in_the_currency_and_only_money_staff_set_it(): void
    {
        $this->actingAs($this->staff)->putJson('/api/kit/admin/pricing/low-balance', ['lowBalanceAt' => '10.005'])->assertStatus(422)
            ->assertJsonPath('errors.lowBalanceAt.0', __('wallet.level_shape', ['places' => 2]));
        $this->defaultLevel('250.50');
        $this->actingAs($this->staff)->getJson('/api/kit/admin/pricing')->assertOk()->assertJsonPath('lowBalanceAt', '250.50');
        $this->defaultLevel(null);

        $support = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'a long password 7', 'role' => 'support']);
        $this->actingAs($support)->putJson('/api/kit/admin/pricing/low-balance', ['lowBalanceAt' => '5'])->assertForbidden();
        $this->actingAs($this->ana)->putJson('/api/kit/admin/pricing/low-balance', ['lowBalanceAt' => '5'])->assertForbidden();
    }
}
