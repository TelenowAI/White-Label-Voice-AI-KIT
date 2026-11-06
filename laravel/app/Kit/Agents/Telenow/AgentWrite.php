<?php

namespace App\Kit\Agents\Telenow;

use App\Kit\Agents\AgentSettings;
use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Agent;

/**
 * What every agent write to Telenow shares: the agent it changes, the key it goes with — the customer's own (its
 * sub-account's, §5 C1), never the operator's — and how the outcome shows on the agent: `pending` while it is tried
 * again (with the kit's own "will retry" words: a passing failure's text can name the platform's host), `failed` with
 * the refusal's reason once given up, `synced` with Telenow's object (its fields mapped back by the manifest) once done.
 *
 * Used by CreateAtTelenow, UpdateAtTelenow, DeleteAtTelenow.
 */
abstract class AgentWrite implements OutboxHandler
{
    public const RESOURCE = 'agents';

    public function __construct(
        protected readonly TelenowClient $telenow,
        protected readonly Manifest $manifest,
        protected readonly Settings $settings,
        protected readonly AgentSettings $agentSettings,
    ) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->agent($entry)->forceFill(['sync_state' => 'pending', 'sync_error' => __('agents.will_retry')])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->agent($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => $error->getMessage()])->save();
    }

    protected function agent(OutboxEntry $entry): Agent
    {
        return Agent::query()->with('workspace')->findOrFail($entry->kit_id);
    }

    protected function customerKey(Agent $agent): string
    {
        $key = $agent->workspace->telenow_key;
        if (! is_string($key) || $key === '') {
            throw new TelenowError(0, __('agents.account_not_ready'));
        }

        return $key;
    }

    /** Telenow took it: its object's fields onto the agent, the object kept as the snapshot. */
    protected function synced(Agent $agent, array $object, ?int $version = null): void
    {
        $agent->forceFill($this->manifest->columns(self::RESOURCE, $object) + [
            'telenow_id' => $object['id'] ?? $agent->telenow_id,
            'telenow_version' => $version ?? $agent->telenow_version,
            'telenow_snapshot' => $object,
            'settings' => $this->agentSettings->read($object),
            'sync_state' => 'synced',
            'sync_error' => null,
            'synced_at' => now(),
        ])->save();
    }
}
