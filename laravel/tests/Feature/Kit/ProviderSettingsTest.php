<?php

namespace Tests\Feature\Kit;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\ProviderSettings;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\CatalogVoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Each provider's own settings, voices by model, checking the voice setup, and stars (App\Kit\Catalog\ProviderSettings, App\Kit\Agents\AgentProviderSettings,
 * App\Kit\Agents\StackCheck, App\Kit\Voices\VoiceStars), through the kit's real routes, against Telenow's recorded catalog and answers (contract_k13settings.py): the operator
 * chooses which settings and options customers get, never a key; an agent's values are checked here, since Telenow keeps any it is sent, and merged into its blob; a provider
 * changed starts afresh; a voice must be its model's; the check says each part in the kit's words; stars are each member's own.
 */
class ProviderSettingsTest extends TestCase
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

    /** @var list<array{method: string, path: string, body: string}> */
    private array $sent = [];

    /** What the voice-setup check answers next: a recording's name, or a closure. */
    private array $checks = [];

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
        // A key Telenow shows masked, and a setting customers are not offered: both kept as they are when the agent's settings change.
        $this->held = ['id' => self::AGENT, 'name' => 'Front desk', 'llm_provider' => 'openai', 'llm_model' => 'gpt-4o-mini', 'stt_provider' => 'deepgram', 'tts_provider' => 'telenow',
            'tts_voice' => 'vikram-en-in', 'llm_config' => ['temperature' => 0.4], 'stt_config' => ['model' => 'nova-3', 'language' => 'hi'],
            'tts_config' => ['model' => 'telenow-v1', 'quality' => 'phone', 'apiKey' => '••••1234', 'fidelity' => 3], 'metadata' => ['kitId' => 'k'], 'session_config' => new \stdClass, 'telephony_config' => new \stdClass];
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->agent->forceFill(['telenow_id' => self::AGENT, 'sync_state' => 'synced', 'llm_provider' => 'openai', 'llm_model' => 'gpt-4o-mini', 'stt_provider' => 'deepgram',
            'tts_provider' => 'telenow', 'tts_voice' => 'vikram-en-in', 'settings' => app(\App\Kit\Agents\AgentSettings::class)->read($this->held)])->save();
    }

    /** Telenow: the recordings, the agent as it is held, a voice list by model where a test gives one, the check's answers. */
    private function answering(?\Closure $voices = null): void
    {
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($recorded, $voices) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if ($voices !== null && preg_match('#^/api/providers/tts/[^/]+/voices$#', $path) === 1 && ($answer = $voices($r)) !== null) {
                return $answer;
            }
            if (str_starts_with($path, '/api/agents/'.self::AGENT) || $path === '/api/agents/preflight' || ($path === '/api/agents' && $r->method() === 'POST')) {
                $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->body()];
            }
            if ($path === '/api/agents/preflight') {
                $next = array_shift($this->checks) ?? 'preflightVoiceStack.no-platform-keys';

                return $next instanceof \Closure ? $next($r) : RecordedTelenow::response($next);
            }
            if ($path === '/api/agents/'.self::AGENT && $r->method() === 'GET') {
                return Http::response(['success' => true, 'data' => $this->held]);
            }
            if ($path === '/api/agents/'.self::AGENT.'/versions') {
                return Http::response(['success' => true, 'data' => ['versions' => [['version' => 1, 'note' => 'update']]]]);
            }
            if ($path === '/api/agents/'.self::AGENT && $r->method() === 'PUT') {
                $names = ['llmProvider' => 'llm_provider', 'llmModel' => 'llm_model', 'sttProvider' => 'stt_provider', 'ttsProvider' => 'tts_provider', 'ttsVoice' => 'tts_voice',
                    'llmConfig' => 'llm_config', 'sttConfig' => 'stt_config', 'ttsConfig' => 'tts_config', 'metadata' => 'metadata', 'sessionConfig' => 'session_config', 'telephonyConfig' => 'telephony_config'];
                foreach (json_decode($r->body(), true) as $k => $v) {
                    $this->held[$names[$k] ?? $k] = $v; // a blob sent replaces the one held whole (recorded)
                }

                return Http::response(['success' => true, 'data' => $this->held, 'version' => 2]);
            }

            return $recorded($r);
        });
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

    /** @return list<array> what a PUT to the agent sent */
    private function puts(): array
    {
        return array_values(array_map(fn (array $s) => json_decode($s['body'], true), array_filter($this->sent, fn (array $s) => $s['method'] === 'PUT')));
    }

    private function lastPut(): array
    {
        $puts = $this->puts();

        return end($puts) ?: [];
    }

    private function save(array $body, ?User $who = null): TestResponse
    {
        return $this->actingAs($who ?? $this->ben)->patchJson("/api/kit/console/agents/{$this->agent->id}", $body);
    }

    private function stack(array $over = []): array
    {
        return $over + ['llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-4o-mini', 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
            'ttsProvider' => Catalog::publicKey('tts', 'telenow'), 'ttsVoice' => 'vikram-en-in'];
    }

    private static function option(string $setting, mixed $value): string
    {
        return ProviderSettings::publicValue($setting, $value);
    }

    public function test_the_operator_chooses_which_settings_and_options_customers_get_and_never_a_key(): void
    {
        $page = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk();
        $settingsOf = fn (TestResponse $p, string $kind, string $id) => collect(collect($p->json("kinds.{$kind}"))->firstWhere('id', $id)['settings'])->keyBy('key');
        $tts = $settingsOf($page, 'tts', 'telenow');
        $this->assertSame(['model', 'quality', 'language', 'speed', 'stability', 'fidelity'], $tts->keys()->all());
        $this->assertSame([true, true], [$tts['model']['model'], $tts['model']['offered']], "the field that holds the voice's model, offered");
        $this->assertSame(['telenow-v1', 'kokoro'], array_column($tts['model']['options'], 'value'), "the operator sees Telenow's own values");
        $stt = $settingsOf($page, 'stt', 'deepgram');
        $this->assertSame([true, true, false], [$stt['language']['offered'], $stt['smart_format']['offered'], $stt['keyterms']['offered']], 'bounded settings offered; free text not until the operator says');
        $this->assertStringNotContainsString('"sensitive"', $page->getContent());
        foreach (collect($page->json('kinds'))->flatten(1) as $p) {
            $this->assertSame([], array_values(array_intersect(array_column($p['settings'], 'key'), ['apiKey', 'secretAccessKey', 'sessionToken', 'bearer', 'custom', 'payload',
                'accessKeyId', 'region', 'endpoint', 'apiEndpoint', 'baseUrl'])), "{$p['id']}: no key, no descriptor, nowhere to send a call (accessKeyId is not marked sensitive in the catalog)");
        }
        $this->assertSame(['azure' => ['deployment', 'apiVersion', 'temperature'], 'customllm' => ['defaultModel', 'temperature', 'max_tokens']], array_map(fn (array $p) => array_column($p['settings'], 'key'),
            collect($page->json('kinds.llm'))->whereIn('id', ['azure', 'customllm'])->keyBy('id')->all()), 'what is left of those providers once the address and the keys are dropped');
        foreach (app(Catalog::class)->copy()['kinds'] as $providers) {
            foreach ($providers as $p) {
                foreach ($p['settings'] as $f) {
                    $this->assertSame([], array_values(array_diff(array_keys($f), [...config('kit.catalog.settings.field'), 'model'])), "{$p['id']}.{$f['key']}: only the fields the kit names, never a placeholder");
                }
            }
        }
        $this->assertTrue($settingsOf($page, 'tts', 'polly')['engine']['model'], "the model field is the one the catalog's models name (Polly's is engine)");
        $this->assertSame(['speed'], array_column(app(ProviderSettings::class)->copied(['configFields' => [['key' => strtolower(config('kit.telenow.name')).'Boost', 'kind' => 'toggle', 'label' => 'Boost'],
            ['key' => 'licence', 'kind' => 'text', 'label' => 'Licence', 'sensitive' => true], ['key' => 'speed', 'kind' => 'number', 'label' => 'Speed']]]), 'key'),
            "neither a setting whose key names the platform (customers name a setting by its key) nor one marked sensitive under a name Telenow's own list does not have");

        $this->offer(['kinds' => ['tts' => ['telenow' => ['settings' => ['stability' => ['offered' => false], 'model' => ['offered' => true, 'options' => ['kokoro' => false]]]]],
            'stt' => ['deepgram' => ['settings' => ['keyterms' => ['offered' => true]]]]]])->assertOk();
        $this->offer(['kinds' => ['tts' => ['telenow' => ['settings' => ['nosuch' => ['offered' => true]]]]]])->assertStatus(422)->assertJsonValidationErrors('kinds.tts.telenow.settings.nosuch');
        $this->offer(['kinds' => ['tts' => ['telenow' => ['settings' => ['model' => ['offered' => true, 'options' => ['nosuch' => true]]]]]]])->assertStatus(422)
            ->assertJsonValidationErrors('kinds.tts.telenow.settings.model.options.nosuch');
        $this->offer(['kinds' => ['tts' => ['telenow' => ['name' => 'Studio voices']]]])->assertOk(); // no settings sent: those chosen stay
        $again = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk();
        $this->assertSame([false, [true, false]], [$settingsOf($again, 'tts', 'telenow')['stability']['offered'], array_column($settingsOf($again, 'tts', 'telenow')['model']['options'], 'offered')]);

        // A label, a help or an option's label that names the platform is never shown: the key, the option's value or, when that names it too, a word of the kit's stands in.
        $named = config('kit.telenow.name');
        $copy = app(Settings::class)->get(Catalog::COPY);
        $copy['kinds']['tts'] = array_map(fn (array $p) => $p['id'] !== 'telenow' ? $p : ['settings' => array_map(fn (array $f) => match ($f['key']) {
            'quality' => ['label' => "{$named} clarity", 'help' => "Tuned by {$named}.", 'options' => [['value' => 'phone', 'label' => "{$named} phone"], ...array_slice($f['options'], 1)]] + $f,
            'model' => ['options' => [['value' => 'telenow-v1', 'label' => "{$named} v1"], ...array_slice($f['options'], 1)]] + $f,
            default => $f,
        }, $p['settings'])] + $p, $copy['kinds']['tts']);
        app(Settings::class)->put(Catalog::COPY, $copy);

        $choices = $this->actingAs($this->ben)->getJson('/api/kit/console/catalog')->assertOk();
        $mine = collect(collect($choices->json('tts'))->firstWhere('name', 'Studio voices')['settings'])->keyBy('key');
        $this->assertSame(['model', 'quality', 'language', 'speed', 'fidelity'], $mine->keys()->all(), 'what the operator offers');
        $this->assertSame([[['value' => self::option('model', 'telenow-v1'), 'label' => __('catalog.option_numbered', ['n' => 1])]], self::option('model', 'telenow-v1')],
            [$mine['model']['options'], $mine['model']['default']], "an option and the default by the kit's own name, and a label naming the platform not shown (nor its value, which does too)");
        $this->assertSame(['quality', false, ['phone', 'Fast (balanced)']], [$mine['quality']['label'], array_key_exists('help', $mine['quality']), array_column(array_slice($mine['quality']['options'], 0, 2), 'label')]);
        $this->assertSame(['min' => 0.5, 'max' => 1.5, 'default' => 1.35], array_intersect_key($mine['speed'], array_flip(['min', 'max', 'default'])));
        $this->assertContains('keyterms', array_column(collect($choices->json('stt'))->first()['settings'], 'key'));
        $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $choices->getContent(), "no Telenow model id (telenow-v1) reaches a customer");

        $this->offer(['kinds' => ['tts' => ['telenow' => ['settings' => ['model' => ['offered' => true, 'options' => ['telenow-v1' => false, 'kokoro' => true]]]]]]])->assertOk();
        $model = collect(collect($this->actingAs($this->ben)->getJson('/api/kit/console/catalog')->json('tts'))->firstWhere('name', 'Studio voices')['settings'])->firstWhere('key', 'model');
        $this->assertSame([[self::option('model', 'kokoro')], null], [array_column($model['options'], 'value'), $model['default']], 'a default the operator no longer offers is not shown as one');
    }

    public function test_an_agents_settings_are_checked_here_and_merged_into_telenows_blob(): void
    {
        $this->assertSame(['model' => 'telenow-v1', 'quality' => 'phone', 'fidelity' => 3], $this->agent->settings['ttsSettings']['values'], 'the kit keeps only the settings the catalog names, never a key, not even masked');
        $saved =$this->save(['providerSettings' => ['tts' => ['quality' => self::option('quality', 'fast'), 'speed' => 1.2], 'llm' => ['temperature' => 0.9]]])->assertOk();
        [$put] = $this->puts();
        $this->assertSame(['model' => 'telenow-v1', 'quality' => 'fast', 'apiKey' => '••••1234', 'fidelity' => 3, 'speed' => 1.2], $put['ttsConfig'],
            "Telenow's blob as it was, the masked key and a setting not offered kept, only those sent changed");
        $this->assertSame(['temperature' => 0.9], $put['llmConfig']);
        $this->assertArrayNotHasKey('sttConfig', $put, 'a blob not touched is not sent');
        $values = $saved->json('settings.ttsSettings.values');
        $this->assertSame(['model' => self::option('model', 'telenow-v1'), 'quality' => self::option('quality', 'fast'), 'speed' => 1.2, 'fidelity' => 3], $values, 'as customers name them, never a key');
        $this->assertNull($saved->json('settings.ttsSettings.provider'), "never Telenow's provider id");
        $this->assertStringNotContainsString('1234', $saved->getContent());

        $refused = [
            [['tts' => ['speed' => 9]], 'providerSettings.tts.speed', __('catalog.setting_out_of_range', ['min' => 0.5, 'max' => 1.5])],
            [['tts' => ['speed' => 0.1]], 'providerSettings.tts.speed', __('catalog.setting_out_of_range', ['min' => 0.5, 'max' => 1.5])],
            [['tts' => ['speed' => '1.2']], 'providerSettings.tts.speed', __('catalog.setting_out_of_range', ['min' => 0.5, 'max' => 1.5])],
            [['tts' => ['quality' => 'fast']], 'providerSettings.tts.quality', __('catalog.setting_not_an_option')],
            [['tts' => ['apiKey' => 'sk-typed']], 'providerSettings.tts.apiKey', __('catalog.not_a_setting')],
            [['stt' => ['smart_format' => 'yes']], 'providerSettings.stt.smart_format', __('catalog.setting_not_a_switch')],
            [['stt' => ['keyterms' => 'Acme']], 'providerSettings.stt.keyterms', __('catalog.not_a_setting')],
            [['tts' => ['speed', 'quality']], 'providerSettings.tts', __('catalog.settings_not_a_map')],
        ];
        foreach ($refused as [$given, $at, $said]) {
            $answer = $this->save(['providerSettings' => $given])->assertStatus(422);
            $this->assertSame([$at => [$said]], $answer->json('errors'), json_encode($given));
        }
        $this->offer(['kinds' => ['stt' => ['deepgram' => ['settings' => ['keyterms' => ['offered' => true]]]], 'tts' => ['telenow' => ['settings' => ['quality' => ['offered' => true, 'options' => ['fast' => false]]]]]]])->assertOk();
        $this->save(['providerSettings' => ['stt' => ['keyterms' => str_repeat('a', config('kit.catalog.settings.text_max_length') + 1)]]])->assertStatus(422)
            ->assertJsonPath('errors', ['providerSettings.stt.keyterms' => [__('catalog.setting_too_long', ['max' => config('kit.catalog.settings.text_max_length')])]]);
        $this->save(['providerSettings' => ['tts' => ['quality' => self::option('quality', 'fast')]]])->assertStatus(422)->assertJsonPath('errors', ['providerSettings.tts.quality' => [__('catalog.setting_not_an_option')]]);
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertOk()->assertJsonPath('settings.ttsSettings.values.quality', null);
        $this->assertCount(1, $this->puts(), 'none of those reached Telenow');

        $this->save(['providerSettings' => ['stt' => ['keyterms' => '  Acme, Dental  ']]])->assertOk();
        $this->assertSame('Acme, Dental', $this->puts()[1]['sttConfig']['keyterms']);
        $this->save(['providerSettings' => ['tts' => ['speed' => null]]])->assertOk();
        $this->assertArrayNotHasKey('speed', $this->puts()[2]['ttsConfig'], "null takes a setting back to the provider's own default");

        $this->save(['settings' => ['ttsSettings' => ['provider' => 'telenow', 'values' => ['apiKey' => 'sk-smuggled', 'quality' => 'fast']]]])->assertOk();
        $this->save(['settings' => ['ttsSettings' => ['provider' => 'telenow', 'values' => ['apiKey' => 'sk-smuggled', 'baseUrl' => 'http://169.254.169.254']]]]);
        $this->assertCount(3, $this->puts(), 'settings for a provider come only through providerSettings, checked');
        $this->assertStringNotContainsString('smuggled', json_encode($this->held));
    }

    public function test_a_provider_changed_starts_afresh_and_a_voice_must_be_its_models(): void
    {
        $eleven = CatalogVoice::query()->where('provider_id', 'elevenlabs')->value('voice_id');
        $this->save($this->stack(['ttsProvider' => Catalog::publicKey('tts', 'elevenlabs'), 'ttsVoice' => $eleven]) + ['providerSettings' => ['tts' => ['stability' => null]]])->assertOk();
        [$put] = $this->puts();
        $this->assertSame(['ttsProvider' => 'elevenlabs', 'ttsVoice' => $eleven, 'ttsConfig' => []], $put, "none of the last provider's settings carry over, and a setting left to the provider is not sent");
        $this->assertStringContainsString('"ttsConfig":{}', collect($this->sent)->firstWhere('method', 'PUT')['body'], 'an object, as Telenow keeps its blobs');
        $this->assertSame([], array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'GET')), 'nothing to read first: a new provider starts from nothing');
        $this->save($this->stack(['ttsProvider' => Catalog::publicKey('tts', 'elevenlabs'), 'ttsVoice' => $eleven]) + ['providerSettings' => ['tts' => ['model' => self::option('model', 'eleven_multilingual_v2')]]])->assertOk();
        $this->assertSame(['model' => 'eleven_multilingual_v2'], $this->lastPut()['ttsConfig']);
        $this->save(['providerSettings' => ['tts' => ['model' => self::option('model', 'eleven_v3')]]])->assertStatus(422)
            ->assertJsonPath('errors.ttsVoice', [__('catalog.not_for_model')], "a voice ElevenLabs lists for other models only");
        $this->save($this->stack())->assertOk(); // back on Telenow's own engine
        $this->assertSame([], $this->lastPut()['ttsConfig']);

        $kokoro = RecordedTelenow::body('listProviderVoices.telenow-kokoro')['data'];
        $this->answering(fn (Request $r) => str_contains($r->url(), '/telenow/voices') && str_contains((string) parse_url($r->url(), PHP_URL_QUERY), 'model=kokoro')
            ? Http::response(RecordedTelenow::body('listProviderVoices.telenow-kokoro')) : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame('|kokoro|', CatalogVoice::query()->where('voice_id', $kokoro[0]['id'])->value('models'));
        $put = count($this->puts());

        $this->save(['providerSettings' => ['tts' => ['model' => self::option('model', 'kokoro')]]])->assertStatus(422)->assertJsonPath('errors.ttsVoice', [__('catalog.not_for_model')]);
        $this->save($this->stack(['ttsVoice' => $kokoro[0]['id']]))->assertStatus(422)->assertJsonPath('errors.ttsVoice', [__('catalog.not_for_model')]);
        $this->assertCount($put, $this->puts(), 'Telenow takes a voice of another model (recorded); the kit does not send one');
        $this->save($this->stack(['ttsVoice' => $kokoro[0]['id']]) + ['providerSettings' => ['tts' => ['model' => self::option('model', 'kokoro')]]])->assertOk();
        $this->assertSame([$kokoro[0]['id'], 'kokoro'], [$this->lastPut()['ttsVoice'], $this->lastPut()['ttsConfig']['model']]);
        $this->save($this->stack())->assertStatus(422)->assertJsonPath('errors.ttsVoice', [__('catalog.not_for_model')], 'the agent speaks with kokoro now: a v1 voice is not one of its');
        $this->actingAs($this->ben)->getJson('/api/kit/console/voices?provider='.Catalog::publicKey('tts', 'telenow').'&model='.self::option('model', 'kokoro'))->assertOk()
            ->assertJsonPath('total', count($kokoro));
    }

    public function test_a_new_agent_starts_with_the_settings_it_was_given(): void
    {
        $make = fn (array $settings) => $this->actingAs($this->ana)->postJson('/api/kit/console/agents', ['name' => 'Night desk', 'systemPrompt' => 'You answer the phone.'] + $this->stack()
            + ['providerSettings' => $settings]);
        $made = fn () => array_values(array_filter($this->sent, fn (array $s) => $s['path'] === '/api/agents'));
        $make(['tts' => ['speed' => 9]])->assertStatus(422)->assertJsonPath('errors', ['providerSettings.tts.speed' => [__('catalog.setting_out_of_range', ['min' => 0.5, 'max' => 1.5])]]);
        $make(['tts' => ['model' => self::option('model', 'kokoro')]])->assertStatus(422)->assertJsonPath('errors.ttsVoice', [__('catalog.not_for_model')]);
        $this->assertSame([], $made(), 'nothing made at Telenow for those');

        $answer = $make(['tts' => ['quality' => self::option('quality', 'fast'), 'speed' => 1.2], 'llm' => ['temperature' => 0.3]])->assertCreated();
        $this->assertStringContainsString('"sttSettings":{"values":{}}', $answer->getContent(), 'none yet is an object, as the contract says');
        [$create] = $made();
        $body = json_decode($create['body'], true);
        $this->assertSame([['quality' => 'fast', 'speed' => 1.2], ['temperature' => 0.3], []], [$body['ttsConfig'] ?? null, $body['llmConfig'] ?? null, $body['sttConfig'] ?? null],
            'made with them, and a kind with none given starts from nothing');
    }

    public function test_checking_the_voice_setup_says_each_part_in_the_kits_words_and_is_kept(): void
    {
        config(['kit.stack_check.per_minute' => 100]);
        $check = fn (?User $who = null) => $this->actingAs($who ?? $this->ben)->postJson("/api/kit/console/agents/{$this->agent->id}/check");
        $done = $check()->assertOk();
        $this->assertSame([['part' => 'llm', 'status' => 'failed', 'reason' => __('agents.check.because.platform')], ['part' => 'stt', 'status' => 'failed', 'reason' => __('agents.check.because.platform')],
            ['part' => 'tts', 'status' => 'not_checked', 'reason' => __('agents.check.not_checked')]], $done->json('parts'));
        [$asked] = array_values(array_filter($this->sent, fn (array $s) => $s['path'] === '/api/agents/preflight'));
        $this->assertSame(['agentId' => self::AGENT], json_decode($asked['body'], true));
        foreach (['deepgram', 'openai', 'platform key', 'BYOK', 'advice'] as $never) {
            $this->assertStringNotContainsStringIgnoringCase($never, $done->getContent(), "a provider's own words never reach a customer: {$never}");
        }
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertOk()->assertJsonPath('stackCheck.parts', $done->json('parts'))->assertJsonPath('stackCheck.checkedAt', $done->json('checkedAt'));

        $this->checks = ['preflightVoiceStack.too-soon'];
        $check()->assertStatus(429)->assertExactJson(['message' => __('agents.check.too_soon')]);
        $this->checks = ['preflightVoiceStack.unknown-agent'];
        $check()->assertStatus(409)->assertExactJson(['message' => __('agents.not_ready')]);
        $this->checks = [fn () => Http::response(['success' => false, 'error' => 'down'], 502)];
        $check()->assertStatus(503)->assertExactJson(['message' => __('agents.check.unavailable')]);
        $this->checks = [fn () => Http::response(['success' => false, 'error' => 'forbidden'], 403)];
        $check()->assertStatus(409)->assertExactJson(['message' => __('agents.check.refused')]);
        $this->checks = [fn () => Http::response(['success' => true, 'data' => ['reports' => [['component' => 'tts', 'provider' => 'x', 'keySource' => 'platform', 'status' => 'ok'],
            ['component' => 'stt', 'provider' => 'x', 'keySource' => 'platform', 'status' => 'failed', 'attribution' => 'something new']], 'persisted' => true, 'analysisModel' => null]])];
        $this->assertSame([['part' => 'llm', 'status' => 'not_checked', 'reason' => __('agents.check.not_checked')], ['part' => 'stt', 'status' => 'failed', 'reason' => __('agents.check.because.unknown')],
            ['part' => 'tts', 'status' => 'ok', 'reason' => null]], $check()->assertOk()->json('parts'), 'a part not reported is not checked; a reason Telenow adds later reads as unknown');

        $asks = count($this->sent);
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $check()->assertStatus(409)->assertExactJson(['message' => __('console.paused.account_suspended')]);
        $this->acme->forceFill(['status' => 'active'])->save();
        $draft = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Draft']);
        $this->actingAs($this->ben)->postJson("/api/kit/console/agents/{$draft->id}/check")->assertStatus(409)->assertExactJson(['message' => __('agents.not_ready')]);
        $this->assertCount($asks, $this->sent, 'nothing asked of Telenow for those');
        config(['kit.stack_check.per_minute' => 1]);
        $this->travel(61)->seconds();
        $check($this->ana)->assertOk();
        $check($this->ana)->assertStatus(429);
        $check($this->ben)->assertOk();
    }

    public function test_stars_are_each_members_own(): void
    {
        $studio = Catalog::publicKey('tts', 'telenow');
        $star = fn (string $voice, ?User $who = null, ?string $provider = null) => $this->actingAs($who ?? $this->ben)->putJson('/api/kit/console/voices/stars', ['provider' => $provider ?? $studio, 'voice' => $voice]);
        $list = fn (string $query, ?User $who = null) => $this->actingAs($who ?? $this->ben)->getJson('/api/kit/console/voices?provider='.$studio.$query)->assertOk();

        $star('vikram-en-in')->assertNoContent();
        $star('vikram-en-in')->assertNoContent();
        $this->assertSame(['vikram-en-in'], array_column($list('&starred=1')->json('voices'), 'id'));
        $this->assertSame([true], array_values(array_unique(array_column(array_filter($list('')->json('voices'), fn ($v) => $v['id'] === 'vikram-en-in'), 'starred'))));
        $this->assertSame(0, $list('&starred=1', $this->ana)->json('total'), "another member's stars are theirs");
        $this->assertSame([false], array_values(array_unique(array_column(array_filter($list('', $this->ana)->json('voices'), fn ($v) => $v['id'] === 'vikram-en-in'), 'starred'))));
        $star('no-such-voice')->assertStatus(422)->assertJsonPath('errors.voice', [__('voices.unknown_voice')]);
        $star('vikram-en-in', null, Catalog::publicKey('tts', 'openai'))->assertStatus(422)->assertJsonValidationErrors('provider');
        config(['kit.voice_stars.per_member' => 1]);
        $other = CatalogVoice::query()->where('provider_id', 'telenow')->where('voice_id', '!=', 'vikram-en-in')->value('voice_id');
        $star($other)->assertStatus(422)->assertJsonPath('errors.voice', [__('voices.stars_full', ['max' => 1])]);
        $star('vikram-en-in')->assertNoContent(); // one already starred is fine at the limit
        $star('vikram-en-in', $this->ana)->assertNoContent();
        $this->actingAs($this->ben)->deleteJson('/api/kit/console/voices/stars', ['provider' => $studio, 'voice' => 'vikram-en-in'])->assertNoContent();
        $this->actingAs($this->ben)->deleteJson('/api/kit/console/voices/stars', ['provider' => $studio, 'voice' => 'vikram-en-in'])->assertNoContent();
        $this->assertSame(0, $list('&starred=1')->json('total'));
        $this->assertSame(['vikram-en-in'], array_column($list('&starred=1', $this->ana)->json('voices'), 'id'), "unstarring is the member's own: Ana's star stays");
        $star($other)->assertNoContent();
    }
}
