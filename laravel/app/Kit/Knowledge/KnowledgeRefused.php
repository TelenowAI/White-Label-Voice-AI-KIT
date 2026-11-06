<?php

namespace App\Kit\Knowledge;

use RuntimeException;

/** A knowledge action the kit refuses, with the status and the customer's words (App\Kit\Knowledge\Knowledge). */
final class KnowledgeRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
