<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Kit\Wallet\SpendLimit;
use App\Models\Agent;
use App\Models\Call;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * The wallet (App\Kit\Wallet): the operator credits a customer; a final call is charged on the customer's plan once
 * Telenow has rated it — once, at the engine's exact price — and never when Telenow never rates it; a call no list can
 * price waits for the list to be fixed; after each entry the spend limit at Telenow is set from the balance (the hard
 * stop: at zero it is the lifetime total, and the kit's own notice and test-call refusal say so in its words). The
 * customer sees its balance and entries; the operator also sees Telenow's cost and the margin. Telenow answers from
 * real recordings (contract_k1money.py, contract_k1calls.py).
 */
class WalletTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const OPERATOR = 'vai_test_operator_admin';

    private const SUB = '/api/v1/sub-accounts/3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private User $staff;

    private User $ana;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', self::OPERATOR, secret: true);
        $settings->put('telenow.events_secrets', ['current' => RecordedTelenow::event('call.completed.answered')['secret'], 'previous' => null], secret: true);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => $this->standard()]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced'])->save();
        RecordedTelenow::fake();
    }

    private function standard(array $rules = [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']]]): array
    {
        return ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => $rules];
    }

    private function answering(\Closure $first): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $first($r) ?? $recorded($r));
    }

    /** @return list<array> the spend limits sent to Telenow, in order */
    private function limitsSent(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'PATCH' && parse_url($r->url(), PHP_URL_PATH) === self::SUB)
            ->map(fn (array $pair) => json_decode($pair[0]->body(), true))->values()->all();
    }

    private function credit(string $amount, ?string $reference = null): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/api/kit/admin/customers/{$this->acme->id}/wallet/credits",
            ['amount' => $amount, 'note' => 'Bank transfer', 'reference' => $reference ?? (string) Str::uuid()]);
    }

    private function deliver(string $name): void
    {
        $e = RecordedTelenow::event($name);
        $server = [];
        foreach ($e['headers'] as $h => $v) {
            $server[$h === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $h))] = $v;
        }
        $this->call('POST', '/api/kit/telenow/events', [], [], [], $server, $e['body'])->assertNoContent();
    }

    private function answered(): Call
    {
        return Call::query()->where('telenow_id', RecordedTelenow::body('getCall.answered')['data']['id'])->sole();
    }

    public function test_a_final_call_is_charged_once_on_its_plan_and_the_limit_follows_the_balance(): void
    {
        $this->credit('100')->assertCreated()->assertJsonPath('amount', '100')->assertJsonPath('balanceAfter', '100');
        $this->assertSame([['limits' => ['totalSpendLimitUsd' => 0.08998]]], $this->limitsSent(),
            'Telenow total 0.029395 + 100 ÷ (83 × 1.40) − 0.10 USD/min × 2 calls × 240 s');
        $this->assertSame('0.089980', $this->acme->fresh()->spend_limit_usd);

        $this->deliver('call.completed.answered');
        $call = $this->answered();
        $this->assertSame(['final', 'pending'], [$call->detail_state, $call->charge_state]);
        $this->artisan('kit:wallet')->assertSuccessful();
        $call->refresh();
        $this->assertSame(['charged', '3.42', 'INR'], [$call->charge_state, $call->charge_amount, $call->charge_currency]);
        $entry = WalletEntry::query()->where('kind', 'charge')->sole();
        $this->assertSame(['-3.42', '96.58', $call->id], [$entry->amount, $entry->balance_after, $entry->reference]);
        $this->assertSame(['component:llm', 'component:platformFee', 'component:stt', 'component:telephony', 'component:tts'], array_column($entry->details['lines'], 'subject'));
        // the rate as the engine writes it: its shortest form (contract/pricing/README.md step 7)
        $this->assertSame(['0.0293950000', '83', 'standard'], [$entry->details['telenow']['totalUsd'], $entry->details['usdRate'], $entry->details['plan']]);
        [$cost] = Http::recorded(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), "/calls/{$call->telenow_id}/cost"))->map(fn ($p) => $p[0])->values()->all();
        $this->assertSame([self::KEY], $cost->header('X-API-Key'), "the customer's own key");
        $this->assertSame(['totalSpendLimitUsd' => 0.57731], $this->limitsSent()[1]['limits'],
            'its own ratio now: 3.42 ÷ 0.029395 USD; its own cost: 0.029395 USD per 50 s');

        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame(1, WalletEntry::query()->where('kind', 'charge')->count(), 'once');
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}")->assertJsonPath('charge', ['amount' => '3.42', 'currency' => 'INR']);
    }

    public function test_a_call_telenow_never_rates_is_never_charged(): void
    {
        config(['kit.wallet.charge_max_reads' => 2]);
        $this->deliver('call.completed.unanswered');
        $call = Call::query()->sole();
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame(['pending', 1], [$call->fresh()->charge_state, $call->fresh()->charge_reads]);
        $this->travel(config('kit.wallet.charge_base_seconds'))->seconds();
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame('unrated', $call->fresh()->charge_state);
        $this->assertSame(0, WalletEntry::query()->count());
    }

    public function test_a_call_its_plan_cannot_price_waits_for_the_list_to_be_fixed(): void
    {
        PriceList::query()->whereKey('standard')->update(['definition' => json_encode($this->standard([
            ['id' => 'lines', 'match' => ['item' => 'component', 'component' => 'telephony'], 'price' => ['perMinute' => '0.90']],
        ]))]);
        $this->deliver('call.completed.answered');
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame(['refused', 'unpriced_component: component:llm'], [$this->answered()->charge_state, $this->answered()->charge_error]);
        $this->actingAs($this->staff)->getJson("/api/kit/admin/customers/{$this->acme->id}/wallet")->assertJsonPath('calls', ['waiting' => 0, 'unpriced' => 1]);

        $this->actingAs($this->staff)->putJson('/api/kit/admin/pricing/lists/standard', ['name' => 'Standard', 'definition' => $this->standard()])->assertOk();
        $this->assertSame('pending', $this->answered()->charge_state, 'tried again');
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame('charged', $this->answered()->charge_state);
    }

    public function test_a_used_up_balance_stops_the_customer_at_telenow_and_in_the_console(): void
    {
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame([['limits' => ['totalSpendLimitUsd' => 0.029395]]], $this->limitsSent(), "no balance: the limit is Telenow's total");
        $this->actingAs($this->ana)->getJson('/api/kit/console/home')->assertJsonPath('paused', ['reason' => 'out_of_credit', 'message' => __('console.paused.out_of_credit')]);

        $agent = Agent::query()->sole();
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/test-call")->assertStatus(409)
            ->assertExactJson(['message' => __('console.paused.out_of_credit')]);
        $this->assertSame([], Http::recorded(fn (Request $r) => str_contains($r->url(), 'init-web-call'))->all(), 'Telenow never asked');

        $this->credit('50')->assertCreated();
        $this->actingAs($this->ana)->getJson('/api/kit/console/home')->assertJsonPath('paused', null);
    }

    public function test_the_limit_keeps_room_for_the_calls_under_way(): void
    {
        $limit = app(SpendLimit::class);
        $call = (new Call)->forceFill(['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(), 'duration_sec' => 120]);
        $call->save();
        $entry = new WalletEntry;
        $entry->forceFill(['workspace_id' => $this->acme->id, 'kind' => 'charge', 'reference' => $call->id, 'amount' => '-10.00', 'currency' => 'INR',
            'balance_after' => '50', 'details' => ['telenow' => ['totalUsd' => '0.1'], 'durationSec' => 120, 'usdRate' => '83.00']])->save();
        $this->acme->forceFill(['balance' => '50'])->save();
        // ratio 10 ÷ 0.1 = 100; cost 0.1 USD per 2 min = 0.05/min; room 0.05 × 3 × 240 s = 0.6 USD > 50 ÷ 100 = 0.5
        $this->assertSame('7.000000', $limit->compute($this->acme, '7', 3));
        $this->acme->forceFill(['balance' => '500'])->save();
        $this->assertSame('11.400000', $limit->compute($this->acme, '7', 3), '7 + 500 ÷ 100 − 0.6');
        $this->assertSame('11.600000', $limit->compute($this->acme, '7', null), 'its own concurrency unknown: assumed_concurrency (2) → 0.4');
        $this->acme->forceFill(['balance' => '-3.10'])->save();
        $this->assertSame('7.000000', $limit->compute($this->acme, '7', 3), 'below zero: stopped');
    }

    public function test_before_any_charge_the_plans_highest_markup_stands_in_for_the_ratio(): void
    {
        PriceList::query()->whereKey('standard')->update(['definition' => json_encode($this->standard([
            ['id' => 'ai', 'match' => ['item' => 'component', 'component' => 'llm'], 'price' => ['markupPercent' => '40']],
            ['id' => 'rest', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '80']],
            ['id' => 'rent', 'match' => ['item' => 'fee'], 'price' => ['markupPercent' => '300']],
        ]))]);
        // 83 × (100 + 80) / 100: the highest call markup (a fee's never prices a call), so the balance allows the least
        $this->assertSame('149.4000000000', (string) app(SpendLimit::class)->ratio($this->acme));
        PriceList::query()->whereKey('standard')->update(['definition' => json_encode($this->standard([
            ['id' => 'flat', 'match' => ['item' => 'call'], 'price' => ['perMinute' => '2.50']],
        ]))]);
        $this->assertSame('83.0000000000', (string) app(SpendLimit::class)->ratio($this->acme), 'no markup at all: the rate alone');
    }

    public function test_credits_happen_once_and_only_from_staff_once_prices_are_set(): void
    {
        $ref = (string) Str::uuid();
        $this->credit('499.50', $ref)->assertCreated();
        $this->credit('499.50', $ref)->assertOk();
        $this->assertSame('499.50', $this->acme->fresh()->balance);
        foreach (['0', '0.00', '-5', '1.123456789', 'ten', '1e3'] as $bad) {
            $this->credit($bad)->assertStatus(422)->assertJsonPath('errors.amount', [__('wallet.amount')]);
        }
        $this->actingAs($this->ana)->postJson("/api/kit/admin/customers/{$this->acme->id}/wallet/credits", ['amount' => '5', 'reference' => (string) Str::uuid()])->assertForbidden();
        app(Settings::class)->put('pricing.default_plan', null);
        $this->credit('5')->assertStatus(409)->assertExactJson(['message' => __('wallet.not_in_force')]);
        $this->assertSame('499.50', $this->acme->fresh()->balance);
    }

    public function test_the_customer_sees_its_balance_and_entries_and_the_operator_also_its_cost_and_margin(): void
    {
        $this->credit('100');
        $this->deliver('call.completed.answered');
        $this->artisan('kit:wallet')->assertSuccessful();

        $mine = $this->actingAs($this->ana)->getJson('/api/kit/console/wallet')->assertOk()
            ->assertJsonPath('balance', '96.58')->assertJsonPath('currency', 'INR')->assertJsonPath('total', 2)
            ->assertJsonPath('entries.0.amount', '-3.42')->assertJsonPath('entries.0.callId', $this->answered()->id)
            ->assertJsonPath('entries.1.kind', 'credit');
        foreach (['0.029395', 'totalUsd', 'telenow', 'margin', 'usdRate'] as $never) {
            $this->assertStringNotContainsString($never, $mine->getContent());
        }

        $this->actingAs($this->staff)->getJson("/api/kit/admin/customers/{$this->acme->id}/wallet")->assertOk()
            ->assertJsonPath('balance', '96.58')->assertJsonPath('effectivePlan', 'standard')->assertJsonPath('spendLimitUsd', '0.577310')
            ->assertJsonPath('entries.0.telenowCostUsd', '0.0293950000')
            ->assertJsonPath('entries.0.margin', '0.98', '3.42 − 0.029395 × 83.00 (2.44)');
        $this->actingAs($this->staff)->getJson("/api/kit/admin/customers/{$this->acme->id}")->assertJsonPath('balance', '96.58')->assertJsonPath('priceListId', null);
    }

    public function test_a_customer_moves_to_another_plan(): void
    {
        PriceList::create(['id' => 'premium', 'name' => 'Premium', 'definition' => ['id' => 'premium'] + $this->standard([
            ['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '80']],
        ])]);
        $this->actingAs($this->staff)->putJson("/api/kit/admin/customers/{$this->acme->id}/plan", ['priceListId' => 'premium'])->assertOk()
            ->assertJsonPath('plan', 'premium')->assertJsonPath('effectivePlan', 'premium');
        $this->assertCount(1, $this->limitsSent(), 'what its balance allows changed with its prices');
        $this->actingAs($this->staff)->putJson("/api/kit/admin/customers/{$this->acme->id}/plan", ['priceListId' => 'nope'])->assertStatus(422);
        $this->actingAs($this->staff)->putJson("/api/kit/admin/customers/{$this->acme->id}/plan", ['priceListId' => null])->assertOk()
            ->assertJsonPath('plan', null)->assertJsonPath('effectivePlan', 'standard');
    }

    public function test_calls_that_ended_before_prices_were_set_are_never_charged(): void
    {
        app(Settings::class)->put('pricing.default_plan', null);
        $this->deliver('call.completed.answered');
        $this->assertSame('not_charged', $this->answered()->charge_state);
        app(Settings::class)->put('pricing.default_plan', 'standard');
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame('not_charged', $this->answered()->charge_state);
        $this->assertSame(0, WalletEntry::query()->count());
    }
}
