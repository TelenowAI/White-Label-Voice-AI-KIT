<?php

namespace App\Kit\Staff;

/** Why App\Kit\Staff\Staff would not do it, with the HTTP status the admin API answers. */
final class StaffRefused extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
