<?php

namespace App\Kit\Campaigns\Telenow;

/** `startCampaign` at Telenow, then the campaign as it is now (CampaignActionAtTelenow). */
final class StartCampaignAtTelenow extends CampaignActionAtTelenow
{
    protected function action(): string
    {
        return 'start';
    }
}
