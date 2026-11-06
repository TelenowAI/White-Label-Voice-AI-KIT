<?php

namespace App\Kit\Team;

use RuntimeException;

/**
 * Team will not make this change: `status` is the HTTP answer (404: no such invite or member; 409: already a member,
 * the last manager) and the message is for the person. Thrown by Team; the team controllers answer it as `{message}`.
 */
final class TeamRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
