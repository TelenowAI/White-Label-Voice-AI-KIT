<?php

namespace App\Console\Commands;

use App\Kit\Wallet\ChargeRollups;
use Illuminate\Console\Command;

/**
 * `php artisan kit:rollups-rebuild`: write the daily charge totals (App\Kit\Wallet\ChargeRollups) again from the
 * ledger — for an install that charged before the totals existed, or after restoring the ledger from a backup. Run by
 * hand; the totals otherwise keep themselves current with each charge.
 */
final class RebuildRollups extends Command
{
    protected $signature = 'kit:rollups-rebuild';

    protected $description = 'Write the daily charge totals again from the ledger';

    public function handle(ChargeRollups $rollups): int
    {
        $this->info(__('reports.rebuilt', ['count' => $rollups->rebuild()]));

        return self::SUCCESS;
    }
}
