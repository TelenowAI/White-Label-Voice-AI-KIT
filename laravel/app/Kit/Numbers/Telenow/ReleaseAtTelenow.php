<?php

namespace App\Kit\Numbers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Releases the number at Telenow: it goes back to the carrier and its rent stops. One Telenow no longer has (404) is as
 * good as released. The kit keeps its row, released.
 */
final class ReleaseAtTelenow extends NumberWrite
{
    public function send(OutboxEntry $entry): void
    {
        $number = $this->number($entry);
        if ($number->telenow_id !== null) {
            try {
                $this->telenow->releaseNumber($this->key($number), $number->telenow_id);
            } catch (TelenowError $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $number->forceFill(['released_at' => $number->released_at ?? now(), 'sync_state' => 'synced', 'sync_error' => null])->save();
    }
}
