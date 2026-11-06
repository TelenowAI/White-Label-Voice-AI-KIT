<?php

namespace App\Console\Commands;

use App\Kit\Consent\ConsentLedger;
use App\Kit\Consent\ConsentRule;
use Illuminate\Console\Command;

/**
 * `php artisan kit:consent`: tells accounts of a change to whether their calls need consent (App\Kit\Consent\ConsentRule::sweep), a new customer's too, and tries again what was given up (ConsentLedger::retryGivenUp). Scheduled every minute.
 */
final class RunConsent extends Command
{
    protected $signature = 'kit:consent';

    protected $description = "Keep whether customers' calls need consent current at the voice platform";

    public function handle(ConsentRule $rule, ConsentLedger $ledger): int
    {
        $told = $rule->sweep();
        $retried = $ledger->retryGivenUp();
        $this->info("{$told} account(s) told, {$retried} write(s) tried again");

        return self::SUCCESS;
    }
}
