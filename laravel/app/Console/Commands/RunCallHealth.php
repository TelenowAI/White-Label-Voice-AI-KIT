<?php

namespace App\Console\Commands;

use App\Kit\Calls\CallHealth;
use Illuminate\Console\Command;

/**
 * `php artisan kit:call-health`: read the provider failures of every account that had calls lately and add them up by provider, for Admin → Voice
 * platform and the dashboard (App\Kit\Calls\CallHealth::sweep). Scheduled every config('kit.call_health.sweep_minutes'). Nothing to read before a
 * customer has calls.
 */
final class RunCallHealth extends Command
{
    protected $signature = 'kit:call-health';

    protected $description = "Add up the providers that failed on customers' recent calls";

    public function handle(CallHealth $health): int
    {
        $summary = $health->sweep();
        $this->info("{$summary['accountsChecked']} accounts read ({$summary['accountsUnread']} not), ".count($summary['providers']).' providers with failures');

        return self::SUCCESS;
    }
}
