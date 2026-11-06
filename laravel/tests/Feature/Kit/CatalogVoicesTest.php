<?php

namespace Tests\Feature\Kit;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\ProviderSettings;
use App\Kit\Settings\Settings;
use App\Models\CatalogVoice;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Telenow's full voice lists in the kit (App\Kit\Catalog\CatalogVoices): each provider's whole list is copied, of its
 * default model; customers narrow and page it in the database, and pick or hear any voice of it; only the fields the kit
 * names are kept; a list that cannot be read or says nothing leaves what the kit has, and is tried again at the next run;
 * a provider Telenow no longer offers is emptied. Real recordings (contract_k1voices.py: six real lists, the rest
 * Telenow's hand-picked ones).
 */
class CatalogVoicesTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'vai_test_operator_admin';

    private User $staff;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => 'vai_test_customer_acme'])->save();
        WorkspaceMember::create(['workspace_id' => $acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        RecordedTelenow::fake();
    }

    private function answering(\Closure $first): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $first($r) ?? $recorded($r));
    }

    private static function voicesOf(Request $r): ?string
    {
        return preg_match('#^/api/providers/tts/([^/]+)/voices$#', (string) parse_url($r->url(), PHP_URL_PATH), $m) ? $m[1] : null;
    }

    private function offer(array $tts): TestResponse
    {
        return $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', ['kinds' => ['tts' => $tts], 'defaults' => []]);
    }

    private function voices(string $query = ''): TestResponse
    {
        return $this->actingAs($this->ana)->getJson('/api/kit/console/voices'.$query)->assertOk();
    }

    private static function listed(string $provider): array
    {
        return RecordedTelenow::body("listProviderVoices.{$provider}")['data'];
    }

    public function test_a_whole_list_is_copied_and_paged_by_the_database(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->offer(['cartesia' => ['offered' => true]])->assertOk();
        $list = self::listed('cartesia');
        $key = Catalog::publicKey('tts', 'cartesia');
        $perPage = config('kit.catalog.voices_per_page');

        $first = $this->voices();
        $this->assertSame([count($list), 1, $perPage], [$first->json('total'), $first->json('page'), $first->json('perPage')]);
        $this->assertSame(array_column(array_slice($list, 0, $perPage), 'id'), array_column($first->json('voices'), 'id'), "Telenow's order");
        $lastPage = (int) ceil(count($list) / $perPage);
        $last = $this->voices("?provider={$key}&page={$lastPage}");
        $this->assertSame(array_column(array_slice($list, ($lastPage - 1) * $perPage), 'id'), array_column($last->json('voices'), 'id'));
        $beyond = $this->voices('?page='.PHP_INT_MAX);
        $this->assertSame([[], count($list)], [$beyond->json('voices'), $beyond->json('total')], 'a page past the end is empty, however far');
    }

    public function test_each_provider_is_asked_for_each_model_its_settings_offer_default_first_and_voices_keep_their_models(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $asked = Http::recorded(fn (Request $r) => self::voicesOf($r) !== null)->groupBy(fn (array $p) => self::voicesOf($p[0]))
            ->map(fn ($rs) => $rs->map(fn (array $p) => parse_url($p[0]->url(), PHP_URL_QUERY))->values()->all())->all();
        $catalog = array_column(RecordedTelenow::body('getCatalog.operator')['data']['tts'], 'id');
        $this->assertEqualsCanonicalizing($catalog, array_keys($asked), 'every voice provider in the catalog');
        $this->assertSame(['model=bulbul%3Av3', 'model=bulbul%3Av2'], $asked['sarvam'], 'its default first, then each other model its model setting offers');
        $this->assertSame(['model=telenow-v1', 'model=kokoro'], $asked['telenow'], 'a model the setting offers though the catalog\'s own list has only the default');
        $this->assertSame([null], $asked['hume'], 'one with no choice of model is asked once, for its whole list');
        $this->assertSame(count(self::listed('sarvam')), CatalogVoice::query()->where('provider_id', 'sarvam')->count());

        // A voice tagged for a model is that model's; one tagged for a model not offered stays out; a voice listed twice is one voice.
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'sarvam' ? Http::response(['success' => true, 'source' => 'fallback', 'origin' => 'curated', 'offered' => true, 'totalCount' => 4, 'hasMore' => false, 'page' => 0, 'data' => [
            ['id' => 'anushka', 'name' => 'Anushka', 'model' => 'bulbul:v2'], ['id' => 'shubh', 'name' => 'Shubh', 'model' => 'bulbul:v3'], ['id' => 'shubh', 'name' => 'Shubh again', 'model' => 'bulbul:v3'],
            ['id' => 'odd', 'name' => 'Odd', 'model' => 'bulbul:v9'],
        ]]) : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame(['anushka' => '|bulbul:v2|', 'shubh' => '|bulbul:v3|'], CatalogVoice::query()->where('provider_id', 'sarvam')->orderBy('voice_id')->pluck('models', 'voice_id')->all());
        $this->assertSame('Shubh', CatalogVoice::query()->where('voice_id', 'shubh')->value('name'));

        // A voice naming the models it speaks with is listed only for those.
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'elevenlabs' ? Http::response(['success' => true, 'source' => 'live', 'origin' => 'synced', 'offered' => true, 'totalCount' => 2, 'hasMore' => false, 'page' => 0, 'data' => [
            ['id' => 'all', 'name' => 'All', 'models' => []], ['id' => 'flashy', 'name' => 'Flashy', 'models' => ['eleven_flash_v2_5']],
        ]]) : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $eleven = collect(RecordedTelenow::body('getCatalog.operator')['data']['tts'])->firstWhere('id', 'elevenlabs');
        $models = array_column(collect($eleven['configFields'])->firstWhere('key', 'model')['options'], 'value');
        $this->assertSame(['all' => '|'.implode('|', $models).'|', 'flashy' => '|eleven_flash_v2_5|'], CatalogVoice::query()->where('provider_id', 'elevenlabs')->orderBy('voice_id')->pluck('models', 'voice_id')->all());
        $this->offer(['elevenlabs' => ['offered' => true]])->assertOk();
        $key = Catalog::publicKey('tts', 'elevenlabs');
        $model = fn (string $id) => ProviderSettings::publicValue('model', $id); // customers name a model by the kit's own name, never Telenow's id
        $this->assertSame(['all'], array_column($this->voices("?provider={$key}&model=".$model('eleven_v3'))->json('voices'), 'id'), 'only the chosen model\'s voices');
        $this->assertSame(['all', 'flashy'], array_column($this->voices("?provider={$key}&model=".$model('eleven_flash_v2_5'))->json('voices'), 'id'));
        $this->actingAs($this->ana)->getJson("/api/kit/console/voices?provider={$key}&model=eleven_v3")->assertStatus(422)->assertJsonValidationErrors('model');
        $this->assertSame(2, $this->voices("?provider={$key}")->json('total'), 'no model chosen: every voice');
        CatalogVoice::query()->where('provider_id', 'elevenlabs')->where('voice_id', 'flashy')->update(['models' => '']);
        $this->assertSame(['all', 'flashy'], array_column($this->voices("?provider={$key}&model=".$model('eleven_v3'))->json('voices'), 'id'), 'a voice copied before its models were read speaks with any');

        // The default first, whatever order the model setting gives its options in.
        $catalog = RecordedTelenow::body('getCatalog.operator');
        $catalog['data']['tts'] = array_map(fn (array $p) => $p['id'] !== 'sarvam' ? $p : ['configFields' => array_map(fn (array $f) => $f['key'] === 'model'
            ? ['options' => array_reverse($f['options'])] + $f : $f, $p['configFields'])] + $p, $catalog['data']['tts']);
        $this->answering(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/catalog' ? Http::response($catalog) : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame(['model=bulbul%3Av3', 'model=bulbul%3Av2'], Http::recorded(fn (Request $r) => self::voicesOf($r) === 'sarvam')->map(fn (array $p) => parse_url($p[0]->url(), PHP_URL_QUERY))->values()->all());
    }

    public function test_voices_are_narrowed_by_language_gender_and_words(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->offer(['cartesia' => ['offered' => true], 'google' => ['offered' => true]])->assertOk();
        $all = [...self::listed('cartesia'), ...self::listed('google')];
        $speaks = fn (array $v, string $base) => array_filter([$v['language'] ?? '', ...($v['languages'] ?? [])], fn ($l) => $l === $base || str_starts_with($l, "{$base}-")) !== [];

        $hindi = array_filter($all, fn ($v) => $speaks($v, 'hi'));
        $this->assertGreaterThan(0, count($hindi));
        $this->assertSame(count($hindi), $this->voices('?language=hi')->json('total'));
        $this->assertSame(count($hindi), $this->voices('?language=HI-in')->json('total'), 'its regional tag, in any case');
        $this->assertSame(0, $this->voices('?language=h')->json('total'), 'the whole language, not part of its code');
        $female = array_filter($all, fn ($v) => ($v['gender'] ?? null) === 'female');
        $this->assertSame(count($female), $this->voices('?gender=FEMALE')->json('total'));
        $both = array_filter($hindi, fn ($v) => ($v['gender'] ?? null) === 'female');
        $this->assertSame(count($both), $this->voices('?language=hi&gender=female')->json('total'));

        $word = 'collections, verification'; // in one of Google's descriptions
        $this->assertSame(count(array_filter($all, fn ($v) => str_contains(mb_strtolower($v['description'] ?? ''), $word))), $this->voices('?search='.urlencode(strtoupper($word)))->json('total'));
        $this->assertGreaterThan(0, $this->voices('?search='.urlencode(strtoupper($word)))->json('total'));
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'google' ? Http::response(['success' => true, 'source' => 'live', 'origin' => 'synced', 'offered' => true, 'totalCount' => 1, 'hasMore' => false, 'page' => 0, 'data' => [
            ['id' => 'elodie', 'name' => 'Élodie'],
        ]]) : null);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk();
        $this->assertSame(['elodie'], array_column($this->voices('?search='.urlencode('ÉLODIE'))->json('voices'), 'id'), 'any alphabet, in any case');
        $this->answering(fn (Request $r) => null);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk();
        foreach (['%', '_', '!'] as $wild) {
            $this->assertSame(count(array_filter($all, fn ($v) => str_contains(mb_strtolower(implode(' ', [$v['name'], $v['description'] ?? '', $v['style'] ?? '', ...($v['tags'] ?? [])])), $wild))),
                $this->voices('?search='.urlencode($wild))->json('total'), "{$wild} is itself, not a wildcard");
        }

        $languages = $this->voices()->json('languages');
        $this->assertContains('hi', $languages);
        $this->assertSame($languages, array_values(array_unique($languages)));
        $this->assertSame(['female', 'male'], array_values(array_intersect($this->voices()->json('genders'), ['female', 'male'])));
        // The languages to narrow by are those of the providers asked about, and stay whatever else is narrowed.
        $google = Catalog::publicKey('tts', 'google');
        $this->assertSame([], $this->voices("?provider={$google}")->json('languages'), "Google's voices state no language");
        $this->assertSame($languages, $this->voices('?search=nothing-has-this')->json('languages'));
    }

    public function test_only_the_fields_the_kit_names_are_kept_and_recommended_voices_come_first(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->offer(['elevenlabs' => ['offered' => true], 'google' => ['offered' => true]])->assertOk();
        $answer = $this->voices();
        $voice = $answer->json('voices.0');
        $this->assertSame(['provider', 'providerName', 'id', 'name', 'language', 'gender', 'accent', 'style', 'useCase', 'description', 'tags', 'recommended', 'starred'], array_keys($voice));
        $this->assertNotEmpty(array_filter(array_column(self::listed('elevenlabs'), 'preview_url')), 'the recording has sample addresses');
        foreach (['preview_url', 'storage.googleapis', 'publicOwnerId', 'popularity', 'category', 'models'] as $never) {
            $this->assertStringNotContainsString($never, $answer->content());
        }

        $marks = array_column(array_filter(collect(RecordedTelenow::body('getCatalog.operator')['data']['tts'])->firstWhere('id', 'google')['voices'], fn ($v) => $v['recommended'] ?? false), 'id');
        $this->assertNotEmpty($marks);
        $google = $this->voices('?provider='.Catalog::publicKey('tts', 'google'))->json('voices');
        $this->assertEqualsCanonicalizing($marks, array_column(array_slice($google, 0, count($marks)), 'id'), "Telenow's hand-picked marks, first");
        $this->assertSame(array_fill(0, count($marks), true), array_column(array_slice($google, 0, count($marks)), 'recommended'));
        $this->assertFalse($google[count($marks)]['recommended']);
        $this->assertSame(array_slice(array_values(array_filter(array_column(self::listed('google'), 'id'), fn ($id) => ! in_array($id, $marks, true))), 0, count($google) - count($marks)),
            array_column(array_slice($google, count($marks)), 'id'), "otherwise Telenow's order");
    }

    public function test_a_hand_picked_voice_adds_its_details_but_one_no_longer_listed_is_gone(): void
    {
        $picked = collect(RecordedTelenow::body('getCatalog.operator')['data']['tts'])->firstWhere('id', 'soniox')['voices'];
        $listed = array_column(self::listed('soniox'), 'id');
        $gone = array_values(array_filter($picked, fn ($v) => ! in_array($v['id'], $listed, true)));
        $this->assertNotEmpty($gone, 'the recording has a hand-picked voice its list dropped');
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame(count($listed), CatalogVoice::query()->where('provider_id', 'soniox')->count());
        $this->assertNull(CatalogVoice::query()->where('provider_id', 'soniox')->where('voice_id', $gone[0]['id'])->first());

        // The list says nothing of a voice's use; the hand-picked entry does, in its own spelling. The list's own word wins.
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'telenow' ? Http::response(['success' => true, 'source' => 'live', 'origin' => 'synced', 'offered' => true, 'totalCount' => 2, 'hasMore' => false, 'page' => 0, 'data' => [
            ['id' => 'nandini-hi-in', 'name' => 'Nandini', 'use_case' => 'From the list'], ['id' => 'vikram-en-in', 'name' => 'Vikram now'],
        ]]) : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $vikram = CatalogVoice::query()->where('provider_id', 'telenow')->where('voice_id', 'vikram-en-in')->sole();
        $this->assertSame(['Vikram now', 'collections', true], [$vikram->name, $vikram->use_case, $vikram->recommended]);
        $this->assertStringContainsString('collections', $vikram->search_text);
        $this->assertSame('From the list', CatalogVoice::query()->where('voice_id', 'nandini-hi-in')->sole()->use_case);
        $this->assertSame(['vikram-en-in', 'nandini-hi-in'], CatalogVoice::query()->where('provider_id', 'telenow')->orderBy('position')->pluck('voice_id')->all(), 'a recommended voice comes first wherever the list has it');
    }

    public function test_any_voice_of_the_list_can_be_picked_and_heard_and_a_voice_off_the_list_cannot(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->offer(['cartesia' => ['offered' => true]])->assertOk();
        $deep = self::listed('cartesia')[count(self::listed('cartesia')) - 1];
        $provider = Catalog::publicKey('tts', 'cartesia');

        $this->answering(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/preview') ? Http::response('RIFF', 200, ['Content-Type' => 'audio/wav']) : null);
        $this->actingAs($this->ana)->postJson('/api/kit/console/voices/preview', ['provider' => $provider, 'voice' => $deep['id']])->assertOk();
        $this->assertSame($deep['id'], json_decode(Http::recorded()->last()[0]->body(), true)['voice']);
        $this->actingAs($this->ana)->postJson('/api/kit/console/voices/preview', ['provider' => $provider, 'voice' => 'no-such-voice'])->assertStatus(422)->assertJsonPath('errors.voice', [__('voices.unknown_voice')]);

        $catalog = app(Catalog::class);
        $this->assertSame($deep['name'], $catalog->voice(['id' => 'cartesia'], $deep['id'])?->name);
        $this->assertNull($catalog->voice(['id' => 'google'], $deep['id']), 'another provider\'s voice');
        $this->assertNull($catalog->voice(['id' => 'cartesia'], null));
        $this->assertNull($catalog->voice(['id' => 'cartesia'], [$deep['id']]), 'a voice is one id, not a list holding one');
    }

    public function test_a_list_that_cannot_be_read_stays_as_it_was_and_is_tried_again_at_the_next_run(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        // A provider read for each of its models keeps what it had when one of them cannot be read: never half a list.
        $v2 = fn (Request $r) => self::voicesOf($r) === 'sarvam' && str_contains((string) parse_url($r->url(), PHP_URL_QUERY), 'bulbul%3Av2');
        $this->answering(fn (Request $r) => $v2($r) ? RecordedTelenow::response('listProviderVoices.sarvam-bulbul-v2') : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $sarvam = CatalogVoice::query()->where('provider_id', 'sarvam')->orderBy('voice_id')->pluck('models', 'voice_id')->all();
        $this->assertContains('|bulbul:v2|', $sarvam, 'both models\' voices held');
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'sarvam' && str_contains((string) parse_url($r->url(), PHP_URL_QUERY), 'bulbul%3Av2')
            ? Http::response(['success' => false, 'error' => 'vendor down'], 503) : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame($sarvam, CatalogVoice::query()->where('provider_id', 'sarvam')->orderBy('voice_id')->pluck('models', 'voice_id')->all());

        $this->offer(['cartesia' => ['offered' => true]])->assertOk();
        $before = CatalogVoice::query()->where('provider_id', 'cartesia')->orderBy('position')->pluck('voice_id')->all();

        $this->answering(fn (Request $r) => self::voicesOf($r) === 'cartesia' ? Http::response(['success' => false, 'error' => 'vendor down'], 503) : null);
        $this->travel(config('kit.catalog.refresh_hours'))->hours();
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame($before, CatalogVoice::query()->where('provider_id', 'cartesia')->orderBy('position')->pluck('voice_id')->all());
        $error = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->json('error');
        $this->assertSame(__('catalog.voices_unread', ['names' => 'Cartesia']), $error['message'], 'the operator is told which');

        $reads = fn () => Http::recorded(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/catalog')->count();
        $this->travel(1)->hours();
        $this->answering(fn (Request $r) => null);
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame(1, $reads(), 'tried again though the copy is recent');
        $this->assertNull($this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->json('error'));
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame(1, $reads(), 'whole again: not read until it is old');
    }

    public function test_voices_come_in_the_order_of_their_providers_however_many_are_offered(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $providers = array_column(RecordedTelenow::body('getCatalog.operator')['data']['tts'], 'id');
        $this->assertGreaterThan(10, count($providers), 'enough that sorting their places as text would show');
        $this->offer(array_fill_keys(array_values(array_filter($providers, fn (string $p) => $p !== 'telenow')), ['offered' => true]))->assertOk();
        config(['kit.catalog.voices_per_page' => CatalogVoice::query()->count()]);
        $seen = array_values(array_unique(array_column($this->voices()->json('voices'), 'provider')));
        $withVoices = array_values(array_filter($providers, fn (string $p) => $p !== 'telenow' && CatalogVoice::query()->where('provider_id', $p)->exists()));
        $this->assertSame(array_map(fn (string $p) => Catalog::publicKey('tts', $p), $withVoices), $seen);
    }

    public function test_ids_a_database_takes_for_one_voice_do_not_stop_the_copy(): void
    {
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'sarvam' ? Http::response(['success' => true, 'source' => 'fallback', 'origin' => 'curated', 'offered' => true, 'totalCount' => 2, 'hasMore' => false, 'page' => 0, 'data' => [
            ['id' => 'Shubh', 'name' => 'First'], ['id' => 'shubh', 'name' => 'Second'],
        ]]) : null);
        $this->artisan('kit:catalog')->assertSuccessful(); // MySQL's collation ignores case, so its unique key holds one of them; SQLite holds both
        $this->assertSame('First', CatalogVoice::query()->where('provider_id', 'sarvam')->orderBy('position')->first()->name);
        $this->assertSame(count(self::listed('cartesia')), CatalogVoice::query()->where('provider_id', 'cartesia')->count(), 'and the other providers are copied');
    }

    public function test_a_first_read_that_fails_starts_from_the_hand_picked_voices(): void
    {
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'telenow' ? Http::response(['success' => false, 'error' => 'down'], 503) : null);
        $this->artisan('kit:catalog')->assertSuccessful();
        $picked = collect(RecordedTelenow::body('getCatalog.operator')['data']['tts'])->firstWhere('id', 'telenow')['voices'];
        $this->assertSame(count($picked), CatalogVoice::query()->where('provider_id', 'telenow')->count(), 'every model\'s, each kept with its model');
        $this->assertSame(['|telenow-v1|', '|kokoro|'], [CatalogVoice::query()->where('voice_id', 'vikram-en-in')->value('models'),
            CatalogVoice::query()->where('voice_id', collect($picked)->firstWhere('model', 'kokoro')['id'])->value('models')]);
        $this->assertSame('collections', CatalogVoice::query()->where('voice_id', 'vikram-en-in')->sole()->use_case);
    }

    public function test_a_list_that_says_nothing_leaves_what_is_there_and_only_not_offered_empties_a_provider(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $this->assertSame(0, CatalogVoice::query()->where('provider_id', 'customtts')->count(), 'a provider with no list at all');
        $count = CatalogVoice::query()->where('provider_id', 'cartesia')->count();
        $this->assertSame(count(self::listed('cartesia')), $count);

        $none = RecordedTelenow::body('listProviderVoices.customtts');
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'cartesia' ? Http::response($none) : null);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk()->assertJsonPath('error', null);
        $this->assertSame($count, CatalogVoice::query()->where('provider_id', 'cartesia')->count(), '"none": told nothing');

        $off = RecordedTelenow::body('listProviderVoices.off');
        $this->answering(fn (Request $r) => self::voicesOf($r) === 'cartesia' ? Http::response($off) : null);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk();
        $this->assertSame(0, CatalogVoice::query()->where('provider_id', 'cartesia')->count(), 'offered false');
    }

    public function test_a_provider_gone_from_the_catalog_loses_its_voices_and_one_switched_off_is_not_asked(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $catalog = RecordedTelenow::body('getCatalog.operator');
        $catalog['data']['tts'] = array_values(array_filter($catalog['data']['tts'], fn ($p) => $p['id'] !== 'google'));
        foreach ($catalog['data']['tts'] as &$p) {
            if ($p['id'] === 'xai') {
                $p['enabled'] = false;
            }
        }
        unset($p);
        $count = CatalogVoice::query()->where('provider_id', 'xai')->count();
        $this->answering(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/catalog' ? Http::response($catalog) : null);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/catalog/refresh')->assertOk();
        $this->assertSame(0, CatalogVoice::query()->where('provider_id', 'google')->count());
        $this->assertSame($count, CatalogVoice::query()->where('provider_id', 'xai')->count());
        $this->assertSame(0, Http::recorded(fn (Request $r) => self::voicesOf($r) === 'xai')->count());
    }

    public function test_the_operator_sees_how_many_and_chooses_the_default_among_any_providers_voices(): void
    {
        $this->artisan('kit:catalog')->assertSuccessful();
        $deep = self::listed('cartesia')[900];
        $page = $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog')->assertOk();
        $tts = collect($page->json('kinds.tts'))->keyBy('id');
        $this->assertSame([count(self::listed('cartesia')), 0], [$tts['cartesia']['voiceCount'], $tts['customtts']['voiceCount']]);
        $this->assertArrayNotHasKey('voices', $tts['cartesia'], 'the lists are too long to send with the page');
        $this->assertSame(0, collect($page->json('kinds.llm'))->firstWhere('id', 'openai')['voiceCount']);

        $voices = fn (string $q) => $this->actingAs($this->staff)->getJson('/api/kit/admin/catalog/voices?'.$q);
        $this->assertSame(count(self::listed('cartesia')), $voices('provider=cartesia')->assertOk()->json('total'), 'offered yet or not');
        $this->assertContains($deep['id'], array_column($voices('provider=cartesia&search='.urlencode($deep['name']))->json('voices'), 'id'));
        $this->assertSame(0, $voices('provider=nosuch')->assertOk()->json('total'));
        $voices('')->assertStatus(422);
        $this->actingAs($this->ana)->getJson('/api/kit/admin/catalog/voices?provider=cartesia')->assertForbidden();

        $saved = $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', ['kinds' => ['tts' => ['cartesia' => ['offered' => true]]], 'defaults' => ['ttsProvider' => 'cartesia', 'ttsVoice' => $deep['id']]])->assertOk();
        $this->assertSame($deep['name'], $saved->json('voiceNames')[$deep['id']]);
        $this->actingAs($this->staff)->putJson('/api/kit/admin/catalog', ['kinds' => ['tts' => ['cartesia' => ['offered' => true]]], 'defaults' => ['ttsProvider' => 'cartesia', 'ttsVoice' => 'no-such-voice']])
            ->assertStatus(422)->assertJsonPath('errors', ['defaults.ttsVoice' => [__('catalog.default_not_offered')]]);
    }
}
