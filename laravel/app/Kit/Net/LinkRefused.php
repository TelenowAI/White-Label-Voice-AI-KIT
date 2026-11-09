<?php

namespace App\Kit\Net;

use RuntimeException;

/** A link PublicLinks would not read, and why, as a key of lang/en/links.php. */
final class LinkRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly array $details = [])
    {
        parent::__construct($reason);
    }
}
