<?php

namespace App\Kit\Routing;

use RuntimeException;

/** A routing action the kit refuses, with the status and the customer's words (App\Kit\Routing). */
final class RoutingRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
