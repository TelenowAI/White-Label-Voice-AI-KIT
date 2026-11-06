<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentFlow;
use App\Kit\Agents\AgentSettings;
use App\Kit\Agents\AgentTransfers;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Models\Agent;
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
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * An agent's call steps (App\Kit\Agents\AgentFlow, AgentFlows), through the kit's real routes. The stand-in for Telenow below does what contract_k6flow.py recorded of the 11-03 release: a graph stored as sent, keys in a step
 * masked on reading and kept while its address reads the same, and a separate check that never refuses a request, only reports. The recorded graph of every kind is what the kit's is compared with.
 */
class AgentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const AGENT = '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159';

    private const SECOND = '7d2c9e14-5a3b-4f60-8e21-9c4b6a1f0d37';

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    private Agent $billing;

    /** @var array<string, mixed> the agent as Telenow holds it, keys in plain */
    private array $held;

    /** @var list<array{method: string, path: string, body: array, ifMatch: ?string}> */
    private array $sent = [];

    /** What requests answer instead, by method and how the path ends. */
    private array $answers = [];

    /** @var array<string, mixed> what the check answers */
    private array $verdict = ['valid' => true, 'errors' => [], 'warnings' => [], 'multiContext' => true];

    private int $version = 1;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, true);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => 'f5b2ecfe-1a21-4c72-83e2-03ced560e865', 'telenow_key' => 'vai_test_customer_acme'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->held = ['id' => self::AGENT, 'name' => 'Front desk', 'system_prompt' => 'You are the front desk.', 'is_active' => true, 'llm_provider' => 'openai', 'llm_model' => 'gpt-5.4-mini', 'stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy',
            'telephony_config' => ['recording' => true], 'session_config' => ['maxDuration' => 600], 'metadata' => ['kitId' => 'kit-agent-1', 'callerIdentity' => ['enabled' => true]]];
        $this->agent = $this->agentNamed('Front desk', self::AGENT, $this->held);
        $this->billing = $this->agentNamed('Billing desk', self::SECOND);
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->telenow($r));
    }

    private function agentNamed(string $name, string $telenowId, ?array $snapshot = null): Agent
    {
        $agent = new Agent(['name' => $name]);
        $agent->workspace_id = $this->acme->id;
        $agent->forceFill(['telenow_id' => $telenowId, 'sync_state' => 'synced', 'telenow_version' => 1] + ($snapshot === null ? [] : ['telenow_snapshot' => $snapshot, 'settings' => app(AgentSettings::class)->read($snapshot)]))->save();

        return $agent;
    }

    private function telenow(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'ifMatch' => $r->header('If-Match')[0] ?? null];
        foreach ($this->answers as $which => [$status, $body]) {
            [$method, $suffix] = explode(' ', $which);
            if ($r->method() === $method && str_ends_with($path, $suffix)) {
                return Http::response($body, $status);
            }
        }
        if ($path === '/api/agents/flow/validate') {
            return Http::response(['success' => true, 'data' => $this->verdict]);
        }
        $root = '/api/agents/'.self::AGENT;
        if ($path === "{$root}/versions") {
            return Http::response(['success' => true, 'data' => ['versions' => [['version' => $this->version, 'note' => 'update']]]]);
        }
        if ($path === $root && $r->method() === 'GET') {
            return Http::response(['success' => true, 'data' => $this->masked($this->held)]);
        }
        if ($path === $root && $r->method() === 'PUT') {
            return $this->saved($r->data());
        }

        throw new \RuntimeException("Nothing stands in for {$r->method()} {$path}");
    }

    /** As recorded: a mask keeps a key only while its address reads the same. Else the whole save is refused. */
    private function saved(array $body): mixed
    {
        if (isset($body['metadata'])) {
            foreach ($body['metadata']['flow']['nodes'] ?? [] as $i => $node) {
                if (str_starts_with((string) ($node['config']['bearer'] ?? ''), '••••')) {
                    $old = collect($this->held['metadata']['flow']['nodes'] ?? [])->first(fn (array $n) => ($n['config']['name'] ?? null) === $node['config']['name'] && ($n['config']['endpoint'] ?? null) === ($node['config']['endpoint'] ?? null));
                    if ($old === null) {
                        return Http::response(RecordedTelenow::body('updateAgent.flow-key-moved'), 400);
                    }
                    $body['metadata']['flow']['nodes'][$i]['config']['bearer'] = $old['config']['bearer'];
                }
            }
            $this->held['metadata'] = $body['metadata'];
        }
        $this->version++;

        return Http::response(['success' => true, 'data' => $this->masked($this->held), 'version' => $this->version]);
    }

    private function masked(array $held): array
    {
        foreach ($held['metadata']['flow']['nodes'] ?? [] as $i => $node) {
            if (isset($node['config']['bearer'])) {
                $held['metadata']['flow']['nodes'][$i]['config']['bearer'] = '••••'.substr($node['config']['bearer'], -4);
            }
        }

        return $held;
    }

    private function url(string $rest = ''): string
    {
        return "/api/kit/console/agents/{$this->agent->id}/flow{$rest}";
    }

    private function publish(array $flow): TestResponse
    {
        return $this->actingAs($this->ana)->postJson($this->url('/publish'), ['flow' => $flow]);
    }

    private function draft(array $flow): TestResponse
    {
        return $this->actingAs($this->ana)->putJson($this->url('/draft'), ['flow' => $flow]);
    }

    private function step(string $id, string $kind, array $over = []): array
    {
        return $over + ['id' => $id, 'kind' => $kind, 'name' => ucfirst($id)];
    }

    private function exit(string $id, string $from, string $to, array $when, ?string $label = null): array
    {
        return ['id' => $id, 'from' => $from, 'to' => $to, 'label' => $label, 'when' => $when];
    }

    /** Two talking steps and an ending: the least that is fine. */
    private function small(array $firstStep = [], array $exits = []): array
    {
        return ['start' => 'greet', 'steps' => [$this->step('greet', 'conversation', $firstStep + ['instructions' => 'Greet them.']), $this->step('bye', 'end', ['line' => 'Goodbye.'])],
            'exits' => $exits === [] ? [$this->exit('e1', 'greet', 'bye', ['kind' => 'ai', 'describe' => 'the caller is done'])] : $exits];
    }

    private function functionStep(array $tool = []): array
    {
        return $this->step('lookup', 'tool', ['tool' => $tool + ['name' => 'lookup_order', 'url' => 'https://example.com/orders', 'token' => 'tok_test_flow_step_1111']]);
    }

    /** One Function step reached from a talking one. */
    private function withFunction(array $tool = []): array
    {
        return ['start' => 'greet', 'steps' => [$this->step('greet', 'conversation', ['instructions' => 'Greet them.']), $this->functionStep($tool)],
            'exits' => [$this->exit('e1', 'greet', 'lookup', ['kind' => 'ai', 'describe' => 'the caller gave the order'])]];
    }

    private function puts(): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'PUT'));
    }

    private function refused(TestResponse $answer, string $field, ?string $words = null): void
    {
        $answer->assertStatus(422);
        $this->assertArrayHasKey($field, $answer->json('errors'), (string) json_encode($answer->json('errors')));
        if ($words !== null) {
            $this->assertSame([$words], $answer->json('errors')[$field]);
        }
    }

    /** Every kind offered, in the kit's words, as the recorded graph has them. */
    private function everyKind(): array
    {
        return ['start' => 'menu', 'steps' => [
            $this->step('menu', 'dtmf', ['name' => 'Main menu', 'x' => 0, 'y' => 0, 'line' => 'For booking press 1. For a person press 2.', 'keypad' => ['routeOnly' => true, 'only' => true]]),
            $this->step('ask', 'extract', ['name' => 'Ask', 'x' => 200, 'y' => 0, 'instructions' => 'Ask for the order number.', 'line' => 'What is your order number?', 'capture' => [['name' => 'order_id', 'type' => 'string', 'description' => 'the order number', 'required' => true]]]),
            $this->step('lookup', 'tool', ['name' => 'Look up', 'x' => 400, 'y' => 0, 'sends' => ['order_id'], 'results' => [['path' => 'order.status', 'variable' => 'order_status']],
                'tool' => ['name' => 'lookup_order', 'description' => 'Look an order up.', 'url' => 'https://example.com/orders', 'token' => 'tok_test_flow_step_1111', 'sayWhileRunning' => 'Let me check.', 'timeoutSecs' => 10, 'parameters' => [['name' => 'order_id', 'type' => 'string', 'required' => true]]]]),
            $this->step('sum', 'code', ['name' => 'Work out', 'x' => 600, 'y' => 0, 'code' => ['source' => 'return { total: Number(dv.a || 0) + 1 };', 'timeoutMs' => 2000, 'keep' => [['field' => 'total', 'variable' => 'order_total']]]]),
            $this->step('split', 'router', ['name' => 'Which', 'x' => 800, 'y' => 0]),
            $this->step('tell', 'conversation', ['name' => 'Tell', 'x' => 1000, 'y' => 0, 'instructions' => 'Your order is {order_status}.']),
            $this->step('say', 'static', ['name' => 'Sorry', 'x' => 1000, 'y' => 200, 'line' => 'Sorry, I cannot reach that just now.']),
            $this->step('person', 'transfer', ['name' => 'A person', 'x' => 1200, 'y' => 0, 'anywhere' => ['when' => 'the caller asks to speak to a person', 'examples' => ['can I talk to someone']],
                'transfer' => ['destinations' => [['label' => 'Reception', 'numbers' => ['+14155550199'], 'mode' => 'serial']], 'message' => 'Connecting you.', 'noAnswerMessage' => 'Nobody is free.']]),
            $this->step('billing', 'agent', ['name' => 'Billing', 'x' => 1200, 'y' => 200, 'handoff' => ['agents' => [['agentId' => $this->billing->id, 'label' => 'Billing', 'mode' => 'transfer']], 'message' => 'One moment.']]),
            $this->step('bye', 'end', ['name' => 'Goodbye', 'x' => 1400, 'y' => 0, 'line' => 'Goodbye.']),
            $this->step('memo', 'note', ['name' => 'A note', 'x' => 0, 'y' => 300, 'instructions' => 'Remember to change the hours.']),
        ], 'exits' => [
            $this->exit('k1', 'menu', 'ask', ['kind' => 'dtmf', 'digit' => '1'], 'Booking'), $this->exit('k2', 'menu', 'person', ['kind' => 'dtmf', 'digit' => '2'], 'A person'),
            $this->exit('a1', 'ask', 'lookup', ['kind' => 'equation', 'expr' => "{order_id} != ''"]), $this->exit('l1', 'lookup', 'sum', ['kind' => 'tool_result', 'match' => 'ok']), $this->exit('l2', 'lookup', 'say', ['kind' => 'fallback']),
            $this->exit('s1', 'sum', 'split', ['kind' => 'always']), $this->exit('r1', 'split', 'tell', ['kind' => 'equation', 'expr' => '{order_total} > 0']), $this->exit('r2', 'split', 'billing', ['kind' => 'fallback']),
            $this->exit('t1', 'tell', 'bye', ['kind' => 'ai', 'describe' => 'the caller has nothing more to ask']), $this->exit('y1', 'say', 'bye', ['kind' => 'always']),
        ]];
    }

    public function test_every_kind_is_written_as_the_graph_the_release_was_recorded_taking(): void
    {
        $this->publish($this->everyKind())->assertOk()->assertJsonPath('syncState', 'synced');
        $flow = $this->puts()[0]['body']['metadata']['flow'];
        $recorded = RecordedTelenow::body('updateAgent.flow-every-kind')['data']['metadata']['flow'];
        foreach ($recorded['nodes'] as $i => $node) {
            unset($node['config']['bearer']);
            $mine = $flow['nodes'][$i];
            unset($mine['config']['bearer']);
            // The recording spelled two things out the kit leaves to Telenow's default, and the kit's own id goes beside a hand-over's.
            unset($node['continueAfterEntry'], $node['promptMode'], $node['globalGoBack']);
            if ($node['id'] === 'billing') {
                $node['config']['agents'][0]['kitAgentId'] = $this->billing->id;
            }
            $this->assertEquals($node, $mine, "step {$node['id']}");
        }
        $this->assertEquals(array_map(fn (array $e) => array_diff_key($e, ['priority' => 1]), $recorded['edges']), array_map(fn (array $e) => array_diff_key($e, ['priority' => 1]), $flow['edges']));
        $this->assertSame($recorded['startNodeId'], $flow['startNodeId']);
        $this->assertSame(1, $flow['schema']);
        $this->assertSame('tok_test_flow_step_1111', collect($flow['nodes'])->firstWhere('id', 'lookup')['config']['bearer']);
    }

    public function test_the_types_are_the_exact_ones_one_wrong_type_would_lose_the_graph_over(): void
    {
        $this->publish($this->everyKind())->assertOk();
        $flow = $this->puts()[0]['body']['metadata']['flow'];
        $this->assertIsInt($flow['schema']);
        foreach ($flow['edges'] as $edge) {
            $this->assertIsInt($edge['priority']);
            $this->assertIsString($edge['id']);
            $this->assertIsArray($edge['condition']);
        }
        foreach ($flow['nodes'] as $node) {
            $this->assertIsNumeric($node['position']['x']);
        }
        $this->assertTrue(collect($flow['nodes'])->firstWhere('id', 'ask')['extract'][0]['required']);
        $this->assertSame(['dtmfRouteOnly' => true, 'keypadOnly' => true], $flow['nodes'][0]['guards']);
    }

    public function test_an_exits_priority_is_its_place_among_those_of_its_step(): void
    {
        $this->publish($this->everyKind())->assertOk();
        $edges = array_column($this->puts()[0]['body']['metadata']['flow']['edges'], 'priority', 'id');
        $this->assertSame([0, 1], [$edges['k1'], $edges['k2']]);
        $this->assertSame([0, 1], [$edges['l1'], $edges['l2']]);
        $this->assertSame(0, $edges['a1']);
    }

    public function test_a_hand_over_goes_by_telenows_id_with_the_kits_beside_it(): void
    {
        $this->publish($this->everyKind())->assertOk();
        $node = collect($this->puts()[0]['body']['metadata']['flow']['nodes'])->firstWhere('id', 'billing');
        $this->assertSame([['agentId' => self::SECOND, 'kitAgentId' => $this->billing->id, 'label' => 'Billing', 'mode' => 'transfer']], $node['config']['agents']);
    }

    public function test_what_was_published_reads_back_the_same_and_publishing_it_again_sends_nothing(): void
    {
        $answer = $this->publish($this->everyKind())->assertOk();
        $published = $answer->json('published');
        $this->assertSame(['id', 'kind', 'name', 'x', 'y', 'instructions', 'replaceInstructions', 'line', 'continueAfterLine', 'capture', 'keypad', 'anywhere', 'tool', 'results', 'sends', 'code', 'transfer', 'handoff'], array_keys($published['steps'][0]));
        $this->actingAs($this->ana)->getJson($this->url())->assertOk()->assertJsonPath('published', $published)->assertJsonPath('draft', null);
        $this->sent = [];
        $again = $this->publish($published)->assertOk();
        $this->assertSame($published, $again->json('published'));
        $this->assertSame([], $this->puts());
    }

    public function test_moving_a_step_counts_as_a_change_and_a_part_of_a_pixel_is_kept(): void
    {
        $back = $this->publish($this->small())->assertOk()->json('published');
        $back['steps'][0]['x'] = 12.5;
        $back['steps'][0]['y'] = 40;
        $this->sent = [];
        $this->publish($back)->assertOk()->assertJsonPath('published.steps.0.x', 12.5)->assertJsonPath('published.steps.0.y', 40);
        $this->assertCount(1, $this->puts());
    }

    public function test_replacing_the_agents_instructions_and_going_on_after_the_line_are_written_and_read_back(): void
    {
        $back = $this->publish($this->small(['replaceInstructions' => true, 'line' => 'Hello.', 'continueAfterLine' => true]))->assertOk()
            ->assertJsonPath('published.steps.0.replaceInstructions', true)->assertJsonPath('published.steps.0.continueAfterLine', true)->json('published');
        $node = $this->puts()[0]['body']['metadata']['flow']['nodes'][0];
        $this->assertSame('replace', $node['promptMode']);
        $this->assertTrue($node['continueAfterEntry']);
        $back['steps'][0]['replaceInstructions'] = false;
        $back['steps'][0]['continueAfterLine'] = false;
        $this->publish($back)->assertOk();
        $node = $this->held['metadata']['flow']['nodes'][0];
        $this->assertArrayNotHasKey('promptMode', $node);
        $this->assertArrayNotHasKey('continueAfterEntry', $node);
    }

    public function test_a_graph_held_at_telenow_reads_with_tidy_places_and_exits_in_their_order(): void
    {
        $read = app(AgentFlow::class)->read(['flow' => ['schema' => 1, 'startNodeId' => 'a', 'nodes' => [
            ['id' => 'a', 'kind' => 'conversation', 'position' => ['x' => 12.3456, 'y' => 40.0]],
            ['id' => 'b', 'kind' => 'end'],
        ], 'edges' => [
            ['id' => 'middle', 'from' => 'a', 'to' => 'b', 'priority' => 1, 'condition' => ['kind' => 'fallback']],
            ['id' => 'first', 'from' => 'a', 'to' => 'b', 'priority' => 0, 'condition' => ['kind' => 'always']],
            ['id' => 'last', 'from' => 'a', 'to' => 'b', 'priority' => 2, 'condition' => ['kind' => 'fallback']],
        ]]], app(AgentSettings::class));
        $this->assertSame([12.35, 40], [$read['steps'][0]['x'], $read['steps'][0]['y']]);
        $this->assertSame(['first', 'middle', 'last'], array_column($read['exits'], 'id'));
    }

    public function test_a_step_keeps_only_what_the_kit_writes(): void
    {
        $flow = $this->small(['model' => ['provider' => 'dear', 'model' => 'very-dear'], 'voice' => ['provider' => 'x', 'voice' => 'y'], 'tools' => [['kind' => 'transfer', 'name' => 'sneak']], 'guards' => ['silenceHangupSecs' => 1], 'behavior' => ['tone' => 'x'], 'stt' => ['provider' => 'x'], 'position' => ['x' => 1]]);
        $flow['routerModel'] = ['provider' => 'dear', 'model' => 'very-dear'];
        $flow['exits'][0]['replyContext'] = 'say this';
        $this->publish($flow)->assertOk();
        $graph = $this->puts()[0]['body']['metadata']['flow'];
        $this->assertSame(['id', 'kind', 'name', 'prompt'], array_keys($graph['nodes'][0]));
        $this->assertArrayNotHasKey('routerModel', $graph);
        $this->assertArrayNotHasKey('replyContext', $graph['edges'][0]);
    }

    public function test_the_rest_of_the_agents_metadata_stays(): void
    {
        $this->held['metadata']['variables'] = [['name' => 'plan', 'required' => false]];
        $this->publish($this->small())->assertOk();
        $this->assertSame([['name' => 'plan', 'required' => false]], $this->held['metadata']['variables']);
        $this->assertSame('kit-agent-1', $this->held['metadata']['kitId']);
    }

    public function test_an_agent_with_no_steps_reads_as_having_none_and_its_answer_says_so(): void
    {
        $this->actingAs($this->ana)->getJson($this->url())->assertOk()->assertJsonPath('published', null)->assertJsonPath('draft', null)->assertJsonPath('otherAgents', [['id' => $this->billing->id, 'name' => 'Billing desk']]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertOk()->assertJsonPath('settings.flow', null);
        $this->assertSame([], $this->sent);
    }

    public function test_a_draft_stays_here_need_not_be_finished_and_does_not_touch_what_calls_use(): void
    {
        $unfinished = ['start' => null, 'steps' => [$this->step('a', 'conversation'), $this->step('b', 'static'), $this->step('c', 'tool', ['tool' => ['name' => null]])], 'exits' => [$this->exit('e', 'a', 'gone', ['kind' => 'ai'])]];
        $answer = $this->draft($unfinished)->assertOk();
        $this->assertSame(['a', 'b', 'c'], array_column($answer->json('draft.steps'), 'id'));
        $answer->assertJsonPath('published', null)->assertJsonPath('draftSavedAt', fn ($at) => is_string($at));
        $this->assertSame([], $this->sent, 'nothing goes to Telenow');
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('draft.exits.0.to', 'gone');
    }

    public function test_a_draft_still_needs_fields_of_the_right_kind(): void
    {
        $this->refused($this->draft(['steps' => [$this->step('a', 'wizard')], 'exits' => []]), 'flow.steps.0.kind');
        $this->refused($this->draft(['steps' => [$this->step('a b', 'end')], 'exits' => []]), 'flow.steps.0.id');
        $this->refused($this->draft(['steps' => [$this->step('a', 'static', ['line' => ['x' => 1]])], 'exits' => []]), 'flow.steps.0.line');
        $this->refused($this->draft(['steps' => [$this->step('a', 'end', ['x' => 'left'])], 'exits' => []]), 'flow.steps.0.x');
        $this->refused($this->draft(['steps' => [], 'exits' => [['id' => 'e', 'from' => 'a', 'to' => 'b', 'when' => ['kind' => 'magic']]]]), 'flow.exits.0.when.kind');
        $this->refused($this->draft(['steps' => 'none', 'exits' => []]), 'flow.steps');
        $this->assertNull($this->agent->fresh()->flow_draft);
    }

    public function test_discarding_a_draft_leaves_what_calls_use(): void
    {
        $this->publish($this->small())->assertOk();
        $this->draft($this->small(['name' => 'Changed']))->assertOk()->assertJsonPath('draft.steps.0.name', 'Changed');
        $this->sent = [];
        $this->actingAs($this->ana)->deleteJson($this->url('/draft'))->assertOk()->assertJsonPath('draft', null)->assertJsonPath('published.steps.0.name', 'Greet');
        $this->assertSame([], $this->sent);
    }

    public function test_publishing_clears_the_draft(): void
    {
        $this->draft($this->small())->assertOk();
        $this->publish($this->small())->assertOk()->assertJsonPath('draft', null)->assertJsonPath('draftSavedAt', null);
        $this->assertNull($this->agent->fresh()->flow_draft);
    }

    public function test_a_key_typed_into_a_draft_is_sealed_at_once_and_reaches_telenow_only_on_publishing(): void
    {
        $answer = $this->draft($this->withFunction())->assertOk();
        $this->assertStringNotContainsString('tok_test_flow', $answer->getContent());
        $answer->assertJsonPath('draft.steps.1.tool.tokenHint', '••••1111');
        $this->assertArrayNotHasKey('sealed', $answer->json('draft.steps.1.tool'));
        $this->assertArrayNotHasKey('sealed', $this->actingAs($this->ana)->getJson($this->url())->assertOk()->json('draft.steps.1.tool'));
        $this->assertStringNotContainsString('tok_test_flow', (string) DB::table('agents')->where('id', $this->agent->id)->value('flow_draft'));
        $this->assertSame([], $this->sent);
        // The page sends the step back with only the hint, as a person publishing their saved draft does.
        $back = $answer->json('draft');
        $published = $this->publish($back)->assertOk();
        $this->assertSame('tok_test_flow_step_1111', $this->puts()[0]['body']['metadata']['flow']['nodes'][1]['config']['bearer']);
        $this->assertStringNotContainsString('tok_test_flow', $published->getContent());
        $published->assertJsonPath('published.steps.1.tool.tokenHint', '••••1111');
        foreach (['settings', 'telenow_snapshot', 'flow_draft'] as $column) {
            $this->assertStringNotContainsString('tok_test_flow', (string) json_encode($this->agent->fresh()->getAttribute($column)));
        }
        $this->assertStringNotContainsString('tok_test_flow', (string) json_encode($this->agent->fresh()->settings));
    }

    public function test_a_draft_with_details_and_a_customised_request_publishes_as_it_was_written(): void
    {
        $tool = ['name' => 'look', 'url' => 'https://example.com/{order_id}', 'parameters' => [['name' => 'order_id', 'type' => 'string']], 'request' => ['method' => 'GET', 'headers' => [['name' => 'Accept', 'value' => 'application/json']]]];
        $saved = $this->draft(['start' => 'a', 'steps' => [$this->step('a', 'tool', ['tool' => $tool + ['token' => 'tok_test_flow_step_1111']])], 'exits' => []])->assertOk();
        $saved->assertJsonPath('draft.steps.0.tool.parameters.0', ['name' => 'order_id', 'type' => 'string', 'description' => null, 'required' => false])->assertJsonPath('draft.steps.0.tool.request.body', null);
        $this->publish($saved->json('draft'))->assertOk()->assertJsonPath('published.steps.0.tool.parameters.0.name', 'order_id');
        $config = $this->puts()[0]['body']['metadata']['flow']['nodes'][0]['config'];
        $this->assertSame(['type' => 'string'], $config['parameters']['properties']['order_id']);
        $this->assertSame(['method' => 'GET', 'url' => 'https://example.com/{order_id}', 'headers' => ['Accept' => 'application/json']], $config['request']);
        $this->assertSame('tok_test_flow_step_1111', $config['bearer']);
    }

    public function test_a_key_in_a_header_is_sealed_in_a_draft_too(): void
    {
        $tool = ['request' => ['method' => 'POST', 'headers' => [['name' => 'Authorization', 'value' => 'Bearer hdr_test_5678'], ['name' => 'Accept', 'value' => 'application/json']], 'body' => null]];
        $answer = $this->draft($this->withFunction($tool + ['token' => null]))->assertOk();
        $this->assertStringNotContainsString('hdr_test', $answer->getContent());
        $this->assertStringNotContainsString('hdr_test', (string) DB::table('agents')->where('id', $this->agent->id)->value('flow_draft'));
        $this->publish($answer->json('draft'))->assertOk();
        $node = $this->puts()[0]['body']['metadata']['flow']['nodes'][1]['config'];
        $this->assertSame('Bearer hdr_test_5678', $node['request']['headers']['Authorization']);
        $this->assertSame('application/json', $node['request']['headers']['Accept']);
    }

    public function test_a_saved_key_stays_through_changes_and_goes_back_as_its_mask(): void
    {
        $answer = $this->publish($this->withFunction())->assertOk();
        $this->sent = [];
        $back = $answer->json('published');
        $back['steps'][1]['name'] = 'Renamed';
        $this->publish($back)->assertOk()->assertJsonPath('published.steps.1.tool.tokenHint', '••••1111');
        $this->assertSame('••••1111', $this->puts()[0]['body']['metadata']['flow']['nodes'][1]['config']['bearer']);
        $this->assertSame('tok_test_flow_step_1111', $this->held['metadata']['flow']['nodes'][1]['config']['bearer']);
    }

    public function test_a_saved_key_is_refused_beside_its_field_when_its_address_changes_before_telenow_would_refuse_all_of_it(): void
    {
        $back = $this->publish($this->withFunction())->assertOk()->json('published');
        $this->sent = [];
        $back['steps'][1]['tool']['url'] = 'https://example.org/orders';
        $this->refused($this->publish($back), 'flow.steps.1.tool.token', __('agents.key_stays_with_address'));
        $this->assertSame([], $this->puts());
    }

    public function test_a_new_key_may_go_to_a_new_address_and_a_key_can_be_taken_away(): void
    {
        $back = $this->publish($this->withFunction())->assertOk()->json('published');
        $back['steps'][1]['tool']['url'] = 'https://example.org/orders';
        $back['steps'][1]['tool']['token'] = 'tok_test_flow_step_2222';
        $again = $this->publish($back)->assertOk()->json('published');
        $this->assertSame('tok_test_flow_step_2222', $this->held['metadata']['flow']['nodes'][1]['config']['bearer']);
        $again['steps'][1]['tool']['token'] = null;
        $this->publish($again)->assertOk()->assertJsonPath('published.steps.1.tool.tokenHint', null);
        $this->assertArrayNotHasKey('bearer', $this->held['metadata']['flow']['nodes'][1]['config']);
    }

    public function test_a_key_taken_away_in_a_draft_stays_away_when_it_is_published(): void
    {
        $back = $this->publish($this->withFunction())->assertOk()->json('published');
        $back['steps'][1]['tool']['token'] = null;
        // What the page holds from here is the draft as answered, which says nothing of a token.
        $saved = $this->draft($back)->assertOk()->assertJsonPath('draft.steps.1.tool.tokenHint', null)->json('draft');
        $this->assertArrayHasKey('bearer', $this->held['metadata']['flow']['nodes'][1]['config']);
        $this->assertArrayNotHasKey('token', $saved['steps'][1]['tool']);
        $this->publish($saved)->assertOk()->assertJsonPath('published.steps.1.tool.tokenHint', null);
        $this->assertArrayNotHasKey('bearer', $this->held['metadata']['flow']['nodes'][1]['config']);
    }

    public function test_a_draft_that_leaves_a_key_alone_leaves_it_in_use(): void
    {
        $back = $this->publish($this->withFunction())->assertOk()->json('published');
        $back['steps'][1]['name'] = 'Look it up';
        $saved = $this->draft($back)->assertOk()->assertJsonPath('draft.steps.1.tool.tokenHint', '••••1111')->json('draft');
        $this->publish($saved)->assertOk()->assertJsonPath('published.steps.1.tool.tokenHint', '••••1111');
        $this->assertSame('tok_test_flow_step_1111', $this->held['metadata']['flow']['nodes'][1]['config']['bearer']);
    }

    public function test_a_key_typed_and_taken_away_in_a_draft_never_goes_out(): void
    {
        $saved = $this->draft($this->withFunction())->assertOk()->json('draft');
        $saved['steps'][1]['tool']['token'] = null;
        $saved = $this->draft($saved)->assertOk()->assertJsonPath('draft.steps.1.tool.tokenHint', null)->json('draft');
        $this->publish($saved)->assertOk();
        $this->assertArrayNotHasKey('bearer', $this->held['metadata']['flow']['nodes'][1]['config']);
    }

    public function test_a_new_key_ending_like_the_old_one_still_replaces_it(): void
    {
        $back = $this->publish($this->withFunction())->assertOk()->json('published');
        $back['steps'][1]['tool']['token'] = 'tok_test_flow_other_1111';
        $this->sent = [];
        $this->publish($back)->assertOk()->assertJsonPath('published.steps.1.tool.tokenHint', '••••1111');
        $this->assertSame('tok_test_flow_other_1111', $this->puts()[0]['body']['metadata']['flow']['nodes'][1]['config']['bearer']);
    }

    public function test_someone_else_cannot_send_a_key_sealed_in_a_draft_to_an_address_of_their_own(): void
    {
        $saved = $this->draft($this->withFunction())->assertOk()->json('draft');
        $saved['steps'][1]['tool']['url'] = 'https://attacker.example.com/steal';
        $this->refused($this->publish($saved), 'flow.steps.1.tool.token', __('agents.key_stays_with_address'));
        $this->assertSame([], $this->puts());
    }

    public function test_an_ai_exit_with_no_words_is_refused_in_the_words_its_field_uses(): void
    {
        $this->publish($this->small([], [$this->exit('e', 'greet', 'bye', ['kind' => 'ai'])]))->assertUnprocessable()
            ->assertJsonPath('errors', ['flow.exits.0.when.describe' => ["The caller's words field is required."]]);
    }

    public function test_a_step_name_cannot_hold_a_web_address_since_telenow_counts_it_as_where_a_key_goes(): void
    {
        $this->refused($this->publish($this->small(['name' => 'See https://example.org'])), 'flow.steps.0.name', __('agents.flow_name_address'));
    }

    public function test_a_web_address_in_the_voicemail_line_waits_on_a_key_in_a_step(): void
    {
        $this->publish($this->withFunction())->assertOk();
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$this->agent->id}", ['settings' => ['voicemailMessage' => 'See https://example.org/help']])->assertStatus(422)->assertJsonValidationErrors('settings.voicemailMessage');
    }

    public function test_only_what_could_run_is_published(): void
    {
        $cases = [
            'no steps' => [['start' => null, 'steps' => [], 'exits' => []], 'flow.steps'],
            'no start' => [['start' => null] + $this->small(), 'flow.start'],
            'a start that is not there' => [['start' => 'nope'] + $this->small(), 'flow.start'],
            'starting on a note' => [['start' => 'n', 'steps' => [$this->step('n', 'note')], 'exits' => []], 'flow.start'],
            'an id twice' => [['start' => 'a', 'steps' => [$this->step('a', 'end'), $this->step('a', 'end')], 'exits' => []], 'flow.steps.0.id'],
            'an exit id twice' => [$this->small([], [$this->exit('e', 'greet', 'bye', ['kind' => 'always']), $this->exit('e', 'greet', 'bye', ['kind' => 'fallback'])]), 'flow.exits.1.id'],
            'no instructions' => [['start' => 'a', 'steps' => [$this->step('a', 'conversation')], 'exits' => []], 'flow.steps.0.instructions'],
            'a menu with no line' => [['start' => 'a', 'steps' => [$this->step('a', 'dtmf')], 'exits' => []], 'flow.steps.0.line'],
            'saying nothing' => [['start' => 'a', 'steps' => [$this->step('a', 'static')], 'exits' => []], 'flow.steps.0.line'],
            'an exit from nowhere' => [$this->small([], [$this->exit('e', 'nope', 'bye', ['kind' => 'always'])]), 'flow.exits.0.from'],
            'an exit to nowhere' => [$this->small([], [$this->exit('e', 'greet', 'nope', ['kind' => 'always'])]), 'flow.exits.0.to'],
            'an exit from an ending' => [$this->small([], [$this->exit('e', 'bye', 'greet', ['kind' => 'always'])]), 'flow.exits.0.from'],
            'an exit to a note' => [['start' => 'a', 'steps' => [$this->step('a', 'router'), $this->step('n', 'note')], 'exits' => [$this->exit('e', 'a', 'n', ['kind' => 'always'])]], 'flow.exits.0.to'],
            'an exit from a note' => [['start' => 'a', 'steps' => [$this->step('a', 'router'), $this->step('n', 'note')], 'exits' => [$this->exit('e', 'n', 'a', ['kind' => 'always'])]], 'flow.exits.0.from'],
            'AI with no words' => [$this->small([], [$this->exit('e', 'greet', 'bye', ['kind' => 'ai'])]), 'flow.exits.0.when.describe'],
            'a rule with no words' => [$this->small([], [$this->exit('e', 'greet', 'bye', ['kind' => 'equation'])]), 'flow.exits.0.when.expr'],
            'a key with no digit' => [$this->small([], [$this->exit('e', 'greet', 'bye', ['kind' => 'dtmf'])]), 'flow.exits.0.when.digit'],
            'a digit that is no key' => [$this->small([], [$this->exit('e', 'greet', 'bye', ['kind' => 'dtmf', 'digit' => '12'])]), 'flow.exits.0.when.digit'],
            'one key twice' => [$this->small([], [$this->exit('e', 'greet', 'bye', ['kind' => 'dtmf', 'digit' => '1']), $this->exit('f', 'greet', 'bye', ['kind' => 'dtmf', 'digit' => '1'])]), 'flow.exits.1.when.digit'],
            'a choice with no answers' => [$this->small(['capture' => [['name' => 'x', 'type' => 'enum']]]), 'flow.steps.0.capture.0.values'],
            'a detail twice' => [$this->small(['capture' => [['name' => 'x', 'type' => 'string'], ['name' => 'x', 'type' => 'number']]]), 'flow.steps.0.capture.1.name'],
            'a detail with a bad name' => [$this->small(['capture' => [['name' => 'a b', 'type' => 'string']]]), 'flow.steps.0.capture.0.name'],
            'an Indian format' => [$this->small(['capture' => [['name' => 'x', 'type' => 'string', 'format' => 'pincode']]]), 'flow.steps.0.capture.0.format'],
            'audio' => [['start' => 'a', 'steps' => [$this->step('a', 'play_audio')], 'exits' => []], 'flow.steps.0.kind'],
            'a jump with no words' => [$this->small(['anywhere' => ['goBack' => true]]), 'flow.steps.0.anywhere.when'],
            'a function with no request' => [['start' => 'a', 'steps' => [$this->step('a', 'tool')], 'exits' => []], 'flow.steps.0.tool'],
            'a function at a plain address' => [['start' => 'a', 'steps' => [$this->step('a', 'tool', ['tool' => ['name' => 'x', 'url' => 'http://example.com']])], 'exits' => []], 'flow.steps.0.tool.url'],
            'a function at a private address' => [['start' => 'a', 'steps' => [$this->step('a', 'tool', ['tool' => ['name' => 'x', 'url' => 'https://10.0.0.5/x']])], 'exits' => []], 'flow.steps.0.tool.url'],
            'a reserved name' => [['start' => 'a', 'steps' => [$this->step('a', 'tool', ['tool' => ['name' => 'transfer', 'url' => 'https://example.com']])], 'exits' => []], 'flow.steps.0.tool.name'],
            'two functions by one name' => [['start' => 'a', 'steps' => [$this->step('a', 'tool', ['tool' => ['name' => 'x', 'url' => 'https://example.com/1']]), $this->step('b', 'tool', ['tool' => ['name' => 'x', 'url' => 'https://example.com/2']])], 'exits' => []], 'flow.steps.1.tool.name'],
            'a value kept from a bad path' => [['start' => 'a', 'steps' => [$this->step('a', 'tool', ['tool' => ['name' => 'x', 'url' => 'https://example.com'], 'results' => [['path' => 'see https://x', 'variable' => 'y']]])], 'exits' => []], 'flow.steps.0.results.0.path'],
            'code with nothing in it' => [['start' => 'a', 'steps' => [$this->step('a', 'code', ['code' => ['source' => null]])], 'exits' => []], 'flow.steps.0.code.source'],
            'code too slow' => [['start' => 'a', 'steps' => [$this->step('a', 'code', ['code' => ['source' => 'return {};', 'timeoutMs' => 99999]])], 'exits' => []], 'flow.steps.0.code.timeoutMs'],
            'a transfer with no number' => [['start' => 'a', 'steps' => [$this->step('a', 'transfer', ['transfer' => ['destinations' => []]])], 'exits' => []], 'flow.steps.0.transfer.destinations'],
            'a transfer to a bad number' => [['start' => 'a', 'steps' => [$this->step('a', 'transfer', ['transfer' => ['destinations' => [['numbers' => ['nope'], 'mode' => 'serial']]]])], 'exits' => []], 'flow.steps.0.transfer.destinations.0.numbers.0'],
            'a hand-over to nobody' => [['start' => 'a', 'steps' => [$this->step('a', 'agent', ['handoff' => ['agents' => []]])], 'exits' => []], 'flow.steps.0.handoff.agents'],
            'a hand-over to an agent unknown' => [['start' => 'a', 'steps' => [$this->step('a', 'agent', ['handoff' => ['agents' => [['agentId' => (string) Str::uuid(), 'mode' => 'transfer']]]])], 'exits' => []], 'flow.steps.0.handoff.agents.0.agentId'],
        ];
        foreach ($cases as $name => [$flow, $field]) {
            $answer = $this->publish($flow);
            $this->assertSame(422, $answer->status(), "{$name}: ".$answer->getContent());
            $this->assertArrayHasKey($field, $answer->json('errors'), "{$name}: ".json_encode($answer->json('errors')));
        }
        $this->assertSame([], $this->puts());
    }

    public function test_a_hand_over_to_itself_or_to_another_accounts_agent_is_refused(): void
    {
        $itself = ['start' => 'a', 'steps' => [$this->step('a', 'agent', ['handoff' => ['agents' => [['agentId' => $this->agent->id, 'mode' => 'transfer']]]])], 'exits' => []];
        $this->refused($this->publish($itself), 'flow.steps.0.handoff.agents.0.agentId', __('agents.handoff_itself'));
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new Agent(['name' => 'Theirs']);
        $theirs->workspace_id = $other->id;
        $theirs->forceFill(['telenow_id' => (string) Str::uuid()])->save();
        $itself['steps'][0]['handoff']['agents'][0]['agentId'] = $theirs->id;
        $this->refused($this->publish($itself), 'flow.steps.0.handoff.agents.0.agentId', __('agents.handoff_unknown'));
    }

    public function test_the_limits_are_the_kits(): void
    {
        config(['kit.agents.flow.limits.steps_max' => 2, 'kit.agents.flow.limits.exits_max' => 1]);
        $this->refused($this->draft(['steps' => [$this->step('a', 'end'), $this->step('b', 'end'), $this->step('c', 'end')], 'exits' => []]), 'flow.steps');
        $this->refused($this->draft(['steps' => [], 'exits' => [$this->exit('a', 'x', 'y', ['kind' => 'always']), $this->exit('b', 'x', 'y', ['kind' => 'always'])]]), 'flow.exits');
    }

    public function test_telenows_check_sees_the_graph_with_no_key_in_it_and_the_agents_own_variables(): void
    {
        $this->held['metadata']['variables'] = [['name' => 'plan', 'required' => false]];
        $this->agent->forceFill(['telenow_snapshot' => $this->held])->save();
        $this->actingAs($this->ana)->postJson($this->url('/check'), ['flow' => $this->withFunction()])->assertOk()->assertExactJson(['warnings' => []]);
        $sent = collect($this->sent)->firstWhere('path', '/api/agents/flow/validate');
        $this->assertStringNotContainsString('tok_test_flow', (string) json_encode($sent['body']));
        $this->assertSame('greet', $sent['body']['flow']['startNodeId']);
        $this->assertSame([['name' => 'plan', 'required' => false]], $sent['body']['variables']);
        $this->assertSame([], $this->puts(), 'a check saves nothing');
    }

    public function test_what_telenows_check_says_would_lose_the_graph_stops_it_being_published(): void
    {
        $this->verdict = RecordedTelenow::body('validateFlow.no-nodes')['data'];
        $this->refused($this->publish($this->small()), 'flow');
        $this->refused($this->actingAs($this->ana)->postJson($this->url('/check'), ['flow' => $this->small()]), 'flow');
        $this->assertSame([], $this->puts());
    }

    public function test_a_section_that_does_not_pair_up_is_refused_beside_its_step(): void
    {
        $this->verdict = ['valid' => true, 'errors' => [], 'multiContext' => true, 'warnings' => [['at' => 'greet', 'code' => 'section_unclosed', 'message' => 'Line 1: `{#if` is never closed.'], ['at' => 'bye', 'code' => 'dead_step', 'message' => 'no way out']]];
        $answer = $this->publish($this->small());
        $this->refused($answer, 'flow.steps.0.instructions', 'Line 1: `{#if` is never closed.');
        $this->assertSame([], $this->puts());
    }

    public function test_what_may_misbehave_is_answered_tidied_and_does_not_stop_it(): void
    {
        $this->verdict = RecordedTelenow::body('validateFlow.every-kind')['data'];
        $this->verdict['warnings'][] = ['at' => 'greet', 'code' => 'x', 'message' => "two   lines\n   apart"];
        $this->verdict['warnings'][] = ['at' => 'greet', 'code' => 'y', 'message' => 'A word about '.config('kit.telenow.name').' here'];
        $answer = $this->publish($this->small())->assertOk();
        $words = array_column($answer->json('warnings'), 'message');
        $this->assertContains('two lines apart', $words);
        $this->assertContains(__('agents.flow_warning_other'), $words);
        $this->assertSame('menu', $answer->json('warnings.0.at'));
        $this->assertStringNotContainsStringIgnoringCase((string) config('kit.telenow.name'), $answer->getContent());
        $this->assertCount(1, $this->puts());
    }

    public function test_steps_are_not_published_unchecked_when_telenow_cannot_check_them(): void
    {
        $this->answers = ['POST /flow/validate' => [503, ['error' => 'down']]];
        $this->publish($this->small())->assertStatus(409)->assertExactJson(['message' => __('agents.flow_uncheckable')]);
        $this->actingAs($this->ana)->postJson($this->url('/check'), ['flow' => $this->small()])->assertStatus(409);
        $this->assertSame([], $this->puts());
    }

    public function test_when_telenow_refuses_the_save_nothing_changes_and_the_work_is_kept_as_a_draft(): void
    {
        $this->publish($this->small())->assertOk();
        $this->answers = ['PUT /'.self::AGENT => [400, RecordedTelenow::body('updateAgent.flow-section')]];
        $changed = $this->small(['instructions' => 'Changed.']);
        $answer = $this->publish($changed)->assertStatus(409)->assertExactJson(['message' => __('agents.flow_refused')]);
        $this->assertStringNotContainsString('node', $answer->getContent());
        $fresh = $this->agent->fresh();
        $this->assertSame('synced', $fresh->sync_state);
        $this->assertNull($fresh->sync_error);
        $this->assertSame('Greet them.', $fresh->settings['flow']['steps'][0]['instructions']);
        $this->assertSame('Changed.', $fresh->flow_draft['steps'][0]['instructions']);
        $this->assertSame(0, OutboxEntry::query()->where('resource', 'agents')->whereNull('done_at')->count());
        $this->answers = [];
        $this->publish($changed)->assertOk()->assertJsonPath('published.steps.0.instructions', 'Changed.')->assertJsonPath('draft', null);
    }

    public function test_when_telenow_is_down_it_is_tried_again(): void
    {
        $this->answers = ['PUT /'.self::AGENT => [503, ['error' => 'down']]];
        $this->publish($this->small())->assertOk()->assertJsonPath('syncState', 'pending')->assertJsonPath('published.start', 'greet');
        $this->answers = [];
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame('greet', $this->held['metadata']['flow']['startNodeId']);
        $this->assertSame('synced', $this->agent->fresh()->sync_state);
    }

    public function test_nothing_is_published_while_a_change_is_on_its_way_or_before_the_agent_is_at_telenow(): void
    {
        $this->agent->forceFill(['sync_state' => 'pending'])->save();
        $this->publish($this->small())->assertStatus(409)->assertExactJson(['message' => __('agents.version_wait')]);
        $this->actingAs($this->ana)->deleteJson($this->url())->assertStatus(409);
        $this->agent->forceFill(['sync_state' => 'synced', 'telenow_id' => null])->save();
        $this->publish($this->small())->assertStatus(409)->assertExactJson(['message' => __('agents.not_ready')]);
        $this->actingAs($this->ana)->postJson($this->url('/check'), ['flow' => $this->small()])->assertStatus(409);
        $this->assertSame([], $this->sent);
    }

    public function test_stopping_using_steps_sets_none_and_keeps_a_draft(): void
    {
        $this->publish($this->small())->assertOk();
        $this->draft($this->small(['name' => 'Later']))->assertOk();
        $this->sent = [];
        $this->actingAs($this->ana)->deleteJson($this->url())->assertOk()->assertJsonPath('published', null)->assertJsonPath('draft.steps.0.name', 'Later');
        $body = $this->puts()[0]['body']['metadata'];
        $this->assertArrayHasKey('flow', $body);
        $this->assertNull($body['flow']);
        $this->sent = [];
        $this->actingAs($this->ana)->deleteJson($this->url())->assertOk();
        $this->assertSame([], $this->puts(), 'already off');
    }

    /** A graph with a transfer step, jumped to from anywhere, reached from the start, with an exit of its own. */
    private function withTransfer(array $transfer = []): array
    {
        return ['start' => 'greet', 'steps' => [
            $this->step('greet', 'conversation', ['instructions' => 'Greet them.']),
            $this->step('person', 'transfer', ['anywhere' => ['when' => 'the caller asks for a person'], 'transfer' => $transfer + ['destinations' => [['numbers' => ['+14155550199'], 'mode' => 'serial']], 'message' => 'Connecting you.', 'noAnswerMessage' => 'Nobody is free.']]),
            $this->step('sorry', 'static', ['line' => 'Sorry.']),
        ], 'exits' => [$this->exit('e1', 'greet', 'person', ['kind' => 'ai', 'describe' => 'the caller wants a person']), $this->exit('e2', 'person', 'sorry', ['kind' => 'fallback'])]];
    }

    public function test_a_transfer_step_waits_on_the_operators_switch(): void
    {
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, false);
        $this->refused($this->publish($this->withTransfer()), 'flow.steps.1.transfer.enabled', __('agents.transfer_off'));
        $this->actingAs($this->ana)->getJson($this->url())->assertOk()->assertJsonMissing(['transfer']);
        $this->assertNotContains('transfer', $this->actingAs($this->ana)->getJson($this->url())->json('kinds'));
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, true);
        $this->assertContains('transfer', $this->actingAs($this->ana)->getJson($this->url())->json('kinds'));
    }

    public function test_turning_transfers_off_ends_a_transfer_step_keeping_the_graph_good(): void
    {
        $this->publish($this->withTransfer())->assertOk();
        $this->sent = [];
        app(AgentTransfers::class)->save(false);
        $this->assertSame(1, app(AgentTransfers::class)->stillOn());
        $this->artisan('kit:agents')->assertSuccessful();
        $graph = $this->held['metadata']['flow'];
        $node = collect($graph['nodes'])->firstWhere('id', 'person');
        $this->assertSame('end', $node['kind']);
        $this->assertSame('Nobody is free.', $node['entryMessage']);
        $this->assertArrayNotHasKey('config', $node);
        $this->assertArrayNotHasKey('isGlobal', $node);
        $this->assertSame(['e1'], array_column($graph['edges'], 'id'), 'exits into it stay, and its own go');
        $this->assertSame(0, app(AgentTransfers::class)->stillOn());
    }

    public function test_a_transfer_with_no_line_for_nobody_answering_ends_in_silence_rather_than_on_words_it_never_had(): void
    {
        $this->publish($this->withTransfer(['noAnswerMessage' => null]))->assertOk();
        app(AgentTransfers::class)->save(false);
        $this->artisan('kit:agents')->assertSuccessful();
        $this->assertArrayNotHasKey('entryMessage', collect($this->held['metadata']['flow']['nodes'])->firstWhere('id', 'person'));
    }

    public function test_deleting_an_agent_takes_it_off_a_step_that_hands_over_to_it(): void
    {
        $sales = $this->agentNamed('Sales desk', (string) Str::uuid());
        $flow = ['start' => 'a', 'steps' => [
            $this->step('a', 'conversation', ['instructions' => 'Talk.']),
            $this->step('both', 'agent', ['handoff' => ['agents' => [['agentId' => $this->billing->id, 'mode' => 'transfer'], ['agentId' => $sales->id, 'mode' => 'transfer']], 'message' => 'One moment.']]),
            $this->step('only', 'agent', ['handoff' => ['agents' => [['agentId' => $this->billing->id, 'mode' => 'transfer']]]]),
        ], 'exits' => [$this->exit('e1', 'a', 'both', ['kind' => 'always']), $this->exit('e2', 'only', 'a', ['kind' => 'fallback'])]];
        $flowWas = $this->publish($flow)->assertOk()->json('published');
        $this->answers = ['DELETE /'.self::SECOND => [200, ['success' => true]]];
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$this->billing->id}")->assertNoContent();
        $nodes = collect($this->held['metadata']['flow']['nodes']);
        $this->assertSame([$sales->id], array_column($nodes->firstWhere('id', 'both')['config']['agents'], 'kitAgentId'));
        $this->assertSame('end', $nodes->firstWhere('id', 'only')['kind']);
        $this->assertSame(['e1'], array_column($this->held['metadata']['flow']['edges'], 'id'));
        // Not only left out of what Telenow is sent: the kit's own copy, read while a write is still on its way, has it gone too.
        $cleaned = app(AgentFlow::class)->cleaned($flowWas, $this->agent, app(AgentSettings::class));
        $this->assertSame([$sales->id], array_column($cleaned['flow']['steps'][1]['handoff']['agents'], 'agentId'));
        $this->assertSame(['handoff'], $cleaned['kept']);
    }

    public function test_only_this_accounts_other_agents_are_offered_for_a_hand_over(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new Agent(['name' => 'Theirs']);
        $theirs->workspace_id = $other->id;
        $theirs->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();
        $this->actingAs($this->ana)->getJson($this->url())->assertOk()->assertJsonPath('otherAgents', [['id' => $this->billing->id, 'name' => 'Billing desk']]);
    }

    public function test_someone_elses_agent_and_a_stranger_are_refused(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new Agent(['name' => 'Theirs']);
        $theirs->workspace_id = $other->id;
        $theirs->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();
        $root = "/api/kit/console/agents/{$theirs->id}/flow";
        $this->actingAs($this->ana)->getJson($root)->assertNotFound();
        $this->actingAs($this->ana)->putJson("{$root}/draft", ['flow' => $this->small()])->assertNotFound();
        $this->actingAs($this->ana)->deleteJson("{$root}/draft")->assertNotFound();
        $this->actingAs($this->ana)->postJson("{$root}/check", ['flow' => $this->small()])->assertNotFound();
        $this->actingAs($this->ana)->postJson("{$root}/publish", ['flow' => $this->small()])->assertNotFound();
        $this->actingAs($this->ana)->deleteJson($root)->assertNotFound();
        $this->assertSame([], $this->sent);
        $stranger = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->getJson($this->url())->assertForbidden();
    }

    public function test_the_recordings_are_what_this_rests_on(): void
    {
        $this->assertTrue(RecordedTelenow::body('validateFlow.small')['data']['valid']);
        $this->assertSame(['flow has no nodes', "startNodeId 'a' does not match any node"], RecordedTelenow::body('validateFlow.no-nodes')['data']['errors']);
        $this->assertStringContainsString('not a flow graph', RecordedTelenow::body('validateFlow.wrong-type')['data']['errors'][0]);
        $this->assertContains('section_unclosed', array_column(RecordedTelenow::body('validateFlow.section')['data']['warnings'], 'code'));
        $this->assertSame('prompt_sections_malformed', RecordedTelenow::body('updateAgent.flow-section')['code']);
        $this->assertSame('agent_foreign_reference', RecordedTelenow::body('updateAgent.flow-foreign-agent')['code']);
        $this->assertStringContainsString('holds a masked key', RecordedTelenow::body('updateAgent.flow-key-moved')['error']);
        $this->assertStringStartsWith('••••', collect(RecordedTelenow::body('updateAgent.flow-every-kind')['data']['metadata']['flow']['nodes'])->firstWhere('id', 'lookup')['config']['bearer']);
    }
}
