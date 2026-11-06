<?php

namespace App\Kit\Campaigns;

use App\Kit\Campaigns\Telenow\CampaignWrite;
use App\Kit\Console\ServicePause;
use App\Kit\Manifest\Manifest;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Campaign;
use App\Models\CampaignTarget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Calling a campaign's people again when a customer asks: those nobody answered, or everyone whose call did not go through. A finished campaign reopens and calls them, and a paused one stays paused (recorded).
 *
 * It is asked once and answered there and then, not queued: every call costs money and Telenow has no idempotency key here, so a try after a lost answer could call people again whose new call has already failed.
 * If the answer is lost the customer is told to look before trying again.
 *
 * Telenow answers only how many, and takes each number back once. So the kit marks people the same way, one per number, and each waits until a newer result comes for that number, which also ends the wait of another
 * row of the same number if Telenow took that one instead (recorded).
 *
 * Not here: reading the results (Campaigns::refresh), what a person reads as (CampaignPeople).
 */
final class CampaignRetries
{
    public function __construct(private readonly TelenowClient $telenow, private readonly Manifest $manifest, private readonly ServicePause $pause) {}

    /** @return array{retried: int, campaign: Campaign} */
    public function retry(Campaign $campaign, array $input): array
    {
        $scope = Validator::make($input, ['scope' => ['required', Rule::in(config('kit.campaigns.retry_scopes'))]], [], ['scope' => __('campaigns.fields.scope')])->validate()['scope'];
        $paused = $this->pause->for($campaign->workspace);
        if ($paused !== null) {
            throw new CampaignRefused(409, $paused['message']);
        }
        if (! is_string($campaign->telenow_id)) {
            throw new CampaignRefused(409, __('campaigns.not_ready'));
        }
        if ($campaign->status === 'cancelled') {
            throw new CampaignRefused(409, __('campaigns.cancelled_no_change'));
        }
        if ($campaign->sync_state === 'pending') {
            throw new CampaignRefused(409, __('campaigns.wait'));
        }
        [$key, $org] = CampaignWrite::account($campaign->workspace);
        try {
            $retried = (int) ($this->telenow->retryFailedTargets($key, $org, $campaign->telenow_id, $scope)['retried'] ?? 0);
        } catch (TelenowError) {
            throw new CampaignRefused(503, __('campaigns.retry_unavailable'));
        }
        if ($retried > 0) {
            $this->mark($campaign, $scope);
        }
        try {
            $campaign->forceFill($this->manifest->columns(CampaignWrite::RESOURCE, $this->telenow->campaign($key, $org, $campaign->telenow_id)) + ['read_at' => now()])->save();
        } catch (TelenowError) {
            $campaign->forceFill(['read_at' => null])->save(); // It was done: the job reads it next.
        }

        return ['retried' => $retried, 'campaign' => $campaign];
    }

    /** A person called again stops waiting once a result newer than that comes for their number. */
    public function called(Campaign $campaign, string $targetId, Carbon $at): void
    {
        $phone = CampaignTarget::query()->where('campaign_id', $campaign->id)->whereKey($targetId)->value('phone_number');
        CampaignTarget::query()->where('campaign_id', $campaign->id)->where('phone_number', $phone)->whereNotNull('retried_at')
            ->where('retried_at', '<=', $at->copy()->setTimezone(config('app.timezone')))->update(['retried_at' => null, 'updated_at' => now()]);
    }

    /** The people Telenow took back, by what the kit has read of them, one for each number. */
    private function mark(Campaign $campaign, string $scope): void
    {
        $c = config('kit.campaigns');
        $ids = CampaignTarget::query()->where('campaign_id', $campaign->id)->whereNotNull('completed_at')->whereNull('retried_at')
            ->when($scope === 'unanswered', fn ($q) => $q->whereIn('outcome', $c['unanswered_outcomes']), fn ($q) => $q->where(fn ($o) => $o->whereNull('outcome')->orWhere('outcome', '!=', $c['answered_outcome'])))
            ->selectRaw('MIN(id) AS id')->groupBy('phone_number')->pluck('id');
        foreach ($ids->chunk($c['insert_batch']) as $chunk) {
            CampaignTarget::query()->whereIn('id', $chunk->all())->update(['retried_at' => now(), 'updated_at' => now()]);
        }
    }
}
