<?php

namespace App\Kit\Reports;

use App\Kit\Health\HealthReport;
use App\Kit\Health\JobRuns;
use App\Kit\Pricing\Pricing;
use App\Kit\Settings\Settings;
use App\Models\Call;
use App\Models\Payment;
use App\Models\WalletEntry;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Admin → Dashboard (SELF_HOSTED_KIT_PLAN.md §4.2): over a Period, what customers paid for (the charges, beside what
 * Telenow charged the operator), what they topped up and what staff credited by hand, the customers and the calls;
 * now, the calls live, the operator's own Telenow account (its mode and balance or usage against the cap, whether it
 * may spend and why not, as kit:telenow-status last read it: App\Kit\Console\ServicePause) with its Billing page, and
 * what needs a look (calls not charged, writes Telenow refused, events that failed, payments to review, customers not
 * set up at Telenow or whose money check shows a gap, and scheduled jobs not running: App\Kit\Health).
 *
 * Money is per currency, added exactly. Used by Admin\ReportsController.
 */
final class Dashboard
{
    public function __construct(private readonly Settings $settings, private readonly CostReport $costs, private readonly Pricing $pricing,
        private readonly HealthReport $health, private readonly JobRuns $jobs, private readonly \App\Kit\Calls\CallHealth $callHealth) {}

    public function build(Period $period): array
    {
        [$start, $end] = $period->bounds();
        $money = [];
        $this->costs->read($period, true, function (object $row) use (&$money) {
            ($money[$row->currency] ??= new Totals($row->currency))->add($row);
        });

        $topUps = [];
        Payment::query()->where('status', Payment::PAID)->whereBetween('paid_at', [$start, $end])
            ->select(['id', 'amount', 'received', 'refunded', 'currency'])->lazyById(config('kit.reports.read_chunk'))
            ->each(function (Payment $p) use (&$topUps) {
                $t = $topUps[$p->currency] ??= ['currency' => $p->currency, 'count' => 0, 'amount' => BigDecimal::zero(), 'refunded' => BigDecimal::zero()];
                $t['count']++;
                $t['amount'] = $t['amount']->plus($p->received ?? $p->amount);
                $t['refunded'] = $t['refunded']->plus($p->refunded ?? '0');
                $topUps[$p->currency] = $t;
            });

        $credits = [];
        WalletEntry::query()->where('kind', WalletEntry::CREDIT)->whereNull('details')->whereBetween('created_at', [$start, $end])
            ->select(['id', 'amount', 'currency'])->lazyById(config('kit.reports.read_chunk'))
            ->each(function (WalletEntry $e) use (&$credits) {
                $c = $credits[$e->currency] ??= ['currency' => $e->currency, 'count' => 0, 'amount' => BigDecimal::zero()];
                $c['count']++;
                $c['amount'] = $c['amount']->plus($e->amount);
                $credits[$e->currency] = $c;
            });

        $inForce = $this->pricing->inForce();
        $outOfCredit = 0;
        if ($inForce) {
            Workspace::query()->where('status', Workspace::ACTIVE)->select(['id', 'balance'])->lazyById(config('kit.reports.read_chunk'))
                ->each(function (Workspace $w) use (&$outOfCredit) {
                    $outOfCredit += BigDecimal::of($w->balance ?? '0')->isPositive() ? 0 : 1;
                });
        }

        $calls = Call::query()->whereBetween('started_at', [$start, $end]);
        $account = $this->settings->get('telenow.billing');

        return [
            'period' => $period->toArray(),
            'money' => array_values(array_map(fn (Totals $t) => $t->toArray(), $money)),
            'topUps' => array_values(array_map(fn (array $t) => ['currency' => $t['currency'], 'count' => $t['count'],
                'amount' => (string) $t['amount'], 'refunded' => (string) $t['refunded']], $topUps)),
            'creditsByHand' => array_values(array_map(fn (array $c) => ['currency' => $c['currency'], 'count' => $c['count'], 'amount' => (string) $c['amount']], $credits)),
            'customers' => [
                'total' => Workspace::query()->count(),
                'active' => Workspace::query()->where('status', Workspace::ACTIVE)->count(),
                'suspended' => Workspace::query()->where('status', Workspace::SUSPENDED)->count(),
                'notReady' => Workspace::query()->whereNull('telenow_sub_account_id')->count(),
                'outOfCredit' => $inForce ? $outOfCredit : null,
                'new' => Workspace::query()->whereBetween('created_at', [$start, $end])->count(),
            ],
            'calls' => [
                'count' => (clone $calls)->count(),
                'seconds' => (int) (clone $calls)->sum('duration_sec'),
                'live' => Call::query()->whereNotNull('status')->whereNotIn('status', config('kit.calls.ended_statuses'))
                    ->where('started_at', '>=', now()->subHours(config('kit.reports.live_call_hours')))->count(),
            ],
            'telenow' => [
                'name' => config('kit.telenow.name'),
                'billingUrl' => config('kit.telenow.billing_url'),
                'connected' => is_string($this->settings->get('telenow.api_key')),
                'keyRejected' => (bool) $this->settings->get('telenow.key_rejected', false),
                'error' => $this->settings->get('telenow.status_error'),
                'checkedAt' => $this->settings->get('telenow.status_checked_at'),
                'account' => is_array($account) ? array_intersect_key($account, array_flip(config('kit.telenow.spend_state_fields'))) : null,
            ],
            'attention' => [
                'callsNotCharged' => Call::query()->whereIn('charge_state', ['refused', 'unrated'])->count(),
                'failedWrites' => DB::table('telenow_outbox')->whereNotNull('failed_at')->count(),
                'paymentsToReview' => Payment::query()->where('status', Payment::REVIEW)->count(),
                'customersNotSynced' => Workspace::query()->where('sync_state', Workspace::SYNC_FAILED)->count(),
                'eventsFailed' => DB::table('telenow_events')->whereNull('applied_at')->whereNotNull('failed_at')->count(),
                'customersDrifting' => count($this->health->drifting()),
                'jobsNotRunning' => ! $this->jobs->running(),
                // Providers that failed on config('kit.call_health.alert_calls') or more of customers' recent calls (App\Kit\Calls\CallHealth).
                'providersFailing' => $this->callHealth->failing(),
            ],
        ];
    }
}
