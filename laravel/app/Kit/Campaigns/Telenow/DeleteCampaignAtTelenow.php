<?php

namespace App\Kit\Campaigns\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/** Deletes the campaign at Telenow (a running one is stopped first). One Telenow no longer has (404) is as good as deleted. */
final class DeleteCampaignAtTelenow extends CampaignWrite
{
    public function send(OutboxEntry $entry): void
    {
        $campaign = $this->campaign($entry);
        if ($campaign->telenow_id !== null) {
            [$key, $org] = self::account($campaign->workspace);
            try {
                $this->telenow->deleteCampaign($key, $org, $campaign->telenow_id);
            } catch (TelenowError $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $campaign->forceFill(['deleted_at' => $campaign->deleted_at ?? now(), 'sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
