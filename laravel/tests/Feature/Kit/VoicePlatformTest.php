<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Models\Call;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Admin → Voice platform (App\Kit\Health): the money check compares what Telenow says a customer spent in all with
 * what the kit charged for, and the period's calls, keeping the last figures with the reason when Telenow cannot be
 * read, a few customers at a time and each again only when due; the scheduler's own events record when each job ran,
 * a job is late after its schedule said it should have started, and the scheduler counts as stopped when nothing ran
 * lately; the report lists the events endpoint, failed events and refused writes (each can be tried again), and the
 * customers whose gap is over the tolerance, whose calls differ or whose check failed, biggest gap first; all of it the
 * staff's alone.
 */
class VoicePlatformTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Workspace $acme;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        app(Settings::class)->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $usage = RecordedTelenow::body('getSubAccountUsage.operator');
        $this->acme->forceFill(['telenow_sub_account_id' => $usage['subAccountId']])->save();
    }

    private function rollup(Workspace $w, string $day, string $costUsd): void
    {
        DB::table('charge_rollups')->insert(['day' => $day, 'workspace_id' => $w->id, 'plan' => 'base', 'subject' => '', 'currency' => 'INR',
            'count' => 1, 'price' => '1.00', 'cost_usd' => $costUsd, 'cost' => '0', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function report(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)->getJson('/api/kit/admin/telenow')->assertOk();
    }

    public function test_the_money_check_compares_telenows_spend_with_what_the_kit_charged(): void
    {
        RecordedTelenow::fake();
        $usage = RecordedTelenow::body('getSubAccountUsage.operator');
        $this->rollup($this->acme, '2026-10-02', '0.010000');
        $this->rollup($this->acme, '2026-10-03', '0.005');
        $call = fn (string $state, string $at) => Call::forceCreate(['workspace_id' => $this->acme->id, 'telenow_id' => (string) Str::uuid(),
            'status' => 'ended', 'started_at' => $at, 'charge_state' => $state]);
        $call('charged', '2026-10-02 10:00:00');
        $call('unrated', '2026-10-03 10:00:00');
        $call('refused', '2026-09-20 10:00:00');
        $call('charged', '2026-09-20 11:00:00');

        $this->artisan('kit:reconcile')->expectsOutput(__('health.reconciled', ['count' => 1]))->assertSuccessful();
        $row = DB::table('reconciliations')->where('workspace_id', $this->acme->id)->first();
        $this->assertSame((string) $usage['totalSpendUsd'], $row->telenow_spend_usd);
        $this->assertSame('0.015000', $row->charged_cost_usd);
        $this->assertSame((string) \Brick\Math\BigDecimal::of((string) $usage['totalSpendUsd'])->minus('0.015000'), $row->drift_usd);
        $this->assertSame($usage['calls'], (int) $row->telenow_period_calls);
        $this->assertSame(2, (int) $row->kit_period_calls, 'the calls since Telenow\'s period began');
        $this->assertSame(2, (int) $row->uncharged_calls);
        $this->assertNull($row->error);

        // Checked lately: not again until due.
        $this->artisan('kit:reconcile')->expectsOutput(__('health.reconciled', ['count' => 0]));
        $this->travel(config('kit.reconcile.every_minutes') + 1)->minutes();

        // Telenow refuses the key: the last figures stay, with why.
        app(Settings::class)->put('telenow.api_key', 'vai_test_no_such_key', secret: true);
        $this->artisan('kit:reconcile')->expectsOutput(__('health.reconciled', ['count' => 1]));
        $after = DB::table('reconciliations')->where('workspace_id', $this->acme->id)->first();
        $this->assertNotNull($after->error);
        $this->assertSame($row->drift_usd, $after->drift_usd);
        $this->assertSame($row->telenow_spend_usd, $after->telenow_spend_usd);
        $this->report()->assertJsonPath('reconciliation.customers.0.error', $after->error);
    }

    public function test_a_run_checks_a_few_customers_at_a_time_the_longest_unchecked_first(): void
    {
        Http::fake(['*' => Http::response(['totalSpendUsd' => 0, 'calls' => 0, 'from' => now()->startOfMonth()->toIso8601String()])]);
        $others = collect(range(1, 3))->map(fn (int $i) => tap(Workspace::create(['name' => "Co {$i}", 'status' => 'active', 'sync_state' => 'synced']),
            fn (Workspace $w) => $w->forceFill(['telenow_sub_account_id' => (string) Str::uuid()])->save()));
        Workspace::create(['name' => 'Not ready', 'status' => 'active', 'sync_state' => 'pending']);
        config(['kit.reconcile.accounts_per_run' => 2]);
        $this->artisan('kit:reconcile')->expectsOutput(__('health.reconciled', ['count' => 2]));
        $this->travel(1)->minutes();
        $this->artisan('kit:reconcile')->expectsOutput(__('health.reconciled', ['count' => 2]));
        $this->artisan('kit:reconcile')->expectsOutput(__('health.reconciled', ['count' => 0]));
        $this->assertSame(4, DB::table('reconciliations')->count(), 'each customer with a sub-account, once');
        $this->assertNull(DB::table('reconciliations')->whereNotIn('workspace_id', $others->pluck('id')->push($this->acme->id))->first());
    }

    public function test_the_schedulers_own_events_say_when_each_job_ran_and_which_are_late(): void
    {
        $schedule = app(Schedule::class);
        $outbox = $schedule->command('kit:outbox')->everyMinute();
        $catalog = $schedule->command('kit:catalog')->hourly();
        $shell = $schedule->exec('echo hi')->everyMinute();
        $this->report()->assertJsonPath('jobs.running', false)->assertJsonPath('jobs.jobs', []);

        $this->travelTo('2026-10-31 10:20:30');
        event(new ScheduledTaskStarting($catalog));
        event(new ScheduledTaskFinished($catalog, 1.5));
        event(new ScheduledTaskStarting($shell));
        $this->travelTo('2026-10-31 10:59:10');
        event(new ScheduledTaskStarting($outbox));
        event(new ScheduledTaskFailed($outbox, new \RuntimeException('boom'), 0.2));

        $jobs = collect($this->report()->assertJsonPath('jobs.running', true)->json('jobs.jobs'))->keyBy('command');
        $this->assertSame(['kit:catalog', 'kit:outbox'], $jobs->keys()->all(), 'only artisan jobs');
        $this->assertSame([true, false], [$jobs['kit:catalog']['succeeded'], $jobs['kit:outbox']['succeeded']]);
        $this->assertSame(['0 * * * *', '* * * * *'], [$jobs['kit:catalog']['expression'], $jobs['kit:outbox']['expression']]);
        $this->assertSame([false, false], [$jobs['kit:catalog']['late'], $jobs['kit:outbox']['late']]);
        $this->assertSame(1500, DB::table('job_runs')->where('command', 'kit:catalog')->value('runtime_ms'));

        // Nothing has run since 10:59:10. At 11:04 the hourly job's 11:00 run is still within the grace, and so is the
        // every-minute job; at 11:05 both are late, and nothing has started for stale_minutes: the scheduler stopped.
        $this->travelTo('2026-10-31 11:04:00');
        $jobs = collect($this->report()->assertJsonPath('jobs.running', true)->json('jobs.jobs'))->keyBy('command');
        $this->assertSame([false, false], [$jobs['kit:catalog']['late'], $jobs['kit:outbox']['late']]);
        $this->travelTo('2026-10-31 11:05:00');
        $jobs = collect($this->report()->assertJsonPath('jobs.running', false)->json('jobs.jobs'))->keyBy('command');
        $this->assertSame([true, true], [$jobs['kit:catalog']['late'], $jobs['kit:outbox']['late']]);
        $this->travelBack();
    }

    public function test_the_report_lists_events_writes_and_drifting_customers_and_each_can_be_tried_again(): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);
        $settings = app(Settings::class);
        $settings->put('telenow.events', ['id' => 'hook-1', 'url' => 'https://voice.example/api/kit/telenow/events', 'events' => ['call.completed'], 'registeredAt' => '2026-10-30T10:00:00+00:00']);
        $settings->put('telenow.events_error', null);
        $event = fn (string $id, array $over) => DB::table('telenow_events')->insertGetId($over + ['delivery_id' => $id, 'event' => 'call.completed', 'body' => '{}',
            'created_at' => now(), 'updated_at' => now()]);
        $event('a', ['applied_at' => now()]);
        $event('b', []);
        $failedEvent = $event('c', ['failed_at' => now(), 'last_error' => str_repeat('x', config('kit.health.error_length') + 50), 'attempts' => 8]);
        $event('old', ['applied_at' => now(), 'created_at' => now()->subHours(config('kit.health.recent_hours') + 1)]);
        $write = fn (array $over) => DB::table('telenow_outbox')->insertGetId($over + ['resource' => 'workspace', 'kit_id' => $this->acme->id,
            'operation' => 'create', 'idempotency_key' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
        $write(['done_at' => now()]);
        $write([]);
        $failedWrite = $write(['failed_at' => now(), 'last_error' => 'sub-account limit reached', 'attempts' => 8]);

        $bolt = Workspace::create(['name' => 'Bolt', 'status' => 'active', 'sync_state' => 'synced']);
        $cora = Workspace::create(['name' => 'Cora', 'status' => 'active', 'sync_state' => 'synced']);
        $dino = Workspace::create(['name' => 'Dino', 'status' => 'active', 'sync_state' => 'synced']);
        $check = fn (Workspace $w, array $over) => DB::table('reconciliations')->insert($over + ['workspace_id' => $w->id, 'checked_at' => now(),
            'telenow_spend_usd' => '1', 'charged_cost_usd' => '1', 'drift_usd' => '0', 'telenow_period_calls' => 3, 'kit_period_calls' => 3,
            'created_at' => now(), 'updated_at' => now()]);
        $check($this->acme, ['drift_usd' => '0.0101']);
        $check($bolt, ['drift_usd' => '-2.5']);
        $check($cora, ['drift_usd' => (string) config('kit.reconcile.tolerance_usd')]);
        $check($dino, ['kit_period_calls' => 2]);

        $r = $this->report();
        $r->assertJsonPath('events.registered', true)->assertJsonPath('events.url', 'https://voice.example/api/kit/telenow/events')
            ->assertJsonPath('events.recent', 3)->assertJsonPath('events.waiting', 1)->assertJsonPath('events.failed', 1)
            ->assertJsonPath('events.failures.0.id', $failedEvent)->assertJsonPath('writes.waiting', 1)->assertJsonPath('writes.failed', 1)
            ->assertJsonPath('writes.failures.0.error', 'sub-account limit reached')->assertJsonPath('telenow.connected', true);
        $this->assertSame(config('kit.health.error_length') + 3, mb_strlen($r->json('events.failures.0.error')), 'cut, with an ellipsis');
        $this->assertSame(['Bolt', 'Acme Dental', 'Dino'], array_column($r->json('reconciliation.customers'), 'name'), 'over the tolerance, or calls differ; biggest gap first');
        $r->assertJsonPath('reconciliation.drifting', 3)->assertJsonPath('reconciliation.checked', 4);

        // Tried again: back in the queue (Telenow is down here, so it waits instead of being given up).
        $this->actingAs($this->staff)->postJson("/api/kit/admin/telenow/writes/{$failedWrite}/retry")->assertOk()->assertJsonPath('writes.failed', 0);
        $this->assertNull(DB::table('telenow_outbox')->where('id', $failedWrite)->value('failed_at'));
        $this->actingAs($this->staff)->postJson("/api/kit/admin/telenow/writes/{$failedWrite}/retry")->assertNotFound()
            ->assertJsonPath('message', __('health.write_not_failed'));
        $this->actingAs($this->staff)->postJson("/api/kit/admin/telenow/events/{$failedEvent}/retry")->assertOk()->assertJsonPath('events.failed', 0);
        $this->assertNull(DB::table('telenow_events')->where('id', $failedEvent)->value('failed_at'));
        $this->actingAs($this->staff)->postJson('/api/kit/admin/telenow/events/nonsense/retry')->assertNotFound();

        $this->actingAs($this->staff)->postJson("/api/kit/admin/telenow/reconcile/{$this->acme->id}")->assertOk();
        $this->assertSame('down', DB::table('reconciliations')->where('workspace_id', $this->acme->id)->value('error'));
        $notReady = Workspace::create(['name' => 'New', 'status' => 'active', 'sync_state' => 'pending']);
        $this->actingAs($this->staff)->postJson("/api/kit/admin/telenow/reconcile/{$notReady->id}")->assertNotFound()
            ->assertJsonPath('message', __('health.customer_not_ready'));
    }

    public function test_the_voice_platform_page_is_the_staffs_alone(): void
    {
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 9']);
        $this->getJson('/api/kit/admin/telenow')->assertUnauthorized();
        $this->postJson("/api/kit/admin/telenow/reconcile/{$this->acme->id}")->assertUnauthorized();
        $this->actingAs($ana)->getJson('/api/kit/admin/telenow')->assertForbidden();
        $this->actingAs($ana)->postJson('/api/kit/admin/telenow/writes/1/retry')->assertForbidden();
        $this->actingAs($ana)->postJson('/api/kit/admin/telenow/events/1/retry')->assertForbidden();
    }
}
