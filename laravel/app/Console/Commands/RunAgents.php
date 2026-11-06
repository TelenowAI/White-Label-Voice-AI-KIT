<?php

namespace App\Console\Commands;

use App\Kit\Agents\AgentTransfers;
use Illuminate\Console\Command;

/**
 * `php artisan kit:agents`: takes transfer off the agents that have it, after the operator turned transfers off
 * (App\Kit\Agents\AgentTransfers). Scheduled every minute; it reads nothing when there is nothing to do.
 */
final class RunAgents extends Command
{
    protected $signature = 'kit:agents';

    protected $description = "Keep customers' agents within what the operator allows";

    public function handle(AgentTransfers $transfers): int
    {
        $this->info("{$transfers->sweep()} agent(s) changed");

        return self::SUCCESS;
    }
}
