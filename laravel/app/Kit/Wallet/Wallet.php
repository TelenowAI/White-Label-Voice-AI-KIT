<?php

namespace App\Kit\Wallet;

use App\Models\Call;
use App\Models\TelenowFee;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Each customer's wallet (SELF_HOSTED_KIT_PLAN.md §4.6 b, wallet first): credits (the operator's, by hand), charges
 * (one per call, CallCharges; one per number fee, FeeCharges) and a payment's entries (App\Kit\Payments\Payments). Every entry is written with
 * the balance after it, in one transaction with the account's row locked, and happens once: a second entry with the same reference is the first one
 * (a credit form sent twice, a call charged twice). Money is exact decimal strings; a balance can go below zero by what
 * calls cost after the stop (Telenow's stop is not instant).
 *
 * After each entry the customer's spend limit at Telenow is brought up to date (SpendLimit).
 * Each charge is added to the day's totals (ChargeRollups) in the same transaction.
 * Each entry also decides, in that transaction, whether the balance emails go (BalanceAlerts), and sends them after it.
 *
 * Not here: what a call costs (CallCharges, App\Kit\Pricing).
 */
final class Wallet
{
    public function __construct(private readonly SpendLimit $spendLimit, private readonly ChargeRollups $rollups, private readonly BalanceAlerts $alerts) {}

    /** Credit the customer. `$reference` makes it happen once. */
    public function credit(Workspace $workspace, string $amount, string $currency, ?string $note, ?User $by, string $reference): WalletEntry
    {
        return $this->record($workspace, WalletEntry::CREDIT, $reference, BigDecimal::of($amount), $currency, null, $note, $by?->id);
    }

    /**
     * Charge the customer for a call: the engine's answer for it, beside what Telenow charged the operator (kept in the
     * entry's details for the admin's cost-versus-price view, never shown to the customer).
     */
    public function charge(Workspace $workspace, Call $call, array $priced, array $telenowCharge): WalletEntry
    {
        return $this->record($workspace, WalletEntry::CHARGE, $call->id, BigDecimal::of($priced['total'])->negated(), $priced['currency'], [
            'call' => $call->id,
            'plan' => $priced['plan'],
            'usdRate' => $priced['usdRate'],
            'lines' => $priced['lines'],
            'durationSec' => $call->duration_sec,
            'telenow' => [
                'totalUsd' => $telenowCharge['totalChargeUsd'] ?? null,
                'callType' => $telenowCharge['callType'] ?? null,
                'ratedAt' => $telenowCharge['ratedAt'] ?? null,
            ],
        ], null, null);
    }

    /** Charge the customer for a number fee (FeeCharges): the engine's answer for it, beside what Telenow charged. */
    public function chargeFee(Workspace $workspace, TelenowFee $fee, array $priced): WalletEntry
    {
        return $this->record($workspace, WalletEntry::CHARGE, $fee->id, BigDecimal::of($priced['total'])->negated(), $priced['currency'], [
            'fee' => ['refKind' => $fee->ref_kind, 'number' => $fee->ref_id, 'note' => $fee->entry['note'] ?? null],
            'plan' => $priced['plan'],
            'usdRate' => $priced['usdRate'],
            'lines' => $priced['lines'],
            // Telenow's ledger writes a charge negative: kept as the cost it is, like a call's.
            'telenow' => ['totalUsd' => (string) BigDecimal::of($fee->amount_usd)->negated(), 'refKind' => $fee->ref_kind, 'createdAt' => $fee->entry['createdAt'] ?? null],
        ], null, null);
    }

    /**
     * A payment's own entry (App\Kit\Payments\Payments): its top-up or a disputed amount given back (a credit or
     * `release`, positive), a refund or a disputed amount held (`refund`, `hold`, negative). `$reference` makes it
     * happen once; `wasRecentlyCreated` on the answer says whether this call wrote it.
     */
    public function forPayment(Workspace $workspace, string $kind, BigDecimal $amount, string $currency, string $reference, array $details, ?User $by): WalletEntry
    {
        return $this->record($workspace, $kind, $reference, $amount, $currency, $details, null, $by?->id);
    }

    private function record(Workspace $workspace, string $kind, string $reference, BigDecimal $amount, string $currency, ?array $details, ?string $note, ?int $by): WalletEntry
    {
        [$entry, $created, $alert] = DB::transaction(function () use ($workspace, $kind, $reference, $amount, $currency, $details, $note, $by) {
            $locked = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $existing = WalletEntry::query()->where('workspace_id', $locked->id)->where('kind', $kind)->where('reference', $reference)->first();
            if ($existing !== null) {
                return [$existing, false, null];
            }
            $balance = BigDecimal::of($locked->balance)->plus($amount);
            $entry = new WalletEntry;
            $entry->forceFill([
                'workspace_id' => $locked->id, 'kind' => $kind, 'reference' => $reference, 'amount' => (string) $amount,
                'currency' => $currency, 'balance_after' => (string) $balance, 'details' => $details, 'note' => $note, 'created_by' => $by,
            ])->save();
            $alert = $this->alerts->decide($locked, $balance, $amount->isNegative());
            $locked->forceFill(['balance' => (string) $balance])->save();
            $this->rollups->add($entry);

            return [$entry, true, $alert];
        });
        $workspace->refresh();
        if ($created) {
            $this->spendLimit->request($workspace);
        }
        if ($alert !== null) {
            $this->alerts->send($workspace, $alert, $entry->balance_after);
        }

        return $entry;
    }
}
