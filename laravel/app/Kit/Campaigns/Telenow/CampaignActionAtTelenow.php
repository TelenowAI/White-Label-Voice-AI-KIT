<?php

namespace App\Kit\Campaigns\Telenow;

use App\Kit\Outbox\OutboxEntry;

/**
 * Starts, pauses or cancels the campaign at Telenow (its `action`), then reads it back: Telenow answers only a message,
 * and leaves a campaign that cannot take the action as it is.
 *
 * Used by StartCampaignAtTelenow, PauseCampaignAtTelenow, CancelCampaignAtTelenow.
 */
abstract class CampaignActionAtTelenow extends CampaignWrite
{
    abstract protected function action(): string;

    public function send(OutboxEntry $entry): void
    {
        $campaign = $this->campaign($entry);
        [$key, $org, $id] = $this->where($campaign);
        $this->telenow->campaignAction($key, $org, $id, $this->action());
        $this->synced($campaign, $this->telenow->campaign($key, $org, $id));
    }
}
