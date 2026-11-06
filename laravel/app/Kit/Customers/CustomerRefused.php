<?php

namespace App\Kit\Customers;

use RuntimeException;

/**
 * Customers will not make this change: `status` is the HTTP answer (409: already so, nothing to retry) and the message
 * is for the operator. Thrown by Customers; CustomersController answers it as `{message}`.
 */
final class CustomerRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
