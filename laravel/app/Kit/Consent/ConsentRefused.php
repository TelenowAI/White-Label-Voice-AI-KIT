<?php

namespace App\Kit\Consent;

use RuntimeException;

/** A consent action the kit will not do: its status and words for the customer. */
final class ConsentRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
