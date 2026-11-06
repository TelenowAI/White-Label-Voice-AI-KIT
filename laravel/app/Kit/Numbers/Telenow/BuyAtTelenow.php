<?php

namespace App\Kit\Numbers\Telenow;

use App\Kit\Outbox\OutboxEntry;

/**
 * Buys the number at Telenow with the customer's own key: the number, the provider that offered it and the prices it
 * was offered at (`extras`, as Telenow asks). Telenow charges the operator's account at once (setup and the first
 * month); the customer's wallet is charged from Telenow's ledger (App\Kit\Wallet\FeeCharges). The number row's id is the
 * Idempotency-Key, so a retried purchase cannot buy two numbers.
 */
final class BuyAtTelenow extends NumberWrite
{
    public function send(OutboxEntry $entry): void
    {
        $number = $this->number($entry);
        $answer = $this->telenow->purchaseNumber($this->key($number), $entry->body, $entry->idempotency_key);
        $this->synced($number, $this->manifest->object(self::RESOURCE, 'create', $answer));
    }
}
