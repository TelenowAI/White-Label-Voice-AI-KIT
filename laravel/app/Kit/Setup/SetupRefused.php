<?php

namespace App\Kit\Setup;

use RuntimeException;

/**
 * The wizard will not take this submission: `status` is the HTTP answer (401, 403, 404, 409) and the message is
 * for the person. Thrown by SetupWizard and the steps; SetupController answers it as `{message}`.
 */
final class SetupRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
