<?php

namespace App\Kit\Numbers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Has calls to the number ask the account's routing endpoint who takes them, or not (`setNumberPool`): the write's body is `{routed}`. Telenow keeps
 * it as `is_pool`, read back through the manifest (`routed`). Telenow refuses a number with no agent (recorded); App\Kit\Numbers\Numbers::route
 * checks that first.
 */
final class RouteAtTelenow extends NumberWrite
{
    public function send(OutboxEntry $entry): void
    {
        $number = $this->number($entry);
        if ($number->telenow_id === null) {
            throw new TelenowError(0, __('numbers.not_bought_yet'));
        }
        $answer = $this->telenow->setNumberPool($this->key($number), $number->telenow_id, ($entry->body['routed'] ?? false) === true);
        $this->synced($number, $this->manifest->object(self::RESOURCE, 'read', $answer));
    }
}
