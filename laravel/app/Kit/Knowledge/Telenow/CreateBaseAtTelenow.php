<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Outbox\OutboxEntry;

/** Makes the base at Telenow with the customer's own key (its name and description, as the manifest writes them). */
final class CreateBaseAtTelenow extends BaseWrite
{
    public function send(OutboxEntry $entry): void
    {
        $base = $this->base($entry);
        [$key, $org] = self::account($base->workspace);
        $object = $this->manifest->object(self::RESOURCE, 'create', $this->telenow->createKnowledgeBase($key, $org, $entry->body ?? []));
        $base->forceFill($this->manifest->columns(self::RESOURCE, $object) + [
            'telenow_id' => $object['id'] ?? null, 'sync_state' => 'synced', 'sync_error' => null,
        ])->save();
    }
}
