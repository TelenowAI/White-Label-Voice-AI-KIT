<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Settings\Settings;
use App\Kit\Softphone\BrowserCalls;
use App\Models\Agent;
use App\Models\Call;
use App\Models\ConsentRecord;
use App\Models\DncEntry;
use App\Models\PhoneNumber;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Calls a member places from the browser (App\Kit\Softphone\BrowserCalls) and the calls on now (App\Kit\Calls\LiveCalls), through the kit's real routes, against
 * Telenow's recorded answers (contract_k9human.py, the 11-03 release): it dials whatever it is given, words included, takes a caller ID only as it stores it, with
 * no +, says no by its own do-not-call list and consent with 403, a busy number with 429, and counts a browser call under a stand-in agent of its own.
 */
class PhoneTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private const SESSION = '5e551011-0000-4000-8000-000000000001';

    /** The kit agent the recorded live count names (the other id is Telenow's stand-in for browser calls). */
    private const AGENT = '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159';

    private User $ana;

    private User $ben;

    private User $cy;

    private User $staff;

    private Workspace $acme;

    private PhoneNumber $line;

    /** What each request answers instead of its recording, by method and how the path ends. */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->cy = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY, 'balance' => '50'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->cy->id, 'role' => 'member']);
        $this->line = $this->number($this->acme, '918045671001');
        $this->switches(browser: true, transfer: false);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(function (Request $r) use ($recorded) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            foreach ($this->answers as $which => $answer) {
                [$method, $end] = explode(' ', $which);
                if ($r->method() === $method && str_ends_with($path, $end)) {
                    return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
                }
            }

            return match (true) {
                $r->method() === 'POST' && $path === '/api/sessions/init-web-call' => RecordedTelenow::response('initWebCall.manual'),
                $r->method() === 'DELETE' && str_starts_with($path, '/api/sessions/') => RecordedTelenow::response('endSession.softphone'),
                $r->method() === 'POST' && str_ends_with($path, '/transfer') => RecordedTelenow::response('transferCall.softphone'),
                $r->method() === 'GET' && $path === '/api/agents/live-calls' => RecordedTelenow::response('countLiveCalls.mixed'),
                $r->method() === 'GET' && $path === '/api/orgs/'.self::ORG.'/calls' && ($r->data()['status'] ?? null) === 'active' => RecordedTelenow::response('listCalls.active-none'),
                default => $recorded($r),
            };
        });
    }

    private function number(Workspace $workspace, string $e164, array $over = []): PhoneNumber
    {
        $n = new PhoneNumber;
        $n->forceFill($over + ['workspace_id' => $workspace->id, 'e164' => $e164, 'telenow_id' => (string) \Illuminate\Support\Str::uuid(), 'sync_state' => 'synced'])->save();

        return $n;
    }

    private function switches(bool $browser, bool $transfer): void
    {
        app(Settings::class)->put(BrowserCalls::ALLOWED, $browser);
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, $transfer);
    }

    private function place(User $who, string $number = '+14155550101', ?string $from = null): TestResponse
    {
        return $this->actingAs($who)->postJson('/api/kit/console/phone/calls', ['callerId' => $from ?? $this->line->id, 'number' => $number]);
    }

    /** @return list<array{method: string, path: string, body: array}> what reached Telenow, but the recordings the kit reads anyway */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => ['method' => $pair[0]->method(), 'path' => (string) parse_url($pair[0]->url(), PHP_URL_PATH), 'body' => $pair[0]->data()])->values()->all();
    }

    private function pricing(array $rules): void
    {
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => $rules]]);
        $settings = app(Settings::class);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
    }

    public function test_none_is_placed_until_the_operator_allows_them(): void
    {
        $this->switches(browser: false, transfer: true);
        $this->actingAs($this->ben)->getJson('/api/kit/console/phone')->assertOk()->assertJsonPath('allowed', false)->assertJsonPath('transferAllowed', false);
        $this->place($this->ben)->assertForbidden()->assertExactJson(['message' => __('softphone.not_allowed')]);
        $this->assertSame([], $this->sent());
        $this->assertSame(0, Call::query()->count());
    }

    public function test_a_call_goes_from_the_accounts_number_as_telenow_stores_it_and_is_kept_at_once(): void
    {
        $answer = $this->place($this->ben, '+1 (415) 555-0101')->assertCreated();
        $call = Call::query()->sole();
        $answer->assertExactJson(['callId' => $call->id, 'session' => ['sessionId' => self::SESSION, 'websocketUrl' => 'ws://127.0.0.1:39897/ws/web-agent']]);
        $this->assertSame([['method' => 'POST', 'path' => '/api/sessions/init-web-call', 'body' => ['mode' => 'manual', 'fromNumber' => '918045671001', 'toNumber' => '+14155550101']]], $this->sent(),
            'the caller ID as Telenow stores it (with a + it answers 404: recorded), the number called with its +');
        $this->assertSame([self::KEY], Http::recorded()->first()[0]->header('X-API-Key'), "the account's own key");
        $this->assertSame(
            [$this->acme->id, self::SESSION, $this->ben->id, 'active', 'outbound', 'softphone', '918045671001', '+14155550101', '918045671001', '14155550101', 'pending', null],
            [$call->workspace_id, $call->telenow_id, (int) $call->placed_by, $call->status, $call->direction, $call->channel, $call->from_number, $call->to_number, $call->from_digits, $call->to_digits, $call->detail_state, $call->agent_id],
        );
        $this->assertTrue($call->next_read_at->isFuture(), 'read once it has had time to end');

        $this->actingAs($this->ben)->getJson('/api/kit/console/calls')->assertOk()
            ->assertJsonPath('calls.0.placedBy', ['name' => 'Ben', 'me' => true])->assertJsonPath('calls.0.agent', null)->assertJsonPath('calls.0.channel', 'softphone');
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}")->assertOk()->assertJsonPath('placedBy', ['name' => 'Ben', 'me' => false]);
    }

    public function test_words_and_a_number_without_its_country_code_are_never_dialled(): void
    {
        // Telenow places a call to "call me" (recorded: initWebCall.manual-not-a-number).
        foreach (['call me', '14155550101', '+1415', '', '+1 415 555 0101 ext 2'] as $typed) {
            $this->place($this->ben, $typed)->assertUnprocessable()->assertJsonValidationErrors(['number']);
        }
        $this->assertSame([], $this->sent());
        $this->assertSame(0, Call::query()->count());
    }

    public function test_only_the_accounts_own_numbers_known_to_the_voice_platform_call(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = $this->number($other, '918045671009');
        $released = $this->number($this->acme, '918045671002', ['released_at' => now()]);
        $unsaved = $this->number($this->acme, '918045671003', ['telenow_id' => null, 'sync_state' => 'pending']);
        foreach ([$theirs->id, $released->id, $unsaved->id, 'not-an-id'] as $from) {
            $this->place($this->ben, '+14155550101', $from)->assertUnprocessable()->assertJsonValidationErrors(['callerId']);
        }
        $this->assertSame([], $this->sent());
        $this->actingAs($this->ben)->getJson('/api/kit/console/phone')->assertOk()
            ->assertJsonPath('callerIds', [['id' => $this->line->id, 'number' => '+918045671001']]);
    }

    public function test_the_do_not_call_list_and_consent_say_no_before_the_voice_platform_is_asked(): void
    {
        foreach (['+14155550199', '14155550199'] as $stored) {
            $entry = new DncEntry(['workspace_id' => $this->acme->id, 'phone_number' => $stored]);
            $entry->forceFill(['workspace_id' => $this->acme->id, 'sync_state' => 'synced'])->save();
            $this->place($this->ben, '+14155550199')->assertUnprocessable()->assertJsonPath('errors.number', [__('softphone.on_dnc')]);
            $entry->forceFill(['deleted_at' => now()])->save();
        }
        $this->assertSame([], $this->sent());
        $this->place($this->ben, '+14155550199')->assertCreated();

        $this->acme->forceFill(['consent_required' => true])->save();
        $this->actingAs($this->ben)->getJson('/api/kit/console/phone')->assertJsonPath('consentRequired', true);
        $record = fn (array $over) => (new ConsentRecord)->forceFill($over + ['workspace_id' => $this->acme->id, 'phone_number' => '+14155550101', 'consent_type' => 'implied', 'scope' => 'marketing',
            'source' => 'paper', 'captured_at' => now()->subDay(), 'sync_state' => 'synced'])->save();
        $record(['revoked_at' => now()->subHour()]);
        $record(['expires_at' => now()->subMinute()]);
        $record(['captured_at' => now()->addDay()]);
        $record(['phone_number' => '14155550101']);
        $this->place($this->ben)->assertUnprocessable()->assertJsonPath('errors.number', [__('softphone.needs_consent')]);
        $this->assertCount(1, $this->sent(), 'withdrawn, ended, not begun, or another way of writing the number: none is consent');
        $record([]);
        $this->place($this->ben)->assertCreated();
    }

    public function test_the_voice_platforms_refusals_come_back_in_the_kits_words(): void
    {
        config(['kit.softphone.calls_per_minute' => 100]);
        $cases = [
            'initWebCall.manual-dnc' => [422, ['number' => [__('softphone.refused')]]],
            'initWebCall.manual-no-consent' => [422, ['number' => [__('softphone.refused')]]],
            'initWebCall.manual-number-busy' => [429, __('softphone.caller_id_busy')],
            'initWebCall.manual-foreign-number' => [422, ['callerId' => [__('softphone.caller_id_gone')]]],
            'initWebCall.manual-no-caller-id' => [503, __('softphone.unavailable')],
        ];
        foreach ($cases as $recording => [$status, $words]) {
            $this->answers = ['POST /init-web-call' => $recording];
            $answer = $this->place($this->ben)->assertStatus($status);
            is_array($words) ? $answer->assertJsonPath('errors', $words) : $answer->assertExactJson(['message' => $words]);
            $this->assertStringNotContainsString('organization', $answer->getContent(), $recording);
        }
        $this->answers = ['POST /init-web-call' => fn () => Http::failedConnection()];
        $this->place($this->ben)->assertStatus(503);
        $this->answers = ['POST /init-web-call' => fn () => Http::response(['success' => true, 'data' => ['status' => 'active']])];
        $this->place($this->ben)->assertStatus(503)->assertExactJson(['message' => __('softphone.unavailable')]);
        $this->assertSame(0, Call::query()->count(), 'none kept that was never placed');
    }

    public function test_a_paused_account_or_a_plan_with_no_price_for_one_places_none(): void
    {
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->place($this->ben)->assertStatus(409)->assertExactJson(['message' => __('console.paused.account_suspended')]);
        $this->actingAs($this->ben)->getJson('/api/kit/console/phone')->assertJsonPath('paused', __('console.paused.account_suspended'));
        $this->acme->forceFill(['status' => 'active'])->save();

        // Priced only for agents' calls: a browser call would go uncharged.
        $this->pricing([['id' => 'ai', 'match' => ['item' => 'component', 'callType' => 'ai'], 'price' => ['markupPercent' => '40']]]);
        $this->actingAs($this->ben)->getJson('/api/kit/console/phone')->assertJsonPath('priced', false);
        $this->place($this->ben)->assertStatus(409)->assertExactJson(['message' => __('softphone.not_priced')]);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertJsonPath('browserCallsUnpriced', [['id' => 'standard', 'name' => 'Standard']]);
        $this->assertSame([], $this->sent());

        PriceList::query()->whereKey('standard')->update(['definition' => json_encode(['id' => 'standard', 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60,
            'rules' => [['id' => 'phone', 'match' => ['item' => 'component', 'callType' => 'manual', 'channel' => 'softphone', 'component' => 'telephony'], 'price' => ['perMinute' => '2.50']]]])]);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertJsonPath('browserCallsUnpriced', []);
        $this->place($this->ben)->assertCreated();
    }

    public function test_a_browser_call_is_read_and_charged_as_the_voice_platform_rated_it(): void
    {
        // The recorded call never connected (0 s), so a price by the minute would make it free: the carrier's cost marked up shows it charged.
        $this->pricing([['id' => 'phone', 'match' => ['item' => 'component', 'callType' => 'manual', 'channel' => 'softphone', 'component' => 'telephony'], 'price' => ['markupPercent' => '40']]]);
        $this->place($this->ben)->assertCreated();
        $this->answers = [
            'GET /calls/'.self::SESSION => 'getCall.softphone-ended',
            'GET /calls/'.self::SESSION.'/cost' => 'getCallCost.softphone',
        ];
        $this->travel(2)->minutes();
        $this->artisan('kit:calls')->assertSuccessful();
        $call = Call::query()->where('telenow_id', self::SESSION)->sole();
        $this->assertSame(['ended', 'final', (int) $this->ben->id], [$call->status, $call->detail_state, (int) $call->placed_by], 'nothing to wait for: no recording, transcript or analysis on one');
        $this->artisan('kit:wallet')->assertSuccessful();
        $call->refresh();
        $this->assertSame(['charged', '3.09', 'INR'], [$call->charge_state, $call->charge_amount, $call->charge_currency], '0.0266 USD × 1.40 × 83');
        $entry = WalletEntry::query()->where('kind', 'charge')->sole();
        $this->assertSame([$call->id, ['component:telephony']], [$entry->reference, array_column($entry->details['lines'], 'subject')]);
    }

    public function test_only_who_placed_it_or_a_manager_ends_it_and_the_voice_platform_is_told(): void
    {
        $this->place($this->ben)->assertCreated();
        $call = Call::query()->sole();
        $this->actingAs($this->cy)->deleteJson("/api/kit/console/phone/calls/{$call->id}")->assertForbidden()->assertExactJson(['message' => __('softphone.not_yours')]);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/phone/calls/{$call->id}")->assertNoContent();
        $this->assertSame(['DELETE', '/api/sessions/'.self::SESSION], [$this->sent()[1]['method'], $this->sent()[1]['path']]);
        $this->assertFalse($call->fresh()->next_read_at->isFuture(), 'read on the next run');

        // One the voice platform no longer holds is over already.
        $this->answers = ['DELETE /'.self::SESSION => 'endSession.unknown'];
        $this->actingAs($this->ben)->deleteJson("/api/kit/console/phone/calls/{$call->id}")->assertNoContent();
        $this->answers = ['DELETE /'.self::SESSION => fn () => Http::failedConnection()];
        $this->actingAs($this->ben)->deleteJson("/api/kit/console/phone/calls/{$call->id}")->assertStatus(503)->assertExactJson(['message' => __('softphone.hang_up_failed')]);

        $call->forceFill(['status' => 'ended'])->save();
        $this->actingAs($this->ben)->deleteJson("/api/kit/console/phone/calls/{$call->id}")->assertStatus(409)->assertExactJson(['message' => __('softphone.call_over')]);
        $agentCall = (new Call)->forceFill(['workspace_id' => $this->acme->id, 'telenow_id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'active', 'channel' => 'telephony']);
        $agentCall->save();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/phone/calls/{$agentCall->id}")->assertStatus(409)->assertExactJson(['message' => __('softphone.not_a_browser_call')]);
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new Call)->forceFill(['workspace_id' => $other->id, 'telenow_id' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'active', 'channel' => 'softphone', 'placed_by' => $this->ben->id]);
        $theirs->save();
        $this->actingAs($this->ben)->deleteJson("/api/kit/console/phone/calls/{$theirs->id}")->assertNotFound();
        $this->assertCount(4, $this->sent(), 'none sent for a call that is over, not one, or not the account\'s');
    }

    public function test_a_transfer_needs_both_switches_and_is_checked_as_a_call_to_that_number_is(): void
    {
        $this->place($this->ben)->assertCreated();
        $call = Call::query()->sole();
        $to = fn (string $n, ?User $who = null) => $this->actingAs($who ?? $this->ben)->postJson("/api/kit/console/phone/calls/{$call->id}/transfer", ['to' => $n]);
        $to('+14155550102')->assertForbidden()->assertExactJson(['message' => __('softphone.transfer_not_allowed')]);
        $this->switches(browser: false, transfer: true);
        $to('+14155550102')->assertForbidden();
        $this->switches(browser: true, transfer: true);
        $this->actingAs($this->ben)->getJson('/api/kit/console/phone')->assertJsonPath('transferAllowed', true);
        $to('+14155550102', $this->cy)->assertForbidden()->assertExactJson(['message' => __('softphone.not_yours')]);
        $to('call reception')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $entry = new DncEntry(['workspace_id' => $this->acme->id, 'phone_number' => '+14155550199']);
        $entry->forceFill(['workspace_id' => $this->acme->id, 'sync_state' => 'synced'])->save();
        $to('+14155550199')->assertUnprocessable()->assertJsonPath('errors.to', [__('softphone.on_dnc')]);
        $this->assertCount(1, $this->sent(), 'only the call itself so far');

        $to('+1 415 555 0102')->assertNoContent();
        $this->assertSame(['POST', '/api/sessions/'.self::SESSION.'/transfer', ['to' => '+14155550102']], array_values($this->sent()[1]));
        foreach (['transferCall.dnc' => [422, null], 'transferCall.ended' => [409, __('softphone.call_over')], 'transferCall.not-held' => [503, __('softphone.transfer_failed')]] as $recording => [$status, $words]) {
            $this->answers = ['POST /transfer' => $recording];
            $answer = $to('+14155550102')->assertStatus($status);
            $words === null ? $answer->assertJsonPath('errors.to', [__('softphone.refused')]) : $answer->assertExactJson(['message' => $words]);
        }
    }

    public function test_one_member_places_so_many_a_minute(): void
    {
        config(['kit.softphone.calls_per_minute' => 2]);
        $this->place($this->ben)->assertCreated();
        $this->answers = ['POST /init-web-call' => fn () => Http::response(['success' => true, 'data' => ['sessionId' => (string) \Illuminate\Support\Str::uuid(), 'status' => 'active']])];
        $this->place($this->ben)->assertCreated();
        $this->place($this->ben)->assertStatus(429)->assertExactJson(['message' => __('session.throttled')]);
        $this->place($this->cy)->assertCreated();
        $this->assertSame(3, Call::query()->count());
    }

    public function test_calls_on_now_are_by_the_accounts_agents_and_the_rest_and_asked_for_once_a_while(): void
    {
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $agent->forceFill(['telenow_id' => self::AGENT, 'sync_state' => 'synced'])->save();
        // Another account's agent that happens to carry the id Telenow counts browser calls under: never one of this account's.
        $elsewhere = Agent::create(['workspace_id' => Workspace::create(['name' => 'Elsewhere', 'status' => 'active', 'sync_state' => 'synced'])->id, 'name' => 'Not ours']);
        $elsewhere->forceFill(['telenow_id' => 'ab6b0b9a-5b29-4b92-b17b-040c8a3f8df3', 'sync_state' => 'synced'])->save();
        $live = $this->actingAs($this->ben)->getJson('/api/kit/console/calls/live')->assertOk();
        $live->assertExactJson(['total' => 2, 'agents' => [['id' => $agent->id, 'name' => 'Front desk', 'count' => 1]], 'others' => 1, 'calls' => [], 'canListen' => false, 'refreshSeconds' => config('kit.calls.live_cache_seconds')]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls/live')->assertOk()->assertJsonPath('canListen', true);
        $this->assertCount(2, $this->sent(), 'the counts and the list, once for the account however many look');

        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $other->forceFill(['telenow_sub_account_id' => '00000000-0000-4000-8000-0000000000aa', 'telenow_key' => 'vai_other'])->save();
        $eve = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $eve->id, 'role' => 'owner']);
        $this->answers = ['GET /live-calls' => fn () => Http::failedConnection()];
        $this->actingAs($eve)->getJson('/api/kit/console/calls/live')->assertStatus(503)->assertExactJson(['message' => __('calls.live_unavailable')]);
        $this->assertSame(['vai_other'], Http::recorded()->last()[0]->header('X-API-Key'), "each account's own, never another's figures");
    }
}
