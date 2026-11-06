<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * An agent's tools, look-ups and hand-overs (App\Kit\Agents\AgentTools, AgentLookups, AgentHandoff, SavedKeys, AgentRequest), through the kit's real routes. The stand-in for Telenow
 * below does what contract_k3tools.py recorded of the 11-03 release, with its own simple code and none of the kit's: it stores keys, answers masks, puts a mask back only while the
 * row's address and any web address in it read as before, and refuses the save otherwise, with the recorded answer. Telenow's answers themselves are read in the last tests.
 */
class AgentToolsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const TOKEN = 'tok_test_webhook_1234';

    private User $ana;

    private Workspace $acme;

    /** @var array<string, array> each agent as Telenow holds it, keys in plain */
    private array $telenow = [];

    /** @var list<array{method: string, url: string, raw: string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, true);
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        app(Settings::class)->put('agents.defaults', ['stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy']);
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
            if (! str_starts_with($path, '/api/agents')) {
                return $recorded($r);
            }
            $this->sent[] = ['method' => $r->method(), 'url' => $r->url(), 'raw' => $r->body()];
            if ($r->method() === 'POST' && $path === '/api/agents') {
                return $this->made($r);
            }
            if (preg_match('#^/api/agents/([0-9a-f-]{36})(/versions)?$#', $path, $m) !== 1) {
                return $recorded($r);
            }
            if (isset($m[2])) {
                return Http::response(['success' => true, 'data' => ['versions' => [['version' => $this->telenow[$m[1]]['version'], 'note' => 'update']]]]);
            }
            if ($r->method() === 'GET') {
                return Http::response(['success' => true, 'data' => $this->masked($this->telenow[$m[1]])]);
            }

            return $r->method() === 'PUT' ? $this->saved($r, $m[1]) : $recorded($r);
        });
    }

    private function made(Request $r): mixed
    {
        $id = (string) Str::uuid();
        $body = $r->data();
        $this->telenow[$id] = ['id' => $id, 'name' => $body['name'], 'system_prompt' => $body['systemPrompt'] ?? null, 'description' => $body['description'] ?? null, 'is_active' => true, 'version' => 1,
            'llm_provider' => 'openai', 'llm_model' => 'gpt-5.4-mini', 'stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy',
            'telephony_config' => $body['telephonyConfig'] ?? [], 'session_config' => $body['sessionConfig'] ?? [], 'metadata' => ($body['metadata'] ?? []) + ['callerIdentity' => ['enabled' => true]]];

        return Http::response(['success' => true, 'data' => $this->masked($this->telenow[$id])], 201);
    }

    /** As recorded: a mask is put back only where everything the key goes to reads as it did, and a save is refused whole otherwise. */
    private function saved(Request $r, string $id): mixed
    {
        $body = $r->data();
        if (isset($body['metadata'])) {
            $held = $this->telenow[$id]['metadata'];
            $above = fn (array $m) => array_filter($m, fn ($v) => is_string($v) && str_contains($v, '://'));
            foreach (['tools', 'precallLookups'] as $list) {
                foreach ($body['metadata'][$list] ?? [] as $i => $row) {
                    foreach ($this->masksIn($row) as $path => $mask) {
                        $from = collect($held[$list] ?? [])->first(fn (array $old) => data_get($old, $path) !== null && $this->maskOf(data_get($old, $path)) === $mask && $this->places($old) === $this->places($row));
                        if ($from === null || $above($held) != $above($body['metadata'])) {
                            return Http::response(RecordedTelenow::body('updateAgent.key-moved'), 400);
                        }
                        data_set($body['metadata'][$list][$i], $path, data_get($from, $path));
                    }
                }
            }
            $this->telenow[$id]['metadata'] = $body['metadata'];
        }
        foreach (['telephonyConfig' => 'telephony_config', 'sessionConfig' => 'session_config', 'name' => 'name', 'systemPrompt' => 'system_prompt'] as $write => $column) {
            if (array_key_exists($write, $body)) {
                $this->telenow[$id][$column] = $body[$write];
            }
        }
        $this->telenow[$id]['version']++;

        return Http::response(['success' => true, 'data' => $this->masked($this->telenow[$id]), 'version' => $this->telenow[$id]['version']]);
    }

    private function maskOf(string $key): string
    {
        return strlen($key) < 12 ? '••••' : '••••'.substr($key, -4);
    }

    /** Every string in a row with a web address in it, or an address by its name: where its keys go. */
    private function places(array $row): array
    {
        $places = [];
        foreach (Arr_dot($row) as $path => $value) {
            if (is_string($value) && (str_contains($value, '://') || str_ends_with(strtolower($path), 'url') || str_ends_with(strtolower($path), 'endpoint'))) {
                $places[$path] = $value;
            }
        }

        return $places;
    }

    /** @return array<string, string> dotted path => mask */
    private function masksIn(array $row): array
    {
        return array_filter(Arr_dot($row), fn ($v) => is_string($v) && str_starts_with($v, '••••'));
    }

    private function masked(array $agent): array
    {
        array_walk_recursive($agent['metadata'], function (&$value, $key) {
            if (is_string($value) && in_array(strtolower((string) $key), ['bearer', 'authorization', 'x-api-key', 'token'], true) && ! str_starts_with($value, '••••')) {
                $value = $this->maskOf($value);
            }
        });

        return $agent;
    }

    private function make(array $input = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $input + ['name' => 'Front desk', 'systemPrompt' => 'You answer the phone.']);
    }

    private function agent(string $name = 'Front desk'): Agent
    {
        return Agent::query()->findOrFail($this->make(['name' => $name])->assertCreated()->json('id'));
    }

    private function change(Agent $agent, array $settings): TestResponse
    {
        $this->sent = [];

        return $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['settings' => $settings]);
    }

    /** @return list<array> what was sent to save, as JSON read plainly */
    private function saves(): array
    {
        return array_map(fn (array $s) => json_decode($s['raw'], true), array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'PUT')));
    }

    private function metadata(Agent $agent): array
    {
        return $this->telenow[$agent->telenow_id]['metadata'];
    }

    /** @return array<string, array> the agent's tools at Telenow, by name */
    private function rows(Agent $agent): array
    {
        return collect($this->metadata($agent)['tools'] ?? [])->keyBy('name')->all();
    }

    private function tool(array $over = []): array
    {
        return $over + ['name' => 'book_visit', 'description' => 'Book a visit when the caller asks for one.', 'url' => 'https://example.com/hooks/book'];
    }

    private function custom(array $over = []): array
    {
        return $this->tool($over + ['name' => 'get_order', 'description' => 'Look an order up.', 'url' => 'https://example.com/orders/{order_id}',
            'request' => ['method' => 'GET', 'headers' => [['name' => 'Accept', 'value' => 'application/json']]], 'parameters' => [['name' => 'order_id', 'type' => 'string']]]);
    }

    private function lookup(array $over = []): array
    {
        return $over + ['name' => 'Who is calling', 'url' => 'https://example.com/customers?phone={contact_number}', 'method' => 'GET', 'fills' => [['variable' => 'customer_name', 'path' => 'data.name']]];
    }

    /** The kit's own stored places a key must never reach. */
    private function assertNowhereInTheKit(string $secret, Agent $agent): void
    {
        $row = Agent::query()->findOrFail($agent->id);
        $this->assertStringNotContainsString($secret, json_encode($row->settings));
        $this->assertStringNotContainsString($secret, json_encode($row->telenow_snapshot));
        foreach (OutboxEntry::query()->get() as $entry) {
            $this->assertStringNotContainsString($secret, json_encode($entry->body), 'typed keys wait sealed');
        }
    }

    public function test_a_plain_tool_is_written_in_the_exact_types_telenow_parses(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN, 'parameters' => [
            ['name' => 'people', 'type' => 'integer', 'description' => 'How many.'], ['name' => 'date', 'type' => 'string', 'description' => 'The day.', 'required' => true],
        ], 'sayWhileRunning' => ' Let me check that. ', 'timeoutSecs' => '20', 'askFirst' => true])]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertSame([
            'name' => 'book_visit', 'description' => 'Book a visit when the caller asks for one.',
            'parameters' => ['type' => 'object', 'properties' => ['date' => ['type' => 'string', 'description' => 'The day.'], 'people' => ['type' => 'integer', 'description' => 'How many.']], 'required' => ['date']],
            'endpoint' => 'https://example.com/hooks/book', 'bearer' => self::TOKEN, 'handoff' => 'Let me check that.', 'timeoutSecs' => 20, 'requiresConfirmation' => true,
        ], $this->rows($agent)['book_visit']);
    }

    public function test_what_a_bare_tool_leaves_out_it_leaves_out_and_no_properties_go_as_an_object(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool()]])->assertOk();
        $this->assertSame(['name', 'description', 'parameters', 'endpoint'], array_keys($this->rows($agent)['book_visit']));
        $this->assertStringContainsString('"properties":{}', collect($this->sent)->firstWhere('method', 'PUT')['raw'], 'a model API refuses a list there');
        $this->assertStringNotContainsString('"required"', json_encode($this->rows($agent)['book_visit']['parameters']));
    }

    public function test_a_customised_tool_sends_what_is_written_with_headers_as_an_object_and_no_empty_ones(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [
            $this->custom(['request' => ['method' => 'POST', 'headers' => [['name' => 'Content-Type', 'value' => 'application/json']], 'body' => '{"text": "{note}", "meta": {"a": 1}, "lit": "{{x}}"}'], 'url' => 'https://example.com/notes', 'name' => 'add_note', 'parameters' => [['name' => 'note', 'type' => 'string']]]),
            $this->custom(['name' => 'plain_get', 'url' => 'https://example.com/ping', 'request' => ['method' => 'GET'], 'parameters' => []]),
        ]])->assertOk();
        $rows = $this->rows($agent);
        $this->assertSame(['method' => 'POST', 'url' => 'https://example.com/notes', 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"text": "{note}", "meta": {"a": 1}, "lit": "{{x}}"}'], $rows['add_note']['request']);
        $this->assertArrayNotHasKey('endpoint', $rows['add_note']);
        $this->assertSame(['method' => 'GET', 'url' => 'https://example.com/ping'], $rows['plain_get']['request'], 'a list of headers would drop the tool at Telenow');
    }

    public function test_a_typed_key_is_sent_once_and_is_nowhere_in_the_kit_or_any_answer(): void
    {
        $agent = $this->agent();
        $header = 'Bearer hdr_test_5678abcd';
        $answer = $this->change($agent, ['tools' => [
            $this->tool(['token' => self::TOKEN]),
            $this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => 'Authorization', 'value' => $header], ['name' => 'X-Tenant', 'value' => 'acme']]]]),
        ], 'lookups' => [$this->lookup(['token' => 'tok_test_lookup_3456'])]])->assertOk();
        $rows = $this->rows($agent);
        $this->assertSame(self::TOKEN, $rows['book_visit']['bearer']);
        $this->assertSame($header, $rows['get_order']['request']['headers']['Authorization']);
        $this->assertSame('tok_test_lookup_3456', $this->metadata($agent)['precallLookups'][0]['bearer']);
        $this->assertSame('••••1234', $answer->json('settings.tools.0.tokenHint'));
        $this->assertSame(['Authorization', '••••abcd'], array_values($answer->json('settings.tools.1.request.headers.0')));
        $this->assertSame('acme', $answer->json('settings.tools.1.request.headers.1.value'), 'any other header is shown as typed');
        $this->assertSame('••••3456', $answer->json('settings.lookups.0.tokenHint'));
        foreach ([self::TOKEN, 'hdr_test_5678abcd', 'tok_test_lookup_3456'] as $secret) {
            $this->assertStringNotContainsString($secret, $answer->getContent());
            $this->assertNowhereInTheKit($secret, $agent);
        }
        $this->assertArrayNotHasKey('sealed', $answer->json('settings.tools.0'));
    }

    public function test_a_key_waiting_for_a_platform_that_is_down_waits_sealed_and_is_answered_only_as_its_hint(): void
    {
        $agent = $this->agent();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response(['error' => 'down'], 503));
        $answer = $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk()->assertJsonPath('syncState', 'pending');
        $this->assertSame('••••1234', $answer->json('settings.tools.0.tokenHint'));
        $this->assertStringNotContainsString(self::TOKEN, $answer->getContent());
        $this->assertNowhereInTheKit(self::TOKEN, $agent);
        $this->assertArrayNotHasKey('sealed', $agent->fresh()->settings['tools'][0]);
        $entry = OutboxEntry::query()->orderByDesc('id')->first();
        $this->assertArrayHasKey('sealed', $entry->body[AgentSettings::CHANGES]['tools'][0], 'it can still be sent later');
    }

    public function test_it_never_asks_for_the_keys_in_plain_text(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk();
        $this->change($agent->fresh(), ['canAdjustVolume' => true])->assertOk();
        $this->assertNotSame([], $this->sent);
        foreach ($this->sent as $request) {
            $this->assertStringNotContainsString('view=', $request['url']);
        }
    }

    public function test_a_saved_key_stays_through_a_save_and_saving_the_same_sends_nothing(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk();
        $this->change($agent->fresh(), ['tools' => [$this->tool(['description' => 'Book it.'])]])->assertOk()->assertJsonPath('syncState', 'synced')->assertJsonPath('settings.tools.0.tokenHint', '••••1234');
        $this->assertSame(self::TOKEN, $this->rows($agent)['book_visit']['bearer'], 'the mask went back and Telenow kept the key');
        $this->assertSame('••••1234', collect($this->saves()[0]['metadata']['tools'])->firstWhere('name', 'book_visit')['bearer'], 'the kit only ever holds the mask');

        $this->change($agent->fresh(), ['tools' => [$this->tool(['description' => 'Book it.'])]])->assertOk();
        $this->assertSame([], $this->saves());
    }

    public function test_a_key_can_be_replaced_and_taken_away(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk();
        $this->change($agent->fresh(), ['tools' => [$this->tool(['token' => 'tok_test_new_77776666'])]])->assertOk()->assertJsonPath('settings.tools.0.tokenHint', '••••6666');
        $this->assertSame('tok_test_new_77776666', $this->rows($agent)['book_visit']['bearer']);
        $this->change($agent->fresh(), ['tools' => [$this->tool(['token' => null])]])->assertOk()->assertJsonPath('settings.tools.0.tokenHint', null);
        $this->assertArrayNotHasKey('bearer', $this->rows($agent)['book_visit']);
        $this->change($agent->fresh(), ['tools' => [$this->tool(['token' => 'short'])]])->assertOk()->assertJsonPath('settings.tools.0.tokenHint', '••••');
        $this->change($agent->fresh(), ['tools' => [$this->tool(['token' => 'short'])]])->assertOk();
        $this->assertCount(1, $this->saves(), 'a key typed is always sent, though its mask reads the same');
    }

    public function test_a_saved_key_is_refused_beside_its_field_before_telenow_would_refuse_the_save(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk();
        foreach ([
            'its address' => ['url' => 'https://example.com/other'],
            'a web address in what it is for' => ['description' => 'See https://example.org/help'],
            'a web address in what it says' => ['sayWhileRunning' => 'See https://example.org'],
            'a web address in a detail' => ['parameters' => [['name' => 'a', 'type' => 'string', 'description' => 'like https://example.org']]],
        ] as $what => $over) {
            $this->change($agent->fresh(), ['tools' => [$this->tool($over)]])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.token' => __('agents.key_stays_with_address')]);
            $this->assertSame([], $this->saves(), $what);
        }
        $this->change($agent->fresh(), ['tools' => [$this->tool(['url' => 'https://example.com/other', 'token' => 'tok_test_new_77776666'])]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertSame('tok_test_new_77776666', $this->rows($agent)['book_visit']['bearer']);
        $this->change($agent->fresh(), ['tools' => [$this->tool(['url' => 'https://example.com/again', 'token' => null])]])->assertOk()->assertJsonPath('syncState', 'synced');
    }

    public function test_a_mask_typed_as_a_key_or_one_that_is_not_this_rows_is_refused(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => 'X-Api-Key', 'value' => 'xkey_test_9012abcd']]]])]])->assertOk();
        $this->change($agent->fresh(), ['tools' => [$this->tool(['token' => '••••1234'])]])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.token' => __('agents.key_unknown')]);
        $this->change($agent->fresh(), ['tools' => [$this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => 'X-Api-Key', 'value' => '••••nope']]]])]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.request.headers.0.value' => __('agents.key_unknown')]);
        $this->change($agent->fresh(), ['tools' => [$this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => 'X-Tenant', 'value' => '••••abcd']]]])]])
            ->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.request.headers.0.value' => __('agents.key_unknown')]);
    }

    public function test_a_saved_header_key_stays_with_its_mask_and_goes_no_further(): void
    {
        $agent = $this->agent();
        $headers = fn (string $value) => ['method' => 'GET', 'headers' => [['name' => 'X-Api-Key', 'value' => $value]]];
        $this->change($agent, ['tools' => [$this->custom(['request' => $headers('xkey_test_9012abcd')])]])->assertOk()->assertJsonPath('settings.tools.0.request.headers.0.value', '••••abcd');
        $this->change($agent->fresh(), ['tools' => [$this->custom(['description' => 'Find it.', 'request' => $headers('••••abcd')])]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertSame('xkey_test_9012abcd', $this->rows($agent)['get_order']['request']['headers']['X-Api-Key']);
        $this->change($agent->fresh(), ['tools' => [$this->custom(['url' => 'https://example.org/{order_id}', 'request' => $headers('••••abcd')])]])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.token' => __('agents.key_stays_with_address')]);
        $this->change($agent->fresh(), ['tools' => [$this->custom(['url' => 'https://example.org/{order_id}', 'request' => $headers('xkey_test_new_00001111')])]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertSame('xkey_test_new_00001111', $this->rows($agent)['get_order']['request']['headers']['X-Api-Key']);
    }

    public function test_a_new_name_is_a_new_tool_and_takes_no_key_with_it(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk();
        $this->change($agent->fresh(), ['tools' => [$this->tool(['name' => 'book_a_visit'])]])->assertOk()->assertJsonPath('settings.tools.0.tokenHint', null);
        $this->assertArrayNotHasKey('bearer', $this->rows($agent)['book_a_visit']);
        $this->assertArrayNotHasKey('book_visit', $this->rows($agent));
    }

    /** @return array<string, array{string}> */
    public static function badAddresses(): array
    {
        return [
            'plain http' => ['http://example.com/x', 'address_https'],
            'another scheme' => ['ftp://example.com/x', 'address_https'],
            'the cloud metadata address' => ['https://169.254.169.254/latest', 'address_private'],
            'a private address' => ['https://10.0.0.5/x', 'address_private'],
            'another private one' => ['https://192.168.1.1/x', 'address_private'],
            'loopback' => ['https://127.0.0.1/x', 'address_private'],
            'loopback by name' => ['https://localhost/x', 'address_private'],
            'loopback under a name' => ['https://db.localhost/x', 'address_private'],
            'an internal name' => ['https://db.internal/x', 'address_private'],
            'one label' => ['https://printer/x', 'address_private'],
            'a private name' => ['https://db.corp/x', 'address_private'],
            'a number for a host' => ['https://2130706433/x', 'address_private'],
            'hex for a host' => ['https://0x7f.1/x', 'address_private'],
            'a short address' => ['https://127.1/x', 'address_private'],
            'loopback v6' => ['https://[::1]/x', 'address_private'],
            'mapped v6' => ['https://[::ffff:127.0.0.1]/x', 'address_private'],
            'a login in it' => ['https://user:pass@example.com/x', 'address_login'],
            'a detail in the host' => ['https://{host}/x', 'address_placeholder_in_host'],
            'a detail on the host' => ['https://example.com{path}/x', 'address_placeholder_in_host'],
            'a backslash' => ['https://example.com\\@evil.example/x', 'address_shape'],
            'a space' => ['https://exam ple.com/x', 'address_shape'],
            'no host' => ['https:///x', 'address_shape'],
            'not an address' => ['book it', 'address_shape'],
        ];
    }

    #[DataProvider('badAddresses')]
    public function test_an_address_telenow_would_store_and_fail_on_is_refused_here(string $url, string $why): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['url' => $url])]])->assertStatus(422)->assertJsonPath('errors', ['settings.tools.0.url' => [__("agents.{$why}")]]);
        $this->assertSame(__("agents.{$why}"), $this->change($agent, ['lookups' => [$this->lookup(['url' => $url.'?who={contact_number}'])]])->assertStatus(422)->json('errors')['settings.lookups.0.url'][0]);
        $this->assertSame([], $this->saves());
    }

    public function test_an_address_on_the_public_internet_is_taken(): void
    {
        $agent = $this->agent();
        foreach (['https://example.com/x', 'https://EXAMPLE.com:8443/x?a=1#b', 'https://8.8.8.8/x', 'https://sub.example.co.uk/'] as $url) {
            $this->change($agent->fresh(), ['tools' => [$this->tool(['url' => $url])]])->assertOk();
        }
    }

    public function test_a_detail_it_reads_must_be_one_it_has_or_one_from_the_call(): void
    {
        $agent = $this->agent();
        $at = fn (string $field) => "settings.tools.0.{$field}";
        $this->change($agent, ['tools' => [$this->custom(['url' => 'https://example.com/{nope}'])]])->assertStatus(422)->assertJsonValidationErrors([$at('url') => __('agents.placeholder_unknown', ['name' => 'nope'])]);
        $this->change($agent, ['tools' => [$this->custom(['request' => ['method' => 'POST', 'body' => '{"a": "{nope}"}'], 'url' => 'https://example.com/x'])]])->assertStatus(422)->assertJsonValidationErrors($at('request.body'));
        $this->change($agent, ['tools' => [$this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => 'X-Who', 'value' => '{nope}']]], 'url' => 'https://example.com/x'])]])->assertStatus(422)->assertJsonValidationErrors($at('request.headers.0.value'));
        $this->change($agent, ['tools' => [$this->custom(['url' => 'https://example.com/{order_id|shout}'])]])->assertStatus(422)->assertJsonValidationErrors([$at('url') => __('agents.placeholder_filter', ['filter' => 'shout'])]);
        $this->change($agent, ['tools' => [$this->tool(['url' => 'https://example.com/{order_id}', 'parameters' => [['name' => 'order_id', 'type' => 'string']]])]])->assertStatus(422)->assertJsonValidationErrors([$at('url') => __('agents.tool_plain_placeholder')]);
        $this->assertSame([], $this->saves());
        $this->change($agent, ['tools' => [$this->custom(['url' => 'https://example.com/x', 'parameters' => [], 'request' => ['method' => 'POST', 'body' => "{\"a\": \"{not_read\n}\", \"b\": {\"c\": 1}}"]])]])->assertOk();
        $this->change($agent, ['tools' => [$this->custom(['url' => 'https://example.com/{order_id|urlencode}?who={contact_number}&on={contact_channel}&lit={{x}}'])]])->assertOk();
        $this->assertSame('https://example.com/{order_id|urlencode}?who={contact_number}&on={contact_channel}&lit={{x}}', $this->rows($agent)['get_order']['request']['url']);
    }

    public function test_the_callers_number_is_only_read_by_a_tool_while_it_is_sent_to_tools(): void
    {
        $agent = $this->agent();
        $reads = $this->custom(['url' => 'https://example.com/{order_id}?who={contact_number}']);
        $this->change($agent, ['tools' => [$reads]])->assertOk();
        $this->change($agent->fresh(), ['sendCallerToTools' => false])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.url' => __('agents.placeholder_needs_caller', ['name' => 'contact_number'])]);
        $this->assertSame([], $this->saves());
        $this->change($agent->fresh(), ['sendCallerToTools' => false, 'tools' => [$reads]])->assertStatus(422)->assertJsonValidationErrors('settings.tools.0.url');
        $this->change($agent->fresh(), ['sendCallerToTools' => false, 'tools' => [$this->custom(['url' => 'https://example.com/{order_id}?on={contact_channel}'])]])->assertOk()->assertJsonPath('settings.sendCallerToTools', false);
        $this->assertSame(['enabled' => false], $this->metadata($agent)['callerIdentity']);
        $this->change($agent->fresh(), ['tools' => [$reads]])->assertStatus(422);
        $this->change($agent->fresh(), ['sendCallerToTools' => true, 'tools' => [$reads]])->assertOk()->assertJsonPath('settings.sendCallerToTools', true);
    }

    public function test_the_caller_switch_keeps_the_rest_of_its_block(): void
    {
        $agent = $this->agent();
        $this->telenow[$agent->telenow_id]['metadata']['callerIdentity'] = ['enabled' => true, 'label' => 'Customer'];
        $this->change($agent, ['sendCallerToTools' => false])->assertOk();
        $this->assertSame(['enabled' => false, 'label' => 'Customer'], $this->metadata($agent)['callerIdentity']);
    }

    public function test_a_tool_that_could_not_work_is_refused_beside_its_field(): void
    {
        $agent = $this->agent();
        $cases = [
            'settings.tools.1.name' => [$this->tool(), $this->tool()],
            'settings.tools.0.name' => [$this->tool(['name' => 'end_call'])],
            'settings.tools.0.description' => [$this->tool(['description' => ''])],
            'settings.tools.0.url' => [$this->tool(['url' => ''])],
            'settings.tools.0.parameters.1.name' => [$this->custom(['parameters' => [['name' => 'order_id', 'type' => 'string'], ['name' => 'order_id', 'type' => 'number']]])],
            'settings.tools.0.parameters.0.type' => [$this->custom(['parameters' => [['name' => 'order_id', 'type' => 'date']]])],
            'settings.tools.0.request.method' => [$this->custom(['request' => ['method' => 'TRACE']])],
            'settings.tools.0.request.body' => [$this->custom(['request' => ['method' => 'GET', 'body' => 'x']])],
            'settings.tools.0.timeoutSecs' => [$this->tool(['timeoutSecs' => 31])],
            'settings.tools.0.request.headers.0.name' => [$this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => 'Bad Name', 'value' => 'x']]]])],
        ];
        foreach ($cases as $field => $tools) {
            $this->change($agent, ['tools' => $tools])->assertStatus(422)->assertJsonValidationErrors($field);
            $this->assertSame([], $this->saves(), $field);
        }
        foreach ([['name' => 'bad name!'], ['name' => str_repeat('a', 65)], ['description' => str_repeat('a', 1001)], ['timeoutSecs' => 0], ['askFirst' => 'perhaps'], ['sayWhileRunning' => str_repeat('a', 501)], ['url' => 'https://example.com/'.str_repeat('a', 2048)]] as $over) {
            $this->change($agent, ['tools' => [$this->tool($over)]])->assertStatus(422);
        }
        foreach (['Host', 'content-length', 'Transfer-Encoding', 'Connection'] as $name) {
            $this->change($agent, ['tools' => [$this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => $name, 'value' => 'x']]]])]])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.request.headers.0.name' => __('agents.header_name')]);
        }
        $this->change($agent, ['tools' => [$this->custom(['request' => ['method' => 'GET', 'headers' => [['name' => 'X-A', 'value' => '1'], ['name' => 'x-a', 'value' => '2']]]])]])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.request.headers.1.name' => __('agents.header_twice')]);
        foreach (['contact_number', 'session_id', 'caller_number'] as $name) {
            $this->change($agent, ['tools' => [$this->tool(['parameters' => [['name' => $name, 'type' => 'string']]])]])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.parameters.0.name' => __('agents.parameter_reserved')]);
        }
        $this->change($agent, ['tools' => [$this->custom(['token' => self::TOKEN, 'request' => ['method' => 'GET', 'headers' => [['name' => 'authorization', 'value' => 'Bearer x']]]])]])->assertStatus(422)->assertJsonValidationErrors(['settings.tools.0.token' => __('agents.key_and_authorization')]);
        $this->assertSame([], $this->saves());
    }

    public function test_limits_are_the_kits_and_the_edge_is_allowed(): void
    {
        $agent = $this->agent();
        $tools = fn (int $n) => array_map(fn (int $i) => $this->tool(['name' => "tool_{$i}"]), range(1, $n));
        $max = config('kit.agents.limits.tools_max');
        $this->change($agent, ['tools' => $tools($max + 1)])->assertStatus(422)->assertJsonValidationErrors('settings.tools');
        $this->change($agent, ['tools' => $tools($max)])->assertOk();
        $parameters = fn (int $n) => array_map(fn (int $i) => ['name' => "p{$i}", 'type' => 'string'], range(1, $n));
        $this->change($agent->fresh(), ['tools' => [$this->tool(['parameters' => $parameters(config('kit.agents.limits.tool_parameters_max') + 1)])]])->assertStatus(422);
        $this->change($agent->fresh(), ['tools' => [$this->tool(['parameters' => $parameters(config('kit.agents.limits.tool_parameters_max'))])]])->assertOk();
        foreach (config('kit.agents.limits.tool_timeout_seconds') as $edge) {
            $this->change($agent->fresh(), ['tools' => [$this->tool(['timeoutSecs' => $edge])]])->assertOk()->assertJsonPath('settings.tools.0.timeoutSecs', $edge);
        }
    }

    public function test_tools_it_does_not_manage_stay_as_they_are_and_taking_all_its_own_away_leaves_them(): void
    {
        $agent = $this->agent();
        $connector = ['name' => 'crm_find', 'kind' => 'connector', 'description' => 'Find a contact.', 'config' => ['connectionId' => (string) Str::uuid(), 'capability' => 'crm.find', 'token' => 'cfg_test_key_00001111']];
        $mcp = ['name' => 'mcp_thing', 'kind' => 'mcp', 'config' => ['url' => 'https://mcp.example.com', 'mcpName' => 'thing']];
        $this->telenow[$agent->telenow_id]['metadata']['tools'][] = $connector;
        $this->telenow[$agent->telenow_id]['metadata']['tools'][] = $mcp;
        $this->telenow[$agent->telenow_id]['metadata']['tools'][] = ['name' => 'app_thing', 'kind' => 'app', 'endpoint' => 'https://app.example.com/run'];
        $agent->forceFill(['settings' => null, 'telenow_snapshot' => $this->masked($this->telenow[$agent->telenow_id])])->save();

        $this->change($agent->fresh(), ['tools' => [$this->tool()]])->assertOk()->assertJsonCount(1, 'settings.tools')->assertJsonPath('syncState', 'synced');
        $rows = $this->rows($agent);
        $this->assertSame(['end_call', 'crm_find', 'mcp_thing', 'app_thing', 'book_visit'], array_keys($rows));
        $this->assertSame($mcp, $rows['mcp_thing']);
        $this->assertSame('••••1111', $this->saves()[0]['metadata']['tools'][1]['config']['token'], 'its mask goes back as it came');

        $this->change($agent->fresh(), ['tools' => []])->assertOk()->assertJsonPath('settings.tools', []);
        $this->assertSame(['end_call', 'crm_find', 'mcp_thing', 'app_thing'], array_keys($this->rows($agent)));
    }

    public function test_a_row_keeps_keys_it_does_not_set(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk();
        $this->telenow[$agent->telenow_id]['metadata']['tools'][1]['piiEgress'] = ['rules' => ['pan'], 'params' => ['card']];
        $this->change($agent->fresh(), ['tools' => [$this->tool(['description' => 'Book it.'])]])->assertOk();
        $this->assertSame(['rules' => ['pan'], 'params' => ['card']], $this->rows($agent)['book_visit']['piiEgress']);
    }

    public function test_the_volume_switch_adds_and_removes_its_own_row(): void
    {
        $agent = $this->agent();
        $this->assertFalse($this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->json('settings.canAdjustVolume'));
        $this->change($agent, ['canAdjustVolume' => true])->assertOk()->assertJsonPath('settings.canAdjustVolume', true);
        $row = $this->rows($agent)['adjust_volume'];
        $this->assertSame(['adjust_volume', config('kit.agents.tool_descriptions.adjust_volume')], [$row['kind'], $row['description']]);
        $this->assertArrayNotHasKey('config', $row);
        $this->assertSame([], array_values(array_filter($this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->json('settings.tools'), fn ($t) => $t['name'] === 'adjust_volume')), 'not one of its own tools');
        $this->change($agent->fresh(), ['canAdjustVolume' => false])->assertOk()->assertJsonPath('settings.canAdjustVolume', false);
        $this->assertSame(['end_call'], array_keys($this->rows($agent)));
    }

    public function test_what_needs_the_agent_to_exist_waits_until_it_does_and_the_switches_do_not(): void
    {
        foreach (['tools' => [$this->tool()], 'lookups' => [$this->lookup()], 'handoff' => ['agents' => []]] as $key => $value) {
            $this->make(['settings' => [$key => $value]])->assertStatus(422)->assertJsonValidationErrors(["settings.{$key}" => __('agents.tools_after_create')]);
        }
        $this->make(['settings' => ['canAdjustVolume' => true, 'sendCallerToTools' => false]])->assertCreated()->assertJsonPath('settings.canAdjustVolume', true)->assertJsonPath('settings.sendCallerToTools', false);
    }

    public function test_a_look_up_is_written_as_telenows_row_saying_who_it_is_about(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['lookups' => [
            $this->lookup(['token' => 'tok_test_lookup_3456', 'headers' => [['name' => 'Accept', 'value' => 'application/json']], 'fills' => [['variable' => 'plan', 'path' => 'data.0.plan'], ['variable' => 'customer_name', 'path' => 'data.name', 'fallback' => 'there']]]),
            $this->lookup(['name' => 'By customer id', 'url' => 'https://example.com/c/{customer_id}/{contact_identifier}', 'fills' => [['variable' => 'tier', 'path' => 'tier']]]),
            $this->lookup(['name' => 'Posted', 'method' => 'POST', 'url' => 'https://example.com/find', 'body' => '{"id": "{customer_id}"}', 'fills' => [['variable' => 'vip', 'path' => 'vip']]]),
        ]])->assertOk()->assertJsonPath('syncState', 'synced');
        $rows = $this->metadata($agent)['precallLookups'];
        $this->assertSame(['kind' => 'http', 'name' => 'Who is calling', 'key' => 'contact_number',
            'request' => ['method' => 'GET', 'url' => 'https://example.com/customers?phone={contact_number}', 'headers' => ['Accept' => 'application/json']],
            'map' => ['customer_name' => 'data.name', 'plan' => 'data.0.plan'], 'bearer' => 'tok_test_lookup_3456'], $rows[0]);
        $this->assertSame('contact_identifier', $rows[1]['key'], "the call's own id before a variable it reads");
        $this->assertSame(['method' => 'GET', 'url' => 'https://example.com/c/{customer_id}/{contact_identifier}'], $rows[1]['request'], 'a list of headers would drop every look-up at Telenow');
        $this->assertSame(['customer_id', ['method' => 'POST', 'url' => 'https://example.com/find', 'body' => '{"id": "{customer_id}"}']], [$rows[2]['key'], $rows[2]['request']]);
        $this->assertSame([['name' => 'customer_name', 'required' => false, 'default' => 'there']], $this->metadata($agent)['variables']);
    }

    public function test_a_look_up_reads_back_and_saving_the_same_sends_nothing(): void
    {
        $agent = $this->agent();
        $input = $this->lookup(['token' => 'tok_test_lookup_3456', 'fills' => [['variable' => 'plan', 'path' => 'plan'], ['variable' => 'customer_name', 'path' => 'data.name', 'fallback' => 'there']]]);
        $answer = $this->change($agent, ['lookups' => [$input]])->assertOk();
        $this->assertSame(['name' => 'Who is calling', 'url' => $input['url'], 'method' => 'GET', 'headers' => [], 'body' => null, 'tokenHint' => '••••3456',
            'fills' => [['variable' => 'customer_name', 'path' => 'data.name', 'fallback' => 'there'], ['variable' => 'plan', 'path' => 'plan', 'fallback' => null]]], $answer->json('settings.lookups.0'));
        unset($input['token']);
        $this->change($agent->fresh(), ['lookups' => [$input]])->assertOk();
        $this->assertSame([], $this->saves());
        $this->change($agent->fresh(), ['lookups' => [$this->lookup(['name' => 'Who is calling', 'fills' => $input['fills'], 'headers' => [['name' => 'Accept', 'value' => 'a/b']]])]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertSame('tok_test_lookup_3456', $this->metadata($agent)['precallLookups'][0]['bearer'], 'its mask went back and the key stayed');
    }

    public function test_a_look_ups_key_goes_no_further_than_its_address(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['lookups' => [$this->lookup(['token' => 'tok_test_lookup_3456'])]])->assertOk();
        $this->change($agent->fresh(), ['lookups' => [$this->lookup(['url' => 'https://example.org/?p={contact_number}'])]])->assertStatus(422)->assertJsonValidationErrors(['settings.lookups.0.token' => __('agents.key_stays_with_address')]);
        $this->assertSame([], $this->saves());
        $this->change($agent->fresh(), ['lookups' => [$this->lookup(['url' => 'https://example.org/?p={contact_number}', 'token' => 'tok_test_new_77776666'])]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->change($agent->fresh(), ['lookups' => [$this->lookup(['url' => 'https://example.net/?p={contact_number}', 'token' => null])]])->assertOk()->assertJsonPath('settings.lookups.0.tokenHint', null);
        $this->assertArrayNotHasKey('bearer', $this->metadata($agent)['precallLookups'][0]);
    }

    public function test_a_look_ups_header_key_is_sealed_sent_kept_with_its_mask_and_goes_no_further(): void
    {
        $agent = $this->agent();
        $headers = fn (string $value) => [['name' => 'X-Api-Key', 'value' => $value], ['name' => 'X-Tenant', 'value' => 'acme']];
        $answer = $this->change($agent, ['lookups' => [$this->lookup(['headers' => $headers('xkey_test_9012abcd')])]])->assertOk()->assertJsonPath('settings.lookups.0.headers.0.value', '••••abcd');
        $this->assertSame('xkey_test_9012abcd', $this->metadata($agent)['precallLookups'][0]['request']['headers']['X-Api-Key']);
        $this->assertStringNotContainsString('xkey_test_9012abcd', $answer->getContent());
        $this->assertNowhereInTheKit('xkey_test_9012abcd', $agent);

        $this->change($agent->fresh(), ['lookups' => [$this->lookup(['headers' => $headers('••••abcd'), 'fills' => [['variable' => 'plan', 'path' => 'plan']]])]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertSame('xkey_test_9012abcd', $this->metadata($agent)['precallLookups'][0]['request']['headers']['X-Api-Key'], 'its mask went back and the key stayed');

        $this->change($agent->fresh(), ['lookups' => [$this->lookup(['url' => 'https://example.org/?p={contact_number}', 'headers' => $headers('••••abcd')])]])->assertStatus(422)->assertJsonValidationErrors(['settings.lookups.0.token' => __('agents.key_stays_with_address')]);
        $this->change($agent->fresh(), ['lookups' => [$this->lookup(['headers' => $headers('••••nope')])]])->assertStatus(422)->assertJsonValidationErrors(['settings.lookups.0.headers.0.value' => __('agents.key_unknown')]);
    }

    public function test_a_look_up_that_could_not_work_is_refused_since_one_bad_row_drops_them_all(): void
    {
        $agent = $this->agent();
        $cases = [
            'settings.lookups.0.url' => [$this->lookup(['url' => 'https://example.com/nobody'])],
            'settings.lookups.0.method' => [$this->lookup(['method' => 'PUT'])],
            'settings.lookups.0.body' => [$this->lookup(['body' => 'x'])],
            'settings.lookups.0.fills' => [$this->lookup(['fills' => []])],
            'settings.lookups.0.fills.0.variable' => [$this->lookup(['fills' => [['variable' => 'current_date', 'path' => 'a']]])],
            'settings.lookups.0.fills.0.path' => [$this->lookup(['fills' => [['variable' => 'a', 'path' => 'not a path']]])],
            'settings.lookups.0.name' => [$this->lookup(['name' => ''])],
            'settings.lookups.1.name' => [$this->lookup(), $this->lookup()],
            'settings.lookups.1.fills.0.variable' => [$this->lookup(), $this->lookup(['name' => 'Again'])],
            'settings.lookups.0.fills.1.variable' => [$this->lookup(['fills' => [['variable' => 'a', 'path' => 'a'], ['variable' => 'a', 'path' => 'b']]])],
            'settings.lookups.0.token' => [$this->lookup(['token' => '••••1234'])],
            'settings.lookups.0.headers.0.name' => [$this->lookup(['headers' => [['name' => 'Bad Name', 'value' => 'x']]])],
        ];
        foreach ($cases as $field => $lookups) {
            $this->change($agent, ['lookups' => $lookups])->assertStatus(422)->assertJsonValidationErrors($field);
            $this->assertSame([], $this->saves(), $field);
        }
        $this->change($agent, ['lookups' => array_map(fn (int $i) => $this->lookup(['name' => "L{$i}", 'fills' => [['variable' => "v{$i}", 'path' => 'a']]]), range(1, config('kit.agents.limits.lookups_max') + 1))])->assertStatus(422)->assertJsonValidationErrors('settings.lookups');
        $this->change($agent, ['lookups' => [$this->lookup(['token' => self::TOKEN, 'headers' => [['name' => 'Authorization', 'value' => 'Bearer x']]])]])->assertStatus(422)->assertJsonValidationErrors(['settings.lookups.0.token' => __('agents.key_and_authorization')]);
        $this->change($agent, ['lookups' => array_map(fn (int $i) => $this->lookup(['name' => "L{$i}", 'fills' => [['variable' => "v{$i}", 'path' => 'a']]]), range(1, config('kit.agents.limits.lookups_max')))])->assertOk();
    }

    public function test_only_the_variables_look_ups_fill_are_declared_and_any_other_stays(): void
    {
        $agent = $this->agent();
        $this->telenow[$agent->telenow_id]['metadata']['variables'] = [['name' => 'campaign', 'required' => true], ['name' => 'customer_name', 'required' => true, 'default' => 'old']];
        $withFallback = fn (?string $fallback) => $this->lookup(['fills' => [['variable' => 'customer_name', 'path' => 'data.name', 'fallback' => $fallback]]]);
        $this->change($agent, ['lookups' => [$withFallback('there')]])->assertOk();
        $this->assertSame([['name' => 'campaign', 'required' => true], ['name' => 'customer_name', 'required' => false, 'default' => 'there']], $this->metadata($agent)['variables'], 'the look-up says what happens to this one now');

        $this->change($agent->fresh(), ['lookups' => [$withFallback(null)]])->assertOk();
        $this->assertSame([['name' => 'campaign', 'required' => true]], $this->metadata($agent)['variables']);

        $this->change($agent->fresh(), ['lookups' => [$withFallback('friend')]])->assertOk();
        $this->change($agent->fresh(), ['lookups' => []])->assertOk()->assertJsonPath('settings.lookups', []);
        $this->assertSame([['name' => 'campaign', 'required' => true]], $this->metadata($agent)['variables']);
        $this->assertArrayNotHasKey('precallLookups', $this->metadata($agent));

        $this->telenow[$agent->telenow_id]['metadata']['variables'] = [];
        $this->change($agent->fresh(), ['lookups' => [$withFallback(null)]])->assertOk();
        $this->assertArrayNotHasKey('variables', $this->metadata($agent), 'an empty list says nothing');
    }

    public function test_look_ups_it_does_not_manage_stay_as_they_are(): void
    {
        $agent = $this->agent();
        $connector = ['name' => 'CRM', 'connectionId' => (string) Str::uuid(), 'capability' => 'crm.find', 'args' => ['phone' => '{contact_number}'], 'map' => ['x' => 'y']];
        $this->telenow[$agent->telenow_id]['metadata']['precallLookups'] = [$connector];
        $agent->forceFill(['settings' => null, 'telenow_snapshot' => $this->masked($this->telenow[$agent->telenow_id])])->save();
        $this->assertSame([], $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->json('settings.lookups'));
        $this->change($agent->fresh(), ['lookups' => [$this->lookup()]])->assertOk();
        $this->assertSame([$connector, 'Who is calling'], [$this->metadata($agent)['precallLookups'][0], $this->metadata($agent)['precallLookups'][1]['name']]);
        $this->change($agent->fresh(), ['lookups' => []])->assertOk();
        $this->assertSame([$connector], $this->metadata($agent)['precallLookups']);
    }

    public function test_a_web_address_in_the_voicemail_line_waits_on_saved_keys_as_telenow_would_refuse_it(): void
    {
        $agent = $this->agent();
        $line = 'See https://example.org/help';
        $this->change($agent, ['voicemailMessage' => $line])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->change($agent->fresh(), ['voicemailMessage' => 'See https://example.org/other'])->assertOk()->assertJsonPath('syncState', 'synced');

        $this->change($agent->fresh(), ['tools' => [$this->tool(['token' => self::TOKEN])]])->assertOk();
        $this->change($agent->fresh(), ['voicemailMessage' => $line])->assertStatus(422)->assertJsonValidationErrors(['settings.voicemailMessage' => __('agents.voicemail_address_keys')]);
        $this->assertSame([], $this->saves());
        $this->change($agent->fresh(), ['voicemailMessage' => null])->assertStatus(422)->assertJsonValidationErrors('settings.voicemailMessage');
        $this->change($agent->fresh(), ['voicemailMessage' => 'See https://example.org/other'])->assertOk();

        $this->change($agent->fresh(), ['voicemailMessage' => $line, 'tools' => [$this->tool(['token' => 'tok_test_new_77776666'])]])->assertOk()->assertJsonPath('syncState', 'synced')->assertJsonPath('settings.voicemailMessage', $line);
    }

    public function test_a_line_with_no_address_changes_freely_beside_saved_keys(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['lookups' => [$this->lookup(['token' => 'tok_test_lookup_3456'])]])->assertOk();
        $this->change($agent->fresh(), ['voicemailMessage' => 'Please call back.'])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->change($agent->fresh(), ['voicemailMessage' => 'See https://example.org'])->assertStatus(422);
        $this->change($agent->fresh(), ['lookups' => []])->assertOk();
        $this->change($agent->fresh(), ['voicemailMessage' => 'See https://example.org'])->assertOk()->assertJsonPath('syncState', 'synced');
    }

    public function test_handing_over_names_the_other_agent_by_telenows_id_and_goes_by_its_name(): void
    {
        $agent = $this->agent();
        $billing = $this->agent('Billing desk');
        $sales = $this->agent('Sales desk');
        $this->change($agent->fresh(), ['handoff' => ['agents' => [['agentId' => $billing->id, 'mode' => 'transfer'], ['agentId' => $sales->id, 'label' => 'Sales', 'mode' => 'connect']], 'message' => ' One moment. ']])
            ->assertOk()->assertJsonPath('syncState', 'synced')->assertJsonPath('settings.handoff', ['agents' => [['agentId' => $billing->id, 'label' => 'Billing desk', 'mode' => 'transfer'], ['agentId' => $sales->id, 'label' => 'Sales', 'mode' => 'connect']], 'message' => 'One moment.']);
        $row = $this->rows($agent)['handoff_to_agent'];
        $this->assertSame('agent', $row['kind']);
        $this->assertSame(config('kit.agents.tool_descriptions.handoff'), $row['description']);
        $this->assertSame([['agentId' => $billing->telenow_id, 'kitAgentId' => $billing->id, 'label' => 'Billing desk', 'mode' => 'transfer'], ['agentId' => $sales->telenow_id, 'kitAgentId' => $sales->id, 'label' => 'Sales', 'mode' => 'connect']], $row['config']['agents']);
        $this->assertSame('One moment.', $row['config']['message']);

        $this->change($agent->fresh(), ['handoff' => ['agents' => [['agentId' => $billing->id, 'mode' => 'transfer'], ['agentId' => $sales->id, 'label' => 'Sales', 'mode' => 'connect']], 'message' => 'One moment.']])->assertOk();
        $this->assertSame([], $this->saves(), 'saving the same sends nothing');

        $this->change($agent->fresh(), ['handoff' => ['agents' => [], 'message' => null]])->assertOk()->assertJsonPath('settings.handoff', ['agents' => [], 'message' => null]);
        $this->assertArrayNotHasKey('handoff_to_agent', $this->rows($agent));
        $this->assertArrayHasKey('end_call', $this->rows($agent));
    }

    public function test_a_hand_over_goes_only_to_another_agent_of_this_account_that_exists_at_telenow(): void
    {
        $agent = $this->agent();
        $to = fn (string $id, array $over = []) => ['agents' => [$over + ['agentId' => $id, 'mode' => 'transfer']]];
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new Agent(['name' => 'Theirs']);
        $theirs->workspace_id = $other->id;
        $theirs->forceFill(['telenow_id' => (string) Str::uuid()])->save();
        $this->change($agent, ['handoff' => $to($theirs->id)])->assertStatus(422)->assertJsonValidationErrors(['settings.handoff.agents.0.agentId' => __('agents.handoff_unknown')]);
        $this->change($agent, ['handoff' => $to((string) Str::uuid())])->assertStatus(422)->assertJsonValidationErrors('settings.handoff.agents.0.agentId');
        $this->change($agent, ['handoff' => $to($agent->id)])->assertStatus(422)->assertJsonValidationErrors(['settings.handoff.agents.0.agentId' => __('agents.handoff_itself')]);
        $pending = new Agent(['name' => 'Being saved']);
        $pending->workspace_id = $this->acme->id;
        $pending->save();
        $this->change($agent, ['handoff' => $to($pending->id)])->assertStatus(422)->assertJsonValidationErrors(['settings.handoff.agents.0.agentId' => __('agents.not_ready')]);
        $gone = $this->agent('Gone');
        $gone->forceFill(['deleted_at' => now()])->save();
        $this->change($agent, ['handoff' => $to($gone->id)])->assertStatus(422)->assertJsonValidationErrors('settings.handoff.agents.0.agentId');
        $billing = $this->agent('Billing');
        $this->change($agent, ['handoff' => ['agents' => [['agentId' => $billing->id, 'mode' => 'transfer'], ['agentId' => $this->agent('billing')->id, 'mode' => 'connect']]]])->assertStatus(422)->assertJsonValidationErrors(['settings.handoff.agents.1.label' => __('agents.handoff_label_twice')]);
        $this->change($agent, ['handoff' => $to($billing->id, ['mode' => 'teleport'])])->assertStatus(422)->assertJsonValidationErrors('settings.handoff.agents.0.mode');
        $this->assertSame([], $this->saves());
    }

    public function test_deleting_an_agent_takes_it_off_the_others_that_hand_over_to_it(): void
    {
        $front = $this->agent();
        $back = $this->agent('Back desk');
        $billing = $this->agent('Billing');
        $sales = $this->agent('Sales');
        $this->change($front->fresh(), ['handoff' => ['agents' => [['agentId' => $billing->id, 'mode' => 'transfer'], ['agentId' => $sales->id, 'mode' => 'transfer']]]])->assertOk();
        $this->change($back->fresh(), ['handoff' => ['agents' => [['agentId' => $billing->id, 'mode' => 'connect']]]])->assertOk();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$billing->id}")->assertNoContent();
        $this->assertSame([$sales->id], array_column($front->fresh()->settings['handoff']['agents'], 'agentId'));
        $this->assertSame(['Sales'], array_column($this->rows($front)['handoff_to_agent']['config']['agents'], 'label'));
        $this->assertArrayNotHasKey('handoff_to_agent', $this->rows($back));
        $this->assertSame([], $back->fresh()->settings['handoff']['agents']);
    }

    public function test_deleting_an_agent_nobody_hands_over_to_writes_to_no_other(): void
    {
        $front = $this->agent();
        $billing = $this->agent('Billing');
        $this->sent = [];
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$billing->id}")->assertNoContent();
        $this->assertSame([], array_filter($this->sent, fn (array $s) => str_contains($s['url'], $front->telenow_id)));
    }

    public function test_the_recordings_are_what_the_kit_reads_back(): void
    {
        $read = app(AgentSettings::class)->read(RecordedTelenow::body('getAgent.tools')['data']);
        $byName = collect($read['tools'])->keyBy('name');
        $this->assertSame(['add_note', 'book_visit', 'get_order'], $byName->keys()->sort()->values()->all());
        $this->assertSame(['name' => 'book_visit', 'description' => 'Book a visit when the caller asks for one.', 'url' => 'https://example.com/hooks/book', 'request' => null,
            'parameters' => [['name' => 'date', 'type' => 'string', 'description' => 'The day.', 'required' => true], ['name' => 'people', 'type' => 'integer', 'description' => 'How many.', 'required' => false]],
            'tokenHint' => '••••1234', 'sayWhileRunning' => 'Let me check that.', 'timeoutSecs' => 20, 'askFirst' => true], $byName['book_visit']);
        $this->assertSame([['name' => 'Accept', 'value' => 'application/json'], ['name' => 'Authorization', 'value' => '••••5678'], ['name' => 'X-Api-Key', 'value' => '••••9012'], ['name' => 'X-Tenant', 'value' => 'acme']], $byName['get_order']['request']['headers']);
        $this->assertSame([true, false], [$read['canAdjustVolume'], $read['sendCallerToTools']]);
        $this->assertSame('••••3456', $read['lookups'][0]['tokenHint']);
        $this->assertSame([['variable' => 'customer_name', 'path' => 'data.name', 'fallback' => 'there'], ['variable' => 'plan', 'path' => 'data.0.plan', 'fallback' => null]], $read['lookups'][0]['fills']);
        $this->assertStringContainsString('holds a masked key this agent does not hold there', RecordedTelenow::body('updateAgent.key-moved')['error']);
        $this->assertStringContainsString('holds a masked key this agent does not hold there', RecordedTelenow::body('updateAgent.key-above')['error']);
    }

    public function test_what_the_kit_would_send_for_the_recorded_rows_is_what_telenow_keeps_as_the_same_place(): void
    {
        $keys = app(\App\Kit\Agents\SavedKeys::class);
        $tools = RecordedTelenow::body('updateAgent.tools')['data']['metadata']['tools'];
        $this->assertSame(['.endpoint=https://example.com/hooks/book'], $keys->destinations($tools[0]));
        $this->assertSame(['.request.url=https://example.com/orders/{order_id}?who={contact_number}'], $keys->destinations($tools[1]));
        $this->assertSame([], $keys->destinations(['bearer' => 'https://example.com', 'region' => 'us-east-1', 'server' => null, 'text' => 'no address']), 'a key is not a place, nor a bare region, nor nothing');
        $this->assertSame(['.region=a/b', '.server=x', '.text=see http://a.example', '.upstream.baseUrl=z'], $keys->destinations(['region' => 'a/b', 'server' => 'x', 'text' => 'see http://a.example', 'upstream' => ['baseUrl' => 'z', 'bearer' => 'k']]));
        $this->assertSame(['.server.host=z'], $keys->destinations(['server' => ['host' => 'z', 'bearer' => 'k']]), 'a place holding a key is read inside, and never written out with the key');
    }

    public function test_a_row_read_with_its_keys_in_the_clear_is_still_only_answered_as_a_mask(): void
    {
        $read = app(AgentSettings::class)->read(['metadata' => ['tools' => [['name' => 'x', 'endpoint' => 'https://example.com', 'bearer' => self::TOKEN], ['name' => 'y', 'description' => 'd', 'request' => ['url' => 'https://example.com', 'headers' => ['x-api-key' => 'xkey_test_9012abcd', 'Token' => 'short']]]]]]);
        $this->assertSame('••••1234', $read['tools'][0]['tokenHint']);
        $this->assertSame([['name' => 'Token', 'value' => '••••'], ['name' => 'x-api-key', 'value' => '••••abcd']], $read['tools'][1]['request']['headers']);
    }

    public function test_odd_rows_read_as_something_true_and_never_fail_the_page(): void
    {
        $read = app(AgentSettings::class)->read(['metadata' => [
            'tools' => ['junk', ['kind' => 'http'], ['name' => 'loc', 'endpoint' => 'https://example.com', 'handoff' => ['source' => 'One sec.'], 'timeoutSecs' => 'soon', 'requiresConfirmation' => 'yes', 'parameters' => 'none'],
                ['name' => 'odd', 'endpoint' => 'https://example.com', 'parameters' => ['properties' => ['a' => 'not an object', 'b' => ['type' => 3]], 'required' => 'a']]],
            'precallLookups' => ['junk', ['kind' => 'http'], ['kind' => 'http', 'name' => 'l', 'request' => ['url' => 'https://example.com/{a}', 'headers' => ['A' => 3]], 'map' => 'name']],
            'variables' => 'none', 'callerIdentity' => 'yes',
        ]]);
        $this->assertSame(['loc', 'odd'], array_column($read['tools'], 'name'));
        $this->assertSame(['One sec.', null, false, []], [$read['tools'][0]['sayWhileRunning'], $read['tools'][0]['timeoutSecs'], $read['tools'][0]['askFirst'], $read['tools'][0]['parameters']]);
        $this->assertSame(['string', 'string'], array_column($read['tools'][1]['parameters'], 'type'));
        $this->assertSame([['l', 'GET', [], []]], array_map(fn ($l) => [$l['name'], $l['method'], $l['headers'], $l['fills']], $read['lookups']));
        $this->assertFalse($read['sendCallerToTools']);
    }
}

/** @return array<string, mixed> `$row` flattened to dotted paths, for the stand-in above: its own code, none of the kit's. */
function Arr_dot(array $row, string $prefix = ''): array
{
    $flat = [];
    foreach ($row as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        if (is_array($value)) {
            $flat += Arr_dot($value, $path);
        } else {
            $flat[$path] = $value;
        }
    }

    return $flat;
}
