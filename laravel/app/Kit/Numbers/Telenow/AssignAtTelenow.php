<?php

namespace App\Kit\Numbers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Has one of the customer's agents answer calls to the number (`assignNumberAgent`), or none (`unassignNumberAgent`):
 * the write's body is `{agentId}` — Telenow's agent id, or null.
 */
final class AssignAtTelenow extends NumberWrite
{
    public function send(OutboxEntry $entry): void
    {
        $number = $this->number($entry);
        if ($number->telenow_id === null) {
            throw new TelenowError(0, __('numbers.not_bought_yet'));
        }
        $agent = $entry->body['agentId'] ?? null;
        $answer = is_string($agent)
            ? $this->telenow->assignNumberAgent($this->key($number), $number->telenow_id, $agent)
            : $this->telenow->unassignNumberAgent($this->key($number), $number->telenow_id);
        $this->synced($number, $this->manifest->object(self::RESOURCE, 'read', $answer));
    }
}
