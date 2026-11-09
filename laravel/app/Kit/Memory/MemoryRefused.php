<?php

namespace App\Kit\Memory;

use RuntimeException;

/** The kit will not read or forget callers now: `status` is the HTTP answer (403 not someone who manages the account, 503 the voice platform cannot be reached); the message never names the voice platform. Thrown by CallerMemory; MemoryController answers it as `{message}`. */
final class MemoryRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
