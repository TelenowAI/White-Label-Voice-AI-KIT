<?php

namespace App\Kit\Calls;

use RuntimeException;

/** Telling the agent refused, with the status and the customer's words (App\Kit\Calls\AgentNotes). */
final class AgentNoteRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
