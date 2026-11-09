<?php

namespace Tests\Feature\Kit;

use App\Kit\Routing\RoutingConnection;
use App\Kit\Routing\RoutingMatch;
use App\Models\Agent;
use App\Models\PhoneNumber;
use App\Models\RoutingEndpoint;
use App\Models\RoutingRule;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Call routing (App\Kit\Routing; contract/kit-api.yaml getRouting, createRoutingRule, updateRoutingRule, deleteRoutingRule, orderRoutingRules,
 * tryRouting, checkRouting, setNumberRouting, answerRoutingRequest): an account's rules, kept in the kit and changed by its managers; which rule takes
 * a call (the forwarded-from line as a carrier reports it, how the caller's number starts, days and hours in a timezone); routing a number (the
 * account's endpoint at Telenow registered once, with the secret kept); this kit's answer to Telenow, signed as Telenow signs (its own test vector);
 * whether Telenow asks this kit, and its own test, in the kit's words. Telenow answers as recorded on its live release (contract_k26routing.py).
 */
class InboundRoutingTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '44c23e3e-30f8-502b-9563-b21848107464';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private Agent $front;

    private Agent $late;

    private PhoneNumber $main;

    private PhoneNumber $spare;

    /** @var array<string, list<string|\Closure>> answers queued by "METHOD what" */
    private array $answers = [];

    /** @var list<array{method: string, path: string, body: mixed, key: ?string}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        config(['app.url' => 'https://Kit.Example.com']);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->front = $this->agent('Front desk');
        $this->late = $this->agent('After hours');
        $this->main = $this->number('918045671001', ['agent_id' => $this->front->id]);
        $this->spare = $this->number('918045671002');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'key' => $r->header('X-API-Key')[0] ?? null];
            $what = $r->method().' '.match (true) {
                str_ends_with($path, '/inbound-routing/test') => 'test',
                str_ends_with($path, '/inbound-routing') => 'routing',
                str_ends_with($path, '/pool') => 'pool',
                default => $path,
            };
            $answer = ($this->answers[$what] ?? []) !== [] ? array_shift($this->answers[$what]) : null;
            $answer ??= match ($what) {
                'PUT routing' => 'setInboundRouting.made',
                'POST pool' => fn (Request $r) => $this->numberAnswer($r, $r->data()['isPool'] ? 'setNumberPool.routed' : 'setNumberPool.unrouted'),
                default => throw new \RuntimeException("nothing recorded for {$what}"),
            };

            return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
        });
    }

    private function agent(string $name): Agent
    {
        $a = Agent::create(['workspace_id' => $this->acme->id, 'name' => $name]);
        $a->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();

        return $a;
    }

    private function number(string $e164, array $over = [], ?Workspace $in = null): PhoneNumber
    {
        $n = new PhoneNumber;
        $n->forceFill($over + ['workspace_id' => ($in ?? $this->acme)->id, 'e164' => $e164, 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();

        return $n;
    }

    /** Telenow's recorded number answer, as the number asked about: its id, its digits and its agent by Telenow's id. */
    private function numberAnswer(Request $r, string $name): \GuzzleHttp\Promise\PromiseInterface
    {
        $body = RecordedTelenow::body($name);
        $n = PhoneNumber::query()->where('telenow_id', basename(dirname((string) parse_url($r->url(), PHP_URL_PATH))))->firstOrFail();
        $body['data'] = array_merge($body['data'], ['id' => $n->telenow_id, 'e164' => $n->e164, 'agent_id' => $n->agent?->telenow_id]);

        return Http::response($body, 200);
    }

    private function rule(array $input, ?User $who = null): TestResponse
    {
        return $this->actingAs($who ?? $this->ana)->postJson('/api/kit/console/routing/rules', $input + ['name' => 'A rule', 'action' => 'answer']);
    }

    private function sentTo(string $suffix): array
    {
        return array_values(array_filter($this->sent, fn ($s) => str_ends_with($s['path'], $suffix)));
    }

    /** A request as Telenow signs one (pool_routing.rs `signed_request`): the body, and `t=<unix>,v2=<hex>` over the time, the address and the body. */
    private function ask(RoutingEndpoint $e, array $call, array $over = []): TestResponse
    {
        $raw = json_encode($call, JSON_UNESCAPED_SLASHES);
        $t = $over['t'] ?? now()->getTimestamp();
        $sig = 't='.$t.',v2='.hash_hmac('sha256', "v2\n{$t}\n".($over['url'] ?? $e->url)."\n".$raw, $over['secret'] ?? $e->secret);
        $headers = ['CONTENT_TYPE' => 'application/json'] + (($over['v1'] ?? false)
            ? ['HTTP_X_TELENOW_SIGNATURE' => hash_hmac('sha256', $raw, $e->secret)]
            : ['HTTP_X_TELENOW_SIGNATURE_V2' => $sig]);

        return $this->call('POST', '/api/kit/telenow/routing/'.($over['token'] ?? $e->token), [], [], [], $headers, $raw);
    }

    private function endpoint(?Workspace $for = null, string $secret = 'ir_secret_kitTestSecret'): RoutingEndpoint
    {
        $e = new RoutingEndpoint;
        $e->forceFill(['workspace_id' => ($for ?? $this->acme)->id, 'token' => Str::lower(Str::random(40)), 'secret' => $secret, 'registered_at' => now()]);
        $e->url = app(RoutingConnection::class)->url($e);
        $e->save();

        return $e;
    }

    public function test_the_rules_are_the_managers_and_kept_in_the_order_they_set(): void
    {
        $a = $this->rule(['name' => 'Spam callers', 'action' => 'refuse', 'callerStartsWith' => ['+1555']])->assertCreated()->json();
        $b = $this->rule(['name' => 'Evenings', 'agentId' => $this->late->id, 'days' => [5, 1, 3], 'from' => '18:00', 'until' => '09:00', 'timezone' => 'Asia/Kolkata'])->assertCreated()->json();
        $c = $this->rule(['name' => 'Everyone else', 'numberIds' => [$this->main->id]])->assertCreated()->json();
        $this->assertSame([1, 3, 5], $b['days'], 'days kept in week order');
        $this->assertSame(['id' => $this->late->id, 'name' => 'After hours'], $b['agent']);
        $this->rule(['name' => 'Not mine'], $this->ben)->assertForbidden()->assertJsonPath('message', __('routing.managers_only'));
        $seen = $this->actingAs($this->ben)->getJson('/api/kit/console/routing')->assertOk();
        $this->assertSame([$a['id'], $b['id'], $c['id']], array_column($seen->json('rules'), 'id'), 'every member sees them, in order');
        $seen->assertJsonPath('canManage', false)->assertJsonPath('connection', null)->assertJsonPath('numbers.0.routed', false)
            ->assertJsonPath('limits.greetingMaxLength', 500);
        $this->actingAs($this->ana)->getJson('/api/kit/console/routing')->assertJsonPath('canManage', true);

        $this->actingAs($this->ana)->putJson('/api/kit/console/routing/order', ['ids' => [$c['id'], $a['id'], $b['id']]])->assertOk()
            ->assertJsonPath('rules.0.id', $c['id'])->assertJsonPath('rules.2.id', $b['id']);
        $this->actingAs($this->ana)->putJson('/api/kit/console/routing/order', ['ids' => [$c['id'], $a['id']]])->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->actingAs($this->ana)->putJson('/api/kit/console/routing/order', ['ids' => [$c['id'], $a['id'], $a['id']]])->assertUnprocessable();
        $this->actingAs($this->ben)->putJson('/api/kit/console/routing/order', ['ids' => [$a['id'], $b['id'], $c['id']]])->assertForbidden();
        $this->actingAs($this->ben)->putJson('/api/kit/console/routing/order', ['ids' => 'not a list'])->assertForbidden();
        $this->actingAs($this->ben)->postJson('/api/kit/console/routing/check', [])->assertForbidden();
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/routing", [])->assertForbidden();

        // A number released after a rule took it stays on the rule until taken off; a released one is not added.
        $this->main->forceFill(['released_at' => now()])->save();
        $this->actingAs($this->ana)->patchJson("/api/kit/console/routing/rules/{$c['id']}", ['numberIds' => [$this->main->id, $this->spare->id]])->assertOk()
            ->assertJsonPath('numberIds', [$this->main->id, $this->spare->id]);
        $this->actingAs($this->ana)->patchJson("/api/kit/console/routing/rules/{$b['id']}", ['numberIds' => [$this->main->id]])->assertUnprocessable()
            ->assertJsonPath('errors', ['numberIds' => [__('routing.unknown_number')]]);
        $this->main->forceFill(['released_at' => null])->save();

        $this->actingAs($this->ana)->patchJson("/api/kit/console/routing/rules/{$b['id']}", ['enabled' => false])->assertOk()
            ->assertJsonPath('enabled', false)->assertJsonPath('from', '18:00')->assertJsonPath('agent.id', $this->late->id);
        $this->actingAs($this->ana)->patchJson("/api/kit/console/routing/rules/{$a['id']}", ['action' => 'answer', 'agentId' => $this->front->id, 'greeting' => 'Hello'])->assertOk()
            ->assertJsonPath('action', 'answer')->assertJsonPath('greeting', 'Hello')->assertJsonPath('callerStartsWith', ['+1555']);
        $this->actingAs($this->ana)->patchJson("/api/kit/console/routing/rules/{$a['id']}", ['action' => 'refuse'])->assertOk()
            ->assertJsonPath('agent', null)->assertJsonPath('greeting', null)->assertJsonPath('maxMinutes', null);
        $this->actingAs($this->ben)->deleteJson("/api/kit/console/routing/rules/{$a['id']}")->assertForbidden();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/routing/rules/{$a['id']}")->assertNoContent();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/routing/rules/{$a['id']}")->assertNotFound();

        $theirs = Workspace::create(['name' => 'Bright Smiles', 'status' => 'active', 'sync_state' => 'synced']);
        $foreign = (new RoutingRule)->forceFill(['workspace_id' => $theirs->id, 'position' => 1, 'name' => 'Theirs', 'action' => 'refuse',
            'numbers' => [], 'forwarded_from' => [], 'caller_starts' => [], 'days' => []]);
        $foreign->save();
        $this->actingAs($this->ana)->patchJson("/api/kit/console/routing/rules/{$foreign->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/routing/rules/{$foreign->id}")->assertNotFound();
        $this->assertSame('Theirs', $foreign->fresh()->name);

        config(['kit.routing.rules_max' => 2]);
        $this->rule(['name' => 'One too many'])->assertStatus(409)->assertJsonPath('message', __('routing.too_many_rules', ['max' => 2]));
        $this->assertSame([], $this->sent, 'rules never reach Telenow');
    }

    public function test_what_a_rule_may_hold(): void
    {
        $this->rule(['forwardedFrom' => ['09876543210']])->assertUnprocessable()->assertJsonPath('errors', ['forwardedFrom.0' => [__('routing.phone_shape')]]);
        $this->rule(['callerStartsWith' => ['44']])->assertUnprocessable()->assertJsonPath('errors', ['callerStartsWith.0' => [__('routing.start_shape')]]);
        $this->rule(['callerStartsWith' => ['+44', '+44']])->assertUnprocessable()->assertJsonValidationErrors('callerStartsWith.0');
        $this->rule(['language' => 'english please'])->assertUnprocessable()->assertJsonPath('errors', ['language' => [__('routing.language_shape')]]);
        $this->rule(['greeting' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('greeting');
        $this->rule(['maxMinutes' => 241])->assertUnprocessable()->assertJsonValidationErrors('maxMinutes');
        $this->rule(['maxMinutes' => 0])->assertUnprocessable()->assertJsonValidationErrors('maxMinutes');
        $this->rule(['action' => 'transfer'])->assertUnprocessable()->assertJsonValidationErrors('action');
        $this->rule(['days' => [0]])->assertUnprocessable()->assertJsonPath('errors', ['days.0' => ['The selected day is invalid.']]);
        $this->rule(['from' => '09:00', 'timezone' => 'UTC'])->assertUnprocessable()->assertJsonPath('errors', ['until' => [__('routing.window_both')]]);
        $this->rule(['from' => '09:00', 'until' => '09:00', 'timezone' => 'UTC'])->assertUnprocessable()->assertJsonPath('errors', ['until' => [__('routing.window_empty')]]);
        $this->rule(['days' => [1]])->assertUnprocessable()->assertJsonPath('errors', ['timezone' => [__('routing.timezone_needed')]]);
        $this->rule(['from' => '09:00', 'until' => '17:00', 'timezone' => 'Mars/Olympus'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        // A browser's own timezone can be an older name (Chrome reports Asia/Calcutta): taken, and the hours read in it.
        $calcutta = $this->rule(['name' => 'Older name', 'from' => '09:00', 'until' => '17:00', 'timezone' => 'Asia/Calcutta'])->assertCreated()->json('id');
        $this->assertTrue(RoutingMatch::inHours(RoutingRule::query()->findOrFail($calcutta), CarbonImmutable::parse('2026-11-09 10:00', 'Asia/Kolkata')));
        RoutingRule::query()->whereKey($calcutta)->delete();
        $theirs = Workspace::create(['name' => 'Bright Smiles', 'status' => 'active', 'sync_state' => 'synced']);
        $this->rule(['numberIds' => [$this->number('918045671009', [], $theirs)->id]])->assertUnprocessable()->assertJsonPath('errors', ['numberIds' => [__('routing.unknown_number')]]);
        $buying = $this->number('918045671008', ['telenow_id' => null, 'sync_state' => 'pending']);
        $this->rule(['numberIds' => [$buying->id]])->assertUnprocessable()->assertJsonPath('errors', ['numberIds' => [__('routing.unknown_number')]]);
        $gone = $this->agent('Gone');
        $gone->forceFill(['deleted_at' => now()])->save();
        $this->rule(['agentId' => $gone->id])->assertUnprocessable()->assertJsonPath('errors', ['agentId' => [__('routing.unknown_agent')]]);
        $draft = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Draft']);
        $this->rule(['agentId' => $draft->id])->assertUnprocessable()->assertJsonValidationErrors('agentId');
        config(['kit.routing.numbers_per_rule' => 2]);
        $this->rule(['forwardedFrom' => ['+14155550101', '+14155550102', '+14155550103']])->assertUnprocessable()->assertJsonValidationErrors('forwardedFrom');
        $this->assertSame(0, RoutingRule::query()->count());

        $made = $this->rule(['action' => 'refuse', 'agentId' => $this->late->id, 'language' => 'hi-IN', 'greeting' => 'Namaste', 'maxMinutes' => 5])->assertCreated();
        $made->assertJsonPath('agent', null)->assertJsonPath('language', null)->assertJsonPath('greeting', null)->assertJsonPath('maxMinutes', null)
            ->assertJsonPath('enabled', true)->assertJsonPath('numberIds', [])->assertJsonPath('days', []);
        $this->rule(['name' => 'Hindi line', 'forwardedFrom' => ['+919876543210'], 'callerStartsWith' => ['+91'], 'language' => 'hi-IN', 'greeting' => '  ', 'maxMinutes' => 240])
            ->assertCreated()->assertJsonPath('greeting', null)->assertJsonPath('language', 'hi-IN')->assertJsonPath('maxMinutes', 240);
    }

    public function test_which_rule_takes_a_call(): void
    {
        // The forwarded-from line as carriers report it (pool_routing.rs: all four seen from Indian networks), and what is not the same line.
        foreach (['+919876543210', '919876543210', '09876543210', '9876543210', '00919876543210', ' +91 98765-43210 '] as $said) {
            $this->assertTrue(RoutingMatch::sameLine('+919876543210', $said), $said);
        }
        foreach ([null, '', '543210', '76543210', '+919876543211', '+449876543210', '19876543210x0'] as $said) {
            $this->assertFalse(RoutingMatch::sameLine('+919876543210', $said), (string) $said);
        }
        $this->assertTrue(RoutingMatch::sameLine('+14155550123', '4155550123'), 'a 1-digit country code left out');
        $this->assertTrue(RoutingMatch::sameLine('+14155550123', '55550123'), 'up to 3 digits may be left out: a country code\'s length is not known');
        $this->assertFalse(RoutingMatch::sameLine('+14155550123', '5550123'), 'more digits left out than any country code has');
        $this->assertFalse(RoutingMatch::sameLine('+79161234567', '89161234567'), 'a trunk prefix other than 0 is not read');
        $this->assertTrue(RoutingMatch::startsWith('+441632960961', '+44'));
        $this->assertTrue(RoutingMatch::startsWith('+441632960961', '+441632960961'), 'a whole number is its own start');
        $this->assertFalse(RoutingMatch::startsWith('+14415550100', '+44'));
        $this->assertFalse(RoutingMatch::startsWith(null, '+44'), 'a withheld number starts with nothing');

        $rule = fn (array $over) => (new RoutingRule)->forceFill($over + ['enabled' => true, 'action' => 'answer', 'numbers' => [], 'forwarded_from' => [],
            'caller_starts' => [], 'days' => [], 'from_time' => null, 'until_time' => null, 'timezone' => null]);
        $at = fn (string $local, string $tz = 'Asia/Kolkata') => CarbonImmutable::parse($local, $tz)->utc();
        // Friday 18:00 until 09:00 in Kolkata: Friday evening, and Saturday early morning as Friday's; not Friday early morning.
        $evenings = $rule(['days' => [5], 'from_time' => '18:00', 'until_time' => '09:00', 'timezone' => 'Asia/Kolkata']);
        $this->assertTrue(RoutingMatch::inHours($evenings, $at('2026-11-13 18:00')), 'Friday at the start');
        $this->assertTrue(RoutingMatch::inHours($evenings, $at('2026-11-14 08:59')), 'Saturday morning belongs to Friday');
        $this->assertFalse(RoutingMatch::inHours($evenings, $at('2026-11-14 09:00')), 'the end is not included');
        $this->assertFalse(RoutingMatch::inHours($evenings, $at('2026-11-13 08:00')), 'Friday morning belongs to Thursday');
        $this->assertFalse(RoutingMatch::inHours($evenings, $at('2026-11-14 18:30')), 'Saturday evening');
        $office = $rule(['days' => [1, 2, 3, 4, 5], 'from_time' => '09:00', 'until_time' => '17:30', 'timezone' => 'America/New_York']);
        $this->assertTrue(RoutingMatch::inHours($office, $at('2026-11-09 17:29', 'America/New_York')));
        $this->assertFalse(RoutingMatch::inHours($office, $at('2026-11-09 17:30', 'America/New_York')));
        $this->assertFalse(RoutingMatch::inHours($office, $at('2026-11-09 12:00', 'Asia/Kolkata')), 'in the rule\'s timezone, not the server\'s');
        $this->assertTrue(RoutingMatch::inHours($rule(['days' => [7], 'timezone' => 'UTC']), $at('2026-11-08 23:00', 'UTC')), 'days alone');
        $this->assertTrue(RoutingMatch::inHours($rule([]), $at('2026-11-08 03:00')), 'no days and no hours: any time');

        $now = $at('2026-11-09 12:00', 'UTC');
        $off = $rule(['name' => 'off', 'enabled' => false]);
        $other = $rule(['name' => 'other number', 'numbers' => [$this->spare->id]]);
        $line = $rule(['name' => 'line', 'forwarded_from' => ['+14155550188', '+919876543210'], 'caller_starts' => ['+1']]);
        $any = $rule(['name' => 'any']);
        $first = fn (?string $caller, ?string $fwd) => RoutingMatch::first([$off, $other, $line, $any], $this->main->id, $caller, $fwd, $now)?->name;
        $this->assertSame('line', $first('+14155550123', '09876543210'), 'every condition holds');
        $this->assertSame('any', $first('+447700900123', '09876543210'), 'one condition fails: the next rule');
        $this->assertSame('any', $first('+14155550123', null), 'not forwarded');
        $this->assertNull(RoutingMatch::first([$off, $other], $this->main->id, null, null, $now), 'an off rule never takes a call');
    }

    public function test_routing_a_number_connects_the_account_once_and_keeps_its_agent(): void
    {
        config(['kit.routing.timeout_ms' => 1500]);
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->spare->id}/routing", ['routed' => true])
            ->assertStatus(409)->assertJsonPath('message', __('numbers.routing_needs_agent'));
        $this->actingAs($this->ben)->putJson("/api/kit/console/numbers/{$this->main->id}/routing", ['routed' => true])->assertForbidden();
        $gone = $this->agent('Gone');
        $this->spare->forceFill(['agent_id' => $gone->id])->save();
        $gone->forceFill(['deleted_at' => now()])->save();
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->spare->id}/routing", ['routed' => true])
            ->assertStatus(409)->assertJsonPath('message', __('numbers.routing_needs_agent'));
        $this->spare->forceFill(['agent_id' => null])->save();
        $this->assertSame([], $this->sent);

        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->main->id}/routing", ['routed' => true])->assertOk()
            ->assertJsonPath('routed', true)->assertJsonPath('agent.id', $this->front->id)->assertJsonPath('syncState', 'synced');
        $endpoint = RoutingEndpoint::query()->findOrFail($this->acme->id);
        $this->assertSame('https://kit.example.com/api/kit/telenow/routing/'.$endpoint->token, $endpoint->url, 'the address as Telenow signs it');
        $this->assertMatchesRegularExpression('/^[a-z0-9]{40}$/', $endpoint->token);
        $this->assertSame(RecordedTelenow::body('setInboundRouting.made')['data']['signingSecret'], $endpoint->secret, 'the secret Telenow made, kept');
        $this->assertNotSame(RecordedTelenow::body('setInboundRouting.made')['data']['signingSecret'], RoutingEndpoint::query()->toBase()->value('secret'), 'encrypted');
        $put = $this->sentTo('/inbound-routing');
        $this->assertSame([['PUT', '/api/orgs/'.self::ORG.'/inbound-routing', ['url' => $endpoint->url, 'timeoutMs' => 1500, 'enabled' => true, 'rotateSecret' => true], self::KEY]],
            array_map(fn ($s) => [$s['method'], $s['path'], $s['body'], $s['key']], $put));
        $this->assertSame([['isPool' => true]], array_column($this->sentTo('/pool'), 'body'));

        // Routed again (another number, say): already registered, nothing asked of Telenow but the number.
        $this->late->refresh();
        $this->spare->forceFill(['agent_id' => $this->late->id])->save();
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->spare->id}/routing", ['routed' => true])->assertOk()->assertJsonPath('routed', true);
        $this->assertCount(1, $this->sentTo('/inbound-routing'));

        // A routed number keeps its agent; another agent is fine.
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->main->id}/agent", ['agentId' => null])
            ->assertStatus(409)->assertJsonPath('message', __('numbers.routed_needs_agent'));
        $this->assertCount(0, $this->sentTo('/agent'));

        // This kit's address changed: registered again, the secret kept (Telenow shows none when it makes none).
        config(['app.url' => 'https://voice.example.org']);
        $this->answers['PUT routing'] = ['setInboundRouting.again'];
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->main->id}/routing", ['routed' => true])->assertOk();
        $again = $endpoint->fresh();
        $this->assertSame('https://voice.example.org/api/kit/telenow/routing/'.$endpoint->token, $again->url);
        $this->assertSame($endpoint->secret, $again->secret);
        $this->assertFalse($this->sentTo('/inbound-routing')[1]['body']['rotateSecret'], 'a secret held is not asked for again');

        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->main->id}/routing", ['routed' => false])->assertOk()->assertJsonPath('routed', false);
        $this->assertSame(['isPool' => false], end($this->sent)['body']);
        $listed = collect($this->actingAs($this->ana)->getJson('/api/kit/console/numbers')->assertOk()->json('numbers'))->pluck('routed', 'id')->all();
        $this->assertSame([$this->main->id => false, $this->spare->id => true], [$this->main->id => $listed[$this->main->id], $this->spare->id => $listed[$this->spare->id]]);
    }

    public function test_a_kit_the_platform_cannot_reach_cannot_route(): void
    {
        $this->answers['PUT routing'] = ['setInboundRouting.http'];
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->main->id}/routing", ['routed' => true])
            ->assertStatus(409)->assertJsonPath('message', __('routing.address_not_public'));
        $this->assertFalse($this->main->fresh()->routed);
        $this->assertCount(0, $this->sentTo('/pool'));
        $this->assertNull(RoutingEndpoint::query()->find($this->acme->id)->registered_at);
        $this->answers['PUT routing'] = [fn () => throw new ConnectionException('down')];
        $this->actingAs($this->ana)->putJson("/api/kit/console/numbers/{$this->main->id}/routing", ['routed' => true])
            ->assertStatus(502)->assertJsonPath('message', __('routing.platform_unreachable'));
    }

    public function test_this_kit_answers_telenow_from_the_rules(): void
    {
        $e = $this->endpoint();
        $this->main->forceFill(['routed' => true])->save();
        $call = fn (array $over = []) => $over + ['to_number' => '+918045671001', 'caller_number' => '+14155550123', 'forwarded_from' => null, 'carrier' => 'plivo', 'call_id' => 'c-1'];
        $this->ask($e, $call())->assertOk()->assertContent('{}');

        $this->rule(['name' => 'Off', 'action' => 'refuse', 'enabled' => false])->assertCreated();
        $this->rule(['name' => 'Spam', 'action' => 'refuse', 'callerStartsWith' => ['+1555']])->assertCreated();
        $this->rule(['name' => 'Hindi line', 'agentId' => $this->late->id, 'forwardedFrom' => ['+919876543210'], 'language' => 'hi-IN', 'greeting' => 'Namaste {name}', 'maxMinutes' => 10])->assertCreated();
        $this->rule(['name' => 'Night', 'numberIds' => [$this->main->id], 'from' => '22:00', 'until' => '06:00', 'timezone' => 'UTC'])->assertCreated();

        $this->ask($e, $call(['caller_number' => '+15550001111']))->assertOk()->assertExactJson(['action' => 'reject']);
        $this->ask($e, $call(['forwarded_from' => '09876543210']))->assertOk()->assertExactJson(['action' => 'route', 'agent_id' => $this->late->telenow_id,
            'overrides' => ['language' => 'hi-IN', 'opener' => 'Namaste {name}'], 'limits' => ['max_duration_secs' => 600]]);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 23:15', 'UTC'));
        $this->ask($e, $call())->assertOk()->assertExactJson(['action' => 'route']);
        $this->travelTo(CarbonImmutable::parse('2026-11-09 12:00', 'UTC'));
        $this->ask($e, $call())->assertOk()->assertContent('{}');
        $this->ask($e, $call(['to_number' => '+14155550199']))->assertOk()->assertContent('{}');
        $this->ask($e, $call(['to_number' => '918045671001', 'forwarded_from' => '+919876543210']))->assertOk()->assertJsonPath('agent_id', $this->late->telenow_id);
        $this->late->forceFill(['deleted_at' => now()])->save();
        $this->ask($e, $call(['forwarded_from' => '9876543210']))->assertOk()->assertExactJson(['action' => 'route',
            'overrides' => ['language' => 'hi-IN', 'opener' => 'Namaste {name}'], 'limits' => ['max_duration_secs' => 600]]);

        // Only Telenow's timed signature, for this account's address and secret, recent.
        $this->ask($e, $call(), ['v1' => true])->assertUnauthorized();
        $this->ask($e, $call(), ['t' => now()->getTimestamp() - 301])->assertUnauthorized();
        $this->ask($e, $call(), ['t' => now()->getTimestamp() + 301])->assertUnauthorized();
        $this->ask($e, $call(), ['t' => now()->getTimestamp() - 299])->assertOk();
        $this->ask($e, $call(), ['url' => 'https://kit.example.com/api/kit/telenow/routing/someone-else'])->assertUnauthorized();
        $this->ask($e, $call(), ['secret' => 'ir_secret_guessed'])->assertUnauthorized();
        $theirs = $this->endpoint(Workspace::create(['name' => 'Bright Smiles', 'status' => 'active', 'sync_state' => 'synced']), 'ir_secret_theirs');
        $this->ask($e, $call(), ['token' => $theirs->token])->assertUnauthorized();
        $this->ask($theirs, $call(['caller_number' => '+15550001111']))->assertOk()->assertContent('{}'); // another account's rules are not theirs
        $this->ask($e, $call(), ['token' => str_repeat('a', 40)])->assertUnauthorized();
    }

    public function test_the_signature_is_the_one_telenow_makes(): void
    {
        // pool_routing.rs's own vector ("worked out separately with openssl"), and its cases for the address it signs.
        $e = new RoutingEndpoint;
        $e->forceFill(['workspace_id' => $this->acme->id, 'token' => 'vectortoken0000000000', 'secret' => 'ir_secret_vector', 'url' => 'https://api.example.test/internal/precall', 'registered_at' => now()])->save();
        $this->travelTo(CarbonImmutable::createFromTimestamp(1_762_250_000));
        $body = '{"to_number":"+918000000001","carrier":"plivo"}';
        $verify = fn (string $header) => app(RoutingConnection::class)->verify('vectortoken0000000000', $header, $body);
        $this->assertNotNull($verify('t=1762250000,v2=ea8d7244bfcd421018c16b4563e6bbbe113bf25b93afd8513cf74ec94a93699a'));
        $this->assertNull($verify('t=1762250000,v2=ea8d7244bfcd421018c16b4563e6bbbe113bf25b93afd8513cf74ec94a93699b'));
        $this->assertNull($verify('t=1762250001,v2=ea8d7244bfcd421018c16b4563e6bbbe113bf25b93afd8513cf74ec94a93699a'));
        foreach (['https://api.example.test/internal/precall' => 'https://api.example.test/internal/precall',
            'https://API.Example.test/internal/precall?token=1#x' => 'https://api.example.test/internal/precall',
            'https://api.example.test:443/hook' => 'https://api.example.test/hook',
            'https://api.example.test:8443/hook' => 'https://api.example.test:8443/hook',
            'https://api.example.test' => 'https://api.example.test/'] as $saved => $signed) {
            $this->assertSame($signed, RoutingConnection::signedUrl($saved), $saved);
        }
    }

    public function test_whether_telenow_asks_this_kit_and_its_own_test_in_the_kits_words(): void
    {
        $e = $this->endpoint();
        $this->main->forceFill(['routed' => true])->save();
        $as = fn (string $name) => function () use ($name, $e) {
            $body = RecordedTelenow::body($name);
            $body['data']['url'] = $e->url;

            return Http::response($body, 200);
        };
        $this->answers['GET routing'] = [$as('getInboundRouting.last-error'), $as('getInboundRouting.off'), 'getInboundRouting.last-error', 'getInboundRouting.none',
            fn () => throw new ConnectionException('down')];
        $recorded = RecordedTelenow::body('getInboundRouting.last-error')['data'];
        $seen = $this->actingAs($this->ben)->getJson('/api/kit/console/routing')->assertOk()
            ->assertJsonPath('connection', ['state' => 'connected', 'lastAnsweredAt' => $recorded['lastOkAt'], 'problem' => 'refused', 'problemAt' => $recorded['lastErrorAt']]);
        $this->assertStringContainsString('404 Not Found', $recorded['lastError']);
        $this->assertStringNotContainsString('Not Found', (string) $seen->getContent(), 'never Telenow\'s words');
        $this->assertSame(['GET', '/api/orgs/'.self::ORG.'/inbound-routing', self::KEY], [end($this->sent)['method'], end($this->sent)['path'], end($this->sent)['key']]);
        $this->actingAs($this->ben)->getJson('/api/kit/console/routing')->assertJsonPath('connection.state', 'off');
        $this->actingAs($this->ben)->getJson('/api/kit/console/routing')->assertJsonPath('connection.state', 'elsewhere');
        $this->actingAs($this->ben)->getJson('/api/kit/console/routing')->assertJsonPath('connection.state', 'not_connected');
        $this->actingAs($this->ben)->getJson('/api/kit/console/routing')->assertOk()->assertJsonPath('connection.state', 'unknown');

        $this->answers['POST test'] = ['testInboundRouting.answered', 'testInboundRouting.timed-out', 'testInboundRouting.status', 'testInboundRouting.not-routed'];
        $check = fn (?User $who = null, ?PhoneNumber $n = null) => $this->actingAs($who ?? $this->ana)->postJson('/api/kit/console/routing/check', ['numberId' => ($n ?? $this->main)->id]);
        $check()->assertOk()->assertExactJson(['answered' => true, 'elapsedMs' => 222, 'action' => 'answer', 'problem' => null]);
        $this->assertSame(['toNumber' => '+918045671001'], end($this->sent)['body']);
        $check()->assertOk()->assertExactJson(['answered' => false, 'elapsedMs' => 202, 'action' => null, 'problem' => 'slow']);
        $check()->assertOk()->assertJsonPath('problem', 'refused');
        $check()->assertStatus(409)->assertJsonPath('message', __('routing.number_not_routed'));
        $sent = count($this->sent);
        $check(null, $this->spare)->assertStatus(409)->assertJsonPath('message', __('routing.number_not_routed'));
        $check($this->ben)->assertForbidden();
        $this->assertCount($sent, $this->sent, 'neither reached Telenow');

        // Every reason recorded on the release, in the kit's words.
        foreach (['testInboundRouting.off' => 'off', 'testInboundRouting.none' => 'not_connected', 'testInboundRouting.timed-out' => 'slow',
            'testInboundRouting.status' => 'refused', 'testInboundRouting.not-there' => 'refused', 'testInboundRouting.answered' => null] as $name => $problem) {
            $this->assertSame($problem, RoutingConnection::problem(RecordedTelenow::body($name)['data']['reason']), $name);
        }
        $this->assertSame('unreachable', RoutingConnection::problem('request failed: error sending request'));
        $this->assertSame('bad_answer', RoutingConnection::problem('answer refused: agent x does not belong to org y'));
        $this->assertSame('other', RoutingConnection::problem('something new'));
    }

    public function test_a_pretend_call_says_which_rule_takes_it(): void
    {
        $this->rule(['name' => 'Spam', 'action' => 'refuse', 'callerStartsWith' => ['+1555']])->assertCreated();
        $hindi = $this->rule(['name' => 'Hindi line', 'agentId' => $this->late->id, 'forwardedFrom' => ['+919876543210'], 'language' => 'hi-IN', 'maxMinutes' => 10])->json();
        $this->rule(['name' => 'Weekends', 'days' => [6, 7], 'timezone' => 'UTC', 'greeting' => 'We are closed'])->assertCreated();
        $try = fn (array $body) => $this->actingAs($this->ben)->postJson('/api/kit/console/routing/try', $body + ['numberId' => $this->main->id]);
        $try(['forwardedFrom' => '09876543210', 'at' => '2026-11-09T10:00:00Z'])->assertOk()->assertExactJson(['rule' => ['id' => $hindi['id'], 'name' => 'Hindi line'],
            'outcome' => 'answer', 'agent' => ['id' => $this->late->id, 'name' => 'After hours'], 'language' => 'hi-IN', 'greeting' => null, 'maxMinutes' => 10, 'routed' => false]);
        $try(['callerNumber' => '+15550001111', 'at' => '2026-11-09T10:00:00Z'])->assertOk()->assertJsonPath('outcome', 'refuse')->assertJsonPath('agent', null);
        $try(['at' => '2026-11-08T10:00:00Z'])->assertOk()->assertJsonPath('rule.name', 'Weekends')->assertJsonPath('agent.id', $this->front->id)->assertJsonPath('greeting', 'We are closed');
        $try(['at' => '2026-11-09T10:00:00Z'])->assertOk()->assertJsonPath('rule', null)->assertJsonPath('outcome', 'own_agent')->assertJsonPath('agent.id', $this->front->id);
        $try(['numberId' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('numberId');
        $this->assertSame([], $this->sent, 'a pretend call asks no one');
    }
}
