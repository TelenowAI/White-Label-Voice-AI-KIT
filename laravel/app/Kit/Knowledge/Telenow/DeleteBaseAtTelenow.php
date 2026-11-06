<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/** Deletes the base at Telenow (every agent stops answering from it there). One Telenow no longer has (404) is as good as deleted. */
final class DeleteBaseAtTelenow extends BaseWrite
{
    public function send(OutboxEntry $entry): void
    {
        $base = $this->base($entry);
        if ($base->telenow_id !== null) {
            [$key, $org] = self::account($base->workspace);
            try {
                $this->telenow->deleteKnowledgeBase($key, $org, $base->telenow_id);
            } catch (TelenowError $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $base->forceFill(['deleted_at' => $base->deleted_at ?? now(), 'sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
