<?php

namespace App\Kit\Sms;

use RuntimeException;

/**
 * Something about texts the kit will not do, with the HTTP status to answer and a message for the person (SmsSetup,
 * the phone code controllers). Answered as `{message}`.
 */
final class SmsRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
