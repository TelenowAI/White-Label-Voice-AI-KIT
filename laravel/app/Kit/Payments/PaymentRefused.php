<?php

namespace App\Kit\Payments;

use RuntimeException;

/**
 * A payment action the kit will not do, with the HTTP status to answer and a message for the person (gateways and
 * payments: Gateways, Payments, PaymentSettings). The payments controllers answer it as `{message}`.
 */
final class PaymentRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
