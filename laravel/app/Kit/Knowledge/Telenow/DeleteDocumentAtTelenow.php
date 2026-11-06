<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/** Deletes the document at Telenow. One Telenow no longer has (404) is as good as deleted. */
final class DeleteDocumentAtTelenow extends DocumentWrite
{
    public function send(OutboxEntry $entry): void
    {
        $doc = $this->document($entry);
        if ($doc->telenow_id !== null) {
            [$key, $org, $kb] = $this->where($doc);
            try {
                $this->telenow->deleteKnowledgeDocument($key, $org, $kb, $doc->telenow_id);
            } catch (TelenowError $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $doc->forceFill(['deleted_at' => $doc->deleted_at ?? now(), 'sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
