<?php

namespace App\Console\Commands;

use App\Kit\Pricing\Pricing;
use App\Kit\Wallet\CallCharges;
use App\Kit\Wallet\FeeCharges;
use App\Kit\Wallet\SpendLimit;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * `php artisan kit:wallet`: charge the calls whose cost Telenow has rated (App\Kit\Wallet\CallCharges) and the number
 * fees `kit:numbers` brought from the ledger (FeeCharges), then bring up
 * to date the spend limit of each customer whose limit is older than config('kit.wallet.limit_refresh_minutes') — or
 * never set (a new customer, or pricing just put in force) — at most config('kit.calls.feed_accounts_per_run') a run.
 * Scheduled every minute (routes/console.php). Does nothing while pricing is not in force.
 */
final class RunWallet extends Command
{
    protected $signature = 'kit:wallet';

    protected $description = "Charge rated calls, and keep customers' spend limits current";

    public function handle(CallCharges $charges, FeeCharges $fees, SpendLimit $spendLimit, Pricing $pricing): int
    {
        if (! $pricing->inForce()) {
            $this->info('pricing is not set up: nothing to charge');

            return self::SUCCESS;
        }
        $charged = $charges->chargeDue();
        $feesCharged = $fees->chargeDue();
        // A closing or closed customer has no limit to keep: left in, it would hold the first places of every run.
        $stale = Workspace::query()->whereNotNull('telenow_sub_account_id')->whereNotIn('status', Workspace::ENDED)
            ->where(fn ($q) => $q->whereNull('spend_limit_at')->orWhere('spend_limit_at', '<=', now()->subMinutes(config('kit.wallet.limit_refresh_minutes'))))
            ->orderByRaw('spend_limit_at IS NOT NULL')->orderBy('spend_limit_at')
            ->limit(config('kit.calls.feed_accounts_per_run'))->get();
        foreach ($stale as $workspace) {
            $spendLimit->request($workspace);
        }
        $this->info("{$charged} call(s) and {$feesCharged} number fee(s) charged, {$stale->count()} spend limit(s) brought up to date");

        return self::SUCCESS;
    }
}
