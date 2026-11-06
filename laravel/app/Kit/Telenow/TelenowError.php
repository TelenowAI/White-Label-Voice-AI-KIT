<?php

namespace App\Kit\Telenow;

use RuntimeException;

/**
 * A Telenow call that did not succeed: `status` is Telenow's HTTP status, or 0 when Telenow could not be reached;
 * the message is Telenow's own refusal text when it gave one. Thrown by TelenowClient.
 */
final class TelenowError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
