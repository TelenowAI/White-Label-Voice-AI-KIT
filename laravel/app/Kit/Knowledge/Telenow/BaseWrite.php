<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\KnowledgeBase;
use App\Models\Workspace;

/**
 * What every knowledge base write to Telenow shares: the base, the customer's own key and sub-account, and how the
 * outcome shows — `pending` with the kit's "will retry" words while it is tried again, `failed` with the kit's words
 * once given up, `synced` with Telenow's base.
 *
 * Used by CreateBaseAtTelenow, DeleteBaseAtTelenow. Documents: DocumentWrite.
 */
abstract class BaseWrite implements OutboxHandler
{
    public const RESOURCE = 'knowledge_bases';

    public function __construct(protected readonly TelenowClient $telenow, protected readonly Manifest $manifest) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->base($entry)->forceFill(['sync_state' => 'pending', 'sync_error' => __('knowledge.will_retry')])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->base($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => __('knowledge.not_done')])->save();
    }

    protected function base(OutboxEntry $entry): KnowledgeBase
    {
        return KnowledgeBase::query()->with('workspace')->findOrFail($entry->kit_id);
    }

    /** @return array{0: string, 1: string} the customer's key and sub-account */
    public static function account(Workspace $w): array
    {
        if (! is_string($w->telenow_key) || $w->telenow_key === '' || ! is_string($w->telenow_sub_account_id)) {
            throw new TelenowError(0, __('knowledge.account_not_ready'));
        }

        return [$w->telenow_key, $w->telenow_sub_account_id];
    }
}
