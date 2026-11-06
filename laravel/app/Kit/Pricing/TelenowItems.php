<?php

namespace App\Kit\Pricing;

/**
 * Telenow's answers → the pricing engine's items (contract/pricing/README.md, "What is priced"): a call from its
 * settled charge (`CallCharge`, null until rated) and the call (`CallV1`); a fee from a `LedgerEntry`. A call's
 * components are every `<name>Usd` field of the charge but the total — the engine keeps no list of them.
 *
 * Not here: the pricing (PricingEngine), fetching the answers (TelenowClient, K1's cost job).
 */
final class TelenowItems
{
    /** @param array<string, mixed>|null $charge @param array<string, mixed> $call */
    public static function call(?array $charge, array $call): array
    {
        if ($charge === null) {
            throw new PricingRefused('not_rated', 'call');
        }
        $components = [];
        foreach ($charge as $key => $value) {
            if (str_ends_with($key, 'Usd') && $key !== 'totalChargeUsd') {
                $components[substr($key, 0, -strlen('Usd'))] = (string) $value;
            }
        }

        return [
            'kind' => 'call',
            'attributes' => ['callType' => $charge['callType'], 'direction' => $call['direction'], 'channel' => $call['channel']],
            'durationSec' => $call['duration_sec'] ?? 0,
            'components' => $components,
            'totalUsd' => (string) $charge['totalChargeUsd'],
        ];
    }

    /** @param array<string, mixed> $entry */
    public static function fee(array $entry): array
    {
        if ($entry['entryType'] !== 'charge') {
            throw new PricingRefused('not_a_charge', 'fee');
        }

        return [
            'kind' => 'fee',
            'attributes' => ['refKind' => $entry['refKind']],
            'costUsd' => PricingEngine::short(PricingEngine::decimal($entry['amountUsd'])->negated()),
        ];
    }
}
