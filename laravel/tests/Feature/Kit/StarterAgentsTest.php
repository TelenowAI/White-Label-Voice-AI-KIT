<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\AuditEvent;
use App\Models\CatalogVoice;
use App\Models\KnowledgeBase;
use App\Models\OwnVoice;
use App\Models\OwnVoiceClone;
use App\Models\StarterAgent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Starter agents (App\Kit\Agents\StarterAgents; contract/kit-api.yaml listStarterAgents, previewStarterAgent, publishStarterAgent, updateStarterAgent,
 * listOfferedStarterAgents, createAgentFromStarter): published only from an agent in an account the staff member owns, after reading every text it
 * carries; taken without anything of that account (keys, tools, look-ups, hand-overs, transfers, knowledge, webhooks, email recipients; tool,
 * hand-over and transfer steps made endings); a customer's new agent made from one through the duplicate's own path. The stand-in for Telenow's
 * agents is the duplicate test's (contract_k3tools.py, contract_k6flow.py as recorded); the rest is answered from recordings.
 */
class StarterAgentsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private User $ana;

    private User $olu;

    private Workspace $acme;

    private Workspace $demo;

    /** @var array<string, array> each agent as Telenow holds it, keys in plain */
    private array $telenow = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    private array $sent = [];

    /** @var list<array> each webhook endpoint made at Telenow */
    private array $endpoints = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put(AgentSettings::TRANSFER_ALLOWED, true);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $settings->put('agents.defaults', ['stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy']);
        $settings->put(Catalog::COPY, ['fetchedAt' => now()->toIso8601String(), 'recommended' => [], 'kinds' => [
            'llm' => [['id' => 'openai', 'name' => 'OpenAI', 'models' => [['id' => 'gpt-5.4-mini', 'name' => 'Mini'], ['id' => 'gpt-other', 'name' => 'Other']]]],
            'stt' => [['id' => 'deepgram', 'name' => 'Deepgram']],
            'tts' => [['id' => 'openai', 'name' => 'OpenAI voices']],
        ]]);
        $settings->put(Catalog::CURATION, ['llm' => ['openai' => ['offered' => true]], 'stt' => ['deepgram' => ['offered' => true]], 'tts' => ['openai' => ['offered' => true]]]);
        foreach (['alloy', 'nova'] as $position => $voice) {
            (new CatalogVoice)->forceFill(['provider_id' => 'openai', 'voice_id' => $voice, 'name' => ucfirst($voice), 'position' => $position, 'language_bases' => 'en', 'tags' => [], 'search_text' => $voice])->save();
        }
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        // The operator's own account, owned by a staff member: where starters are built and tested.
        $this->olu = User::create(['name' => 'Olu', 'email' => 'olu@op.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->demo = Workspace::create(['name' => 'Operator demo', 'status' => 'active', 'sync_state' => 'synced']);
        $this->demo->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->demo->id, 'user_id' => $this->olu->id, 'role' => 'owner']);
        RecordedTelenow::fake();
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($recorded) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data()];
            if ($r->method() === 'POST' && $path === '/api/agents') {
                return $this->made($r);
            }
            if ($r->method() === 'PUT' && preg_match('#/agents/[0-9a-f-]{36}/knowledge-bases$#', $path) === 1) {
                return RecordedTelenow::response('setAgentKnowledgeBases.customer');
            }
            if ($r->method() === 'POST' && str_ends_with($path, '/webhooks')) {
                // As recorded: each endpoint made is Telenow's own, with its own id and its own signing secret, answered once.
                $recorded = RecordedTelenow::body('createWebhookEndpoint.customer');
                $recorded['data']['endpoint'] = ['id' => (string) Str::uuid(), 'agent_id' => $r->data()['agentId'] ?? null, 'url' => $r->data()['url'], 'events' => $r->data()['events']] + $recorded['data']['endpoint'];
                $recorded['data']['signing_secret'] = 'wh_test_'.Str::random(24);
                $this->endpoints[] = $recorded['data']['endpoint'] + ['signing_secret' => $recorded['data']['signing_secret']];

                return Http::response($recorded, 201);
            }
            if ($path === '/api/agents/flow/validate') {
                return Http::response(['success' => true, 'data' => RecordedTelenow::body('validateFlow.small')['data']]);
            }
            if (preg_match('#^/api/agents/([0-9a-f-]{36})(/versions)?$#', $path, $m) === 1 && isset($this->telenow[$m[1]])) {
                if (isset($m[2])) {
                    return Http::response(['success' => true, 'data' => ['versions' => [['version' => $this->telenow[$m[1]]['version'], 'note' => 'update']]]]);
                }

                return $r->method() === 'GET' ? Http::response(['success' => true, 'data' => $this->masked($this->telenow[$m[1]])]) : $this->saved($r, $m[1]);
            }

            return $recorded($r);
        });
    }

    private function made(Request $r): mixed
    {
        $id = (string) Str::uuid();
        $body = $r->data();
        $this->telenow[$id] = ['id' => $id, 'name' => $body['name'], 'system_prompt' => $body['systemPrompt'] ?? null, 'description' => $body['description'] ?? null, 'is_active' => true, 'version' => 1,
            'llm_provider' => $body['llmProvider'] ?? 'openai', 'llm_model' => $body['llmModel'] ?? 'gpt-5.4-mini', 'stt_provider' => $body['sttProvider'] ?? 'deepgram',
            'tts_provider' => $body['ttsProvider'] ?? 'openai', 'tts_voice' => $body['ttsVoice'] ?? 'alloy',
            'telephony_config' => $body['telephonyConfig'] ?? [], 'session_config' => $body['sessionConfig'] ?? [], 'metadata' => $body['metadata'] ?? []];

        return Http::response(['success' => true, 'data' => $this->masked($this->telenow[$id])], 201);
    }

    /** As recorded: a mask keeps a key only where the row it is in reads as it did; a save names its blobs whole. */
    private function saved(Request $r, string $id): mixed
    {
        $body = $r->data();
        if (isset($body['metadata'])) {
            $held = $this->telenow[$id]['metadata'];
            foreach (['tools', 'precallLookups'] as $list) {
                foreach ($body['metadata'][$list] ?? [] as $i => $row) {
                    foreach (array_filter(Arr::dot($row), fn ($v) => is_string($v) && str_starts_with($v, '••••')) as $path => $mask) {
                        $from = collect($held[$list] ?? [])->first(fn (array $old) => ($old['name'] ?? null) === ($row['name'] ?? null));
                        if ($from === null) {
                            return Http::response(RecordedTelenow::body('updateAgent.key-moved'), 400);
                        }
                        data_set($body['metadata'][$list][$i], $path, data_get($from, $path));
                    }
                }
            }
            $this->telenow[$id]['metadata'] = $body['metadata'];
        }
        foreach (['telephonyConfig' => 'telephony_config', 'sessionConfig' => 'session_config', 'name' => 'name', 'systemPrompt' => 'system_prompt', 'description' => 'description', 'isActive' => 'is_active',
            'llmModel' => 'llm_model', 'ttsVoice' => 'tts_voice'] as $write => $column) {
            if (array_key_exists($write, $body)) {
                $this->telenow[$id][$column] = $body[$write];
            }
        }
        $this->telenow[$id]['version']++;

        return Http::response(['success' => true, 'data' => $this->masked($this->telenow[$id]), 'version' => $this->telenow[$id]['version']]);
    }

    private function masked(array $agent): array
    {
        array_walk_recursive($agent['metadata'], function (&$value, $key) {
            if (is_string($value) && in_array(strtolower((string) $key), ['bearer', 'authorization', 'x-api-key', 'token'], true) && ! str_starts_with($value, '••••')) {
                $value = strlen($value) < 12 ? '••••' : '••••'.substr($value, -4);
            }
        });

        return $agent;
    }

    private function make(string $name, array $input = [], ?User $as = null): Agent
    {
        $id = $this->actingAs($as ?? $this->olu)->postJson('/api/kit/console/agents', $input + ['name' => $name, 'systemPrompt' => 'You answer the phone for a dental clinic.'])->assertCreated()->json('id');

        return Agent::query()->findOrFail($id);
    }

    private function change(Agent $agent, array $input): void
    {
        $this->actingAs($this->olu)->patchJson("/api/kit/console/agents/{$agent->id}", $input)->assertOk();
    }


    /** The operator's receptionist, with everything an account has: keys, tools, a look-up, a hand-over, a transfer, email, knowledge, a webhook, steps. */
    private function receptionist(): Agent
    {
        $billing = $this->make('Billing');
        $source = $this->make('Receptionist');
        $this->change($source, ['description' => 'Books cleanings and answers questions', 'settings' => [
            'greetingInbound' => 'Hello, thanks for calling.', 'voicemailMessage' => 'Please call us back.', 'maxDurationSecs' => 900,
            'transfer' => ['enabled' => true, 'destinations' => [['label' => 'Front office', 'numbers' => ['+14155550123'], 'mode' => 'serial']], 'message' => 'One moment.'],
            'emailAfterCall' => ['recipients' => [$this->olu->id], 'onlyAnswered' => false],
            'analysis' => ['enabled' => true, 'minDurationSec' => 0, 'minCustomerTurns' => 0, 'customFields' => [['key' => 'booked', 'label' => 'Booked a visit', 'type' => 'boolean', 'description' => 'Whether a visit was booked']], 'qaCriteria' => []],
        ]]);
        $this->change($source, ['settings' => [
            'tools' => [['name' => 'book_visit', 'description' => 'Book a visit.', 'url' => 'https://demo.example/hooks/book', 'token' => 'tok_test_book_9876']],
            'lookups' => [['name' => 'Patient', 'url' => 'https://demo.example/patients?phone={caller_number}', 'method' => 'GET', 'token' => 'tok_test_patient_4321', 'fills' => [['variable' => 'patient_name', 'path' => 'name']]]],
            'handoff' => ['agents' => [['agentId' => $billing->id, 'label' => 'Billing desk', 'mode' => 'transfer']]],
        ]]);
        $flow = ['start' => 'greet', 'steps' => [
            ['id' => 'greet', 'kind' => 'conversation', 'name' => 'Greet', 'instructions' => 'Greet the caller and ask how you can help.'],
            ['id' => 'book', 'kind' => 'tool', 'name' => 'Book it', 'tool' => ['name' => 'book_slot', 'url' => 'https://demo.example/slots']],
            ['id' => 'bye', 'kind' => 'end', 'name' => 'Bye', 'line' => 'Thanks for calling, goodbye.'],
            ['id' => 'bill', 'kind' => 'agent', 'name' => 'To billing', 'handoff' => ['agents' => [['agentId' => $billing->id, 'label' => 'Billing desk', 'mode' => 'transfer']]]],
        ], 'exits' => [
            ['id' => 'e1', 'from' => 'greet', 'to' => 'book', 'label' => 'Wants a visit', 'when' => ['kind' => 'ai', 'describe' => 'the caller wants to book']],
            ['id' => 'e2', 'from' => 'book', 'to' => 'bye', 'label' => null, 'when' => ['kind' => 'ai', 'describe' => 'the booking is done']],
            ['id' => 'e3', 'from' => 'greet', 'to' => 'bye', 'label' => null, 'when' => ['kind' => 'ai', 'describe' => 'the caller is done']],
            ['id' => 'e4', 'from' => 'greet', 'to' => 'bill', 'label' => null, 'when' => ['kind' => 'ai', 'describe' => 'the caller asks about a bill']],
        ]];
        $this->actingAs($this->olu)->postJson("/api/kit/console/agents/{$source->id}/flow/publish", ['flow' => $flow])->assertOk();
        $base = (new KnowledgeBase)->forceFill(['workspace_id' => $this->demo->id, 'name' => 'Prices', 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced']);
        $base->save();
        $this->actingAs($this->olu)->putJson("/api/kit/console/agents/{$source->id}/knowledge", ['knowledgeBaseIds' => [$base->id]])->assertOk();
        $this->actingAs($this->olu)->postJson("/api/kit/console/agents/{$source->id}/webhooks", ['url' => 'https://demo.example/hooks/calls', 'events' => [config('kit.agents.webhook_events')[0]]])->assertCreated();

        return $source->fresh();
    }

    private function publish(Agent $source, array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->olu)->postJson('/api/kit/admin/starters', $over + ['agentId' => $source->id, 'name' => 'Dental receptionist', 'description' => 'Answers calls for a clinic', 'reviewed' => true]);
    }

    public function test_published_only_from_an_account_the_staff_member_owns_after_reading_what_it_carries(): void
    {
        $source = $this->receptionist();
        $theirs = $this->make('Front desk', [], $this->ana);
        $listed = $this->actingAs($this->olu)->getJson('/api/kit/admin/starters')->assertOk();
        $this->assertSame([['id' => $this->demo->id, 'name' => 'Operator demo']], array_map(fn ($s) => array_intersect_key($s, array_flip(['id', 'name'])), $listed->json('sources')),
            'only accounts the staff member owns: never a customer\'s');
        $this->assertSame(['Billing', 'Receptionist'], array_column($listed->json('sources.0.agents'), 'name'));

        $this->actingAs($this->olu)->getJson("/api/kit/admin/starters/preview?agentId={$theirs->id}")->assertNotFound()->assertExactJson(['message' => __('starters.agent_not_yours')]);
        $this->publish($theirs)->assertNotFound();
        $preview = $this->actingAs($this->olu)->getJson("/api/kit/admin/starters/preview?agentId={$source->id}")->assertOk();
        $texts = collect($preview->json('texts'))->map(fn ($t) => $t['where'].':'.$t['key'].':'.$t['text'])->all();
        foreach (['prompt::You answer the phone for a dental clinic.', 'description::Books cleanings and answers questions', 'setting:greetingInbound:Hello, thanks for calling.',
            'setting:voicemailMessage:Please call us back.', 'analysis:booked:Booked a visit Whether a visit was booked', 'step:instructions:Greet the caller and ask how you can help.',
            'step:line:Thanks for calling, goodbye.', 'exit:label:Wants a visit', 'exit:when:the caller wants to book'] as $text) {
            $this->assertContains($text, $texts);
        }
        $this->assertNotContains('setting:transfer:One moment.', $texts);
        $of = collect($preview->json('texts'))->filter(fn ($t) => in_array($t['where'], ['step', 'exit'], true))->map(fn ($t) => $t['of'].' / '.$t['key'])->values()->all();
        $this->assertContains('Greet / instructions', $of, 'each step text says which step it is in');
        $this->assertContains('Greet → Book it / label', $of, 'each exit text says which steps it joins');
        $left = collect($preview->json('leftOut'))->keyBy('kind');
        $this->assertSame([['book_visit'], ['Patient'], 1, 1, 1, ['Prices'], 1, ['Book it', 'To billing']],
            [$left['tools']['names'], $left['lookups']['names'], $left['handoff']['count'], $left['transfer']['count'], $left['emailAfterCall']['count'], $left['knowledge']['names'], $left['webhooks']['count'], $left['steps']['names']]);

        $this->publish($source, ['reviewed' => false])->assertStatus(422)->assertJsonPath('errors.reviewed.0', __('starters.review_first'));
        $this->assertSame(0, StarterAgent::query()->count());
        $got = $this->publish($source)->assertCreated();
        $this->assertSame(['Dental receptionist', true, 4, ['agent' => 'Receptionist', 'account' => 'Operator demo'], 'Olu'],
            [$got->json('name'), $got->json('offered'), $got->json('steps'), $got->json('from'), $got->json('publishedBy')]);
        $stored = StarterAgent::query()->sole();
        $raw = json_encode([$stored->agent, $stored->flow]);
        foreach (['tok_test', '••••', '+14155550123', 'demo.example', $source->workspace_id, Agent::query()->where('name', 'Billing')->value('id')] as $theirs) {
            $this->assertStringNotContainsString($theirs, $raw, "nothing of the account: {$theirs}");
        }
        $this->assertSame([], array_intersect(array_keys($stored->agent['settings']), ['transfer', 'emailAfterCall', 'sendCallerToTools', 'tools', 'lookups', 'handoff', 'flow']));
        $this->assertSame(['conversation', 'end', 'end', 'end'], array_column($stored->flow['steps'], 'kind'), 'the tool and hand-over steps made endings');
        $this->assertSame([null, null], [$stored->flow['steps'][3]['handoff'], $stored->flow['steps'][1]['tool']]);
        $this->assertSame(['e1', 'e3', 'e4'], array_column($stored->flow['exits'], 'id'), 'no exit from an ending');
        $this->assertSame('starters.published', AuditEvent::query()->latest('id')->value('action'));

        // An account the staff member is only a member of is not theirs to publish from.
        $clinic = Workspace::create(['name' => 'Clinic B', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $clinic->id, 'user_id' => $this->olu->id, 'role' => 'member']);
        $theirAgent = Agent::create(['workspace_id' => $clinic->id, 'name' => 'Clinic desk']);
        $this->assertSame(['Operator demo'], array_column($this->actingAs($this->olu)->getJson('/api/kit/admin/starters')->json('sources'), 'name'));
        $this->actingAs($this->olu)->getJson("/api/kit/admin/starters/preview?agentId={$theirAgent->id}")->assertNotFound();

        $sam = User::create(['name' => 'Sam', 'email' => 'sam@op.example', 'password' => 'a long password 1', 'role' => 'support']);
        $this->actingAs($sam)->getJson('/api/kit/admin/starters')->assertForbidden();
        $this->actingAs($this->ana)->getJson('/api/kit/admin/starters')->assertForbidden();
    }

    public function test_a_customer_makes_an_agent_from_a_starter_and_nothing_of_the_operator_s_account_comes(): void
    {
        $source = $this->receptionist();
        $starter = $this->publish($source)->assertCreated()->json('id');
        $offered = $this->actingAs($this->ana)->getJson('/api/kit/console/starters')->assertOk();
        $this->assertSame([['id' => $starter, 'name' => 'Dental receptionist', 'description' => 'Answers calls for a clinic']], $offered->json('starters'));

        $got = $this->actingAs($this->ana)->postJson("/api/kit/console/starters/{$starter}/agents")->assertCreated();
        $agent = Agent::query()->findOrFail($got->json('agent.id'));
        $this->assertSame([$this->acme->id, 'Dental receptionist', 'Books cleanings and answers questions', 'You answer the phone for a dental clinic.', 'synced', true],
            [$agent->workspace_id, $agent->name, $agent->description, $agent->system_prompt, $agent->sync_state, $agent->is_active]);
        $this->assertSame([], $got->json('toRedo'));
        $shown = $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->json('settings');
        $this->assertSame(['Hello, thanks for calling.', 900, [], [], [], false, []],
            [$shown['greetingInbound'], $shown['maxDurationSecs'], $shown['tools'] ?? [], $shown['lookups'] ?? [], $shown['handoff']['agents'] ?? [], $shown['transfer']['enabled'] ?? false, $shown['emailAfterCall']['recipients']]);
        $this->assertSame('booked', $shown['analysis']['customFields'][0]['key']);
        $at = $this->telenow[$agent->telenow_id];
        $this->assertStringNotContainsString('demo.example', json_encode($at), 'no address of the operator\'s account at the platform');
        $flow = $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}/flow")->json('published');
        $this->assertSame(['conversation', 'end', 'end', 'end'], array_column($flow['steps'], 'kind'), 'the steps published on the new agent');
        $this->assertSame(0, $agent->knowledgeBases()->count());
        $this->assertStringNotContainsString('Operator demo', $got->getContent());

        $this->assertSame('Dental receptionist (2)', $this->actingAs($this->ana)->postJson("/api/kit/console/starters/{$starter}/agents")->assertCreated()->json('agent.name'));

        // Its voice setup no longer offered: the defaults, and said.
        $other = $this->make('Other voice', ['llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-other', 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
            'ttsProvider' => Catalog::publicKey('tts', 'openai'), 'ttsVoice' => 'nova']);
        $second = $this->publish($other, ['name' => 'Other'])->assertCreated()->json('id');
        app(Settings::class)->put(Catalog::CURATION, ['llm' => ['openai' => ['offered' => true, 'models' => ['gpt-other' => ['offered' => false]]]], 'stt' => ['deepgram' => ['offered' => true]], 'tts' => ['openai' => ['offered' => true]]]);
        $this->assertSame([['kind' => 'voiceSetup', 'name' => null]], $this->actingAs($this->ana)->postJson("/api/kit/console/starters/{$second}/agents")->assertCreated()->json('toRedo'));

        $this->actingAs($this->olu)->patchJson("/api/kit/admin/starters/{$starter}", ['offered' => false])->assertOk()->assertJsonPath('offered', false);
        $this->assertSame(['Other'], array_column($this->actingAs($this->ana)->getJson('/api/kit/console/starters')->json('starters'), 'name'));
        $this->actingAs($this->ana)->postJson("/api/kit/console/starters/{$starter}/agents")->assertNotFound();
        $this->acme->forceFill(['telenow_sub_account_id' => null])->save();
        $this->actingAs($this->ana)->postJson("/api/kit/console/starters/{$second}/agents")->assertStatus(409);
    }

    public function test_an_own_cloned_voice_stays_with_its_account(): void
    {
        $source = $this->make('Own voice');
        $voice = (new OwnVoice)->forceFill(['workspace_id' => $this->demo->id, 'telenow_id' => (string) Str::uuid(), 'name' => 'Olu', 'person_name' => 'Olu', 'relation' => 'self',
            'source' => 'upload', 'release_version' => 'v1', 'confirmed_at' => now()]);
        $voice->save();
        (new OwnVoiceClone)->forceFill(['own_voice_id' => $voice->id, 'provider_id' => 'openai', 'telenow_id' => (string) Str::uuid(), 'status' => 'ready'])->save();
        $source->forceFill(['tts_voice' => config('kit.own_voices.telenow_prefix').$voice->telenow_id])->save();
        $preview = $this->actingAs($this->olu)->getJson("/api/kit/admin/starters/preview?agentId={$source->id}")->assertOk();
        $this->assertContains(['kind' => 'ownVoice', 'count' => 1, 'names' => []], $preview->json('leftOut'));
        $this->publish($source)->assertCreated();
        $this->assertSame([], StarterAgent::query()->sole()->agent['voice'], 'the starter takes the defaults');
        $this->assertStringNotContainsString($voice->id, json_encode(StarterAgent::query()->sole()->agent));
        $got = $this->actingAs($this->ana)->postJson('/api/kit/console/starters/'.StarterAgent::query()->value('id').'/agents')->assertCreated();
        $this->assertSame('alloy', $this->telenow[Agent::query()->findOrFail($got->json('agent.id'))->telenow_id]['tts_voice']);
    }

    public function test_staff_rename_withdraw_and_offer_a_starter_again(): void
    {
        $starter = $this->publish($this->make('Receptionist'))->assertCreated()->json('id');
        $this->actingAs($this->olu)->patchJson("/api/kit/admin/starters/{$starter}", ['name' => 'Clinic receptionist', 'description' => null])->assertOk()
            ->assertJsonPath('name', 'Clinic receptionist')->assertJsonPath('description', null);
        $this->actingAs($this->olu)->patchJson("/api/kit/admin/starters/{$starter}", ['offered' => false])->assertOk()->assertJsonPath('offered', false);
        $this->actingAs($this->olu)->patchJson("/api/kit/admin/starters/{$starter}", ['offered' => true])->assertOk()->assertJsonPath('offered', true);
        $this->assertSame(['starters.published', 'starters.changed', 'starters.withdrawn', 'starters.offered'], AuditEvent::query()->orderBy('id')->pluck('action')->all());
        $this->actingAs($this->olu)->patchJson('/api/kit/admin/starters/'.Str::uuid(), ['offered' => false])->assertNotFound();
        $this->actingAs($this->olu)->patchJson("/api/kit/admin/starters/{$starter}", ['name' => ''])->assertStatus(422);
    }
}
