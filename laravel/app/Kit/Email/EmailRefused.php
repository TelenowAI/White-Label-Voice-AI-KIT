<?php

namespace App\Kit\Email;

use RuntimeException;

/**
 * A change to how the kit sends email was refused: `status` is the HTTP answer (404 no such sender, 409 not now,
 * 422 the test email failed) and the message is for the operator. Thrown by SenderSetup; answered as `{message}`.
 */
final class EmailRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
