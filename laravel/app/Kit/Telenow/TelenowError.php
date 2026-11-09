<?php

namespace App\Kit\Telenow;

use RuntimeException;

/**
 * A Telenow call that did not succeed: `status` is Telenow's HTTP status, or 0 when Telenow could not be reached;
 * the message is Telenow's own refusal text when it gave one, and `body` its whole answer when it was JSON (a refusal's details, as a note's
 * `maxChars`). Thrown by TelenowClient.
 */
final class TelenowError extends RuntimeException
{
    /** @param  array<string, mixed>  $body */
    public function __construct(public readonly int $status, string $message, public readonly array $body = [])
    {
        parent::__construct($message);
    }
}
