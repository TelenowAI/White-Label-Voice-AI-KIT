<?php

namespace App\Kit\Softphone;

use RuntimeException;

/** A call from the browser the kit will not place, end or transfer: its status and words for the customer. */
final class SoftphoneRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
