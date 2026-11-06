<?php

namespace App\Kit\Signup;

use RuntimeException;

/**
 * A sign-up step was refused: `status` is the HTTP answer (404 no such sign-up, 409 closed or not now) and the message
 * is for the person. Thrown by Signups and SignInLinks; answered as `{message}`.
 */
final class SignupRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
