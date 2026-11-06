<?php

namespace App\Kit\Campaigns\Telenow;

/** `cancelCampaign` at Telenow, then the campaign as it is now (CampaignActionAtTelenow). */
final class CancelCampaignAtTelenow extends CampaignActionAtTelenow
{
    protected function action(): string
    {
        return 'cancel';
    }
}
