<?php

namespace App\Console\Commands;

use App\Kit\Numbers\NumberRenewals;
use App\Kit\Numbers\Numbers;
use App\Kit\Numbers\NumbersRefused;
use App\Kit\Telenow\TelenowError;
use App\Kit\Wallet\FeeCharges;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * `php artisan kit:numbers`: for each account not read for config('kit.numbers.feed_minutes') (or just after a purchase),
 * the longest-waiting first, up to config('kit.numbers.feed_accounts_per_run') a run: the numbers Telenow changed
 * (App\Kit\Numbers\Numbers::feed) and the number fees in its ledger (App\Kit\Wallet\FeeCharges::feed; `kit:wallet`
 * charges them); then the email for numbers whose next rent its balance will not cover (App\Kit\Numbers\NumberRenewals::warn).
 * Scheduled every minute.
 */
final class RunNumbers extends Command
{
    protected $signature = 'kit:numbers';

    protected $description = "Keep customers' numbers and number fees current";

    public function handle(Numbers $numbers, FeeCharges $fees, NumberRenewals $renewals): int
    {
        [$seen, $new, $warned] = [0, 0, 0];
        $accounts = Workspace::query()->whereNotNull('telenow_sub_account_id')->whereNotNull('telenow_key')
            ->where(fn ($q) => $q->whereNull('numbers_synced_at')->orWhere('numbers_synced_at', '<=', now()->subMinutes(config('kit.numbers.feed_minutes'))))
            ->orderByRaw('numbers_synced_at IS NOT NULL')->orderBy('numbers_synced_at')
            ->limit(config('kit.numbers.feed_accounts_per_run'))->get();
        foreach ($accounts as $workspace) {
            try {
                $seen += $numbers->feed($workspace);
                $new += $fees->feed($workspace);
                $workspace->forceFill(['numbers_synced_at' => now()])->save();
                $warned += $renewals->warn($workspace->fresh());
            } catch (TelenowError|NumbersRefused $e) {
                $this->warn("{$workspace->id}: {$e->getMessage()}");
            }
        }
        $this->info("{$seen} number change(s), {$new} new fee(s), {$warned} renewal(s) warned of in {$accounts->count()} account(s)");

        return self::SUCCESS;
    }
}
