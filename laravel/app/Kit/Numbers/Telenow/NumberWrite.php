<?php

namespace App\Kit\Numbers\Telenow;

use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;
use App\Models\PhoneNumber;

/**
 * What every number write to Telenow shares: the number, the customer's own key, Telenow's number mapped back by the
 * manifest (its agent to the kit's), and how the outcome shows — `pending` with the kit's "will retry" words while it is
 * tried again, `failed` with the kit's words for what went wrong (Telenow's own text speaks of its Billing page and
 * reward credits, which are the operator's business), `synced` once done.
 *
 * Used by BuyAtTelenow, AssignAtTelenow, RouteAtTelenow, CallerIdAtTelenow, UnansweredAtTelenow, ReleaseAtTelenow.
 */
abstract class NumberWrite implements OutboxHandler
{
    public const RESOURCE = 'numbers';

    public function __construct(protected readonly TelenowClient $telenow, protected readonly Manifest $manifest) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->number($entry)->forceFill(['sync_state' => 'pending', 'sync_error' => __('numbers.will_retry')])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->number($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => $this->words($entry, $error)])->save();
    }

    protected function number(OutboxEntry $entry): PhoneNumber
    {
        return PhoneNumber::query()->with('workspace')->findOrFail($entry->kit_id);
    }

    protected function key(PhoneNumber $number): string
    {
        $key = $number->workspace->telenow_key;
        if (! is_string($key) || $key === '') {
            throw new TelenowError(0, __('numbers.account_not_ready'));
        }

        return $key;
    }

    /** Telenow's number onto the row: its fields by the manifest, its agent as the kit's. */
    protected function synced(PhoneNumber $number, array $object): void
    {
        $agent = $object['agent_id'] ?? null;
        $number->forceFill($this->manifest->columns(self::RESOURCE, $object) + [
            'telenow_id' => $object['id'] ?? $number->telenow_id,
            'agent_id' => is_string($agent) ? Agent::query()->where('workspace_id', $number->workspace_id)->where('telenow_id', $agent)->value('id') : null,
            'sync_state' => 'synced',
            'sync_error' => null,
        ])->save();
    }

    private function words(OutboxEntry $entry, TelenowError $error): string
    {
        return match (true) {
            $entry->operation === 'create' && in_array($error->status, [404, 409], true) => __('numbers.not_available'),
            $error->status === 403 => __('numbers.cannot_buy_now'),
            default => __('numbers.not_done'),
        };
    }
}
