<?php

namespace App\Kit\Campaigns\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Models\CampaignTarget;

/**
 * Sends the campaign's people not yet sent, config('kit.campaigns.push_batch') at a time, each with the kit's own id (it
 * comes back on the call's result; a duplicate id is skipped at Telenow, so a batch sent again is harmless). What Telenow
 * counted — added, already there, on the do-not-call list, without a number — adds up in the campaign's `push_summary`.
 */
final class PushTargetsAtTelenow extends CampaignWrite
{
    public function send(OutboxEntry $entry): void
    {
        $campaign = $this->campaign($entry);
        [$key, , $id] = $this->where($campaign);
        while (($batch = CampaignTarget::query()->where('campaign_id', $campaign->id)->whereNull('pushed_at')
            ->orderBy('created_at')->orderBy('id')->limit(config('kit.campaigns.push_batch'))->get())->isNotEmpty()) {
            $answer = $this->telenow->pushCampaignTargets($key, $id, ['source' => config('kit.campaigns.source'), 'targets' => $batch->map(fn (CampaignTarget $t) => array_filter([
                'id' => $t->id, 'phoneNumber' => $t->phone_number, 'variables' => $t->variables ?: null,
            ], fn ($v) => $v !== null))->values()->all()]);
            $summary = (array) ($campaign->push_summary ?? []);
            foreach (['added', 'duplicates', 'suppressed', 'skipped'] as $count) {
                $summary[$count] = ($summary[$count] ?? 0) + (int) ($answer[$count] ?? 0);
            }
            CampaignTarget::query()->whereIn('id', $batch->pluck('id'))->update(['pushed_at' => now()]);
            $campaign->forceFill(['push_summary' => $summary])->save();
        }
        $campaign->forceFill(['sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
