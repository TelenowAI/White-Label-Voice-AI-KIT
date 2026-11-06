<?php

namespace App\Kit\Pricing;

use RuntimeException;

/**
 * The pricing engine will not price this (contract/pricing/README.md, "A refusal"): `code` is one of the spec's
 * refusal codes and `subject` what it was pricing (a line, `call`/`fee`, or a price list's id). Thrown by
 * PricingEngine and TelenowItems; never a silent zero.
 */
final class PricingRefused extends RuntimeException
{
    public function __construct(public readonly string $refusal, public readonly string $subject)
    {
        parent::__construct("{$refusal}: {$subject}");
    }
}
