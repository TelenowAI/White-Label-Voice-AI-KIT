<?php

namespace App\Kit\Campaigns\Telenow;

/** `pauseCampaign` at Telenow, then the campaign as it is now (CampaignActionAtTelenow). */
final class PauseCampaignAtTelenow extends CampaignActionAtTelenow
{
    protected function action(): string
    {
        return 'pause';
    }
}
