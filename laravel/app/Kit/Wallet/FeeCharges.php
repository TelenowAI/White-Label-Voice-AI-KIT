<?php

namespace App\Kit\Wallet;

use App\Kit\Pricing\Pricing;
use App\Kit\Pricing\PricingRefused;
use App\Kit\Pricing\TelenowItems;
use App\Kit\Telenow\TelenowClient;
use App\Models\TelenowFee;
use App\Models\Workspace;

/**
 * Number fees (SELF_HOSTED_KIT_PLAN.md §4.6 b-c): Telenow charges the operator a number's setup and every month's rent;
 * read with the customer's own key, its ledger lists the entries that customer caused. feed() keeps the fee entries
 * (config('kit.numbers.fee_kinds'); a call's charge comes with the call, App\Kit\Wallet\CallCharges), and chargeDue()
 * prices each on the customer's plan and charges its wallet once — the same states as a call: `charged`, `free`,
 * `refused` (the plan cannot price it; saving a price list tries it again), `not_charged` (charged by Telenow while
 * pricing was not in force).
 */
final class FeeCharges
{
    public function __construct(private readonly TelenowClient $telenow, private readonly Pricing $pricing, private readonly Wallet $wallet) {}

    /** Read the account's ledger on from where it stopped; keep its number fees. Answers how many fees were new. */
    public function feed(Workspace $workspace): int
    {
        $new = 0;
        for ($page = 0; $page < config('kit.numbers.feed_pages_per_run'); $page++) {
            $position = $workspace->ledger_cursor !== null
                ? ['cursor' => $workspace->ledger_cursor]
                : ['updatedAfter' => $workspace->created_at->utc()->format('Y-m-d\TH:i:s.v\Z')];
            $answer = $this->telenow->ledgerFeed($workspace->telenow_key, $workspace->telenow_sub_account_id, $position);
            $entries = $answer['entries'] ?? [];
            foreach ($entries as $entry) {
                if (($entry['entryType'] ?? null) !== 'charge' || ! in_array($entry['refKind'] ?? null, config('kit.numbers.fee_kinds'), true)
                    || ! is_string($entry['id'] ?? null) || TelenowFee::query()->whereKey($entry['id'])->exists()) {
                    continue;
                }
                $fee = new TelenowFee;
                $fee->forceFill([
                    'id' => $entry['id'], 'workspace_id' => $workspace->id, 'ref_kind' => $entry['refKind'], 'ref_id' => $entry['refId'] ?? null,
                    'amount_usd' => (string) ($entry['amountUsd'] ?? '0'), 'entry' => $entry,
                    'charge_state' => $this->pricing->inForce() ? 'pending' : 'not_charged',
                ])->save();
                $new++;
            }
            if (is_string($answer['nextCursor'] ?? null)) {
                $workspace->forceFill(['ledger_cursor' => $answer['nextCursor']])->save();
            }
            if ($entries === []) {
                break;
            }
        }

        return $new;
    }

    /** Charge the fees that are due. Answers how many were charged. */
    public function chargeDue(): int
    {
        if (! $this->pricing->inForce()) {
            return 0;
        }
        $charged = 0;
        foreach (TelenowFee::query()->with('workspace')->where('charge_state', 'pending')->orderBy('created_at')->get() as $fee) {
            $charged += (int) $this->charge($fee);
        }

        return $charged;
    }

    /** After a price list changed: the fees it could not price are tried again. */
    public function retryRefused(): int
    {
        return TelenowFee::query()->where('charge_state', 'refused')->update(['charge_state' => 'pending', 'charge_error' => null]);
    }

    private function charge(TelenowFee $fee): bool
    {
        try {
            $priced = $this->pricing->price($fee->workspace, TelenowItems::fee($fee->entry));
        } catch (PricingRefused $e) {
            $fee->forceFill(['charge_state' => 'refused', 'charge_error' => $e->getMessage()])->save();

            return false;
        }
        if ($priced['lines'] === [] || preg_match('/^-?0(\.0+)?$/', $priced['total'])) {
            $fee->forceFill(['charge_state' => 'free'])->save();

            return false;
        }
        $this->wallet->chargeFee($fee->workspace, $fee, $priced);
        $fee->forceFill(['charge_state' => 'charged', 'charge_error' => null])->save();

        return true;
    }
}
