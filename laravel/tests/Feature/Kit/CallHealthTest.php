<?php

namespace Tests\Feature\Kit;

use App\Kit\Calls\CallHealth;
use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\Call;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Call health (App\Kit\Calls\CallHealth; contract/kit-api.yaml getCallHealth, getCustomerCallHealth, getVoicePlatformHealth's `callHealth`, the
 * dashboard's `providersFailing`): the providers that failed on an account's recent calls, read with the account's own key — customers under the
 * operator's names with the kit's own words and their own agents, never the raw error or the platform's ids; staff in detail; every account with
 * calls added up by `kit:call-health`. Telenow answers as recorded on its live release (contract_k32helpers.py: failures written by SQL as the call
 * engine writes them).
 */
class CallHealthTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const HEALTH = '/api/agents/provider-health';

    private User $owner;

    private User $ana;

    private Workspace $acme;

    /** @var array<string, list<string|\Closure>> answers by the key that asks */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $settings->put(Catalog::COPY, ['fetchedAt' => now()->toIso8601String(), 'recommended' => [], 'kinds' => [
            'llm' => [['id' => 'openai', 'name' => 'OpenAI', 'models' => []]],
            'stt' => [['id' => 'deepgram', 'name' => 'Deepgram']],
            'tts' => [['id' => 'telenow', 'name' => config('kit.telenow.name').' voices']],
        ]]);
        $settings->put(Catalog::CURATION, ['tts' => ['telenow' => ['offered' => true, 'name' => 'Studio voices']]]);
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = $this->account('Acme Dental', self::KEY);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $this->assertSame(self::HEALTH, parse_url($r->url(), PHP_URL_PATH));
            $key = $r->header('X-API-Key')[0] ?? '';
            $answer = ($this->answers[$key] ?? []) !== [] ? array_shift($this->answers[$key]) : throw new \RuntimeException("nothing queued for {$key}");

            return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
        });
    }

    private function account(string $name, ?string $key): Workspace
    {
        $w = Workspace::create(['name' => $name, 'status' => 'active', 'sync_state' => 'synced']);
        $w->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => $key])->save();

        return $w;
    }

    /** @return list<string> the windows asked for, in turn */
    private function asked(): array
    {
        return Http::recorded()->map(fn (array $p) => parse_url($p[0]->url(), PHP_URL_QUERY))->values()->all();
    }

    /** The kit's own agents for the recorded failures: the first group's two, the third group's (deleted at Telenow) known here too. */
    private function agents(): array
    {
        $groups = RecordedTelenow::body('getProviderHealth.hour')['data']['groups'];
        [$a, $b] = $groups[0]['agentIds'];
        $make = function (string $telenowId, string $name, bool $deleted = false): Agent {
            $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => $name]);
            $agent->forceFill(['telenow_id' => $telenowId, 'sync_state' => 'synced', 'deleted_at' => $deleted ? now() : null])->save();

            return $agent;
        };

        return ['a' => $make($a, 'Front desk'), 'b' => $make($b, 'Billing desk'), 'gone' => $make($groups[3]['agentIds'][0], 'Old desk', deleted: true)];
    }

    public function test_customers_see_their_own_failures_under_the_operators_names_in_the_kits_words_and_never_the_platforms(): void
    {
        $this->freezeSecond(); // checkedAt is compared with now(): a running clock can tick between the two
        $agents = $this->agents();
        $this->answers[self::KEY] = ['getProviderHealth.hour'];
        $groups = RecordedTelenow::body('getProviderHealth.hour')['data']['groups'];
        $health = $this->actingAs($this->ana)->getJson('/api/kit/console/call-health')->assertOk();
        $health->assertExactJson(['windowMinutes' => 60, 'callsAffected' => 5, 'checkedAt' => now()->toIso8601String(), 'failures' => [
            ['part' => 'stt', 'provider' => 'Deepgram', 'cause' => 'provider', 'calls' => 2, 'lastAt' => $groups[0]['lastAt'],
                'agents' => [['id' => $agents['a']->id, 'name' => 'Front desk'], ['id' => $agents['b']->id, 'name' => 'Billing desk']]],
            ['part' => 'tts', 'provider' => 'Studio voices', 'cause' => 'setup', 'calls' => 1, 'lastAt' => $groups[1]['lastAt'], 'agents' => [['id' => $agents['b']->id, 'name' => 'Billing desk']]],
            ['part' => 'llm', 'provider' => 'OpenAI', 'cause' => 'unknown', 'calls' => 1, 'lastAt' => $groups[2]['lastAt'], 'agents' => []],
            ['part' => 'tts', 'provider' => 'Studio voices', 'cause' => 'platform', 'calls' => 1, 'lastAt' => $groups[3]['lastAt'], 'agents' => []],
        ]]);
        $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $health->getContent(), 'no platform name, provider id, or raw error');
        foreach (array_column($groups, 'lastError') as $error) {
            $this->assertStringNotContainsString((string) $error, $health->getContent());
        }
        $this->assertSame(['minutes=60'], $this->asked());

        app(Settings::class)->put(Catalog::CURATION, []);
        app(Settings::class)->put(Catalog::COPY, ['fetchedAt' => now()->toIso8601String(), 'recommended' => [], 'kinds' => ['llm' => [], 'stt' => [['id' => 'deepgram', 'name' => 'Deepgram']],
            'tts' => [['id' => 'telenow', 'name' => config('kit.telenow.name').' voices']]]]);
        $this->travel(config('kit.call_health.cache_seconds') + 1)->seconds();
        $this->answers[self::KEY] = ['getProviderHealth.hour'];
        $named = $this->actingAs($this->ana)->getJson('/api/kit/console/call-health')->assertOk();
        $this->assertSame(['Deepgram', __('call_health.parts.tts'), __('call_health.parts.llm'), __('call_health.parts.tts')], array_column($named->json('failures'), 'provider'),
            "a name that carries the platform's, or none in the catalog: its part");
    }

    public function test_the_window_is_one_minute_to_a_day_and_a_read_is_kept_a_while(): void
    {
        $this->answers[self::KEY] = ['getProviderHealth.four-hours', 'getProviderHealth.four-hours', 'getProviderHealth.none'];
        $this->actingAs($this->ana)->getJson('/api/kit/console/call-health?minutes=240')->assertOk()->assertJsonPath('windowMinutes', 240)->assertJsonPath('callsAffected', 6);
        $this->actingAs($this->ana)->getJson('/api/kit/console/call-health?minutes=240')->assertOk();
        $this->assertSame(['minutes=240'], $this->asked(), 'kept');
        $this->actingAs($this->ana)->getJson('/api/kit/console/call-health?minutes=1440')->assertOk();
        foreach (['0', '1441', 'soon', '1.5', '-3'] as $bad) {
            $this->actingAs($this->ana)->getJson("/api/kit/console/call-health?minutes={$bad}")->assertStatus(422)->assertJsonValidationErrors('minutes');
        }
        $this->travel(config('kit.call_health.cache_seconds') + 1)->seconds();
        $this->actingAs($this->ana)->getJson('/api/kit/console/call-health?minutes=240')->assertOk()->assertJsonPath('failures', []);
        $this->assertSame(['minutes=240', 'minutes=1440', 'minutes=240'], $this->asked(), 'read again once old; nothing refused is sent');
    }

    public function test_staff_see_a_customers_failures_in_detail_and_refusals_are_said(): void
    {
        $this->agents();
        $support = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'a long password 1', 'role' => 'support']);
        $this->answers[self::KEY] = ['getProviderHealth.hour'];
        $groups = RecordedTelenow::body('getProviderHealth.hour')['data']['groups'];
        $staff = $this->actingAs($support)->getJson("/api/kit/admin/customers/{$this->acme->id}/call-health")->assertOk();
        $this->assertSame(array_map(fn ($g) => [$g['provider'], $g['attribution'], $g['keySource'], $g['incidents'], $g['lastError']], $groups),
            array_map(fn ($f) => [$f['providerId'], $f['attribution'], $f['keySource'], $f['incidents'], $f['error']], $staff->json('failures')));
        $this->assertSame(['provider', 'setup', 'unknown', 'platform'], array_column($staff->json('failures'), 'cause'));
        $this->travel(config('kit.call_health.cache_seconds') + 1)->seconds();
        $long = str_repeat('e', config('kit.health.error_length') + 50);
        $this->answers[self::KEY] = [fn () => Http::response(['success' => true, 'data' => ['windowMinutes' => 60, 'callsAffected' => 1, 'groups' => [
            ['component' => 'analysis', 'provider' => 'openai', 'keySource' => 'platform', 'attribution' => 'mystery', 'calls' => 1, 'incidents' => 1, 'lastAt' => null, 'lastError' => $long, 'agentIds' => [], 'agentNames' => []]]]]),
            fn () => throw new ConnectionException('down')];
        $odd = $this->actingAs($support)->getJson("/api/kit/admin/customers/{$this->acme->id}/call-health")->assertOk()->json('failures.0');
        $this->assertSame(['other', __('call_health.parts.other'), 'unknown', config('kit.health.error_length') + 1], [$odd['part'], $odd['provider'], $odd['cause'], mb_strlen($odd['error'])],
            'a part the kit does not know, a cause it does not know, an error cut');
        $this->travel(config('kit.call_health.cache_seconds') + 1)->seconds();
        $this->actingAs($support)->getJson("/api/kit/admin/customers/{$this->acme->id}/call-health")->assertStatus(502)->assertExactJson(['message' => __('call_health.unread')]);
        $this->actingAs($support)->getJson('/api/kit/admin/customers/'.Str::uuid().'/call-health')->assertNotFound();
        $this->actingAs($support)->getJson("/api/kit/admin/customers/{$this->acme->id}/call-health?minutes=0")->assertStatus(422);
        $this->actingAs($this->ana)->getJson("/api/kit/admin/customers/{$this->acme->id}/call-health")->assertForbidden();
        $pending = $this->account('Not yet', null);
        $this->actingAs($support)->getJson("/api/kit/admin/customers/{$pending->id}/call-health")->assertStatus(409)->assertExactJson(['message' => __('call_health.no_account')]);
        $stranger = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->getJson('/api/kit/console/call-health')->assertForbidden();
    }

    public function test_the_sweep_reads_the_accounts_with_recent_calls_busiest_first_and_adds_the_failures_up_by_provider(): void
    {
        $call = fn (Workspace $w, int $minutesAgo) => Call::forceCreate(['workspace_id' => $w->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'started_at' => now()->subMinutes($minutesAgo)]);
        $bright = $this->account('Bright Smiles', 'vai_test_customer_bright');
        $down = $this->account('Down Dental', 'vai_test_customer_down');
        $quiet = $this->account('Quiet Clinic', 'vai_test_customer_quiet');
        $unset = $this->account('Not yet', null);
        foreach ([5, 10, 15] as $m) {
            $call($this->acme, $m);
        }
        $call($bright, 20);
        $call($bright, 25);
        $call($down, 30);
        $call($quiet, config('kit.call_health.minutes') + 5);
        $call($unset, 5);
        $this->answers = [self::KEY => ['getProviderHealth.hour'], 'vai_test_customer_bright' => ['getProviderHealth.operator'], 'vai_test_customer_down' => [fn () => throw new ConnectionException('down')]];
        $this->artisan('kit:call-health')->assertSuccessful();
        $summary = app(CallHealth::class)->summary();
        $this->assertSame([3, 2, 1, config('kit.call_health.alert_calls')], [$summary['accountsActive'], $summary['accountsChecked'], $summary['accountsUnread'], $summary['alertCalls']]);
        $this->assertSame([
            ['stt', 'deepgram', 'Deepgram', 2, 1, [['Acme Dental', 2]]],
            ['tts', 'telenow', 'Studio voices', 2, 1, [['Acme Dental', 2]]],
            ['llm', 'anthropic', __('call_health.parts.llm'), 1, 1, [['Bright Smiles', 1]]],
            ['llm', 'openai', 'OpenAI', 1, 1, [['Acme Dental', 1]]],
        ], array_map(fn ($p) => [$p['part'], $p['providerId'], $p['provider'], $p['calls'], $p['customerCount'], array_map(fn ($c) => [$c['name'], $c['calls']], $p['customers'])], $summary['providers']),
            'by provider, most calls first; the same provider with two causes is one');
        $this->assertSame(['vai_test_customer_acme', 'vai_test_customer_bright'], array_values(array_filter(Http::recorded()->map(fn ($p) => $p[0]->header('X-API-Key')[0] ?? null)->all(),
            fn ($k) => $k !== 'vai_test_customer_down')), 'the busiest first; none with no calls in the window, nor with no account');

        config(['kit.call_health.alert_calls' => 2]);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/dashboard')->assertOk()->assertJsonPath('attention.providersFailing', 2);
        $this->actingAs($this->owner)->getJson('/api/kit/admin/telenow')->assertOk()->assertJsonPath('callHealth.providers.0.providerId', 'deepgram')
            ->assertJsonPath('callHealth.accountsActive', 3)->assertJsonPath('callHealth.alertCalls', 2, 'the level set now, as the dashboard flags by it');

        config(['kit.call_health.customers_listed' => 1]);
        $this->travel(config('kit.call_health.cache_seconds') + 1)->seconds();
        $this->answers = [self::KEY => ['getProviderHealth.hour'], 'vai_test_customer_bright' => ['getProviderHealth.hour'], 'vai_test_customer_down' => ['getProviderHealth.none']];
        $this->artisan('kit:call-health')->assertSuccessful();
        $deepgram = app(CallHealth::class)->summary()['providers'][0];
        $this->assertSame(['deepgram', 4, 2, [['Acme Dental', 2]]], [$deepgram['providerId'], $deepgram['calls'], $deepgram['customerCount'], array_map(fn ($c) => [$c['name'], $c['calls']], $deepgram['customers'])],
            'every customer counted, so many named; the tie by name');

        config(['kit.call_health.sweep_max_accounts' => 1]);
        $this->travel(config('kit.call_health.cache_seconds') + 1)->seconds();
        $this->answers[self::KEY] = ['getProviderHealth.none'];
        $this->artisan('kit:call-health')->assertSuccessful();
        $this->assertSame([3, 1, []], [app(CallHealth::class)->summary()['accountsActive'], app(CallHealth::class)->summary()['accountsChecked'], app(CallHealth::class)->summary()['providers']],
            'at most so many accounts a run, the busiest');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/dashboard')->assertOk()->assertJsonPath('attention.providersFailing', 0);
    }
}
