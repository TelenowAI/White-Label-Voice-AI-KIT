<?php

namespace App\Kit\Dnc;

use RuntimeException;

/** A DNC action the kit refuses, with the HTTP status and the customer's words. */
final class DncRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
