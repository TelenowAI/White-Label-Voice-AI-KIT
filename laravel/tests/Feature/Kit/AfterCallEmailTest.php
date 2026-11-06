<?php

namespace Tests\Feature\Kit;

use App\Kit\Calls\Calls;
use App\Kit\Email\Senders;
use App\Models\Agent;
use App\Models\Call;
use App\Models\OutgoingEmail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Emailing a finished call's summary to people on the team (App\Kit\Agents\AfterCallEmail), through the kit's real routes and a real read of a recorded call (getCall.answered). The sender here refuses every send, so each
 * email waits, readable, in `emails`: what was rendered and who it is for are what is checked. It writes nothing to Telenow.
 */
class AfterCallEmailTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.example';

    private User $ana;

    private User $ben;

    private Workspace $acme;

    private Agent $agent;

    private array $call;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $senders = app(Senders::class);
        $senders->putSiteUrl(self::SITE);
        $senders->putFrom('hello@acme.example', null);
        $senders->put([['id' => 'down', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']]]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => RecordedTelenow::event('call.completed.answered')['payload']['subAccountId'], 'telenow_key' => 'vai_test_customer_acme'])->save();
        foreach ([$this->ana, $this->ben] as $user) {
            WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $user->id, 'role' => 'member']);
        }
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced', 'telenow_snapshot' => RecordedTelenow::body('getAgent.customer')['data']])->save();
        RecordedTelenow::fake();
        $this->answerWith($this->justHappened('getCall.answered'));
    }

    /** A recorded call as if it just happened: the recording's own date would be too old to be news. */
    private function justHappened(string $recording): array
    {
        $call = RecordedTelenow::body($recording);
        $call['data']['start_time'] = now()->subMinutes(5)->toIso8601String();

        return $call;
    }

    private function answerWith(array $call): void
    {
        $this->call = $call;
        $recorded = RecordedTelenow::responder();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/calls/'.$call['data']['id']) ? Http::response($call) : $recorded($r));
    }

    private function read(): ?Call
    {
        return app(Calls::class)->read($this->acme, $this->call['data']['id']);
    }

    private function choose(array $setting): TestResponse
    {
        return $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$this->agent->id}", ['settings' => ['emailAfterCall' => $setting]]);
    }

    /** @return list<string> who has been emailed about a call */
    private function told(): array
    {
        return OutgoingEmail::query()->where('kind', 'call_summary')->orderBy('to_address')->pluck('to_address')->all();
    }

    public function test_the_choice_is_saved_answered_and_writes_nothing_to_telenow(): void
    {
        Http::fake();
        $answer = $this->choose(['recipients' => [$this->ben->id, $this->ana->id, $this->ana->id], 'onlyAnswered' => false])->assertOk();
        $answer->assertJsonPath('settings.emailAfterCall', ['recipients' => [$this->ana->id, $this->ben->id], 'onlyAnswered' => false]);
        $this->assertSame([], Http::recorded(fn (Request $r) => $r->method() === 'PUT')->all());
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertJsonPath('settings.emailAfterCall.onlyAnswered', false);
        $this->choose(['recipients' => [$this->ana->id]])->assertOk()->assertJsonPath('settings.emailAfterCall', ['recipients' => [$this->ana->id], 'onlyAnswered' => true]);
    }

    public function test_an_agent_starts_with_nobody_and_only_answered_calls(): void
    {
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertJsonPath('settings.emailAfterCall', ['recipients' => [], 'onlyAnswered' => true]);
    }

    public function test_it_survives_the_agent_being_read_back_from_telenow(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $this->actingAs($this->ana)->patchJson("/api/kit/console/agents/{$this->agent->id}", ['settings' => ['canEndCall' => false, 'emailAfterCall' => ['recipients' => [$this->ben->id]]]])->assertOk();
        $this->assertSame([$this->ben->id], $this->agent->fresh()->after_call_email['recipients']);
        $this->actingAs($this->ana)->getJson("/api/kit/console/agents/{$this->agent->id}")->assertJsonPath('settings.emailAfterCall.recipients', [$this->ben->id]);
    }

    public function test_a_new_agent_can_start_with_people_chosen_and_is_held_to_the_same_rule(): void
    {
        $body = ['name' => 'Night desk', 'systemPrompt' => 'You answer the phone.'];
        $made = $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $body + ['settings' => ['emailAfterCall' => ['recipients' => [$this->ben->id], 'onlyAnswered' => false]]])->assertCreated();
        $made->assertJsonPath('settings.emailAfterCall', ['recipients' => [$this->ben->id], 'onlyAnswered' => false]);
        $stranger = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 9']);
        $count = Agent::query()->count();
        $this->actingAs($this->ana)->postJson('/api/kit/console/agents', $body + ['settings' => ['emailAfterCall' => ['recipients' => [$stranger->id]]]])->assertStatus(422)->assertJsonValidationErrors('settings.emailAfterCall.recipients.0');
        $this->assertSame($count, Agent::query()->count());
    }

    public function test_only_people_on_this_team_with_an_address_can_be_chosen(): void
    {
        $stranger = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'a long password 9']);
        $phone = User::create(['name' => 'Dee', 'email' => null, 'phone' => '+15551230000', 'password' => 'a long password 9']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $phone->id, 'role' => 'member']);
        $this->choose(['recipients' => [$this->ana->id, $stranger->id]])->assertStatus(422)->assertJsonValidationErrors(['settings.emailAfterCall.recipients.1' => __('agents.email_not_a_member')]);
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $stranger->id, 'role' => 'member']);
        $this->choose(['recipients' => [$stranger->id]])->assertStatus(422)->assertJsonValidationErrors('settings.emailAfterCall.recipients.0');
        config(['kit.agents.limits.after_call_email_recipients_max' => 1]);
        $this->choose(['recipients' => [$this->ana->id, $this->ben->id]])->assertStatus(422)->assertJsonValidationErrors('settings.emailAfterCall.recipients');
        config(['kit.agents.limits.after_call_email_recipients_max' => 2]);
        $this->choose(['recipients' => [$this->ana->id, $this->ben->id]])->assertOk();
        $this->agent->forceFill(['after_call_email' => null])->save();
        $this->choose(['recipients' => [$phone->id]])->assertStatus(422)->assertJsonValidationErrors('settings.emailAfterCall.recipients.0');
        $this->choose(['recipients' => ['someone@example.com']])->assertStatus(422)->assertJsonValidationErrors('settings.emailAfterCall.recipients.0');
        $this->choose(['onlyAnswered' => true])->assertStatus(422)->assertJsonValidationErrors('settings.emailAfterCall.recipients');
        $this->choose(['recipients' => [$this->ana->id], 'onlyAnswered' => 'perhaps'])->assertStatus(422)->assertJsonValidationErrors('settings.emailAfterCall.onlyAnswered');
        $this->assertNull($this->agent->fresh()->after_call_email);
    }

    public function test_a_finished_call_tells_the_people_chosen_once(): void
    {
        $this->choose(['recipients' => [$this->ana->id, $this->ben->id]])->assertOk();
        $call = $this->read();
        $this->assertSame('final', $call->detail_state);
        $this->assertSame(['ana@example.com', 'ben@example.com'], $this->told());
        $this->assertNotNull($call->fresh()->after_call_emailed_at);
        $this->read();
        $this->assertCount(2, $this->told(), 'read again, it tells nobody again');
    }

    public function test_the_email_says_what_happened_and_links_to_the_call(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $long = $this->call;
        $long['data']['duration_sec'] = 200;
        $this->answerWith($long);
        $call = $this->read();
        $email = OutgoingEmail::query()->where('kind', 'call_summary')->sole();
        $summary = RecordedTelenow::body('getCallAnalysis.answered')['data']['summary'];
        $number = $call->direction === 'outbound' ? $call->to_number : $call->from_number;
        $this->assertSame("Front desk: a call with {$number}", $email->subject);
        $text = $email->body['text'];
        $this->assertStringContainsString("Hi Ana, Front desk had a call with {$number} on ", $text);
        $this->assertStringContainsString($summary, $text);
        $this->assertStringContainsString('Outcome: answered', $text);
        $this->assertStringContainsString('It lasted 3 min 20 s.', $text);
        $this->assertStringContainsString(self::SITE."/console/calls/{$call->id}", $text);
        $this->assertStringNotContainsStringIgnoringCase(config('kit.telenow.name'), $text);
    }

    public function test_a_call_still_waiting_for_its_analysis_tells_nobody_until_it_has_it_or_never_will(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $finished = $this->call;
        $waiting = $finished;
        $waiting['data']['completeness']['analysis'] = 'pending';
        $this->answerWith($waiting);
        $this->assertSame('pending', $this->read()->detail_state);
        $this->assertSame([], $this->told());
        $this->answerWith($finished);
        $this->assertSame('final', $this->read()->detail_state);
        $this->assertSame(['ana@example.com'], $this->told());
    }

    public function test_a_recording_that_has_not_come_does_not_hold_up_the_email_or_repeat_it(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $finished = $this->call;
        $waiting = $finished;
        $waiting['data']['completeness']['recording'] = 'pending';
        $this->answerWith($waiting);
        $this->assertSame('pending', $this->read()->detail_state);
        $this->assertSame(['ana@example.com'], $this->told());
        $this->answerWith($finished);
        $this->assertSame('final', $this->read()->detail_state);
        $this->assertSame(['ana@example.com'], $this->told());
    }

    public function test_a_call_still_going_tells_nobody_however_complete_it_reads(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $going = $this->call;
        $going['data']['status'] = 'active';
        $this->answerWith($going);
        $this->assertSame('pending', $this->read()->detail_state);
        $this->assertSame([], $this->told());
    }

    public function test_a_call_whose_analysis_never_comes_is_still_told_with_no_summary(): void
    {
        config(['kit.calls.max_reads' => 1]);
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $waiting = $this->call;
        $waiting['data']['completeness']['analysis'] = 'pending';
        $this->answerWith($waiting);
        $this->assertSame('gave_up', $this->read()->detail_state);
        $this->assertSame(['ana@example.com'], $this->told());
        $this->assertStringContainsString(__('emails.call_no_summary'), OutgoingEmail::query()->where('kind', 'call_summary')->sole()->body['text']);
    }

    public function test_a_call_nobody_answered_tells_nobody_unless_asked_for_all(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $this->answerWith($this->justHappened('getCall.unanswered'));
        $this->assertSame('no_answer', $this->read()->outcome);
        $this->assertSame([], $this->told());
    }

    public function test_asked_for_all_a_call_nobody_answered_says_so(): void
    {
        $this->choose(['recipients' => [$this->ana->id], 'onlyAnswered' => false])->assertOk();
        $this->answerWith($this->justHappened('getCall.unanswered'));
        $call = $this->read();
        $this->assertSame(['ana@example.com'], $this->told());
        $text = OutgoingEmail::query()->where('kind', 'call_summary')->sole()->body['text'];
        $this->assertStringContainsString('Outcome: no answer', $text);
        $this->assertStringContainsString('It lasted 0 min 0 s', $text);
        $this->assertStringContainsString("a call with {$call->to_number}", $text);
    }

    public function test_a_machine_picking_up_is_not_answered_whatever_its_outcome(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $machine = $this->justHappened('getCall.answered');
        $machine['data']['answered_by'] = 'machine_end_beep';
        $this->answerWith($machine);
        $this->assertSame(50, $this->read()->duration_sec);
        $this->assertSame([], $this->told());
    }

    public function test_a_browser_call_has_no_outcome_and_still_counts_and_reads_well(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $browser = $this->justHappened('getCall.answered');
        $browser['data']['disposition'] = null;
        $browser['data']['answered_by'] = null;
        $this->answerWith($browser);
        $this->read();
        $text = OutgoingEmail::query()->where('kind', 'call_summary')->sole()->body['text'];
        $this->assertStringContainsString('It lasted 0 min 50 s.', $text);
        $this->assertStringNotContainsString('Outcome:', $text);
    }

    public function test_the_words_telenow_really_writes_read_well(): void
    {
        // set_disposition_by_call_sid, map_twilio_disposition and the like write these; the recordings of `completed` and `no_answer` came from a test's own seed.
        $this->choose(['recipients' => [$this->ana->id], 'onlyAnswered' => false])->assertOk();
        $real = $this->justHappened('getCall.answered');
        $real['data']['disposition'] = 'answered';
        $this->answerWith($real);
        $this->read();
        $this->assertStringContainsString('Outcome: answered.', OutgoingEmail::query()->where('kind', 'call_summary')->sole()->body['text']);
        OutgoingEmail::query()->delete();
        $missed = $this->justHappened('getCall.unanswered');
        $missed['data']['disposition'] = 'no-answer';
        $this->answerWith($missed);
        $this->read();
        $this->assertStringContainsString('Outcome: no answer.', OutgoingEmail::query()->where('kind', 'call_summary')->sole()->body['text']);
    }

    public function test_an_unknown_word_with_a_hyphen_reads_as_words(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $odd = $this->justHappened('getCall.answered');
        $odd['data']['disposition'] = 'call-back_later';
        $this->answerWith($odd);
        $this->read();
        $this->assertStringContainsString('Outcome: call back later.', OutgoingEmail::query()->where('kind', 'call_summary')->sole()->body['text']);
    }

    public function test_an_outcome_the_kit_has_no_words_for_reads_as_itself(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $booked = $this->justHappened('getCall.answered');
        $booked['data']['disposition'] = 'call_back_later';
        $this->answerWith($booked);
        $this->read();
        $this->assertStringContainsString('Outcome: call back later', OutgoingEmail::query()->where('kind', 'call_summary')->sole()->body['text']);
    }

    public function test_someone_chosen_later_hears_of_new_calls_and_not_of_one_already_read(): void
    {
        $this->assertSame('final', $this->read()->detail_state);
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $this->read();
        $this->assertSame([], $this->told());
    }

    public function test_nobody_chosen_tells_nobody_and_the_call_is_not_marked(): void
    {
        $call = $this->read();
        $this->assertSame([], $this->told());
        $this->assertNull($call->fresh()->after_call_emailed_at);
    }

    public function test_someone_who_left_is_not_told_even_if_they_are_on_another_account(): void
    {
        $this->choose(['recipients' => [$this->ana->id, $this->ben->id]])->assertOk();
        WorkspaceMember::query()->where('user_id', $this->ben->id)->delete();
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $other->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->read();
        $this->assertSame(['ana@example.com'], $this->told());
    }

    public function test_someone_who_lost_their_address_is_passed_over_and_the_rest_are_told(): void
    {
        $this->choose(['recipients' => [$this->ana->id, $this->ben->id]])->assertOk();
        $this->ana->forceFill(['email' => null])->save();
        Exceptions::fake();
        $this->read();
        $this->assertSame(['ben@example.com'], $this->told());
        Exceptions::assertNothingReported();
    }

    public function test_an_old_call_first_read_late_tells_nobody(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $this->travel(config('kit.agents.after_call_email_max_age_hours') + 1)->hours();
        $this->read();
        $this->assertSame([], $this->told());
    }

    public function test_with_no_way_to_send_email_it_tells_nobody_and_the_form_says_so(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents/settings-form')->assertJsonPath('emailOn', true);
        app(Senders::class)->put([]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/agents/settings-form')->assertJsonPath('emailOn', false);
        $this->read();
        $this->assertSame([], $this->told());
    }

    public function test_an_email_that_cannot_be_made_does_not_lose_the_call(): void
    {
        $this->choose(['recipients' => [$this->ana->id]])->assertOk();
        Exceptions::fake();
        OutgoingEmail::creating(fn () => throw new \RuntimeException('the template broke'));
        $call = $this->read();
        $this->assertSame('final', $call->fresh()->detail_state);
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_the_operator_can_word_it_and_every_placeholder_has_a_name(): void
    {
        $this->assertContains('call_summary', app(\App\Kit\Email\EmailTexts::class)->kinds());
        $this->assertSame('After a call', __('emails.kind_names.call_summary'));
        foreach (['agent', 'caller', 'when', 'duration', 'outcome', 'summary'] as $placeholder) {
            $this->assertNotSame("emails.placeholders.{$placeholder}", __("emails.placeholders.{$placeholder}"));
        }
    }
}
