<?php

namespace App\Kit\Voices;

use RuntimeException;

/** An own voice will not be made, cloned, removed or deleted: its status and words for the customer. Thrown by App\Kit\Voices\OwnVoices, answered by Console\OwnVoicesController. */
final class OwnVoiceRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
