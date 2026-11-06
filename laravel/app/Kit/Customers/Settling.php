<?php

namespace App\Kit\Customers;

use App\Kit\Calls\Calls;
use App\Kit\Pricing\Pricing;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Kit\Wallet\FeeCharges;
use App\Models\Call;
use App\Models\PhoneNumber;
use App\Models\TelenowFee;
use App\Models\Workspace;

/**
 * What must be in before a closing customer reaches Telenow's close (Telenow\CloseAtTelenow). That close revokes the
 * customer's key for good, and every call, every call's cost and every number fee is read with that key; Telenow bills the
 * operator for them either way. A suspended sub-account's key still works (recorded, contract_k1close.py), so the kit keeps
 * reading until all of it is in, in this order:
 * - its numbers are released (their rent would go on);
 * - config('kit.customers.close_settle_seconds') have passed since closing was asked, for a call's event still landing;
 * - Telenow counts no live call (the suspend ended them);
 * - the calls feed and the ledger have nothing new, and no call or fee is left to read or charge. Those waits are bounded:
 *   a call runs out of reads (config('kit.calls.max_reads')) and a cost Telenow never rates runs out too
 *   (config('kit.wallet.charge_max_reads')), so the longest wait is a few hours.
 * A close that already landed (Telenow says closed), or a key Telenow already refuses, has nothing left to wait for.
 *
 * Not here: sending the close, what happens after it (CloseAtTelenow); running the reads and charges (the kit:* jobs).
 */
final class Settling
{
    public function __construct(
        private readonly TelenowClient $telenow,
        private readonly Calls $calls,
        private readonly FeeCharges $fees,
        private readonly Pricing $pricing,
    ) {}

    /** Why this closing customer cannot be closed at Telenow yet, in words for staff; null when it can. */
    public function waitingFor(Workspace $workspace, string $operatorKey): ?string
    {
        $held = PhoneNumber::query()->where('workspace_id', $workspace->id)
            ->where(fn ($q) => $q->whereNull('released_at')->orWhere('sync_state', '!=', 'synced'))->count();
        if ($held > 0) {
            return __('customers.numbers_releasing', ['count' => $held]);
        }
        $usage = $this->telenow->subAccountUsage($operatorKey, $workspace->telenow_sub_account_id);
        if (($usage['status'] ?? null) === 'closed') {
            return null;
        }
        if ($workspace->close_requested_at?->gt(now()->subSeconds((int) config('kit.customers.close_settle_seconds')))) {
            return __('customers.calls_landing');
        }
        $live = (int) ($usage['activeCalls'] ?? 0);
        if ($live > 0) {
            return __('customers.calls_ending', ['count' => $live]);
        }
        try {
            $seen = $this->calls->feed($workspace) + $this->fees->feed($workspace);
        } catch (TelenowError $e) {
            if ($e->status === 401) {
                return null;
            }
            throw $e;
        }

        return $seen > 0 || $this->open($workspace) ? __('customers.calls_settling') : null;
    }

    /** Whether a call or number fee of this customer is still to be read or charged. */
    private function open(Workspace $workspace): bool
    {
        $reading = Call::query()->where('workspace_id', $workspace->id)->where('detail_state', 'pending')->exists();
        if ($reading || ! $this->pricing->inForce()) {
            return $reading;
        }

        return Call::query()->where('workspace_id', $workspace->id)->where('charge_state', 'pending')->exists()
            || TelenowFee::query()->where('workspace_id', $workspace->id)->where('charge_state', 'pending')->exists();
    }
}
