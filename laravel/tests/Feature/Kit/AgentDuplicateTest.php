<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\AgentWebChat;
use App\Models\AgentWebhook;
use App\Models\CatalogVoice;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Duplicating an agent (App\Kit\Agents\AgentDuplicate; contract/kit-api.yaml duplicateAgent), through the kit's real routes: the copy is made and
 * finished by the same writes a customer's own saves take, so it reaches the voice platform whole — prompt, call settings, voice setup, hand-overs,
 * tools and look-ups (without their keys, which Telenow alone holds), call steps, knowledge, webhook addresses (with new secrets), on or off —
 * and says what is left to do. The stand-in for Telenow's agents below keeps keys and answers them masked, as recorded (contract_k3tools.py,
 * contract_k6flow.py); the rest is answered from recordings.
 */
class AgentDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private User $ana;

    private Workspace $acme;

    /** @var array<string, array> each agent as Telenow holds it, keys in plain */
    private array $telenow = [];

    /** @var list<array{method: string, path: string, body: mixed}> */
    private array $sent = [];

    /** @var list<array> each webhook endpoint made at Telenow, with the secret it answered */
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
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
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

    private function make(string $name, array $input = []): Agent
    {
        $id = $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $input + ['name' => $name, 'systemPrompt' => 'You answer the phone for Acme Dental.'])->assertCreated()->json('id');

        return Agent::query()->findOrFail($id);
    }

    private function change(Agent $agent, array $input): void
    {
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", $input)->assertOk();
    }

    private function duplicate(Agent $agent): TestResponse
    {
        return $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/duplicate");
    }

    private function at(Agent $agent): array
    {
        return $this->telenow[$agent->fresh()->telenow_id];
    }

    /** A source with everything: settings, a voice, a hand-over, tools and a look-up with keys, email, knowledge, a webhook, off. */
    private function everything(): Agent
    {
        $billing = $this->make('Billing');
        $source = $this->make('Front desk');
        $this->change($source, ['description' => 'Answers every call', 'llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-5.4-mini',
            'sttProvider' => Catalog::publicKey('stt', 'deepgram'), 'ttsProvider' => Catalog::publicKey('tts', 'openai'), 'ttsVoice' => 'nova', 'settings' => [
            'greetingInbound' => 'Hello, Acme Dental.', 'maxDurationSecs' => 900, 'recording' => false, 'canEndCall' => true,
            'transfer' => ['enabled' => true, 'destinations' => [['label' => 'Front office', 'numbers' => ['+14155550123'], 'mode' => 'serial']], 'message' => 'One moment.'],
            'emailAfterCall' => ['recipients' => [$this->ana->id], 'onlyAnswered' => false],
        ]]);
        $this->change($source, ['settings' => [
            'sendCallerToTools' => true,
            'tools' => [
                ['name' => 'book_visit', 'description' => 'Book a visit.', 'url' => 'https://example.com/hooks/book', 'token' => 'tok_test_book_9876'],
                ['name' => 'get_order', 'description' => 'Look an order up.', 'url' => 'https://example.com/orders/{order_id}', 'parameters' => [['name' => 'order_id', 'type' => 'string', 'required' => true]],
                    'request' => ['method' => 'GET', 'headers' => [['name' => 'X-Api-Key', 'value' => 'key_test_orders_5555'], ['name' => 'Accept', 'value' => 'application/json']]]],
                ['name' => 'hours', 'description' => 'Opening hours.', 'url' => 'https://example.com/hours'],
            ],
            'lookups' => [['name' => 'Patient', 'url' => 'https://example.com/patients?phone={caller_number}', 'method' => 'GET', 'token' => 'tok_test_patient_4321',
                'fills' => [['variable' => 'patient_name', 'path' => 'name']]]],
            'handoff' => ['agents' => [['agentId' => $billing->id, 'label' => 'Billing desk', 'mode' => 'transfer']]],
        ]]);
        $this->change($source, ['isActive' => false]);
        $base = (new KnowledgeBase)->forceFill(['workspace_id' => $this->acme->id, 'name' => 'Prices', 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced']);
        $base->save();
        $this->actingAs($this->ana)->putJson("/api/kit/console/agents/{$source->id}/knowledge", ['knowledgeBaseIds' => [$base->id]])->assertOk();
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$source->id}/webhooks", ['url' => 'https://example.com/hooks/calls', 'events' => [config('kit.agents.webhook_events')[0]]])->assertCreated();
        (new AgentWebChat)->forceFill(['agent_id' => $source->id, 'workspace_id' => $this->acme->id, 'public_key' => 'k', 'enabled' => true, 'websites' => ['https://www.acme.example']])->save();

        return $source->fresh();
    }

    public function test_a_copy_of_an_agent_with_everything_reaches_the_platform_whole(): void
    {
        $source = $this->everything();
        $disk = \Illuminate\Support\Facades\Storage::fake(config('kit.agents.logo.disk'));
        $this->actingAs($this->ana)->post("/api/kit/console/agents/{$source->id}/logo", ['file' => \Tests\Support\TestImages::file('logo.png', \Tests\Support\TestImages::png(64, 64))])->assertOk();
        $source = $source->fresh();
        $before = $this->at($source);
        $this->sent = [];
        $got = $this->duplicate($source)->assertCreated();
        $copy = Agent::query()->findOrFail($got->json('agent.id'));
        $this->assertSame([config('kit.agents.logo.dir')."/{$copy->id}/".basename($source->logo), $disk->get($source->logo)], [$copy->logo, $disk->get($copy->logo)],
            'the same image, in a file of its own');
        $this->assertStringEndsWith("/agent-logos/{$copy->id}/".basename($source->logo), (string) $got->json('agent.logoUrl'));

        $this->assertSame(['Front desk (copy)', 'Answers every call', 'You answer the phone for Acme Dental.', false, 'synced'],
            [$copy->name, $copy->description, $copy->system_prompt, $copy->is_active, $copy->sync_state]);
        $this->assertNotSame($source->telenow_id, $copy->telenow_id);
        $made = $this->at($copy);
        $this->assertSame(['nova', false, 'Hello, Acme Dental.', 900], [$made['tts_voice'], $made['is_active'], $made['telephony_config']['agentMsg'], $made['session_config']['maxDuration']],
            'the voice, off, and the call settings as the source has them');
        $settings = fn (Agent $a) => array_diff_key($this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$a->id}")->json('settings'), array_flip(['tools', 'lookups']));
        $this->assertEquals($settings($source), $settings($copy), 'the settings the console shows, the same');

        $tools = collect($made['metadata']['tools'] ?? [])->keyBy('name');
        $this->assertSame(['book_visit', 'get_order', 'hours'], $tools->keys()->intersect(['book_visit', 'get_order', 'hours'])->values()->all());
        $this->assertArrayNotHasKey('bearer', $tools['book_visit'], 'no key: Telenow alone holds it');
        $this->assertSame(['Accept' => 'application/json'], $tools['get_order']['request']['headers'], 'a secret header left out, the rest kept');
        $this->assertSame('https://example.com/hours', $tools['hours']['endpoint']);
        $lookup = $made['metadata']['precallLookups'][0];
        $was = $before['metadata']['precallLookups'][0];
        $this->assertArrayHasKey('bearer', $was);
        unset($was['bearer']);
        $this->assertEquals($was, $lookup, 'the look-up as the source has it, without its key');
        $handoff = $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$copy->id}")->json('settings.handoff.agents');
        $this->assertSame([Agent::query()->where('name', 'Billing')->value('id')], array_column($handoff, 'agentId'));
        $this->assertSame(['Prices'], $copy->knowledgeBases()->pluck('name')->all());
        $this->assertSame([$this->ana->id], $copy->after_call_email['recipients']);

        $hook = AgentWebhook::query()->where('agent_id', $copy->id)->firstOrFail();
        $from = AgentWebhook::query()->where('agent_id', $source->id)->firstOrFail();
        $this->assertSame([$from->url, $from->events], [$hook->url, $hook->events]);
        $made = collect($this->endpoints)->keyBy('agent_id');
        $this->assertSame([$copy->telenow_id, $hook->telenow_id], [$made[$copy->telenow_id]['agent_id'], $made[$copy->telenow_id]['id']], 'an endpoint of its own at the platform, for the copy');
        $this->assertNotSame($made[$source->telenow_id]['signing_secret'], $made[$copy->telenow_id]['signing_secret'], 'with its own signing secret');
        $this->assertSame(0, AgentWebChat::query()->where('agent_id', $copy->id)->count(), 'the web chat box stays with the source');

        $this->assertEqualsCanonicalizing([['kind' => 'key', 'name' => 'book_visit'], ['kind' => 'key', 'name' => 'get_order'], ['kind' => 'key', 'name' => 'Patient'], ['kind' => 'webhooks', 'name' => null]],
            $got->json('toRedo'));
        $this->assertSame($before['metadata'], $this->at($source)['metadata'], 'the source untouched');
        $this->assertStringNotContainsString('tok_test', json_encode($got->json()));
        $this->assertStringNotContainsString('key_test', json_encode($this->at($copy)));

        $this->assertSame('Front desk (copy 2)', $this->duplicate($source)->assertCreated()->json('agent.name'));
        $copyLogo = $copy->logo;
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$copy->id}");
        $this->assertSame([false, true, null], [$disk->exists($copyLogo), $disk->exists($source->logo), $copy->fresh()->logo], "deleting the copy leaves the source's");
    }

    public function test_call_steps_published_on_the_copy_or_left_as_a_draft_to_finish(): void
    {
        $source = $this->make('Front desk');
        $small = ['start' => 'greet', 'steps' => [['id' => 'greet', 'kind' => 'conversation', 'name' => 'Greet', 'instructions' => 'Greet them.'], ['id' => 'bye', 'kind' => 'end', 'name' => 'Bye', 'line' => 'Goodbye.']],
            'exits' => [['id' => 'e1', 'from' => 'greet', 'to' => 'bye', 'label' => null, 'when' => ['kind' => 'ai', 'describe' => 'the caller is done']]]];
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$source->id}/flow/publish", ['flow' => $small])->assertOk();
        $published = $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$source->id}/flow")->json('published');
        $got = $this->duplicate($source)->assertCreated();
        $copy = $got->json('agent.id');
        $this->assertSame([], $got->json('toRedo'));
        $this->assertEquals($published, $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$copy}/flow")->json('published'), 'published on the copy as on the source');

        $keyed = $this->make('Orders');
        $withKey = ['start' => 'greet', 'steps' => [['id' => 'greet', 'kind' => 'conversation', 'name' => 'Greet', 'instructions' => 'Greet them.'],
            ['id' => 'lookup', 'kind' => 'tool', 'name' => 'Lookup', 'tool' => ['name' => 'lookup_order', 'url' => 'https://example.com/orders', 'token' => 'tok_test_flow_step_1111']]],
            'exits' => [['id' => 'e1', 'from' => 'greet', 'to' => 'lookup', 'label' => null, 'when' => ['kind' => 'ai', 'describe' => 'the caller gave the order']]]];
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$keyed->id}/flow/publish", ['flow' => $withKey])->assertOk();
        $got = $this->duplicate($keyed)->assertCreated();
        $this->assertSame([['kind' => 'flow', 'name' => null]], $got->json('toRedo'));
        $flow = $this->actingAs($this->ana)->getJson('/api/kit/console/agents/'.$got->json('agent.id').'/flow')->json();
        $this->assertNull($flow['published'], 'a step whose key Telenow alone holds is not published without it');
        $this->assertSame(['lookup_order', null], [$flow['draft']['steps'][1]['tool']['name'], $flow['draft']['steps'][1]['tool']['tokenHint']], 'left as a draft, its key to type again');

        $this->actingAs($this->ana)->putJson("/api/kit/console/agents/{$keyed->id}/flow/draft", ['flow' => $small])->assertOk();
        $got = $this->duplicate($keyed)->assertCreated();
        $this->assertSame('Bye', $this->actingAs($this->ana)->getJson('/api/kit/console/agents/'.$got->json('agent.id').'/flow')->json('draft.steps.1.name'), 'the source\'s own draft comes too');
    }

    public function test_a_voice_setup_no_longer_offered_starts_from_the_defaults_and_says_so(): void
    {
        $source = $this->make('Front desk', ['llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-other', 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
            'ttsProvider' => Catalog::publicKey('tts', 'openai'), 'ttsVoice' => 'nova']);
        app(Settings::class)->put(Catalog::CURATION, ['llm' => ['openai' => ['offered' => true, 'models' => ['gpt-other' => ['offered' => false]]]], 'stt' => ['deepgram' => ['offered' => true]], 'tts' => ['openai' => ['offered' => true]]]);
        $got = $this->duplicate($source)->assertCreated();
        $this->assertSame([['kind' => 'voiceSetup', 'name' => null]], $got->json('toRedo'));
        $this->assertSame('gpt-5.4-mini', $this->at(Agent::query()->findOrFail($got->json('agent.id')))['llm_model'], 'the defaults');
        $this->assertSame('gpt-other', $this->at($source)['llm_model']);

        // A source that has the defaults, of which the operator offers no longer all: the copy has them too, so nothing to say.
        $plain = $this->make('Plain');
        app(Settings::class)->put(Catalog::CURATION, ['llm' => ['openai' => ['offered' => true, 'models' => ['gpt-5.4-mini' => ['offered' => false]]]], 'stt' => ['deepgram' => ['offered' => true]], 'tts' => ['openai' => ['offered' => true]]]);
        $got = $this->duplicate($plain)->assertCreated();
        $this->assertSame([], $got->json('toRedo'));
        $this->assertSame('gpt-5.4-mini', $this->at(Agent::query()->findOrFail($got->json('agent.id')))['llm_model']);
    }

    public function test_what_no_longer_passes_is_left_to_set_again_and_the_copy_still_made_whole(): void
    {
        $source = $this->everything();
        // The operator has since lowered how many tools an agent may have, and taken transfers back (agents not swept yet).
        config(['kit.agents.limits.tools_max' => 2]);
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, false);
        $this->assertTrue($this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$source->id}")->json('settings.transfer.enabled'));
        $got = $this->duplicate($source)->assertCreated();
        $copy = Agent::query()->findOrFail($got->json('agent.id'));
        $this->assertEqualsCanonicalizing([['kind' => 'tools', 'name' => null], ['kind' => 'key', 'name' => 'Patient'], ['kind' => 'webhooks', 'name' => null]], $got->json('toRedo'),
            'no key asked for of tools the copy has not got');
        $this->assertSame('synced', $copy->sync_state);
        $shown = $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$copy->id}")->json('settings');
        $this->assertSame([[], false, 'Patient'], [$shown['tools'] ?? [], $shown['transfer']['enabled'] ?? false, $shown['lookups'][0]['name']], 'no tools, no transfer; the rest copied');
    }

    public function test_only_an_account_s_own_agents_and_a_long_name_cut_to_fit(): void
    {
        $long = $this->make(str_repeat('a', config('kit.agents.name_max_length')));
        $name = $this->duplicate($long)->assertCreated()->json('agent.name');
        $this->assertSame(config('kit.agents.name_max_length'), mb_strlen($name));
        $this->assertStringEndsWith(' (copy)', $name);

        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $ben->id, 'role' => 'owner']);
        $this->actingAs($ben)->postJson("/api/kit/console/agents/{$long->id}/duplicate")->assertNotFound();
        $gone = $this->make('Gone');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$gone->id}")->assertNoContent();
        $this->duplicate($gone)->assertNotFound();
        $count = Agent::query()->count();
        $this->acme->forceFill(['telenow_sub_account_id' => null])->save();
        $this->duplicate($long)->assertStatus(409);
        $this->assertSame($count, Agent::query()->count(), 'nothing made');
    }
}
