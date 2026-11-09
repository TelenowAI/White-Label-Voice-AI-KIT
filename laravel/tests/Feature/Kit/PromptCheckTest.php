<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Catalog\Catalog;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\CatalogVoice;
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
 * The prompt check (App\Kit\Agents\PromptCheck; contract/kit-api.yaml checkPrompt, checkNewPrompt): an agent's instructions and opening line checked
 * as the voice platform checks them, with the agent's own variables, tools and call steps; a preview with sample values; and a save whose sections
 * Telenow would refuse (recorded: 400 `prompt_sections_malformed`) refused in the kit before anything is sent. Telenow answers as recorded on its live
 * release (contract_k32helpers.py).
 */
class PromptCheckTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const CHECK = '/api/agents/prompt/validate';

    private const UNCLOSED = "You answer the phone for Acme Dental.\n{#if {plan} == \"gold\"}\nOffer the gold members' early slot first.\n";

    private User $ana;

    private Workspace $acme;

    /** @var list<string|\Closure> the prompt check's answers, in turn */
    private array $checks = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $settings->put('agents.defaults', ['stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy']);
        $settings->put(Catalog::COPY, ['fetchedAt' => now()->toIso8601String(), 'recommended' => [], 'kinds' => [
            'llm' => [['id' => 'openai', 'name' => 'OpenAI', 'models' => [['id' => 'gpt-5.4-mini', 'name' => 'Mini']]]],
            'stt' => [['id' => 'deepgram', 'name' => 'Deepgram']], 'tts' => [['id' => 'openai', 'name' => 'OpenAI voices']],
        ]]);
        $settings->put(Catalog::CURATION, ['llm' => ['openai' => ['offered' => true]], 'stt' => ['deepgram' => ['offered' => true]], 'tts' => ['openai' => ['offered' => true]]]);
        (new CatalogVoice)->forceFill(['provider_id' => 'openai', 'voice_id' => 'alloy', 'name' => 'Alloy', 'position' => 0, 'language_bases' => 'en', 'tags' => [], 'search_text' => 'alloy'])->save();
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        RecordedTelenow::fake();
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($recorded) {
            if (parse_url($r->url(), PHP_URL_PATH) !== self::CHECK) {
                return $recorded($r);
            }
            $answer = array_shift($this->checks) ?? throw new \RuntimeException('no prompt check queued');

            return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
        });
    }

    /** @return list<array> the bodies the prompt check was sent */
    private function asked(): array
    {
        return Http::recorded(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === self::CHECK)->map(fn (array $p) => json_decode($p[0]->body(), true))->values()->all();
    }

    private function sentTo(string $method, string $path): int
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path)->count();
    }

    private function agent(array $settings = [], array $metadata = []): Agent
    {
        $agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $agent->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced', 'system_prompt' => 'You answer the phone.', 'settings' => $settings,
            'llm_provider' => 'openai', 'llm_model' => 'gpt-5.4-mini', 'stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy',
            'telenow_snapshot' => ['metadata' => $metadata]])->save();

        return $agent;
    }

    public function test_an_agents_instructions_are_checked_with_its_own_variables_tools_and_call_steps_and_the_findings_are_in_the_kits_fields(): void
    {
        $variables = [['name' => 'plan', 'type' => 'choice', 'choices' => ['gold', 'basic'], 'default' => 'basic'], 'not a row'];
        $agent = $this->agent(['tools' => [['name' => 'book_slot'], ['name' => 'book_slot'], ['name' => 'look_up']],
            'flow' => ['start' => 'a', 'steps' => [['id' => 'a', 'capture' => [['name' => 'booked'], ['name' => 'when']]], ['id' => 'b', 'capture' => [['name' => 'booked']]]], 'exits' => []]],
            ['variables' => $variables]);
        $this->checks = ['validatePrompt.unclosed', 'validatePrompt.greeting-section'];
        $found = $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/prompt-check", ['systemPrompt' => self::UNCLOSED, 'greeting' => 'Hello'])->assertOk();
        $unclosed = RecordedTelenow::body('validatePrompt.unclosed')['data']['errors'][0];
        $found->assertExactJson(['checked' => true, 'valid' => false, 'preview' => null, 'warnings' => [],
            'errors' => [['field' => 'systemPrompt', 'code' => 'section_unclosed', 'line' => 2, 'column' => 1, 'message' => $unclosed['message']]]]);
        [$asked] = $this->asked();
        $this->assertSame(['prompt' => trim(self::UNCLOSED), 'greeting' => 'Hello', 'variables' => [$variables[0]], 'tools' => ['book_slot', 'look_up'],
            'kind' => 'flowBase', 'writtenBySteps' => ['booked', 'when']], $asked, "the agent's own: its declared variables, its tools once each, its steps' captures");

        $greeting = $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/prompt-check", ['systemPrompt' => 'Hi.', 'greeting' => 'Hello{#if {plan} == "gold"}, gold member{/if}!'])->assertOk();
        $this->assertSame([['greeting', 'section_in_spoken_line', 1, 6]], array_map(fn ($w) => [$w['field'], $w['code'], $w['line'], $w['column']], $greeting->json('warnings')));
        $this->assertTrue($greeting->json('valid'), 'a finding in the opening line is a warning only');
    }

    public function test_a_preview_shows_the_instructions_with_sample_values_and_which_branch_each_section_took(): void
    {
        $agent = $this->agent();
        $this->checks = ['validatePrompt.preview', 'validatePrompt.preview-no-values'];
        $preview = $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/prompt-check", [
            'systemPrompt' => 'Sections.', 'values' => ['plan' => 'gold', 'caller_name' => 'Asha', 'unused' => null], 'channel' => 'phone_inbound'])->assertOk();
        $recorded = RecordedTelenow::body('validatePrompt.preview')['data'];
        $preview->assertJsonPath('preview', ['text' => $recorded['rendered'], 'sections' => [['line' => 2, 'branch' => 1, 'branches' => 2]]]);
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/prompt-check", ['systemPrompt' => 'Sections.', 'values' => []])->assertOk()
            ->assertJsonPath('preview.sections', [['line' => 2, 'branch' => 2, 'branches' => 2]]);
        [$withValues, $empty] = $this->asked();
        $this->assertSame(['plan' => 'gold', 'caller_name' => 'Asha'], $withValues['values']);
        $this->assertSame('phone_inbound', $withValues['channel']);
        $this->assertSame([], $empty['values']);
        $this->assertStringContainsString('"values":{}', Http::recorded(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === self::CHECK)->last()[0]->body(), 'an empty object, so Telenow renders');
        $this->assertArrayNotHasKey('channel', $empty);
        $this->checks = ['validatePrompt.clean'];
        $noSteps = $this->agent(['flow' => ['start' => null, 'steps' => [], 'exits' => []]]);
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$noSteps->id}/prompt-check", ['systemPrompt' => 'Plain.', 'channel' => 'chat'])->assertOk()->assertJsonPath('preview', null);
        $this->assertSame(['prompt' => 'Plain.'], $this->asked()[2], 'a channel is for a preview only; a flow with no steps is no flow');

        $refused = fn (array $body, string $field) => $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/prompt-check", ['systemPrompt' => 'x'] + $body)
            ->assertStatus(422)->assertJsonValidationErrors($field);
        $refused(['values' => ['plan' => ['gold']]], 'values.plan');
        $refused(['values' => array_fill_keys(array_map(fn ($i) => "v{$i}", range(0, config('kit.agents.prompt_check.values_max'))), 'x')], 'values');
        $refused(['values' => ['plan' => str_repeat('x', config('kit.agents.prompt_check.value_max_length') + 1)]], 'values.plan');
        $refused(['values' => [], 'channel' => 'whatsapp'], 'channel');
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$agent->id}/prompt-check", [])->assertStatus(422)->assertJsonValidationErrors('systemPrompt');
        $this->assertCount(3, $this->asked(), 'nothing refused is sent');
    }

    public function test_a_new_agents_instructions_are_checked_with_nothing_of_an_agents_own_and_what_cannot_be_checked_says_so(): void
    {
        $this->checks = ['validatePrompt.unknown-variable'];
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => 'Unknown {tier}.'])->assertOk()
            ->assertJsonPath('warnings.0.code', 'section_unknown_var')->assertJsonPath('valid', true);
        $this->assertSame([['prompt' => 'Unknown {tier}.']], $this->asked());

        $this->checks[] = 'validatePrompt.clean';
        $most = str_repeat('x', config('kit.agents.prompt_check.check_max_length'));
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => $most, 'greeting' => $most])->assertOk()->assertJsonPath('checked', true);
        $this->assertSame([$most, $most], [$this->asked()[1]['prompt'], $this->asked()[1]['greeting']], 'exactly the most is checked');
        $long = str_repeat('x', config('kit.agents.prompt_check.check_max_length') + 1);
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => $long])->assertOk()
            ->assertExactJson(['checked' => false, 'valid' => true, 'errors' => [], 'warnings' => [], 'preview' => null]);
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => 'x', 'greeting' => $long])->assertOk()->assertJsonPath('checked', false);
        $this->assertCount(2, $this->asked(), 'more than the platform checks is not sent');
        $this->checks = ['validatePrompt.too-long', fn () => throw new ConnectionException('down'), 'validatePrompt.no-key'];
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => 'x'])->assertOk()->assertJsonPath('checked', false);
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => 'x'])->assertStatus(503)
            ->assertExactJson(['message' => __('agents.prompt_check.unavailable')]);
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => 'x'])->assertStatus(409)
            ->assertExactJson(['message' => __('agents.account_not_ready')]);
        $this->acme->forceFill(['telenow_key' => null])->save();
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents/prompt-check', ['systemPrompt' => 'x'])->assertStatus(409);
        $stranger = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->postJson('/api/kit/console/agents/'.$this->agent()->id.'/prompt-check', ['systemPrompt' => 'x'])->assertForbidden();
    }

    public function test_a_save_whose_sections_the_platform_would_refuse_is_refused_in_the_kit_and_nothing_is_sent(): void
    {
        $stack = ['llmProvider' => Catalog::publicKey('llm', 'openai'), 'llmModel' => 'gpt-5.4-mini', 'sttProvider' => Catalog::publicKey('stt', 'deepgram'),
            'ttsProvider' => Catalog::publicKey('tts', 'openai'), 'ttsVoice' => 'alloy'];
        $message = RecordedTelenow::body('validatePrompt.unclosed')['data']['errors'][0]['message'];
        $this->checks = ['validatePrompt.unclosed'];
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents', ['name' => 'Front desk', 'systemPrompt' => self::UNCLOSED] + $stack)->assertStatus(422)
            ->assertJsonPath('errors', ['systemPrompt' => [$message]]);
        $this->assertSame(0, Agent::query()->count());
        $this->assertSame(0, $this->sentTo('POST', '/api/agents'), 'never sent to the platform');

        $this->checks = ['validatePrompt.near-miss'];
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents', ['name' => 'Front desk', 'systemPrompt' => '{{#if {plan} == "gold"}}x{{/if}}'] + $stack)->assertCreated();
        $agent = Agent::query()->sole();
        $this->assertSame(1, $this->sentTo('POST', '/api/agents'), 'warnings never stop a save');

        $agent = $agent->fresh();
        $saved = $agent->system_prompt;
        $put = fn () => Http::recorded(fn (Request $r) => $r->method() === 'PUT' && parse_url($r->url(), PHP_URL_PATH) === '/api/agents/'.$agent->telenow_id)
            ->map(fn (array $p) => json_decode($p[0]->body(), true)['systemPrompt'] ?? null)->values()->all();
        $this->checks = ['validatePrompt.unclosed'];
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['systemPrompt' => self::UNCLOSED])->assertStatus(422)
            ->assertJsonPath('errors', ['systemPrompt' => [$message]]);
        $this->assertSame($saved, $agent->fresh()->system_prompt);
        $this->assertSame([], $put(), 'never sent to the platform');

        $braced = 'Hello {caller_name}.';
        $agent->forceFill(['system_prompt' => $braced])->save();
        $before = count($this->asked());
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['systemPrompt' => $braced])->assertOk();
        $this->assertSame($before, count($this->asked()), 'the same instructions again: nothing changed, nothing to check');
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['systemPrompt' => 'No sections here.'])->assertOk();
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['description' => 'Front desk calls'])->assertOk();
        $this->assertSame($before, count($this->asked()), 'instructions with no brace, or none changed: nothing to check');

        $tried = 0;
        $this->checks = [function () use (&$tried) {
            $tried++;
            throw new ConnectionException('down');
        }];
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['systemPrompt' => 'With {caller_name}.'])->assertOk();
        $this->assertSame(1, $tried, 'checked, and out of reach');
        $long = '{'.str_repeat('x', config('kit.agents.prompt_check.check_max_length'));
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['systemPrompt' => $long])->assertOk();
        $this->assertSame([1, $before], [$tried, count($this->asked())], 'more than the platform checks is saved unchecked');
        $queued = \App\Kit\Outbox\OutboxEntry::query()->where('kit_id', $agent->id)->where('operation', 'update')->orderBy('id')->get()
            ->map(fn ($e) => $e->body['systemPrompt'] ?? null)->filter()->values()->all();
        $this->assertSame(['No sections here.', 'With {caller_name}.', $long], $queued, 'on their way: the check out of reach, the platform checks it on the save');
    }
}
