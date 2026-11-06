<?php

namespace App\Kit\Customers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;

/**
 * What every customer write to Telenow shares: the workspace it changes, the operator's key it is sent with (only the
 * operator's key may manage sub-accounts, §5 C1), and how its outcome shows on the workspace — `pending` with the
 * last reason while it is tried again, `failed` with Telenow's reason once given up, `synced` with Telenow's answer
 * once done (synced()).
 *
 * Used by CreateAtTelenow, SuspendAtTelenow, ResumeAtTelenow.
 */
abstract class WorkspaceWrite implements OutboxHandler
{
    public function __construct(protected readonly TelenowClient $telenow, protected readonly Settings $settings) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->workspace($entry)->forceFill(['sync_state' => Workspace::SYNC_PENDING, 'sync_error' => $error->getMessage()])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->workspace($entry)->forceFill(['sync_state' => Workspace::SYNC_FAILED, 'sync_error' => $error->getMessage()])->save();
    }

    protected function workspace(OutboxEntry $entry): Workspace
    {
        return Workspace::query()->findOrFail($entry->kit_id);
    }

    protected function operatorKey(): string
    {
        $key = $this->settings->get('telenow.api_key');
        if (! is_string($key) || $key === '') {
            throw new TelenowError(401, __('customers.no_operator_key'));
        }

        return $key;
    }

    /** Telenow took it: keep its sub-account object, and the row is in step. */
    protected function synced(Workspace $workspace, array $subAccount): void
    {
        $workspace->forceFill([
            'telenow_snapshot' => $subAccount,
            'sync_state' => Workspace::SYNC_SYNCED,
            'sync_error' => null,
            'synced_at' => now(),
        ])->save();
    }
}
