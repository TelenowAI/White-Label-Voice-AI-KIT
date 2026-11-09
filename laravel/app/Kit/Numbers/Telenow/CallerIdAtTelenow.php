<?php

namespace App\Kit\Numbers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * Makes the number its agent's caller id when a call names none, or stops it being (`setDefaultOutboundNumber`): the write's body is `{on}`. Telenow
 * keeps it as `is_default_outbound`, read back through the manifest (`agent_caller_id`), and clears the agent's other one itself; the kit cleared
 * its own copy of that one when the write was queued (App\Kit\Numbers\Numbers::callerId). Telenow refuses a number with no agent (recorded).
 */
final class CallerIdAtTelenow extends NumberWrite
{
    public function send(OutboxEntry $entry): void
    {
        $number = $this->number($entry);
        if ($number->telenow_id === null) {
            throw new TelenowError(0, __('numbers.not_bought_yet'));
        }
        $answer = $this->telenow->setDefaultOutboundNumber($this->key($number), $number->telenow_id, ($entry->body['on'] ?? false) === true);
        $this->synced($number, $this->manifest->object(self::RESOURCE, 'read', $answer));
    }
}
