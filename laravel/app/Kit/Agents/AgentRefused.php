<?php

namespace App\Kit\Agents;

use RuntimeException;

/**
 * The kit will not do this to an agent: `status` is the HTTP answer (404 no such agent, 409 not possible now, 429 too
 * many test calls at once, 503 the voice service could not start a call) and the message is for the customer — it
 * never names the voice platform. Thrown by Agents and TestCall; AgentsController answers it as `{message}`.
 */
final class AgentRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
