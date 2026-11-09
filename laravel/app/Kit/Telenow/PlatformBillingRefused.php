<?php

namespace App\Kit\Telenow;

/** Refused for the operator's billing at Telenow (App\Kit\Telenow\PlatformBilling): the status to answer with, and the kit's words. */
final class PlatformBillingRefused extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
