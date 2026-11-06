<?php

namespace App\Kit\Campaigns\Telenow;

use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Campaign;
use App\Models\Workspace;

/**
 * What every campaign write to Telenow shares: the campaign, the customer's own key and sub-account, Telenow's campaign
 * mapped back by the manifest, and how the outcome shows — `pending` with the kit's "will retry" words while it is tried
 * again, `failed` with the kit's words once given up, `synced` with Telenow's campaign.
 *
 * Used by CreateCampaignAtTelenow, PushTargetsAtTelenow, Start/Pause/Cancel/DeleteCampaignAtTelenow.
 */
abstract class CampaignWrite implements OutboxHandler
{
    public const RESOURCE = 'campaigns';

    public function __construct(protected readonly TelenowClient $telenow, protected readonly Manifest $manifest) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->campaign($entry)->forceFill(['sync_state' => 'pending', 'sync_error' => __('campaigns.will_retry')])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->campaign($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => __('campaigns.not_done')])->save();
    }

    protected function campaign(OutboxEntry $entry): Campaign
    {
        return Campaign::query()->with(['workspace', 'agent', 'callerId'])->findOrFail($entry->kit_id);
    }

    /** @return array{0: string, 1: string} the customer's key and sub-account */
    public static function account(Workspace $w): array
    {
        if (! is_string($w->telenow_key) || $w->telenow_key === '' || ! is_string($w->telenow_sub_account_id)) {
            throw new TelenowError(0, __('campaigns.account_not_ready'));
        }

        return [$w->telenow_key, $w->telenow_sub_account_id];
    }

    /** @return array{0: string, 1: string, 2: string} the key, sub-account and Telenow's campaign (waits until it is made) */
    protected function where(Campaign $campaign): array
    {
        [$key, $org] = self::account($campaign->workspace);
        if (! is_string($campaign->telenow_id)) {
            throw new TelenowError(0, __('campaigns.not_ready'));
        }

        return [$key, $org, $campaign->telenow_id];
    }

    /** Telenow's campaign onto the row: its fields by the manifest. */
    public function synced(Campaign $campaign, array $object): void
    {
        $campaign->forceFill($this->manifest->columns(self::RESOURCE, $object) + [
            'telenow_id' => $object['id'] ?? $campaign->telenow_id, 'read_at' => now(), 'sync_state' => 'synced', 'sync_error' => null,
        ])->save();
    }
}
