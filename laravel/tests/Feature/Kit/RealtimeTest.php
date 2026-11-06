<?php

namespace Tests\Feature\Kit;

use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Models\Agent;
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
 * Realtime voices (App\Kit\Catalog\RealtimeCatalog, App\Kit\Agents\AgentRealtime, App\Kit\Catalog\VoicePreview::realtime), through the kit's real routes, against Telenow's
 * recorded roster and answers (contract_k14realtime.py, the platform's engine on and off): the operator offers them, only while the platform runs them; an agent's realtime voice
 * is checked here and kept in Telenow's blob, merged while the engine stays and fresh when it changes; hearing one uses the customer's key, and Telenow's refusals come back
 * in the kit's words; the setup check says the realtime part is not checked.
 */
class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const OPERATOR = 'vai_test_operator_admin';

    private const AGENT = '7a1c0e5d-2b3f-4c6a-9d8e-1f2a3b4c5d6e';

    private User $staff;

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private Agent $agent;

    /** The agent as Telenow holds it (snake_case), changed by each PUT as Telenow changes it. */
    private array $held;

    /** @var list<array{method: string, path: string, body: string, key: ?string}> */
    private array $sent = [];

    /** What the realtime roster answers: a recording's name, or a closure. */
    private string|\Closure $roster = 'getRealtimeCatalog.on';

    /** What a realtime preview answers next: recordings' names or closures (none left: a clip). */
    private array $previews = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->answering();
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->offer()->assertOk();
        $this->held = ['id' => self::AGENT, 'name' => 'Front desk', 'llm_provider' => 'openai', 'llm_model' => 'gpt-4o-mini', 'stt_provider' => 'deepgram', 'tts_provider' => 'telenow',
            'tts_voice' => 'vikram-en-in', 'llm_config' => null, 'stt_config' => null, 'tts_config' => null, 's2s_config' => null, 'metadata' => ['kitId' => 'k'],
            'session_config' => new \stdClass, 'telephony_config' => new \stdClass];
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->agent->forceFill(['telenow_id' => self::AGENT, 'sync_state' => 'synced', 'llm_provider' => 'openai', 'llm_model' => 'gpt-4o-mini', 'stt_provider' => 'deepgram',
            'tts_provider' => 'telenow', 'tts_voice' => 'vikram-en-in', 'settings' => app(\App\Kit\Agents\AgentSettings::class)->read($this->held)])->save();
    }

    /** Telenow: the recordings, the realtime roster as this test says, the agent as it is held, realtime previews as queued. */
    private function answering(): void
    {
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($recorded) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/api/catalog/s2s') {
                return $this->roster instanceof \Closure ? ($this->roster)($r) : RecordedTelenow::response($this->roster);
            }
            if (str_starts_with($path, '/api/agents/'.self::AGENT) || str_starts_with($path, '/api/providers/s2s/') || ($path === '/api/agents' && $r->method() === 'POST')
                || $path === '/api/agents/preflight') {
                $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->body(), 'key' => $r->header('X-API-Key')[0] ?? null];
            }
            if (str_starts_with($path, '/api/providers/s2s/')) {
                $next = array_shift($this->previews);

                return $next === null ? self::clip() : ($next instanceof \Closure ? $next($r) : RecordedTelenow::response($next));
            }
            if ($path === '/api/agents/preflight') {
                return RecordedTelenow::response('preflightVoiceStack.no-platform-keys'); // the same parts whatever the engine (recorded)
            }
            if ($path === '/api/agents' && $r->method() === 'POST') {
                // Made as sent (recorded: Telenow keeps the realtime blob it is sent).
                $made = RecordedTelenow::body('createAgent.with-realtime');
                $made['data']['s2s_config'] = json_decode($r->body(), true)['s2sConfig'] ?? null;

                return Http::response($made, 201);
            }
            if ($path === '/api/agents/'.self::AGENT && $r->method() === 'GET') {
                return Http::response(['success' => true, 'data' => $this->held]);
            }
            if ($path === '/api/agents/'.self::AGENT.'/versions') {
                return Http::response(['success' => true, 'data' => ['versions' => [['version' => 1, 'note' => 'update']]]]);
            }
            if ($path === '/api/agents/'.self::AGENT && $r->method() === 'PUT') {
                $names = ['s2sConfig' => 's2s_config', 'llmProvider' => 'llm_provider', 'llmModel' => 'llm_model', 'sttProvider' => 'stt_provider', 'ttsProvider' => 'tts_provider',
                    'ttsVoice' => 'tts_voice', 'metadata' => 'metadata', 'sessionConfig' => 'session_config', 'telephonyConfig' => 'telephony_config'];
                foreach (json_decode($r->body(), true) as $k => $v) {
                    $this->held[$names[$k] ?? $k] = $v; // a blob sent replaces the one held whole (recorded)
                }

                return Http::response(['success' => true, 'data' => $this->held, 'version' => 2]);
            }

            return $recorded($r);
        });
    }

    /** A realtime clip, as the release answered one (its WAV). */
    private static function clip(): \GuzzleHttp\Promise\PromiseInterface
    {
        $c = json_decode((string) file_get_contents(base_path('../contract/telenow-mock/cassettes/previewAgentVoice.realtime-off.json')), true)['response'];

        return Http::response(base64_decode($c['bodyBase64']), 200, ['Content-Type' => $c['contentType']]);
    }

    private function offer(array $more = []): TestResponse
    {
        return $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', array_replace_recursive([
            'kinds' => [
                'llm' => ['openai' => ['offered' => true]],
                'stt' => ['deepgram' => ['offered' => true]],
                'tts' => ['telenow' => ['offered' => true, 'name' => 'Studio voices'], 'elevenlabs' => ['offered' => true]],
            ],
            'defaults' => ['llmProvider' => 'openai', 'llmModel' => 'gpt-4o-mini', 'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in'],
        ], $more));
    }

    private function offerRealtime(): void
    {
        $this->offer(['realtime' => ['openai' => ['offered' => true, 'models' => ['gpt-realtime-mini' => ['offered' => false]]], 'gemini' => ['offered' => true]]])->assertOk();
    }

    private function refresh(): void
    {
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
    }

    private function choices(?User $who = null): TestResponse
    {
        return $this->actingAs($who ?? $this->ben)->getJson('/api/kit/console/catalog')->assertOk();
    }

    private function save(array $body, ?User $who = null): TestResponse
    {
        return $this->actingAs($who ?? $this->ben)->patchJson("/api/kit/console/agents/{$this->agent->id}", $body);
    }

    /** @return list<array> what was sent to Telenow, decoded, by method and path */
    private function sentTo(string $method, string $path): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === $method && $s['path'] === $path));
    }

    private function lastPut(): array
    {
        $puts = $this->sentTo('PUT', '/api/agents/'.self::AGENT);

        return $puts === [] ? [] : json_decode(end($puts)['body'], true);
    }

    private static function key(string $id): string
    {
        return Catalog::publicKey('realtime', $id);
    }

    public function test_the_operator_offers_realtime_voices_only_while_the_platform_runs_them(): void
    {
        $page = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk();
        $this->assertTrue($page->json('realtime.enabled'));
        $this->assertSame([['openai', 'OpenAI Realtime', false], ['gemini', 'Google Gemini Live', false]],
            array_map(fn (array $p) => [$p['id'], $p['catalogName'], $p['offered']], $page->json('realtime.providers')), 'none until offered');
        $this->assertSame([], $this->choices()->json('realtime'));
        $copy = app(Catalog::class)->copy()['realtime'];
        foreach (['blurb', 'contextWindow', 'keyEnvVar', 'latency', 'Usd', 'apiKey'] as $never) {
            $this->assertStringNotContainsString($never, json_encode($copy), "only the fields the kit names: {$never}");
        }

        $this->offer(['realtime' => ['openai' => ['offered' => true, 'name' => 'Live voices', 'models' => ['gpt-realtime-mini' => ['offered' => false], 'gpt-realtime' => ['name' => 'Everyday']]]]])->assertOk();
        $mine = $this->choices();
        $this->assertSame([[self::key('openai'), 'Live voices', [['gpt-realtime-2.1', 'GPT Realtime 2.1'], ['gpt-realtime-2.1-mini', 'GPT Realtime 2.1 mini'], ['gpt-realtime', 'Everyday']]]],
            array_map(fn (array $p) => [$p['key'], $p['name'], array_map(fn (array $m) => [$m['id'], $m['name']], $p['models'])], $mine->json('realtime')), 'offered, renamed, one model switched off');
        $this->assertSame(['accent' => 'American', 'gender' => 'female', 'id' => 'marin', 'name' => 'Marin', 'recommended' => true, 'tags' => ['natural', 'warm']],
            $mine->json('realtime.0.models.2.voices.0'), "each model's voices, as a picker shows them");
        $this->assertContains('hi', $mine->json('realtime.0.languages'));
        $this->assertStringNotContainsString('"openai"', $mine->getContent(), "never Telenow's provider id");
        $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $mine->getContent());

        $this->offer(['realtime' => ['nosuch' => ['offered' => true]]])->assertStatus(422)->assertJsonValidationErrors('realtime.nosuch');
        $this->offer(['realtime' => ['openai' => ['offered' => true, 'models' => ['nosuch' => ['offered' => true]]]]])->assertStatus(422)->assertJsonValidationErrors('realtime.openai.models.nosuch');
        $this->offer(['realtime' => ['gemini' => ['offered' => true, 'name' => config('kit.telenow.name').' Live']], 'kinds' => ['tts' => ['elevenlabs' => ['offered' => false]]]])
            ->assertStatus(422)->assertJsonPath('errors', ['realtime.gemini.name' => [__('catalog.names_platform', ['platform' => config('kit.telenow.name')])]]);
        $this->assertSame(2, count($this->choices()->json('tts')), 'refused whole: the voice provider sent with it stays offered');
        $this->offer(['realtime' => ['gemini' => ['offered' => true, 'name' => str_repeat('a', config('kit.agents.name_max_length') + 1)]]])->assertStatus(422)->assertJsonValidationErrors('realtime.gemini.name');
        $this->offer(['realtime' => ['gemini' => ['offered' => true]]])->assertOk();
        $this->assertSame(['Live voices', 'Google Gemini Live'], array_column($this->choices()->json('realtime'), 'name'), 'a provider not sent keeps its choices');

        $this->roster = 'getRealtimeCatalog.off';
        $this->refresh();
        $this->assertSame([], $this->choices()->json('realtime'), 'the platform runs none: none offered');
        $off = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk();
        $this->assertSame([false, []], [$off->json('realtime.enabled'), $off->json('realtime.providers')]);
        $offButListed = RecordedTelenow::body('getRealtimeCatalog.on');
        $offButListed['data']['enabled'] = false;
        $this->roster = fn () => Http::response($offButListed);
        $this->refresh();
        $this->assertSame([], $this->choices()->json('realtime'), 'off is off, whatever the roster lists');

        $this->roster = 'getRealtimeCatalog.on';
        $this->refresh();
        $this->assertSame([true, true], array_column($this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->json('realtime.providers'), 'offered'), "the operator's choices stay while it is off");
        $before = app(Catalog::class)->copy()['realtime'];
        $this->roster = fn () => Http::response(['success' => false, 'error' => 'upstream down'], 503);
        $this->refresh();
        $this->assertSame($before, app(Catalog::class)->copy()['realtime'], 'a roster that cannot be read stays as it was');
        $this->assertSame(__('catalog.realtime_unread'), $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->json('error.message'));
        $this->assertFalse(app(Catalog::class)->current(), 'and is read again at the next run');

        // The platform renames a provider to carry its own name, and adds a voice whose name does: neither reaches a customer; the operator sees its choice, flagged.
        $renamed = RecordedTelenow::body('getRealtimeCatalog.on');
        $renamed['data']['providers'][1]['name'] = config('kit.telenow.name').' Live';
        $renamed['data']['providers'][0]['models'][2]['voices'][] = ['id' => 'nova', 'name' => config('kit.telenow.name').' Nova', 'gender' => 'female'];
        $this->roster = fn () => Http::response($renamed);
        $this->refresh();
        $admin = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->json('realtime.providers');
        $this->assertSame([true, true], [$admin[1]['offered'], $admin[1]['namesPlatform']], "the operator's choice shown, its name flagged");
        $this->assertSame(['Live voices'], array_column($this->choices()->json('realtime'), 'name'), 'a name that names the platform is never offered');
        $this->assertNotContains('nova', array_column($this->choices()->json('realtime.0.models.2.voices'), 'id'), 'nor a voice whose name does');
        $this->offer(['realtime' => ['openai' => ['offered' => true, 'models' => array_fill_keys(['gpt-realtime-2.1', 'gpt-realtime-2.1-mini', 'gpt-realtime', 'gpt-realtime-mini'], ['offered' => false])]]])->assertOk();
        $this->assertSame([], $this->choices()->json('realtime'), 'a provider with every model switched off is not offered');
    }

    public function test_an_agent_speaks_with_a_realtime_voice_kept_whole_in_telenows_blob(): void
    {
        $this->offerRealtime();
        // As Telenow holds one with no `fallback`, which it reads as falling back (recorded).
        $this->agent->forceFill(['settings' => app(\App\Kit\Agents\AgentSettings::class)->read(['s2s_config' => ['provider' => 'openai', 'model' => 'gpt-realtime', 'voice' => 'marin']] + $this->held)])->save();
        $this->assertSame(['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'voice' => 'marin', 'fallback' => true],
            array_intersect_key($this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}")->json('settings.realtime'), array_flip(['provider', 'model', 'voice', 'fallback'])));
        $this->agent->forceFill(['settings' => app(\App\Kit\Agents\AgentSettings::class)->read($this->held)])->save();
        $rt = fn (array $over = []) => ['realtime' => $over + ['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'voice' => 'marin']];
        $saved = $this->save($rt())->assertOk();
        $this->assertSame(['s2sConfig' => ['provider' => 'openai', 'model' => 'gpt-realtime', 'voice' => 'marin', 'fallback' => true]], $this->lastPut(), 'only the realtime blob, falling back to the pipeline');
        $this->assertSame(['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'voice' => 'marin', 'fallback' => true,
            'names' => ['provider' => 'OpenAI Realtime', 'model' => 'GPT Realtime', 'voice' => 'Marin'], 'available' => true], $saved->json('settings.realtime'));

        // Telenow's blob as it is: a key it shows masked and a setting of its own stay while the engine does.
        $this->held['s2s_config'] = ['provider' => 'openai', 'model' => 'gpt-realtime', 'voice' => 'marin', 'fallback' => true, 'apiKey' => '••••1234', 'temperature' => 0.6];
        $this->save($rt(['voice' => 'cedar', 'fallback' => false]))->assertOk();
        $this->assertSame(['provider' => 'openai', 'model' => 'gpt-realtime', 'voice' => 'cedar', 'fallback' => false, 'apiKey' => '••••1234', 'temperature' => 0.6], $this->lastPut()['s2sConfig']);
        $this->save($rt(['voice' => null, 'fallback' => false]))->assertOk();
        $this->assertSame(['provider' => 'openai', 'model' => 'gpt-realtime', 'fallback' => false, 'apiKey' => '••••1234', 'temperature' => 0.6], $this->lastPut()['s2sConfig'], "none: the engine's own default");
        $this->save(['realtime' => ['provider' => self::key('gemini'), 'model' => 'gemini-3.8-live', 'voice' => 'Puck']])->assertOk();
        $this->assertSame(['provider' => 'gemini', 'model' => 'gemini-3.8-live', 'voice' => 'Puck', 'fallback' => true], $this->lastPut()['s2sConfig'],
            "another engine starts from nothing: Telenow refuses a mask when the engine changes (recorded)");
        $off = $this->save(['realtime' => null])->assertOk();
        $this->assertStringContainsString('"s2sConfig":{}', end($this->sent)['body'], 'none is {}, an object');
        $this->assertNull($off->json('settings.realtime'));
        $puts = count($this->sentTo('PUT', '/api/agents/'.self::AGENT));

        $refused = [
            [['provider' => Catalog::publicKey('tts', 'telenow'), 'model' => 'gpt-realtime'], 'realtime.provider', __('catalog.not_offered')],
            [['provider' => self::key('openai'), 'model' => 'gpt-realtime-mini'], 'realtime.model', __('catalog.not_offered')],
            [['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'voice' => 'Zephyr'], 'realtime.voice', __('voices.unknown_voice')],
            [['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'fallback' => 'false'], 'realtime.fallback', __('catalog.setting_not_a_switch')],
            [[['provider' => self::key('openai')]], 'realtime', __('catalog.realtime_not_a_choice')],
        ];
        foreach ($refused as [$given, $at, $said]) {
            $this->assertSame([$at => [$said]], $this->save(['realtime' => $given])->assertStatus(422)->json('errors'), json_encode($given));
        }
        $this->assertCount($puts, $this->sentTo('PUT', '/api/agents/'.self::AGENT), 'none of those reached Telenow');

        // The platform stops running realtime voices: none can be picked, an agent left on one says its calls use the pipeline, and it can still be turned off.
        $this->save($rt())->assertOk();
        $this->roster = 'getRealtimeCatalog.off';
        $this->refresh();
        $this->assertSame(['realtime' => [__('catalog.realtime_off')]], $this->save($rt(['voice' => 'cedar']))->assertStatus(422)->json('errors'));
        $this->assertSame([false, null], [$this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}")->json('settings.realtime.available'),
            $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}")->json('settings.realtime.names.provider')]);
        $this->save(['realtime' => null])->assertOk();
        $this->assertSame([], $this->lastPut()['s2sConfig']);
    }

    public function test_a_new_agent_starts_on_the_realtime_voice_it_was_given(): void
    {
        $this->offerRealtime();
        $body = ['name' => 'Night desk', 'systemPrompt' => 'You answer the phone.', 'llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-4o-mini',
            'sttProvider' => Catalog::publicKey('stt', 'deepgram'), 'ttsProvider' => Catalog::publicKey('tts', 'telenow'), 'ttsVoice' => 'vikram-en-in'];
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $body + ['realtime' => ['provider' => self::key('gemini'), 'model' => 'nosuch']])->assertStatus(422)
            ->assertJsonPath('errors', ['realtime.model' => [__('catalog.not_offered')]]);
        $this->assertSame([], $this->sentTo('POST', '/api/agents'), 'nothing made at Telenow');
        $made = $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $body + ['realtime' => ['provider' => self::key('gemini'), 'model' => 'gemini-3.8-live']])->assertCreated();
        [$create] = $this->sentTo('POST', '/api/agents');
        $sent = json_decode($create['body'], true);
        $this->assertSame([['provider' => 'gemini', 'model' => 'gemini-3.8-live', 'fallback' => true], 'telenow'], [$sent['s2sConfig'], $sent['ttsProvider']], 'the pipeline beside it, as Telenow requires');
        $this->assertSame(self::key('gemini'), $made->json('settings.realtime.provider'));
    }

    public function test_a_realtime_voice_is_heard_with_the_customers_key_and_the_setup_check_says_it_is_not_checked(): void
    {
        $this->offerRealtime();
        config(['kit.catalog.realtime.previews_per_minute' => 100]);
        $hear = fn (array $body) => $this->actingAs($this->ben)->postJson('/api/kit/console/voices/realtime-preview', $body);
        $hear(['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'voice' => 'cedar'])->assertOk()->assertHeader('Content-Type', 'audio/wav');
        [$asked] = $this->sentTo('POST', '/api/providers/s2s/openai/preview');
        $this->assertSame([self::KEY, ['model' => 'gpt-realtime', 'voice' => 'cedar', 'text' => __('voices.preview_text')]], [$asked['key'], json_decode($asked['body'], true)], "the customer's own key");
        // The roster with the model's voices the other way round: none named is the first it recommends, not its first.
        $reordered = RecordedTelenow::body('getRealtimeCatalog.on');
        $reordered['data']['providers'][0]['models'][2]['voices'] = array_reverse($reordered['data']['providers'][0]['models'][2]['voices']);
        $this->roster = fn () => Http::response($reordered);
        $this->refresh();
        $this->travel(7)->seconds();
        $hear(['provider' => self::key('openai'), 'model' => 'gpt-realtime'])->assertOk();
        $this->assertSame('cedar', json_decode(end($this->sent)['body'], true)['voice'], 'none named: the one the model recommends');
        $this->travel(7)->seconds();
        $hear(['provider' => self::key('gemini'), 'model' => 'gemini-3.8-live'])->assertOk();
        $this->assertSame('Zephyr', json_decode(end($this->sent)['body'], true)['voice'], 'a model recommending none: its first');

        foreach ([['previewRealtimeVoice.no-key', 422, __('voices.preview_refused')], ['previewRealtimeVoice.too-soon', 429, __('voices.preview_busy')],
            ['previewRealtimeVoice.off', 409, __('catalog.realtime_off')]] as [$recording, $status, $said]) {
            $this->previews = [$recording];
            $this->travel(7)->seconds();
            $hear(['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'voice' => 'cedar'])->assertStatus($status)->assertExactJson(['message' => $said]);
        }
        $asks = count($this->sent);
        $hear(['provider' => self::key('openai'), 'model' => 'gpt-realtime', 'voice' => 'Zephyr'])->assertStatus(422)->assertJsonValidationErrors('voice');
        $hear(['provider' => self::key('openai'), 'model' => 'gpt-realtime-mini'])->assertStatus(422)->assertJsonValidationErrors('model');
        $hear(['provider' => Catalog::publicKey('tts', 'telenow'), 'model' => 'gpt-realtime'])->assertStatus(422)->assertJsonValidationErrors('provider');
        $this->assertCount($asks, $this->sent, 'nothing asked of Telenow for those');
        config(['kit.catalog.realtime.previews_per_minute' => 1]);
        $this->travel(61)->seconds();
        $hear(['provider' => self::key('openai'), 'model' => 'gpt-realtime'])->assertOk();
        $hear(['provider' => self::key('openai'), 'model' => 'gpt-realtime'])->assertStatus(429)->assertExactJson(['message' => __('session.throttled')]);
        $this->actingAs($this->ana)->postJson('/api/kit/console/voices/realtime-preview', ['provider' => self::key('openai'), 'model' => 'gpt-realtime'])->assertOk();

        config(['kit.stack_check.per_minute' => 100]);
        $check = fn () => $this->actingAs($this->ben)->postJson("/api/kit/console/agents/{$this->agent->id}/check")->assertOk()->json('parts');
        $this->assertSame(['llm', 'stt', 'tts'], array_column($check(), 'part'), 'on the pipeline: its three parts');
        $this->save(['realtime' => ['provider' => self::key('openai'), 'model' => 'gpt-realtime']])->assertOk();
        $this->travel(4)->seconds();
        $parts = $check();
        $this->assertSame(['llm', 'stt', 'tts', 'realtime'], array_column($parts, 'part'));
        $this->assertSame(['part' => 'realtime', 'status' => 'not_checked', 'reason' => __('agents.check.realtime_not_checked')], end($parts), "Telenow's check probes the pipeline only (recorded)");
    }
}
