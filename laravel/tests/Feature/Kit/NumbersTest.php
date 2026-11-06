<?php

namespace Tests\Feature\Kit;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Kit\Wallet\SpendLimit;
use App\Models\Agent;
use App\Models\PhoneNumber;
use App\Models\PriceList;
use App\Models\TelenowFee;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Console → Numbers (App\Kit\Numbers) and number fees (App\Kit\Wallet\FeeCharges): numbers for sale priced on the
 * customer's plan (never the carrier or Telenow's prices); buying one with the customer's own key at the provider and
 * prices Telenow's listing gave — refused before Telenow when the balance does not cover it — and Telenow's refusals in
 * the kit's words; the agent that answers it; releasing it (one Telenow no longer has is released too); a later write
 * replaces one Telenow gave up on; the feed brings numbers changed at Telenow and the fees in its ledger, charged once on
 * the plan and never moving the spend limit's call figures. Real recordings (contract_k1numbers.py: Telenow's answers
 * are real, the carrier behind them was a stand-in).
 */
class NumbersTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const OPERATOR = 'vai_test_operator_admin';

    private const V = '/api/voice/numbers';

    private const LEDGER = '/api/orgs/3e1cd17a-d25e-40c4-b0b0-0694f052f6da/billing/ledger';

    private User $staff;

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', self::OPERATOR, secret: true);
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
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced'])->save();
        RecordedTelenow::fake();
    }

    /** Calls marked up 40 %; a number's setup 50.00 INR whatever it costs; other number fees 20 %: a rent of 5.25 USD is 5.25 × 83 × 1.2 = 522.90 INR. */
    private function standard(array $rules = [
        ['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']],
        ['id' => 'setup', 'match' => ['item' => 'fee', 'refKind' => 'number_setup'], 'price' => ['fixed' => '50.00']],
        ['id' => 'numbers', 'match' => ['item' => 'fee'], 'price' => ['markupPercent' => '20']],
    ]): array
    {
        return ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => $rules];
    }

    private function callsOnly(): void
    {
        PriceList::query()->whereKey('standard')->update(['definition' => json_encode($this->standard([
            ['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']],
        ]))]);
    }

    private function answering(\Closure $first): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $first($r) ?? $recorded($r));
    }

    private static function is(Request $r, string $method, string $path): bool
    {
        return $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path;
    }

    /** @return list<Request> */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => self::is($r, $method, $path))->map(fn (array $p) => $p[0])->values()->all();
    }

    /** @return array<string, string> a request's query */
    private static function queryOf(Request $r): array
    {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $q;
    }

    /** @return array<string, string> the query a recording was made with */
    private static function recordedQuery(string $name): array
    {
        $c = json_decode((string) file_get_contents(base_path("../contract/telenow-mock/cassettes/{$name}.json")), true);
        parse_str($c['request']['query'], $q);

        return $q;
    }

    private function buy(string $number = '+91 80456 71001', array $more = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson('/api/kit/console/numbers', ['number' => $number, 'country' => 'in'] + $more);
    }

    private function bought(): PhoneNumber
    {
        $this->acme->forceFill(['balance' => '1000'])->save();
        $this->buy()->assertCreated();

        return PhoneNumber::query()->sole();
    }

    private static function telenowNumber(): array
    {
        return RecordedTelenow::body('purchaseNumber.customer')['data']['number'];
    }

    public function test_numbers_for_sale_come_priced_on_the_customers_plan_never_with_the_carrier_or_telenows_prices(): void
    {
        $listed = $this->actingAs($this->ana)->getJson('/api/kit/console/numbers/available?country=in&pattern=8045')->assertOk();
        $offer = fn (string $n) => ['number' => $n, 'country' => 'India', 'region' => 'Karnataka', 'type' => 'local', 'voice' => true, 'sms' => false,
            'setupPrice' => ['amount' => '50.00', 'currency' => 'INR'], 'monthlyPrice' => ['amount' => '522.90', 'currency' => 'INR']];
        $listed->assertExactJson(['numbers' => [$offer('+918045671001'), $offer('+918045671002')]]);
        [$search] = $this->sent('GET', self::V.'/search');
        $this->assertSame([self::KEY], $search->header('X-API-Key'), "the customer's own key");
        $this->assertSame(['country' => 'IN', 'pattern' => '8045', 'limit' => (string) config('kit.numbers.search_limit')], self::queryOf($search), 'Telenow picks the carrier');
        foreach (['teleone', '5.25', '499'] as $never) {
            $this->assertStringNotContainsString($never, $listed->getContent());
        }

        PriceList::query()->delete();
        $this->actingAs($this->ana)->getJson('/api/kit/console/numbers/available?country=IN')->assertOk()
            ->assertJsonPath('numbers.0.setupPrice', null)->assertJsonPath('numbers.0.monthlyPrice', null);
    }

    public function test_the_search_is_checked_and_telenows_refusals_are_the_kits_words(): void
    {
        $search = fn (string $query) => $this->actingAs($this->ana)->getJson("/api/kit/console/numbers/available?{$query}");
        $search('country=India')->assertStatus(422)->assertJsonValidationErrors(['country']);
        $search('country=IN&pattern=80-45')->assertStatus(422)->assertJsonValidationErrors(['pattern']);
        $this->assertSame([], $this->sent('GET', self::V.'/search'));

        foreach ([[403, 409, 'numbers.cannot_buy_now'], [502, 503, 'numbers.search_unavailable']] as [$telenow, $status, $words]) {
            // Telenow's own text for a carrier not open to the account names the carrier
            $this->answering(fn (Request $r) => self::is($r, 'GET', self::V.'/search')
                ? Http::response(['success' => false, 'error' => 'Numbers on teleone are not available to this organization yet.'], $telenow) : null);
            $search('country=IN')->assertStatus($status)->assertExactJson(['message' => __($words)]);
        }
        $this->answering(fn (Request $r) => self::is($r, 'GET', self::V.'/search') ? Http::response(['success' => false, 'error' => 'Unsupported country ZZ'], 400) : null);
        $search('country=ZZ')->assertStatus(422)->assertJsonPath('errors', ['country' => [__('numbers.search_refused')]]);

        $this->acme->forceFill(['telenow_key' => null])->save();
        $search('country=IN')->assertStatus(409)->assertExactJson(['message' => __('numbers.account_not_ready')]);
    }

    public function test_a_number_is_bought_with_the_customers_key_at_the_provider_and_prices_telenows_listing_gave(): void
    {
        $this->acme->forceFill(['balance' => '1000', 'numbers_synced_at' => now()])->save();
        $made = $this->buy('+91 80456 71001', ['provider' => 'another', 'extras' => ['monthlyRent' => '0']])->assertCreated();
        $number = PhoneNumber::query()->sole();
        $telenow = self::telenowNumber();
        $made->assertExactJson([
            'id' => $number->id, 'number' => '+918045671001', 'country' => 'India', 'region' => 'Karnataka', 'type' => 'local', 'agent' => null,
            'monthlyPrice' => ['amount' => '522.90', 'currency' => 'INR'], 'nextRenewalAt' => Carbon::parse($telenow['next_renewal_at'])->toIso8601String(),
            'releasing' => false, 'syncState' => 'synced', 'syncError' => null, 'createdAt' => $number->created_at->toIso8601String(),
        ]);
        [$search] = $this->sent('GET', self::V.'/search');
        $this->assertSame('918045671001', self::queryOf($search)['pattern'], 'found again: its provider and prices are Telenow\'s');
        [$purchase] = $this->sent('POST', self::V.'/purchase');
        $this->assertSame([self::KEY], $purchase->header('X-API-Key'));
        $this->assertSame([$number->id], $purchase->header('Idempotency-Key'), 'a retried purchase cannot buy two numbers');
        $this->assertEquals(['number' => '918045671001', 'provider' => 'teleone', 'extras' => ['monthlyRent' => '499.00000', 'setupPrice' => '0.00000']],
            json_decode($purchase->body(), true), "never the browser's provider or prices");
        $this->assertSame([$telenow['id'], $telenow['updated_at']], [$number->telenow_id, $number->telenow_updated_at]);
        $this->assertNull($this->acme->fresh()->numbers_synced_at, 'its fees: on the next feed run');
        $this->actingAs($this->ana)->getJson('/api/kit/console/numbers')->assertOk()->assertJsonPath('numbers.0.id', $number->id)->assertJsonCount(1, 'numbers');
    }

    public function test_a_purchase_is_refused_before_telenow_when_it_cannot_be_charged(): void
    {
        $this->acme->forceFill(['balance' => '572.89'])->save();
        $this->buy()->assertStatus(409)->assertExactJson(['message' => __('numbers.not_enough_balance', ['amount' => '572.90', 'currency' => 'INR'])],
            'the setup and the first month');
        $this->buy('+91 99999 99999')->assertStatus(409)->assertExactJson(['message' => __('numbers.not_available')]);
        $this->callsOnly();
        $this->acme->forceFill(['balance' => '1000'])->save();
        $this->buy()->assertStatus(409)->assertExactJson(['message' => __('numbers.no_price')]);
        $this->buy('India')->assertStatus(409);
        $this->actingAs($this->ana)->postJson('/api/kit/console/numbers', ['number' => '+918045671001'])->assertStatus(422)->assertJsonValidationErrors(['country']);
        $this->assertSame([], $this->sent('POST', self::V.'/purchase'));
        $this->assertSame(0, PhoneNumber::query()->count());

        PriceList::query()->delete(); // pricing not in force: the operator is not charging, so nothing to cover
        $this->acme->forceFill(['balance' => '0'])->save();
        $this->buy()->assertCreated()->assertJsonPath('monthlyPrice', null)->assertJsonPath('syncState', 'synced');
    }

    public function test_a_purchase_telenow_refuses_is_said_in_the_kits_words_and_can_be_removed_without_telenow(): void
    {
        $this->acme->forceFill(['balance' => '1000'])->save();
        $notForSale = RecordedTelenow::body('purchaseNumber.not-for-sale');
        foreach ([[404, $notForSale, 'failed', 'numbers.not_available'], [403, ['success' => false, 'error' => 'Add funds on the Billing page; reward credits cannot buy numbers.'], 'failed', 'numbers.cannot_buy_now'],
            [503, ['success' => false, 'error' => 'carrier timeout'], 'pending', 'numbers.will_retry']] as [$status, $body, $state, $words]) {
            PhoneNumber::query()->delete();
            OutboxEntry::query()->delete();
            $this->answering(fn (Request $r) => self::is($r, 'POST', self::V.'/purchase') ? Http::response($body, $status) : null);
            $made = $this->buy()->assertCreated()->assertJsonPath('syncState', $state)->assertJsonPath('syncError', __($words));
            $this->assertStringNotContainsString('Billing', $made->getContent());
        }

        $this->answering(fn (Request $r) => self::is($r, 'POST', self::V.'/purchase') ? Http::response($notForSale, 404) : null);
        PhoneNumber::query()->delete();
        OutboxEntry::query()->delete();
        $failed = $this->buy()->json('id');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/numbers/{$failed}")->assertNoContent();
        $this->assertSame([], Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->all(), 'never bought: Telenow is not asked');
        $this->assertSame(__('numbers.cancelled'), OutboxEntry::query()->sole()->last_error);
        $this->actingAs($this->ana)->getJson('/api/kit/console/numbers')->assertExactJson(['numbers' => []]);
    }

    public function test_the_agent_that_answers_a_number_is_chosen_and_taken_away(): void
    {
        $number = $this->bought();
        $tid = $number->telenow_id;
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$number->id}/agent", ['agentId' => $this->agent->id])->assertOk()
            ->assertJsonPath('agent', ['id' => $this->agent->id, 'name' => 'Front desk'])->assertJsonPath('syncState', 'synced');
        [$assign] = $this->sent('POST', self::V."/{$tid}/assign-agent");
        $this->assertSame([self::KEY], $assign->header('X-API-Key'));
        $this->assertEquals(['agentId' => $this->agent->telenow_id], json_decode($assign->body(), true), "Telenow's agent id");

        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$number->id}/agent", ['agentId' => null])->assertOk()->assertJsonPath('agent', null);
        $this->assertCount(1, $this->sent('DELETE', self::V."/{$tid}/agent"));

        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = Agent::create(['workspace_id' => $other->id, 'name' => 'Theirs']);
        $theirs->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();
        $unsaved = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Draft']);
        foreach ([$theirs->id, $unsaved->id, 'not-an-agent'] as $bad) {
            $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$number->id}/agent", ['agentId' => $bad])->assertStatus(422)
                ->assertJsonPath('errors', ['agentId' => [__('numbers.unknown_agent')]]);
        }
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$number->id}/agent", [])->assertStatus(422)->assertJsonValidationErrors(['agentId']);

        $elsewhere = (new PhoneNumber)->forceFill(['workspace_id' => $other->id, 'e164' => '918045671002', 'telenow_id' => 'x', 'sync_state' => 'synced']);
        $elsewhere->save();
        foreach ([$elsewhere->id, 'not-a-number'] as $missing) {
            $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$missing}/agent", ['agentId' => null])->assertNotFound();
            $this->actingAs($this->ana)->deleteJson("/api/kit/console/numbers/{$missing}")->assertNotFound();
        }
        $this->assertCount(1, $this->sent('POST', self::V."/{$tid}/assign-agent"), 'nothing more sent');
    }

    public function test_a_number_still_being_bought_has_no_agent_yet(): void
    {
        $this->acme->forceFill(['balance' => '1000'])->save();
        $this->answering(fn (Request $r) => self::is($r, 'POST', self::V.'/purchase') ? Http::response(['success' => false, 'error' => 'timeout'], 503) : null);
        $id = $this->buy()->assertCreated()->json('id');
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$id}/agent", ['agentId' => $this->agent->id])->assertStatus(409)
            ->assertExactJson(['message' => __('numbers.not_bought_yet')]);
    }

    public function test_release_gives_the_number_back_and_one_telenow_no_longer_has_is_released_too(): void
    {
        $number = $this->bought();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/numbers/{$number->id}")->assertNoContent();
        [$release] = $this->sent('DELETE', self::V."/{$number->telenow_id}");
        $this->assertSame([self::KEY], $release->header('X-API-Key'));
        $this->assertSame('synced', $number->fresh()->sync_state);
        $this->assertNotNull($number->fresh()->released_at);
        $this->actingAs($this->ana)->getJson('/api/kit/console/numbers')->assertExactJson(['numbers' => []]);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/numbers/{$number->id}")->assertNotFound();

        $gone = RecordedTelenow::body('releaseNumber.gone');
        $number->refresh()->forceFill(['released_at' => null])->save();
        $this->answering(fn (Request $r) => self::is($r, 'DELETE', self::V."/{$number->telenow_id}") ? Http::response($gone, 404) : null);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/numbers/{$number->id}")->assertNoContent();
        $this->assertSame(['synced', true], [$number->fresh()->sync_state, $number->fresh()->released_at !== null], 'Telenow no longer has it: as good as released');
    }

    public function test_a_release_not_yet_through_stays_listed_and_a_later_write_replaces_one_telenow_gave_up_on(): void
    {
        $number = $this->bought();
        $this->answering(fn (Request $r) => self::is($r, 'DELETE', self::V."/{$number->telenow_id}") ? Http::response(['success' => false, 'error' => 'carrier timeout'], 503) : null);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/numbers/{$number->id}")->assertNoContent();
        $this->actingAs($this->ana)->getJson('/api/kit/console/numbers')->assertJsonCount(1, 'numbers')
            ->assertJsonPath('numbers.0.releasing', true)->assertJsonPath('numbers.0.syncState', 'pending')->assertJsonPath('numbers.0.syncError', __('numbers.will_retry'));

        // An assign Telenow gave up on (its agent was gone there) would hold the release back
        $number->forceFill(['released_at' => null, 'sync_state' => 'synced'])->save();
        OutboxEntry::query()->delete();
        $this->answering(fn (Request $r) => self::is($r, 'POST', self::V."/{$number->telenow_id}/assign-agent") ? Http::response(['success' => false, 'error' => 'Agent not found'], 404) : null);
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$number->id}/agent", ['agentId' => $this->agent->id])->assertOk()
            ->assertJsonPath('syncState', 'failed')->assertJsonPath('syncError', __('numbers.not_done'));
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/numbers/{$number->id}")->assertNoContent();
        $this->assertCount(1, $this->sent('DELETE', self::V."/{$number->telenow_id}"), 'sent, not held behind the given-up assign');
        $this->assertSame([__('numbers.replaced'), null], OutboxEntry::query()->orderBy('id')->pluck('last_error')->all());
        $this->assertSame('synced', $number->fresh()->sync_state);
    }

    public function test_the_feed_brings_numbers_changed_at_telenow_and_the_fees_in_its_ledger(): void
    {
        // The account was made before the recording's changes: it read from where the recording did.
        $from = self::recordedQuery('listNumbers.feed')['updatedAfter'];
        $this->acme->forceFill(['created_at' => Carbon::parse($from)])->save();
        $this->artisan('kit:numbers')->assertSuccessful();
        [$feed] = $this->sent('GET', self::V);
        $this->assertSame([[self::KEY], ['updatedAfter' => $from]], [$feed->header('X-API-Key'), self::queryOf($feed)]);
        $telenow = RecordedTelenow::body('listNumbers.feed')['data']['numbers'][0];
        $number = PhoneNumber::query()->sole();
        $this->assertSame([$telenow['id'], '918045671001', 'India', 'local', null, 'synced', null],
            [$number->telenow_id, $number->e164, $number->country, $number->number_type, $number->agent_id, $number->sync_state, $number->monthly_price]);
        $this->assertSame($telenow['updated_at'], $this->acme->fresh()->numbers_after, 'read on from the newest change seen');

        $ledger = RecordedTelenow::body('listLedger.customer-feed')['data'];
        [$first, $next] = $this->sent('GET', self::LEDGER);
        $this->assertSame([['updatedAfter' => $from], ['cursor' => $ledger['nextCursor']]], [self::queryOf($first), self::queryOf($next)], 'read on until a page is empty');
        $byKind = collect($ledger['entries'])->keyBy('refKind');
        $this->assertSame(['rental', 'session'], $byKind->keys()->sort()->values()->all(), "the page holds a call's charge beside the rent");
        $fee = TelenowFee::query()->sole(); // a call's charge comes with the call, never as a fee
        $this->assertSame([$byKind['rental']['id'], 'rental', $telenow['id'], '-5.2500000000', 'pending'],
            [$fee->id, $fee->ref_kind, $fee->ref_id, $fee->amount_usd, $fee->charge_state]);
        $this->assertNotNull($this->acme->fresh()->numbers_synced_at);

        $this->artisan('kit:numbers')->assertSuccessful();
        $this->assertCount(1, $this->sent('GET', self::V), 'read again only after feed_minutes');
        $this->travel(config('kit.numbers.feed_minutes'))->minutes();
        $this->answering(fn (Request $r) => self::is($r, 'GET', self::V) ? Http::response(RecordedTelenow::body('listNumbers.feed-released')) : null);
        $this->artisan('kit:numbers')->assertSuccessful();
        [$again] = $this->sent('GET', self::V);
        $this->assertSame(['updatedAfter' => $telenow['updated_at']], self::queryOf($again));
        $this->assertSame(['cursor' => $ledger['nextCursor']], self::queryOf($this->sent('GET', self::LEDGER)[0]));
        $this->assertNotNull($number->fresh()->released_at, 'released at Telenow');
        $this->actingAs($this->ana)->getJson('/api/kit/console/numbers')->assertExactJson(['numbers' => []]);
        $this->assertSame(1, TelenowFee::query()->count(), 'once');
    }

    public function test_the_feed_leaves_a_number_the_kit_is_changing_alone(): void
    {
        $number = $this->bought();
        $number->forceFill(['sync_state' => 'pending', 'agent_id' => $this->agent->id])->save();
        $this->artisan('kit:numbers')->assertSuccessful();
        $this->assertSame(['pending', $this->agent->id], [$number->fresh()->sync_state, $number->fresh()->agent_id]);
    }

    public function test_a_number_fee_is_charged_once_on_the_plan_shown_with_its_number_and_never_moves_the_call_figures(): void
    {
        $number = $this->bought();
        $limit = app(SpendLimit::class);
        [$ratio, $perMinute] = [(string) $limit->ratio($this->acme), (string) $limit->costPerMinute($this->acme)];
        $this->artisan('kit:numbers')->assertSuccessful();
        $this->artisan('kit:wallet')->assertSuccessful();
        $fee = TelenowFee::query()->sole();
        $this->assertSame('charged', $fee->charge_state);
        $entry = WalletEntry::query()->where('kind', 'charge')->sole();
        $this->assertSame(['-522.90', '477.10', $fee->id], [$entry->amount, $entry->balance_after, $entry->reference]);
        $this->assertSame(['fee:rental'], array_column($entry->details['lines'], 'subject'));
        $this->assertSame(['5.2500000000', 'rental'], [$entry->details['telenow']['totalUsd'], $entry->details['fee']['refKind']], 'kept as the cost it is');
        $this->assertSame([$ratio, $perMinute], [(string) $limit->ratio($this->acme->fresh()), (string) $limit->costPerMinute($this->acme->fresh())],
            'a number fee has no minutes and the limit stops calls');

        $this->actingAs($this->ana)->getJson('/api/kit/console/wallet')->assertOk()
            ->assertJsonPath('entries.0.numberId', $number->id)->assertJsonPath('entries.0.callId', null);
        $this->actingAs($this->staff)->getJson("/api/kit/admin/customers/{$this->acme->id}/wallet")->assertOk()->assertJsonPath('entries.0.numberId', $number->id);

        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame(1, WalletEntry::query()->where('kind', 'charge')->count(), 'once');
    }

    public function test_a_fee_its_plan_cannot_price_waits_for_the_list_to_be_fixed(): void
    {
        $this->callsOnly();
        $this->artisan('kit:numbers')->assertSuccessful();
        $this->artisan('kit:wallet')->assertSuccessful();
        $fee = TelenowFee::query()->sole();
        $this->assertSame(['refused', 'unpriced_fee: fee:rental'], [$fee->charge_state, $fee->charge_error]);

        $this->actingAs($this->staff)->putJson('/api/kit/admin/pricing/lists/standard', ['name' => 'Standard', 'definition' => $this->standard()])->assertOk();
        $this->assertSame('pending', $fee->fresh()->charge_state, 'tried again');
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame('charged', $fee->fresh()->charge_state);
    }

    public function test_a_fee_telenow_charged_while_pricing_was_not_in_force_is_never_charged(): void
    {
        $definition = PriceList::query()->find('standard')->definition;
        PriceList::query()->delete();
        $this->artisan('kit:numbers')->assertSuccessful();
        $this->assertSame('not_charged', TelenowFee::query()->sole()->charge_state);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => $definition]);
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame(0, WalletEntry::query()->count());
    }
}
