<?php

namespace App\Console\Commands;

use App\Kit\Outbox\Outbox;
use Illuminate\Console\Command;

/**
 * `php artisan kit:outbox`: send the Telenow writes that are due (App\Kit\Outbox). Scheduled every minute
 * (routes/console.php) — on shared hosting through the one cron line `php artisan schedule:run`.
 */
final class RunOutbox extends Command
{
    protected $signature = 'kit:outbox';

    protected $description = 'Send the Telenow writes that are due';

    public function handle(Outbox $outbox): int
    {
        $this->info("{$outbox->runDue()} write(s) done");

        return self::SUCCESS;
    }
}
