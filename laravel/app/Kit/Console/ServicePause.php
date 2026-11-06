<?php

namespace App\Kit\Console;

use App\Kit\Pricing\Pricing;
use Brick\Math\BigDecimal;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;

/**
 * Whether a customer account can use the service right now, and why not (SELF_HOSTED_KIT_PLAN.md §4.6 c): the console
 * shows a "service paused" notice and the paid actions stay shut until it clears by itself.
 *
 * Reasons, first match wins:
 * - `account_closed`: the operator closed this account (closing or closed);
 * - `account_suspended`: the operator suspended this account;
 * - `account_not_ready`: Telenow has not made its sub-account yet;
 * - `out_of_credit`: its balance is used up (only while the wallet is in force, App\Kit\Pricing\Pricing::inForce;
 *   Telenow's spend limit is what actually stops its calls, App\Kit\Wallet\SpendLimit);
 * - `service_paused`: Telenow rejects the operator's key, or will not let the operator's account spend.
 *
 * A customer is never told why the operator's own account is stopped, or that Telenow exists (white label, §9): that
 * reason is the admin's (refresh() keeps it in settings as `telenow.billing`, `telenow.key_rejected`).
 *
 * refresh() reads Telenow; `kit:telenow-status` runs it every config('kit.telenow.status_minutes').
 */
final class ServicePause
{
    public function __construct(private readonly Settings $settings, private readonly TelenowClient $telenow, private readonly Pricing $pricing) {}

    /** @return array{reason: string, message: string}|null */
    public function for(Workspace $workspace): ?array
    {
        $reason = match (true) {
            in_array($workspace->status, Workspace::ENDED, true) => 'account_closed',
            $workspace->status === Workspace::SUSPENDED => 'account_suspended',
            $workspace->telenow_sub_account_id === null => 'account_not_ready',
            ! BigDecimal::of($workspace->balance ?? '0')->isPositive() && $this->pricing->inForce() => 'out_of_credit',
            (bool) $this->settings->get('telenow.key_rejected', false),
            ($this->settings->get('telenow.billing')['canSpend'] ?? true) === false => 'service_paused',
            default => null,
        };

        return $reason === null ? null : ['reason' => $reason, 'message' => __("console.paused.{$reason}")];
    }

    /** Read the operator's account state from Telenow. A failed read keeps the last known state and says why. */
    public function refresh(): void
    {
        $key = $this->settings->get('telenow.api_key');
        $orgId = $this->settings->get('telenow.account')['org_id'] ?? null;
        if (! is_string($key) || ! is_string($orgId)) {
            return; // not set up yet
        }
        try {
            $state = $this->telenow->billingSummary($key, $orgId)['spendState'] ?? null;
        } catch (TelenowError $e) {
            $this->settings->put('telenow.status_error', $e->getMessage());
            if ($e->status === 401) {
                $this->settings->put('telenow.key_rejected', true);
            }

            return;
        }
        $this->settings->put('telenow.key_rejected', false);
        $this->settings->put('telenow.status_error', null);
        $this->settings->put('telenow.status_checked_at', now()->toIso8601String());
        if (is_array($state)) {
            $this->settings->put('telenow.billing', array_intersect_key($state, array_flip(config('kit.telenow.spend_state_fields'))));
        }
    }
}
