<?php

namespace Tests\Feature\Kit;

use App\Kit\Agents\AgentSettings;
use App\Kit\Agents\AgentTransfers;
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
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * How an agent behaves on a call and after it (App\Kit\Agents\AgentSettings), through the kit's real routes. Telenow's answers are
 * real recordings of the 11-03 release (contract/telenow-mock/cassettes, contract_k1settings.py): what a create with these settings
 * stores, a read-merge-write with If-Match, a stale one refused. What was recorded and pinned here: a blob sent replaces the stored
 * one, Telenow keeps odd values as they are, and with nothing set a phone call opens in silence with no call limit.
 */
class AgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const AGENT = '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159';

    private const PATH = '/api/agents/'.self::AGENT;

    private User $ana;

    private Workspace $acme;

    /** @var list<array{method: string, path: string, raw: string, ifMatch: ?string}> */
    private array $sent = [];

    /** The agent as Telenow has it now: what a read answers, what a save replaces blobs of. */
    private array $telenow;

    private int $version = 2;

    private bool $conflictOnce = false;

    /** How many agents Telenow has made here: each after the first gets an id of its own, as telenow_id is unique. */
    private int $made = 0;

    /** Telenow refuses every save with this reason, as it does a bad value: the outbox gives up at once. */
    private ?string $refusal = null;

    /** What Telenow does to a save before it answers: it has the last word on what is stored. */
    private ?\Closure $telenowTidies = null;

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
        $this->telenow = RecordedTelenow::body('getAgent.before-settings')['data'];
        RecordedTelenow::fake();
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $r) use ($recorded) {
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            if (! str_starts_with($path, '/api/agents')) {
                return $recorded($r);
            }
            $this->sent[] = ['method' => $r->method(), 'path' => $path, 'raw' => $r->body(), 'ifMatch' => $r->header('If-Match')[0] ?? null];
            if ($r->method() === 'POST' && $path === '/api/agents' && $this->made++ > 0) {
                $another = RecordedTelenow::body('createAgent.customer');
                $another['data']['id'] = (string) Str::uuid();

                return Http::response($another, 201);
            }
            $asked = basename(dirname($path.'/x'));
            $path = (string) preg_replace('#^/api/agents/[0-9a-f-]{36}#', self::PATH, $path); // a test's agents share one state at Telenow
            if ($r->method() === 'GET' && $path === self::PATH) {
                return Http::response(['success' => true, 'data' => ['id' => $asked] + $this->telenow]);
            }
            if ($r->method() === 'GET' && $path === self::PATH.'/versions') {
                return Http::response(['success' => true, 'data' => ['versions' => [['version' => $this->version, 'note' => 'update']]]]);
            }
            if ($r->method() === 'PUT' && $path === self::PATH) {
                return $this->save($r, $asked);
            }

            return $recorded($r);
        });
    }

    /** As Telenow saves: a blob sent replaces the stored one whole, a precondition refuses with nothing written. */
    private function save(Request $r, string $id): mixed
    {
        if ($this->refusal !== null) {
            return Http::response(['success' => false, 'error' => $this->refusal], 400);
        }
        if ($this->conflictOnce) {
            $this->conflictOnce = false;
            $this->version++;

            return Http::response(RecordedTelenow::body('updateAgent.stale'), 409);
        }
        foreach (['telephonyConfig' => 'telephony_config', 'sessionConfig' => 'session_config', 'metadata' => 'metadata', 'name' => 'name', 'systemPrompt' => 'system_prompt', 'isActive' => 'is_active'] as $write => $column) {
            if (array_key_exists($write, $r->data())) {
                $this->telenow[$column] = $r->data()[$write];
            }
        }
        $this->version++;
        if ($this->telenowTidies !== null) {
            ($this->telenowTidies)();
        }

        return Http::response(['success' => true, 'data' => ['id' => $id] + $this->telenow, 'version' => $this->version]);
    }

    private function make(array $input = []): TestResponse
    {
        return $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $input + ['name' => 'Front desk', 'systemPrompt' => 'You answer the phone.']);
    }

    private function agent(): Agent
    {
        return Agent::query()->findOrFail($this->make()->assertCreated()->json('id'));
    }

    private function change(Agent $agent, array $settings, array $more = []): TestResponse
    {
        $this->sent = [];

        return $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['settings' => $settings] + $more);
    }

    /** @return list<array> what was sent to save, as JSON read plainly */
    private function saves(): array
    {
        return array_map(fn (array $s) => json_decode($s['raw'], true), array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'PUT')));
    }

    private function transfer(array $over = []): array
    {
        return $over + ['enabled' => true, 'destinations' => [['label' => null, 'numbers' => ['+15551234567'], 'mode' => 'serial']], 'message' => null, 'noAnswerMessage' => null];
    }

    private function analysis(array $over = []): array
    {
        return $over + ['enabled' => true, 'minDurationSec' => 30, 'minCustomerTurns' => 3, 'customFields' => [], 'qaCriteria' => []];
    }

    public function test_a_new_agent_has_a_limit_a_way_to_hang_up_and_opens_as_a_phone_call_will(): void
    {
        $this->make()->assertCreated();
        $body = collect(Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/agents')))->first()[0]->data();
        $this->assertSame(['firstResponse' => 'none', 'agentMsg' => '', 'firstResponseOutbound' => 'none', 'agentMsgOutbound' => '', 'recording' => true], $this->sorted($body['telephonyConfig'], ['firstResponse', 'agentMsg', 'firstResponseOutbound', 'agentMsgOutbound', 'recording']));
        $this->assertSame([600, 15, ['enabled' => true, 'secs' => 10]], [$body['sessionConfig']['maxDuration'], $body['sessionConfig']['silenceHangupSecs'], $body['sessionConfig']['silenceCheckin']]);
        $this->assertSame(['end_call'], array_column($body['metadata']['tools'], 'kind'));
        $this->assertSame(config('kit.agents.tool_descriptions.end_call'), $body['metadata']['tools'][0]['description']);
        $this->assertArrayHasKey('kitId', $body['metadata']);
    }

    /** @return array<string, mixed> `$blob`'s `$keys`, in that order */
    private function sorted(array $blob, array $keys): array
    {
        return array_merge(array_flip($keys), array_intersect_key($blob, array_flip($keys)));
    }

    public function test_what_a_new_agent_answers_comes_from_telenows_own_recording_of_that_create(): void
    {
        $settings = $this->make()->assertCreated()->json('settings');
        $this->assertSame([null, null, true, 600, 15, 10, true, false, false], [$settings['greetingInbound'], $settings['greetingOutbound'], $settings['recording'], $settings['maxDurationSecs'], $settings['silenceHangupSecs'],
            $settings['silenceCheckinSecs'], $settings['canEndCall'], $settings['transfer']['enabled'], $settings['analysis']['enabled']]);
    }

    public function test_a_new_agent_can_be_given_its_opening_line_and_limits_at_once(): void
    {
        $this->make(['settings' => ['greetingInbound' => '  Hi, thanks for calling.  ', 'maxDurationSecs' => 120]])->assertCreated();
        $body = collect(Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/agents')))->first()[0]->data();
        $this->assertSame(['agent', 'Hi, thanks for calling.', 'none'], [$body['telephonyConfig']['firstResponse'], $body['telephonyConfig']['agentMsg'], $body['telephonyConfig']['firstResponseOutbound']]);
        $this->assertSame(120, $body['sessionConfig']['maxDuration']);
    }

    public function test_a_change_reads_telenow_changes_only_those_keys_and_saves_only_at_that_version(): void
    {
        $agent = $this->agent();
        $this->telenow['telephony_config'] = ['firstResponse' => 'none', 'somethingElse' => 'kept'];
        $this->telenow['session_config'] = ['maxDuration' => 600, 'behavior' => ['tone' => 'warm']];
        $meta = $this->telenow['metadata'];
        $this->version = 7;

        $this->change($agent, ['greetingInbound' => 'Hi there.'])->assertOk()->assertJsonPath('settings.greetingInbound', 'Hi there.')->assertJsonPath('syncState', 'synced');

        $this->assertSame([['GET', self::PATH, null], ['GET', self::PATH.'/versions', null], ['PUT', self::PATH, '"7"']], array_map(fn ($s) => [$s['method'], $s['path'], $s['ifMatch']], $this->sent));
        $saved = $this->saves()[0];
        $this->assertSame(['telephonyConfig'], array_keys($saved), 'the blobs it did not touch are not sent');
        $this->assertSame(['firstResponse' => 'agent', 'somethingElse' => 'kept', 'agentMsg' => 'Hi there.'], $saved['telephonyConfig']);
        $this->assertSame($meta, $this->telenow['metadata']);
        $this->assertSame(['maxDuration' => 600, 'behavior' => ['tone' => 'warm']], $this->telenow['session_config']);
    }

    public function test_a_stored_empty_object_goes_back_as_an_empty_object(): void
    {
        $agent = $this->agent();
        $this->telenow['session_config'] = ['behavior' => new \stdClass, 'maxDuration' => 600];
        $this->change($agent, ['maxDurationSecs' => 300])->assertOk();
        $raw = collect($this->sent)->firstWhere('method', 'PUT')['raw'];
        $this->assertStringContainsString('"behavior":{}', $raw);
    }

    public function test_settings_and_plain_fields_go_in_one_save_and_nothing_changed_sends_nothing(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['greetingInbound' => 'Hi.'], ['name' => 'Night desk'])->assertOk();
        $this->assertSame(['telephonyConfig', 'name'], array_keys($this->saves()[0]));

        $this->change($agent->fresh(), ['greetingInbound' => 'Hi.', 'recording' => true, 'maxDurationSecs' => 600], ['name' => 'Night desk'])->assertOk();
        $this->assertSame([], $this->sent, 'all as it already is');
    }

    public function test_a_save_with_no_settings_reads_nothing_first(): void
    {
        $agent = $this->agent();
        $this->sent = [];
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['name' => 'Night desk'])->assertOk();
        $this->assertSame([['PUT', null]], array_map(fn ($s) => [$s['method'], $s['ifMatch']], $this->sent));
    }

    public function test_someone_saving_in_between_refuses_it_with_nothing_lost_and_the_next_try_starts_from_their_save(): void
    {
        $agent = $this->agent();
        $this->conflictOnce = true;
        $this->change($agent, ['greetingInbound' => 'Hi.'])->assertOk()->assertJsonPath('syncState', 'pending');
        $this->assertSame(0, Agent::query()->findOrFail($agent->id)->outbox_failed ?? 0);
        $this->assertNotSame('failed', $agent->fresh()->sync_state, 'a conflict is never a failure');

        $this->telenow['telephony_config']['theirs'] = 'kept';
        $this->travel(config('kit.outbox.not_yet_seconds') + 1)->seconds();
        $this->artisan('kit:outbox')->assertSuccessful();
        $this->assertSame('synced', $agent->fresh()->sync_state);
        $this->assertSame('kept', $this->telenow['telephony_config']['theirs']);
        $this->assertSame('Hi.', $this->telenow['telephony_config']['agentMsg']);
    }

    public function test_an_opening_line_taken_away_makes_the_agent_wait_for_the_caller(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['greetingInbound' => 'Hi.', 'greetingOutbound' => 'Hello.'])->assertOk();
        $this->assertSame(['agent', 'agent'], [$this->telenow['telephony_config']['firstResponse'], $this->telenow['telephony_config']['firstResponseOutbound']]);
        $this->change($agent->fresh(), ['greetingInbound' => '   '])->assertOk()->assertJsonPath('settings.greetingInbound', null)->assertJsonPath('settings.greetingOutbound', 'Hello.');
        $this->assertSame(['none', '', 'agent', 'Hello.'], [$this->telenow['telephony_config']['firstResponse'], $this->telenow['telephony_config']['agentMsg'],
            $this->telenow['telephony_config']['firstResponseOutbound'], $this->telenow['telephony_config']['agentMsgOutbound']]);
    }

    public function test_recording_limits_and_silence_are_written_where_telenow_reads_them(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['recording' => false, 'maxDurationSecs' => 300, 'maxDurationMessage' => 'Out of time.', 'silenceHangupSecs' => 20, 'silenceHangupMessage' => 'Bye.', 'silenceCheckinSecs' => 12, 'silenceCheckinMessage' => 'Still there?', 'voicemailMessage' => 'Call us back.'])
            ->assertOk()->assertJsonPath('settings.recording', false)->assertJsonPath('settings.silenceCheckinMessage', 'Still there?');
        $this->assertFalse($this->telenow['telephony_config']['recording']);
        $s = $this->telenow['session_config'];
        $this->assertSame([300, 'Out of time.', 20, 'Bye.', ['enabled' => true, 'secs' => 12, 'message' => 'Still there?']], [$s['maxDuration'], $s['maxDurationMessage'], $s['silenceHangupSecs'], $s['silenceHangupMessage'], $s['silenceCheckin']]);
        $this->assertSame('Call us back.', $this->telenow['metadata']['voicemailMessage']);
    }

    public function test_turning_silence_handling_off_and_taking_lines_away(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['maxDurationMessage' => 'Out of time.', 'silenceHangupMessage' => 'Bye.', 'silenceCheckinMessage' => 'Still there?', 'voicemailMessage' => 'Call us.'])->assertOk();
        $this->change($agent->fresh(), ['silenceHangupSecs' => null, 'silenceCheckinSecs' => null, 'maxDurationMessage' => null, 'silenceHangupMessage' => null, 'silenceCheckinMessage' => null, 'voicemailMessage' => ''])
            ->assertOk()->assertJsonPath('settings.silenceHangupSecs', null)->assertJsonPath('settings.silenceCheckinSecs', null)->assertJsonPath('settings.voicemailMessage', null);
        $s = $this->telenow['session_config'];
        $this->assertFalse(array_key_exists('silenceHangupSecs', $s) || array_key_exists('silenceHangupMessage', $s) || array_key_exists('maxDurationMessage', $s), json_encode($s));
        $this->assertSame(['enabled' => false], $s['silenceCheckin'], 'off is written off: with no block at all Telenow checks in anyway');
        $this->assertArrayNotHasKey('voicemailMessage', $this->telenow['metadata']);
    }

    public function test_an_agent_with_nothing_set_says_what_telenow_will_really_do(): void
    {
        $agent = $this->agent();
        $agent->forceFill(['settings' => null, 'telenow_snapshot' => RecordedTelenow::body('updateAgent.stack')['data']])->save();
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->assertOk()
            ->assertJsonPath('settings.greetingInbound', null)->assertJsonPath('settings.recording', true)->assertJsonPath('settings.maxDurationSecs', null)
            ->assertJsonPath('settings.silenceHangupSecs', null)->assertJsonPath('settings.silenceCheckinSecs', config('kit.agents.telenow_silence_checkin_seconds'))
            ->assertJsonPath('settings.canEndCall', false)->assertJsonPath('settings.analysis.minDurationSec', config('kit.agents.telenow_analysis_min_duration'));
    }

    public function test_what_telenow_answers_is_what_the_agent_then_says(): void
    {
        $agent = $this->agent();
        $this->telenowTidies = function () {
            $this->telenow['metadata']['postCallAnalysis']['minDurationSec'] = 60;
        };
        $this->change($agent, ['analysis' => $this->analysis()])->assertOk()->assertJsonPath('settings.analysis.minDurationSec', 60);
        $this->assertSame(60, $agent->fresh()->settings['analysis']['minDurationSec']);
    }

    public function test_an_opening_line_stored_with_its_mode_off_is_not_spoken_so_it_does_not_read_as_one(): void
    {
        $agent = $this->agent();
        foreach (['none', 'user', null] as $mode) {
            $object = $this->telenow;
            $object['telephony_config'] = array_filter(['firstResponse' => $mode, 'agentMsg' => 'Hi.'], fn ($v) => $v !== null);
            $agent->forceFill(['settings' => null, 'telenow_snapshot' => $object])->save();
            $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->assertJsonPath('settings.greetingInbound', null);
        }
    }

    public function test_a_line_telenow_keeps_with_its_translations_reads_as_its_text(): void
    {
        $agent = $this->agent();
        $object = $this->telenow;
        $object['session_config'] = ['maxDurationMessage' => ['source' => 'Out of time.', 'variants' => ['hi' => ['text' => 'x']]]];
        $agent->forceFill(['settings' => null, 'telenow_snapshot' => $object])->save();
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->assertJsonPath('settings.maxDurationMessage', 'Out of time.');
    }

    public function test_hanging_up_is_a_tool_of_its_own_and_other_tools_stay(): void
    {
        $agent = $this->agent();
        $other = ['name' => 'lookup', 'kind' => 'http', 'description' => 'Looks up an order', 'config' => ['url' => 'https://example.test/x']];
        $this->telenow['metadata']['tools'][] = $other;

        $this->change($agent, ['canEndCall' => false])->assertOk()->assertJsonPath('settings.canEndCall', false);
        $this->assertSame([$other], $this->telenow['metadata']['tools']);

        $this->change($agent->fresh(), ['canEndCall' => true])->assertOk()->assertJsonPath('settings.canEndCall', true);
        $this->assertSame(['http', 'end_call'], array_column($this->telenow['metadata']['tools'], 'kind'));
    }

    public function test_a_transfer_is_written_as_telenows_own_tool_with_the_numbers_tidied(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['transfer' => ['enabled' => true, 'message' => ' Connecting you. ', 'noAnswerMessage' => 'Nobody picked up.', 'destinations' => [
            ['label' => 'Billing', 'numbers' => ['+1 (555) 123-4567', '00 1 555 123 4568'], 'mode' => 'serial'],
            ['label' => 'Support', 'numbers' => ['+15551230000'], 'mode' => 'parallel'],
        ]]])->assertOk()->assertJsonPath('settings.transfer.enabled', true)->assertJsonPath('settings.transfer.destinations.0.numbers', ['+15551234567', '+15551234568']);
        $tool = collect($this->telenow['metadata']['tools'])->firstWhere('kind', 'transfer');
        $this->assertSame(['transfer_to_human', config('kit.agents.tool_descriptions.transfer'), 'Connecting you.', 'Nobody picked up.'], [$tool['name'], $tool['description'], $tool['config']['message'], $tool['config']['noAnswerMessage']]);
        $this->assertSame(['label' => 'Billing', 'numbers' => ['+15551234567', '+15551234568'], 'mode' => 'serial'], $tool['config']['destinations'][0]);
        $this->assertSame(['end_call', 'transfer'], array_column($this->telenow['metadata']['tools'], 'kind'));

        $this->change($agent->fresh(), ['transfer' => $this->transfer(['enabled' => false, 'destinations' => []])])->assertOk()->assertJsonPath('settings.transfer.enabled', false);
        $this->assertSame(['end_call'], array_column($this->telenow['metadata']['tools'], 'kind'));
    }

    public function test_a_single_transfer_with_no_name_leaves_the_label_out(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['transfer' => $this->transfer()])->assertOk();
        $this->assertSame(['numbers' => ['+15551234567'], 'mode' => 'serial'], collect($this->telenow['metadata']['tools'])->firstWhere('kind', 'transfer')['config']['destinations'][0]);
    }

    public function test_a_transfer_the_agent_could_not_make_is_refused_since_telenow_would_keep_it(): void
    {
        $agent = $this->agent();
        $d = fn (array $over = []) => $over + ['label' => 'Billing', 'numbers' => ['+15551234567'], 'mode' => 'serial'];
        $cases = [
            'settings.transfer.destinations.0.numbers.0' => [$d(['numbers' => ['not a number']])],
            'settings.transfer.destinations.0.numbers' => [$d(['numbers' => []])],
            'settings.transfer.destinations.0.mode' => [$d(['mode' => 'weird'])],
            'settings.transfer.destinations.1.label' => [$d(), $d(['label' => null, 'numbers' => ['+15551230000']])],
        ];
        foreach ($cases as $field => $destinations) {
            $this->change($agent, ['transfer' => $this->transfer(['destinations' => $destinations])])->assertStatus(422)->assertJsonValidationErrors([$field]);
        }
        $this->change($agent, ['transfer' => $this->transfer(['destinations' => [$d(), $d(['label' => 'billing', 'numbers' => ['+15551230000']])]])])->assertStatus(422)->assertJsonValidationErrors(['settings.transfer.destinations.1.label']);
        $this->change($agent, ['transfer' => $this->transfer(['destinations' => [$d(['numbers' => ['+15551234567', '+1 555 123 4567']])]])])->assertStatus(422)->assertJsonValidationErrors(['settings.transfer.destinations.0.numbers.1']);
        $this->change($agent, ['transfer' => $this->transfer(['destinations' => []])])->assertStatus(422)->assertJsonValidationErrors(['settings.transfer.destinations']);
        $max = config('kit.agents.limits.transfer_numbers_max');
        $this->change($agent, ['transfer' => $this->transfer(['destinations' => [$d(['numbers' => array_map(fn (int $i) => '+1555123'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), range(0, $max))])]])])->assertStatus(422)->assertJsonValidationErrors(['settings.transfer.destinations.0.numbers']);
        $this->assertSame([], $this->saves(), 'nothing refused reaches Telenow');
    }

    public function test_analysis_is_written_with_telenows_other_keys_of_the_block_left_alone(): void
    {
        $agent = $this->agent();
        $this->telenow['metadata']['postCallAnalysis'] = ['enabled' => false, 'model' => ['provider' => 'openai', 'model' => 'x'], 'sections' => ['judge' => false], 'basePrompt' => 'Ours'];
        $this->change($agent, ['analysis' => $this->analysis([
            'customFields' => [['key' => 'appointment_date', 'label' => 'Appointment date', 'type' => 'date', 'description' => 'The date booked.'], ['key' => 'plan', 'label' => null, 'type' => 'string', 'description' => null]],
            'qaCriteria' => [['key' => 'greeted', 'description' => 'It greeted the caller.']],
        ])])->assertOk()->assertJsonPath('settings.analysis.enabled', true)->assertJsonPath('settings.analysis.customFields.0.label', 'Appointment date');
        $pca = $this->telenow['metadata']['postCallAnalysis'];
        $this->assertSame([true, 30, 3, ['provider' => 'openai', 'model' => 'x'], ['judge' => false], 'Ours'], [$pca['enabled'], $pca['minDurationSec'], $pca['minCustomerTurns'], $pca['model'], $pca['sections'], $pca['basePrompt']]);
        $this->assertSame(['key' => 'plan', 'type' => 'string'], $pca['customFields'][1], 'nothing empty is sent');
        $this->assertSame([['key' => 'greeted', 'description' => 'It greeted the caller.']], $pca['qaCriteria']);
    }

    public function test_turning_analysis_off_keeps_what_was_set_up(): void
    {
        $agent = $this->agent();
        $fields = [['key' => 'plan', 'label' => null, 'type' => 'string', 'description' => null]];
        $this->change($agent, ['analysis' => $this->analysis(['customFields' => $fields])])->assertOk();
        $this->change($agent->fresh(), ['analysis' => $this->analysis(['enabled' => false, 'customFields' => $fields])])->assertOk()
            ->assertJsonPath('settings.analysis.enabled', false)->assertJsonPath('settings.analysis.customFields.0.key', 'plan');
    }

    public function test_analysis_rows_are_refused_when_telenow_could_not_use_them(): void
    {
        $agent = $this->agent();
        $row = ['key' => 'plan', 'label' => null, 'type' => 'string', 'description' => null];
        $this->change($agent, ['analysis' => $this->analysis(['customFields' => [['key' => 'Bad Key!'] + $row]])])->assertStatus(422)->assertJsonValidationErrors(['settings.analysis.customFields.0.key']);
        $this->change($agent, ['analysis' => $this->analysis(['customFields' => [['type' => 'nonsense'] + $row]])])->assertStatus(422)->assertJsonValidationErrors(['settings.analysis.customFields.0.type']);
        $this->change($agent, ['analysis' => $this->analysis(['customFields' => [$row, $row]])])->assertStatus(422)->assertJsonValidationErrors(['settings.analysis.customFields.1.key']);
        $this->change($agent, ['analysis' => $this->analysis(['qaCriteria' => [['key' => 'a', 'description' => null], ['key' => 'a', 'description' => null]]])])->assertStatus(422)->assertJsonValidationErrors(['settings.analysis.qaCriteria.1.key']);
        $this->change($agent, ['analysis' => $this->analysis(['minDurationSec' => config('kit.agents.limits.analysis_min_duration_max') + 1])])->assertStatus(422)->assertJsonValidationErrors(['settings.analysis.minDurationSec']);
        $this->change($agent, ['analysis' => $this->analysis(['minCustomerTurns' => -1])])->assertStatus(422)->assertJsonValidationErrors(['settings.analysis.minCustomerTurns']);
        $this->change($agent, ['analysis' => $this->analysis(['customFields' => array_map(fn (int $i) => ['key' => "f{$i}"] + $row, range(0, config('kit.agents.limits.analysis_fields_max')))])])->assertStatus(422)->assertJsonValidationErrors(['settings.analysis.customFields']);
        $this->assertSame([], $this->saves());
    }

    public function test_limits_are_the_kits_since_telenow_keeps_anything(): void
    {
        $agent = $this->agent();
        [$low, $high] = config('kit.agents.limits.max_duration_seconds');
        foreach ([$low - 1, $high + 1, null, 'soon'] as $bad) {
            $this->change($agent, ['maxDurationSecs' => $bad])->assertStatus(422)->assertJsonValidationErrors(['settings.maxDurationSecs']);
        }
        [$low, $high] = config('kit.agents.limits.silence_seconds');
        foreach ([$low - 1, $high + 1] as $bad) {
            $this->change($agent, ['silenceHangupSecs' => $bad])->assertStatus(422)->assertJsonValidationErrors(['settings.silenceHangupSecs']);
            $this->change($agent, ['silenceCheckinSecs' => $bad])->assertStatus(422)->assertJsonValidationErrors(['settings.silenceCheckinSecs']);
        }
        $long = str_repeat('x', config('kit.agents.limits.line_max_length') + 1);
        foreach (['greetingInbound', 'greetingOutbound', 'voicemailMessage', 'maxDurationMessage', 'silenceHangupMessage', 'silenceCheckinMessage'] as $line) {
            $this->change($agent, [$line => $long])->assertStatus(422)->assertJsonValidationErrors(["settings.{$line}"]);
        }
        $this->change($agent, ['recording' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors(['settings.recording']);
        $this->change($agent, ['canEndCall' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors(['settings.canEndCall']);
        $this->assertSame([], $this->saves());
    }

    public function test_a_setting_it_does_not_know_is_ignored(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['somethingNew' => 'x', 'recording' => false])->assertOk();
        $this->assertSame(['telephonyConfig'], array_keys($this->saves()[0]));
        $this->assertArrayNotHasKey('somethingNew', $agent->fresh()->settings);
    }

    public function test_it_answers_what_was_sent_while_telenow_cannot_take_it_and_keeps_trying(): void
    {
        $agent = $this->agent();
        Http::swap(new Factory);
        Http::fake(fn () => Http::response(['error' => 'down'], 503));
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$agent->id}", ['settings' => ['greetingInbound' => 'Hi.']])->assertOk()
            ->assertJsonPath('settings.greetingInbound', 'Hi.')->assertJsonPath('syncState', 'pending');
    }

    public function test_another_accounts_agent_cannot_be_reached_and_a_member_can_read_its_own(): void
    {
        $agent = $this->agent();
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->assertOk()->assertJsonStructure(['settings' => ['greetingInbound', 'transfer' => ['destinations'], 'analysis' => ['customFields', 'qaCriteria']]]);
        $other = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 8']);
        $theirs = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $theirs->id, 'user_id' => $other->id, 'role' => 'member']);
        $this->actingAs($other)->patchJson("/api/kit/console/agents/{$agent->id}", ['settings' => ['recording' => false]])->assertNotFound();
    }

    public function test_the_recordings_are_what_the_kit_reads_back(): void
    {
        $saved = RecordedTelenow::body('updateAgent.settings')['data'];
        $read = app(\App\Kit\Agents\AgentSettings::class)->read($saved);
        $this->assertSame('Hi, thanks for calling Acme Dental.', $read['greetingInbound']);
        $this->assertSame('Hi, this is Acme Dental.', $read['greetingOutbound']);
        $this->assertSame([300, 'We are out of time, goodbye.', 15, 'It seems you stepped away.', 12, 'Are you still there?'], [$read['maxDurationSecs'], $read['maxDurationMessage'], $read['silenceHangupSecs'], $read['silenceHangupMessage'], $read['silenceCheckinSecs'], $read['silenceCheckinMessage']]);
        $this->assertSame(['Billing', ['+15551234567', '+15551234568'], 'serial'], [$read['transfer']['destinations'][0]['label'], $read['transfer']['destinations'][0]['numbers'], $read['transfer']['destinations'][0]['mode']]);
        $this->assertSame([true, 20, 2, 'appointment_date', 'greeted'], [$read['analysis']['enabled'], $read['analysis']['minDurationSec'], $read['analysis']['minCustomerTurns'], $read['analysis']['customFields'][0]['key'], $read['analysis']['qaCriteria'][0]['key']]);
        $this->assertSame('agent_version_conflict', RecordedTelenow::body('updateAgent.stale')['code']);
        $this->assertSame(2, RecordedTelenow::body('listAgentVersions.customer')['data']['versions'][0]['version']);
    }

    private function agentWithTransfer(): Agent
    {
        $agent = $this->agent();
        $this->change($agent, ['transfer' => $this->transfer()])->assertOk();

        return $agent->fresh();
    }

    private function hasTransferAtTelenow(): bool
    {
        return in_array('transfer', array_column($this->telenow['metadata']['tools'], 'kind'), true);
    }

    public function test_a_customer_cannot_set_up_a_transfer_until_the_operator_allows_them(): void
    {
        app(Settings::class)->forget(AgentSettings::TRANSFER_ALLOWED);
        $agent = $this->agent();
        $this->change($agent, ['transfer' => $this->transfer()])->assertStatus(422)->assertJsonValidationErrors(['settings.transfer.enabled' => __('agents.transfer_off')]);
        $this->assertSame([], $this->saves());
        $this->make(['settings' => ['transfer' => $this->transfer()]])->assertStatus(422)->assertJsonValidationErrors('settings.transfer.enabled');
        $this->change($agent, ['transfer' => $this->transfer(['enabled' => false, 'destinations' => []])])->assertOk();
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents/settings-form')->assertOk()->assertJsonPath('transferAllowed', false);

        app(AgentTransfers::class)->save(true);
        $this->change($agent, ['transfer' => $this->transfer()])->assertOk()->assertJsonPath('settings.transfer.enabled', true);
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents/settings-form')->assertOk()->assertJsonPath('transferAllowed', true);
    }

    public function test_turning_transfers_off_takes_them_off_the_agents_that_have_them_and_nothing_else(): void
    {
        $agent = $this->agentWithTransfer();
        $this->assertTrue($this->hasTransferAtTelenow());
        $transfers = app(AgentTransfers::class);
        $this->assertSame(1, $transfers->stillOn());

        $this->sent = [];
        $this->artisan('kit:agents')->assertSuccessful();
        $this->assertSame([], $this->saves(), 'with the switch still on nothing is touched');
        $this->assertTrue($this->hasTransferAtTelenow());

        $transfers->save(false);
        $this->artisan('kit:agents')->assertSuccessful();
        $this->assertTrue(app(Settings::class)->has('agents.transfer_sweep_since'), 'it comes back once to see the write landed');
        $this->assertFalse($this->hasTransferAtTelenow());
        $this->assertSame(['end_call'], array_column($this->telenow['metadata']['tools'], 'kind'), 'its other tools stay');
        $this->assertCount(1, $this->saves());
        $this->assertSame(['metadata'], array_keys($this->saves()[0]), 'only the blob it lives in');
        $this->assertSame(0, $transfers->stillOn());
        $this->assertFalse($agent->fresh()->settings['transfer']['enabled']);
        $this->assertSame('synced', $agent->fresh()->sync_state);
        $this->assertFalse(app(AgentSettings::class)->transferAllowed());

        $this->artisan('kit:agents')->assertSuccessful();
        $this->assertFalse(app(Settings::class)->has('agents.transfer_sweep_since'), 'with none left it stops looking');
        $this->sent = [];
        $this->artisan('kit:agents')->assertSuccessful();
        $this->assertSame([], $this->sent);
    }

    public function test_turning_transfers_back_on_before_the_job_comes_round_leaves_them_alone(): void
    {
        $agent = $this->agentWithTransfer();
        $transfers = app(AgentTransfers::class);
        $transfers->save(false);
        $transfers->save(true);
        $this->sent = [];
        $this->assertSame(0, $transfers->sweep());
        $this->assertSame([], $this->saves());
        $this->assertTrue($this->hasTransferAtTelenow());
        $this->assertTrue($agent->fresh()->settings['transfer']['enabled']);
    }

    public function test_it_takes_at_most_so_many_agents_off_a_run_and_comes_back_for_the_rest(): void
    {
        config(['kit.agents.transfer_sweep_per_run' => 1]);
        $one = $this->agentWithTransfer();
        $two = $this->agentWithTransfer();
        $this->assertSame(['synced', 'synced'], [$one->sync_state, $two->sync_state]);
        $this->assertNotSame($one->telenow_id, $two->telenow_id);
        $transfers = app(AgentTransfers::class);
        $transfers->save(false);
        $states = fn () => array_map(fn (Agent $a) => $a->fresh()->settings['transfer']['enabled'], [$one, $two]);

        $this->assertSame(1, $transfers->sweep());
        $this->assertCount(1, array_keys($states(), true, true));
        $this->assertSame(1, $transfers->sweep());
        $this->assertSame([false, false], $states());
        $this->assertSame(0, $transfers->sweep());
        $this->assertSame(0, $transfers->stillOn());
    }

    public function test_a_write_telenow_gave_up_on_is_tried_again_only_when_the_operator_saves_again(): void
    {
        $agent = $this->agentWithTransfer();
        $transfers = app(AgentTransfers::class);
        $transfers->save(false);
        $this->refusal = 'Nothing here is acceptable';
        $this->assertSame(1, $transfers->sweep());
        $this->assertSame('failed', $agent->fresh()->sync_state);
        $this->assertSame(1, $transfers->stillOn(), 'still at the platform, so still counted');

        $this->sent = [];
        $this->assertSame(0, $transfers->sweep());
        $this->assertSame([], $this->saves(), 'not sent over and over');
        $this->assertFalse(app(Settings::class)->has('agents.transfer_sweep_since'), 'nothing left it can do by itself');
        $this->assertSame(1, $transfers->stillOn(), 'and the page goes on saying so');

        $this->travel(1)->minutes();
        $this->refusal = null;
        $transfers->save(false);
        $this->assertSame(1, $transfers->sweep());
        $this->assertFalse($this->hasTransferAtTelenow());
        $this->assertSame('synced', $agent->fresh()->sync_state);
        $this->assertSame(0, $transfers->stillOn());
    }

    public function test_a_write_still_going_is_waited_for_and_not_written_again(): void
    {
        $agent = $this->agentWithTransfer();
        $transfers = app(AgentTransfers::class);
        $transfers->save(false);
        $agent->forceFill(['sync_state' => 'pending', 'settings' => ['transfer' => ['enabled' => false, 'destinations' => [], 'message' => null, 'noAnswerMessage' => null]] + $agent->settings])->save();
        $this->sent = [];
        $this->assertSame(0, $transfers->sweep());
        $this->assertSame([], $this->saves());
        $this->assertTrue(app(Settings::class)->has('agents.transfer_sweep_since'), 'it comes back to see');
    }

    public function test_it_finds_an_agent_whose_transfer_has_not_reached_the_platform_yet(): void
    {
        $agent = $this->agent();
        $tools = array_column($this->telenow['metadata']['tools'], 'kind');
        $this->assertNotContains('transfer', $tools);
        $agent->forceFill(['sync_state' => 'pending', 'settings' => ['transfer' => $this->transfer()] + $agent->settings])->save();
        $this->assertSame(1, app(AgentTransfers::class)->stillOn());
        app(AgentTransfers::class)->save(false);
        $this->assertSame(1, app(AgentTransfers::class)->sweep());
        $this->assertFalse($agent->fresh()->settings['transfer']['enabled']);
    }

    public function test_deleted_agents_closed_customers_and_words_in_a_prompt_are_not_transfers(): void
    {
        $transfers = app(AgentTransfers::class);
        $gone = $this->agentWithTransfer();
        $gone->forceFill(['deleted_at' => now()])->save();
        $this->assertSame(0, $transfers->stillOn());

        $closed = Workspace::create(['name' => 'Closed', 'status' => Workspace::CLOSED, 'sync_state' => 'synced']);
        $left = new Agent(['name' => 'Left behind']);
        $left->workspace_id = $closed->id;
        $left->forceFill(['telenow_snapshot' => ['metadata' => ['tools' => [['kind' => 'transfer']]]]])->save();
        $this->assertSame(0, $transfers->stillOn());

        $wordy = $this->agent();
        $wordy->forceFill(['telenow_snapshot' => ['system_prompt' => 'Use {"kind":"transfer"} and "transfer":{"enabled":true when asked']])->save();
        $this->assertSame(0, $transfers->stillOn());
    }

    /** Every setting of how it speaks and listens, and what the kit writes for it: Telenow's recording of exactly these (contract_k1settings.py). */
    private function everyBehaviour(): array
    {
        return [
            'tone' => 'professional_conversational', 'personaGender' => 'female', 'naturalFillers' => true, 'highEmpathy' => true, 'echoVerification' => true, 'natoPhonetic' => true,
            'speechNormalization' => true, 'smartMatching' => true, 'scopeBoundaries' => true, 'aiDisclosure' => false,
            'interruptions' => 'hard', 'rememberInterruptions' => true, 'noisyRoom' => true, 'listeningSounds' => true, 'ignoreSpeechBeforeGreeting' => true,
            'thinkingFillers' => true, 'backgroundSound' => 'call_center', 'backgroundVolume' => 30, 'ringInbound' => true, 'ringOutbound' => false, 'ringSeconds' => 6,
            'contextMode' => 'window', 'contextTurns' => 25, 'callerMemory' => true, 'callerMemoryDays' => 30,
            'keypad' => true, 'keypadWaitMs' => 3000, 'keypadEndKey' => '#', 'keypadDigits' => 8, 'optOut' => false,
            'fastOpener' => true, 'greetingDelaySecs' => 2, 'ringDurationSecs' => 45, 'callScreen' => true, 'callScreenMessage' => 'Who is calling, please?', 'ivrHangup' => true,
        ];
    }

    public function test_a_new_agent_starts_as_the_platforms_own_builder_starts_one(): void
    {
        $settings = $this->make()->assertCreated()->json('settings');
        $body = collect(Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/agents')))->first()[0]->data();
        $this->assertSame(['speechNormalization' => true, 'smartMatching' => true, 'scopeBoundaries' => true], $body['sessionConfig']['behavior']);
        $this->assertSame([['enabled' => true], 'summary', 10], [$body['sessionConfig']['backchannel'], $body['sessionConfig']['contextMode'], $body['sessionConfig']['contextWindow']]);
        $this->assertSame([true, true, true, true, 'summary', 10, 'professional', true, 'normal', true], [$settings['speechNormalization'], $settings['smartMatching'], $settings['scopeBoundaries'], $settings['listeningSounds'],
            $settings['contextMode'], $settings['contextTurns'], $settings['tone'], $settings['aiDisclosure'], $settings['interruptions'], $settings['optOut']]);
    }

    public function test_each_setting_goes_in_the_place_and_the_exact_type_the_platform_recorded_and_parses(): void
    {
        $agent = $this->agent();
        $this->change($agent, $this->everyBehaviour())->assertOk();
        $save = $this->saves()[0];
        $recorded = RecordedTelenow::body('updateAgent.settings')['data'];
        unset($recorded['session_config']['ringSound']['outbound']); // false already, so no change to send
        foreach (['sessionConfig' => 'session_config', 'telephonyConfig' => 'telephony_config', 'metadata' => 'metadata'] as $write => $column) {
            foreach (['behavior', 'personaGender', 'bargeInSensitivity', 'rememberInterruptions', 'directedSpeechGate', 'backchannel', 'fillers', 'backgroundSound', 'ringSound', 'contextMode', 'contextWindow', 'keypadInput', 'optOut',
                'ignoreSpeechBeforeGreeting', 'fastOpener', 'greetingDelaySecs', 'ringDurationSecs', 'callScreen', 'ivrHangup', 'gender', 'callerMemory'] as $key) {
                if (array_key_exists($key, $recorded[$column])) {
                    $sent = $save[$write][$key];
                    $want = $recorded[$column][$key];
                    is_array($want) && ksort($want);
                    is_array($sent) && ksort($sent);
                    $this->assertSame($want, $sent, "{$column}.{$key}");
                }
            }
        }
    }

    public function test_what_the_platform_stored_reads_back_as_each_setting(): void
    {
        $read = app(AgentSettings::class)->read(RecordedTelenow::body('updateAgent.settings')['data']);
        $want = ['aiDisclosure' => true] + $this->everyBehaviour();
        $this->assertSame($want, array_intersect_key($read, $want), 'that recording has a transfer tool, so it says it is an AI although false is stored');
        $this->assertFalse(RecordedTelenow::body('updateAgent.settings')['data']['session_config']['behavior']['aiDisclosureWhenAsked']);
    }

    public function test_with_nothing_stored_each_setting_reads_as_the_platform_will_act(): void
    {
        $read = app(AgentSettings::class)->read([]);
        $this->assertSame([
            'tone' => 'professional', 'personaGender' => null, 'naturalFillers' => false, 'aiDisclosure' => true, 'interruptions' => 'normal', 'rememberInterruptions' => false, 'noisyRoom' => false,
            'listeningSounds' => false, 'thinkingFillers' => false, 'backgroundSound' => null, 'backgroundVolume' => 50, 'ringSeconds' => 4, 'contextMode' => 'window', 'contextTurns' => 10,
            'callerMemory' => false, 'callerMemoryDays' => 90, 'keypad' => false, 'keypadWaitMs' => 2500, 'keypadEndKey' => null, 'keypadDigits' => 0, 'optOut' => true, 'greetingDelaySecs' => 0, 'ringDurationSecs' => null, 'ivrHangup' => false,
        ], array_intersect_key($read, array_flip(['tone', 'personaGender', 'naturalFillers', 'aiDisclosure', 'interruptions', 'rememberInterruptions', 'noisyRoom', 'listeningSounds', 'thinkingFillers', 'backgroundSound', 'backgroundVolume', 'ringSeconds', 'contextMode', 'contextTurns', 'callerMemory', 'callerMemoryDays', 'keypad', 'keypadWaitMs', 'keypadEndKey', 'keypadDigits', 'optOut', 'greetingDelaySecs', 'ringDurationSecs', 'ivrHangup'])));
    }

    public function test_a_number_or_word_nobody_here_wrote_reads_as_something_true(): void
    {
        $settings = app(AgentSettings::class);
        $session = fn (array $s) => $settings->read(['session_config' => $s]);
        foreach ([[0, 'never'], [0.0, 'never'], [-1, 'never'], [0.01, 'hard'], [0.3, 'hard'], [0.4, 'normal'], [0.5, 'normal'], [0.7, 'easy'], [1, 'easy'], ['loud', 'normal'], [null, 'normal']] as [$number, $word]) {
            $this->assertSame($word, $session(['bargeInSensitivity' => $number])['interruptions'], json_encode($number));
        }
        $this->assertSame('professional', $session(['behavior' => ['tone' => 'gruff']])['tone']);
        $this->assertSame('office', $session(['backgroundSound' => ['enabled' => true]])['backgroundSound'], 'on with no preset');
        $this->assertSame('office', $session(['backgroundSound' => ['enabled' => true, 'preset' => 'whale']])['backgroundSound']);
        $this->assertNull($session(['backgroundSound' => ['enabled' => false, 'preset' => 'rain']])['backgroundSound']);
        $this->assertNull($session(['backgroundSound' => 'rain'])['backgroundSound']);
        $this->assertSame(4, $session(['ringSound' => ['secs' => 'long']])['ringSeconds']);
        $this->assertTrue($session(['optOut' => 'no'])['optOut']);
        $this->assertFalse($session(['behavior' => ['highEmpathy' => 'yes']])['highEmpathy'], 'a word is not a yes: the platform would not take it as one');
        $this->assertTrue($session(['behavior' => new \stdClass])['aiDisclosure'], 'an empty block stored as {}');
        $this->assertNull($session(['personaGender' => 'robot'])['personaGender']);
    }

    public function test_only_what_changed_goes_and_the_rest_of_each_block_stays(): void
    {
        $agent = $this->agent();
        $this->telenow['session_config'] = ['behavior' => ['mustDeliverMarkers' => ['Terms apply.'], 'tone' => 'professional'], 'keypadInput' => ['enabled' => true, 'storeAs' => 'pin'], 'somethingElse' => 1];
        $this->telenow['telephony_config'] = ['recording' => true];
        $agent->forceFill(['settings' => null, 'telenow_snapshot' => $this->telenow])->save();

        $this->change($agent->fresh(), ['highEmpathy' => true, 'keypadDigits' => 4])->assertOk();
        $save = $this->saves()[0];
        $this->assertSame(['sessionConfig'], array_keys($save));
        $this->assertSame(['mustDeliverMarkers' => ['Terms apply.'], 'tone' => 'professional', 'highEmpathy' => true], $save['sessionConfig']['behavior']);
        $this->assertSame(['enabled' => true, 'storeAs' => 'pin', 'digitLimit' => 4], $save['sessionConfig']['keypadInput']);
        $this->assertSame(1, $save['sessionConfig']['somethingElse']);

        $this->sent = [];
        $this->change($agent->fresh(), $this->read($agent))->assertOk();
        $this->assertSame([], $this->saves(), 'saving what it has sends nothing');
    }

    /** Every setting of how it speaks and listens, as the agent answers them now. */
    private function read(Agent $agent): array
    {
        return array_intersect_key($this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$agent->id}")->assertOk()->json('settings'), $this->everyBehaviour());
    }

    public function test_what_is_off_by_not_being_there_goes_and_no_empty_block_is_left_behind(): void
    {
        $agent = $this->agent();
        $this->change($agent, $this->everyBehaviour())->assertOk();
        $this->change($agent->fresh(), ['noisyRoom' => false, 'fastOpener' => false, 'ignoreSpeechBeforeGreeting' => false, 'ringDurationSecs' => null, 'greetingDelaySecs' => null, 'personaGender' => null,
            'callScreenMessage' => '   ', 'callerMemoryDays' => null, 'keypadEndKey' => ''])->assertOk();
        $save = $this->saves()[0];
        foreach (['directedSpeechGate', 'personaGender'] as $gone) {
            $this->assertArrayNotHasKey($gone, $save['sessionConfig']);
        }
        foreach (['fastOpener', 'ignoreSpeechBeforeGreeting', 'ringDurationSecs', 'greetingDelaySecs'] as $gone) {
            $this->assertArrayNotHasKey($gone, $save['telephonyConfig']);
        }
        $this->assertArrayNotHasKey('gender', $save['metadata']);
        $this->assertSame(['enabled' => true], $save['telephonyConfig']['callScreen']);
        $this->assertSame(['enabled' => true], $save['metadata']['callerMemory']);
        $this->assertArrayNotHasKey('terminationKey', $save['sessionConfig']['keypadInput']);

        $this->telenow['metadata']['callerMemory'] = ['ttlDays' => 30];
        $this->telenow['session_config']['ringSound'] = ['secs' => 6];
        $agent->forceFill(['settings' => null, 'telenow_snapshot' => $this->telenow])->save();
        $this->change($agent->fresh(), ['callerMemoryDays' => null, 'ringSeconds' => null])->assertOk();
        $raw = collect($this->sent)->firstWhere('method', 'PUT')['raw'];
        $this->assertStringNotContainsString('"callerMemory"', $raw, 'an empty PHP array would go as a list, which the platform cannot parse');
        $this->assertStringNotContainsString('"ringSound"', $raw);
    }

    public function test_speaks_as_goes_to_both_places_it_is_kept_when_it_is_all_that_changed(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['personaGender' => 'male'])->assertOk();
        $this->assertSame(['sessionConfig', 'metadata'], array_keys($this->saves()[0]));
        $this->assertSame(['male', 'male'], [$this->saves()[0]['sessionConfig']['personaGender'], $this->saves()[0]['metadata']['gender']]);
    }

    public function test_background_sound_off_keeps_the_rest_of_its_block_and_on_again_picks_it_up(): void
    {
        $agent = $this->agent();
        $this->change($agent, ['backgroundSound' => 'rain', 'backgroundVolume' => 20])->assertOk()->assertJsonPath('settings.backgroundSound', 'rain')->assertJsonPath('settings.backgroundVolume', 20);
        $this->assertEqualsWithDelta(0.2, $this->telenow['session_config']['backgroundSound']['volume'], 0.0001);
        $this->assertSame([true, 'rain'], [$this->telenow['session_config']['backgroundSound']['enabled'], $this->telenow['session_config']['backgroundSound']['preset']]);

        $this->change($agent->fresh(), ['backgroundSound' => null])->assertOk()->assertJsonPath('settings.backgroundSound', null)->assertJsonPath('settings.backgroundVolume', 20);
        $this->assertFalse($this->telenow['session_config']['backgroundSound']['enabled']);

        $this->change($agent->fresh(), ['backgroundVolume' => 100, 'backgroundSound' => 'traffic'])->assertOk()->assertJsonPath('settings.backgroundSound', 'traffic');
        $this->assertStringContainsString('"volume":1', collect($this->sent)->firstWhere('method', 'PUT')['raw']);
        $this->change($agent->fresh(), ['backgroundVolume' => null])->assertOk()->assertJsonPath('settings.backgroundVolume', 50);
        $this->assertArrayNotHasKey('volume', $this->telenow['session_config']['backgroundSound']);
    }

    public function test_how_easily_it_can_be_interrupted_goes_as_the_number_the_platform_acts_on(): void
    {
        $agent = $this->agent();
        foreach (['never' => '0', 'easy' => '0.85', 'hard' => '0.15', 'normal' => '0.5'] as $word => $number) {
            $this->change($agent->fresh(), ['interruptions' => $word])->assertOk()->assertJsonPath('settings.interruptions', $word);
            $this->assertMatchesRegularExpression('/"bargeInSensitivity":'.preg_quote($number, '/').'[,}]/', collect($this->sent)->firstWhere('method', 'PUT')['raw'], $word);
        }
    }

    public function test_a_setting_out_of_bounds_or_of_the_wrong_kind_is_refused_since_one_would_turn_every_other_off(): void
    {
        $agent = $this->agent();
        $over = str_repeat('x', config('kit.agents.limits.line_max_length') + 1);
        foreach ([
            'tone' => ['warm', null, 3], 'personaGender' => ['robot', 3], 'interruptions' => ['loud', null, 0.5], 'backgroundSound' => ['whale'], 'contextMode' => ['all', null], 'keypadEndKey' => ['x', '##'],
            'highEmpathy' => ['maybe', null, 2], 'noisyRoom' => ['maybe', null], 'optOut' => ['maybe', null], 'ivrHangup' => [[], null],
            'backgroundVolume' => [-1, 101, 'loud', 1.5], 'ringSeconds' => [0, 31], 'contextTurns' => [0, 201], 'callerMemoryDays' => [0, 3651], 'keypadWaitMs' => [499, 15001], 'keypadDigits' => [-1, 33],
            'greetingDelaySecs' => [-1, 11], 'ringDurationSecs' => [4, 121], 'callScreenMessage' => [$over, 3],
        ] as $key => $values) {
            foreach ($values as $value) {
                $this->change($agent, [$key => $value])->assertStatus(422)->assertJsonValidationErrors("settings.{$key}");
                $this->assertSame([], $this->saves(), "{$key}: nothing is sent");
            }
        }
        $limits = config('kit.agents.limits');
        foreach (['backgroundVolume' => 'background_volume_percent', 'ringSeconds' => 'ring_sound_seconds', 'contextTurns' => 'context_turns', 'callerMemoryDays' => 'caller_memory_days', 'keypadWaitMs' => 'keypad_wait_ms', 'keypadDigits' => 'keypad_digits', 'greetingDelaySecs' => 'greeting_delay_seconds', 'ringDurationSecs' => 'ring_duration_seconds'] as $key => $name) {
            foreach ($limits[$name] as $edge) {
                $this->change($agent->fresh(), [$key => $edge])->assertOk();
            }
        }
        $this->change($agent->fresh(), ['keypadDigits' => '7'])->assertOk()->assertJsonPath('settings.keypadDigits', 7);
        $this->assertSame(7, $this->saves()[0]['sessionConfig']['keypadInput']['digitLimit'], 'typed in a box or sent as a number, it goes as a number');
    }

    public function test_a_new_agent_can_be_given_how_it_speaks_at_once(): void
    {
        $this->make(['settings' => ['tone' => 'professional_conversational', 'scopeBoundaries' => false]])->assertCreated();
        $body = collect(Http::recorded(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/agents')))->first()[0]->data();
        $this->assertSame(['tone' => 'professional_conversational', 'scopeBoundaries' => false, 'speechNormalization' => true, 'smartMatching' => true], $this->sorted($body['sessionConfig']['behavior'], ['tone', 'scopeBoundaries', 'speechNormalization', 'smartMatching']));
    }

    public function test_the_form_declares_every_setting_in_the_customers_words(): void
    {
        $answer = $this->actingAs($this->ana)->getJson('/api/kit/console/agents/settings-form')->assertOk();
        $fields = collect($answer->json('groups'))->flatMap(fn (array $g) => $g['fields']);
        $this->assertSame(array_keys($this->everyBehaviour()), $fields->pluck('name')->all(), 'each setting once, in the order it is shown');
        foreach ($answer->json('groups') as $g) {
            $this->assertNotSame("agents.behaviour.groups.{$g['id']}.title", $g['title']);
            $this->assertNotSame("agents.behaviour.groups.{$g['id']}.description", $g['description']);
        }
        $names = $fields->pluck('name')->all();
        foreach ($fields as $f) {
            $this->assertStringNotContainsString('agents.behaviour', json_encode($f), "{$f['name']} has all its words");
            $this->assertNull($f['value']);
            if ($f['showWhen'] !== null) {
                $this->assertContains($f['showWhen']['field'], $names);
                $this->assertNotEmpty($f['showWhen']['values']);
            }
            if ($f['type'] === 'select') {
                $this->assertNotEmpty($f['options'], $f['name']);
            }
        }
        $by = $fields->keyBy('name');
        $this->assertSame(['checkbox', 'select', 'number', 'text'], [$by['highEmpathy']['type'], $by['tone']['type'], $by['keypadDigits']['type'], $by['callScreenMessage']['type']]);
        $this->assertSame('', $by['personaGender']['options'][0]['value'], 'a choice that can be left unset offers that');
        $this->assertNotSame('', $by['tone']['options'][0]['value']);
        $this->assertSame(['never', 'hard', 'normal', 'easy'], array_column($by['interruptions']['options'], 'value'));
        $this->assertSame(['field' => 'keypad', 'values' => ['1']], $by['keypadDigits']['showWhen']);
        $this->assertSame(config('kit.agents.choices.background_sounds'), $by['backgroundVolume']['showWhen']['values']);
        $this->assertSame('#', $by['keypadEndKey']['options'][1]['label']);
        $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $answer->getContent(), 'a customer never sees its name');
    }

    public function test_the_form_is_only_for_people_signed_in_to_an_account(): void
    {
        $this->getJson('/api/kit/console/agents/settings-form')->assertUnauthorized();
        $stranger = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->getJson('/api/kit/console/agents/settings-form')->assertForbidden();
    }
}
