<?php

namespace App\Kit\Campaigns;

use RuntimeException;

/** A campaign action the kit refuses, with the status and the customer's words (App\Kit\Campaigns\Campaigns). */
final class CampaignRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
