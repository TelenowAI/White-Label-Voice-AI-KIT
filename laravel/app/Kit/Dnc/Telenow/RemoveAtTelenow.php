<?php

namespace App\Kit\Dnc\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Takes the number off the customer's list at Telenow. One Telenow no longer has (404: removed already) is as good as
 * removed. The kit keeps its row, marked deleted.
 */
final class RemoveAtTelenow extends DncWrite
{
    public function send(OutboxEntry $entry): void
    {
        $row = $this->entry($entry);
        if ($row->telenow_id !== null) {
            [$key, $org] = $this->account($row);
            try {
                $this->telenow->removeDnc($key, $org, $row->telenow_id);
            } catch (TelenowError $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $row->forceFill(['sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
