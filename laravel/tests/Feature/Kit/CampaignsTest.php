<?php

namespace Tests\Feature\Kit;

use App\Kit\Campaigns\Campaigns;
use App\Kit\Campaigns\Telenow\CampaignWrite;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Console → Campaigns (App\Kit\Campaigns): a campaign is made at Telenow as a draft with the customer's own key — its
 * agent and caller id by Telenow's ids, the customer's voicemail choice kept as given; people to call come from a CSV,
 * each sent with the kit's own id; it is started (never paused, never empty), paused and cancelled, and read back — why it
 * is waiting in the kit's words; the job reads running campaigns and each call's result by the kit's id; how many can be
 * dialled and the variables its agent uses are read live; it is deleted. Real recordings (contract_k1campaigns.py: the
 * caller id came from a stand-in carrier and every call failed there).
 */
class CampaignsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'vai_test_customer_acme';

    private const ORG = '/api/orgs/3e1cd17a-d25e-40c4-b0b0-0694f052f6da';

    private const CAMPAIGN = 'c0ffee00-1a2b-4c3d-8e4f-5a6b7c8d9e0f';

    private const LATER = 'c0ffee00-2a2b-4c3d-8e4f-5a6b7c8d9e0f';

    private const NUMBER = '9c4d1e2f-3a4b-4c5d-8e6f-7a8b9c0d1e2f';

    private const ANA = 'a0000001-0000-4000-8000-000000000000';

    private const BEN = 'a0000002-0000-4000-8000-000000000000';

    private User $ana;

    private Workspace $acme;

    private Agent $agent;

    private PhoneNumber $number;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => self::KEY])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        $this->agent = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Recall caller']);
        $this->agent->forceFill(['telenow_id' => RecordedTelenow::body('createAgent.customer')['data']['id'], 'sync_state' => 'synced'])->save();
        $this->number = (new PhoneNumber)->forceFill(['workspace_id' => $this->acme->id, 'e164' => '918045671001', 'telenow_id' => self::NUMBER, 'sync_state' => 'synced']);
        $this->number->save();
        RecordedTelenow::fake();
    }

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

    /** @return list<Request> */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => self::is($r, $method, $path))->map(fn (array $p) => $p[0])->values()->all();
    }

    private function campaign(array $over = []): Campaign
    {
        $c = (new Campaign)->forceFill(array_merge(['workspace_id' => $this->acme->id, 'agent_id' => $this->agent->id, 'phone_number_id' => $this->number->id,
            'name' => 'Recall reminders', 'concurrency' => 2, 'timezone' => 'UTC', 'machine_detection' => 'voicemail', 'max_attempts' => 1,
            'retry_on_no_answer' => false, 'status' => 'draft', 'telenow_id' => self::CAMPAIGN, 'sync_state' => 'synced'], $over));
        $c->save();

        return $c;
    }

    private function target(Campaign $c, array $over = []): CampaignTarget
    {
        $t = (new CampaignTarget)->forceFill(array_merge(['campaign_id' => $c->id, 'workspace_id' => $this->acme->id, 'phone_number' => '+14155550101'], $over));
        $t->save();

        return $t;
    }

    private static function recorded(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("../contract/telenow-mock/cassettes/{$name}.json")), true);
    }

    public function test_a_campaign_is_made_as_a_draft_with_the_customers_key(): void
    {
        $made = $this->actingAs($this->ana)->postJson('/api/kit/console/campaigns', ['name' => 'Recall reminders', 'agentId' => $this->agent->id,
            'callerId' => $this->number->id, 'concurrency' => 2, 'windowStart' => '00:00', 'windowEnd' => '23:59', 'timezone' => 'UTC',
            'machineDetection' => 'voicemail', 'maxAttempts' => 1, 'retryOnNoAnswer' => false])->assertCreated();
        $made->assertJson(['name' => 'Recall reminders', 'agent' => ['id' => $this->agent->id, 'name' => 'Recall caller'],
            'callerId' => ['id' => $this->number->id, 'number' => '+918045671001'], 'status' => 'draft', 'waiting' => null,
            'settings' => ['concurrency' => 2, 'windowStart' => '00:00', 'windowEnd' => '23:59', 'timezone' => 'UTC', 'machineDetection' => 'voicemail',
                'maxAttempts' => 1, 'retryOnNoAnswer' => false],
            'counts' => ['people' => 0, 'answered' => 0, 'notReached' => 0], 'syncState' => 'synced']);
        [$create] = $this->sent('POST', self::ORG.'/campaigns');
        $this->assertSame([self::KEY], $create->header('X-API-Key'));
        $this->assertEquals(['name' => 'Recall reminders', 'concurrency' => 2, 'startTimeLocal' => '00:00', 'endTimeLocal' => '23:59', 'timezone' => 'UTC',
            'maxAttempts' => 1, 'retryOnNoAnswer' => false, 'retryBackoffSecs' => 300, 'agentId' => $this->agent->telenow_id, 'machineDetection' => 'voicemail', 'fromNumberId' => self::NUMBER],
            json_decode($create->body(), true));
        $row = Campaign::query()->sole();
        $this->assertSame([self::CAMPAIGN, 'draft', 'voicemail'], [$row->telenow_id, $row->status, $row->machine_detection], 'Telenow reads voicemail back as "true": the choice stays as given');
        foreach ([self::CAMPAIGN, $this->agent->telenow_id, self::NUMBER] as $never) {
            $this->assertStringNotContainsString($never, $made->getContent());
        }

        Campaign::query()->update(['telenow_id' => null]); // the recording answers one id; a real Telenow gives each campaign its own
        $this->actingAs($this->ana)->postJson('/api/kit/console/campaigns', ['name' => 'Plain', 'agentId' => $this->agent->id])->assertCreated();
        $this->assertEquals(['name' => 'Plain', 'concurrency' => config('kit.campaigns.concurrency.default'), 'timezone' => config('app.timezone'),
            'maxAttempts' => config('kit.campaigns.max_attempts.default'), 'retryOnNoAnswer' => true, 'retryBackoffSecs' => config('kit.campaigns.retry_gap_minutes.default') * 60, 'agentId' => $this->agent->telenow_id,
            'machineDetection' => config('kit.campaigns.machine_detection')[0]], json_decode($this->sent('POST', self::ORG.'/campaigns')[1]->body(), true), 'the defaults; no window, no caller id');

        Campaign::query()->update(['telenow_id' => null]);
        $this->actingAs($this->ana)->postJson('/api/kit/console/campaigns', ['name' => 'Browser zone', 'agentId' => $this->agent->id, 'timezone' => 'Asia/Calcutta'])
            ->assertCreated();
        $this->assertSame('Asia/Calcutta', json_decode($this->sent('POST', self::ORG.'/campaigns')[2]->body(), true)['timezone'], "a browser's own zone, an older name");
    }

    public function test_a_campaign_is_checked_before_telenow_and_one_telenow_refuses_says_so_in_the_kits_words(): void
    {
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirAgent = Agent::create(['workspace_id' => $other->id, 'name' => 'Theirs']);
        $theirNumber = (new PhoneNumber)->forceFill(['workspace_id' => $other->id, 'e164' => '1', 'sync_state' => 'synced']);
        $theirNumber->save();
        $make = fn (array $over) => $this->actingAs($this->ana)->postJson('/api/kit/console/campaigns', $over + ['name' => 'x', 'agentId' => $this->agent->id]);
        $make(['agentId' => $theirAgent->id])->assertStatus(422)->assertJsonPath('errors.agentId', [__('campaigns.unknown_agent')]);
        $make(['callerId' => $theirNumber->id])->assertStatus(422)->assertJsonPath('errors.callerId', [__('campaigns.unknown_caller_id')]);
        $make(['windowStart' => '09:00'])->assertStatus(422)->assertJsonValidationErrors(['windowEnd']);
        $make(['windowStart' => '9am', 'windowEnd' => '17:00'])->assertStatus(422)->assertJsonValidationErrors(['windowStart']);
        $make(['timezone' => 'Mars/Olympus'])->assertStatus(422)->assertJsonValidationErrors(['timezone']);
        $make(['concurrency' => config('kit.campaigns.concurrency.max') + 1])->assertStatus(422)->assertJsonValidationErrors(['concurrency']);
        $make(['machineDetection' => 'maybe'])->assertStatus(422)->assertJsonValidationErrors(['machineDetection']);
        $this->assertSame([], $this->sent('POST', self::ORG.'/campaigns'), 'nothing sent');

        $gone = self::recorded('createCampaign.unknown-agent')['response'];
        $this->answering(fn (Request $r) => self::is($r, 'POST', self::ORG.'/campaigns') ? Http::response($gone['body'], $gone['status']) : null);
        $make([])->assertCreated()->assertJson(['status' => 'failed', 'syncState' => 'failed', 'syncError' => __('campaigns.not_done')]);

        $this->agent->forceFill(['telenow_id' => null])->save();
        $make([])->assertCreated()->assertJson(['status' => 'saving', 'syncState' => 'pending']);
        $this->assertSame(__('campaigns.agent_not_ready'), OutboxEntry::query()->where('operation', 'create')->whereNull('done_at')->whereNull('failed_at')->sole()->last_error, 'waits for its agent');
    }

    public function test_people_come_from_a_csv_and_are_sent_with_the_kits_own_ids(): void
    {
        $c = $this->campaign();
        $csv = "phone_number,first_name,appointment\n+14155550101,Ana,cleaning\n+14155550102,Ben,check-up\n+14155550199,Cleo,\n,Dev,\n";
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/targets", ['csv' => $csv])->assertOk()->assertExactJson(['added' => 3, 'skipped' => 1, 'noCountryCode' => 0]);
        [$push] = $this->sent('POST', '/api/v1/campaigns/'.self::CAMPAIGN.'/targets');
        $this->assertSame([self::KEY], $push->header('X-API-Key'));
        $body = json_decode($push->body(), true);
        $this->assertSame(config('kit.campaigns.source'), $body['source']);
        $rows = CampaignTarget::query()->get()->keyBy('id');
        $this->assertEqualsCanonicalizing($rows->keys()->all(), array_column($body['targets'], 'id'), "each with the kit's own id");
        $this->assertEqualsCanonicalizing([['+14155550101', ['first_name' => 'Ana', 'appointment' => 'cleaning']], ['+14155550102', ['first_name' => 'Ben', 'appointment' => 'check-up']],
            ['+14155550199', ['first_name' => 'Cleo']]], array_map(fn ($t) => [$t['phoneNumber'], $t['variables']], $body['targets']));
        $this->assertTrue($rows->every(fn (CampaignTarget $t) => $t->pushed_at !== null));
        $counts = self::recorded('pushCampaignTargetsV1.customer')['response']['body'];
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}")
            ->assertJsonPath('pushed', ['added' => $counts['added'], 'duplicates' => $counts['duplicates'], 'suppressed' => $counts['suppressed'], 'skipped' => $counts['skipped']]);
        $listed = $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/targets")->assertOk();
        $this->assertSame([3, ['waiting']], [$listed->json('total'), array_values(array_unique(array_column($listed->json('targets'), 'state')))]);

        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/targets", ['csv' => "name,mobile\nAna,+1\n"])->assertStatus(422)
            ->assertJsonPath('errors.csv', [__('campaigns.no_phone_column', ['columns' => implode(', ', config('kit.campaigns.phone_columns'))])]);
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/targets", ['csv' => "\xEF\xBB\xBFPhone,Name\n+14155550103,Eve\n"])->assertOk()
            ->assertExactJson(['added' => 1, 'skipped' => 0, 'noCountryCode' => 0]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}")->assertJsonPath('pushed', ['added' => 2 * $counts['added'],
            'duplicates' => 2 * $counts['duplicates'], 'suppressed' => 2 * $counts['suppressed'], 'skipped' => 2 * $counts['skipped']]); // each push adds to it
        // The request middleware trims a leading byte-order mark already; the service strips it itself all the same.
        $this->assertSame(['added' => 1, 'skipped' => 0, 'noCountryCode' => 0], app(Campaigns::class)->addTargets($c, ['csv' => "\xEF\xBB\xBFphone\n+14155550104\n"]));
        config(['kit.campaigns.import_max_rows' => 1]);
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/targets", ['csv' => "phone\n+1\n+2\n"])->assertStatus(422)->assertJsonValidationErrors(['csv']);

        foreach (config('kit.campaigns.finished_statuses') as $status) {
            $c->forceFill(['status' => $status])->save();
            $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/targets", ['csv' => "phone\n+14155550104\n"])->assertStatus(409)
                ->assertExactJson(['message' => __('campaigns.finished')]);
        }
    }

    public function test_people_wait_to_be_sent_until_their_campaign_is_made(): void
    {
        $c = $this->campaign(['telenow_id' => null, 'status' => null, 'sync_state' => 'pending']);
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/targets", ['csv' => "phone\n+14155550101\n"])->assertOk();
        $this->assertSame([], Http::recorded()->all());
        $this->assertSame('saving', $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/targets")->json('targets.0.state'));
        $this->assertSame(__('campaigns.not_ready'), OutboxEntry::query()->sole()->last_error);
    }

    public function test_a_campaign_is_started_paused_and_cancelled_and_why_it_waits_is_the_kits_words(): void
    {
        $c = $this->campaign(['telenow_id' => self::LATER, 'name' => 'Evening follow-ups']);
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/start")->assertStatus(409)->assertExactJson(['message' => __('campaigns.no_targets')]);
        $this->target($c, ['pushed_at' => now()]);
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/pause")->assertStatus(409)->assertExactJson(['message' => __('campaigns.cannot_pause')]);
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/start")->assertStatus(409)->assertExactJson(['message' => __('console.paused.account_suspended')]);
        $this->assertSame([], Http::recorded()->all(), 'refused before Telenow');
        $this->acme->forceFill(['status' => 'active'])->save();

        $running = $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/start")->assertOk();
        $this->assertCount(1, $this->sent('POST', self::ORG.'/campaigns/'.self::LATER.'/start'));
        $block = RecordedTelenow::body('getCampaign.later')['data']['dial_block'];
        $running->assertJsonPath('status', 'running')->assertJsonPath('waiting', ['reason' => 'window', 'opensAt' => $block['opensAt']]);
        $this->assertStringNotContainsString($block['message'], $running->getContent(), "never Telenow's own words");
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/start")->assertStatus(409)->assertExactJson(['message' => __('campaigns.cannot_start')]);

        foreach ([['pause', 'later-paused', 'paused'], ['cancel', 'later-cancelled', 'cancelled']] as [$action, $state, $status]) {
            $now = RecordedTelenow::body("getCampaign.{$state}");
            $this->answering(fn (Request $r) => self::is($r, 'GET', self::ORG.'/campaigns/'.self::LATER) ? Http::response($now) : null);
            $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/{$action}")->assertOk()->assertJsonPath('status', $status)->assertJsonPath('waiting', null);
            $this->assertCount(1, $this->sent('POST', self::ORG.'/campaigns/'.self::LATER."/{$action}"));
        }
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/cancel")->assertStatus(409)->assertExactJson(['message' => __('campaigns.finished')]);
        $this->actingAs($this->ana)->postJson("/api/kit/console/campaigns/{$c->id}/start")->assertStatus(409)->assertExactJson(['message' => __('campaigns.cannot_start')]);
    }

    public function test_the_job_reads_running_campaigns_and_brings_each_calls_result_by_the_kits_id(): void
    {
        parse_str(self::recorded('getCampaignResultsV1.feed')['request']['query'], $q);
        $c = $this->campaign(['status' => 'running', 'created_at' => Carbon::parse($q['since'])]);
        $ana = $this->target($c, ['id' => self::ANA, 'pushed_at' => now()]);
        $ben = $this->target($c, ['id' => self::BEN, 'phone_number' => '+14155550102', 'pushed_at' => now()]);
        $this->artisan('kit:campaigns')->assertSuccessful();
        $done = RecordedTelenow::body('getCampaign.customer')['data'];
        $this->assertSame([$done['status'], $done['failed_targets'], $done['total_targets']], [$c->fresh()->status, $c->fresh()->failed_targets, $c->fresh()->total_targets]);
        [$first, $next] = $this->sent('GET', '/api/v1/campaigns/'.self::CAMPAIGN.'/results');
        parse_str((string) parse_url($first->url(), PHP_URL_QUERY), $q1);
        parse_str((string) parse_url($next->url(), PHP_URL_QUERY), $q2);
        $feed = RecordedTelenow::body('getCampaignResultsV1.feed');
        $this->assertSame([['since' => $q['since']], ['cursor' => $feed['nextCursor']]], [$q1, $q2], 'read on from where it stopped');
        $byId = collect($feed['results'])->keyBy('id');
        foreach ([$ana, $ben] as $t) {
            $t->refresh();
            $this->assertSame([$byId[$t->id]['outcome'], 1, $byId[$t->id]['callId']], [$t->outcome, $t->attempt, $t->telenow_call_id]);
            $this->assertNotNull($t->completed_at);
        }
        $this->assertSame($feed['nextCursor'], $c->fresh()->results_cursor);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}")->assertJsonPath('status', 'completed')
            ->assertJsonPath('counts', ['people' => $done['total_targets'], 'answered' => $done['completed_targets'], 'notReached' => $done['failed_targets']]);
        $this->assertSame(['done'], array_values(array_unique(array_column($this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/targets")->json('targets'), 'state'))));

        $this->travel(config('kit.campaigns.read_minutes'))->minutes();
        $this->artisan('kit:campaigns')->assertSuccessful();
        $this->assertCount(2, $this->sent('GET', '/api/v1/campaigns/'.self::CAMPAIGN.'/results'), 'finished, every result in: not read again');
    }

    public function test_the_job_leaves_a_campaign_the_kit_is_changing_alone_but_takes_its_results(): void
    {
        $c = $this->campaign(['status' => 'running', 'sync_state' => 'pending', 'created_at' => now()->subHour()]);
        $ana = $this->target($c, ['id' => self::ANA, 'pushed_at' => now()]);
        $this->artisan('kit:campaigns')->assertSuccessful();
        $this->assertSame(['running', 'pending'], [$c->fresh()->status, $c->fresh()->sync_state]);
        $this->assertSame('failed', $ana->fresh()->outcome);
    }

    public function test_the_job_reads_again_only_after_read_minutes_and_a_finished_campaign_only_while_someone_waits(): void
    {
        $this->answering(fn (Request $r) => self::is($r, 'GET', '/api/v1/campaigns/'.self::LATER.'/results') ? Http::response(['results' => [], 'nextCursor' => null]) : null);
        $reads = fn (string $id) => count($this->sent('GET', self::ORG.'/campaigns/'.$id));
        $running = $this->campaign(['telenow_id' => self::LATER, 'status' => 'running', 'read_at' => now()]);
        $this->target($running, ['pushed_at' => now()]);
        $finished = $this->campaign(['status' => 'completed']);
        $this->target($finished, ['id' => self::ANA, 'pushed_at' => now()]);
        $done = (string) Str::uuid();
        $this->campaign(['status' => 'completed', 'telenow_id' => $done]); // finished, nobody waiting

        $this->artisan('kit:campaigns')->assertSuccessful();
        $this->assertSame([0, 1, 0], [$reads(self::LATER), $reads(self::CAMPAIGN), $reads($done)], 'read moments ago: not yet; finished with someone waiting: read; finished, nobody waiting: never');
        $this->assertSame('failed', $finished->targets()->sole()->outcome);
        $this->travel(config('kit.campaigns.read_minutes'))->minutes();
        $this->artisan('kit:campaigns')->assertSuccessful();
        $this->assertSame([1, 1], [$reads(self::LATER), $reads(self::CAMPAIGN)], 'read again once read_minutes have passed; the finished one has every result');

        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$running->id}")->assertJsonPath('waiting.reason', 'window');
        $finished->forceFill(['dial_block' => $running->fresh()->dial_block])->save();
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$finished->id}")->assertJsonPath('status', 'completed')->assertJsonPath('waiting', null);
    }

    public function test_how_many_can_be_called_and_the_variables_its_agent_uses_are_read_live(): void
    {
        $c = $this->campaign();
        $reach = RecordedTelenow::body('getCampaignReach.customer')['data'];
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/reach")->assertOk()->assertExactJson(['pending' => $reach['pending'],
            'dialable' => $reach['dialable'], 'onDoNotCall' => $reach['excludedDnc'], 'withoutConsent' => $reach['excludedConsent'], 'blocked' => false]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/variables")->assertOk()->assertExactJson(['variables' => RecordedTelenow::body('listCampaignVariables.customer')['data']['variables']]);
        $this->answering(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/reach') ? Http::response(['success' => true, 'data' => array_merge($reach, ['accountBlock' => 'spend limit reached', 'excludedDnc' => 3, 'excludedConsent' => 4])]) : null);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/reach")->assertJsonPath('blocked', true)->assertJsonMissingPath('accountBlock')
            ->assertJsonPath('onDoNotCall', 3)->assertJsonPath('withoutConsent', 4);
        $this->answering(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/variables') ? Http::response(['success' => false, 'error' => 'boom'], 500) : null);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}/variables")->assertStatus(503)->assertExactJson(['message' => __('campaigns.unavailable')]);
        $unmade = $this->campaign(['telenow_id' => null]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$unmade->id}/reach")->assertStatus(409)->assertExactJson(['message' => __('campaigns.not_ready')]);
    }

    public function test_a_campaign_is_deleted_and_only_its_account_sees_it(): void
    {
        $c = $this->campaign();
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $theirs = (new Campaign)->forceFill(['workspace_id' => $other->id, 'name' => 'Theirs', 'concurrency' => 1, 'timezone' => 'UTC', 'machine_detection' => 'off',
            'max_attempts' => 1, 'retry_on_no_answer' => true, 'sync_state' => 'synced']);
        $theirs->save();
        $list = $this->actingAs($this->ana)->getJson('/api/kit/console/campaigns');
        $this->assertSame([$c->id], array_column($list->json('campaigns'), 'id'));
        $this->assertSame([config('kit.campaigns.phone_columns'), config('kit.campaigns.max_attempts'), config('kit.campaigns.import_max_rows')],
            [$list->json('limits.phoneColumns'), $list->json('limits.maxAttempts'), $list->json('limits.importMaxRows')]);
        foreach (['getJson' => '', 'deleteJson' => '', 'postJson' => '/start'] as $verb => $suffix) {
            $this->actingAs($this->ana)->{$verb}("/api/kit/console/campaigns/{$theirs->id}{$suffix}")->assertNotFound();
        }

        $this->actingAs($this->ana)->deleteJson("/api/kit/console/campaigns/{$c->id}")->assertNoContent();
        $this->assertCount(1, $this->sent('DELETE', self::ORG.'/campaigns/'.self::CAMPAIGN));
        $this->assertSame(['synced', true], [$c->fresh()->sync_state, $c->fresh()->deleted_at !== null]);
        $this->actingAs($this->ana)->getJson("/api/kit/console/campaigns/{$c->id}")->assertNotFound();

        $gone = $this->campaign(['telenow_id' => self::LATER]);
        $this->answering(fn (Request $r) => $r->method() === 'DELETE' ? Http::response(self::recorded('deleteCampaign.gone')['response']['body'], 404) : null);
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/campaigns/{$gone->id}")->assertNoContent();
        $this->assertSame('synced', $gone->fresh()->sync_state, 'Telenow no longer has it: as good as deleted');

        $never = $this->campaign(['telenow_id' => null, 'sync_state' => 'failed']);
        (new OutboxEntry)->forceFill(['resource' => CampaignWrite::RESOURCE, 'kit_id' => $never->id, 'operation' => 'create', 'body' => [], 'idempotency_key' => 'k',
            'next_attempt_at' => now(), 'failed_at' => now()])->save();
        $this->actingAs($this->ana)->deleteJson("/api/kit/console/campaigns/{$never->id}")->assertNoContent();
        $this->assertSame(__('campaigns.cancelled'), OutboxEntry::query()->where('kit_id', $never->id)->sole()->last_error);
    }
}
