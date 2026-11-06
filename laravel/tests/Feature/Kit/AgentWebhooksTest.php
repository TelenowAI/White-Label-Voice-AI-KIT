<?php

namespace Tests\Feature\Kit;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\AgentWebhook;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Sending an agent's finished calls to a customer's web address (App\Kit\Agents\AgentWebhooks), through the kit's real routes. The stand-in for Telenow below does what contract_k4hooks.py recorded of the 11-03
 * release, with its answers taken from those recordings: a secret answered once, an endpoint that outlives its agent, test sends behind a setting, a replay refused until a delivery has finished.
 */
class AgentWebhooksTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const SECRET = 'wh_secret_recorded_placeholder_not_a_real_secret_0001';

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    /** @var array<string, array> Telenow's active endpoints, by id */
    private array $endpoints = [];

    /** @var list<array{method: string, path: string, raw: string, idempotency: ?string, query: ?string}> */
    private array $sent = [];

    /** What requests answer instead, by method and how the path ends: ['POST /test' => [status, body]]. */
    private array $answers = [];

    private int $losesNextCreateAnswer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => 'f5b2ecfe-1a21-4c72-83e2-03ced560e865', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->agent = new Agent(['name' => 'Front desk']);
        $this->agent->workspace_id = $this->acme->id;
        $this->agent->forceFill(['telenow_id' => '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159', 'sync_state' => 'synced'])->save();
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->telenow($r));
    }

    private function telenow(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $this->sent[] = ['method' => $r->method(), 'path' => $path, 'raw' => $r->body(), 'idempotency' => $r->header('Idempotency-Key')[0] ?? null, 'query' => parse_url($r->url(), PHP_URL_QUERY)];
        foreach ($this->answers as $which => [$status, $body]) {
            [$method, $suffix] = explode(' ', $which);
            if ($r->method() === $method && str_ends_with($path, $suffix)) {
                return Http::response($body, $status);
            }
        }
        $root = "/api/orgs/{$this->acme->telenow_sub_account_id}/webhooks";
        if ($path === $root && $r->method() === 'GET') {
            return Http::response(['success' => true, 'data' => ['endpoints' => array_values($this->endpoints), 'total' => count($this->endpoints)]]);
        }
        if ($path === $root && $r->method() === 'POST') {
            $body = $r->data();
            $id = (string) Str::uuid();
            $this->endpoints[$id] = ['id' => $id, 'agent_id' => $body['agentId'] ?? null, 'url' => $body['url'], 'events' => $body['events'], 'include_transcript' => $body['includeTranscript'] ?? false, 'include_recording' => false, 'last_ok_at' => null, 'last_error' => null];
            if ($this->losesNextCreateAnswer-- > 0) {
                return Http::response(['error' => 'the answer was lost'], 503);
            }
            $recorded = RecordedTelenow::body('createWebhookEndpoint.customer');
            $recorded['data']['endpoint'] = $this->endpoints[$id] + $recorded['data']['endpoint'];

            return Http::response($recorded, 201);
        }
        if (preg_match('#/webhooks/([0-9a-f-]{36})$#', $path, $m) === 1 && $r->method() === 'DELETE') {
            unset($this->endpoints[$m[1]]);

            return Http::response(RecordedTelenow::body('disableWebhookEndpoint.customer'));
        }
        if (str_ends_with($path, '/test') && $r->method() === 'POST') {
            return Http::response(RecordedTelenow::body('sendWebhookTest.customer'), 202);
        }
        if (str_ends_with($path, '/deliveries') && $r->method() === 'GET') {
            return Http::response(RecordedTelenow::body('listWebhookDeliveries.customer'));
        }
        if (str_ends_with($path, '/replay') && $r->method() === 'POST') {
            return Http::response(RecordedTelenow::body('sendWebhookTest.customer'), 202);
        }

        throw new \RuntimeException("Nothing stands in for {$r->method()} {$path}");
    }

    private function add(array $over = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$this->agent->id}/webhooks", $over + ['url' => 'https://example.com/hooks/calls', 'events' => ['call.completed']]);
    }

    private function list(): TestResponse
    {
        return $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$this->agent->id}/webhooks");
    }

    private function hook(TestResponse $made): string
    {
        return $made->assertCreated()->json('id');
    }

    /** @return list<array> requests of that method, as sent */
    private function requests(string $method): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === $method));
    }

    public function test_an_address_is_made_at_telenow_for_this_agent_and_its_secret_is_answered_once(): void
    {
        $made = $this->add(['events' => ['call.completed', 'call.analyzed'], 'includeTranscript' => true])->assertCreated();
        $made->assertJsonPath('syncState', 'synced')->assertJsonPath('signingSecret', self::SECRET)->assertJsonPath('events', ['call.completed', 'call.analyzed'])->assertJsonPath('includeTranscript', true);
        $this->assertSame(['url' => 'https://example.com/hooks/calls', 'events' => ['call.completed', 'call.analyzed'], 'agentId' => $this->agent->telenow_id, 'includeTranscript' => true], json_decode($this->requests('POST')[0]['raw'], true));
        $this->assertSame('POST', $this->sent[0]['method'], 'a first try has nothing to clear up, so it reads nothing first');
        $this->assertNull(AgentWebhook::query()->sole()->getRawOriginal('signing_secret'), 'gone from the kit once shown');
        $this->list()->assertOk()->assertJsonPath('webhooks.0.signingSecret', null)->assertJsonPath('events', config('kit.agents.webhook_events'));
    }

    public function test_no_id_of_telenows_is_ever_answered(): void
    {
        $made = $this->add()->assertCreated();
        $id = AgentWebhook::query()->sole()->telenow_id;
        foreach ([$made->getContent(), $this->list()->getContent()] as $answer) {
            $this->assertStringNotContainsString($id, $answer);
            $this->assertStringNotContainsString($this->agent->telenow_id, $answer);
            $this->assertStringNotContainsString('telenow', strtolower($answer));
        }
    }

    public function test_a_secret_waiting_to_be_shown_is_encrypted_and_shown_to_the_next_look_once(): void
    {
        $this->answers = ['POST /webhooks' => [503, ['error' => 'down']]];
        $made = $this->add()->assertCreated()->assertJsonPath('syncState', 'pending')->assertJsonPath('signingSecret', null);
        $this->answers = [];
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $raw = DB::table('agent_webhooks')->value('signing_secret');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::SECRET, $raw);
        $this->list()->assertOk()->assertJsonPath('webhooks.0.id', $made->json('id'))->assertJsonPath('webhooks.0.signingSecret', self::SECRET)->assertJsonPath('webhooks.0.syncState', 'synced');
        $this->list()->assertOk()->assertJsonPath('webhooks.0.signingSecret', null);
    }

    public function test_a_try_after_a_lost_answer_removes_what_the_lost_one_made_and_nothing_else(): void
    {
        $this->add(['url' => 'https://example.com/other'])->assertCreated();
        $other = array_key_first($this->endpoints);
        $this->endpoints['theirs'] = ['id' => 'theirs', 'agent_id' => 'another-agent', 'url' => 'https://example.com/hooks/calls', 'events' => ['*'], 'last_ok_at' => null, 'last_error' => null];
        $this->losesNextCreateAnswer = 1;
        $this->add()->assertCreated()->assertJsonPath('syncState', 'pending');
        $this->assertCount(3, $this->endpoints, 'the lost try did make one');
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertCount(3, $this->endpoints, 'that one went and a new one came, whose secret the kit has');
        $this->assertArrayHasKey($other, $this->endpoints);
        $this->assertArrayHasKey('theirs', $this->endpoints);
        $this->list()->assertJsonPath('webhooks.1.signingSecret', self::SECRET);
        $this->assertCount(1, $this->requests('DELETE'));
    }

    public function test_an_address_for_an_agent_not_at_telenow_yet_waits_for_it_with_no_try_counted(): void
    {
        $this->agent->forceFill(['telenow_id' => null, 'sync_state' => 'pending'])->save();
        $this->add()->assertCreated()->assertJsonPath('syncState', 'pending');
        $this->assertSame([], $this->requests('POST'));
        $this->assertSame(0, OutboxEntry::query()->where('resource', 'agent_webhooks')->sole()->attempts);
        $this->agent->forceFill(['telenow_id' => '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159'])->save();
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame('synced', AgentWebhook::query()->sole()->sync_state);
        $this->assertCount(1, $this->requests('POST'));
    }

    public function test_an_address_removed_before_its_agent_got_there_is_never_made_and_waits_for_nothing(): void
    {
        $this->agent->forceFill(['telenow_id' => null, 'sync_state' => 'pending'])->save();
        $id = $this->hook($this->add());
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}")->assertNoContent();
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame([], [...$this->requests('POST'), ...$this->requests('DELETE')]);
        $this->assertSame(0, OutboxEntry::query()->where('resource', 'agent_webhooks')->whereNull('done_at')->count());
        $this->list()->assertJsonPath('webhooks', []);
    }

    public function test_an_address_removed_while_its_create_waits_is_not_made_and_a_lost_try_is_cleared(): void
    {
        $this->losesNextCreateAnswer = 1;
        $id = $this->hook($this->add());
        $this->assertCount(1, $this->endpoints, 'the lost try did make one');
        $this->assertNull(AgentWebhook::query()->find($id)->telenow_id);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}")->assertNoContent();
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame([], $this->endpoints);
        $this->assertCount(1, $this->requests('POST'), 'never made again');
        $this->assertNull(AgentWebhook::query()->find($id)->getRawOriginal('signing_secret'));
        $this->assertSame(0, OutboxEntry::query()->where('resource', 'agent_webhooks')->whereNull('done_at')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function badAddresses(): array
    {
        return [
            'plain http' => ['http://example.com/x', 'address_https'],
            'a private address' => ['https://10.0.0.5/x', 'address_private'],
            'the cloud metadata address' => ['https://169.254.169.254/x', 'address_private'],
            'a login in it' => ['https://user:pass@example.com/x', 'address_login'],
            'not an address' => ['call me', 'address_shape'],
            'braces' => ['https://example.com/{x}', 'webhook_placeholder'],
        ];
    }

    #[DataProvider('badAddresses')]
    public function test_an_address_that_could_not_work_is_refused_beside_its_field(string $url, string $why): void
    {
        $this->add(['url' => $url])->assertStatus(422)->assertJsonValidationErrors(['url' => __("agents.{$why}")]);
        $this->assertSame([], $this->sent);
        $this->assertSame(0, AgentWebhook::query()->count());
    }

    public function test_only_the_events_that_name_no_platform_can_be_chosen(): void
    {
        foreach ([['call.ended'], ['*'], ['recording.ready'], ['nonsense'], []] as $events) {
            $this->add(['events' => $events])->assertStatus(422)->assertJsonValidationErrors(array_key_exists(0, $events) ? 'events.0' : 'events');
        }
        $this->add(['events' => null])->assertStatus(422);
        $this->add(['includeTranscript' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors('includeTranscript');
        $this->assertSame([], $this->sent);
        $this->assertSame(['call.completed', 'call.analyzed'], config('kit.agents.webhook_events'));
    }

    public function test_the_same_address_twice_and_too_many_are_refused(): void
    {
        $this->add()->assertCreated();
        $this->add()->assertStatus(422)->assertJsonValidationErrors(['url' => __('agents.webhook_twice')]);
        $max = config('kit.agents.limits.webhooks_per_agent_max');
        foreach (range(2, $max) as $i) {
            $this->add(['url' => "https://example.com/{$i}"])->assertCreated();
        }
        $this->add(['url' => 'https://example.com/one-more'])->assertStatus(422)->assertJsonValidationErrors(['url' => __('agents.webhooks_too_many')]);
    }

    public function test_an_address_removed_is_free_to_add_again_and_does_not_count(): void
    {
        $max = config('kit.agents.limits.webhooks_per_agent_max');
        $first = $this->hook($this->add());
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/webhooks/{$first}")->assertNoContent();
        $this->add()->assertCreated();
        foreach (range(2, $max) as $i) {
            $this->add(['url' => "https://example.com/{$i}"])->assertCreated();
        }
    }

    public function test_an_address_telenow_refuses_says_so_in_the_customers_words_and_can_be_removed(): void
    {
        $this->answers = ['POST /webhooks' => [400, RecordedTelenow::body('createWebhookEndpoint.refused')]];
        $made = $this->add()->assertCreated()->assertJsonPath('syncState', 'failed')->assertJsonPath('syncError', __('agents.webhook_refused'));
        $this->assertStringNotContainsString('rejected', $made->getContent());
        $this->sent = [];
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/webhooks/{$made->json('id')}")->assertNoContent();
        $this->assertSame([], $this->sent, 'it never got there, so nothing to remove');
        $this->list()->assertJsonPath('webhooks', []);
        $this->assertNull(OutboxEntry::query()->where('resource', 'agent_webhooks')->whereNull('done_at')->first());
    }

    public function test_removing_an_address_removes_it_at_telenow_and_one_already_gone_is_no_trouble(): void
    {
        $id = $this->hook($this->add());
        $this->assertCount(1, $this->endpoints);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}")->assertNoContent();
        $this->assertSame([], $this->endpoints);
        $this->list()->assertJsonPath('webhooks', []);

        $second = $this->hook($this->add());
        $this->answers = ['DELETE /webhooks/'.AgentWebhook::query()->find($second)->telenow_id => [404, RecordedTelenow::body('sendWebhookTest.gone')]];
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/webhooks/{$second}")->assertNoContent();
        $this->assertSame('synced', AgentWebhook::query()->find($second)->sync_state);
        $this->assertNotNull(AgentWebhook::query()->find($second)->deleted_at);
    }

    public function test_a_removal_telenow_is_down_for_waits_and_is_tried_again(): void
    {
        $id = $this->hook($this->add());
        $at = AgentWebhook::query()->find($id)->telenow_id;
        $this->answers = ["DELETE /webhooks/{$at}" => [503, ['error' => 'down']]];
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}")->assertNoContent();
        $this->assertArrayHasKey($at, $this->endpoints, 'still there');
        $this->assertSame('pending', AgentWebhook::query()->find($id)->sync_state);
        $this->list()->assertJsonPath('webhooks', []);
        $this->answers = [];
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame([], $this->endpoints);
        $this->assertSame('synced', AgentWebhook::query()->find($id)->sync_state);
    }

    public function test_removing_an_agent_removes_its_addresses_as_telenow_would_keep_them(): void
    {
        $this->add()->assertCreated();
        $this->add(['url' => 'https://example.com/two'])->assertCreated();
        $this->assertCount(2, $this->endpoints);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->agent->id}")->assertNoContent();
        $this->assertSame([], $this->endpoints);
    }

    public function test_it_says_how_an_address_is_doing_and_stopped_when_telenow_no_longer_lists_it(): void
    {
        $id = $this->hook($this->add());
        $at = AgentWebhook::query()->find($id)->telenow_id;
        $this->list()->assertJsonPath('webhooks.0.lastOkAt', null)->assertJsonPath('webhooks.0.failing', false)->assertJsonPath('webhooks.0.stopped', false);
        $this->endpoints[$at]['last_ok_at'] = '2026-11-04T05:39:00Z';
        $this->endpoints[$at]['last_error'] = 'transport: error sending request for url';
        $answer = $this->list()->assertJsonPath('webhooks.0.lastOkAt', '2026-11-04T05:39:00Z')->assertJsonPath('webhooks.0.failing', true);
        $this->assertStringNotContainsString('transport', $answer->getContent(), 'its internals stay');
        unset($this->endpoints[$at]);
        $this->list()->assertJsonPath('webhooks.0.stopped', true)->assertJsonPath('webhooks.0.failing', false);
    }

    public function test_when_telenow_cannot_be_read_the_list_still_answers_and_says_nothing_it_does_not_know(): void
    {
        $this->add()->assertCreated();
        $this->answers = ['GET /webhooks' => [500, ['error' => 'down']]];
        $this->list()->assertOk()->assertJsonCount(1, 'webhooks')->assertJsonPath('webhooks.0.stopped', false)->assertJsonPath('webhooks.0.failing', false);
    }

    public function test_an_agent_with_none_reads_nothing_from_telenow(): void
    {
        $this->list()->assertOk()->assertJsonPath('webhooks', []);
        $this->assertSame([], $this->sent);
    }

    public function test_a_test_send_names_the_event_and_answers_the_latest_tries(): void
    {
        $id = $this->hook($this->add());
        $this->sent = [];
        $url = "/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}";
        $answer = $this->actingAs($this->ana)->postJson("{$url}/test", ['event' => 'call.completed'])->assertStatus(202);
        $this->assertSame(['eventType' => 'call.completed'], json_decode($this->requests('POST')[0]['raw'], true));
        $answer->assertJsonPath('deliveries.0.event', 'call.completed')->assertJsonPath('deliveries.0.kind', 'test');
        $this->actingAs($this->ana)->postJson("{$url}/test", ['event' => 'call.analyzed'])->assertStatus(422)->assertJsonValidationErrors('event');
        $this->actingAs($this->ana)->postJson("{$url}/test", [])->assertStatus(422);
    }

    public function test_a_refused_test_or_replay_says_why_in_the_customers_words(): void
    {
        $id = $this->hook($this->add());
        $url = "/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}";
        $d = (string) Str::uuid();
        foreach ([[503, ['code' => 'webhook_manual_send_unconfigured', 'error' => 'test events and replays are off: WEBHOOK_MANUAL_SENDS_PER_HOUR is not set'], 'webhook_test_off'], [429, ['error' => 'hourly budget'], 'webhook_test_busy'], [400, ['code' => 'webhook_url_refused', 'error' => 'webhook url rejected: resolves to a private address'], 'webhook_refused'], [404, ['error' => 'not found'], 'webhook_unreadable']] as [$status, $body, $words]) {
            $this->answers = ['POST /test' => [$status, $body], 'POST /replay' => [$status, $body]];
            $this->actingAs($this->ana)->postJson("{$url}/test", ['event' => 'call.completed'])->assertStatus(409)->assertExactJson(['message' => __("agents.{$words}")]);
            $this->actingAs($this->ana)->postJson("{$url}/deliveries/{$d}/replay")->assertStatus(409)->assertExactJson(['message' => __("agents.{$words}")]);
        }
        $this->answers = ['POST /replay' => [409, RecordedTelenow::body('replayWebhookDelivery.not-finished')]];
        $this->actingAs($this->ana)->postJson("{$url}/deliveries/{$d}/replay")->assertStatus(409)->assertExactJson(['message' => __('agents.webhook_still_trying')]);
    }

    public function test_an_address_not_at_telenow_yet_can_not_be_tested_and_has_no_tries(): void
    {
        $this->agent->forceFill(['telenow_id' => null])->save();
        $id = $this->hook($this->add());
        $url = "/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}";
        $this->actingAs($this->ana)->postJson("{$url}/test", ['event' => 'call.completed'])->assertStatus(409)->assertExactJson(['message' => __('agents.not_ready')]);
        $this->actingAs($this->ana)->postJson("{$url}/deliveries/".Str::uuid().'/replay')->assertStatus(409);
        $this->actingAs($this->ana)->getJson("{$url}/deliveries")->assertOk()->assertJsonPath('deliveries', []);
    }

    public function test_the_latest_tries_say_how_each_went_and_never_what_was_in_it(): void
    {
        $id = $this->hook($this->add());
        $url = "/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}/deliveries";
        $recorded = RecordedTelenow::body('listWebhookDeliveries.customer');
        $answer = $this->actingAs($this->ana)->getJson($url)->assertOk();
        $this->assertSame(config('kit.agents.webhook_deliveries_shown'), (int) str_replace('limit=', '', collect($this->sent)->last()['query']));
        $answer->assertJsonPath('deliveries.0.id', $recorded['data']['deliveries'][0]['id'])->assertJsonPath('deliveries.0.status', 'failed')->assertJsonPath('deliveries.0.problem', __('agents.webhook_problems.connection_refused'))
            ->assertJsonPath('deliveries.0.canSendAgain', false)->assertJsonPath('deliveries.0.attempt', 1);
        foreach (['sample-customer-42', 'transport', 'payload', 'example.com', 'response_body'] as $kept) {
            $this->assertStringNotContainsString($kept, $answer->getContent());
        }
        $row = fn (array $over) => $over + ['id' => 'd', 'event_type' => 'call.completed', 'attempt' => 2, 'created_at' => '2026-11-04T05:39:00Z', 'delivery_kind' => null];
        $this->answers = ['GET /deliveries' => [200, ['success' => true, 'data' => ['deliveries' => [
            $row(['status' => 'delivered', 'response_status' => 200]),
            $row(['status' => 'dead', 'response_status' => 500, 'error' => 'HTTP 500']),
            $row(['status' => 'dead', 'error' => 'odd', 'error_kind' => 'something_new']),
            $row(['status' => 'pending']),
        ], 'total' => 4]]]];
        $tries = $this->actingAs($this->ana)->getJson($url)->assertOk()->json('deliveries');
        $this->assertSame([[null, true, 200], [__('agents.webhook_answered', ['status' => 500]), true, 500], [__('agents.webhook_problems.other'), true, null], [null, false, null]], array_map(fn (array $d) => [$d['problem'], $d['canSendAgain'], $d['responseStatus']], $tries));
        $this->answers = ['GET /deliveries' => [500, ['error' => 'down']]];
        $this->actingAs($this->ana)->getJson($url)->assertStatus(409)->assertExactJson(['message' => __('agents.webhook_unreadable')]);
    }

    public function test_sending_one_again_is_one_send_per_click(): void
    {
        $id = $this->hook($this->add());
        $url = "/api/kit/console/agents/{$this->agent->id}/webhooks/{$id}/deliveries/".Str::uuid().'/replay';
        $this->sent = [];
        $this->actingAs($this->ana)->postJson($url)->assertStatus(202)->assertJsonStructure(['deliveries']);
        $this->actingAs($this->ana)->postJson($url)->assertStatus(202);
        $keys = array_column($this->requests('POST'), 'idempotency');
        $this->assertCount(2, array_unique(array_filter($keys)), 'Telenow requires a key, and two clicks are two sends');
    }

    public function test_someone_elses_agent_or_address_is_not_found(): void
    {
        $id = $this->hook($this->add());
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new Agent(['name' => 'Theirs']);
        $theirs->workspace_id = $other->id;
        $theirs->save();
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$theirs->id}/webhooks")->assertNotFound();
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$theirs->id}/webhooks", ['url' => 'https://example.com/x', 'events' => ['call.completed']])->assertNotFound();
        $second = new Agent(['name' => 'Second']);
        $second->workspace_id = $this->acme->id;
        $second->save();
        foreach ([$id, 'not-a-uuid', (string) Str::uuid()] as $hook) {
            $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$second->id}/webhooks/{$hook}")->assertNotFound()->assertExactJson(['message' => __('agents.webhook_not_found')]);
            $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$second->id}/webhooks/{$hook}/deliveries")->assertNotFound();
            $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$second->id}/webhooks/{$hook}/test", ['event' => 'call.completed'])->assertNotFound();
        }
        $this->assertCount(1, $this->endpoints);
    }

    public function test_signed_out_is_refused(): void
    {
        $this->getJson("/api/kit/console/agents/{$this->agent->id}/webhooks")->assertUnauthorized();
        $stranger = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->getJson("/api/kit/console/agents/{$this->agent->id}/webhooks")->assertForbidden();
    }

    public function test_the_recordings_are_what_this_rests_on(): void
    {
        $this->assertSame(self::SECRET, RecordedTelenow::body('createWebhookEndpoint.customer')['data']['signing_secret']);
        $this->assertSame(['call.completed', 'call.analyzed'], RecordedTelenow::body('createWebhookEndpoint.customer')['data']['endpoint']['events']);
        $this->assertStringContainsString('webhook url rejected', RecordedTelenow::body('createWebhookEndpoint.refused')['error']);
        $this->assertSame('webhook_delivery_not_finished', RecordedTelenow::body('replayWebhookDelivery.not-finished')['code']);
        $this->assertSame('webhook_event_not_subscribed', RecordedTelenow::body('sendWebhookTest.not-subscribed')['code']);
        $this->assertSame('connection_refused', RecordedTelenow::body('listWebhookDeliveries.customer')['data']['deliveries'][0]['error_kind']);
    }
}
