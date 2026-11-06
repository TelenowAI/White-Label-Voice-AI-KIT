<?php

namespace App\Kit\Health;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\DB;

/**
 * When each scheduled job last ran (the `job_runs` table), from Laravel's own scheduler events, which
 * AppServiceProvider passes here: on shared hosting one cron line (`php artisan schedule:run`, every minute) drives
 * every kit job (routes/console.php), and when it is missing nothing is charged, synced or sent — the admin must see
 * that (SELF_HOSTED_KIT_PLAN.md §8). A job is late when it has not started since the last time its schedule said it
 * should, at least config('kit.jobs.grace_minutes') ago; the scheduler is not running when no job has started for
 * config('kit.jobs.stale_minutes').
 *
 * Read by HealthReport and App\Kit\Reports\Dashboard. Only artisan commands are tracked (all of the kit's jobs are).
 */
final class JobRuns
{
    public function started(ScheduledTaskStarting $e): void
    {
        $this->write($e->task, ['started_at' => now()]);
    }

    public function finished(ScheduledTaskFinished $e): void
    {
        $this->write($e->task, ['finished_at' => now(), 'succeeded' => ($e->task->exitCode ?? 0) === 0, 'runtime_ms' => (int) round($e->runtime * 1000)]);
    }

    public function failed(ScheduledTaskFailed $e): void
    {
        $this->write($e->task, ['finished_at' => now(), 'succeeded' => false]);
    }

    /** The artisan command a scheduled task runs (`kit:outbox`), or null for anything else. */
    public static function nameOf(Event $task): ?string
    {
        return preg_match("/artisan'?\\s+'?([a-z0-9][a-z0-9:_-]*)/i", (string) $task->command, $m) ? $m[1] : null;
    }

    /** Whether any job has started in the last config('kit.jobs.stale_minutes'). */
    public function running(): bool
    {
        $last = DB::table('job_runs')->max('started_at');

        return $last !== null && CarbonImmutable::parse($last)->greaterThanOrEqualTo(now()->subMinutes(config('kit.jobs.stale_minutes')));
    }

    /** @return array{lastStartedAt: ?string, running: bool, jobs: list<array>} */
    public function report(): array
    {
        $now = CarbonImmutable::now();
        $jobs = DB::table('job_runs')->orderBy('command')->get()->map(function ($r) use ($now) {
            // The last time it was due at least the grace ago: it is late when it has not started since.
            $due = CarbonImmutable::instance((new CronExpression($r->expression))
                ->getPreviousRunDate($now->subMinutes(config('kit.jobs.grace_minutes')), 0, true));
            $started = $r->started_at === null ? null : CarbonImmutable::parse($r->started_at);

            return [
                'command' => $r->command,
                'expression' => $r->expression,
                'startedAt' => $started?->toIso8601String(),
                'finishedAt' => $r->finished_at === null ? null : CarbonImmutable::parse($r->finished_at)->toIso8601String(),
                'succeeded' => $r->succeeded === null ? null : (bool) $r->succeeded,
                'late' => $started === null || $started->lessThan($due),
            ];
        })->all();
        $last = DB::table('job_runs')->max('started_at');

        return ['lastStartedAt' => $last === null ? null : CarbonImmutable::parse($last)->toIso8601String(), 'running' => $this->running(), 'jobs' => $jobs];
    }

    private function write(Event $task, array $values): void
    {
        $name = self::nameOf($task);
        if ($name === null) {
            return;
        }
        $key = ['command' => $name];
        DB::table('job_runs')->insertOrIgnore($key + ['expression' => $task->expression, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('job_runs')->where($key)->update($values + ['expression' => $task->expression, 'updated_at' => now()]);
    }
}
