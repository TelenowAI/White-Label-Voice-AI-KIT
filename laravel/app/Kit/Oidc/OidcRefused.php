<?php

namespace App\Kit\Oidc;

use RuntimeException;

/** Something about sign-in providers the kit will not do, with the HTTP status and a message for staff (OidcProviders). */
final class OidcRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
