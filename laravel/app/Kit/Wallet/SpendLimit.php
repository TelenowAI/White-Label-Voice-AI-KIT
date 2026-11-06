<?php

namespace App\Kit\Wallet;

use App\Kit\Customers\Telenow\SetSpendLimitAtTelenow;
use App\Kit\Outbox\Outbox;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Pricing\Pricing;
use App\Kit\Settings\Settings;
use App\Models\PriceList;
use App\Models\WalletEntry;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The hard stop (SELF_HOSTED_KIT_PLAN.md §5 C5): Telenow refuses a sub-account's new calls once its lifetime charges
 * reach `limits.totalSpendLimitUsd`, and ends its live ones on its spend guard's next tick. The kit sets that limit to
 *
 *     Telenow's lifetime total for the sub-account + max(0, the balance in Telenow cost − room for calls under way)
 *
 * - The balance in Telenow cost: the balance ÷ what the customer has recently paid per USD of Telenow's charge (its last
 *   config('kit.wallet.ratio_calls') charges). With no charge yet, the plan's highest markup stands in (the cautious
 *   choice: a higher ratio allows less), else the rate alone.
 * - The room: its concurrency (its own limit, else config('kit.wallet.assumed_concurrency')) × its recent Telenow cost
 *   per minute × config('kit.wallet.overshoot_seconds') — what calls can still cost before the stop bites.
 * - A balance at or below zero stops it at once (the limit is the total).
 * So a customer can end slightly below zero, by what its last calls cost after the stop; never by more than its room.
 *
 * request() queues the write (outbox `workspace.limit`, computed when it is sent: Telenow's total moves) unless one is
 * already waiting. Nothing is limited while pricing is not in force (Pricing::inForce).
 */
final class SpendLimit
{
    public function __construct(
        private readonly Outbox $outbox,
        private readonly Pricing $pricing,
        private readonly Settings $settings,
    ) {}

    public function request(Workspace $workspace): void
    {
        if (! $this->pricing->inForce() || $workspace->telenow_sub_account_id === null || in_array($workspace->status, Workspace::ENDED, true)) {
            return;
        }
        $waiting = OutboxEntry::query()->where('resource', SetSpendLimitAtTelenow::RESOURCE)->where('kit_id', $workspace->id)
            ->where('operation', SetSpendLimitAtTelenow::OPERATION)->whereNull('done_at')->whereNull('failed_at')->exists();
        if (! $waiting) {
            $this->outbox->enqueue(SetSpendLimitAtTelenow::RESOURCE, $workspace->id, SetSpendLimitAtTelenow::OPERATION, null);
        }
        $this->outbox->runFor(SetSpendLimitAtTelenow::RESOURCE, $workspace->id);
    }

    /** The limit to set, in USD (6 places, rounded down), given Telenow's lifetime total for the sub-account. */
    public function compute(Workspace $workspace, string $totalUsd, ?int $concurrency): string
    {
        $total = BigDecimal::of($totalUsd);
        $balance = BigDecimal::of($workspace->balance);
        $room = BigDecimal::zero();
        if ($balance->isPositive()) {
            $allowed = $balance->dividedBy($this->ratio($workspace), 10, RoundingMode::Down);
            $inFlight = $this->costPerMinute($workspace)->multipliedBy($concurrency ?? config('kit.wallet.assumed_concurrency'))
                ->multipliedBy(config('kit.wallet.overshoot_seconds'))->dividedBy(60, 10, RoundingMode::Up);
            $room = BigDecimal::max($allowed->minus($inFlight), BigDecimal::zero());
        }

        return (string) $total->plus($room)->toScale(6, RoundingMode::Down);
    }

    /** What the customer pays (its currency) per USD Telenow charges for its calls. Always above zero. */
    public function ratio(Workspace $workspace): BigDecimal
    {
        [$paid, $cost] = [BigDecimal::zero(), BigDecimal::zero()];
        foreach ($this->recentCharges($workspace) as $e) {
            $paid = $paid->plus(BigDecimal::of($e->amount)->negated());
            $cost = $cost->plus(BigDecimal::of($e->details['telenow']['totalUsd']));
        }
        if ($cost->isPositive() && $paid->isPositive()) {
            return $paid->dividedBy($cost, 10, RoundingMode::Up);
        }
        $rate = BigDecimal::of($this->pricing->settings()['usdRate']);
        $markups = [];
        foreach ($this->chain($this->pricing->planOf($workspace)) as $list) {
            foreach ($list['rules'] as $rule) {
                if ($rule['match']['item'] !== 'fee' && isset($rule['price']['markupPercent'])) {
                    $markups[] = BigDecimal::of($rule['price']['markupPercent']);
                }
            }
        }
        $highest = $markups === [] ? BigDecimal::zero() : BigDecimal::max(...$markups);
        $ratio = $rate->multipliedBy(BigDecimal::of(100)->plus($highest))->dividedBy(100, 10, RoundingMode::Up);

        return $ratio->isPositive() ? $ratio : $rate;
    }

    /** The customer's recent Telenow cost per billed minute, in USD. */
    public function costPerMinute(Workspace $workspace): BigDecimal
    {
        [$cost, $seconds] = [BigDecimal::zero(), 0];
        foreach ($this->recentCharges($workspace) as $e) {
            $cost = $cost->plus(BigDecimal::of($e->details['telenow']['totalUsd']));
            $seconds += (int) ($e->details['durationSec'] ?? 0);
        }

        return $seconds > 0 && $cost->isPositive()
            ? $cost->multipliedBy(60)->dividedBy($seconds, 10, RoundingMode::Up)
            : BigDecimal::of(config('kit.wallet.assumed_cost_per_minute_usd'));
    }

    /** @return \Illuminate\Support\Collection<int, WalletEntry> the last call charges that carry Telenow's charge (a number fee has no minutes, and the limit stops calls) */
    private function recentCharges(Workspace $workspace): \Illuminate\Support\Collection
    {
        return WalletEntry::query()->where('workspace_id', $workspace->id)->where('kind', WalletEntry::CHARGE)->whereNull('details->fee')
            ->orderByDesc('id')->limit(config('kit.wallet.ratio_calls'))->get()
            ->filter(fn (WalletEntry $e) => is_string($e->details['telenow']['totalUsd'] ?? null));
    }

    /** @return list<array> the plan's list, then each it extends (the engine checks the chain when it prices) */
    private function chain(?string $plan): array
    {
        $out = [];
        $seen = [];
        while ($plan !== null && ! isset($seen[$plan]) && ($list = PriceList::query()->find($plan)) !== null) {
            $seen[$plan] = true;
            $out[] = $list->definition;
            $plan = $list->definition['extends'] ?? null;
        }

        return $out;
    }
}
