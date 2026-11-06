<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Outbox\OutboxEntry;

/** Has Telenow read a document again that it could not (`retryKnowledgeDocument`); the feed brings how that went. */
final class RetryDocumentAtTelenow extends DocumentWrite
{
    public function send(OutboxEntry $entry): void
    {
        $doc = $this->document($entry);
        [$key, $org, $kb] = $this->where($doc);
        $this->synced($doc, $this->manifest->object(self::RESOURCE, 'create', $this->telenow->retryKnowledgeDocument($key, $org, $kb, $doc->telenow_id)));
    }
}
