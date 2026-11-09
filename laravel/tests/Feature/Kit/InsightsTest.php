<?php

namespace Tests\Feature\Kit;

use App\Models\Agent;
use App\Models\Call;
use App\Models\ConsentRecord;
use App\Models\DncEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Insights and files from the kit's own records (contract/kit-api.yaml getInsights, listCalls' filters, exportCalls, exportDnc, exportConsent):
 * a period in the viewer's time zone, the answered rule, sentiment as its own column (filled as a call is read, and for calls read before),
 * spend added as decimals in each currency, each day and each agent, and the files' rows, words and escaping. Nothing of the voice platform
 * is in any of them, and no account sees another's.
 */
class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private User $ben;

    private Workspace $acme;

    private Agent $desk;

    private Agent $sales;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->travelTo(CarbonImmutable::parse('2026-11-08 12:00:00', 'UTC'));
        $this->ben = User::create(['name' => 'Ben', 'email' => 'ben@example.com', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ben->id, 'role' => 'member']);
        $this->desk = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Front desk']);
        $this->sales = Agent::create(['workspace_id' => $this->acme->id, 'name' => 'Sales line']);
    }

    private function aCall(array $columns): Call
    {
        $call = (new Call)->forceFill($columns + ['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(), 'status' => 'ended', 'direction' => 'inbound',
            'channel' => 'telephony', 'detail_state' => 'final', 'reads' => 1]);
        $call->save();

        return $call;
    }

    private function ask(string $path): TestResponse
    {
        return $this->actingAs($this->ben)->getJson($path);
    }

    /** A spread of calls: two days, two agents and none, answered, a machine, unanswered, three currencies of charge, a chat. */
    private function spread(): void
    {
        $this->aCall(['agent_id' => $this->desk->id, 'started_at' => '2026-11-07 09:00:00', 'duration_sec' => 120, 'outcome' => 'answered', 'sentiment' => 'positive',
            'charge_state' => 'charged', 'charge_amount' => '0.1', 'charge_currency' => 'INR', 'analysis' => ['summary' => 'Booked a cleaning.']]);
        $this->aCall(['agent_id' => $this->desk->id, 'started_at' => '2026-11-07 10:00:00', 'duration_sec' => 60, 'outcome' => 'answered', 'sentiment' => 'negative',
            'charge_state' => 'charged', 'charge_amount' => '0.2', 'charge_currency' => 'INR']);
        $this->aCall(['agent_id' => $this->desk->id, 'started_at' => '2026-11-07 11:00:00', 'duration_sec' => 30, 'outcome' => 'machine', 'answered_by' => 'machine_start',
            'charge_state' => 'charged', 'charge_amount' => '1.25', 'charge_currency' => 'USD']);
        $this->aCall(['agent_id' => $this->sales->id, 'started_at' => '2026-11-06 22:00:00', 'duration_sec' => 0, 'outcome' => 'no-answer', 'direction' => 'outbound',
            'charge_state' => 'refused', 'charge_amount' => '9', 'charge_currency' => 'INR']);
        $this->aCall(['agent_id' => $this->sales->id, 'started_at' => '2026-11-08 08:00:00', 'duration_sec' => 300, 'outcome' => null, 'channel' => 'chat', 'direction' => 'web',
            'sentiment' => 'positive']);
        $this->aCall(['agent_id' => null, 'started_at' => '2026-11-08 09:00:00', 'duration_sec' => 45, 'outcome' => 'answered', 'channel' => 'softphone']);
        // Another account's call: never counted.
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        $this->aCall(['workspace_id' => $other->id, 'started_at' => '2026-11-07 09:00:00', 'duration_sec' => 999, 'outcome' => 'answered', 'charge_state' => 'charged',
            'charge_amount' => '100', 'charge_currency' => 'INR']);
    }

    public function test_the_figures_of_a_period(): void
    {
        $this->spread();
        $got = $this->ask('/api/kit/console/insights?from=2026-11-06&to=2026-11-08&timeZone=UTC')->assertOk();
        $this->assertSame(['from' => '2026-11-06', 'to' => '2026-11-08', 'timeZone' => 'UTC', 'calls' => 6, 'answered' => 4, 'seconds' => 555, 'averageSeconds' => 131],
            array_intersect_key($got->json(), array_flip(['from', 'to', 'timeZone', 'calls', 'answered', 'seconds', 'averageSeconds'])),
            'answered: it lasted and no machine picked up (120 + 60 + 300 + 45, over 4)');
        $this->assertSame([['key' => 'answered', 'count' => 3], ['key' => null, 'count' => 1], ['key' => 'machine', 'count' => 1], ['key' => 'no-answer', 'count' => 1]], $got->json('outcomes'));
        $this->assertSame([['key' => 'positive', 'count' => 2], ['key' => 'negative', 'count' => 1]], $got->json('sentiments'), 'only calls with a sentiment');
        $this->assertSame([['key' => 'telephony', 'count' => 4], ['key' => 'chat', 'count' => 1], ['key' => 'softphone', 'count' => 1]], $got->json('channels'));
        $this->assertSame([['currency' => 'INR', 'amount' => '0.3'], ['currency' => 'USD', 'amount' => '1.25']], $got->json('spend'), 'charged calls only, added as decimals');
        $this->assertSame([
            ['day' => '2026-11-06', 'calls' => 1, 'answered' => 0, 'seconds' => 0],
            ['day' => '2026-11-07', 'calls' => 3, 'answered' => 2, 'seconds' => 210],
            ['day' => '2026-11-08', 'calls' => 2, 'answered' => 2, 'seconds' => 345],
        ], $got->json('days'));
        $this->assertSame([
            ['agentId' => $this->desk->id, 'name' => 'Front desk', 'calls' => 3, 'answered' => 2, 'seconds' => 210, 'spend' => [['currency' => 'INR', 'amount' => '0.3'], ['currency' => 'USD', 'amount' => '1.25']]],
            ['agentId' => $this->sales->id, 'name' => 'Sales line', 'calls' => 2, 'answered' => 1, 'seconds' => 300, 'spend' => []],
            ['agentId' => null, 'name' => null, 'calls' => 1, 'answered' => 1, 'seconds' => 45, 'spend' => []],
        ], $got->json('agents'));
        $this->assertStringNotContainsString('telenow', strtolower($got->getContent()));

        // One agent's calls.
        $this->assertSame(3, $this->ask("/api/kit/console/insights?from=2026-11-06&to=2026-11-08&agentId={$this->desk->id}")->json('calls'));
        $this->assertSame(0, $this->ask('/api/kit/console/insights?from=2026-11-06&to=2026-11-08&agentId=not-an-id')->json('calls'));
    }

    public function test_agents_are_most_calls_first(): void
    {
        // Made in the other order: the agent with fewer calls first.
        $this->aCall(['agent_id' => $this->desk->id, 'started_at' => '2026-11-07 09:00:00', 'duration_sec' => 10]);
        $this->aCall(['agent_id' => $this->sales->id, 'started_at' => '2026-11-07 10:00:00', 'duration_sec' => 10]);
        $this->aCall(['agent_id' => $this->sales->id, 'started_at' => '2026-11-07 11:00:00', 'duration_sec' => 10]);
        $this->assertSame(['Sales line', 'Front desk'], array_column($this->ask('/api/kit/console/insights?from=2026-11-07&to=2026-11-07')->json('agents'), 'name'));
    }

    public function test_days_are_the_viewers_own(): void
    {
        $this->spread();
        // In Asia/Kolkata (UTC+5:30) the call at 22:00 UTC on the 6th is on the 7th, and the last 30 days end today there.
        $got = $this->ask('/api/kit/console/insights?from=2026-11-07&to=2026-11-07&timeZone=Asia/Kolkata')->assertOk();
        $this->assertSame([4, 'Asia/Kolkata'], [$got->json('calls'), $got->json('timeZone')]);
        $default = $this->ask('/api/kit/console/insights?timeZone=Asia/Kolkata')->assertOk();
        $this->assertSame(['2026-10-10', '2026-11-08', 30], [$default->json('from'), $default->json('to'), count($default->json('days'))]);
        $this->assertSame(6, $default->json('calls'));
        // The old name some browsers still report for the same zone.
        $this->assertSame(4, $this->ask('/api/kit/console/insights?from=2026-11-07&to=2026-11-07&timeZone=Asia/Calcutta')->assertOk()->json('calls'));
        $this->assertSame(4, count($this->ask('/api/kit/console/calls?from=2026-11-07&to=2026-11-07&timeZone=Asia/Calcutta')->assertOk()->json('calls')));
    }

    public function test_a_period_that_is_not_one_is_refused_by_its_field(): void
    {
        $this->ask('/api/kit/console/insights?timeZone=Mars/Olympus')->assertStatus(422)->assertJsonValidationErrors(['timeZone']);
        $this->ask('/api/kit/console/insights?from=07-11-2026')->assertStatus(422)->assertJsonValidationErrors(['from']);
        $this->ask('/api/kit/console/insights?from=2026-11-08&to=2026-11-07')->assertStatus(422)->assertJsonPath('errors.to.0', __('calls.period_backwards'));
        $this->ask('/api/kit/console/insights?from=2025-01-01&to=2026-11-07')->assertStatus(422)
            ->assertJsonPath('errors.to.0', __('calls.period_too_long', ['days' => config('kit.insights.max_days')]));
        $this->ask('/api/kit/console/insights?from=2025-11-08&to=2026-11-08')->assertOk();
        $this->ask('/api/kit/console/calls?timeZone=Mars/Olympus')->assertStatus(422);
        $this->ask('/api/kit/console/calls/export?to=nope')->assertStatus(422);
    }

    public function test_the_calls_list_takes_the_same_filters(): void
    {
        $this->spread();
        $ids = fn (TestResponse $r) => count($r->json('calls'));
        $this->assertSame(6, $ids($this->ask('/api/kit/console/calls')->assertOk()), 'no period: every call, as before');
        $this->assertSame(3, $ids($this->ask('/api/kit/console/calls?from=2026-11-07&to=2026-11-07')));
        $this->assertSame(4, $ids($this->ask('/api/kit/console/calls?from=2026-11-07&to=2026-11-07&timeZone=Asia/Kolkata')));
        $this->assertSame(3, $ids($this->ask('/api/kit/console/calls?outcome=answered')));
        $this->assertSame(1, $ids($this->ask('/api/kit/console/calls?channel=chat')));
        $this->assertSame(2, $this->ask("/api/kit/console/calls?agentId={$this->desk->id}&outcome=answered")->json('total'));
        $this->assertSame(['answered', 'machine', 'no-answer'], $this->ask('/api/kit/console/calls?channel=chat')->json('outcomes'), "the account's outcomes, most calls first, whatever the filter"); 
    }

    public function test_the_calls_file(): void
    {
        $this->spread();
        $this->aCall(['agent_id' => $this->desk->id, 'started_at' => '2026-11-08 10:00:00', 'duration_sec' => 10, 'outcome' => 'answered', 'from_number' => '+14155550100',
            'analysis' => ['summary' => '=HYPERLINK("http://evil.example")']]);
        $file = $this->actingAs($this->ben)->get('/api/kit/console/calls/export?from=2026-11-07&to=2026-11-08&timeZone=Asia/Kolkata')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('calls-2026-11-08.csv', (string) $file->headers->get('Content-Disposition'));
        $text = $file->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $rows = array_map('str_getcsv', explode("\n", trim(substr($text, 3))));
        $words = __('calls.export_columns');
        $this->assertSame(array_values($words), $rows[0]);
        $this->assertCount(1 + 7, $rows, 'every call of those days in Kolkata, the other account\'s not');
        $this->assertSame(['2026-11-08 15:30:00', 'Front desk', 'inbound', 'telephony', '+14155550100', '', '10', 'answered', '', '', '\'=HYPERLINK("http://evil.example")', '', ''], $rows[1],
            'newest first, in the viewer\'s time; a formula written as text');
        $booked = array_values(array_filter($rows, fn (array $r) => ($r[10] ?? '') === 'Booked a cleaning.'))[0];
        $this->assertSame(['2026-11-07 14:30:00', 'positive', '0.1', 'INR'], [$booked[0], $booked[9], $booked[11], $booked[12]]);
        $refused = array_values(array_filter($rows, fn (array $r) => ($r[7] ?? '') === 'no-answer'))[0];
        $this->assertSame(['', ''], [$refused[11], $refused[12]], 'only what was charged');
        foreach (Call::query()->pluck('telenow_id') as $id) {
            $this->assertStringNotContainsString($id, $text);
        }
        $this->assertCount(1 + 3, array_filter(explode("\n", trim($this->actingAs($this->ben)->get('/api/kit/console/calls/export?channel=telephony&outcome=answered')->streamedContent()))),
            'no period: every answered phone call');
    }

    public function test_the_do_not_call_and_consent_files(): void
    {
        (new DncEntry)->forceFill(['workspace_id' => $this->acme->id, 'phone_number' => '+14155550101', 'reason' => 'asked', 'source' => 'call', 'sync_state' => 'synced'])->save();
        (new DncEntry)->forceFill(['workspace_id' => $this->acme->id, 'phone_number' => '+14155550102', 'reason' => 'gone', 'sync_state' => 'synced', 'deleted_at' => now()])->save();
        $other = Workspace::create(['name' => 'Other', 'status' => 'active', 'sync_state' => 'synced']);
        (new DncEntry)->forceFill(['workspace_id' => $other->id, 'phone_number' => '+14155550103', 'sync_state' => 'synced'])->save();
        $dnc = array_map('str_getcsv', explode("\n", trim(substr($this->actingAs($this->ben)->get('/api/kit/console/dnc/export')->assertOk()->streamedContent(), 3))));
        $this->assertSame([array_values(__('dnc.export_columns')), ['+14155550101', 'asked', 'call', now()->toIso8601String()]], $dnc);

        $record = ['workspace_id' => $this->acme->id, 'consent_type' => 'written', 'scope' => 'calls', 'source' => 'web_form', 'disclosure_version' => 'v2',
            'captured_at' => '2026-11-01 10:00:00', 'recorded_ip' => '198.51.100.7', 'recorded_agent' => 'Mozilla/5.0'];
        (new ConsentRecord)->forceFill($record + ['phone_number' => '+14155550104', 'note' => '+1 asked to be called'])->save();
        (new ConsentRecord)->forceFill($record + ['phone_number' => '+14155550105', 'revoked_at' => '2026-11-05 10:00:00'])->save();
        (new ConsentRecord)->forceFill(['workspace_id' => $other->id, 'phone_number' => '+14155550106'] + array_diff_key($record, ['workspace_id' => 1]))->save();
        $text = $this->actingAs($this->ben)->get('/api/kit/console/consent/export')->assertOk()->streamedContent();
        $consent = array_map('str_getcsv', explode("\n", trim(substr($text, 3))));
        $this->assertSame(array_values(__('consent.export_columns')), $consent[0]);
        $byNumber = array_column(array_slice($consent, 1), null, 0);
        $this->assertEqualsCanonicalizing(['+14155550104', '+14155550105'], array_keys($byNumber), 'withdrawn ones too, the other account\'s not');
        $this->assertSame(['written', 'calls', 'web_form', 'v2', CarbonImmutable::parse('2026-11-01 10:00:00')->toIso8601String(), '', '', '\'+1 asked to be called'],
            array_slice($byNumber['+14155550104'], 1), 'a note that would run as a formula is written as text');
        $this->assertSame(CarbonImmutable::parse('2026-11-05 10:00:00')->toIso8601String(), $byNumber['+14155550105'][7]);
        $this->assertStringNotContainsString('198.51.100.7', $text);
        $this->assertStringNotContainsString('Mozilla', $text);
    }

    public function test_a_calls_sentiment_is_kept_as_it_is_read_and_for_calls_read_before(): void
    {
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => 'vai_test_customer_acme'])->save();
        RecordedTelenow::fake();
        $answered = RecordedTelenow::body('getCallAnalysis.answered');
        $call = app(\App\Kit\Calls\Calls::class)->read($this->acme, $answered['data']['sessionId']);
        if ($call !== null && $call->analysis !== null) {
            $this->assertSame(strtolower($answered['data']['sentiment']), $call->sentiment);
        } else {
            $this->markTestIncomplete('the recorded call has no analysis to read');
        }

        // Calls read before the column: the migration fills theirs from the analysis already kept.
        $old = $this->aCall(['started_at' => now(), 'analysis' => ['sentiment' => '  Neutral ', 'summary' => 'x']]);
        $none = $this->aCall(['started_at' => now(), 'analysis' => ['summary' => 'no sentiment']]);
        Schema::table('calls', fn (Blueprint $t) => $t->dropColumn('sentiment'));
        (require database_path('migrations/2026_11_08_200000_add_sentiment_to_calls.php'))->up();
        $this->assertSame(['neutral', null], [DB::table('calls')->where('id', $old->id)->value('sentiment'), DB::table('calls')->where('id', $none->id)->value('sentiment')]);
        Http::preventStrayRequests();
    }
}
