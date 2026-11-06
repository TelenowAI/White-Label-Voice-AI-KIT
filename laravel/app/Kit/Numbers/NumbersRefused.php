<?php

namespace App\Kit\Numbers;

use RuntimeException;

/** A number action the kit refuses, with the HTTP status and the customer's words. */
final class NumbersRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
