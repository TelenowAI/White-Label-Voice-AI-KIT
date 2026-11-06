<?php

namespace Tests\Feature\Kit;

use App\Kit\Events\TelenowEvent;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\Call;
use App\Models\User;
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
 * The kit's copy of a customer's calls (App\Kit\Calls): `call.completed` names a call of a customer's sub-account and
 * the kit reads the call itself, with the customer's own key — fields by the manifest, the analysis once Telenow has
 * it — and reads it again while a part the console shows is still pending; the calls feed brings the calls whose
 * events never came. Console → Calls shows only the account's own calls, and plays a recording through the kit.
 * Telenow answers from real recordings (contract_k1calls.py); its events are recorded deliveries, signed as it signs.
 */
class CallsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    private array $answered;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.events_secrets', ['current' => RecordedTelenow::event('call.completed.answered')['secret'], 'previous' => null], secret: true);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => RecordedTelenow::event('call.completed.answered')['payload']['subAccountId'], 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced'])->save();
        $this->answered = RecordedTelenow::body('getCall.answered')['data'];
        RecordedTelenow::fake();
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
        return Http::recorded(fn (Request $r) => self::is($r, $method, $path))->map(fn (array $pair) => $pair[0])->values()->all();
    }

    private function org(string $tail = ''): string
    {
        return "/api/orgs/{$this->acme->telenow_sub_account_id}{$tail}";
    }

    private function deliver(string $name): TestResponse
    {
        $e = RecordedTelenow::event($name);
        $server = [];
        foreach ($e['headers'] as $h => $v) {
            $server[$h === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $h))] = $v;
        }

        return $this->call('POST', '/api/kit/telenow/events', [], [], [], $server, $e['body']);
    }

    public function test_call_completed_brings_the_customers_call_into_the_kit(): void
    {
        $this->deliver('call.completed.answered')->assertNoContent();
        $call = Call::query()->sole();
        $this->assertSame([$this->acme->id, $this->agent->id, $this->answered['id'], 'ended', 'completed', 'inbound', '+14155550123', '+14155550100', 50, 'human', 'final'],
            [$call->workspace_id, $call->agent_id, $call->telenow_id, $call->status, $call->outcome, $call->direction, $call->from_number, $call->to_number,
                $call->duration_sec, $call->answered_by, $call->detail_state]);
        $this->assertCount(4, $call->transcript);
        $this->assertCount(1, $call->recordings);
        $this->assertSame(RecordedTelenow::body('getCallAnalysis.answered')['data']['summary'], $call->analysis['summary']);
        $this->assertSame('pending', $call->completeness['metrics'], 'a part the console does not show is not waited for');
        [$read] = $this->sent('GET', $this->org("/calls/{$this->answered['id']}"));
        $this->assertSame([self::KEY], $read->header('X-API-Key'), "the customer's own key");

        $list = $this->actingAs($this->ana)->getJson('/api/kit/console/calls')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('calls.0.id', $call->id)->assertJsonPath('calls.0.agent', ['id' => $this->agent->id, 'name' => 'Front desk'])
            ->assertJsonPath('calls.0.outcome', 'completed')->assertJsonPath('calls.0.complete', true);
        $this->assertArrayNotHasKey('transcript', $list->json('calls.0'));
        $detail = $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}")->assertOk()
            ->assertJsonPath('transcript.0', ['role' => 'assistant', 'text' => 'Acme Dental, how can I help?', 'at' => $this->answered['transcript'][0]['at']])
            ->assertJsonPath('recordings', [['index' => 0, 'durationSec' => null, 'createdAt' => $this->answered['recordings'][0]['created_at']]])
            ->assertJsonPath('analysis', ['summary' => 'Booked a cleaning for Tuesday 10 am.', 'sentiment' => 'positive', 'disposition' => 'booked', 'actionItems' => [], 'customData' => [], 'qa' => []]);
        foreach ([$this->answered['id'], $this->answered['recordings'][0]['id'], $this->acme->telenow_sub_account_id, 'charge_usd', 'chargeUsd', 'model'] as $never) {
            $this->assertStringNotContainsString($never, $detail->getContent());
        }
    }

    public function test_an_unanswered_call_is_kept_and_the_operators_own_call_is_left_alone(): void
    {
        $this->deliver('call.completed.unanswered')->assertNoContent();
        $call = Call::query()->sole();
        $this->assertSame(['no_answer', 'outbound', 0, 'final', null], [$call->outcome, $call->direction, $call->duration_sec, $call->detail_state, $call->analysis]);
        $this->assertSame([], Http::recorded(fn (Request $r) => str_contains($r->url(), '/analysis/'))->all(), 'no analysis to read');

        // An account still being set up has no sub-account yet: the operator's own call is not its call either.
        Workspace::create(['name' => 'Not set up yet', 'status' => 'active', 'sync_state' => 'pending']);
        $this->deliver('call.completed.operator-own')->assertNoContent();
        $this->assertSame(1, Call::query()->count());
        $this->assertSame(2, TelenowEvent::query()->whereNotNull('applied_at')->count());
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_contains($r->url(), '/calls/'))->all(), 'nothing read for it');
    }

    public function test_a_call_still_waiting_for_a_part_is_read_again_until_it_is_there(): void
    {
        $pending = RecordedTelenow::body('getCall.answered');
        $pending['data']['completeness']['analysis'] = 'pending';
        $first = true;
        $this->answering(function (Request $r) use (&$first, $pending) {
            if (self::is($r, 'GET', $this->org("/calls/{$this->answered['id']}")) && $first) {
                $first = false;

                return Http::response($pending);
            }

            return null;
        });
        $this->deliver('call.completed.answered')->assertNoContent();
        $call = Call::query()->sole();
        $this->assertSame(['pending', null], [$call->detail_state, $call->analysis]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}")->assertJsonPath('complete', false);

        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertCount(1, $this->sent('GET', $this->org("/calls/{$this->answered['id']}")), 'not before its time');
        $this->travel(config('kit.calls.reread_base_seconds'))->seconds();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertSame('final', $call->fresh()->detail_state);
        $this->assertSame('Booked a cleaning for Tuesday 10 am.', $call->fresh()->analysis['summary']);
    }

    public function test_an_agent_that_records_nothing_has_no_recording_to_wait_for(): void
    {
        // Recorded on the 11-03 release: an ended call with a transcript and no recording reads `recording: pending`, and its integrity worker leaves out agents with recording off.
        $pending = RecordedTelenow::body('getCall.answered');
        $pending['data']['completeness']['recording'] = 'pending';
        $this->answering(fn (Request $r) => self::is($r, 'GET', $this->org("/calls/{$this->answered['id']}")) ? Http::response($pending) : null);
        $agent = Agent::query()->where('telenow_id', $pending['data']['agent_id'])->sole();
        $settings = $agent->settings;

        $agent->forceFill(['settings' => ['recording' => true] + (array) $settings])->save();
        $this->deliver('call.completed.answered');
        $call = Call::query()->where('telenow_id', $this->answered['id'])->sole();
        $this->assertSame('pending', $call->detail_state, 'one that records has a recording coming');
        $this->assertNull($call->charge_state);

        $agent->forceFill(['settings' => ['recording' => false] + (array) $settings])->save();
        $this->travel(config('kit.calls.reread_base_seconds'))->seconds();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertSame('final', $call->fresh()->detail_state);
        $this->assertNotNull($call->fresh()->charge_state);
    }

    public function test_a_call_given_up_on_is_still_handed_on_to_be_charged(): void
    {
        config(['kit.calls.max_reads' => 2]);
        $pending = RecordedTelenow::body('getCall.answered');
        $pending['data']['completeness']['recording'] = 'pending';
        $this->answering(fn (Request $r) => self::is($r, 'GET', $this->org("/calls/{$this->answered['id']}")) ? Http::response($pending) : null);
        $this->deliver('call.completed.answered');
        $call = Call::query()->where('telenow_id', $this->answered['id'])->sole();
        $this->assertNull($call->charge_state);
        $this->travel(config('kit.calls.reread_max_seconds'))->seconds();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertSame('gave_up', $call->fresh()->detail_state);
        $this->assertNotNull($call->fresh()->charge_state, 'the operator pays Telenow for it either way');
    }

    public function test_a_call_whose_parts_never_come_is_given_up_after_max_reads(): void
    {
        config(['kit.calls.max_reads' => 2]);
        $pending = RecordedTelenow::body('getCall.answered');
        $pending['data']['completeness']['transcript'] = 'pending';
        $this->answering(fn (Request $r) => self::is($r, 'GET', $this->org("/calls/{$this->answered['id']}")) ? Http::response($pending) : null);
        $this->deliver('call.completed.answered');
        $this->travel(config('kit.calls.reread_max_seconds'))->seconds();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertSame('gave_up', Call::query()->where('telenow_id', $this->answered['id'])->sole()->detail_state);
        $this->travel(config('kit.calls.reread_max_seconds'))->seconds();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertCount(2, $this->sent('GET', $this->org("/calls/{$this->answered['id']}")));
    }

    public function test_the_feed_brings_the_calls_whose_events_never_came(): void
    {
        $this->artisan('kit:calls')->assertSuccessful();
        [$start, $next] = $this->sent('GET', $this->org('/calls'));
        parse_str((string) parse_url($start->url(), PHP_URL_QUERY), $q1);
        $this->assertSame(['updatedAfter' => $this->acme->created_at->utc()->format('Y-m-d\TH:i:s.v\Z')], $q1, "from the account's start");
        $cursor = RecordedTelenow::body('listCalls.feed-first')['data']['nextCursor'];
        parse_str((string) parse_url($next->url(), PHP_URL_QUERY), $q2);
        $this->assertSame(['cursor' => $cursor], $q2, 'then from where it stopped');
        $this->assertSame($cursor, $this->acme->fresh()->calls_cursor);
        $this->assertSame(['pending', 'pending'], Call::query()->orderBy('telenow_id')->pluck('detail_state')->all());

        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertSame(['final', 'final'], Call::query()->pluck('detail_state')->all(), 'read on the next run');
        $this->assertCount(2, $this->sent('GET', $this->org('/calls')), 'the feed waits feed_minutes');
        $this->travel(config('kit.calls.feed_minutes'))->minutes();
        $this->artisan('kit:calls')->assertSuccessful();
        [, , $again] = $this->sent('GET', $this->org('/calls'));
        $this->assertStringContainsString('cursor=', $again->url());
        $this->assertSame(2, Call::query()->count());
    }

    public function test_a_call_the_feed_lists_again_is_read_again_only_when_it_changed(): void
    {
        $this->artisan('kit:calls')->assertSuccessful();
        $this->artisan('kit:calls')->assertSuccessful();
        $reads = fn () => count($this->sent('GET', $this->org("/calls/{$this->answered['id']}")));
        $this->assertSame(1, $reads());
        $page = RecordedTelenow::body('listCalls.feed-first');
        $this->answering(fn (Request $r) => self::is($r, 'GET', $this->org('/calls')) ? Http::response($page) : null);
        $this->travel(config('kit.calls.feed_minutes'))->minutes();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->artisan('kit:calls')->assertSuccessful();
        // (a new fake starts a new request log: these count the reads since it)
        $this->assertSame(['final', 0], [Call::query()->where('telenow_id', $this->answered['id'])->value('detail_state'), $reads()], 'listed again, unchanged');

        foreach ($page['data']['calls'] as &$row) {
            if ($row['id'] === $this->answered['id']) {
                $row['updated_at'] = '2026-10-30T11:38:57.196796Z'; // one microsecond later: Telenow changed it
            }
        }
        unset($row);
        $this->answering(fn (Request $r) => self::is($r, 'GET', $this->org('/calls')) ? Http::response($page) : null);
        $this->travel(config('kit.calls.feed_minutes'))->minutes();
        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertSame('pending', Call::query()->where('telenow_id', $this->answered['id'])->value('detail_state'));
        $this->artisan('kit:calls')->assertSuccessful();
        $this->assertSame(1, $reads(), 'read again');
    }

    public function test_telenows_updated_at_is_kept_as_text_exactly(): void
    {
        // A date column cuts microseconds (MySQL; SQLite keeps any text, so only the type shows it): every feed pass
        // would then see an unchanged call as changed and read it again.
        $this->assertContains(\Illuminate\Support\Facades\Schema::getColumnType('calls', 'telenow_updated_at'), ['varchar', 'string', 'text']);
        $this->deliver('call.completed.answered');
        $this->assertSame($this->answered['updated_at'], Call::query()->sole()->telenow_updated_at);
    }

    public function test_telenow_unreachable_on_an_event_tries_the_event_again_later(): void
    {
        $down = true;
        $this->answering(function (Request $r) use (&$down) {
            return $down && str_contains($r->url(), '/calls/') ? Http::response(['error' => 'upstream'], 503) : null;
        });
        $this->deliver('call.completed.answered')->assertNoContent();
        $event = TelenowEvent::query()->sole();
        $this->assertSame([1, null], [$event->attempts, $event->applied_at]);
        $this->assertSame(0, Call::query()->count());

        $down = false;
        $this->artisan('kit:events')->assertSuccessful();
        $this->assertNull($event->fresh()->applied_at, 'not before its time');
        $this->travel(config('kit.events.retry_base_seconds'))->seconds();
        $this->artisan('kit:events')->assertSuccessful();
        $this->assertNotNull($event->fresh()->applied_at);
        $this->assertSame(1, Call::query()->count());
    }

    public function test_a_recording_plays_through_the_kit(): void
    {
        $this->deliver('call.completed.answered');
        $call = Call::query()->sole();
        $audio = $this->actingAs($this->ana)->get("/api/kit/console/calls/{$call->id}/recordings/0")->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $recorded = RecordedTelenow::cassettes();
        $wav = base64_decode(collect($recorded)->firstWhere('operationId', 'downloadRecording')['response']['bodyBase64']);
        $this->assertSame($wav, $audio->streamedContent());
        [$file] = $this->sent('GET', $this->org("/recordings/{$this->answered['recordings'][0]['id']}/file"));
        $this->assertSame([self::KEY], $file->header('X-API-Key'));

        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}/recordings/1")->assertNotFound()
            ->assertExactJson(['message' => __('calls.no_recording')]);
        $this->answering(fn (Request $r) => str_contains($r->url(), '/recordings/') ? Http::response(['error' => 'blob store down'], 503) : null);
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}/recordings/0")->assertStatus(503)
            ->assertExactJson(['message' => __('calls.recording_unavailable')]);
    }

    public function test_a_member_sees_only_their_accounts_calls(): void
    {
        $this->deliver('call.completed.answered');
        $other = Workspace::create(['name' => 'Beta Clinic', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new Call)->forceFill(['workspace_id' => $other->id, 'telenow_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'ended', 'recordings' => [['id' => (string) \Illuminate\Support\Str::uuid()]]]);
        $theirs->save();
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls')->assertJsonPath('total', 1);
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$theirs->id}")->assertNotFound()->assertExactJson(['message' => __('calls.not_found')]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$theirs->id}/recordings/0")->assertNotFound();
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls/not-a-uuid')->assertNotFound();
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls?agentId='.$this->agent->id)->assertJsonPath('total', 1);
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls?agentId='.\Illuminate\Support\Str::uuid())->assertJsonPath('total', 0);
        $this->actingAs($this->ana)->getJson('/api/kit/console/calls?agentId=nope')->assertJsonPath('total', 0);

        auth()->guard('web')->logout();
        $this->getJson('/api/kit/console/calls')->assertUnauthorized();
    }

    public function test_the_events_the_kit_handles_are_the_manifests(): void
    {
        $manifest = json_decode((string) file_get_contents(resource_path('kit/manifest.json')), true);
        $events = array_merge(...array_map(fn (array $r) => $r['events'], array_values($manifest['resources'])));
        $this->assertEqualsCanonicalizing($events, array_keys(config('kit.events.handlers')));
    }
}
