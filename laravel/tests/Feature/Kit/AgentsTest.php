<?php

namespace Tests\Feature\Kit;

use App\Kit\Catalog\Catalog;
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
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Console → Agents: a customer's prompt agents, made, changed and deleted at Telenow with the customer's OWN key
 * (its sub-account's) through the outbox — only what changed is sent, Telenow's answer is what the agent then says —
 * and the browser test call, whose session reaches the console without any credential or Telenow id. Telenow answers
 * from real recordings (contract/telenow-mock/cassettes, made by the box's contract_k1agents.py).
 */
class AgentsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private User $ana;

    private Workspace $acme;

    /** The agent as Telenow stored it (createAgent's recording). */
    private array $telenow;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        // Telenow recommends no speech providers for this account (its recording answers ''), so the operator names them.
        app(Settings::class)->put('agents.defaults', ['stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy']);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = $this->account('Acme Dental', self::KEY);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->telenow = RecordedTelenow::body('createAgent.customer')['data'];
        RecordedTelenow::fake();
    }

    private function account(string $name, ?string $key): Workspace
    {
        $w = Workspace::create(['name' => $name, 'status' => 'active', 'sync_state' => 'synced']);
        $w->forceFill(['telenow_sub_account_id' => $key === null ? null : (string) \Illuminate\Support\Str::uuid(), 'telenow_key' => $key])->save();

        return $w;
    }

    private function make(array $input = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $input + [
            'name' => 'Front desk',
            'description' => 'Books appointments',
            'systemPrompt' => 'You are the front desk of Acme Dental. Book appointments politely.',
        ]);
    }

    /** Telenow answers with `$first` where it says something, and from the recordings otherwise. */
    private function answering(\Closure $first): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $first($r) ?? $recorded($r));
    }

    private static function is(Request $r, string $method, string $path): bool
    {
        return $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path;
    }

    /** @return list<Request> the requests sent to Telenow as `$method $path` */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => self::is($r, $method, $path))->map(fn (array $pair) => $pair[0])->values()->all();
    }

    public function test_a_member_makes_an_agent_at_telenow_with_the_customers_own_key(): void
    {
        $made = $this->make()->assertCreated();
        $id = $made->json('id');
        $made->assertExactJson([
            'id' => $id,
            'name' => 'Front desk',
            'description' => 'Books appointments',
            'systemPrompt' => 'You are the front desk of Acme Dental. Book appointments politely.',
            'isActive' => true,
            // Telenow's provider ids under the kit's own keys; no names while the operator offers none of them
            'stack' => [
                'llmProvider' => Catalog::publicKey('llm', $this->telenow['llm_provider']), 'llmModel' => $this->telenow['llm_model'],
                'sttProvider' => Catalog::publicKey('stt', $this->telenow['stt_provider']), 'ttsProvider' => Catalog::publicKey('tts', $this->telenow['tts_provider']),
                'ttsVoice' => $this->telenow['tts_voice'],
            ],
            'stackNames' => ['llmProvider' => null, 'llmModel' => null, 'sttProvider' => null, 'ttsProvider' => null, 'ttsVoice' => null],
            'settings' => $made->json('settings'),
            'stackCheck' => null,
            'knowledgeBases' => [],
            'syncState' => 'synced',
            'syncError' => null,
            'createdAt' => $made->json('createdAt'),
            'updatedAt' => $made->json('updatedAt'),
        ]);
        $this->assertStringNotContainsString($this->telenow['id'], $made->getContent());
        $this->assertSame($this->telenow['id'], Agent::find($id)->telenow_id);

        [$create] = $this->sent('POST', '/api/agents');
        $this->assertSame([self::KEY], $create->header('X-API-Key'));
        $this->assertSame([$id], $create->header('Idempotency-Key'), 'a retried create cannot make two agents');
        $this->assertEquals([
            'name' => 'Front desk',
            'description' => 'Books appointments',
            'systemPrompt' => 'You are the front desk of Acme Dental. Book appointments politely.',
            'telephonyConfig' => ['firstResponse' => 'none', 'agentMsg' => '', 'firstResponseOutbound' => 'none', 'agentMsgOutbound' => '', 'recording' => true],
            'sessionConfig' => ['maxDuration' => 600, 'silenceHangupSecs' => 15, 'silenceCheckin' => ['enabled' => true, 'secs' => 10],
                'behavior' => ['speechNormalization' => true, 'smartMatching' => true, 'scopeBoundaries' => true], 'backchannel' => ['enabled' => true], 'contextMode' => 'summary', 'contextWindow' => 10],
            'metadata' => ['kitId' => $id, 'tools' => [['name' => 'end_call', 'kind' => 'end_call', 'description' => config('kit.agents.tool_descriptions.end_call')]]],
            // the operator's choices first, Telenow's recommendation for the rest
            'llmProvider' => 'openai', 'llmModel' => 'gpt-5.4-mini',
            'sttProvider' => 'deepgram', 'ttsProvider' => 'openai', 'ttsVoice' => 'alloy',
        ], json_decode($create->body(), true));
        $this->assertStringContainsString('"sessionConfig":{"', $create->body(), 'Telenow is sent an object, never []');

        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$id}")->assertOk()->assertJsonPath('name', 'Front desk');
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('agents.0.id', $id)->assertJsonPath('perPage', config('kit.agents.per_page'));
    }

    public function test_a_change_sends_only_what_changed(): void
    {
        $id = $this->make()->json('id');
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$id}", [
            'name' => 'Front desk (evenings)',
            'description' => 'Books appointments', // unchanged
            'systemPrompt' => 'You are the evening front desk.',
            'isActive' => false,
        ])->assertOk()->assertJson(['name' => 'Front desk (evenings)', 'isActive' => false, 'syncState' => 'synced']);

        [$put] = $this->sent('PUT', "/api/agents/{$this->telenow['id']}");
        $this->assertSame([self::KEY], $put->header('X-API-Key'));
        $this->assertEquals(['name' => 'Front desk (evenings)', 'systemPrompt' => 'You are the evening front desk.', 'isActive' => false],
            json_decode($put->body(), true));
        $this->assertSame(RecordedTelenow::body('updateAgent.customer')['version'], Agent::find($id)->telenow_version);

        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$id}", ['name' => 'Front desk (evenings)', 'isActive' => 0])->assertOk();
        $this->assertCount(1, $this->sent('PUT', "/api/agents/{$this->telenow['id']}"), 'nothing changed, nothing sent');
    }

    public function test_deleting_deletes_it_at_telenow(): void
    {
        $id = $this->make()->json('id');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$id}")->assertNoContent();
        [$delete] = $this->sent('DELETE', "/api/agents/{$this->telenow['id']}");
        $this->assertSame([self::KEY], $delete->header('X-API-Key'));
        $this->assertSame('synced', Agent::find($id)->sync_state);
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$id}")->assertNotFound();
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents')->assertJsonPath('total', 0);
    }

    public function test_an_agent_already_gone_at_telenow_is_deleted(): void
    {
        $this->answering(fn (Request $r) => self::is($r, 'DELETE', "/api/agents/{$this->telenow['id']}")
            ? Http::response(RecordedTelenow::body('deleteAgent.already-deleted'), 404) : null);
        $id = $this->make()->json('id');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$id}")->assertNoContent();
        $this->assertCount(1, $this->sent('DELETE', "/api/agents/{$this->telenow['id']}"));
        $this->assertSame(['synced', null], [Agent::find($id)->sync_state, Agent::find($id)->sync_error]);
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
    }

    public function test_a_delete_telenow_refuses_shows_the_agent_again_with_why(): void
    {
        $refuse = true;
        $this->answering(function (Request $r) use (&$refuse) {
            return $refuse && self::is($r, 'DELETE', "/api/agents/{$this->telenow['id']}")
                ? Http::response(['success' => false, 'error' => 'Agent is assigned to a phone number'], 409) : null;
        });
        $id = $this->make()->json('id');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$id}")->assertNoContent();
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$id}")->assertOk()
            ->assertJson(['syncState' => 'failed', 'syncError' => 'Agent is assigned to a phone number']);
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents')->assertJsonPath('total', 1);

        $refuse = false;
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$id}/retry")->assertOk();
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$id}")->assertNotFound();
        $this->assertCount(2, $this->sent('DELETE', "/api/agents/{$this->telenow['id']}"));
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
    }

    public function test_no_agent_before_the_account_is_set_up(): void
    {
        $this->acme->forceFill(['telenow_sub_account_id' => null, 'telenow_key' => null])->save();
        $this->make()->assertStatus(409)->assertExactJson(['message' => __('agents.account_not_ready')]);
        $this->assertSame(0, Agent::query()->count());
        $this->assertSame(0, OutboxEntry::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_stack_nobody_names_fails_with_why_and_is_retried_once_named(): void
    {
        app(Settings::class)->put('agents.defaults', []);
        $made = $this->make()->assertCreated()->assertJson([
            'syncState' => 'failed',
            'syncError' => __('agents.no_default', ['what' => 'sttProvider, ttsProvider, ttsVoice']),
        ]);
        $this->assertSame([], $this->sent('POST', '/api/agents'), 'Telenow is not asked to make a broken agent');

        app(Settings::class)->put('agents.defaults', ['stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy']);
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$made->json('id')}/retry")->assertOk()->assertJsonPath('syncState', 'synced');
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$made->json('id')}/retry")->assertStatus(409)
            ->assertExactJson(['message' => __('agents.nothing_to_retry')]);
    }

    public function test_a_passing_failure_waits_in_the_kits_words_and_is_sent_later(): void
    {
        $down = true;
        $this->answering(function (Request $r) use (&$down) {
            return $down && self::is($r, 'POST', '/api/agents') ? Http::response(['error' => 'upstream timeout at telenow-internal'], 503) : null;
        });
        $made = $this->make()->assertCreated()->assertJson(['syncState' => 'pending', 'syncError' => __('agents.will_retry')]);
        $this->assertStringNotContainsString('telenow', strtolower($made->getContent()));

        $down = false;
        $this->travel(config('kit.outbox.retry_max_seconds') + 1)->seconds();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame('synced', Agent::find($made->json('id'))->sync_state);
        $this->assertCount(2, $this->sent('POST', '/api/agents'));
    }

    public function test_an_agent_telenow_never_made_is_deleted_without_asking_it(): void
    {
        app(Settings::class)->put('agents.defaults', []);
        $id = $this->make()->assertJsonPath('syncState', 'failed')->json('id');
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/agents/{$id}")->assertNoContent();
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());

        app(Settings::class)->put('agents.defaults', ['stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy']);
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame([], $this->sent('POST', '/api/agents'));
        $this->assertSame([], Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->all());
    }

    public function test_what_a_member_may_type_and_see(): void
    {
        $this->make(['name' => ''])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->make(['name' => str_repeat('a', config('kit.agents.name_max_length') + 1)])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->make(['systemPrompt' => str_repeat('a', config('kit.agents.prompt_max_length') + 1)])->assertStatus(422)
            ->assertJsonValidationErrors('systemPrompt');
        $this->assertSame(0, Agent::query()->count());

        $other = $this->account('Beta Clinic', 'vai_test_customer_beta');
        $theirs = Agent::create(['workspace_id' => $other->id, 'name' => 'Theirs']);
        foreach ([['getJson', ''], ['patchJson', ''], ['deleteJson', ''], ['postJson', '/retry'], ['postJson', '/test-call']] as [$call, $tail]) {
            $this->actingAs($this->ana)->{$call}("/api/kit/console/agents/{$theirs->id}{$tail}")->assertNotFound()
                ->assertExactJson(['message' => __('agents.not_found')]);
        }
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents/not-a-uuid')->assertNotFound();
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents')->assertJsonPath('total', 0);
        $this->assertNull($theirs->fresh()->deleted_at);

        auth()->guard('web')->logout();
        $this->getJson('/api/kit/console/agents')->assertUnauthorized();
    }

    public function test_a_test_call_gives_the_browser_its_session_and_nothing_else(): void
    {
        $id = $this->make()->json('id');
        $session = RecordedTelenow::body('initWebCall.customer')['data'];
        $answer = $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$id}/test-call")->assertOk()->assertExactJson(['session' => [
            'sessionId' => $session['sessionId'], 'transport' => $session['transport'], 'websocketUrl' => $session['websocketUrl'],
        ]]);
        $this->assertStringNotContainsString($this->telenow['id'], $answer->getContent());

        [$init] = $this->sent('POST', '/api/sessions/init-web-call');
        $this->assertSame([self::KEY], $init->header('X-API-Key'));
        $this->assertEquals(['agentId' => $this->telenow['id']], json_decode($init->body(), true));
    }

    public function test_no_test_call_while_paused_or_unsaved_and_telenows_refusals_in_the_kits_words(): void
    {
        $id = $this->make()->json('id');
        $call = fn () => $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$id}/test-call");

        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'stopped', 'canSpend' => false, 'stoppedBy' => 'balance']);
        $call()->assertStatus(409)->assertExactJson(['message' => __('console.paused.service_paused')]);
        $this->assertSame([], $this->sent('POST', '/api/sessions/init-web-call'), 'a paused account never starts a paid call');
        app(Settings::class)->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);

        $unsaved = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Not at Telenow yet']);
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$unsaved->id}/test-call")->assertStatus(409)
            ->assertExactJson(['message' => __('agents.not_ready')]);

        foreach ([
            [404, RecordedTelenow::body('initWebCall.unknown-agent'), 409, 'agents.not_ready'],
            [403, ['success' => false, 'error' => 'Telenow says the org is suspended'], 409, 'console.paused.service_paused'],
            [429, ['success' => false, 'error' => 'Telenow rate limit'], 429, 'agents.test_busy'],
            [503, ['success' => false, 'error' => 'Telenow is down'], 503, 'agents.test_unavailable'],
        ] as [$status, $body, $ours, $words]) {
            $this->answering(fn (Request $r) => self::is($r, 'POST', '/api/sessions/init-web-call') ? Http::response($body, $status) : null);
            $answer = $call()->assertStatus($ours)->assertExactJson(['message' => __($words)]);
            $this->assertStringNotContainsString((string) $body['error'], $answer->getContent());
        }
    }
}
