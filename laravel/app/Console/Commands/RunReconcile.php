<?php

namespace App\Console\Commands;

use App\Kit\Health\Reconciliation;
use Illuminate\Console\Command;

/**
 * `php artisan kit:reconcile`: the money check for the customers checked longest ago (App\Kit\Health\Reconciliation),
 * at most config('kit.reconcile.accounts_per_run'), each again after config('kit.reconcile.every_minutes').
 * Scheduled every five minutes (routes/console.php).
 */
final class RunReconcile extends Command
{
    protected $signature = 'kit:reconcile';

    protected $description = "Compare each customer's spend at the voice platform with what the kit charged";

    public function handle(Reconciliation $reconciliation): int
    {
        $this->info(__('health.reconciled', ['count' => $reconciliation->due()]));

        return self::SUCCESS;
    }
}
