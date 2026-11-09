<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Kit\Wallet\Wallet;
use App\Models\Call;
use App\Models\PriceList;
use App\Models\TelenowFee;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin → Dashboard and Admin → Cost vs price (App\Kit\Reports, App\Kit\Wallet\ChargeRollups): each charge adds to its
 * day's totals once, in the price's currency at the charge's own rate, and a rebuild from the ledger writes the same;
 * Cost vs price groups the period's charges by customer, by what was charged, by plan and by day, exactly, with the
 * margin; the dashboard adds up the period's charges, top-ups and credits by hand, counts customers and calls (live
 * ones only while they can still be live), shows the operator's Telenow account as last read and what needs a look;
 * both are the staff's alone and refuse a period that is backwards or too long.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Workspace $acme;

    private Workspace $bolt;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        // Charging asks Telenow for each customer's spend limit at once: here Telenow is down, so it only waits.
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['message' => 'down'], 503)]);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        PriceList::create(['id' => 'base', 'name' => 'Pay as you go', 'definition' => ['id' => 'base', 'currency' => 'INR', 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => []]]);
        // Customers since before the reports' month.
        $this->travelTo('2026-09-01 09:00:00');
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => (string) Str::uuid()])->save();
        $this->bolt = Workspace::create(['name' => 'Bolt Clinics', 'status' => 'active', 'sync_state' => 'synced']);
        $this->bolt->forceFill(['telenow_sub_account_id' => (string) Str::uuid()])->save();
        $this->travelBack();
    }

    /** A call charged on `$at`, with its priced lines and what Telenow charged. */
    private function charge(Workspace $w, string $at, string $total, string $costUsd, string $rate, array $lines, string $plan = 'base', ?Call $call = null): Call
    {
        $this->travelTo($at);
        $call ??= Call::forceCreate(['workspace_id' => $w->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'started_at' => now(), 'duration_sec' => 90]);
        app(Wallet::class)->charge($w, $call, ['total' => $total, 'currency' => 'INR', 'plan' => $plan, 'usdRate' => $rate, 'lines' => $lines], ['totalChargeUsd' => $costUsd]);
        $this->travelBack();

        return $call;
    }

    private function line(string $subject, string $costUsd, string $amount): array
    {
        return ['subject' => $subject, 'rule' => 'x', 'method' => 'markupPercent', 'costUsd' => $costUsd, 'billedSeconds' => null, 'amount' => $amount];
    }

    private function rollups(): array
    {
        return DB::table('charge_rollups')->orderBy('day')->orderBy('workspace_id')->orderBy('subject')
            ->get(['day', 'workspace_id', 'plan', 'subject', 'currency', 'count', 'price', 'cost_usd', 'cost'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /** The usual month: two customers, two days, two plans, and a charge before the month. */
    private function month(): void
    {
        $this->charge($this->acme, '2026-10-05 10:00:00', '12.50', '0.10', '83.25', [$this->line('component:llm', '0.04', '5.00'), $this->line('component:telephony', '0.06', '7.50')]);
        $this->charge($this->acme, '2026-10-06 10:00:00', '5.00', '0.02', '83.25', [$this->line('component:newthing', '0.02', '5.00')], 'pro');
        $this->charge($this->bolt, '2026-10-06 11:00:00', '30.00', '0.20', '83.25', [$this->line('call', '0.20', '30.00')]);
        $this->charge($this->bolt, '2026-09-30 23:00:00', '100.00', '1.00', '83.25', [$this->line('call', '1.00', '100.00')]);
    }

    private function costs(array $query): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)->getJson('/api/kit/admin/costs?'.http_build_query($query));
    }

    public function test_each_charge_adds_to_its_days_totals_once_and_a_rebuild_writes_the_same(): void
    {
        $call = $this->charge($this->acme, '2026-10-05 10:00:00', '12.50', '0.10', '83.25',
            [$this->line('component:llm', '0.04', '5.00'), $this->line('component:telephony', '0.06', '7.50')]);
        $whole = fn () => collect($this->rollups())->firstWhere('subject', '');
        $this->assertSame(['day' => '2026-10-05', 'workspace_id' => $this->acme->id, 'plan' => 'base', 'subject' => '', 'currency' => 'INR',
            'count' => 1, 'price' => '12.50', 'cost_usd' => '0.10', 'cost' => '8.3250000000'], $whole());
        $this->assertSame(['component:llm', 'component:telephony'], array_values(array_filter(array_column($this->rollups(), 'subject'))));

        // The same charge again is not a new charge.
        $this->charge($this->acme, '2026-10-05 11:00:00', '12.50', '0.10', '83.25', [$this->line('component:llm', '0.04', '5.00')], call: $call);
        $this->assertSame(1, $whole()['count']);

        // Another charge that day, at another rate: its cost converted at its own rate.
        $this->charge($this->acme, '2026-10-05 12:00:00', '6.00', '0.05', '90', [$this->line('component:llm', '0.05', '6.00')]);
        $this->assertSame(['count' => 2, 'price' => '18.50', 'cost_usd' => '0.15', 'cost' => '12.8250000000'],
            array_intersect_key($whole(), array_flip(['count', 'price', 'cost_usd', 'cost'])));

        // A number fee, and a credit (not a charge: no totals).
        $this->travelTo('2026-10-05 13:00:00');
        $fee = TelenowFee::forceCreate(['id' => (string) Str::uuid(), 'workspace_id' => $this->acme->id, 'ref_kind' => 'rental', 'ref_id' => 'n1',
            'amount_usd' => '-2.00', 'entry' => ['note' => null, 'createdAt' => now()->toIso8601String()], 'charge_state' => 'pending']);
        app(Wallet::class)->chargeFee($this->acme, $fee, ['total' => '199.00', 'currency' => 'INR', 'plan' => 'base', 'usdRate' => '83.25',
            'lines' => [$this->line('fee:rental', '2.00', '199.00')]]);
        app(Wallet::class)->credit($this->acme, '500', 'INR', 'goodwill', $this->staff, 'credit-1');
        $this->travelBack();
        $this->assertSame('217.50', $whole()['price']);
        $this->assertSame(1, collect($this->rollups())->firstWhere('subject', 'fee:rental')['count']);

        $before = $this->rollups();
        DB::table('charge_rollups')->update(['price' => '0', 'count' => 0]);
        $this->artisan('kit:rollups-rebuild')->expectsOutput(__('reports.rebuilt', ['count' => 3]))->assertSuccessful();
        $this->assertSame($before, $this->rollups());
    }

    public function test_cost_vs_price_groups_the_periods_charges_exactly(): void
    {
        $this->month();
        $october = ['from' => '2026-10-01', 'to' => '2026-10-31'];

        $byCustomer = $this->costs($october + ['by' => 'customer'])->assertOk()->assertJsonPath('total', 2)->assertJsonPath('by', 'customer');
        $this->assertSame([
            ['key' => $this->bolt->id, 'label' => 'Bolt Clinics', 'currency' => 'INR', 'count' => 1, 'price' => '30.00', 'cost' => '16.65', 'costUsd' => '0.2000', 'margin' => '13.35', 'marginPercent' => '44.5'],
            ['key' => $this->acme->id, 'label' => 'Acme Dental', 'currency' => 'INR', 'count' => 2, 'price' => '17.50', 'cost' => '9.99', 'costUsd' => '0.1200', 'margin' => '7.51', 'marginPercent' => '42.9'],
        ], $byCustomer->json('rows'));
        $this->assertSame([['currency' => 'INR', 'count' => 3, 'price' => '47.50', 'cost' => '26.64', 'costUsd' => '0.3200', 'margin' => '20.86', 'marginPercent' => '43.9']],
            $byCustomer->json('totals'));
        $this->assertSame(config('kit.reports.groups'), array_column($byCustomer->json('groups'), 'value'));

        $byComponent = $this->costs($october + ['by' => 'component'])->assertOk();
        $this->assertSame(['call' => __('reports.subjects.call'), 'component:telephony' => __('reports.components.telephony'),
            'component:llm' => __('reports.components.llm'), 'component:newthing' => 'component:newthing'],
            array_column($byComponent->json('rows'), 'label', 'key'));
        $this->assertSame('47.50', $byComponent->json('totals.0.price'), 'the totals are the charges, whichever the grouping');

        $this->assertSame(['base' => 'Pay as you go', 'pro' => 'pro'], array_column($this->costs($october + ['by' => 'plan'])->json('rows'), 'label', 'key'));
        $this->assertSame(['2026-10-05', '2026-10-06'], array_column($this->costs($october + ['by' => 'day'])->json('rows'), 'key'));
        $this->assertSame(['12.50', '35.00'], array_column($this->costs($october + ['by' => 'day'])->json('rows'), 'price'));
        $this->assertSame('100.00', $this->costs(['from' => '2026-09-30', 'to' => '2026-09-30', 'by' => 'day'])->json('rows.0.price'));

        config(['kit.admin.per_page' => 1]);
        $this->costs($october + ['by' => 'customer', 'page' => 2])->assertJsonPath('rows.0.label', 'Acme Dental')->assertJsonPath('total', 2)->assertJsonCount(1, 'rows');

        $this->travelTo('2026-10-20 09:00:00');
        $this->costs([])->assertOk()->assertJsonPath('period', ['from' => '2026-10-01', 'to' => '2026-10-20'])->assertJsonPath('by', config('kit.reports.groups')[0]);
        $this->travelBack();
    }

    public function test_one_customers_figures_by_what_was_charged(): void
    {
        $this->month();
        $october = ['from' => '2026-10-01', 'to' => '2026-10-31'];
        $acme = $this->costs($october + ['by' => 'component', 'customer' => $this->acme->id])->assertOk()
            ->assertJsonPath('customer', ['id' => $this->acme->id, 'name' => 'Acme Dental'])->assertJsonPath('total', 3);
        $this->assertSame(['component:telephony' => '7.50', 'component:llm' => '5.00', 'component:newthing' => '5.00'], array_column($acme->json('rows'), 'price', 'key'));
        $this->assertSame([['currency' => 'INR', 'count' => 2, 'price' => '17.50', 'cost' => '9.99', 'costUsd' => '0.1200', 'margin' => '7.51', 'marginPercent' => '42.9']],
            $acme->json('totals'), "the customer's totals, not everyone's");
        $this->assertSame(['call' => '30.00'], array_column($this->costs($october + ['by' => 'component', 'customer' => $this->bolt->id])->json('rows'), 'price', 'key'));
        $this->assertSame([$this->acme->id], array_column($this->costs($october + ['by' => 'customer', 'customer' => $this->acme->id])->json('rows'), 'key'));
        $this->costs($october + ['by' => 'component'])->assertJsonPath('customer', null)->assertJsonPath('totals.0.price', '47.50');
        foreach ([(string) Str::uuid(), 'acme'] as $unknown) {
            $this->costs($october + ['customer' => $unknown])->assertStatus(422)->assertJsonValidationErrors('customer');
        }
    }

    public function test_a_period_must_run_forwards_and_not_too_long_and_a_grouping_must_be_known(): void
    {
        $this->costs(['from' => '2026-10-10', 'to' => '2026-10-01'])->assertStatus(422)->assertJsonValidationErrors(['to']);
        $this->costs(['from' => '2026-10-1', 'to' => '2026-10-31'])->assertStatus(422)->assertJsonValidationErrors(['from']);
        config(['kit.reports.max_days' => 31]);
        $this->costs(['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk();
        $this->costs(['from' => '2026-10-01', 'to' => '2026-11-01'])->assertStatus(422)->assertJsonPath('errors.to.0', __('reports.too_long', ['days' => 31]));
        $this->actingAs($this->staff)->getJson('/api/kit/admin/dashboard?from=2026-10-01&to=2026-11-01')->assertStatus(422);
        $this->costs(['by' => 'nonsense'])->assertStatus(422)->assertJsonValidationErrors(['by']);
    }

    public function test_the_dashboard_adds_up_the_period_and_shows_what_needs_a_look(): void
    {
        $this->month();
        $gateway = (string) Str::uuid();
        DB::table('payment_gateways')->insert(['id' => $gateway, 'driver' => 'manual', 'name' => 'Bank', 'mode' => 'live', 'config' => '{}',
            'methods' => '[]', 'position' => 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        $payment = fn (string $status, ?string $paidAt, string $amount, ?string $received = null, string $refunded = '0') => DB::table('payments')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $this->acme->id, 'gateway_id' => $gateway, 'amount' => $amount, 'currency' => 'INR',
            'status' => $status, 'received' => $received, 'refunded' => $refunded, 'paid_at' => $paidAt, 'created_at' => now(), 'updated_at' => now()]);
        $payment('paid', '2026-10-07 10:00:00', '1000', '990', '100');
        $payment('paid', '2026-10-08 10:00:00', '500');
        $payment('paid', '2026-09-07 10:00:00', '700');
        $payment('open', null, '300');
        $payment('review', null, '250');
        $this->travelTo('2026-10-09 10:00:00');
        app(Wallet::class)->credit($this->acme, '200', 'INR', 'goodwill', $this->staff, 'by-hand');
        app(Wallet::class)->forPayment($this->bolt, 'credit', \Brick\Math\BigDecimal::of('900'), 'INR', 'pay-1', ['payment' => 'p1'], null);
        $this->travelBack();

        $this->bolt->forceFill(['status' => Workspace::SUSPENDED, 'sync_state' => Workspace::SYNC_FAILED])->save();
        $this->travelTo('2026-10-10 10:00:00');
        Workspace::create(['name' => 'New Co', 'status' => 'active', 'sync_state' => 'pending']);
        $this->travelBack();
        $this->travelTo('2026-10-31 12:00:00');
        Call::forceCreate(['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'active', 'started_at' => now()->subHour()]);
        Call::forceCreate(['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'active',
            'started_at' => now()->subHours(config('kit.reports.live_call_hours') + 1)]);
        Call::forceCreate(['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'started_at' => now()->subMinutes(5),
            'duration_sec' => 30, 'charge_state' => 'refused']);
        DB::table('telenow_outbox')->insert(['resource' => 'workspace', 'kit_id' => $this->acme->id, 'operation' => 'create', 'idempotency_key' => 'k',
            'failed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('telenow_events')->insert(['delivery_id' => 'd1', 'event' => 'call.completed', 'body' => '{}', 'failed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reconciliations')->insert(['workspace_id' => $this->acme->id, 'checked_at' => now(), 'telenow_spend_usd' => '2.00',
            'charged_cost_usd' => '1.00', 'drift_usd' => '1.00', 'created_at' => now(), 'updated_at' => now()]);
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'low', 'canSpend' => true, 'stoppedBy' => null, 'balanceUsd' => '4.50',
            'monthlySpendLimitUsd' => null, 'periodSpendUsd' => '35.50', 'periodStart' => '2026-09-30T18:30:00Z', 'headroomUsd' => '4.50', 'lowThresholdUsd' => '5']);
        $settings->put('telenow.status_checked_at', '2026-10-31T11:55:00+00:00');

        $d = $this->actingAs($this->staff)->getJson('/api/kit/admin/dashboard?from=2026-10-01&to=2026-10-31')->assertOk();
        $this->assertSame([['currency' => 'INR', 'count' => 3, 'price' => '47.50', 'cost' => '26.64', 'costUsd' => '0.3200', 'margin' => '20.86', 'marginPercent' => '43.9']], $d->json('money'));
        $this->assertSame([['currency' => 'INR', 'count' => 2, 'amount' => '1490', 'refunded' => '100']], $d->json('topUps'));
        $this->assertSame([['currency' => 'INR', 'count' => 1, 'amount' => '200']], $d->json('creditsByHand'));
        $this->assertSame(['total' => 3, 'active' => 2, 'suspended' => 1, 'notReady' => 1, 'outOfCredit' => null, 'new' => 1], $d->json('customers'));
        $this->assertSame(['count' => 6, 'seconds' => 300, 'live' => 1], $d->json('calls'));
        $this->assertSame(['callsNotCharged' => 1, 'failedWrites' => 1, 'paymentsToReview' => 1, 'customersNotSynced' => 1, 'eventsFailed' => 1,
            'customersDrifting' => 1, 'jobsNotRunning' => true, 'providersFailing' => 0], $d->json('attention'));
        DB::table('job_runs')->insert(['command' => 'kit:outbox', 'expression' => '* * * * *', 'started_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/dashboard?from=2026-10-01&to=2026-10-31')->assertJsonPath('attention.jobsNotRunning', false);
        $d->assertJsonPath('telenow.connected', true)->assertJsonPath('telenow.keyRejected', false)->assertJsonPath('telenow.billingUrl', config('kit.telenow.billing_url'))
            ->assertJsonPath('telenow.account.state', 'low')->assertJsonPath('telenow.account.balanceUsd', '4.50')
            ->assertJsonPath('telenow.checkedAt', '2026-10-31T11:55:00+00:00');
        $this->travelBack();

        // With pricing in force, active customers with nothing left in their wallet are counted.
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.25');
        $settings->put('pricing.default_plan', 'base');
        $this->acme->forceFill(['balance' => '0'])->save();
        $this->actingAs($this->staff)->getJson('/api/kit/admin/dashboard?from=2026-10-01&to=2026-10-31')->assertJsonPath('customers.outOfCredit', 2);
    }

    public function test_reports_are_the_staffs_alone(): void
    {
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $paths = ['/api/kit/admin/dashboard', '/api/kit/admin/costs'];
        foreach ($paths as $path) {
            $this->getJson($path)->assertUnauthorized();
        }
        foreach ($paths as $path) {
            $this->actingAs($ana)->getJson($path)->assertForbidden();
            $this->actingAs($this->staff)->getJson($path)->assertOk();
        }
    }
}
