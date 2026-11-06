<?php

namespace App\Kit\Wallet;

use App\Kit\Pricing\Pricing;
use App\Kit\Pricing\PricingRefused;
use App\Kit\Pricing\TelenowItems;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\Workspace;

/**
 * Charging each call (SELF_HOSTED_KIT_PLAN.md §5 C5): once the kit's copy of a call is final (App\Kit\Calls), its cost is
 * read from Telenow with the customer's own key (`getCallCost`, null until Telenow has rated it — minutes after the
 * call), priced on the customer's plan (App\Kit\Pricing) and charged to its wallet, once.
 *
 * A call's `charge_state`: `pending` (waiting for Telenow's rating), `charged`, `free` (it came to nothing), `unrated`
 * (Telenow never rated it: an unanswered call, after config('kit.wallet.charge_max_reads') reads; or its customer is closed
 * and its key forgotten, which App\Kit\Customers\Settling waits to avoid), `refused` (the plan
 * cannot price it — `charge_error` says why; saving a price list tries them again), `not_charged` (it ended while
 * pricing was not in force).
 *
 * Not here: the wallet entry (Wallet), the arithmetic (PricingEngine).
 */
final class CallCharges
{
    public function __construct(private readonly TelenowClient $telenow, private readonly Pricing $pricing, private readonly Wallet $wallet) {}

    /** A call read as far as it will be: charge it, if pricing is in force. */
    public function queue(Call $call): void
    {
        if ($call->charge_state !== null) {
            return;
        }
        $call->forceFill($this->pricing->inForce()
            ? ['charge_state' => 'pending', 'charge_reads' => 0, 'charge_next_at' => now()]
            : ['charge_state' => 'not_charged'])->save();
    }

    /** Charge the calls that are due, up to config('kit.wallet.charges_per_run'). Answers how many were charged. */
    public function chargeDue(): int
    {
        if (! $this->pricing->inForce()) {
            return 0;
        }
        $charged = 0;
        $due = Call::query()->with('workspace')->where('charge_state', 'pending')->where('charge_next_at', '<=', now())
            ->orderBy('charge_next_at')->limit(config('kit.wallet.charges_per_run'))->get();
        foreach ($due as $call) {
            try {
                $charged += (int) $this->charge($call);
            } catch (TelenowError) {
                $this->later($call);
            }
        }

        return $charged;
    }

    /** After a price list changed: the calls it could not price are tried again. */
    public function retryRefused(): int
    {
        return Call::query()->where('charge_state', 'refused')
            ->update(['charge_state' => 'pending', 'charge_reads' => 0, 'charge_next_at' => now(), 'charge_error' => null]);
    }

    private function charge(Call $call): bool
    {
        /** @var Workspace $workspace */
        $workspace = $call->workspace;
        if (! is_string($workspace->telenow_key)) {
            // Its customer is closed and its key forgotten: this cost can never be read.
            $call->forceFill(['charge_state' => 'unrated', 'charge_next_at' => null])->save();

            return false;
        }
        $cost = $this->telenow->callCost($workspace->telenow_key, $workspace->telenow_sub_account_id, $call->telenow_id);
        if ($cost === null) {
            $this->later($call);

            return false;
        }
        try {
            $item = TelenowItems::call($cost, ['direction' => $call->direction, 'channel' => $call->channel, 'duration_sec' => $call->duration_sec]);
            $priced = $this->pricing->price($workspace, $item);
        } catch (PricingRefused $e) {
            $call->forceFill(['charge_state' => 'refused', 'charge_error' => $e->getMessage(), 'charge_next_at' => null])->save();

            return false;
        }
        if ($priced['lines'] === [] || preg_match('/^-?0(\.0+)?$/', $priced['total'])) {
            $call->forceFill(['charge_state' => 'free', 'charge_amount' => $priced['total'], 'charge_currency' => $priced['currency'], 'charge_next_at' => null])->save();

            return false;
        }
        $this->wallet->charge($workspace, $call, $priced, $cost);
        $call->forceFill([
            'charge_state' => 'charged', 'charge_amount' => $priced['total'], 'charge_currency' => $priced['currency'],
            'charge_error' => null, 'charge_next_at' => null,
        ])->save();

        return true;
    }

    /** Not rated yet (or Telenow not reachable): read again later, up to charge_max_reads times. */
    private function later(Call $call): void
    {
        $reads = $call->charge_reads + 1;
        $call->forceFill($reads >= config('kit.wallet.charge_max_reads')
            ? ['charge_state' => 'unrated', 'charge_reads' => $reads, 'charge_next_at' => null]
            : ['charge_reads' => $reads, 'charge_next_at' => now()->addSeconds((int) min(
                config('kit.wallet.charge_base_seconds') * (2 ** ($reads - 1)), config('kit.wallet.charge_max_seconds')))])->save();
    }
}
