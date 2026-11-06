<?php

namespace App\Kit\Oidc;

use RuntimeException;

/**
 * A provider could not be read, refused, or answered something that does not check out (a token whose signature,
 * issuer, audience, expiry or nonce is wrong) — in words for staff and the logs, never shown to the person signing in.
 * Thrown by Discovery, IdToken and OidcSignIn.
 */
final class OidcFailed extends RuntimeException {}
