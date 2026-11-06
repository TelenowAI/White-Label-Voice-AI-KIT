<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Kit\Softphone\BrowserCalls;
use App\Models\Agent;
use App\Models\AuditEvent;
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
 * The catalog (App\Kit\Catalog): the kit's copy of Telenow's catalog keeps only the fields it names — never a price or a
 * key's name — and Telenow's recommended stack; the operator offers providers (and language models) under names of its
 * own, never one that carries the platform's name, and picks the new-agent defaults among them; customers see only
 * what is offered, under the kit's own keys; they browse the offered voices and hear one, or an agent, in a clip made
 * with their own key (Telenow's refusals in the kit's words); an agent's stack is chosen whole from what is offered.
 * Real recordings (contract_k1catalog.py: Telenow main's voice library binary; its own TTS engine was a stand-in). The
 * voice lists themselves: CatalogVoicesTest.
 */
class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const OPERATOR = 'vai_test_operator_admin';

    /** The agent whose stack the recording changed (k1catalog_cassettes.py BILLING_DESK). */
    private const BILLING_DESK = '5b0c2a9e-4d1f-4c3a-8e72-9f6d1b0a4c35';

    private User $staff;

    private User $ana;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        RecordedTelenow::fake();
    }

    private function answering(\Closure $first): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $first($r) ?? $recorded($r));
    }

    /** @return list<Request> */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path)->map(fn (array $p) => $p[0])->values()->all();
    }

    private static function catalog(): array
    {
        return RecordedTelenow::body('getCatalog.operator')['data'];
    }

    /** The operator's offer most tests start from: Telenow's own voices only under a name of the operator's. */
    private function offer(array $more = []): TestResponse
    {
        return $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', array_replace_recursive([
            'kinds' => [
                'llm' => ['openai' => ['offered' => true, 'models' => ['gpt-4.1-nano' => ['offered' => false]]],
                    'anthropic' => ['offered' => true, 'models' => ['claude-haiku-4-5' => ['name' => 'Fast and thrifty']]]],
                'stt' => ['deepgram' => ['offered' => true]],
                'tts' => ['telenow' => ['offered' => true, 'name' => 'Studio voices'], 'elevenlabs' => ['offered' => true]],
            ],
            'defaults' => ['llmProvider' => 'openai', 'llmModel' => 'gpt-4o-mini', 'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in'],
        ], $more));
    }

    private function copied(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->offer()->assertOk();
    }

    /** @return list<string> every key anywhere in a nested array */
    private static function keys(array $a): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            if (is_string($k)) {
                $out[] = $k;
            }
            if (is_array($v)) {
                array_push($out, ...self::keys($v));
            }
        }

        return array_values(array_unique($out));
    }

    public function test_the_copy_keeps_only_the_fields_it_names_never_a_price_and_is_read_again_once_old(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        [$read] = $this->sent('GET', '/api/catalog');
        $this->assertSame([self::OPERATOR], $read->header('X-API-Key'), "the operator's key");
        $copy = app(Catalog::class)->copy();
        $fields = config('kit.catalog.fields');
        $allowed = array_merge($fields['provider'], $fields['model'], array_keys(config('kit.catalog.kinds')), config('kit.agents.stack'),
            ['fetchedAt', 'kinds', 'recommended', 'models', 'defaultModel', 'settings', 'value', 'model', 'realtime', 'enabled', 'providers', 'voices'], config('kit.catalog.settings.field'),
            ...array_values(config('kit.catalog.realtime.fields')));
        $this->assertSame([], array_values(array_diff(self::keys($copy), $allowed)), 'nothing but the allow-listed fields');
        $kinds = collect($copy['kinds'])->flatten(1)->flatMap(fn (array $p) => array_column($p['settings'], 'kind'))->unique()->values()->all();
        $this->assertSame([], array_values(array_intersect($kinds, config('kit.catalog.settings.never_kinds'))), 'no vendor descriptor among the settings');
        $this->assertContains('select', $kinds);
        foreach (['pricing', 'perMinuteUsd', 'perMillionCharsUsd', 'platformFee', 'keyEnvVar', 'previewUrl', 'blurb', 'sensitive', 'apiKey', 'secretAccessKey', 'bearer'] as $never) {
            $this->assertStringNotContainsString("\"{$never}\"", json_encode($copy));
        }
        $telenow = collect($copy['kinds']['tts'])->firstWhere('id', 'telenow');
        $this->assertSame(['Telenow TTS', 'telenow-v1'], [$telenow['name'], $telenow['defaultModel']]);
        $this->assertSame(['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini', 'gpt-4.1-nano'], array_column(collect($copy['kinds']['llm'])->firstWhere('id', 'openai')['models'], 'id'));
        $this->assertSame('nova-3', collect($copy['kinds']['stt'])->firstWhere('id', 'deepgram')['models'][0]['id'], "a speech model's key is its id");
        $recommended = RecordedTelenow::body('getAgentDefaults.operator')['data'];
        $this->assertSame(['llm_provider' => $recommended['llmProvider'], 'llm_model' => $recommended['llmModel'], 'stt_provider' => null, 'tts_provider' => null, 'tts_voice' => null],
            $copy['recommended'], "Telenow's recommendation; '' is none");

        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertCount(1, $this->sent('GET', '/api/catalog'), 'current: not read again');
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->answering(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/catalog' ? Http::response(['success' => false, 'error' => 'upstream down'], 503) : null);
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame($copy, app(Catalog::class)->copy(), 'a failed read keeps the copy');
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertJsonPath('error.message', 'upstream down');
    }

    public function test_the_operator_offers_providers_under_names_of_its_own_and_never_the_platforms(): void
    {
        $this->offer()->assertStatus(409)->assertExactJson(['message' => __('catalog.not_copied')]);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk()->assertJsonPath('platformName', config('kit.telenow.name'));
        $this->actingAs($this->ana)->getJson('/api/kit/admin/catalog')->assertForbidden();

        $tts = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->json('kinds.tts'))->keyBy('id');
        $this->assertSame(['Telenow TTS', true, false], [$tts['telenow']['catalogName'], $tts['telenow']['namesPlatform'], $tts['telenow']['offered']], 'nothing is offered until the operator offers it');
        $this->offer(['kinds' => ['tts' => ['telenow' => ['name' => ' ']]]])->assertStatus(422)
            ->assertJsonPath('errors', ['kinds.tts.telenow.name' => [__('catalog.names_platform', ['platform' => config('kit.telenow.name')])]]);
        $this->assertSame([], app(Catalog::class)->curation(), 'all or nothing');
        $this->offer(['defaults' => ['llmModel' => 'gpt-4.1-nano']])->assertStatus(422)->assertJsonPath('errors', ['defaults.llmModel' => [__('catalog.default_not_offered')]]);
        $this->assertSame([], app(Catalog::class)->curation(), 'a refused default saves nothing either');
        $this->offer(['kinds' => ['llm' => ['nosuch' => ['offered' => true]]]])->assertStatus(422)->assertJsonValidationErrors(['kinds.llm.nosuch']);

        $saved = $this->offer()->assertOk();
        $tts = collect($saved->json('kinds.tts'))->keyBy('id');
        $this->assertSame(count(RecordedTelenow::body('listProviderVoices.telenow')['data']), $tts['telenow']['voiceCount']);
        $this->assertSame('Vikram', $saved->json('voiceNames.vikram-en-in'), "the default voice's name, for when its page is not the one shown");
        $this->assertSame(['Studio voices', true, false], [$tts['telenow']['name'], $tts['telenow']['offered'], $tts['telenow']['namesPlatform']]);
        $models = collect(collect($saved->json('kinds.llm'))->firstWhere('id', 'anthropic')['models'])->keyBy('id');
        $this->assertSame(['Fast and thrifty', true], [$models['claude-haiku-4-5']['name'], $models['claude-haiku-4-5']['offered']]);
        $this->assertSame(['gpt-4o-mini', 'gpt-4o', 'gpt-4.1-mini'], array_column(array_filter(collect($saved->json('kinds.llm'))->firstWhere('id', 'openai')['models'], fn ($m) => $m['offered']), 'id'));
        $this->assertSame(['llm_provider' => 'openai', 'llm_model' => 'gpt-4o-mini', 'stt_provider' => 'deepgram', 'tts_provider' => 'telenow', 'tts_voice' => 'vikram-en-in'],
            app(Settings::class)->get(Catalog::DEFAULTS), 'what a new agent starts from');
        $saved->assertJsonPath('recommended.llmModel', RecordedTelenow::body('getAgentDefaults.operator')['data']['llmModel']);
        // Telenow recommends a model its own catalog does not list for that provider: never a default.
        $this->offer(['defaults' => ['llmModel' => 'gpt-5.4-mini']])->assertStatus(422)->assertJsonValidationErrors(['defaults.llmModel']);
    }

    public function test_customers_see_only_what_is_offered_under_the_kits_own_keys(): void
    {
        $this->copied();
        $choices = $this->actingAs($this->ana)->getJson('/api/kit/console/catalog')->assertOk();
        $withoutSettings = $choices->json();
        foreach (config('kit.catalog.kinds') as $kind => $parts) {
            foreach ($withoutSettings[$kind] as $i => $p) {
                $this->assertIsArray($p['settings'], "each provider's own settings (ProviderSettingsTest)");
                unset($withoutSettings[$kind][$i]['settings']);
            }
        }
        $this->assertEquals([
            'llm' => [
                ['key' => Catalog::publicKey('llm', 'anthropic'), 'name' => 'Anthropic', 'models' => [
                    ['id' => 'claude-opus-4-7', 'name' => 'Claude Opus 4.7'], ['id' => 'claude-haiku-4-5', 'name' => 'Fast and thrifty'],
                    ['id' => 'claude-sonnet-5', 'name' => 'Claude Sonnet 5'], ['id' => 'claude-opus-5', 'name' => 'Claude Opus 5'], ['id' => 'claude-fable-5', 'name' => 'Claude Fable 5']]],
                ['key' => Catalog::publicKey('llm', 'openai'), 'name' => 'OpenAI', 'models' => [
                    ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o mini'], ['id' => 'gpt-4o', 'name' => 'GPT-4o'], ['id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 mini']]],
            ],
            'stt' => [['key' => Catalog::publicKey('stt', 'deepgram'), 'name' => 'Deepgram']],
            'tts' => [['key' => Catalog::publicKey('tts', 'telenow'), 'name' => 'Studio voices'], ['key' => Catalog::publicKey('tts', 'elevenlabs'), 'name' => 'ElevenLabs']],
            'defaults' => ['llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-4o-mini', 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
                'ttsProvider' => Catalog::publicKey('tts', 'telenow'), 'ttsVoice' => 'vikram-en-in'],
            'realtime' => [], // the platform runs no realtime voices in this recording (RealtimeTest)
        ], $withoutSettings);
        $voices = $this->actingAs($this->ana)->getJson('/api/kit/console/voices')->assertOk();
        foreach ([$choices, $voices] as $page) {
            $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $page->getContent());
            $this->assertStringNotContainsString('previewUrl', $page->getContent());
        }
    }

    public function test_a_name_or_description_that_carries_the_platforms_never_reaches_a_customer(): void
    {
        $this->copied();
        // The operator offered Deepgram; then its catalog name came to carry the platform's (here: the word "Deep").
        config(['kit.telenow.name' => 'Deep']);
        $this->assertSame([], $this->actingAs($this->ana)->getJson('/api/kit/console/catalog')->json('stt'), 'withdrawn until renamed');
        $deepgram = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->json('kinds.stt'))->firstWhere('id', 'deepgram');
        $this->assertSame([true, false], [$deepgram['namesPlatform'], $deepgram['offered']], 'the operator is told why');
        // A voice named so is left out; one described so loses its description (from the next copy).
        config(['kit.telenow.name' => 'Vikram']);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk();
        $this->assertNotContains('vikram-en-in', array_column($this->actingAs($this->ana)->getJson('/api/kit/console/voices?search=collections')->json('voices'), 'id'));
        config(['kit.telenow.name' => 'payment reminders']);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk();
        [$vikram] = $this->actingAs($this->ana)->getJson('/api/kit/console/voices?search=firm')->json('voices');
        $this->assertSame(['vikram-en-in', null], [$vikram['id'], $vikram['description']]);
    }

    public function test_customers_browse_the_offered_voices_of_each_providers_default_model(): void
    {
        config(['kit.catalog.voices_per_page' => 20]);
        $this->copied();
        $ofDefault = RecordedTelenow::body('listProviderVoices.telenow')['data'];
        $eleven = RecordedTelenow::body('listProviderVoices.elevenlabs')['data'];
        $all = $this->actingAs($this->ana)->getJson('/api/kit/console/voices')->assertOk();
        $this->assertSame([count($ofDefault) + count($eleven), 1, 20], [$all->json('total'), $all->json('page'), $all->json('perPage')], "another model's voices are left out");
        $this->assertCount(20, $all->json('voices'));
        $first = $all->json('voices.0');
        $this->assertSame(['provider' => Catalog::publicKey('tts', 'telenow'), 'providerName' => 'Studio voices', 'id' => $ofDefault[0]['id'], 'name' => $ofDefault[0]['name']],
            array_intersect_key($first, array_flip(['provider', 'providerName', 'id', 'name'])));
        $this->assertContains('hi', $all->json('languages'));
        $genders = array_values(array_unique(array_filter(array_column([...$ofDefault, ...$eleven], 'gender'))));
        sort($genders);
        $this->assertSame($genders, $all->json('genders'));

        $one = $this->actingAs($this->ana)->getJson('/api/kit/console/voices?provider='.Catalog::publicKey('tts', 'elevenlabs'));
        $this->assertSame([count($eleven), ['ElevenLabs']], [$one->json('total'), array_values(array_unique(array_column($one->json('voices'), 'providerName')))]);
        $hindi = array_filter([...$ofDefault, ...$eleven], fn ($v) => array_filter([$v['language'] ?? '', ...($v['languages'] ?? [])], fn ($l) => str_starts_with($l, 'hi')) !== []);
        $this->assertSame(count($hindi), $this->actingAs($this->ana)->getJson('/api/kit/console/voices?language=hi-IN')->json('total'), 'a language matches its regional tags');
        $female = count(array_filter([...$ofDefault, ...$eleven], fn ($v) => ($v['gender'] ?? null) === 'female'));
        $this->assertSame($female, $this->actingAs($this->ana)->getJson('/api/kit/console/voices?gender=female')->json('total'));
        $this->assertSame(['Vikram'], array_column($this->actingAs($this->ana)->getJson('/api/kit/console/voices?search=COLLECTIONS')->json('voices'), 'name'), 'words in its tags, style or use');
        $last = $this->actingAs($this->ana)->getJson('/api/kit/console/voices?page=2')->json('voices');
        $this->assertSame(array_column(array_slice([...$ofDefault, ...$eleven], 20, 20), 'id'), array_column($last, 'id'));
    }

    public function test_a_voice_is_heard_in_a_clip_made_with_the_customers_key_and_refusals_are_the_kits_words(): void
    {
        $this->copied();
        $studio = Catalog::publicKey('tts', 'telenow');
        $clip = $this->actingAs($this->ana)->postJson('/api/kit/console/voices/preview', ['provider' => $studio, 'voice' => 'vikram-en-in'])->assertOk();
        $this->assertSame(['audio/wav', 'no-store, private'], [$clip->headers->get('Content-Type'), $clip->headers->get('Cache-Control')]);
        $recorded = json_decode((string) file_get_contents(base_path('../contract/telenow-mock/cassettes/previewVoice.telenow.json')), true)['response'];
        $this->assertSame(base64_decode($recorded['bodyBase64']), $clip->getContent());
        [$asked] = $this->sent('POST', '/api/providers/tts/telenow/preview');
        $this->assertSame([self::KEY], $asked->header('X-API-Key'), "the customer's own key: Telenow's spend check is theirs");
        $this->assertEquals(['text' => __('voices.preview_text'), 'voice' => 'vikram-en-in'], json_decode($asked->body(), true));

        $preview = fn (array $body) => $this->actingAs($this->ana)->postJson('/api/kit/console/voices/preview', $body);
        $preview(['provider' => $studio, 'voice' => 'alloy'])->assertStatus(422)->assertJsonPath('errors.voice', [__('voices.unknown_voice')]);
        $preview(['provider' => Catalog::publicKey('tts', 'openai'), 'voice' => 'alloy'])->assertStatus(422)->assertJsonPath('errors.voice', [__('voices.unknown_voice')]);
        $preview(['provider' => $studio, 'voice' => 'vikram-en-in', 'text' => str_repeat('a', config('kit.catalog.preview_text_max') + 1)])->assertStatus(422)->assertJsonValidationErrors(['text']);
        foreach ([['previewVoice.throttled', 429, __('voices.preview_busy')], ['previewVoice.unreachable', 503, __('voices.preview_unavailable')]] as [$name, $status, $words]) {
            $c = json_decode((string) file_get_contents(base_path("../contract/telenow-mock/cassettes/{$name}.json")), true)['response'];
            $this->answering(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/preview') ? Http::response($c['body'], $c['status']) : null);
            $refused = $preview(['provider' => $studio, 'voice' => 'vikram-en-in'])->assertStatus($status)->assertExactJson(['message' => $words]);
            $this->assertStringNotContainsString('openai.com', $refused->getContent());
        }

        Http::swap(new Factory);
        RecordedTelenow::fake();
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $preview(['provider' => $studio, 'voice' => 'vikram-en-in'])->assertStatus(409)->assertExactJson(['message' => __('console.paused.account_suspended')]);
        $this->assertSame([], $this->sent('POST', '/api/providers/tts/telenow/preview'), 'a paused account asks Telenow for nothing paid');
    }

    public function test_an_agents_stack_is_chosen_whole_from_what_is_offered_and_its_own_voice_is_heard(): void
    {
        $this->copied();
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Billing desk']);
        $agent->forceFill(['telenow_id' => self::BILLING_DESK, 'sync_state' => 'synced', 'llm_provider' => 'openai', 'llm_model' => 'gpt-4o-mini',
            'stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy'])->save();
        $key = fn (string $kind, string $id) => Catalog::publicKey($kind, $id);
        $stack = ['llmProvider' => $key('llm', 'anthropic'), 'llmModel' => 'claude-haiku-4-5', 'sttProvider' => $key('stt', 'deepgram'),
            'ttsProvider' => $key('tts', 'telenow'), 'ttsVoice' => 'vikram-en-in'];
        $update = fn (array $body) => $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", $body);

        $update(['ttsVoice' => 'vikram-en-in'])->assertStatus(422)->assertJsonPath('errors', array_fill_keys(['llmProvider', 'sttProvider', 'ttsProvider'], [__('catalog.not_offered')]));
        $update(['llmModel' => 'gpt-4.1-nano', 'llmProvider' => $key('llm', 'openai')] + $stack)->assertStatus(422)->assertJsonPath('errors', ['llmModel' => [__('catalog.not_offered')]]);
        $update(['sttProvider' => $key('stt', 'groq')] + $stack)->assertStatus(422)->assertJsonPath('errors', ['sttProvider' => [__('catalog.not_offered')]]);
        $update(['sttProvider' => 'deepgram'] + $stack)->assertStatus(422)->assertJsonPath('errors', ['sttProvider' => [__('catalog.not_offered')]]);
        $update(['ttsVoice' => 'alloy'] + $stack)->assertStatus(422)->assertJsonPath('errors', ['ttsVoice' => [__('voices.unknown_voice')]]);
        $this->assertSame([], $this->sent('PUT', '/api/agents/'.self::BILLING_DESK), 'nothing sent');

        $saved = $update($stack)->assertOk();
        [$put] = $this->sent('PUT', '/api/agents/'.self::BILLING_DESK);
        $this->assertEquals(['llmProvider' => 'anthropic', 'llmModel' => 'claude-haiku-4-5', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in', 'llmConfig' => [], 'ttsConfig' => []],
            json_decode($put->body(), true), "Telenow's ids, only what changed, and a provider changed with none of the last one's settings");
        $this->assertStringContainsString('"llmConfig":{}', $put->body(), 'an empty object, as Telenow keeps one');
        $saved->assertJsonPath('stack', $stack)->assertJsonPath('syncState', 'synced')
            ->assertJsonPath('stackNames', ['llmProvider' => 'Anthropic', 'llmModel' => 'Fast and thrifty', 'sttProvider' => 'Deepgram', 'ttsProvider' => 'Studio voices', 'ttsVoice' => 'Vikram']);
        $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $saved->getContent());

        $clip = $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/voice-preview")->assertOk();
        $this->assertSame('audio/wav', $clip->headers->get('Content-Type'));
        [$asked] = $this->sent('POST', '/api/agents/'.self::BILLING_DESK.'/voice-preview');
        $this->assertSame([[self::KEY], ['text' => __('voices.preview_text')]], [$asked->header('X-API-Key'), json_decode($asked->body(), true)]);
        $draft = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Draft']);
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$draft->id}/voice-preview")->assertStatus(409)->assertExactJson(['message' => __('agents.not_ready')]);
    }

    public function test_a_new_agent_starts_from_the_stack_it_was_given(): void
    {
        $this->copied();
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents', ['name' => 'Front desk', 'systemPrompt' => 'Be kind.',
            'llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-4o', 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
            'ttsProvider' => Catalog::publicKey('tts', 'elevenlabs'), 'ttsVoice' => RecordedTelenow::body('listProviderVoices.elevenlabs')['data'][0]['id']])->assertCreated();
        [$create] = $this->sent('POST', '/api/agents');
        $body = json_decode($create->body(), true);
        $this->assertSame(['openai', 'gpt-4o', 'deepgram', 'elevenlabs'], [$body['llmProvider'], $body['llmModel'], $body['sttProvider'], $body['ttsProvider']]);
        $this->assertSame([], array_filter($this->sent('GET', '/api/agent-defaults'), fn (Request $r) => $r->header('X-API-Key') === [self::KEY]), 'a whole stack needs no default');
        $this->assertTrue(Str::isUuid(Agent::query()->sole()->id));
    }

    public function test_every_customers_calls_need_consent_only_when_the_operator_says_and_each_change_is_in_the_audit_log(): void
    {
        $this->copied();
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk()->assertJsonPath('consentRequired', false)->assertJsonPath('consentStillToTell', 0);
        $save = fn (array $more = []) => $this->offer($more);
        $save()->assertOk()->assertJsonPath('consentRequired', false);
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'consent.%')->count(), 'not sent, so nothing changed');
        $customer = Workspace::create(['name' => 'Acme', 'status' => 'active', 'sync_state' => 'synced']);
        $customer->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => 'vai_test_acme'])->save();

        $save(['consentRequired' => true])->assertOk()->assertJsonPath('consentRequired', true)->assertJsonPath('consentStillToTell', Workspace::query()->whereNotNull('telenow_key')->whereNotNull('telenow_sub_account_id')->count());
        $this->assertSame(['consent.required_for_all'], AuditEvent::query()->where('action', 'like', 'consent.%')->pluck('action')->all());
        $this->assertSame($this->staff->id, AuditEvent::query()->where('action', 'consent.required_for_all')->value('user_id'));
        $save()->assertOk()->assertJsonPath('consentRequired', true);
        $save(['consentRequired' => true])->assertOk();
        $this->assertSame(1, AuditEvent::query()->where('action', 'like', 'consent.%')->count(), 'the same again is no change');

        $save(['consentRequired' => false])->assertOk()->assertJsonPath('consentRequired', false)->assertJsonPath('consentStillToTell', 0);
        $this->assertSame(['consent.required_for_all', 'consent.left_to_customers'], AuditEvent::query()->where('action', 'like', 'consent.%')->orderBy('id')->pluck('action')->all());
        $save(['consentRequired' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors(['consentRequired']);
    }

    public function test_transfers_stay_off_until_the_operator_allows_them_and_each_change_is_in_the_audit_log(): void
    {
        $this->copied();
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk()->assertJsonPath('transferAllowed', false)->assertJsonPath('transferStillOn', 0);
        $save = fn (array $more = []) => $this->offer($more);
        $save()->assertOk()->assertJsonPath('transferAllowed', false);
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'agents.%')->count(), 'not sent, so nothing changed');

        $save(['transferAllowed' => true])->assertOk()->assertJsonPath('transferAllowed', true);
        $this->assertSame(['agents.transfers_allowed'], AuditEvent::query()->where('action', 'like', 'agents.%')->pluck('action')->all());
        $this->assertSame($this->staff->id, AuditEvent::query()->where('action', 'agents.transfers_allowed')->value('user_id'));
        $save()->assertOk()->assertJsonPath('transferAllowed', true);
        $save(['transferAllowed' => true])->assertOk();
        $this->assertSame(1, AuditEvent::query()->where('action', 'like', 'agents.%')->count(), 'the same again is no change');

        $save(['transferAllowed' => false])->assertOk()->assertJsonPath('transferAllowed', false);
        $this->assertSame(['agents.transfers_allowed', 'agents.transfers_stopped'], AuditEvent::query()->where('action', 'like', 'agents.%')->orderBy('id')->pluck('action')->all());
        $this->assertTrue(app(Settings::class)->has('agents.transfer_sweep_since'));
        $save(['transferAllowed' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors('transferAllowed');
        $this->assertNotSame('audit.actions.agents.transfers_stopped', __('audit.actions.agents.transfers_stopped'));
    }

    public function test_calls_from_the_browser_stay_off_until_the_operator_allows_them_and_each_change_is_in_the_audit_log(): void
    {
        $this->copied();
        $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk()->assertJsonPath('browserCallsAllowed', false)->assertJsonPath('browserCallsUnpriced', []);
        $this->offer()->assertOk()->assertJsonPath('browserCallsAllowed', false);
        $this->offer(['browserCallsAllowed' => true])->assertOk()->assertJsonPath('browserCallsAllowed', true);
        $this->offer(['browserCallsAllowed' => true])->assertOk();
        $this->offer()->assertOk()->assertJsonPath('browserCallsAllowed', true);
        $this->offer(['browserCallsAllowed' => false])->assertOk()->assertJsonPath('browserCallsAllowed', false);
        $this->assertSame(['softphone.allowed', 'softphone.stopped'], AuditEvent::query()->where('action', 'like', 'softphone.%')->orderBy('id')->pluck('action')->all(), 'once each, as it changed');
        $this->assertSame($this->staff->id, AuditEvent::query()->where('action', 'softphone.allowed')->value('user_id'));
        $this->offer(['browserCallsAllowed' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors('browserCallsAllowed');
        $this->assertNotSame('audit.actions.softphone.allowed', __('audit.actions.softphone.allowed'));
        $this->assertNotSame('audit.actions.consent.required_for_all', __('audit.actions.consent.required_for_all'));
    }

    public function test_a_save_the_catalog_refuses_changes_no_switch(): void
    {
        $this->offer(['transferAllowed' => true, 'browserCallsAllowed' => true])->assertStatus(409);
        $this->assertFalse(app(AgentSettings::class)->transferAllowed());
        $this->assertFalse(app(BrowserCalls::class)->allowed());
        $this->assertSame(0, AuditEvent::query()->where('action', 'like', 'agents.%')->count());
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->offer(['transferAllowed' => true, 'defaults' => ['llmModel' => 'gpt-4.1-nano']])->assertStatus(422);
        $this->assertFalse(app(AgentSettings::class)->transferAllowed(), 'nor one it refuses for a bad default');
    }
}
