<?php

namespace App\Kit\Calls;

use RuntimeException;

/** Something a member writes on a call that the kit will not keep: its status and words for the customer. */
final class CallWorkRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
