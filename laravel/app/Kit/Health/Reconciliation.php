<?php

namespace App\Kit\Health;

use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The money check between the kit and Telenow, per customer (SELF_HOSTED_KIT_PLAN.md §5 C8), kept in
 * `reconciliations`:
 * - what Telenow says the customer's sub-account has spent in all (`…/usage` totalSpendUsd), beside what the kit has
 *   charged the customer for (Telenow's cost of every charge, from App\Kit\Wallet\ChargeRollups) — the gap is drift;
 * - the calls Telenow counts in its current period, beside the calls the kit holds for the same time;
 * - the customer's calls the kit has not charged (waiting for a rating, never rated, no price for them, or priced at
 *   nothing), which is where most drift comes from until they are charged.
 * A failed read keeps the last figures and says why.
 *
 * `kit:reconcile` runs check() hourly for the customers checked longest ago (config('kit.reconcile')).
 * Read by HealthReport. Not here: fixing drift (re-charging is the wallet's; a missing call is the calls feed's).
 */
final class Reconciliation
{
    public function __construct(private readonly Settings $settings, private readonly TelenowClient $telenow) {}

    /** @return int how many customers were checked */
    public function due(): int
    {
        $limit = config('kit.reconcile.accounts_per_run');
        $ids = Workspace::query()->whereNotNull('telenow_sub_account_id')
            ->leftJoin('reconciliations', 'reconciliations.workspace_id', '=', 'workspaces.id')
            ->where(fn ($q) => $q->whereNull('reconciliations.checked_at')
                ->orWhere('reconciliations.checked_at', '<=', now()->subMinutes(config('kit.reconcile.every_minutes'))))
            ->orderByRaw('reconciliations.checked_at IS NOT NULL')->orderBy('reconciliations.checked_at')
            ->limit($limit)->pluck('workspaces.id');
        foreach (Workspace::query()->whereIn('id', $ids)->get() as $workspace) {
            $this->check($workspace);
        }

        return $ids->count();
    }

    public function check(Workspace $workspace): void
    {
        $key = $this->settings->get('telenow.api_key');
        $row = ['checked_at' => now(), 'updated_at' => now()];
        try {
            if (! is_string($key) || $workspace->telenow_sub_account_id === null) {
                throw new TelenowError(0, __('health.not_connected'));
            }
            $usage = $this->telenow->subAccountUsage($key, $workspace->telenow_sub_account_id);
            $spend = BigDecimal::of((string) ($usage['totalSpendUsd'] ?? '0'));
            $charged = BigDecimal::zero();
            DB::table('charge_rollups')->where('workspace_id', $workspace->id)->where('subject', '')->select(['id', 'cost_usd'])
                ->lazyById(config('kit.reports.read_chunk'))->each(function ($r) use (&$charged) {
                    $charged = $charged->plus($r->cost_usd);
                });
            $from = isset($usage['from']) ? CarbonImmutable::parse($usage['from']) : null;
            $to = isset($usage['to']) ? CarbonImmutable::parse($usage['to']) : now();
            $row += [
                'telenow_spend_usd' => (string) $spend,
                'charged_cost_usd' => (string) $charged,
                'drift_usd' => (string) $spend->minus($charged),
                'period_from' => $from,
                'telenow_period_calls' => isset($usage['calls']) ? (int) $usage['calls'] : null,
                'kit_period_calls' => $from === null ? null
                    : Call::query()->where('workspace_id', $workspace->id)->whereBetween('started_at', [$from, $to])->count(),
                'uncharged_calls' => Call::query()->where('workspace_id', $workspace->id)
                    ->whereIn('charge_state', config('kit.reconcile.uncharged_states'))->count(),
                'error' => null,
            ];
        } catch (TelenowError $e) {
            $row['error'] = $e->getMessage();
        }
        $exists = DB::table('reconciliations')->where('workspace_id', $workspace->id)->exists();
        $exists
            ? DB::table('reconciliations')->where('workspace_id', $workspace->id)->update($row)
            : DB::table('reconciliations')->insert($row + ['workspace_id' => $workspace->id, 'created_at' => now()]);
    }
}
