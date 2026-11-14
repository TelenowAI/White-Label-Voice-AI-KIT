<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * The processing country (App\Kit\Catalog\ProcessingCountry; contract/kit-api.yaml getPlatformProcessingCountry, getCustomerProcessingCountry):
 * the operator's account's and a customer's (read with the customer's own key), in the kit's words for where it comes from, kept a while and read
 * afresh when asked; Admin only. Telenow answers as recorded on its live release (contract_k31openrouter.py: the operator's country changed by SQL,
 * a sub-account made before it keeping the old one, one made after taking the new).
 */
class ProcessingCountryTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'vai_test_operator_admin';

    private const KEY = 'vai_test_customer_acme';

    private User $support;

    private Workspace $acme;

    /** @var list<string|\Closure> answers in turn */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        $this->support = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'a long password 1', 'role' => 'support']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $this->assertSame('/api/catalog/processing-country', parse_url($r->url(), PHP_URL_PATH));
            $answer = array_shift($this->answers) ?? throw new \RuntimeException('nothing queued');

            return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
        });
    }

    /** @return list<?string> the key each read was made with */
    private function keys(): array
    {
        return Http::recorded()->map(fn (array $p) => $p[0]->header('X-API-Key')[0] ?? null)->values()->all();
    }

    public function test_the_operators_is_read_with_its_key_kept_a_while_and_read_afresh_when_asked(): void
    {
        $this->freezeSecond(); // checkedAt is compared with now(): a running clock can tick between the two
        $this->answers = ['getProcessingCountry.operator', 'getProcessingCountry.operator-changed'];
        $first = $this->actingAs($this->support)->getJson('/api/kit/admin/telenow/processing-country')->assertOk()
            ->assertJson(['country' => 'IN', 'source' => 'country', 'checkedAt' => now()->toIso8601String()]);
        $this->assertSame(['country', 'source', 'checkedAt'], array_keys($first->json()));
        $this->travel(config('kit.catalog.processing_country_minutes') - 1)->minutes();
        $this->actingAs($this->support)->getJson('/api/kit/admin/telenow/processing-country')->assertOk()->assertExactJson($first->json());
        $this->assertSame([self::OPERATOR], $this->keys(), 'kept');
        $this->actingAs($this->support)->getJson('/api/kit/admin/telenow/processing-country?fresh=1')->assertOk()
            ->assertExactJson(['country' => 'DE', 'source' => 'country', 'checkedAt' => now()->toIso8601String()]);
        $this->answers = ['getProcessingCountry.operator'];
        $this->travel(config('kit.catalog.processing_country_minutes') + 1)->minutes();
        $this->actingAs($this->support)->getJson('/api/kit/admin/telenow/processing-country')->assertOk()->assertJsonPath('country', 'IN');
        $this->assertSame([self::OPERATOR, self::OPERATOR, self::OPERATOR], $this->keys(), 'read again once old');
    }

    public function test_a_customers_is_read_with_their_own_key_and_kept_apart_from_the_operators(): void
    {
        $this->freezeSecond(); // checkedAt is compared with now(): a running clock can tick between the two
        $this->answers = ['getProcessingCountry.operator-changed', 'getProcessingCountry.older-sub-account'];
        $this->actingAs($this->support)->getJson('/api/kit/admin/telenow/processing-country')->assertOk()->assertJsonPath('country', 'DE');
        $this->actingAs($this->support)->getJson("/api/kit/admin/customers/{$this->acme->id}/processing-country")->assertOk()
            ->assertExactJson(['country' => 'IN', 'source' => 'country', 'checkedAt' => now()->toIso8601String()]);
        $this->actingAs($this->support)->getJson("/api/kit/admin/customers/{$this->acme->id}/processing-country")->assertOk()->assertJsonPath('country', 'IN');
        $this->assertSame([self::OPERATOR, self::KEY], $this->keys(), "made before the operator's changed, it keeps its own; each kept apart");
        $this->answers = ['getProcessingCountry.newer-sub-account'];
        $this->actingAs($this->support)->getJson("/api/kit/admin/customers/{$this->acme->id}/processing-country?fresh=true")->assertOk()->assertJsonPath('country', 'DE');

        $this->actingAs($this->support)->getJson('/api/kit/admin/customers/'.Str::uuid().'/processing-country')->assertNotFound();
        $this->actingAs($this->support)->getJson('/api/kit/admin/customers/not-an-id/processing-country')->assertNotFound();
        $pending = Workspace::create(['name' => 'Bright Smiles', 'status' => 'active', 'sync_state' => 'pending']);
        $this->actingAs($this->support)->getJson("/api/kit/admin/customers/{$pending->id}/processing-country")->assertStatus(409)
            ->assertExactJson(['message' => __('catalog.processing_country_no_account')]);
        $this->assertCount(3, $this->keys());
    }

    public function test_where_it_comes_from_is_in_the_kits_words_and_a_failed_read_is_never_kept(): void
    {
        $answer = fn (mixed $country, mixed $source) => fn () => Http::response(['success' => true, 'data' => ['country' => $country, 'source' => $source]]);
        $read = fn () => $this->actingAs($this->support)->getJson('/api/kit/admin/telenow/processing-country?fresh=1');
        foreach ([['IN', 'OrgTimezone', 'IN', 'time_zone'], ['IN', 'Explicit', 'IN', 'unknown'], [null, 'Unknown', null, 'unknown'], ['in', 'OrgCountry', null, 'unknown'],
            ['IND', 'OrgCountry', null, 'unknown'], ['DE', ['OrgCountry'], 'DE', 'unknown'], ['DE', null, 'DE', 'unknown']] as [$country, $source, $shown, $said]) {
            $this->answers = [$answer($country, $source)];
            $read()->assertOk()->assertJson(['country' => $shown, 'source' => $said]);
        }

        Cache::flush();
        $kept = fn () => $this->actingAs($this->support)->getJson('/api/kit/admin/telenow/processing-country');
        $this->answers = [fn () => throw new ConnectionException('down'), 'getProcessingCountry.no-key', 'getProcessingCountry.operator-changed', fn () => throw new ConnectionException('down')];
        $kept()->assertStatus(502)->assertExactJson(['message' => __('catalog.processing_country_unread')]);
        $kept()->assertStatus(502);
        $kept()->assertOk()->assertJsonPath('country', 'DE');
        $read()->assertStatus(502);
        $kept()->assertOk()->assertJsonPath('country', 'DE');
        $this->assertSame([], $this->answers, 'a failed read is kept by no one: the one before stays');

        app(Settings::class)->put('telenow.api_key', null);
        $read()->assertStatus(409)->assertExactJson(['message' => __('catalog.no_key')]);
        $customer = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->actingAs($customer)->getJson('/api/kit/admin/telenow/processing-country')->assertForbidden();
        $this->actingAs($customer)->getJson("/api/kit/admin/customers/{$this->acme->id}/processing-country")->assertForbidden();
    }
}
