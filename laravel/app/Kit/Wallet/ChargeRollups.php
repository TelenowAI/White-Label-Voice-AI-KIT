<?php

namespace App\Kit\Wallet;

use App\Models\WalletEntry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Each day's charges added up per customer, plan, currency and subject (the `charge_rollups` table): the charge as a
 * whole (subject '') and each of its priced lines (`call`, `component:<name>`, `fee:<kind>`), with how many, the
 * customer's price, what Telenow charged the operator (USD) and that cost in the price's currency at the charge's own
 * rate. Wallet adds a charge here in the same transaction that writes it, so the totals never drift from the ledger;
 * `kit:rollups-rebuild` writes them again from the ledger.
 *
 * Read by App\Kit\Reports (the admin dashboard, Cost vs price). Not here: charging (CallCharges, FeeCharges, Wallet).
 */
final class ChargeRollups
{
    /** Decimal places kept for a cost converted at a charge's rate. */
    private const COST_SCALE = 10;

    /** Add one charge entry (inside the caller's transaction). Anything but a charge is ignored. */
    public function add(WalletEntry $entry): void
    {
        $details = $entry->details;
        if ($entry->kind !== WalletEntry::CHARGE || ! is_array($details)) {
            return;
        }
        $day = $entry->created_at->copy()->setTimezone(config('app.timezone'))->toDateString();
        $plan = (string) ($details['plan'] ?? '');
        $rate = BigDecimal::of((string) ($details['usdRate'] ?? '0'));
        $costUsd = (string) ($details['telenow']['totalUsd'] ?? '0');
        $this->bump($day, $entry->workspace_id, $plan, '', $entry->currency, (string) BigDecimal::of($entry->amount)->negated(), $costUsd, $rate);
        foreach ($details['lines'] ?? [] as $line) {
            $this->bump($day, $entry->workspace_id, $plan, (string) $line['subject'], $entry->currency, (string) $line['amount'],
                (string) ($line['costUsd'] ?? '0'), $rate);
        }
    }

    /** Write every total again from the ledger: for an install whose totals were lost, or that predates them. */
    public function rebuild(): int
    {
        $count = 0;
        DB::transaction(function () use (&$count) {
            DB::table('charge_rollups')->delete();
            WalletEntry::query()->where('kind', WalletEntry::CHARGE)->orderBy('id')->chunkById(config('kit.reports.rebuild_chunk'), function ($entries) use (&$count) {
                foreach ($entries as $entry) {
                    $this->add($entry);
                    $count++;
                }
            });
        });

        return $count;
    }

    private function bump(string $day, string $workspaceId, string $plan, string $subject, string $currency, string $price, string $costUsd, BigDecimal $rate): void
    {
        $key = ['day' => $day, 'workspace_id' => $workspaceId, 'plan' => $plan, 'subject' => $subject, 'currency' => $currency];
        DB::table('charge_rollups')->insertOrIgnore($key + ['created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('charge_rollups')->where($key)->lockForUpdate()->first();
        DB::table('charge_rollups')->where('id', $row->id)->update([
            'count' => $row->count + 1,
            'price' => (string) BigDecimal::of($row->price)->plus($price),
            'cost_usd' => (string) BigDecimal::of($row->cost_usd)->plus($costUsd),
            'cost' => (string) BigDecimal::of($row->cost)->plus(BigDecimal::of($costUsd)->multipliedBy($rate)->toScale(self::COST_SCALE, RoundingMode::HalfUp)),
            'updated_at' => now(),
        ]);
    }
}
