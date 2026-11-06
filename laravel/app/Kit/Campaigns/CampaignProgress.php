<?php

namespace App\Kit\Campaigns;

use App\Kit\Campaigns\Telenow\CampaignWrite;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Campaign;

/**
 * How a campaign went, read live from Telenow (getCampaignAnalytics) when a customer looks. Only what is named here is answered, so nothing Telenow adds later can reach them: its figures hold what it charges the operator
 * (`chargeUsdTotal`, `talkedChargeUsd`) and how long each part of its service took, neither of which is a customer's business.
 *
 * Not here: each person's call (CampaignPeople), what the customer paid (their wallet, call by call).
 */
final class CampaignProgress
{
    public function __construct(private readonly TelenowClient $telenow) {}

    /** @return array<string, mixed> */
    public function of(Campaign $campaign): array
    {
        if (! is_string($campaign->telenow_id)) {
            throw new CampaignRefused(409, __('campaigns.not_ready'));
        }
        try {
            [$key, $org] = CampaignWrite::account($campaign->workspace);
            $a = $this->telenow->campaignAnalytics($key, $org, $campaign->telenow_id);
        } catch (TelenowError) {
            throw new CampaignRefused(503, __('campaigns.unavailable'));
        }
        $n = fn (string $name): int => (int) ($a[$name] ?? 0);
        $hours = fn (string $name): array => array_values(array_map('intval', (array) ($a[$name] ?? [])));

        return [
            'people' => $n('targets'),
            'called' => $n('dialled'),
            'reached' => $n('reached'),
            'notReached' => $n('unreached'),
            'waiting' => $n('pending') + $n('dialing'),
            'onDoNotCall' => $n('suppressed'),
            'skipped' => $n('skipped'),
            'whyNotReached' => ['noAnswer' => $n('fNoAnswer'), 'busy' => $n('fBusy'), 'voicemail' => $n('fMachine'), 'couldNotConnect' => $n('fCarrierFailed') + $n('fDialTimeout') + $n('fOther')],
            'talk' => [
                'totalSeconds' => $n('talkSecs'), 'averageSeconds' => isset($a['talkSecsAvg']) ? (int) $a['talkSecsAvg'] : null, 'nobodySpoke' => $n('answeredSilent'),
                'under15' => $n('talkU15'), 'under30' => $n('talkU30'), 'under60' => $n('talkU60'), 'under120' => $n('talkU120'), 'over120' => $n('talkO120'),
            ],
            'reachedOnAttempt' => ['first' => $n('reachedA1'), 'second' => $n('reachedA2'), 'thirdOrLater' => $n('reachedA3plus')],
            'calledLastHour' => $n('dialledLastHour'),
            'byHour' => ['timezone' => is_string($a['timezone'] ?? null) ? $a['timezone'] : $campaign->timezone, 'called' => $hours('dialHours'), 'reached' => $hours('reachedHours')],
        ];
    }
}
