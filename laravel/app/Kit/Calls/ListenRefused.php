<?php

namespace App\Kit\Calls;

use RuntimeException;

/** Listening in the kit will not start: its status and words for the customer. */
final class ListenRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
