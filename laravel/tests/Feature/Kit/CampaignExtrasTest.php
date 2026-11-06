<?php

namespace Tests\Feature\Kit;

use App\Kit\Campaigns\Campaigns;
use App\Kit\Campaigns\Telenow\CampaignWrite;
use App\Kit\Outbox\Outbox;
use App\Kit\Outbox\OutboxEntry;
use App\Models\Agent;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * What a campaign can do after it is made (App\Kit\Campaigns\Campaigns::update, CampaignRetries, CampaignPeople, CampaignProgress), through the kit's real routes, against a stand-in for Telenow answering with what
 * contract_k7campaigns.py recorded of the 11-03 release: settings that clamp and never refuse a cancelled campaign, a retry that says only how many, a finished campaign reopening, analytics holding the operator's charge,
 * a carrier's words in an outcome.
 */
class CampaignExtrasTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private const CAMPAIGN = 'c0ffee00-1a2b-4c3d-8e4f-5a6b7c8d9e0f';

    private const NUMBER = '9c4d1e2f-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    private PhoneNumber $number;

    /** @var list<array{method: string, path: string, body: array}> */
    private array $sent = [];

    /** What requests answer instead, by method and how the path ends. */
    private array $answers = [];

    /** The campaign as Telenow holds it. */
    private array $held;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => self::ORG, 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Recall caller']);
        $this->agent->forceFill(['telenow_id' => '606ea9ac-1eb0-48bb-9d4f-f0897ae0f159', 'sync_state' => 'synced'])->save();
        $this->number = (new PhoneNumber)->forceFill(['workspace_id' => $this->acme->id, 'e164' => '918045671001', 'telenow_id' => self::NUMBER, 'sync_state' => 'synced']);
        $this->number->save();
        $this->held = RecordedTelenow::body('setCampaignSchedule.everything')['data'];
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->telenow($r));
    }

    private function telenow(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'key' => $r->header('X-API-Key')[0] ?? null];
        foreach ($this->answers as $which => [$status, $body]) {
            [$method, $end] = explode(' ', $which);
            if ($r->method() === $method && str_ends_with($path, $end)) {
                return Http::response($body, $status);
            }
        }
        $at = '/api/orgs/'.self::ORG.'/campaigns/'.self::CAMPAIGN;
        if ($r->method() === 'PATCH' && $path === "{$at}/schedule") {
            $b = $r->data();
            $this->held = array_replace($this->held, ['start_time_local' => $b['startTimeLocal'] ?? null, 'end_time_local' => $b['endTimeLocal'] ?? null, 'timezone' => $b['timezone'] ?? $this->held['timezone'], 'max_attempts' => $b['maxAttempts'] ?? $this->held['max_attempts'], 'retry_backoff_secs' => $b['retryBackoffSecs'] ?? $this->held['retry_backoff_secs'], 'retry_on_no_answer' => $b['retryOnNoAnswer'] ?? $this->held['retry_on_no_answer']]);

            return Http::response(['success' => true, 'data' => $this->held]);
        }
        if ($r->method() === 'PATCH' && $path === "{$at}/concurrency") {
            $this->held['concurrency'] = $r->data()['concurrency'];

            return Http::response(['success' => true, 'data' => $this->held]);
        }
        if ($r->method() === 'GET' && $path === $at) {
            return Http::response(['success' => true, 'data' => $this->held]);
        }
        if ($r->method() === 'POST' && $path === "{$at}/retry-failed") {
            return Http::response(RecordedTelenow::body('retryFailedTargets.unanswered'));
        }
        if ($r->method() === 'GET' && $path === "{$at}/analytics") {
            return Http::response(RecordedTelenow::body('getCampaignAnalytics.settled'));
        }

        return Http::response(['success' => false, 'error' => "no stand-in for {$r->method()} {$path}"], 500);
    }

    private function campaign(array $over = []): Campaign
    {
        $c = (new Campaign)->forceFill($over + ['workspace_id' => $this->acme->id, 'agent_id' => $this->agent->id, 'phone_number_id' => $this->number->id, 'name' => 'Recall reminders', 'concurrency' => 2, 'timezone' => 'UTC',
            'start_time_local' => '09:00', 'end_time_local' => '18:00', 'machine_detection' => 'voicemail', 'max_attempts' => 2, 'retry_on_no_answer' => true, 'retry_backoff_secs' => 300, 'status' => 'running',
            'telenow_id' => Campaign::query()->where('telenow_id', self::CAMPAIGN)->exists() ? (string) Str::uuid() : self::CAMPAIGN, 'sync_state' => 'synced']);
        $c->save();

        return $c;
    }

    private function person(Campaign $c, string $phone, ?string $outcome, array $over = []): CampaignTarget
    {
        $t = (new CampaignTarget)->forceFill($over + ['campaign_id' => $c->id, 'workspace_id' => $this->acme->id, 'phone_number' => $phone, 'pushed_at' => now(), 'outcome' => $outcome, 'attempt' => $outcome === null ? null : 1, 'completed_at' => $outcome === null ? null : now()->subMinutes(5)]);
        $t->save();

        return $t;
    }

    private function change(Campaign $c, array $body): TestResponse
    {
        return $this->actingAs($this->ana)->patchJson("/api/kit/console/campaigns/{$c->id}", $body);
    }

    private function retry(Campaign $c, string $scope): TestResponse
    {
        return $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/retry", ['scope' => $scope]);
    }

    /** @return list<array{method: string, path: string, body: array}> */
    private function patches(string $end): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'PATCH' && str_ends_with($s['path'], $end)));
    }

    private function retries(): array
    {
        return array_values(array_filter($this->sent, fn (array $s) => str_ends_with($s['path'], '/retry-failed')));
    }

    private function csv(TestResponse $answer): array
    {
        $text = $answer->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $rows = array_map(fn (string $l) => str_getcsv($l, escape: ''), array_values(array_filter(explode("\n", substr($text, 3)), fn (string $l) => $l !== '')));

        return $rows;
    }

    // ── Changing a campaign ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_every_setting_goes_to_telenow_with_the_customers_key_and_is_read_back(): void
    {
        $c = $this->campaign();
        $second = (new PhoneNumber)->forceFill(['workspace_id' => $this->acme->id, 'e164' => '14155550100', 'telenow_id' => 'aaaaaaaa-1eb0-48bb-9d4f-f0897ae0f159', 'sync_state' => 'synced']);
        $second->save();
        $answer = $this->change($c, ['windowStart' => '10:00', 'windowEnd' => '16:30', 'timezone' => 'Europe/London', 'machineDetection' => 'hangup', 'maxAttempts' => 4, 'retryGapMinutes' => 10, 'retryOnNoAnswer' => false, 'callerId' => $second->id, 'concurrency' => 7])->assertOk();
        $answer->assertJsonPath('settings', ['concurrency' => 7, 'windowStart' => '10:00', 'windowEnd' => '16:30', 'timezone' => 'Europe/London', 'machineDetection' => 'hangup', 'maxAttempts' => 4, 'retryOnNoAnswer' => false, 'retryGapMinutes' => 10]);
        $answer->assertJsonPath('callerId.id', $second->id)->assertJsonPath('syncState', 'synced');
        [$schedule] = $this->patches('/schedule');
        $this->assertEquals(['startTimeLocal' => '10:00', 'endTimeLocal' => '16:30', 'timezone' => 'Europe/London', 'maxAttempts' => 4, 'retryOnNoAnswer' => false, 'retryBackoffSecs' => 600, 'machineDetection' => 'hangup', 'fromNumberId' => 'aaaaaaaa-1eb0-48bb-9d4f-f0897ae0f159'], $schedule['body']);
        $this->assertSame(self::KEY, $schedule['key']);
        $this->assertSame(['concurrency' => 7], $this->patches('/concurrency')[0]['body']);
    }

    public function test_what_is_left_out_stays_and_goes_as_it_was_since_telenow_replaces_the_window_each_time(): void
    {
        $c = $this->campaign();
        $this->change($c, ['maxAttempts' => 3])->assertOk()->assertJsonPath('settings.maxAttempts', 3)->assertJsonPath('settings.windowStart', '09:00');
        $body = $this->patches('/schedule')[0]['body'];
        $this->assertSame(['09:00', '18:00', 'voicemail', self::NUMBER], [$body['startTimeLocal'], $body['endTimeLocal'], $body['machineDetection'], $body['fromNumberId']]);
    }

    public function test_a_window_and_a_caller_id_can_be_cleared(): void
    {
        $c = $this->campaign();
        $this->change($c, ['windowStart' => null, 'windowEnd' => null, 'callerId' => null])->assertOk()->assertJsonPath('settings.windowStart', null)->assertJsonPath('callerId', null);
        $body = $this->patches('/schedule')[0]['body'];
        $this->assertNull($body['startTimeLocal']);
        $this->assertArrayHasKey('fromNumberId', $body);
        $this->assertNull($body['fromNumberId']);
    }

    public function test_changing_nothing_sends_nothing(): void
    {
        $c = $this->campaign();
        $this->change($c, ['maxAttempts' => 2, 'windowStart' => '09:00', 'windowEnd' => '18:00', 'callerId' => $this->number->id, 'retryGapMinutes' => 5, 'retryOnNoAnswer' => true, 'concurrency' => 2, 'timezone' => 'UTC', 'machineDetection' => 'voicemail'])->assertOk();
        $this->change($c->fresh(), ['maxAttempts' => '2', 'concurrency' => '2'])->assertOk();
        $this->assertSame([], $this->sent);
    }

    public function test_it_waits_for_a_caller_id_still_on_its_way_rather_than_clear_the_one_in_use(): void
    {
        $c = $this->campaign();
        $coming = (new PhoneNumber)->forceFill(['workspace_id' => $this->acme->id, 'e164' => '14155550177', 'sync_state' => 'pending']);
        $coming->save();
        $this->change($c, ['callerId' => $coming->id])->assertOk()->assertJsonPath('syncState', 'pending');
        $this->assertSame([], $this->patches('/schedule'));
        $coming->forceFill(['telenow_id' => 'bbbbbbbb-1eb0-48bb-9d4f-f0897ae0f159', 'sync_state' => 'synced'])->save();
        OutboxEntry::query()->update(['next_attempt_at' => now()->subMinute()]);
        Artisan::call('kit:outbox');
        $this->assertSame('bbbbbbbb-1eb0-48bb-9d4f-f0897ae0f159', $this->patches('/schedule')[0]['body']['fromNumberId']);
    }

    public function test_taking_back_one_kind_of_write_leaves_the_others(): void
    {
        $c = $this->campaign();
        $outbox = app(Outbox::class);
        $outbox->enqueue(CampaignWrite::RESOURCE, $c->id, 'settings', null);
        $outbox->enqueue(CampaignWrite::RESOURCE, $c->id, 'targets', null);
        $outbox->cancel(CampaignWrite::RESOURCE, $c->id, 'no', 'settings');
        $this->assertSame(['targets'], OutboxEntry::query()->whereNull('done_at')->pluck('operation')->all());
        $outbox->cancel(CampaignWrite::RESOURCE, $c->id, 'no');
        $this->assertSame([], OutboxEntry::query()->whereNull('done_at')->pluck('operation')->all());
    }

    public function test_what_telenow_would_clamp_without_saying_is_refused_here(): void
    {
        $c = $this->campaign();
        foreach ([
            'attempts' => [['maxAttempts' => 99], 'maxAttempts'], 'no attempts' => [['maxAttempts' => 0], 'maxAttempts'],
            'at once' => [['concurrency' => 99], 'concurrency'], 'none at once' => [['concurrency' => 0], 'concurrency'],
            'gap' => [['retryGapMinutes' => 61], 'retryGapMinutes'], 'no gap' => [['retryGapMinutes' => 0], 'retryGapMinutes'],
            'one end' => [['windowStart' => '09:00'], 'windowEnd'], 'the other end' => [['windowEnd' => '09:00'], 'windowStart'], 'same ends' => [['windowStart' => '09:00', 'windowEnd' => '09:00'], 'windowStart'],
            'a word' => [['windowStart' => 'noon', 'windowEnd' => '18:00'], 'windowStart'], 'a zone' => [['timezone' => 'Mars/Olympus'], 'timezone'], 'voicemail' => [['machineDetection' => 'maybe'], 'machineDetection'],
            'a number' => [['callerId' => 'c0ffee00-1a2b-4c3d-8e4f-5a6b7c8d9e0f'], 'callerId'],
        ] as [$body, $field]) {
            $this->change($c, $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
        $this->assertSame([], $this->sent);
    }

    public function test_it_is_refused_when_nothing_more_will_happen_or_a_change_is_on_its_way(): void
    {
        $this->change($this->campaign(['status' => 'cancelled']), ['maxAttempts' => 3])->assertStatus(409)->assertJsonPath('message', __('campaigns.cancelled_no_change'));
        $this->change($this->campaign(['sync_state' => 'pending']), ['maxAttempts' => 3])->assertStatus(409)->assertJsonPath('message', __('campaigns.wait'));
        $this->change($this->campaign(['telenow_id' => null, 'status' => null]), ['maxAttempts' => 3])->assertStatus(409)->assertJsonPath('message', __('campaigns.not_ready'));
        $this->assertSame([], $this->sent);
    }

    public function test_a_finished_campaign_can_be_changed_before_trying_people_again(): void
    {
        $this->change($this->campaign(['status' => 'completed']), ['maxAttempts' => 3])->assertOk();
        $this->assertCount(1, $this->patches('/schedule'));
    }

    public function test_when_telenow_refuses_nothing_changes_and_it_says_so_in_the_kits_words(): void
    {
        $c = $this->campaign();
        $this->answers = ['PATCH /schedule' => [400, RecordedTelenow::body('setCampaignSchedule.foreign-number')]];
        $answer = $this->change($c, ['maxAttempts' => 3, 'windowStart' => '11:00', 'windowEnd' => '12:00'])->assertStatus(409)->assertJsonPath('message', __('campaigns.settings_refused'));
        $this->assertStringNotContainsString('organization', $answer->getContent());
        $row = $c->fresh();
        $this->assertSame([2, '09:00', 'synced', null], [$row->max_attempts, $row->start_time_local, $row->sync_state, $row->sync_error]);
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
    }

    public function test_when_telenow_is_down_it_goes_later_and_the_page_says_it_is_on_its_way(): void
    {
        $c = $this->campaign();
        $this->answers = ['PATCH /schedule' => [503, ['success' => false, 'error' => 'down']]];
        $this->change($c, ['maxAttempts' => 3])->assertOk()->assertJsonPath('syncState', 'pending')->assertJsonPath('settings.maxAttempts', 3);
        $this->answers = [];
        OutboxEntry::query()->update(['next_attempt_at' => now()->subMinute()]);
        Artisan::call('kit:outbox');
        $this->assertSame(['synced', 3], [$c->fresh()->sync_state, $c->fresh()->max_attempts]);
        $this->assertSame(3, $this->patches('/schedule')[1]['body']['maxAttempts']);
    }

    public function test_a_read_while_a_change_is_on_its_way_does_not_undo_it_and_a_later_one_takes_what_telenow_holds(): void
    {
        $c = $this->campaign(['retry_backoff_secs' => 300]);
        $this->held['retry_backoff_secs'] = 1200;
        $this->answers = ['GET /results' => [200, ['results' => [], 'nextCursor' => null]]];
        app(Campaigns::class)->refresh($c->fresh());
        $this->assertSame(1200, $c->fresh()->retry_backoff_secs);
    }

    public function test_a_write_given_up_no_longer_holds_up_every_later_one(): void
    {
        $c = $this->campaign();
        $this->answers = ['POST /start' => [403, ['success' => false, 'error' => 'no']]];
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/pause")->assertOk();
        OutboxEntry::query()->update(['failed_at' => now(), 'done_at' => null, 'operation' => 'start']);
        $c->forceFill(['sync_state' => 'failed'])->save();
        $this->answers = ['POST /start' => [200, ['success' => true, 'message' => 'campaign started']], 'POST /pause' => [200, ['success' => true, 'message' => 'campaign paused']]];
        $this->change($c->fresh(), ['maxAttempts' => 3])->assertOk()->assertJsonPath('syncState', 'synced');
        $this->assertCount(1, $this->patches('/schedule'));
        $this->assertSame(0, OutboxEntry::query()->whereNull('done_at')->count());
    }

    public function test_a_campaign_is_made_with_how_long_it_waits_before_trying_again(): void
    {
        $this->answers = ['POST /campaigns' => [201, ['success' => true, 'data' => RecordedTelenow::body('setCampaignSchedule.everything')['data']]]];
        $this->actingAs($this->ana)->postJson('/api/kit/console/campaigns', ['name' => 'Recall', 'agentId' => $this->agent->id, 'retryGapMinutes' => 10])->assertCreated();
        $made = array_values(array_filter($this->sent, fn (array $s) => $s['method'] === 'POST' && str_ends_with($s['path'], '/campaigns')));
        $this->assertSame(600, $made[0]['body']['retryBackoffSecs']);
        $this->actingAs($this->ana)->postJson('/api/kit/console/campaigns', ['name' => 'Recall', 'agentId' => $this->agent->id, 'retryGapMinutes' => 61])->assertUnprocessable()->assertJsonValidationErrors(['retryGapMinutes']);
        $this->actingAs($this->ana)->getJson('/api/kit/console/campaigns')->assertOk()->assertJsonPath('limits.retryGapMinutes', config('kit.campaigns.retry_gap_minutes'));
    }

    // ── Trying people again ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_trying_those_nobody_answered_marks_them_and_reopens_a_finished_campaign(): void
    {
        $c = $this->campaign(['status' => 'completed']);
        $answered = $this->person($c, '+14155550101', 'answered');
        $none = $this->person($c, '+14155550102', 'no-answer');
        $busy = $this->person($c, '+14155550103', 'busy');
        $machine = $this->person($c, '+14155550104', 'machine');
        $dead = $this->person($c, '+14155550105', 'dial timed out');
        $this->held['status'] = 'running';
        $answer = $this->retry($c, 'unanswered')->assertOk()->assertJsonPath('retried', 3)->assertJsonPath('campaign.status', 'running');
        $this->assertSame('unanswered', $this->retries()[0]['body']['scope']);
        $this->assertStringNotContainsString(self::CAMPAIGN, $answer->getContent());
        $this->assertNotNull($none->fresh()->retried_at);
        $this->assertNotNull($busy->fresh()->retried_at);
        $this->assertNotNull($machine->fresh()->retried_at);
        $this->assertNull($answered->fresh()->retried_at);
        $this->assertNull($dead->fresh()->retried_at);
    }

    public function test_trying_everyone_whose_call_failed_takes_all_but_those_who_answered(): void
    {
        $c = $this->campaign();
        $answered = $this->person($c, '+14155550101', 'answered');
        $none = $this->person($c, '+14155550102', 'no-answer');
        $dead = $this->person($c, '+14155550105', 'dial timed out');
        $notYet = $this->person($c, '+14155550106', null);
        $this->retry($c, 'all')->assertOk();
        $this->assertSame('all', $this->retries()[0]['body']['scope']);
        $this->assertNull($notYet->fresh()->retried_at, 'someone not called yet is not being called again');
        $this->assertNotNull($none->fresh()->retried_at);
        $this->assertNotNull($dead->fresh()->retried_at);
        $this->assertNull($answered->fresh()->retried_at);
    }

    public function test_a_number_listed_twice_comes_back_once_and_a_result_for_either_row_ends_the_wait(): void
    {
        $c = $this->campaign();
        $one = $this->person($c, '+14155550102', 'no-answer');
        $two = $this->person($c, '+14155550102', 'no-answer');
        $this->retry($c, 'unanswered')->assertOk();
        $this->assertSame(1, CampaignTarget::query()->whereNotNull('retried_at')->count());
        $other = CampaignTarget::query()->whereNull('retried_at')->sole();
        CampaignTarget::query()->whereNotNull('retried_at')->update(['retried_at' => now()->subMinute()]);
        $this->answers = ['GET /results' => [200, ['results' => [['id' => $other->id, 'phone' => '+14155550102', 'outcome' => 'busy', 'attempt' => 1, 'completedAt' => now()->toIso8601String(), 'callId' => (string) Str::uuid()]], 'nextCursor' => null]]];
        app(Campaigns::class)->refresh($c->fresh());
        $this->assertSame(0, CampaignTarget::query()->whereNotNull('retried_at')->count());
        $this->assertSame('busy', $other->fresh()->outcome);
        $this->assertSame('no-answer', ($one->id === $other->id ? $two : $one)->fresh()->outcome);
    }

    public function test_a_result_from_before_the_retry_leaves_them_waiting_and_a_newer_one_ends_it(): void
    {
        $c = $this->campaign();
        $person = $this->person($c, '+14155550102', 'no-answer', ['retried_at' => now()->subMinutes(10)]);
        $result = fn ($at) => ['GET /results' => [200, ['results' => [['id' => $person->id, 'phone' => '+14155550102', 'outcome' => 'no-answer', 'attempt' => 1, 'completedAt' => $at->toIso8601String(), 'callId' => (string) Str::uuid()]], 'nextCursor' => null]]];
        $this->answers = $result(now()->subMinutes(20));
        app(Campaigns::class)->refresh($c->fresh());
        $this->assertNotNull($person->fresh()->retried_at);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/targets")->assertJsonPath('targets.0.state', 'waiting')->assertJsonPath('targets.0.tryingAgain', true)->assertJsonPath('targets.0.outcome', null);
        $this->answers = $result(now());
        app(Campaigns::class)->refresh($c->fresh());
        $this->assertNull($person->fresh()->retried_at);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/targets")->assertJsonPath('targets.0.state', 'done')->assertJsonPath('targets.0.tryingAgain', false);
    }

    public function test_the_job_keeps_reading_a_finished_campaign_while_someone_is_being_called_again(): void
    {
        $c = $this->campaign(['status' => 'completed', 'read_at' => now()->subHour()]);
        $this->person($c, '+14155550102', 'no-answer', ['retried_at' => now()->subMinutes(10)]);
        $this->answers = ['GET /results' => [200, ['results' => [], 'nextCursor' => null]]];
        $this->held['status'] = 'completed';
        Artisan::call('kit:campaigns');
        $this->assertTrue($c->fresh()->read_at->gt(now()->subMinute()));
    }

    public function test_nothing_is_marked_when_telenow_took_nobody_back(): void
    {
        $c = $this->campaign();
        $none = $this->person($c, '+14155550102', 'no-answer');
        $this->answers = ['POST /retry-failed' => [200, RecordedTelenow::body('retryFailedTargets.nothing')]];
        $this->retry($c, 'unanswered')->assertOk()->assertJsonPath('retried', 0);
        $this->assertNull($none->fresh()->retried_at);
    }

    public function test_it_is_refused_while_paused_for_the_account_when_cancelled_or_not_ready_and_never_sent(): void
    {
        $this->retry($this->campaign(['status' => 'cancelled']), 'all')->assertStatus(409)->assertJsonPath('message', __('campaigns.cancelled_no_change'));
        $this->retry($this->campaign(['sync_state' => 'pending']), 'all')->assertStatus(409)->assertJsonPath('message', __('campaigns.wait'));
        $this->retry($this->campaign(['telenow_id' => null, 'status' => null]), 'all')->assertStatus(409)->assertJsonPath('message', __('campaigns.not_ready'));
        $this->retry($this->campaign(), 'maybe')->assertUnprocessable()->assertJsonValidationErrors(['scope']);
        $this->acme->forceFill(['status' => 'suspended'])->save();
        $this->retry($this->campaign(), 'all')->assertStatus(409);
        $this->assertSame([], $this->retries());
    }

    public function test_when_it_cannot_be_asked_nothing_is_marked_it_is_asked_only_once_and_it_says_to_look_first(): void
    {
        $c = $this->campaign();
        $none = $this->person($c, '+14155550102', 'no-answer');
        $this->answers = ['POST /retry-failed' => [503, ['success' => false, 'error' => 'down']]];
        $answer = $this->retry($c, 'unanswered')->assertStatus(503)->assertJsonPath('message', __('campaigns.retry_unavailable'));
        $this->assertStringNotContainsString('down', $answer->getContent());
        $this->assertCount(1, $this->retries());
        $this->assertNull($none->fresh()->retried_at);
        $this->assertSame(0, OutboxEntry::query()->count());
    }

    public function test_a_read_that_fails_after_it_was_done_still_says_it_was_done_and_the_job_reads_it_next(): void
    {
        $c = $this->campaign(['read_at' => now()]);
        $none = $this->person($c, '+14155550102', 'no-answer');
        $this->answers = ['GET /'.self::CAMPAIGN => [503, ['success' => false, 'error' => 'down']]];
        $this->retry($c, 'unanswered')->assertOk()->assertJsonPath('retried', 3);
        $this->assertNotNull($none->fresh()->retried_at);
        $this->assertNull($c->fresh()->read_at);
    }

    // ── Its people ──────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_each_group_of_people_and_how_many_stand_where(): void
    {
        $c = $this->campaign();
        $this->person($c, '+14155550101', 'answered');
        $this->person($c, '+14155550102', 'no-answer');
        $this->person($c, '+14155550103', 'busy');
        $this->person($c, '+14155550104', 'machine');
        $this->person($c, '+14155550105', 'dial timed out');
        $this->person($c, '+14155550106', 'insufficient balance');
        $this->person($c, '+14155550107', null);
        $this->person($c, '+14155550108', 'no-answer', ['retried_at' => now()]);
        $this->person($c, '+14155550109', null, ['pushed_at' => null]);
        $this->person($c, '+14155550110', 'no-answer', ['completed_at' => null]);
        $this->person($c, '+14155550111', 'answered', ['retried_at' => now()]);
        $root = "/api/kit/console/campaigns/{$c->id}/targets";
        $all = $this->actingAs($this->ana)->getJson($root)->assertOk()->assertJsonPath('total', 11);
        $all->assertJsonPath('counts', ['waiting' => 5, 'answered' => 1, 'unanswered' => 3, 'failed' => 2]);
        $phones = fn (string $show) => array_column($this->actingAs($this->ana)->getJson("{$root}?show={$show}")->assertOk()->assertJsonPath('counts.answered', 1)->json('targets'), 'phoneNumber');
        $sorted = function (array $list) {
            sort($list);

            return $list;
        };
        $this->assertSame(['+14155550101'], $phones('answered'));
        $this->assertSame(['+14155550102', '+14155550103', '+14155550104'], $sorted($phones('unanswered')));
        $this->assertSame(['+14155550105', '+14155550106'], $sorted($phones('failed')));
        $this->assertSame(['+14155550107', '+14155550108', '+14155550109', '+14155550110', '+14155550111'], $sorted($phones('waiting')));
        $this->actingAs($this->ana)->getJson("{$root}?show=maybe")->assertUnprocessable()->assertJsonValidationErrors(['show']);
    }

    public function test_a_carriers_own_words_never_reach_a_customer(): void
    {
        $c = $this->campaign();
        $this->person($c, '+14155550106', 'insufficient balance');
        $this->person($c, '+14155550107', 'dial timed out');
        $answer = $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/targets")->assertOk();
        $this->assertSame(['failed', 'failed'], array_column($answer->json('targets'), 'outcome'));
        $this->assertStringNotContainsString('insufficient', $answer->getContent());
        $file = $this->actingAs($this->ana)->get("/api/kit/console/campaigns/{$c->id}/targets/export")->assertOk();
        $this->assertStringNotContainsString('insufficient', $file->streamedContent());
    }

    public function test_the_recording_shows_those_words_do_come(): void
    {
        $outcomes = array_column(RecordedTelenow::body('getCampaignResultsV1.after-retry')['results'], 'outcome');
        $this->assertContains('insufficient balance', $outcomes);
        $this->assertContains('dial timed out', $outcomes);
    }

    public function test_the_file_holds_their_columns_beside_how_each_call_went_and_can_be_a_new_list(): void
    {
        $c = $this->campaign();
        $this->person($c, '+14155550101', 'answered', ['variables' => ['first_name' => 'Ana', 'plan' => 'Gold'], 'attempt' => 2, 'completed_at' => '2026-11-04 09:00:00', 'created_at' => '2026-11-04 08:00:00']);
        $this->person($c, '+14155550102', 'no-answer', ['variables' => ['first_name' => 'Ben', 'city' => 'Lagos'], 'created_at' => '2026-11-04 08:00:01', 'completed_at' => '2026-11-04 09:01:00']);
        $this->person($c, '+14155550103', null, ['created_at' => '2026-11-04 08:00:02']);
        $answer = $this->actingAs($this->ana)->get("/api/kit/console/campaigns/{$c->id}/targets/export")->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $answer->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $answer->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $answer->headers->get('Cache-Control'));
        $rows = $this->csv($answer);
        $this->assertSame([config('kit.campaigns.phone_columns')[0], 'outcome', 'attempts', 'finished_at', 'first_name', 'plan', 'city'], $rows[0]);
        $this->assertSame(['+14155550101', 'Answered', '2', '2026-11-04T09:00:00+00:00', 'Ana', 'Gold', ''], $rows[1]);
        $this->assertSame(['+14155550102', 'No answer', '1', '2026-11-04T09:01:00+00:00', 'Ben', '', 'Lagos'], $rows[2]);
        $this->assertSame(['+14155550103', 'Waiting', '', '', '', '', ''], $rows[3]);
    }

    public function test_the_file_can_be_one_group_and_someone_being_called_again_reads_as_waiting(): void
    {
        $c = $this->campaign();
        $this->person($c, '+14155550101', 'answered');
        $this->person($c, '+14155550102', 'no-answer');
        $this->person($c, '+14155550103', 'no-answer', ['retried_at' => now()]);
        $rows = $this->csv($this->actingAs($this->ana)->get("/api/kit/console/campaigns/{$c->id}/targets/export?show=unanswered")->assertOk());
        $this->assertSame(['+14155550102'], array_column(array_slice($rows, 1), 0));
        $rows = $this->csv($this->actingAs($this->ana)->get("/api/kit/console/campaigns/{$c->id}/targets/export?show=waiting")->assertOk());
        $this->assertSame([['+14155550103', 'Waiting', '', '']], array_slice($rows, 1), 'nothing of the call before');
        $this->actingAs($this->ana)->get("/api/kit/console/campaigns/{$c->id}/targets/export?show=maybe", ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_a_cell_a_spreadsheet_would_run_is_written_as_text_and_a_number_stays_a_number(): void
    {
        $c = $this->campaign();
        $this->person($c, '=HYPERLINK("x")', 'answered', ['variables' => ['a' => '+CMD()', 'b' => '-1+1', 'c' => '@SUM(1)', 'd' => '+14155550101', 'e' => '-12.50', 'f' => "\tx", '=bad' => 'y']]);
        $rows = $this->csv($this->actingAs($this->ana)->get("/api/kit/console/campaigns/{$c->id}/targets/export")->assertOk());
        $this->assertSame("'=bad", $rows[0][10]);
        $this->assertSame(["'=HYPERLINK(\"x\")", "'+CMD()", "'-1+1", "'@SUM(1)", '+14155550101', '-12.50', "'\tx"], [$rows[1][0], $rows[1][4], $rows[1][5], $rows[1][6], $rows[1][7], $rows[1][8], $rows[1][9]]);
    }

    public function test_a_long_list_is_read_a_page_at_a_time(): void
    {
        config(['kit.campaigns.export_chunk' => 2]);
        $c = $this->campaign();
        foreach (range(1, 5) as $i) {
            $this->person($c, "+1415555010{$i}", 'answered', ['created_at' => "2026-11-04 08:00:0{$i}"]);
        }
        $rows = $this->csv($this->actingAs($this->ana)->get("/api/kit/console/campaigns/{$c->id}/targets/export")->assertOk());
        $this->assertSame(['+14155550101', '+14155550102', '+14155550103', '+14155550104', '+14155550105'], array_column(array_slice($rows, 1), 0));
    }

    // ── How it went ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_how_it_went_says_what_was_recorded_in_the_kits_words(): void
    {
        $answer = $this->actingAs($this->ana)->getJson('/api/kit/console/campaigns/'.$this->campaign()->id.'/progress')->assertOk();
        $answer->assertExactJson([
            'people' => 8, 'called' => 7, 'reached' => 2, 'notReached' => 5, 'waiting' => 1, 'onDoNotCall' => 0, 'skipped' => 0,
            'whyNotReached' => ['noAnswer' => 1, 'busy' => 1, 'voicemail' => 1, 'couldNotConnect' => 2],
            'talk' => ['totalSeconds' => 82, 'averageSeconds' => 41, 'nobodySpoke' => 0, 'under15' => 0, 'under30' => 1, 'under60' => 0, 'under120' => 1, 'over120' => 0],
            'reachedOnAttempt' => ['first' => 1, 'second' => 1, 'thirdOrLater' => 0],
            'calledLastHour' => 7,
            'byHour' => ['timezone' => 'Europe/London', 'called' => RecordedTelenow::body('getCampaignAnalytics.settled')['data']['dialHours'], 'reached' => RecordedTelenow::body('getCampaignAnalytics.settled')['data']['reachedHours']],
        ]);
    }

    public function test_what_telenow_charges_the_operator_and_anything_it_adds_later_never_reach_a_customer(): void
    {
        $this->assertArrayHasKey('chargeUsdTotal', RecordedTelenow::body('getCampaignAnalytics.settled')['data']);
        $body = RecordedTelenow::body('getCampaignAnalytics.settled');
        $body['data'] = ['chargeUsdTotal' => '12.34', 'talkedChargeUsd' => '9.99', 'llmMsAvg' => 321, 'somethingNew' => 'secret-value'] + $body['data'];
        $this->answers = ['GET /analytics' => [200, $body]];
        $content = $this->actingAs($this->ana)->getJson('/api/kit/console/campaigns/'.$this->campaign()->id.'/progress')->assertOk()->getContent();
        foreach (['12.34', '9.99', '321', 'secret-value', 'Usd', 'charge', 'llm'] as $word) {
            $this->assertStringNotContainsString($word, $content);
        }
    }

    public function test_those_on_a_call_now_count_as_waiting(): void
    {
        $body = RecordedTelenow::body('getCampaignAnalytics.settled');
        $body['data'] = ['pending' => 3, 'dialing' => 2] + $body['data'];
        $this->answers = ['GET /analytics' => [200, $body]];
        $this->actingAs($this->ana)->getJson('/api/kit/console/campaigns/'.$this->campaign()->id.'/progress')->assertOk()->assertJsonPath('waiting', 5);
    }

    public function test_how_a_draft_stands_reads_as_nothing_yet_and_a_failed_read_says_try_again(): void
    {
        $c = $this->campaign(['status' => 'draft']);
        $this->answers = ['GET /analytics' => [200, RecordedTelenow::body('getCampaignAnalytics.draft')]];
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/progress")->assertOk()->assertJsonPath('called', 0)->assertJsonPath('talk.averageSeconds', null);
        $this->answers = ['GET /analytics' => [503, ['success' => false, 'error' => 'down']]];
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/progress")->assertStatus(503)->assertJsonPath('message', __('campaigns.unavailable'));
        $this->actingAs($this->ana)->getJson('/api/kit/console/campaigns/'.$this->campaign(['telenow_id' => null, 'status' => null])->id.'/progress')->assertStatus(409);
    }

    // ── Whose ───────────────────────────────────────────────────────────────────────────────────────────────────────

    public function test_someone_elses_campaign_and_a_stranger_are_refused(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $other->forceFill(['telenow_sub_account_id' => (string) Str::uuid(), 'telenow_key' => 'vai_test_other'])->save();
        $theirs = $this->campaign(['workspace_id' => $other->id, 'agent_id' => null, 'phone_number_id' => null]);
        $root = "/api/kit/console/campaigns/{$theirs->id}";
        $this->change($theirs, ['maxAttempts' => 3])->assertNotFound();
        $this->actingAs($this->ana)->postJson("{$root}/retry", ['scope' => 'all'])->assertNotFound();
        $this->actingAs($this->ana)->getJson("{$root}/progress")->assertNotFound();
        $this->actingAs($this->ana)->get("{$root}/targets/export")->assertNotFound();
        $this->assertSame([], $this->sent);
        $stranger = User::create(['name' => 'Nobody', 'email' => 'nobody@example.com', 'password' => 'a long password 9']);
        $this->actingAs($stranger)->getJson("{$root}/progress")->assertForbidden();
        $this->actingAs($stranger)->get("{$root}/targets/export", ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_another_accounts_number_cannot_be_the_caller_id(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new PhoneNumber)->forceFill(['workspace_id' => $other->id, 'e164' => '14155550199', 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced']);
        $theirs->save();
        $released = (new PhoneNumber)->forceFill(['workspace_id' => $this->acme->id, 'e164' => '14155550198', 'telenow_id' => (string) Str::uuid(), 'sync_state' => 'synced', 'released_at' => now()]);
        $released->save();
        $c = $this->campaign();
        $this->change($c, ['callerId' => $theirs->id])->assertUnprocessable()->assertJsonValidationErrors(['callerId']);
        $this->change($c, ['callerId' => $released->id])->assertUnprocessable()->assertJsonValidationErrors(['callerId']);
        $this->assertSame([], $this->sent);
    }

    public function test_the_recordings_are_what_this_rests_on(): void
    {
        $this->assertSame(10, RecordedTelenow::body('setCampaignSchedule.too-many-attempts')['data']['max_attempts']);
        $this->assertSame(50, RecordedTelenow::body('setCampaignConcurrency.too-many')['data']['concurrency']);
        $this->assertSame('cancelled', RecordedTelenow::body('setCampaignSchedule.cancelled')['data']['status']);
        $this->assertSame(3, RecordedTelenow::body('retryFailedTargets.unanswered')['data']['retried']);
        $this->assertSame(1, RecordedTelenow::body('retryFailedTargets.listed-twice')['data']['retried']);
        $this->assertStringContainsString('cancelled', RecordedTelenow::body('retryFailedTargets.cancelled')['error']);
        $this->assertSame('running', RecordedTelenow::body('getCampaign.after-retry')['data']['status']);
        $this->assertSame('paused', RecordedTelenow::body('getCampaign.paused-after-retry')['data']['status']);
        $this->assertSame(2, RecordedTelenow::body('listCampaignCalls.settled')['data']['failedUnanswered'], 'Telenow counts two where a retry takes three, so the kit counts its own');
        $this->assertNull(RecordedTelenow::body('setCampaignSchedule.no-window')['data']['start_time_local']);
        $this->assertSame(CampaignWrite::RESOURCE, 'campaigns');
    }
}
