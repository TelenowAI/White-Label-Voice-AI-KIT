<?php

namespace App\Console\Commands;

use App\Kit\Campaigns\CampaignSources;
use Illuminate\Console\Command;

/**
 * kit:campaign-links — every minute: each campaign link that is due read again (App\Kit\Campaigns\CampaignSources::due), adding the new numbers.
 * Nothing while the operator has links turned off.
 */
final class RunCampaignLinks extends Command
{
    protected $signature = 'kit:campaign-links';

    protected $description = "Read campaigns' contact links again and add new people";

    public function handle(CampaignSources $sources): int
    {
        $read = $sources->due();
        if ($read > 0) {
            $this->info("read {$read} link(s)");
        }

        return self::SUCCESS;
    }
}
