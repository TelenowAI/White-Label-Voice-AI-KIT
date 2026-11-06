<?php

namespace App\Kit\Campaigns\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Sends a campaign's settings as the kit holds them when the write's turn comes, with the customer's own key: the manifest's fields, voicemail handling as Telenow names it and the caller id by Telenow's id
 * (it waits until that is made there), then how many calls at once, which has its own operation. Telenow replaces the window each time and reads the rest as sent, so a try after a lost answer changes nothing further.
 */
final class UpdateCampaignAtTelenow extends CampaignWrite
{
    public function send(OutboxEntry $entry): void
    {
        $campaign = $this->campaign($entry);
        [$key, $org, $id] = $this->where($campaign);
        if ($campaign->phone_number_id !== null && ! is_string($campaign->callerId?->telenow_id)) {
            throw new TelenowError(0, __('campaigns.agent_not_ready'));
        }
        $body = $this->manifest->writeBody(self::RESOURCE, 'update', $campaign->only(['start_time_local', 'end_time_local', 'timezone', 'max_attempts', 'retry_on_no_answer', 'retry_backoff_secs'])) + [
            'machineDetection' => $campaign->machine_detection,
            'fromNumberId' => $campaign->callerId?->telenow_id,
        ];
        $this->telenow->setCampaignSchedule($key, $org, $id, $body);
        $this->synced($campaign, $this->manifest->object(self::RESOURCE, 'update', $this->telenow->setCampaignConcurrency($key, $org, $id, (int) $campaign->concurrency)));
    }
}
