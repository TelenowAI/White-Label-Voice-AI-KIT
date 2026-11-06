<?php

namespace Tests\Feature\Kit;

use App\Kit\Events\TelenowEvent;
use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\Outbox;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\KnowledgeBase;
use App\Models\PriceList;
use App\Models\TelenowFee;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Closing a customer (App\Kit\Customers\Customers::close, Telenow\CloseAtTelenow) against Telenow's RECORDED answers
 * (contract_k1close.py on the real binary): closing revokes the customer's key for good, so nothing of theirs can be read
 * afterwards. So the close waits, without ever running out of tries, until the customer's numbers are released, its live
 * calls have ended, and every call and number fee has been read and charged; only then does it reach Telenow. A close that
 * already landed finishes. Once closed, nothing is asked of Telenow with the forgotten key, and what is left of a closed
 * customer breaks nothing and holds up nobody else.
 */
class CustomerCloseTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'vai_test_operator_admin';

    private const KEY = 'vai_test_customer_acme';

    private const SUB_ID = '3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private const SUB = '/api/v1/sub-accounts/'.self::SUB_ID;

    private User $staff;

    private User $ana;

    private Workspace $acme;

    /** Telenow's answers to change mid-test: its live calls, and its answer to the close and to the usage read. */
    private int $activeCalls = 0;

    private ?string $closeAnswer = null;

    private ?string $usageAnswer = null;

    private bool $keyRefused = false;

    /** The calls feed has nothing new, and the ledger. */
    private bool $callsEmpty = false;

    private bool $ledgerEmpty = false;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', self::OPERATOR, secret: true);
        $settings->put('telenow.events_secrets', ['current' => RecordedTelenow::event('call.completed.answered')['secret'], 'previous' => null], secret: true);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']]]]]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::SUB_ID, 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced'])->save();
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($recorded) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($this->keyRefused && ($r->header('X-API-Key')[0] ?? null) === self::KEY) {
                return Http::response(RecordedTelenow::body('listNumbers.closed-key'), 401);
            }
            if ($r->method() === 'GET' && $path === self::SUB.'/usage') {
                $usage = RecordedTelenow::body($this->usageAnswer ?? 'getSubAccountUsage.operator');

                return Http::response(['activeCalls' => $this->activeCalls] + $usage);
            }
            if ($this->callsEmpty && str_ends_with($path, '/calls')) {
                return Http::response(RecordedTelenow::body('listCalls.feed-next'));
            }
            if ($this->ledgerEmpty && str_ends_with($path, '/billing/ledger')) {
                return Http::response(RecordedTelenow::body('listLedger.customer-feed-next'));
            }
            if ($r->method() === 'DELETE' && $path === self::SUB && $this->closeAnswer !== null) {
                return Http::response(RecordedTelenow::body($this->closeAnswer), 409);
            }

            return $recorded($r);
        });
    }

    private function close(): void
    {
        $this->actingAs($this->staff)->postJson("/api/kit/admin/customers/{$this->acme->id}/close", ['confirm' => 'Acme Dental'])
            ->assertOk()->assertJsonPath('status', Workspace::CLOSING);
    }

    /** @return list<Request> what reached Telenow's close */
    private function closes(): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && parse_url($r->url(), PHP_URL_PATH) === self::SUB)
            ->map(fn (array $pair) => $pair[0])->values()->all();
    }

    private function closeRow(): object
    {
        return DB::table('telenow_outbox')->where('kit_id', $this->acme->id)->where('operation', 'close')->sole();
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

    /** A while later: the jobs that run every minute, once. */
    private function aWhileLater(int $minutes = 61): void
    {
        $this->travel($minutes)->minutes();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->artisan('kit:wallet')->assertSuccessful();
        app(Outbox::class)->runDue();
    }

    public function test_the_close_waits_for_live_calls_and_the_last_charge_and_only_then_reaches_telenow(): void
    {
        $this->actingAs($this->staff)->postJson("/api/kit/admin/customers/{$this->acme->id}/wallet/credits",
            ['amount' => '100', 'note' => 'Bank transfer', 'reference' => (string) Str::uuid()])->assertCreated();
        $this->deliver('call.completed.answered');
        $this->assertSame('pending', $this->answered()->charge_state, 'Telenow has not been asked its cost yet');

        $this->close();
        $this->assertCount(0, $this->closes(), 'a call may still be landing');
        $this->assertSame(self::KEY, $this->acme->fresh()->telenow_key);

        // A call is still live: the close waits, and says so.
        $this->activeCalls = 1;
        $this->travel((int) config('kit.customers.close_settle_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(0, $this->closes());
        $acme = $this->acme->fresh();
        $this->assertSame([Workspace::CLOSING, __('customers.calls_ending', ['count' => 1])], [$acme->status, $acme->sync_error]);

        // None is live, but the last call is not charged: still waiting, and the key still works to read its cost.
        $this->activeCalls = 0;
        $this->travel((int) config('kit.outbox.not_yet_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(0, $this->closes());
        $this->assertSame(Workspace::CLOSING, $this->acme->fresh()->status);

        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame('charged', $this->answered()->charge_state, 'charged while the key still works');
        $this->assertSame(1, WalletEntry::query()->where('workspace_id', $this->acme->id)->where('kind', 'charge')->count());

        // Everything the feed brought is read and charged in its time (an unanswered call runs out of reads), then it closes.
        for ($i = 0; $i < 14 && $this->closes() === []; $i++) {
            $this->aWhileLater();
        }
        $this->assertCount(1, $this->closes());
        $this->assertSame(self::OPERATOR, $this->closes()[0]->header('X-API-Key')[0]);
        $acme = $this->acme->fresh();
        $this->assertSame([Workspace::CLOSED, null, 'synced', null], [$acme->status, $acme->telenow_key, $acme->sync_state, $acme->sync_error]);
        $this->assertSame('closed', $acme->telenow_snapshot['status']);
        $this->assertNotNull($acme->closed_at);
        $this->assertSame(0, Call::query()->where('workspace_id', $acme->id)->where(fn ($q) => $q->where('charge_state', 'pending')->orWhere('detail_state', 'pending'))->count());
    }

    public function test_a_close_with_nothing_to_charge_still_waits_a_moment_for_a_call_landing(): void
    {
        $this->callsEmpty = $this->ledgerEmpty = true;
        $this->close();
        $this->assertCount(0, $this->closes());
        $this->assertSame(__('customers.calls_landing'), $this->acme->fresh()->sync_error);

        $this->travel((int) config('kit.customers.close_settle_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(1, $this->closes());
        $this->assertSame(Workspace::CLOSED, $this->acme->fresh()->status);
    }

    public function test_the_close_waits_for_a_call_still_being_read(): void
    {
        $this->callsEmpty = $this->ledgerEmpty = true;
        $call = Call::forceCreate(['workspace_id' => $this->acme->id, 'telenow_id' => RecordedTelenow::body('getCall.answered')['data']['id'], 'status' => 'ended',
            'started_at' => now()->subHour(), 'duration_sec' => 60, 'detail_state' => 'pending', 'next_read_at' => now()->addDay()]);
        $this->close();
        $this->travel((int) config('kit.customers.close_settle_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(0, $this->closes(), 'its transcript and cost can only be read with the key');
        $this->assertSame(__('customers.calls_settling'), $this->acme->fresh()->sync_error);

        $call->forceFill(['next_read_at' => now()])->save();
        $this->aWhileLater();
        $this->assertSame(['final', 'charged'], [$call->fresh()->detail_state, $call->fresh()->charge_state]);
        $this->aWhileLater();
        $this->assertSame(Workspace::CLOSED, $this->acme->fresh()->status);
    }

    public function test_the_close_waits_for_a_number_fee_to_be_charged(): void
    {
        $this->callsEmpty = $this->ledgerEmpty = true;
        $entry = RecordedTelenow::body('listLedger.number-fees')['data']['entries'][0];
        $fee = new TelenowFee;
        $fee->forceFill(['id' => $entry['id'], 'workspace_id' => $this->acme->id, 'ref_kind' => $entry['refKind'], 'ref_id' => $entry['refId'] ?? null,
            'amount_usd' => (string) $entry['amountUsd'], 'entry' => $entry, 'charge_state' => 'pending'])->save();
        $this->close();
        $this->travel((int) config('kit.customers.close_settle_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(0, $this->closes());

        $this->aWhileLater();
        $this->assertNotSame('pending', $fee->fresh()->charge_state);
        $this->assertSame(Workspace::CLOSED, $this->acme->fresh()->status);
    }

    public function test_the_close_reads_the_calls_feed_to_its_end_even_when_a_page_changed_nothing(): void
    {
        config(['kit.calls.feed_pages_per_run' => 1]);
        $this->ledgerEmpty = true;
        foreach (RecordedTelenow::body('listCalls.feed-first')['data']['calls'] as $row) {
            Call::forceCreate(app(Manifest::class)->columns('calls', $row) + ['workspace_id' => $this->acme->id, 'telenow_id' => $row['id'], 'detail_state' => 'final', 'charge_state' => 'charged']);
        }
        $this->close();
        $this->travel((int) config('kit.customers.close_settle_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(0, $this->closes(), 'a page of calls it already had: there may be more after it');
        $this->travel((int) config('kit.outbox.not_yet_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(1, $this->closes(), 'nothing more');
    }

    public function test_closed_customers_do_not_take_the_spend_limits_places(): void
    {
        $gone = Workspace::create(['name' => 'Gone', 'status' => Workspace::CLOSED, 'sync_state' => 'synced', 'closed_at' => now()]);
        $gone->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => null])->save();
        config(['kit.calls.feed_accounts_per_run' => 1]);
        $this->acme->forceFill(['spend_limit_at' => now()->subDays(2)])->save();
        $this->artisan('kit:wallet')->assertSuccessful();
        $this->assertSame(1, Http::recorded(fn (Request $r) => $r->method() === 'PATCH' && parse_url($r->url(), PHP_URL_PATH) === self::SUB)->count());
    }

    public function test_the_close_never_runs_out_of_tries_while_it_waits(): void
    {
        $this->close();
        // Telenow says a number is still held (409, recorded), however many tries that takes.
        $this->closeAnswer = 'closeSubAccount.holds-numbers';
        for ($i = 0; $i < 14 && $this->closes() === []; $i++) {
            $this->aWhileLater();
        }
        $this->assertCount(1, $this->closes(), 'settled, so it asks');
        $tries = (int) config('kit.outbox.max_attempts') + 3;
        for ($i = 0; $i < $tries; $i++) {
            $this->aWhileLater();
        }
        $row = $this->closeRow();
        $this->assertNull($row->failed_at, 'waiting is not failing');
        $this->assertNull($row->done_at);
        $this->assertSame(0, $row->attempts, 'waiting is not a try');
        $this->assertSame(RecordedTelenow::body('closeSubAccount.holds-numbers')['error'], $this->acme->fresh()->sync_error);
        $this->assertSame(Workspace::CLOSING, $this->acme->fresh()->status);
        $this->assertCount(1 + $tries, $this->closes());

        $this->closeAnswer = null;
        $this->aWhileLater();
        $this->assertSame(Workspace::CLOSED, $this->acme->fresh()->status);
        $this->assertNotNull($this->closeRow()->done_at);
    }

    public function test_a_close_that_already_landed_finishes(): void
    {
        $this->close();
        // The close reached Telenow but the kit never saw the answer: the sub-account says closed, its key is revoked.
        $this->usageAnswer = 'getSubAccountUsage.closed';
        $this->keyRefused = true;
        $this->travel((int) config('kit.customers.close_settle_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(1, $this->closes());
        $acme = $this->acme->fresh();
        $this->assertSame([Workspace::CLOSED, null], [$acme->status, $acme->telenow_key]);
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), '/calls') || str_contains($r->url(), '/ledger'))->count(), 'nothing to read with a dead key');
        $this->assertNull($this->closeRow()->failed_at);
    }

    public function test_a_key_telenow_already_refuses_does_not_hold_the_close_up(): void
    {
        $this->close();
        // Telenow does not say closed, but the customer's key is refused: nothing more can be read, so there is nothing to wait for.
        $this->keyRefused = true;
        $this->travel((int) config('kit.customers.close_settle_seconds') + 1)->seconds();
        app(Outbox::class)->runDue();
        $this->assertCount(1, $this->closes());
        $this->assertSame(Workspace::CLOSED, $this->acme->fresh()->status);
    }

    public function test_with_pricing_off_a_close_does_not_wait_for_charges_that_will_never_run(): void
    {
        $this->deliver('call.completed.answered');
        $this->assertSame('pending', $this->answered()->charge_state);
        app(Settings::class)->put('pricing.default_plan', 'gone');
        $this->close();
        for ($i = 0; $i < 14 && $this->closes() === []; $i++) {
            $this->travel(61)->minutes();
            $this->artisan('kit:calls')->assertSuccessful();
            app(Outbox::class)->runDue();
        }
        $this->assertCount(1, $this->closes());
        $this->assertSame(Workspace::CLOSED, $this->acme->fresh()->status);
    }

    public function test_a_closed_customers_leftovers_break_nothing_and_hold_up_nobody(): void
    {
        // Acme, active, has a call to charge; Gone, closed with its key forgotten, has every kind of leftover ahead of it.
        $this->deliver('call.completed.answered');
        $gone = Workspace::create(['name' => 'Gone', 'status' => Workspace::CLOSED, 'sync_state' => 'synced', 'closed_at' => now()]);
        $gone->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => null])->save();
        $gina = User::create(['name' => 'Gina', 'email' => 'gina@example.com', 'password' => 'a long password 8']);
        WorkspaceMember::create(['workspace_id' => $gone->id, 'user_id' => $gina->id, 'role' => 'owner']);
        $toCharge = Call::forceCreate(['workspace_id' => $gone->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'started_at' => now()->subHour(),
            'duration_sec' => 60, 'detail_state' => 'final', 'charge_state' => 'pending', 'charge_reads' => 0, 'charge_next_at' => now()->subDay()]);
        $toRead = Call::forceCreate(['workspace_id' => $gone->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'started_at' => now()->subHour(),
            'duration_sec' => 60, 'detail_state' => 'pending', 'next_read_at' => now()->subDay(),
            'recordings' => [['id' => (string) Str::uuid()]]]);
        $base = new KnowledgeBase;
        $base->forceFill(['workspace_id' => $gone->id, 'name' => 'FAQ', 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();
        $campaign = new Campaign;
        $campaign->forceFill(['workspace_id' => $gone->id, 'name' => 'Recall', 'concurrency' => 1, 'timezone' => 'Asia/Kolkata', 'machine_detection' => 'off',
            'max_attempts' => 1, 'retry_on_no_answer' => false, 'telenow_id' => (string) Str::uuid(), 'status' => config('kit.campaigns.active_statuses')[0]])->save();
        $acmeBase = new KnowledgeBase;
        $acmeBase->forceFill(['workspace_id' => $this->acme->id, 'name' => 'Acme FAQ', 'telenow_id' => RecordedTelenow::body('createKnowledgeBase.customer')['data']['id'], 'sync_state' => 'synced'])->save();
        $acmeCampaign = new Campaign;
        $acmeCampaign->forceFill(['workspace_id' => $this->acme->id, 'name' => 'Acme recall', 'concurrency' => 1, 'timezone' => 'Asia/Kolkata', 'machine_detection' => 'off', 'max_attempts' => 1,
            'retry_on_no_answer' => false, 'telenow_id' => RecordedTelenow::body('getCampaign.customer')['data']['id'], 'status' => config('kit.campaigns.active_statuses')[0], 'sync_state' => 'synced'])->save();

        // One a run: a closed customer's leftovers must not take the only place.
        config(['kit.knowledge.feed_bases_per_run' => 1, 'kit.campaigns.per_run' => 1, 'kit.calls.feed_accounts_per_run' => 1]);
        $this->acme->forceFill(['spend_limit_at' => now()->subDays(2), 'calls_synced_at' => now()])->save();

        foreach (['kit:consent', 'kit:wallet', 'kit:calls', 'kit:numbers', 'kit:dnc', 'kit:knowledge', 'kit:campaigns', 'kit:agents'] as $job) {
            $this->artisan($job)->assertSuccessful();
        }
        $this->assertSame('charged', $this->answered()->charge_state, "another customer's call is still charged");
        $this->assertSame('unrated', $toCharge->fresh()->charge_state, 'its key is gone: its cost can never be read');
        $this->assertNull($toCharge->fresh()->charge_next_at);
        $this->assertSame('gave_up', $toRead->fresh()->detail_state);
        $this->assertNull($toRead->fresh()->next_read_at);
        $this->assertNotNull($acmeBase->fresh()->documents_read_at, "the active customer's knowledge is still read");
        $this->assertNull($base->fresh()->documents_read_at, 'nothing asked for the closed one');
        $this->assertNotNull($acmeCampaign->fresh()->read_at, "the active customer's campaign is still read");
        $this->assertNull($campaign->fresh()->read_at);
        $sent = Http::recorded()->map(fn (array $pair) => $pair[0]->header('X-API-Key')[0] ?? '')->values()->all();
        $this->assertNotContains('', $sent, 'nothing goes out with a forgotten key');
        $this->assertSame(0, Http::recorded(fn (Request $r) => str_contains($r->url(), $gone->telenow_sub_account_id))->count());

        // Its people may still look: a recording the platform can no longer give is simply not there.
        $this->actingAs($gina)->getJson("/api/kit/console/calls/{$toRead->id}/recordings/0")->assertNotFound()
            ->assertExactJson(['message' => __('calls.no_recording')]);

        // A late event about it is taken and changes nothing.
        $this->acme->forceFill(['status' => Workspace::CLOSED, 'telenow_key' => null])->save();
        $before = Call::query()->count();
        $this->deliver('call.completed.unanswered');
        $this->assertSame($before, Call::query()->count());
        $this->assertSame(0, TelenowEvent::query()->whereNotNull('failed_at')->count());
        $this->assertSame(0, TelenowEvent::query()->whereNull('applied_at')->count(), 'nothing left to try again');
    }
}
