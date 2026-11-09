<?php

namespace App\Kit\Health;

use App\Kit\Settings\Settings;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admin → Voice platform (SELF_HOSTED_KIT_PLAN.md §4.2 "Telenow"): how the kit's link to Telenow is doing.
 * - the operator's key: connected, rejected, the last status read and its error;
 * - events: the kit's endpoint at Telenow (App\Kit\Events\Endpoint), the last event received, how many came lately,
 *   how many wait or failed, the latest failures;
 * - writes to Telenow (App\Kit\Outbox): how many wait or were given up, the latest given up;
 * - scheduled jobs (JobRuns): each one's last run, the late ones, whether the scheduler runs at all;
 * - the money check (Reconciliation): the customers whose gap is over the tolerance, whose call counts differ, or
 *   whose check failed, biggest gap first.
 * - call health: the providers that failed on customers' recent calls, added up by `kit:call-health` (App\Kit\Calls\CallHealth);
 * Lists are at most config('kit.health.list') rows, with how many there are in all. Used by Admin\HealthController;
 * drifting() and JobRuns::running() also feed the dashboard's "needs a look".
 */
final class HealthReport
{
    public function __construct(private readonly Settings $settings, private readonly JobRuns $jobs, private readonly \App\Kit\Calls\CallHealth $callHealth) {}

    public function build(): array
    {
        $list = config('kit.health.list');
        $endpoint = (array) $this->settings->get('telenow.events', []);
        $events = DB::table('telenow_events');
        $failedEvents = fn () => DB::table('telenow_events')->whereNull('applied_at')->whereNotNull('failed_at');
        $failedWrites = fn () => DB::table('telenow_outbox')->whereNull('done_at')->whereNotNull('failed_at');
        $drifting = $this->drifting();
        $account = (array) $this->settings->get('telenow.account', []);

        return [
            'telenow' => [
                'name' => config('kit.telenow.name'),
                'connected' => is_string($this->settings->get('telenow.api_key')),
                'account' => $account['org_name'] ?? $account['org_id'] ?? null,
                'keyRejected' => (bool) $this->settings->get('telenow.key_rejected', false),
                'error' => $this->settings->get('telenow.status_error'),
                'checkedAt' => $this->settings->get('telenow.status_checked_at'),
            ],
            // The providers that failed on customers' recent calls, as `kit:call-health` last added them up (null before its first run).
            'callHealth' => $this->callHealth->summary(),
            'events' => [
                'registered' => isset($endpoint['id']),
                'url' => $endpoint['url'] ?? null,
                'registeredAt' => $endpoint['registeredAt'] ?? null,
                'checkedAt' => $this->settings->get('telenow.events_checked_at'),
                'error' => $this->settings->get('telenow.events_error'),
                'lastReceivedAt' => $this->iso((clone $events)->max('created_at')),
                'recentHours' => config('kit.health.recent_hours'),
                'recent' => (clone $events)->where('created_at', '>=', now()->subHours(config('kit.health.recent_hours')))->count(),
                'waiting' => DB::table('telenow_events')->whereNull('applied_at')->whereNull('failed_at')->count(),
                'failed' => $failedEvents()->count(),
                'failures' => $failedEvents()->orderByDesc('failed_at')->limit($list)->get(['id', 'event', 'last_error', 'failed_at', 'attempts'])
                    ->map(fn ($e) => ['id' => (int) $e->id, 'event' => $e->event, 'error' => $this->cut($e->last_error),
                        'failedAt' => $this->iso($e->failed_at), 'attempts' => (int) $e->attempts])->all(),
            ],
            'writes' => [
                'waiting' => DB::table('telenow_outbox')->whereNull('done_at')->whereNull('failed_at')->count(),
                'failed' => $failedWrites()->count(),
                'failures' => $failedWrites()->orderByDesc('failed_at')->limit($list)->get(['id', 'resource', 'kit_id', 'operation', 'last_error', 'failed_at', 'attempts'])
                    ->map(fn ($w) => ['id' => (int) $w->id, 'resource' => $w->resource, 'kitId' => $w->kit_id, 'operation' => $w->operation,
                        'error' => $this->cut($w->last_error), 'failedAt' => $this->iso($w->failed_at), 'attempts' => (int) $w->attempts])->all(),
            ],
            'jobs' => $this->jobs->report(),
            'reconciliation' => [
                'everyMinutes' => config('kit.reconcile.every_minutes'),
                'toleranceUsd' => (string) config('kit.reconcile.tolerance_usd'),
                'checked' => DB::table('reconciliations')->whereNotNull('checked_at')->count(),
                'oldestCheckAt' => $this->iso(DB::table('reconciliations')->min('checked_at')),
                'drifting' => count($drifting),
                'customers' => array_slice($drifting, 0, $list),
            ],
        ];
    }

    /**
     * The customers whose last check shows a gap over the tolerance, different call counts, or an error, biggest gap
     * first. Read one row per customer, a chunk at a time.
     *
     * @return list<array>
     */
    public function drifting(): array
    {
        $tolerance = BigDecimal::of((string) config('kit.reconcile.tolerance_usd'));
        $rows = [];
        DB::table('reconciliations')->whereNotNull('checked_at')
            ->lazyById(config('kit.reports.read_chunk'), 'workspace_id')->each(function ($r) use ($tolerance, &$rows) {
                $gap = $r->drift_usd === null ? null : BigDecimal::of($r->drift_usd);
                $callsDiffer = $r->telenow_period_calls !== null && $r->kit_period_calls !== null && (int) $r->telenow_period_calls !== (int) $r->kit_period_calls;
                if ($r->error === null && ! $callsDiffer && ($gap === null || $gap->abs()->isLessThanOrEqualTo($tolerance))) {
                    return;
                }
                $rows[] = ['row' => $r, 'size' => $gap?->abs() ?? BigDecimal::zero()];
            });
        usort($rows, fn (array $a, array $b) => $b['size']->compareTo($a['size']) ?: strcmp($a['row']->workspace_id, $b['row']->workspace_id));
        $names = Workspace::query()->whereIn('id', array_map(fn ($x) => $x['row']->workspace_id, $rows))->pluck('name', 'id');

        return array_map(fn (array $x) => [
            'customerId' => $x['row']->workspace_id,
            'name' => $names[$x['row']->workspace_id] ?? $x['row']->workspace_id,
            'telenowSpendUsd' => $x['row']->telenow_spend_usd,
            'chargedCostUsd' => $x['row']->charged_cost_usd,
            'driftUsd' => $x['row']->drift_usd,
            'periodFrom' => $this->iso($x['row']->period_from),
            'telenowPeriodCalls' => $x['row']->telenow_period_calls === null ? null : (int) $x['row']->telenow_period_calls,
            'kitPeriodCalls' => $x['row']->kit_period_calls === null ? null : (int) $x['row']->kit_period_calls,
            'unchargedCalls' => $x['row']->uncharged_calls === null ? null : (int) $x['row']->uncharged_calls,
            'checkedAt' => $this->iso($x['row']->checked_at),
            'error' => $this->cut($x['row']->error),
        ], $rows);
    }

    private function iso(mixed $at): ?string
    {
        return $at === null ? null : CarbonImmutable::parse($at)->toIso8601String();
    }

    private function cut(?string $text): ?string
    {
        return $text === null ? null : Str::limit($text, config('kit.health.error_length'));
    }
}
