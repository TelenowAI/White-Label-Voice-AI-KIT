<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Catalog\Catalog;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Settings\Settings;
use App\Models\Agent;
use App\Models\AgentWebhook;
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
 * An agent's versions (App\Kit\Agents\AgentVersions), through the kit's real routes. The stand-in for Telenow below does what contract_k5versions.py recorded of the 11-03 release: a version for each save that
 * changes something, none for one that does not, a restore writing the version back whole (keys, a transfer, a hand-over to a deleted agent) and saved as a new version, with its answers and rows taken from those recordings.
 */
class AgentVersionsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const AGENT = '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159';

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    /** @var array<string, mixed> the agent as Telenow holds it now, keys in plain */
    private array $held;

    /** @var list<array{version: int, note: string, snapshot: array}> oldest first */
    private array $history = [];

    /** @var list<array{method: string, path: string, query: ?string, raw: string, ifMatch: ?string}> */
    private array $sent = [];

    /** What requests answer instead, by method and how the path ends: ['POST /restore' => [status, body]]. */
    private array $answers = [];

    private int $losesNextRestoreAnswer = 0;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        $settings->put(AgentSettings::TRANSFER_ALLOWED, true);
        $settings->put(Catalog::COPY, ['fetchedAt' => now()->toIso8601String(), 'recommended' => [], 'kinds' => [
            'llm' => [['id' => 'openai', 'name' => 'OpenAI', 'models' => [['id' => 'gpt-5.4-mini', 'name' => 'Mini'], ['id' => 'gpt-old', 'name' => 'Old'], ['id' => 'gpt-other', 'name' => 'Other']]]],
            'stt' => [['id' => 'deepgram', 'name' => 'Deepgram'], ['id' => 'other-stt', 'name' => 'Other listening']],
            'tts' => [['id' => 'openai', 'name' => 'OpenAI voices']],
        ]]);
        $settings->put(Catalog::CURATION, [
            'llm' => ['openai' => ['offered' => true, 'models' => ['gpt-old' => ['offered' => false]]]],
            'stt' => ['deepgram' => ['offered' => true]],
            'tts' => ['openai' => ['offered' => true]],
        ]);
        foreach (['alloy', 'nova'] as $position => $voice) {
            (new CatalogVoice)->forceFill(['provider_id' => 'openai', 'voice_id' => $voice, 'name' => ucfirst($voice), 'position' => $position, 'language_bases' => 'en', 'tags' => [], 'search_text' => $voice])->save();
        }
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => 'f5b2ecfe-1a21-4c72-83e2-03ced560e865', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->held = ['id' => self::AGENT, 'name' => 'Front desk', 'description' => null, 'system_prompt' => 'You are the front desk.', 'is_active' => true, 'llm_provider' => 'openai', 'llm_model' => 'gpt-5.4-mini',
            'stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy', 'telephony_config' => ['recording' => true], 'session_config' => ['maxDuration' => 600], 'metadata' => ['callerIdentity' => ['enabled' => true]]];
        $this->history = [['version' => 1, 'note' => 'created', 'snapshot' => $this->held]];
        $this->agent = new Agent(['name' => 'Front desk']);
        $this->agent->workspace_id = $this->acme->id;
        $this->agent->forceFill(['telenow_id' => self::AGENT, 'sync_state' => 'synced', 'telenow_version' => 1, 'telenow_snapshot' => $this->held, 'settings' => app(AgentSettings::class)->read($this->held),
            'llm_provider' => 'openai', 'llm_model' => 'gpt-5.4-mini', 'stt_provider' => 'deepgram', 'tts_provider' => 'openai', 'tts_voice' => 'alloy', 'system_prompt' => 'You are the front desk.'])->save();
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->telenow($r));
    }

    private function telenow(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $this->sent[] = ['method' => $r->method(), 'path' => $path, 'query' => parse_url($r->url(), PHP_URL_QUERY), 'raw' => $r->body(), 'ifMatch' => $r->header('If-Match')[0] ?? null];
        foreach ($this->answers as $which => [$status, $body]) {
            [$method, $suffix] = explode(' ', $which);
            if ($r->method() === $method && str_ends_with($path, $suffix)) {
                return Http::response($body, $status);
            }
        }
        $root = '/api/agents/'.self::AGENT;
        if ($path === "{$root}/versions") {
            return Http::response(['success' => true, 'data' => ['versions' => array_reverse(array_map(fn (array $v) => ['version' => $v['version'], 'note' => $v['note'], 'createdAt' => '2026-11-04T07:00:39Z', 'name' => $v['snapshot']['name'],
                'llmModel' => $v['snapshot']['llm_model'], 'ttsVoice' => $v['snapshot']['tts_voice'], 'savedByEmail' => 'sub-account+4dd331fa@sub-accounts.invalid'], $this->history))]]);
        }
        if (preg_match('#/versions/(\d+)/restore$#', $path, $m) === 1) {
            return $this->restored((int) $m[1]);
        }
        if ($path === $root && $r->method() === 'GET') {
            return Http::response(['success' => true, 'data' => $this->masked($this->held)]);
        }
        if ($path === $root && $r->method() === 'PUT') {
            return $this->saved($r->data());
        }

        throw new \RuntimeException("Nothing stands in for {$r->method()} {$path}");
    }

    private function saved(array $body): mixed
    {
        foreach (['name' => 'name', 'systemPrompt' => 'system_prompt', 'metadata' => 'metadata', 'telephonyConfig' => 'telephony_config', 'sessionConfig' => 'session_config', 'llmProvider' => 'llm_provider', 'llmModel' => 'llm_model', 'sttProvider' => 'stt_provider', 'ttsProvider' => 'tts_provider', 'ttsVoice' => 'tts_voice'] as $write => $column) {
            if (array_key_exists($write, $body)) {
                $this->held[$column] = $write === 'metadata' ? $this->withKeysKept($body[$write]) : $body[$write];
            }
        }
        $this->keep('update');

        return Http::response(['success' => true, 'data' => $this->masked($this->held), 'version' => end($this->history)['version']]);
    }

    /** As recorded: a mask handed back keeps the key. */
    private function withKeysKept(array $metadata): array
    {
        foreach ($metadata['tools'] ?? [] as $i => $row) {
            if (str_starts_with((string) ($row['bearer'] ?? ''), '••••')) {
                $metadata['tools'][$i]['bearer'] = collect($this->held['metadata']['tools'] ?? [])->firstWhere('name', $row['name'])['bearer'];
            }
        }

        return $metadata;
    }

    private function restored(int $version): mixed
    {
        $found = collect($this->history)->firstWhere('version', $version);
        if ($found === null) {
            return Http::response(RecordedTelenow::body('restoreAgentVersion.unknown'), 404);
        }
        $this->held = $found['snapshot'];
        $this->keep("restore of v{$version}");
        if ($this->losesNextRestoreAnswer-- > 0) {
            return Http::response(['error' => 'the answer was lost'], 503);
        }

        return Http::response(['success' => true, 'data' => $this->masked($this->held)]);
    }

    /** As recorded: no version when nothing differs. */
    private function keep(string $note): void
    {
        $last = end($this->history);
        if ($last['snapshot'] != $this->held) {
            $this->history[] = ['version' => $last['version'] + 1, 'note' => $note, 'snapshot' => $this->held];
        }
    }

    private function masked(array $held): array
    {
        foreach ($held['metadata']['tools'] ?? [] as $i => $row) {
            if (isset($row['bearer'])) {
                $held['metadata']['tools'][$i]['bearer'] = '••••'.substr($row['bearer'], -4);
            }
        }

        return $held;
    }

    /** A version held at Telenow with these changes, then back to the current one in use, as a save then another would leave it. */
    private function aVersionWith(array $changes): int
    {
        $this->held = array_replace_recursive($this->held, $changes);
        $this->keep('update');

        return end($this->history)['version'];
    }

    private function url(string $rest = ''): string
    {
        return "/api/kit/console/agents/{$this->agent->id}/versions{$rest}";
    }

    private function restore(int $version): TestResponse
    {
        return $this->actingAs($this->ana)->postJson($this->url("/{$version}/restore"));
    }

    /** The rows a change answers for one of Telenow's. */
    private function changesFor(array $rows, int $version = 2): array
    {
        $this->answers = ["GET /versions/{$version}/diff" => [200, ['success' => true, 'data' => ['changes' => $rows]]]];

        return $this->actingAs($this->ana)->getJson($this->url("/{$version}/changes"))->assertOk()->json('changes');
    }

    private function puts(): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'PUT'));
    }

    private function posts(): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'POST'));
    }

    private function toolKinds(): array
    {
        return array_column($this->held['metadata']['tools'] ?? [], 'kind');
    }

    public function test_the_list_is_newest_first_in_the_kits_words_and_says_nothing_else_of_telenows(): void
    {
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->answers = ['GET /versions' => [200, RecordedTelenow::body('listAgentVersions.restored')]];
        $answer = $this->actingAs($this->ana)->getJson($this->url())->assertOk();
        $first = $answer->json('versions.0');
        $this->assertSame(['version' => 11, 'at' => $first['at'], 'name' => 'Front desk 2', 'kind' => 'restored', 'restoredFrom' => 7, 'current' => true], $first);
        $this->assertFalse($answer->json('versions.1.current'));
        $this->assertSame([11 => 7, 10 => 5, 9 => 1], array_filter(array_column($answer->json('versions'), 'restoredFrom', 'version')));
        $this->assertSame(['changed', 'created'], array_slice(array_values(array_unique(array_column($answer->json('versions'), 'kind'))), 1));
        $this->assertSame(1, collect($answer->json('versions'))->last()['version']);
        foreach (['sub-accounts.invalid', 'savedBy', 'gpt-5.4', 'alloy', 'restore of', self::AGENT] as $kept) {
            $this->assertStringNotContainsString($kept, $answer->getContent());
        }
    }

    public function test_a_note_it_does_not_know_reads_as_a_change(): void
    {
        $this->history[0]['note'] = 'pre-edit state';
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('versions.0.kind', 'changed')->assertJsonPath('versions.0.restoredFrom', null);
    }

    public function test_an_agent_not_at_telenow_yet_has_no_versions_and_none_can_be_read(): void
    {
        $this->agent->forceFill(['telenow_id' => null])->save();
        $this->actingAs($this->ana)->getJson($this->url())->assertOk()->assertExactJson(['versions' => [], 'lastRestore' => null]);
        $this->actingAs($this->ana)->getJson($this->url('/1/changes'))->assertStatus(409);
        $this->actingAs($this->ana)->getJson($this->url('/1/results'))->assertStatus(409);
        $this->restore(1)->assertStatus(409)->assertExactJson(['message' => __('agents.not_ready')]);
        $this->assertSame([], $this->sent);
    }

    public function test_when_telenow_cannot_be_read_it_says_so_in_the_kits_words(): void
    {
        $this->answers = ['GET /versions' => [500, ['error' => 'database on fire']], 'GET /versions/2/diff' => [500, ['error' => 'database on fire']], 'GET /versions/2/perf' => [500, ['error' => 'database on fire']]];
        foreach (['', '/2/changes', '/2/results'] as $rest) {
            $this->actingAs($this->ana)->getJson($this->url($rest))->assertStatus(409)->assertExactJson(['message' => __('agents.version_unreadable')]);
        }
        $this->restore(2)->assertStatus(409)->assertExactJson(['message' => __('agents.version_unreadable')]);
    }

    public function test_reading_a_version_telenow_does_not_have_says_it_was_not_found(): void
    {
        $this->answers = ['GET /versions/9999/diff' => [404, RecordedTelenow::body('diffAgentVersion.unknown')], 'GET /versions/9999/perf' => [404, RecordedTelenow::body('diffAgentVersion.unknown')]];
        $this->actingAs($this->ana)->getJson($this->url('/9999/changes'))->assertNotFound()->assertExactJson(['message' => __('agents.version_not_found')]);
        $this->actingAs($this->ana)->getJson($this->url('/9999/results'))->assertNotFound()->assertExactJson(['message' => __('agents.version_not_found')]);
    }

    public function test_what_changed_reads_in_the_kits_words(): void
    {
        $this->assertSame([
            ['label' => 'Name', 'from' => 'Front desk', 'to' => 'Front desk 2'],
            ['label' => 'Instructions', 'from' => 'You are the front desk.', 'to' => 'You are the front desk, version two.'],
        ], $this->changesFor(RecordedTelenow::body('diffAgentVersion.customer')['data']['changes']));
    }

    public function test_the_first_versions_rows_read_in_the_kits_words_with_no_word_of_its_own_names_left(): void
    {
        $rows = $this->changesFor(RecordedTelenow::body('diffAgentVersion.first')['data']['changes'], 1);
        $labels = array_column($rows, 'label');
        $this->assertSame(['Name', 'Description', 'Instructions', 'Language model provider', 'Language model', 'Speech recognition', 'Voice provider', 'Voice', 'Opening line for incoming calls', 'During the call: longest call, in seconds'], $labels);
        foreach ($labels as $label) {
            $this->assertDoesNotMatchRegularExpression('/LLM|STT|TTS|System prompt|Session config/', $label);
        }
        $this->assertSame(['label' => 'During the call: longest call, in seconds', 'from' => null, 'to' => '600'], end($rows));
    }

    public function test_a_session_row_shows_a_plain_value_and_hides_a_whole_blob(): void
    {
        $this->assertSame([
            ['label' => 'During the call: interruptions', 'from' => null, 'to' => 'high'],
            ['label' => 'During the call: how it speaks', 'from' => null, 'to' => null],
            ['label' => 'During the call: longest call, in seconds', 'from' => '600', 'to' => '900'],
        ], $this->changesFor(RecordedTelenow::body('diffAgentVersion.session')['data']['changes']));
        $this->assertSame([['label' => 'During the call: some new setting', 'from' => null, 'to' => null]], $this->changesFor([['path' => 'Session config · someNewSetting', 'from' => '[1,2]', 'to' => null]]));
    }

    public function test_a_tool_row_says_added_changed_or_removed_and_never_its_kind(): void
    {
        $this->assertSame([['label' => 'Tool: book_visit', 'from' => null, 'to' => 'Added']], $this->changesFor(RecordedTelenow::body('diffAgentVersion.tool-added')['data']['changes']));
        $this->assertSame([['label' => 'Tool: book_visit', 'from' => null, 'to' => 'Changed']], $this->changesFor(RecordedTelenow::body('diffAgentVersion.tool-edited')['data']['changes']));
        $this->assertSame([
            ['label' => 'Transfer to a person', 'from' => null, 'to' => 'Removed'],
            ['label' => 'Hand over to another agent', 'from' => null, 'to' => 'Changed'],
        ], $this->changesFor([['path' => 'Tool · transfer_to_human', 'from' => 'transfer', 'to' => 'removed'], ['path' => 'Tool · handoff_to_agent', 'from' => 'agent', 'to' => 'edited · connector']]));
    }

    public function test_a_row_it_does_not_know_says_only_that_something_changed(): void
    {
        $this->assertSame([['label' => __('agents.version_other'), 'from' => null, 'to' => null], ['label' => __('agents.version_other'), 'from' => null, 'to' => null]], $this->changesFor([
            ['path' => 'Telenow engine', 'from' => 'api.telenow.ai', 'to' => 'https://internal'], ['path' => 'Realtime engine · model', 'from' => 'x', 'to' => 'y'],
        ]));
    }

    public function test_a_switch_and_cut_text_read_as_words_and_a_masked_key_stays_masked(): void
    {
        $this->assertSame([
            ['label' => 'Analysis after a call', 'from' => 'on', 'to' => 'off'],
            ['label' => 'Instructions', 'from' => 'You are…', 'to' => 'changed'],
            ['label' => 'Language model: api key', 'from' => '•••', 'to' => null],
        ], $this->changesFor([['path' => 'Post-call analysis', 'from' => 'true', 'to' => 'false'], ['path' => 'System prompt', 'from' => 'You are…', 'to' => '(edited)'], ['path' => 'LLM config · api_key', 'from' => '•••', 'to' => null]]));
    }

    public function test_nothing_turning_into_something_empty_is_not_a_change(): void
    {
        $this->assertSame([['label' => 'Name', 'from' => null, 'to' => 'New']], $this->changesFor([['path' => 'Greeting (outbound)', 'from' => null, 'to' => ''], ['path' => 'Name', 'from' => null, 'to' => 'New'], ['path' => 'Description', 'from' => '', 'to' => null]]));
    }

    public function test_call_steps_changing_read_in_the_kits_words(): void
    {
        $this->assertSame([['label' => 'Call steps', 'from' => null, 'to' => 'Added']], $this->changesFor(RecordedTelenow::body('diffAgentVersion.flow-added')['data']['changes']));
        $this->assertSame([['label' => 'Call steps', 'from' => null, 'to' => 'Removed']], $this->changesFor(RecordedTelenow::body('diffAgentVersion.flow-removed')['data']['changes']));
        $this->assertSame([['label' => 'Call steps: steps changed', 'from' => null, 'to' => '1']], $this->changesFor(RecordedTelenow::body('diffAgentVersion.flow-step-changed')['data']['changes']));
    }

    public function test_a_save_that_changed_nothing_listed_answers_none(): void
    {
        $this->assertSame([], $this->changesFor(RecordedTelenow::body('diffAgentVersion.nothing-listed')['data']['changes'], 3));
    }

    public function test_no_key_ever_comes_through_what_changed(): void
    {
        $answer = json_encode($this->changesFor(RecordedTelenow::body('diffAgentVersion.tool-edited')['data']['changes']));
        $this->assertStringNotContainsString('tok_test', $answer);
    }

    public function test_results_say_how_calls_went_beside_the_version_before_and_never_what_telenow_charged(): void
    {
        $this->answers = ['GET /versions/13/perf' => [200, RecordedTelenow::body('getAgentVersionPerf.customer')]];
        $answer = $this->actingAs($this->ana)->getJson($this->url('/13/results'))->assertOk();
        $this->assertSame(['calls', 'answeredRate', 'avgDurationSec', 'avgScore', 'sentiment', 'from', 'to'], array_keys($answer->json('current')));
        $answer->assertJsonPath('current.calls', 2)->assertJsonPath('current.answeredRate', 0.5)->assertJsonPath('current.avgScore', 87)->assertJsonPath('current.avgDurationSec', 31)
            ->assertJsonPath('current.sentiment', ['positive' => 1, 'neutral' => 0, 'negative' => 0])->assertJsonPath('previous.calls', 0)->assertJsonPath('previous.answeredRate', null);
        foreach (['Charge', 'charge', '0.1234', 'SttMs', 'LlmMs', 'TtsMs', 'RespMs'] as $kept) {
            $this->assertStringNotContainsString($kept, $answer->getContent());
        }
    }

    public function test_the_first_version_has_nothing_before_it(): void
    {
        $this->answers = ['GET /versions/1/perf' => [200, RecordedTelenow::body('getAgentVersionPerf.first')]];
        $this->actingAs($this->ana)->getJson($this->url('/1/results'))->assertOk()->assertJsonPath('previous', null)->assertJsonPath('current.calls', 0);
    }

    public function test_putting_one_back_reads_the_agent_from_telenows_answer(): void
    {
        $this->aVersionWith(['name' => 'Front desk 2', 'system_prompt' => 'Second.', 'session_config' => ['maxDuration' => 900]]);
        $this->agent->forceFill(['telenow_snapshot' => $this->held, 'settings' => app(AgentSettings::class)->read($this->held), 'name' => 'Front desk 2', 'system_prompt' => 'Second.'])->save();
        $answer = $this->restore(1)->assertOk();
        $this->assertSame([['method' => 'POST', 'path' => '/api/agents/'.self::AGENT.'/versions/1/restore', 'query' => null]], array_map(fn (array $s) => array_intersect_key($s, ['method' => 1, 'path' => 1, 'query' => 1]), $this->posts()));
        $answer->assertJsonPath('name', 'Front desk')->assertJsonPath('systemPrompt', 'You are the front desk.')->assertJsonPath('settings.maxDurationSecs', 600)->assertJsonPath('syncState', 'synced');
        $this->assertSame(3, $this->agent->fresh()->telenow_version);
        $this->assertSame([], $this->puts(), 'nothing to take off');
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('versions.0.kind', 'restored')->assertJsonPath('versions.0.restoredFrom', 1)->assertJsonPath('lastRestore', null);
    }

    public function test_the_next_save_goes_from_the_version_it_is_now_at(): void
    {
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->restore(1)->assertOk();
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$this->agent->id}", ['settings' => ['maxDurationSecs' => 300]])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertSame('"3"', end($this->sent)['ifMatch'] ?? collect($this->puts())->last()['ifMatch']);
        $this->assertSame(300, $this->held['session_config']['maxDuration']);
        $this->assertSame('Front desk', $this->held['name']);
    }

    public function test_a_key_comes_back_as_it_was_and_is_only_ever_answered_as_a_mask(): void
    {
        $tool = ['name' => 'book_visit', 'description' => 'Book a visit.', 'endpoint' => 'https://example.com/book', 'bearer' => 'tok_test_version_1111'];
        $old = $this->aVersionWith(['metadata' => ['tools' => [$tool]]]);
        $this->held['metadata']['tools'][0] = ['bearer' => 'tok_test_version_2222', 'endpoint' => 'https://example.org/book'] + $tool;
        $this->keep('update');
        $answer = $this->restore($old)->assertOk();
        $this->assertSame('tok_test_version_1111', $this->held['metadata']['tools'][0]['bearer']);
        $answer->assertJsonPath('settings.tools.0.tokenHint', '••••1111')->assertJsonPath('settings.tools.0.url', 'https://example.com/book');
        $this->assertStringNotContainsString('tok_test_version', $answer->getContent());
        $this->assertStringNotContainsString('view=edit', (string) json_encode($this->sent));
        foreach (['settings', 'telenow_snapshot'] as $column) {
            $this->assertStringNotContainsString('tok_test_version', (string) json_encode($this->agent->fresh()->getAttribute($column)));
        }
    }

    public function test_one_that_is_not_there_or_in_use_is_refused_with_nothing_sent(): void
    {
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->restore(9)->assertNotFound()->assertExactJson(['message' => __('agents.version_not_found')]);
        $this->restore(2)->assertStatus(409)->assertExactJson(['message' => __('agents.version_in_use')]);
        $this->assertSame([], $this->posts());
        $this->assertSame(0, OutboxEntry::query()->where('resource', 'agents')->count());
    }

    public function test_nothing_is_put_back_while_a_change_is_still_on_its_way_or_was_refused(): void
    {
        $this->aVersionWith(['name' => 'Front desk 2']);
        foreach (['pending', 'failed'] as $state) {
            $this->agent->forceFill(['sync_state' => $state])->save();
            $this->restore(1)->assertStatus(409)->assertExactJson(['message' => __('agents.version_wait')]);
        }
        $this->assertSame([], $this->posts());
    }

    public function test_when_telenow_refuses_nothing_changes_and_the_agent_goes_on_as_it_was(): void
    {
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->answers = ['POST /restore' => [400, ['error' => 'a reference in this version belongs to another org at telenow']]];
        $answer = $this->restore(1)->assertStatus(409)->assertExactJson(['message' => __('agents.version_refused')]);
        $this->assertStringNotContainsString('telenow', $answer->getContent());
        $this->assertSame('synced', $this->agent->fresh()->sync_state);
        $this->assertNull($this->agent->fresh()->sync_error);
        $this->assertSame(0, OutboxEntry::query()->where('resource', 'agents')->whereNull('done_at')->count());
        $this->answers = [];
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$this->agent->id}", ['name' => 'Goes on'])->assertOk()->assertJsonPath('syncState', 'synced');
    }

    public function test_when_telenow_is_down_it_is_tried_again_and_settled_then(): void
    {
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, false);
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->transfer()]]]);
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->answers = ['POST /restore' => [503, ['error' => 'down']]];
        $this->restore($old)->assertOk()->assertJsonPath('syncState', 'pending')->assertJsonPath('syncError', __('agents.will_retry'));
        $this->answers = [];
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame('synced', $this->agent->fresh()->sync_state);
        $this->assertNotContains('transfer', $this->toolKinds());
    }

    public function test_a_try_after_a_lost_answer_makes_no_second_version(): void
    {
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->losesNextRestoreAnswer = 1;
        $this->restore(1)->assertOk()->assertJsonPath('syncState', 'pending');
        $this->assertCount(3, $this->history);
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertCount(3, $this->history);
        $this->assertSame('synced', $this->agent->fresh()->sync_state);
        $this->assertSame('Front desk', $this->agent->fresh()->name);
        $this->assertSame(3, $this->agent->fresh()->telenow_version);
    }

    private function transfer(): array
    {
        return ['name' => 'transfer_to_human', 'kind' => 'transfer', 'description' => 'Transfer the caller to a person.', 'config' => ['destinations' => [['label' => 'Front desk', 'number' => '+14155550199']], 'message' => 'One moment.']];
    }

    public function test_a_transfer_does_not_come_back_while_the_operator_has_them_off(): void
    {
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, false);
        $end = ['name' => 'end_call', 'kind' => 'end_call', 'description' => 'End the call.'];
        $old = $this->aVersionWith(['metadata' => ['tools' => [$end, $this->transfer()]]]);
        $this->held['metadata']['tools'] = [$end];
        $this->keep('update');
        $answer = $this->restore($old)->assertOk()->assertJsonPath('settings.transfer.enabled', false)->assertJsonPath('syncState', 'synced');
        $this->assertSame(['end_call'], $this->toolKinds(), 'taken off at Telenow, and only that');
        $methods = array_map(fn (array $s) => $s['method'], array_values(array_filter($this->sent, fn (array $s) => $s['method'] !== 'GET')));
        $this->assertSame(['POST', 'PUT'], $methods, 'right after');
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore', ['version' => $old, 'kept' => [__('agents.version_kept.transfer')]]);
        $this->assertSame(0, app(\App\Kit\Agents\AgentTransfers::class)->stillOn());
    }

    public function test_when_taking_a_transfer_off_is_refused_the_agent_says_so_and_it_is_not_forgotten(): void
    {
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, false);
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->transfer()]]]);
        $this->held['metadata']['tools'] = [];
        $this->keep('update');
        $this->answers = ['PUT /'.self::AGENT => [400, ['error' => 'refused']]];
        $this->restore($old)->assertOk()->assertJsonPath('syncState', 'failed');
        $this->assertContains('transfer', $this->toolKinds(), 'it did come back, and the write taking it off was refused');
        $this->assertSame('failed', $this->agent->fresh()->sync_state, 'not put down as a refused restore');
        $this->assertSame(1, OutboxEntry::query()->where('resource', 'agents')->where('operation', 'update')->whereNull('done_at')->whereNotNull('failed_at')->count());
        $this->answers = [];
        $this->actingAs($this->ana)->postJson("/api/kit/console/agents/{$this->agent->id}/retry")->assertOk();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertNotContains('transfer', $this->toolKinds());
        $this->assertSame('synced', $this->agent->fresh()->sync_state);
    }

    public function test_a_flow_put_back_with_a_transfer_while_they_are_off_ends_that_step(): void
    {
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, false);
        $flow = ['schema' => 1, 'startNodeId' => 'a', 'nodes' => [['id' => 'a', 'kind' => 'conversation', 'prompt' => 'Talk.'], ['id' => 'b', 'kind' => 'transfer', 'config' => ['destinations' => [['numbers' => ['+14155550199'], 'mode' => 'serial']], 'noAnswerMessage' => 'Nobody is free.']]],
            'edges' => [['id' => 'e', 'source' => 'a', 'target' => 'b', 'priority' => 0, 'condition' => ['kind' => 'always']]]];
        $old = $this->aVersionWith(['metadata' => ['flow' => $flow]]);
        $this->held['metadata']['flow'] = null;
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('settings.flow.steps.1.kind', 'end')->assertJsonPath('syncState', 'synced');
        $this->assertSame('end', $this->held['metadata']['flow']['nodes'][1]['kind']);
        $this->assertSame('Nobody is free.', $this->held['metadata']['flow']['nodes'][1]['entryMessage']);
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.kept', [__('agents.version_kept.transfer')]);
    }

    public function test_a_flow_put_back_with_a_hand_over_to_a_deleted_agent_ends_that_step(): void
    {
        $gone = $this->colleague('Gone', alive: false);
        $flow = ['schema' => 1, 'startNodeId' => 'a', 'nodes' => [['id' => 'a', 'kind' => 'conversation', 'prompt' => 'Talk.'], ['id' => 'b', 'kind' => 'agent', 'config' => ['agents' => [['agentId' => $gone->telenow_id, 'kitAgentId' => $gone->id, 'label' => 'Gone', 'mode' => 'transfer']]]]],
            'edges' => [['id' => 'e', 'source' => 'a', 'target' => 'b', 'priority' => 0, 'condition' => ['kind' => 'always']]]];
        $old = $this->aVersionWith(['metadata' => ['flow' => $flow]]);
        $this->held['metadata']['flow'] = null;
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('settings.flow.steps.1.kind', 'end');
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.kept', [__('agents.version_kept.handoff')]);
    }

    public function test_a_transfer_comes_back_when_the_operator_allows_them(): void
    {
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->transfer()]]]);
        $this->held['metadata']['tools'] = [];
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('settings.transfer.enabled', true);
        $this->assertSame(['transfer'], $this->toolKinds());
        $this->assertSame([], $this->puts());
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore', null);
    }

    private function colleague(string $name, bool $alive = true): Agent
    {
        $agent = new Agent(['name' => $name]);
        $agent->workspace_id = $this->acme->id;
        $agent->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced', 'deleted_at' => $alive ? null : now()])->save();

        return $agent;
    }

    private function handoff(array $agents, ?string $message = 'One moment.'): array
    {
        return ['name' => 'handoff_to_agent', 'kind' => 'agent', 'description' => 'Hand this call to another AI agent.', 'config' => ['agents' => array_map(fn (Agent $a) => ['agentId' => $a->telenow_id, 'kitAgentId' => $a->id, 'label' => $a->name, 'mode' => 'transfer'], $agents), 'message' => $message]];
    }

    public function test_a_hand_over_to_a_deleted_agent_is_taken_off_and_the_others_stay(): void
    {
        $gone = $this->colleague('Gone', alive: false);
        $here = $this->colleague('Here');
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->handoff([$gone, $here])]]]);
        $this->held['metadata']['tools'] = [];
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('settings.handoff.agents.0.agentId', $here->id)->assertJsonCount(1, 'settings.handoff.agents')->assertJsonPath('settings.handoff.message', 'One moment.');
        $row = collect($this->held['metadata']['tools'])->firstWhere('kind', 'agent');
        $this->assertSame([$here->telenow_id], array_column($row['config']['agents'], 'agentId'));
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.kept', [__('agents.version_kept.handoff')]);
    }

    public function test_a_hand_over_to_another_accounts_agent_is_taken_off_though_it_is_still_there(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new Agent(['name' => 'Theirs']);
        $theirs->workspace_id = $other->id;
        $theirs->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->handoff([$theirs])]]]);
        $this->held['metadata']['tools'] = [];
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('settings.handoff.agents', []);
        $this->assertNotContains('agent', $this->toolKinds());
    }

    public function test_with_nobody_left_to_hand_over_to_the_row_goes(): void
    {
        $gone = $this->colleague('Gone', alive: false);
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->handoff([$gone])]]]);
        $this->held['metadata']['tools'] = [];
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('settings.handoff', ['agents' => [], 'message' => null]);
        $this->assertNotContains('agent', $this->toolKinds());
    }

    public function test_a_hand_over_to_an_agent_still_here_stays_with_nothing_more_written(): void
    {
        $here = $this->colleague('Here');
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->handoff([$here])]]]);
        $this->held['metadata']['tools'] = [];
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('settings.handoff.agents.0.agentId', $here->id);
        $this->assertSame([], $this->puts());
    }

    public function test_a_voice_setup_no_longer_offered_does_not_come_back_and_the_rest_of_the_version_does(): void
    {
        $old = $this->aVersionWith(['llm_model' => 'gpt-old', 'system_prompt' => 'From then.']);
        $this->agent->forceFill(['llm_model' => 'gpt-old'])->save();
        $this->held = ['llm_model' => 'gpt-5.4-mini', 'system_prompt' => 'Now.'] + $this->held;
        $this->keep('update');
        $this->agent->forceFill(['llm_model' => 'gpt-5.4-mini'])->save();
        $this->restore($old)->assertOk()->assertJsonPath('systemPrompt', 'From then.')->assertJsonPath('stack.llmModel', 'gpt-5.4-mini')->assertJsonPath('syncState', 'synced');
        $this->assertSame('gpt-5.4-mini', $this->held['llm_model']);
        $this->assertSame('From then.', $this->held['system_prompt']);
        $this->assertSame(['llmProvider' => 'openai', 'llmModel' => 'gpt-5.4-mini'], json_decode($this->puts()[0]['raw'], true));
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.kept', [__('agents.version_kept.llm')]);
    }

    public function test_a_voice_setup_being_kept_reads_as_kept_while_telenow_is_down(): void
    {
        $old = $this->aVersionWith(['llm_model' => 'gpt-old']);
        $this->held = ['llm_model' => 'gpt-5.4-mini'] + $this->held;
        $this->keep('update');
        $this->answers = ['PUT /'.self::AGENT => [503, ['error' => 'down']]];
        $this->restore($old)->assertOk()->assertJsonPath('stack.llmModel', 'gpt-5.4-mini')->assertJsonPath('syncState', 'pending');
        $this->assertSame('gpt-old', $this->held['llm_model'], 'not yet');
        $this->answers = [];
        $this->travel(1)->hours();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame('gpt-5.4-mini', $this->held['llm_model']);
        $this->assertSame('synced', $this->agent->fresh()->sync_state);
    }

    public function test_each_part_of_a_voice_setup_is_held_to_what_is_offered_on_its_own(): void
    {
        $old = $this->aVersionWith(['stt_provider' => 'other-stt', 'tts_voice' => 'no-longer', 'llm_model' => 'gpt-other']);
        $this->held = ['stt_provider' => 'deepgram', 'tts_voice' => 'alloy', 'llm_model' => 'gpt-5.4-mini'] + $this->held;
        $this->keep('update');
        $this->restore($old)->assertOk()->assertJsonPath('stack.llmModel', 'gpt-other');
        $this->assertSame(['deepgram', 'alloy', 'gpt-other'], [$this->held['stt_provider'], $this->held['tts_voice'], $this->held['llm_model']]);
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.kept', [__('agents.version_kept.stt'), __('agents.version_kept.tts')]);
    }

    public function test_a_setup_that_was_already_not_offered_stays_with_no_complaint_when_the_version_does_not_change_it(): void
    {
        $this->held['llm_model'] = 'gpt-old';
        $this->history[0]['snapshot']['llm_model'] = 'gpt-old';
        $this->agent->forceFill(['llm_model' => 'gpt-old'])->save();
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->restore(1)->assertOk()->assertJsonPath('stack.llmModel', 'gpt-old');
        $this->assertSame([], $this->puts());
    }

    public function test_what_is_not_part_of_a_version_stays_as_it_is(): void
    {
        $this->agent->forceFill(['after_call_email' => ['recipients' => [$this->ana->id], 'onlyAnswered' => false]])->save();
        $hook = (new AgentWebhook)->forceFill(['agent_id' => $this->agent->id, 'url' => 'https://example.com/calls', 'events' => ['call.completed'], 'include_transcript' => false, 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced']);
        $hook->save();
        $this->aVersionWith(['name' => 'Front desk 2']);
        $this->restore(1)->assertOk()->assertJsonPath('settings.emailAfterCall', ['recipients' => [$this->ana->id], 'onlyAnswered' => false]);
        $this->assertNull($hook->fresh()->deleted_at);
    }

    public function test_the_note_goes_after_a_day_and_with_the_next_restore(): void
    {
        app(Settings::class)->put(AgentSettings::TRANSFER_ALLOWED, false);
        $old = $this->aVersionWith(['metadata' => ['tools' => [$this->transfer()]]]);
        $this->held['metadata']['tools'] = [];
        $this->keep('update');
        $this->restore($old)->assertOk();
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.version', $old);
        $this->travel(config('kit.agents.restore_note_hours') - 1)->hours();
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.version', $old);
        $this->travel(2)->hours();
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore', null);
        $this->travelBack();
        $this->restore($old)->assertOk();
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore.version', $old);
        $this->restore(1)->assertOk();
        $this->actingAs($this->ana)->getJson($this->url())->assertJsonPath('lastRestore', null);
    }

    public function test_someone_elses_agent_a_bad_number_and_a_stranger_are_refused(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = new Agent(['name' => 'Theirs']);
        $theirs->workspace_id = $other->id;
        $theirs->forceFill(['telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();
        $root = "/api/kit/console/agents/{$theirs->id}/versions";
        $this->actingAs($this->ana)->getJson($root)->assertNotFound();
        $this->actingAs($this->ana)->getJson("{$root}/1/changes")->assertNotFound();
        $this->actingAs($this->ana)->getJson("{$root}/1/results")->assertNotFound();
        $this->actingAs($this->ana)->postJson("{$root}/1/restore")->assertNotFound();
        foreach (['first', '1234567890', '-1', '1.5'] as $bad) {
            $this->actingAs($this->ana)->getJson($this->url("/{$bad}/changes"))->assertNotFound();
            $this->actingAs($this->ana)->postJson($this->url("/{$bad}/restore"))->assertNotFound();
        }
        $this->assertSame([], $this->sent);
        $stranger = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->getJson($this->url())->assertForbidden();
        $this->actingAs($stranger)->postJson($this->url('/1/restore'))->assertForbidden();
    }

    public function test_the_recordings_are_what_this_rests_on(): void
    {
        $this->assertStringEndsWith('@sub-accounts.invalid', RecordedTelenow::body('listAgentVersions.history')['data']['versions'][0]['savedByEmail']);
        $this->assertSame('restore of v7', RecordedTelenow::body('listAgentVersions.restored')['data']['versions'][0]['note']);
        $this->assertSame(0.1234, RecordedTelenow::body('getAgentVersionPerf.customer')['data']['current']['totalChargeUsd']);
        $this->assertSame([], RecordedTelenow::body('diffAgentVersion.nothing-listed')['data']['changes']);
        $back = RecordedTelenow::body('restoreAgentVersion.deleted-target')['data']['metadata']['tools'];
        $this->assertSame([null, 'transfer', 'agent'], array_map(fn (array $t) => $t['kind'] ?? null, $back), 'a version comes back whole, a deleted agent and a transfer included');
        $this->assertStringStartsWith('••••', RecordedTelenow::body('restoreAgentVersion.with-key')['data']['metadata']['tools'][0]['bearer']);
        $this->assertSame('Version not found', RecordedTelenow::body('restoreAgentVersion.unknown')['error']);
    }
}
