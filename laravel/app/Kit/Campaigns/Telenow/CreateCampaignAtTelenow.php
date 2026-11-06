<?php

namespace App\Kit\Campaigns\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Makes the campaign at Telenow as a draft, with the customer's own key: the manifest's fields, then its agent and caller
 * id by Telenow's ids (it waits until both are made there) and voicemail handling as Telenow names it.
 */
final class CreateCampaignAtTelenow extends CampaignWrite
{
    public function send(OutboxEntry $entry): void
    {
        $campaign = $this->campaign($entry);
        [$key, $org] = self::account($campaign->workspace);
        if (! is_string($campaign->agent?->telenow_id) || ($campaign->phone_number_id !== null && ! is_string($campaign->callerId?->telenow_id))) {
            throw new TelenowError(0, __('campaigns.agent_not_ready'));
        }
        $body = array_filter(($entry->body ?? []) + [
            'agentId' => $campaign->agent->telenow_id,
            'machineDetection' => $campaign->machine_detection,
            'fromNumberId' => $campaign->callerId?->telenow_id,
        ], fn ($v) => $v !== null);
        $this->synced($campaign, $this->manifest->object(self::RESOURCE, 'create', $this->telenow->createCampaign($key, $org, $body)));
    }
}
