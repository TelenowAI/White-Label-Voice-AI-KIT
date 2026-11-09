<?php

namespace Tests\Feature\Kit;

use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Models the operator adds to OpenRouter beyond the catalog's (App\Kit\Catalog\OpenModels, Catalog `extraModels`; contract/kit-api.yaml
 * listOpenModels, getOpenModelPrice, saveCatalogCuration): OpenRouter's own list as Telenow reads it, searched a page at a time and kept a while;
 * Telenow's price to the operator for one (never a customer's to see); only a model of that list may be added, never one the catalog has, at most
 * the configured count; customers pick an added model as any other, and the agent carries OpenRouter's id at Telenow. Telenow answers as recorded
 * on its live release (contract_k31openrouter.py: 461 models, all text to text; createAgent took even an id OpenRouter has not got).
 */
class OpenModelsTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'vai_test_operator_admin';

    private const KEY = 'vai_test_customer_acme';

    private User $owner;

    private User $support;

    private User $ana;

    private Workspace $acme;

    /** @var array<string, list<string|\Closure>> answers queued by path */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->support = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'a long password 1', 'role' => 'support']);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(function (Request $r) use ($recorded) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $answer = ($this->answers[$path] ?? []) !== [] ? array_shift($this->answers[$path]) : match ($path) {
                '/api/catalog/openrouter-models' => 'listOpenRouterModels.operator',
                '/api/catalog/openrouter-price' => 'getOpenRouterPrice.found',
                default => null,
            };
            if ($answer === null) {
                return $recorded($r);
            }

            return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
        });
        $this->artisan('kit:catalog')->assertSuccessful();
    }

    /** @return list<Request> */
    private function sent(string $path): array
    {
        return Http::recorded(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === $path)->map(fn (array $p) => $p[0])->values()->all();
    }

    /** @return array<string, string> OpenRouter's models as recorded, name by id */
    private static function listed(): array
    {
        $out = [];
        foreach (RecordedTelenow::body('listOpenRouterModels.operator')['data'] as $m) {
            $out[$m['id']] = $m['name'];
        }

        return $out;
    }

    /** The operator's save: OpenRouter offered (with what is sent), and the rest as CatalogTest offers it. */
    private function save(array $openrouter, array $more = []): TestResponse
    {
        return $this->actingAs($this->owner)->putJson('/api/kit/admin/catalog', array_replace([
            'kinds' => ['llm' => ['openrouter' => ['offered' => true] + $openrouter, 'openai' => ['offered' => true]], 'stt' => ['deepgram' => ['offered' => true]],
                'tts' => ['telenow' => ['offered' => true, 'name' => 'Studio voices']]],
            'defaults' => ['llmProvider' => 'openai', 'llmModel' => 'gpt-4o-mini', 'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in'],
        ], $more));
    }

    /** @return array the operator's page's provider of that id */
    private function provider(TestResponse $page, string $id): array
    {
        return collect($page->json('kinds.llm'))->firstWhere('id', $id);
    }

    public function test_the_operator_reads_openrouters_list_a_page_at_a_time_searched_and_kept_a_while(): void
    {
        config(['kit.catalog.open_models.per_page' => 5]);
        $listed = self::listed();
        $page = $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertOk();
        $first = array_slice($listed, 0, 5, true);
        $page->assertExactJson(['provider' => 'openrouter', 'page' => 1, 'perPage' => 5, 'total' => count($listed), 'max' => config('kit.catalog.open_models.models_max'),
            'models' => array_map(fn (string $id) => ['id' => $id, 'name' => $listed[$id], 'added' => false, 'inCatalog' => false], array_keys($first))]);
        [$read] = $this->sent('/api/catalog/openrouter-models');
        $this->assertSame([self::OPERATOR], $read->header('X-API-Key'), "the operator's key");

        $search = 'GPT-4O-MINI';
        $matching = array_filter($listed, fn (string $name, string $id) => stripos($id, $search) !== false || stripos($name, $search) !== false, ARRAY_FILTER_USE_BOTH);
        $this->assertGreaterThan(1, count($matching), 'the recording has more than one');
        $found = $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models?search='.urlencode(strtolower($search)))->assertOk();
        $found->assertJsonPath('total', count($matching));
        $this->assertSame(array_slice(array_keys($matching), 0, 5), array_column($found->json('models'), 'id'), 'by id or name, any case');
        $mini = collect($found->json('models'))->firstWhere('id', 'openai/gpt-4o-mini');
        $this->assertSame([true, false], [$mini['inCatalog'], $mini['added']], 'the catalog has it: in the catalog, not added');
        $word = 'liquidai';
        $byName = array_filter($listed, fn (string $name, string $id) => stripos($name, $word) !== false, ARRAY_FILTER_USE_BOTH);
        $this->assertNotSame([], $byName);
        $this->assertSame([], array_filter(array_keys($byName), fn (string $id) => stripos($id, $word) !== false), 'a word in names only');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models?search='.$word)->assertOk()->assertJsonPath('total', count($byName));

        $second = $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models?page=2')->assertOk();
        $this->assertSame(array_slice(array_keys($listed), 5, 5), array_column($second->json('models'), 'id'));
        $this->assertCount(1, $this->sent('/api/catalog/openrouter-models'), 'read once, then kept');
        $this->travel(config('kit.catalog.open_models.list_minutes') + 1)->minutes();
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertOk();
        $this->assertCount(2, $this->sent('/api/catalog/openrouter-models'), 'read again once old');
    }

    public function test_an_unreadable_list_is_refused_and_never_kept_nor_is_the_page_for_anyone_but_the_operators_settings_staff(): void
    {
        $this->answers['/api/catalog/openrouter-models'][] = 'listOpenRouterModels.no-key';
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertStatus(502)->assertExactJson(['message' => __('catalog.open_models_unread')]);
        $this->answers['/api/catalog/openrouter-models'][] = fn () => Http::response(['success' => true, 'data' => []]);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertStatus(502)->assertExactJson(['message' => __('catalog.open_models_unread')]);
        $this->assertFalse(Cache::has('kit.catalog.open-models'), 'an empty list (OpenRouter out of reach) is never kept');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertOk();
        Cache::flush();
        $this->answers['/api/catalog/openrouter-models'][] = fn () => Http::response(['success' => true, 'data' => [
            ['id' => 'acme/talker', 'name' => 'Acme: Talker', 'textToText' => true], ['id' => 'acme/painter', 'name' => 'Acme: Painter', 'textToText' => false],
            ['id' => 'no shape', 'name' => 'Bad', 'textToText' => true], 'not a model', ['id' => 'acme/unnamed', 'textToText' => true], ['name' => 'No id', 'textToText' => true]]]);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('models', [['id' => 'acme/talker', 'name' => 'Acme: Talker', 'added' => false, 'inCatalog' => false],
                ['id' => 'acme/unnamed', 'name' => 'acme/unnamed', 'added' => false, 'inCatalog' => false]]);

        $this->actingAs($this->support)->getJson('/api/kit/admin/catalog/open-models')->assertForbidden();
        $this->actingAs($this->support)->getJson('/api/kit/admin/catalog/open-models/price?model=openai/gpt-4o-mini')->assertForbidden();
        $this->actingAs($this->ana)->getJson('/api/kit/admin/catalog/open-models')->assertForbidden();
        app(Settings::class)->put('telenow.api_key', null);
        Cache::flush();
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertStatus(409)->assertExactJson(['message' => __('catalog.no_key')]);
    }

    public function test_the_price_is_telenows_to_the_operator_per_million_tokens_and_none_for_a_model_it_has_not_priced(): void
    {
        $found = RecordedTelenow::body('getOpenRouterPrice.found')['data'];
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models/price?model=openai/gpt-4o-mini')->assertOk()
            ->assertExactJson(['model' => 'openai/gpt-4o-mini', 'found' => true, 'inputPerM' => $found['inputPerM'], 'outputPerM' => $found['outputPerM']]);
        [$asked] = $this->sent('/api/catalog/openrouter-price');
        $this->assertSame(['model=openai%2Fgpt-4o-mini', [self::OPERATOR]], [parse_url($asked->url(), PHP_URL_QUERY), $asked->header('X-API-Key')]);
        $this->answers['/api/catalog/openrouter-price'][] = 'getOpenRouterPrice.unknown';
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models/price?model=nobody/no-such-model')->assertOk()
            ->assertExactJson(['model' => 'nobody/no-such-model', 'found' => false, 'inputPerM' => null, 'outputPerM' => null]);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models/price?model=no%20slash')->assertStatus(422)->assertJsonValidationErrors('model');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models/price')->assertStatus(422)->assertJsonValidationErrors('model');
        $this->assertCount(2, $this->sent('/api/catalog/openrouter-price'), 'a model of no shape is never asked about');
        $this->answers['/api/catalog/openrouter-price'][] = fn () => throw new ConnectionException('down');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models/price?model=openai/gpt-4o-mini')->assertStatus(502)
            ->assertExactJson(['message' => __('catalog.open_model_price_unread')]);
    }

    public function test_the_operator_adds_models_of_the_list_and_customers_pick_them_as_the_catalogs(): void
    {
        $listed = self::listed();
        $page = $this->save(['models' => ['meta-llama/llama-3.3-70b-instruct' => ['offered' => false]], 'extraModels' => [
            'liquid/lfm-3-1.7b' => ['name' => 'Quick and small'], 'google/gemini-omni-1.2-flash' => [], '~openai/gpt-luna-latest' => ['offered' => false]]])->assertOk();
        $openrouter = $this->provider($page, 'openrouter');
        $this->assertTrue($openrouter['openModels']);
        $this->assertFalse($this->provider($page, 'openai')['openModels'], 'only the provider whose whole list is read');
        $this->assertSame([
            ['openai/gpt-4o-mini', false, true], ['anthropic/claude-haiku-4.5', false, true], ['meta-llama/llama-3.3-70b-instruct', false, false],
            ['liquid/lfm-3-1.7b', true, true], ['google/gemini-omni-1.2-flash', true, true], ['~openai/gpt-luna-latest', true, false],
        ], array_map(fn (array $m) => [$m['id'], $m['added'], $m['offered']], $openrouter['models']), "the catalog's first, then those added in the order sent");
        $liquid = collect($openrouter['models'])->firstWhere('id', 'liquid/lfm-3-1.7b');
        $this->assertSame([$listed['liquid/lfm-3-1.7b'], 'Quick and small', 'Quick and small'], [$liquid['catalogName'], $liquid['name'], $liquid['ownName']]);
        $this->assertSame($listed['google/gemini-omni-1.2-flash'], collect($openrouter['models'])->firstWhere('id', 'google/gemini-omni-1.2-flash')['name'], "OpenRouter's name until one is given");

        $listing = $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models?search=lfm-3-1.7b')->assertOk();
        $this->assertSame([['liquid/lfm-3-1.7b', true, false], ['liquid/lfm-3-1.7b:free', false, false]],
            array_map(fn (array $m) => [$m['id'], $m['added'], $m['inCatalog']], $listing->json('models')), 'added, and not in the catalog');

        $choices = $this->actingAs($this->ana)->getJson('/api/kit/console/catalog')->assertOk();
        $catalogs = array_column(collect(RecordedTelenow::body('getCatalog.operator')['data']['llm'])->firstWhere('id', 'openrouter')['models'], 'name', 'id');
        $this->assertSame([['openai/gpt-4o-mini', $catalogs['openai/gpt-4o-mini']], ['anthropic/claude-haiku-4.5', $catalogs['anthropic/claude-haiku-4.5']],
            ['liquid/lfm-3-1.7b', 'Quick and small'], ['google/gemini-omni-1.2-flash', $listed['google/gemini-omni-1.2-flash']]],
            array_map(fn (array $m) => [$m['id'], $m['name']], collect($choices->json('llm'))->firstWhere('key', Catalog::publicKey('llm', 'openrouter'))['models']),
            "only those offered, the catalog's first");

        $stack = ['llmProvider' => Catalog::publicKey('llm', 'openrouter'), 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
            'ttsProvider' => Catalog::publicKey('tts', 'telenow'), 'ttsVoice' => 'vikram-en-in'];
        $make = fn (string $model) => $this->actingAs($this->ana)->postJson('/api/kit/console/agents', ['name' => 'Front desk', 'systemPrompt' => 'Be kind.', 'llmModel' => $model] + $stack);
        $make('~openai/gpt-luna-latest')->assertStatus(422)->assertJsonPath('errors', ['llmModel' => [__('catalog.not_offered')]]);
        $make('liquid/lfm-3-1.7b:free')->assertStatus(422)->assertJsonPath('errors', ['llmModel' => [__('catalog.not_offered')]]);
        $this->assertSame([], $this->sent('/api/agents'), 'nothing sent for a model not added or switched off');
        $make('liquid/lfm-3-1.7b')->assertCreated();
        [$create] = $this->sent('/api/agents');
        $body = json_decode($create->body(), true);
        $this->assertSame(['openrouter', 'liquid/lfm-3-1.7b', [self::KEY]], [$body['llmProvider'], $body['llmModel'], $create->header('X-API-Key')]);
        // An agent on an added model, as Telenow keeps one (it answers the id it was given, recorded: createAgent.openrouter-unknown-model).
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Quick desk']);
        $agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.openrouter')['data']['id'], 'sync_state' => 'synced', 'llm_provider' => 'openrouter',
            'llm_model' => 'liquid/lfm-3-1.7b', 'stt_provider' => 'deepgram', 'tts_provider' => 'telenow', 'tts_voice' => 'vikram-en-in'])->save();
        $shown = $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->assertOk()
            ->assertJsonPath('stack.llmModel', 'liquid/lfm-3-1.7b')->assertJsonPath('stackNames.llmModel', 'Quick and small');
        $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $choices->getContent().$shown->getContent());

        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => ['name' => 'Quick and small']]], ['defaults' => ['llmProvider' => 'openrouter', 'llmModel' => 'liquid/lfm-3-1.7b',
            'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in']])->assertOk()
            ->assertJsonPath('defaults.llmModel', 'liquid/lfm-3-1.7b');
        $this->assertSame(['liquid/lfm-3-1.7b'], array_column(array_filter($this->provider($this->actingAs($this->owner)->getJson('/api/kit/admin/catalog'), 'openrouter')['models'],
            fn (array $m) => $m['added']), 'id'), 'those sent are the added ones now: an added model may be a default');
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => ['offered' => false]]])->assertOk();
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->assertOk()->assertJsonPath('stackNames.llmModel', null);
    }

    public function test_only_a_model_of_the_list_is_added_never_one_the_catalog_has_and_a_refusal_saves_nothing(): void
    {
        config(['kit.catalog.open_models.models_max' => 2]);
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => []]])->assertOk();
        $at = 'kinds.llm.openrouter.extraModels';
        $refused = fn (array $extra, array $errors, array $openai = []) => $this->actingAs($this->owner)->putJson('/api/kit/admin/catalog', [
            'kinds' => ['llm' => ['openrouter' => ['offered' => true, 'extraModels' => $extra], 'openai' => ['offered' => true] + $openai]],
            'defaults' => ['llmProvider' => 'openai', 'llmModel' => 'gpt-4o-mini', 'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in'],
        ])->assertStatus(422)->assertJsonPath('errors', $errors);
        $refused(['nobody/no-such-model' => []], ["{$at}.nobody/no-such-model" => [__('catalog.open_model_unknown')]]);
        Cache::flush();
        $refused(['no shape at all' => []], ["{$at}.no shape at all" => [__('catalog.open_model_unknown')]]);
        $this->assertCount(1, $this->sent('/api/catalog/openrouter-models'), 'an id of no model shape is refused without reading the list');
        $refused(['openai/gpt-4o-mini' => []], ["{$at}.openai/gpt-4o-mini" => [__('catalog.open_model_in_catalog')]]);
        $refused(['liquid/lfm-3-1.7b' => 'yes'], ["{$at}.liquid/lfm-3-1.7b" => [__('catalog.open_model_unknown')]]);
        $refused(['liquid/lfm-3-1.7b'], [$at => [__('catalog.open_models_not_here')]]);
        $refused(['liquid/lfm-3-1.7b' => [], 'liquid/lfm-3-1.7b:free' => [], 'google/gemini-omni-1.2-flash' => []], [$at => [__('catalog.open_models_max', ['max' => 2])]]);
        $refused(['liquid/lfm-3-1.7b' => ['name' => str_repeat('a', config('kit.agents.name_max_length') + 1)]],
            ["{$at}.liquid/lfm-3-1.7b.name" => [__('catalog.name_max', ['max' => config('kit.agents.name_max_length')])]]);
        $refused(['liquid/lfm-3-1.7b' => ['name' => 'Fast on '.config('kit.telenow.name')]],
            ["{$at}.liquid/lfm-3-1.7b.name" => [__('catalog.names_platform', ['platform' => config('kit.telenow.name')])]]);
        $refused([], ['kinds.llm.openai.extraModels' => [__('catalog.open_models_not_here')]], ['extraModels' => ['liquid/lfm-3-1.7b' => []]]);
        $this->assertSame(['liquid/lfm-3-1.7b'], array_keys(app(Catalog::class)->curation()['llm']['openrouter']['extraModels']), 'every refusal saved nothing');
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => [], 'liquid/lfm-3-1.7b:free' => []]])->assertOk(); // exactly the most

        $this->actingAs($this->owner)->putJson('/api/kit/admin/catalog', [
            'kinds' => ['llm' => ['openrouter' => ['offered' => false, 'extraModels' => ['liquid/lfm-3-1.7b' => ['name' => 'Fast on '.config('kit.telenow.name')]]]]],
            'defaults' => ['llmProvider' => 'openai', 'llmModel' => 'gpt-4o-mini', 'sttProvider' => 'deepgram', 'ttsProvider' => 'telenow', 'ttsVoice' => 'vikram-en-in'],
        ])->assertOk(); // a name of a provider not offered reaches no customer, as with the catalog's models
    }

    public function test_a_model_added_before_stays_when_the_list_cannot_be_read_and_one_not_sent_is_kept(): void
    {
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => ['name' => 'Quick and small']]])->assertOk();
        Cache::flush();
        $renamed = RecordedTelenow::body('listOpenRouterModels.operator');
        $renamed['data'] = array_map(fn (array $m) => ['name' => 'Renamed since'] + $m, $renamed['data']);
        $this->answers['/api/catalog/openrouter-models'] = [fn () => Http::response($renamed), fn () => Http::response(['success' => true, 'data' => []])];
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => ['name' => 'Quick']], 'models' => []])->assertOk();
        $this->assertCount(1, $this->sent('/api/catalog/openrouter-models'), 'the list is read for no model added before');
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => ['name' => 'Quick'], 'acme/not-listed' => []]])->assertStatus(422)
            ->assertJsonPath('errors', ['kinds.llm.openrouter.extraModels.acme/not-listed' => [__('catalog.open_model_unknown')]]);
        Cache::flush();
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => [], 'google/gemini-omni-1.2-flash' => []]])->assertStatus(422)
            ->assertJsonPath('errors', ['kinds.llm.openrouter.extraModels.google/gemini-omni-1.2-flash' => [__('catalog.open_models_unread')]]);
        $this->save([])->assertOk();
        $this->save(['offered' => false])->assertOk();
        $openrouter = $this->provider($this->actingAs($this->owner)->getJson('/api/kit/admin/catalog'), 'openrouter');
        $this->assertSame([[self::listed()['liquid/lfm-3-1.7b'], 'Quick']], array_map(fn (array $m) => [$m['catalogName'], $m['ownName']], array_values(array_filter($openrouter['models'], fn (array $m) => $m['added']))),
            "kept under OpenRouter's name of when it was added; none sent, those added stay");
        $this->save(['extraModels' => []])->assertOk();
        $this->assertSame([], array_values(array_filter($this->provider($this->actingAs($this->owner)->getJson('/api/kit/admin/catalog'), 'openrouter')['models'], fn (array $m) => $m['added'])), 'none sent as none');
    }

    public function test_a_model_added_that_the_catalog_takes_in_later_is_listed_once_as_the_catalogs(): void
    {
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => ['name' => 'Quick and small']]])->assertOk();
        $settings = app(Settings::class);
        $copy = $settings->get(Catalog::COPY);
        foreach ($copy['kinds']['llm'] as $i => $p) {
            if ($p['id'] === 'openrouter') {
                $copy['kinds']['llm'][$i]['models'][] = ['id' => 'liquid/lfm-3-1.7b', 'name' => 'Liquid, in the catalog now'];
            }
        }
        $settings->put(Catalog::COPY, $copy);
        $models = $this->provider($this->actingAs($this->owner)->getJson('/api/kit/admin/catalog'), 'openrouter')['models'];
        $this->assertSame([['liquid/lfm-3-1.7b', 'Liquid, in the catalog now', false]], array_map(fn (array $m) => [$m['id'], $m['catalogName'], $m['added']],
            array_values(array_filter($models, fn (array $m) => $m['id'] === 'liquid/lfm-3-1.7b'))));
    }

    public function test_with_no_open_model_provider_nothing_can_be_added(): void
    {
        config(['kit.catalog.open_models.provider' => '']);
        $page = $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog')->assertOk();
        $this->assertSame([], array_filter(array_column($page->json('kinds.llm'), 'openModels')));
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models')->assertNotFound()->assertExactJson(['message' => __('catalog.open_models_not_here')]);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/catalog/open-models/price?model=openai/gpt-4o-mini')->assertNotFound();
        $this->save(['extraModels' => ['liquid/lfm-3-1.7b' => []]])->assertStatus(422)
            ->assertJsonPath('errors', ['kinds.llm.openrouter.extraModels' => [__('catalog.open_models_not_here')]]);
        $this->assertSame([], $this->sent('/api/catalog/openrouter-models'));
    }
}
