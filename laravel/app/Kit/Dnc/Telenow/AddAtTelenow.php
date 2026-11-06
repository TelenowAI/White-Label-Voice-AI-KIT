<?php

namespace App\Kit\Dnc\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;
use App\Models\DncEntry;

/**
 * Puts the number on the customer's list at Telenow. Telenow writes the number its own way (`+1 (415) 555-0123` →
 * `+14155550123`), and adding a number already on the list answers that entry with the new reason — so when the kit
 * already holds that entry (added before, or brought by the feed), the two rows become one: the older takes the new
 * reason, the newer is closed.
 */
final class AddAtTelenow extends DncWrite
{
    public function send(OutboxEntry $entry): void
    {
        $row = $this->entry($entry);
        [$key, $org] = $this->account($row);
        try {
            $answer = $this->telenow->addDnc($key, $org, $entry->body);
        } catch (TelenowError $e) {
            throw $e->status === 400 ? new TelenowError(400, __('dnc.not_dialable')) : $e;
        }
        $object = $this->manifest->object(self::RESOURCE, 'create', $answer);
        $columns = $this->manifest->columns(self::RESOURCE, $object);
        $twin = DncEntry::query()->where('telenow_id', $object['id'] ?? null)->whereKeyNot($row->id)->first();
        if ($twin !== null) {
            $twin->forceFill($columns + ['deleted_at' => null, 'sync_state' => 'synced', 'sync_error' => null])->save();
            // Closed, with the number as Telenow writes it: what the caller of Dnc::add() finds the entry by.
            $row->forceFill($columns + ['deleted_at' => now(), 'sync_state' => 'synced', 'sync_error' => null])->save();

            return;
        }
        $row->forceFill($columns + ['telenow_id' => $object['id'] ?? null, 'sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
