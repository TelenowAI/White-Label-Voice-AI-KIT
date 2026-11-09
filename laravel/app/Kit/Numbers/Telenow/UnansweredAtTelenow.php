<?php

namespace App\Kit\Numbers\Telenow;

use App\Kit\Outbox\OutboxEntry;
use App\Kit\Telenow\TelenowError;

/**
 * What a call to the number does while no agent answers it (`setNumberInboundAction`): the write's body is `{action}`, Telenow's word for the
 * kit's choice (config('kit.numbers.unanswered')). Telenow keeps it as `inbound_action`, read back through the manifest. Telenow refuses it for
 * a number with an agent (recorded); App\Kit\Numbers\Numbers::unanswered checks that first.
 */
final class UnansweredAtTelenow extends NumberWrite
{
    public function send(OutboxEntry $entry): void
    {
        $number = $this->number($entry);
        if ($number->telenow_id === null) {
            throw new TelenowError(0, __('numbers.not_bought_yet'));
        }
        $action = $entry->body['action'] ?? null;
        $answer = $this->telenow->setNumberInboundAction($this->key($number), $number->telenow_id, is_string($action) ? $action : null);
        $this->synced($number, $this->manifest->object(self::RESOURCE, 'read', $answer));
    }
}
