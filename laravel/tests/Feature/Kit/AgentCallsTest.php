<?php

namespace Tests\Feature\Kit;

use App\Models\Agent;
use App\Models\Call;
use App\Models\ConsentRecord;
use App\Models\DncEntry;
use App\Models\PhoneNumber;
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
 * An agent calling one person (App\Kit\Agents\AgentCalls), through the kit's real routes, against Telenow's recorded answers (contract_k11agentcall.py, the 11-03 release with a stand-in carrier):
 * it dials words and a number with no +, takes the caller ID by its row, refuses a missing detail with 400 and its own lists with 403, answers the same call for the same Idempotency-Key, and
 * takes two calls at once from a number.
 */
class AgentCallsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private const SESSION = '5e551011-0000-4000-8000-000000000005';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private Agent $agent;

    private PhoneNumber $own;

    private PhoneNumber $other;

    /** What the dial answers instead of its recording: a recording's name, or a closure. */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY, 'balance' => '50'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'owner']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Reminders', 'system_prompt' => 'You remind {first_name} about appointment {account_ref}. Call {contact_number} back if cut off.']);
        $this->agent->forceFill(['telenow_id' => '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159', 'sync_state' => 'synced',
            'settings' => ['greetingOutbound' => 'Hi {first_name}, it is {clinic} calling about {visit_day}.', 'lookups' => [['name' => 'crm', 'fills' => [['variable' => 'clinic', 'path' => 'clinic']]]]],
            'telenow_snapshot' => ['metadata' => ['variables' => [['name' => 'account_ref', 'required' => true], ['name' => 'clinic', 'required' => true]]]]])->save();
        $this->own = $this->number('918045671001', ['agent_id' => $this->agent->id, 'telenow_id' => '9c4d1e2f-3a4b-4c5d-8e6f-7a8b9c0d1e2f']);
        $this->other = $this->number('918045671002');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(function (Request $r) use ($recorded) {
            if ($r->method() === 'POST' && parse_url($r->url(), PHP_URL_PATH) === '/api/sessions/initiate-call') {
                $answer = array_shift($this->answers) ?? 'initiateCall.agent';

                return $answer instanceof \Closure ? $answer($r) : RecordedTelenow::response($answer);
            }

            return $recorded($r);
        });
    }

    private function number(string $e164, array $over = []): PhoneNumber
    {
        $n = new PhoneNumber;
        $n->forceFill($over + ['workspace_id' => $this->acme->id, 'e164' => $e164, 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced'])->save();

        return $n;
    }

    private function dial(array $input = [], ?User $who = null, ?Agent $agent = null): TestResponse
    {
        return $this->actingAs($who ?? $this->ben)->postJson('/api/kit/console/agents/'.($agent ?? $this->agent)->id.'/calls',
            $input + ['number' => '+1 415 555 0131', 'variables' => ['account_ref' => 'A-17', 'first_name' => 'Ana']]);
    }

    /** @return list<Request> the dials Telenow was asked for */
    private function dials(): array
    {
        return Http::recorded(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH) === '/api/sessions/initiate-call')->map(fn ($p) => $p[0])->values()->all();
    }

    public function test_the_form_offers_the_accounts_numbers_the_agents_own_and_the_details_its_words_use(): void
    {
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}/call-form")->assertOk()->assertExactJson([
            'callerIds' => [['id' => $this->own->id, 'number' => '+918045671001'], ['id' => $this->other->id, 'number' => '+918045671002']],
            'ownNumber' => $this->own->id,
            'variables' => [['name' => 'account_ref', 'required' => true], ['name' => 'first_name', 'required' => false], ['name' => 'visit_day', 'required' => false]],
            'machineAnswers' => ['voicemail', 'hang_up'],
            'paused' => null,
            'limits' => ['numberMaxLength' => 40, 'variableValueMaxLength' => 500, 'openingLineMaxLength' => 1000],
        ]);
        $this->assertSame([], $this->dials(), 'the form asks the voice platform nothing');
    }

    public function test_the_agent_calls_from_its_own_number_with_the_details_and_the_call_is_kept_at_once_with_who_asked(): void
    {
        $answer = $this->dial(['openingLine' => 'Hi {first_name}, a quick reminder.', 'machine' => 'hang_up', 'variables' => ['account_ref' => 'A-17', 'first_name' => 'Ana', 'unused' => '']])->assertCreated();
        $call = Call::query()->sole();
        $answer->assertExactJson(['callId' => $call->id]);
        [$dial] = $this->dials();
        $this->assertSame([
            'agentId' => '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159', 'mobileNumber' => '+14155550131', 'fromNumberId' => '9c4d1e2f-3a4b-4c5d-8e6f-7a8b9c0d1e2f',
            'variables' => ['account_ref' => 'A-17', 'first_name' => 'Ana'], 'firstResponse' => 'Hi {first_name}, a quick reminder.', 'machineDetection' => 'hangup',
        ], $dial->data(), 'its own number by its row, the number with its +, only the details given, the machine answer in its words');
        $this->assertTrue(Str::isUuid($dial->header('Idempotency-Key')[0] ?? ''), 'an Idempotency-Key of its own');
        $this->assertSame([self::KEY], $dial->header('X-API-Key'));
        $this->assertSame(
            [self::SESSION, $this->agent->id, (int) $this->ben->id, null, 'outbound', 'telephony', '918045671001', '+14155550131', 'pending', 'active'],
            [$call->telenow_id, $call->agent_id, (int) $call->requested_by, $call->placed_by, $call->direction, $call->channel, $call->from_number, $call->to_number, $call->detail_state, $call->status],
        );
        $this->assertTrue($call->next_read_at->isFuture(), 'read once it has had time to end');
        $this->actingAs($this->ben)->getJson('/api/kit/console/calls')->assertJsonPath('calls.0.requestedBy', ['name' => 'Ben', 'me' => true])
            ->assertJsonPath('calls.0.agent.name', 'Reminders')->assertJsonPath('calls.0.placedBy', null);
        $this->actingAs($this->ana)->getJson("/api/kit/console/calls/{$call->id}")->assertJsonPath('requestedBy', ['name' => 'Ben', 'me' => false]);
    }

    public function test_another_of_the_accounts_numbers_can_be_picked_and_none_but_its_own(): void
    {
        config(['kit.agent_calls.calls_per_minute' => 100]);
        $this->dial(['callerId' => $this->other->id])->assertCreated();
        $this->assertSame($this->other->telenow_id, $this->dials()[0]->data()['fromNumberId']);
        $foreign = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new PhoneNumber)->forceFill(['workspace_id' => $foreign->id, 'e164' => '918045671009', 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced']);
        $theirs->save();
        $released = $this->number('918045671003', ['released_at' => now()]);
        $unsaved = $this->number('918045671004', ['telenow_id' => null]);
        foreach ([$theirs->id, $released->id, $unsaved->id, 'not-an-id'] as $from) {
            $this->dial(['callerId' => $from])->assertUnprocessable()->assertJsonPath('errors.callerId', [__('agents.call.pick_caller_id')]);
        }
        $this->own->forceFill(['agent_id' => null])->save();
        $this->dial()->assertUnprocessable()->assertJsonPath('errors.callerId', [__('agents.call.agent_has_no_number')]);
        $this->assertCount(1, $this->dials());
    }

    public function test_words_a_number_without_its_country_code_the_do_not_call_list_and_consent_are_said_no_to_here(): void
    {
        config(['kit.agent_calls.calls_per_minute' => 100]);
        // Telenow dials "call me" and "14155550132" (recorded: initiateCall.words, initiateCall.no-plus).
        foreach (['call me', '14155550132', '+1415'] as $typed) {
            $this->dial(['number' => $typed])->assertUnprocessable()->assertJsonValidationErrors(['number']);
        }
        (new DncEntry(['workspace_id' => $this->acme->id, 'phone_number' => '14155550131']))->forceFill(['workspace_id' => $this->acme->id, 'sync_state' => 'synced'])->save();
        $this->dial()->assertUnprocessable()->assertJsonPath('errors.number', [__('softphone.on_dnc')]);
        DncEntry::query()->update(['deleted_at' => now()]);
        $this->acme->forceFill(['consent_required' => true])->save();
        $this->dial()->assertUnprocessable()->assertJsonPath('errors.number', [__('softphone.needs_consent')]);
        $this->assertSame([], $this->dials());
        (new ConsentRecord)->forceFill(['workspace_id' => $this->acme->id, 'phone_number' => '+14155550131', 'consent_type' => 'implied', 'scope' => 'service', 'source' => 'paper',
            'captured_at' => now()->subDay(), 'sync_state' => 'synced'])->save();
        $this->dial()->assertCreated();
    }

    public function test_only_the_details_the_agent_uses_and_those_it_needs(): void
    {
        config(['kit.agent_calls.calls_per_minute' => 100]);
        $this->dial(['variables' => ['account_ref' => 'A-17', 'favourite_colour' => 'blue']])->assertUnprocessable()->assertJsonPath('errors', ['variables.favourite_colour' => [__('agents.call.not_a_detail')]]);
        $this->dial(['variables' => ['first_name' => 'Ana', 'account_ref' => '']])->assertUnprocessable()->assertJsonPath('errors', ['variables.account_ref' => [__('agents.call.details_missing')]]);
        $this->dial(['variables' => ['clinic' => 'Main St']])->assertUnprocessable()->assertJsonValidationErrors(['variables.clinic', 'variables.account_ref']);
        $this->dial(['variables' => ['account_ref' => str_repeat('x', 501)]])->assertUnprocessable()->assertJsonValidationErrors(['variables.account_ref']);
        $this->dial(['openingLine' => str_repeat('x', 1001)])->assertUnprocessable()->assertJsonValidationErrors(['openingLine']);
        $this->dial(['machine' => 'shout'])->assertUnprocessable()->assertJsonValidationErrors(['machine']);
        $this->assertSame([], $this->dials());
        $this->dial(['variables' => ['account_ref' => 'A-17']])->assertCreated();
        $this->assertSame(['account_ref' => 'A-17'], $this->dials()[0]->data()['variables'], 'a lookup fills its own; the rest may be left out');
    }

    public function test_the_voice_platforms_refusals_in_the_kits_words_and_nothing_kept(): void
    {
        $cases = [
            'initiateCall.missing-variable' => [422, ['variables' => [__('agents.call.details_missing')]]],
            'initiateCall.dnc' => [422, ['number' => [__('agents.call.refused')]]],
            'initiateCall.no-consent' => [422, ['number' => [__('agents.call.refused')]]],
            'initiateCall.suspended' => [422, ['number' => [__('agents.call.refused')]]],
            'initiateCall.unknown-agent' => [409, __('agents.not_ready')],
            'initiateCall.number-busy' => [429, __('agents.call.caller_id_busy')],
        ];
        config(['kit.agent_calls.calls_per_minute' => 100]);
        foreach ($cases as $recording => [$status, $words]) {
            $this->answers = [$recording];
            $answer = $this->dial()->assertStatus($status);
            is_array($words) ? $answer->assertJsonPath('errors', $words) : $answer->assertExactJson(['message' => $words]);
            $this->assertStringNotContainsString('organization', $answer->getContent(), $recording);
        }
        $this->answers = [fn () => Http::response(['error' => 'carrier refused'], 502)];
        $this->dial()->assertStatus(503)->assertExactJson(['message' => __('agents.call.unavailable')]);
        $this->answers = [fn () => Http::response(['success' => true, 'data' => ['status' => 'active']], 201)];
        $this->dial()->assertStatus(503);
        $this->assertSame(0, Call::query()->count());
    }

    public function test_a_lost_answer_is_asked_again_with_the_same_key_so_nobody_is_called_twice(): void
    {
        $this->answers = [fn () => Http::failedConnection(), 'initiateCall.agent'];
        $this->dial()->assertCreated();
        [$first, $second] = $this->dials();
        $this->assertSame($first->header('Idempotency-Key'), $second->header('Idempotency-Key'));
        $this->assertSame($first->data(), $second->data());
        $this->assertSame(1, Call::query()->count());

        $this->answers = [fn () => Http::failedConnection(), fn () => Http::failedConnection()];
        $this->dial()->assertStatus(503);
        $this->assertCount(4, $this->dials(), 'once more, never a third time');
        $this->dial()->assertCreated();
        $this->assertNotSame($first->header('Idempotency-Key'), $this->dials()[4]->header('Idempotency-Key'), 'a new call is a new key');
    }

    public function test_a_paused_account_an_agent_not_saved_or_another_accounts_and_so_many_a_minute(): void
    {
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->dial()->assertStatus(409)->assertExactJson(['message' => __('console.paused.account_suspended')]);
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$this->agent->id}/call-form")->assertJsonPath('paused', __('console.paused.account_suspended'));
        $this->acme->forceFill(['status' => 'active'])->save();
        $unsaved = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Draft']);
        $this->dial([], null, $unsaved)->assertStatus(409)->assertExactJson(['message' => __('agents.not_ready')]);
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = Agent::create(['workspace_id' => $other->id, 'name' => 'Theirs']);
        $this->dial([], null, $theirs)->assertNotFound();
        $this->actingAs($this->ben)->getJson("/api/kit/console/agents/{$theirs->id}/call-form")->assertNotFound();
        $this->assertSame([], $this->dials());

        // Each member's own count, of every try: Ana and Cy have not tried yet.
        config(['kit.agent_calls.calls_per_minute' => 2]);
        $cy = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $cy->id, 'role' => 'member']);
        $this->dial([], $this->ana)->assertCreated();
        $this->dial([], $this->ana)->assertCreated();
        $this->dial([], $this->ana)->assertStatus(429)->assertExactJson(['message' => __('session.throttled')]);
        $this->dial([], $cy)->assertCreated();
    }
}
