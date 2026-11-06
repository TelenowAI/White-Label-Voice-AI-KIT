<?php

namespace Tests\Feature\Kit;

use App\Models\Call;
use App\Models\CallListen;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * The calls on now, listed, and a manager listening in to one (App\Kit\Calls\LiveCalls, ListenIn), through the kit's real routes, against Telenow's recorded answers (contract_k10listen.py, the 11-03
 * release with a stand-in carrier and media relay): it lists a live call with status active, answers a one-time listening ticket and the socket's address for the account's own live call, 409 once it is
 * over and 404 for another account's.
 */
class ListenInTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    /** The live call the recordings name. */
    private const LIVE = '5e551011-0000-4000-8000-000000000003';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    /** What each request answers instead of its recording, by method and how the path ends. */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY, 'balance' => '50'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
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
                $r->method() === 'GET' && $path === '/api/agents/live-calls' => RecordedTelenow::response('countLiveCalls.softphone'),
                $r->method() === 'GET' && $path === '/api/orgs/'.self::ORG.'/calls' && ($r->data()['status'] ?? null) === 'active' => RecordedTelenow::response('listCalls.active-softphone'),
                $r->method() === 'POST' && str_ends_with($path, '/listen') => RecordedTelenow::response('listenToCall.sounding'),
                default => $recorded($r),
            };
        });
    }

    private function live(?User $who = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($who ?? $this->ana)->getJson('/api/kit/console/calls/live')->assertOk();
    }

    private function listen(Call $call, ?User $who = null): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($who ?? $this->ana)->postJson("/api/kit/console/calls/{$call->id}/listen");
    }

    /** @return list<string> Telenow paths asked, but the counts and the list */
    private function asked(string $end): array
    {
        return Http::recorded()->map(fn (array $p) => (string) parse_url($p[0]->url(), PHP_URL_PATH))->filter(fn (string $p) => str_ends_with($p, $end))->values()->all();
    }

    private function aCall(array $over = []): Call
    {
        $call = (new Call)->forceFill($over + ['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'active', 'channel' => 'telephony', 'started_at' => now(), 'detail_state' => 'pending']);
        $call->save();

        return $call;
    }

    public function test_the_calls_on_now_are_listed_by_the_kits_own_ids_and_say_who_may_listen(): void
    {
        $answer = $this->live();
        $call = Call::query()->where('telenow_id', self::LIVE)->sole();
        $answer->assertJsonPath('calls', [[
            'id' => $call->id, 'agent' => null, 'placedBy' => null, 'direction' => 'outbound', 'channel' => 'softphone',
            'from' => '918045671001', 'to' => '+14155550101', 'startedAt' => $call->started_at->toIso8601String(), 'canListen' => true,
        ]])->assertJsonPath('canListen', true)->assertJsonPath('total', 2); // the counts are their own recording, made with two calls on
        $this->assertStringNotContainsString(self::LIVE, $answer->getContent(), "Telenow's own id never");
        $this->assertSame(['active', 'pending', $this->acme->id], [$call->status, $call->detail_state, $call->workspace_id], 'kept as the feed keeps it, and read once over');

        $this->live($this->ben)->assertJsonPath('canListen', false)->assertJsonPath('calls.0.canListen', false);
        $this->assertCount(1, $this->asked('/calls'), 'the list once for the account in a while, however many look');
        Cache::flush();
        $this->live()->assertJsonPath('calls.0.id', $call->id);
        $this->assertSame(1, Call::query()->count(), 'listed again unchanged: the same call, kept once');
    }

    public function test_a_call_over_or_another_accounts_is_not_listed(): void
    {
        $this->live();
        Call::query()->where('telenow_id', self::LIVE)->update(['status' => 'ended']);
        $this->live()->assertJsonPath('calls', []);

        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $other->forceFill(['telenow_sub_account_id' => '00000000-0000-4000-8000-0000000000aa', 'telenow_key' => 'vai_other'])->save();
        $eve = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $eve->id, 'role' => 'owner']);
        $this->answers = ['GET /calls' => fn () => Http::response(['success' => true, 'data' => ['calls' => [], 'total' => 0]]),
            'GET /live-calls' => fn () => Http::response(['success' => true, 'data' => ['counts' => (object) [], 'total' => 0]])];
        $this->live($eve)->assertJsonPath('calls', [])->assertJsonPath('total', 0);
    }

    public function test_a_manager_listens_on_the_address_telenow_gives_and_it_is_kept_who_did(): void
    {
        $this->live();
        $call = Call::query()->where('telenow_id', self::LIVE)->sole();
        $this->listen($call)->assertOk()->assertExactJson(['wsUrl' => 'wss://127.0.0.1:39897/ws/monitor?ticket=0123456789abcdef0123456789abcdef']);
        [$asked] = Http::recorded(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/listen'))->map(fn ($p) => $p[0])->values()->all();
        $this->assertSame(['POST', '/api/orgs/'.self::ORG.'/calls/'.self::LIVE.'/listen', [self::KEY]], [$asked->method(), (string) parse_url($asked->url(), PHP_URL_PATH), $asked->header('X-API-Key')]);
        $row = CallListen::query()->sole();
        $this->assertSame([$call->id, $this->acme->id, (int) $this->ana->id], [$row->call_id, $row->workspace_id, (int) $row->user_id]);

        $this->travel(1)->minutes();
        $this->listen($call)->assertOk();
        $this->actingAs($this->ben)->getJson("/api/kit/console/calls/{$call->id}")->assertOk()
            ->assertJsonPath('canListen', false)->assertJsonPath('listens.0.by', 'Ana')->assertJsonPath('listens.0.at', now()->toIso8601String());
        config(['kit.calls.listens_shown' => 1]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}")->assertJsonPath('canListen', true)->assertJsonCount(1, 'listens');
    }

    public function test_only_a_manager_and_only_a_call_on_with_sound_and_none_is_asked_for_otherwise(): void
    {
        $this->listen($this->aCall(), $this->ben)->assertForbidden()->assertExactJson(['message' => __('calls.listen_only_managers')]);
        foreach (['web_call', 'web_chat', 'chat', 'simulation'] as $channel) {
            $this->listen($this->aCall(['channel' => $channel]))->assertStatus(409)->assertExactJson(['message' => __('calls.listen_not_heard')]);
        }
        foreach (['ended', 'orphaned'] as $over) {
            $this->listen($this->aCall(['status' => $over]))->assertStatus(409)->assertExactJson(['message' => __('calls.listen_call_over')]);
        }
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $this->listen($this->aCall(['workspace_id' => $other->id]))->assertNotFound();
        $this->actingAs($this->ana)->postJson('/api/kit/console/calls/not-an-id/listen')->assertNotFound();
        $this->assertSame([], $this->asked('/listen'));
        $this->assertSame(0, CallListen::query()->count());
        foreach (['whatsapp', 'softphone', 'telephony'] as $heard) {
            $this->listen($this->aCall(['channel' => $heard]))->assertOk();
        }
        // What a call's page offers, the same way.
        $page = fn (Call $c, ?User $who = null) => $this->actingAs($who ?? $this->ana)->getJson("/api/kit/console/calls/{$c->id}")->assertOk()->json('canListen');
        $this->assertSame([true, false, false, false], [$page($this->aCall()), $page($this->aCall(), $this->ben), $page($this->aCall(['status' => 'ended'])), $page($this->aCall(['channel' => 'web_call']))]);
    }

    public function test_telenows_refusals_say_the_call_is_over_and_read_it_again_soon(): void
    {
        foreach (['listenToCall.ended', 'listenToCall.other-account', 'listenToCall.unknown'] as $recording) {
            $this->answers = ['POST /listen' => $recording];
            $call = $this->aCall(['next_read_at' => now()->addHour()]);
            $this->listen($call)->assertStatus(409)->assertExactJson(['message' => __('calls.listen_call_over')]);
            $this->assertFalse($call->fresh()->next_read_at->isFuture(), $recording);
        }
        $this->answers = ['POST /listen' => fn () => Http::failedConnection()];
        $this->listen($this->aCall())->assertStatus(503)->assertExactJson(['message' => __('calls.listen_unavailable')]);
        foreach (['javascript:alert(1)', 'https://elsewhere.example/ws', ''] as $bad) {
            $this->answers = ['POST /listen' => fn () => Http::response(['success' => true, 'data' => ['ticket' => 'x', 'wsUrl' => $bad]])];
            $this->listen($this->aCall())->assertStatus(503);
        }
        $this->assertSame(0, CallListen::query()->count(), 'nobody listened');
    }

    public function test_listening_needs_the_account_ready(): void
    {
        $this->acme->forceFill(['telenow_key' => null])->save();
        $this->listen($this->aCall())->assertStatus(409)->assertExactJson(['message' => __('calls.account_not_ready')]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls/live')->assertStatus(503);
        $this->assertSame([], $this->asked('/listen'));
    }
}
