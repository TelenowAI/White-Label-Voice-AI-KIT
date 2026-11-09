<?php

namespace App\Kit\Calls;

use RuntimeException;

/** Call health cannot be read, with the status and the words to answer (App\Kit\Calls\CallHealth). */
final class CallHealthRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
