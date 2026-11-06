<?php

namespace App\Kit\Pricing;

use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * The pricing engine (contract/pricing/README.md, its steps word for word): an item — a call or a fee, from
 * TelenowItems — run through a plan's price list at a rate gives the customer's lines and total, or a
 * PricingRefused. The same spec as contract/pricing/engine.ts; tests/Feature/Kit/PricingFixturesTest.php holds this
 * one to the shared fixtures, so both stacks charge the same to the last decimal.
 *
 * Exact throughout (brick/math fractions); money is a decimal string in and out; each line is rounded once.
 * Not here: where price lists are stored, the rate source, the wallet entry (K1).
 */
final class PricingEngine
{
    private const DECIMAL = '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/';

    private const ROUNDING = [
        'half-up' => RoundingMode::HalfUp,
        'half-even' => RoundingMode::HalfEven,
        'up' => RoundingMode::Up,
        'down' => RoundingMode::Down,
    ];

    /**
     * @param  list<array<string, mixed>>  $lists  every price list the plan may reach
     * @param  array<string, mixed>  $item
     * @return array{currency: string, usdRate: string, lines: list<array<string, mixed>>, total: string}
     */
    public function price(array $lists, string $plan, string $usdRate, array $item): array
    {
        $chain = $this->chain(array_column($lists, null, 'id'), $plan);
        $head = $chain[0];
        $rules = [];
        foreach ($chain as $list) {
            foreach ($list['rules'] as $rule) {
                $rules[] = ['list' => $list['id'], 'rule' => $rule];
            }
        }
        $rate = self::decimal($usdRate);
        $first = function (string $kind, array $attributes) use ($rules): ?array {
            foreach ($rules as $hit) {
                if (self::matches($hit['rule'], $kind, $attributes)) {
                    return $hit;
                }
            }

            return null;
        };

        $lines = [];
        $add = function (string $subject, array $hit, BigRational $cost) use (&$lines, $head, $rate, $item): void {
            $price = $hit['rule']['price'];
            $billedSeconds = null;
            if (isset($price['perMinute'])) {
                $increment = $head['billingIncrementSeconds'];
                $duration = $item['kind'] === 'call' ? (int) ($item['durationSec'] ?? 0) : 0;
                $billedSeconds = intdiv($duration + $increment - 1, $increment) * $increment;
                $amount = self::decimal($price['perMinute'])->multipliedBy($billedSeconds)->dividedBy(60);
                $method = 'perMinute';
            } elseif (isset($price['markupPercent'])) {
                $amount = $cost->multipliedBy(BigRational::of(100)->plus(self::decimal($price['markupPercent'])))
                    ->dividedBy(100)->multipliedBy($rate);
                $method = 'markupPercent';
            } else {
                $amount = self::decimal($price['fixed']);
                $method = 'fixed';
            }
            $lines[] = [
                'subject' => $subject,
                'rule' => "{$hit['list']}/{$hit['rule']['id']}",
                'method' => $method,
                'costUsd' => self::short($cost),
                'billedSeconds' => $billedSeconds,
                'amount' => $amount->toScale($head['decimals'], self::ROUNDING[$head['rounding']]),
            ];
        };

        if ($item['kind'] === 'call') {
            $total = self::decimal($item['totalUsd']);
            $whole = $first('call', $item['attributes']);
            if ($whole !== null) {
                $add('call', $whole, $total);
            } else {
                $parts = array_map(fn (string $v) => self::decimal($v), $item['components']);
                $rest = $total;
                foreach ($parts as $cost) {
                    $rest = $rest->minus($cost);
                }
                if ($rest->isNegative()) {
                    throw new PricingRefused('components_exceed_total', 'call');
                }
                if (! $rest->isZero()) {
                    $parts['unlisted'] = $rest;
                }
                $names = array_map('strval', array_keys($parts));
                sort($names, SORT_STRING);
                foreach ($names as $name) {
                    if ($parts[$name]->isZero()) {
                        continue;
                    }
                    $hit = $first('component', $item['attributes'] + ['component' => $name]);
                    if ($hit === null) {
                        throw new PricingRefused('unpriced_component', "component:{$name}");
                    }
                    $add("component:{$name}", $hit, $parts[$name]);
                }
            }
        } else {
            $refKind = $item['attributes']['refKind'] ?? '';
            $hit = $first('fee', $item['attributes']);
            if ($hit === null) {
                throw new PricingRefused('unpriced_fee', "fee:{$refKind}");
            }
            $add("fee:{$refKind}", $hit, self::decimal($item['costUsd']));
        }

        $sum = BigRational::of(0);
        foreach ($lines as $line) {
            $sum = $sum->plus($line['amount']);
        }

        return [
            'currency' => $head['currency'],
            'usdRate' => self::short($rate),
            'lines' => array_map(fn (array $l) => array_merge($l, ['amount' => (string) $l['amount']]), $lines),
            'total' => (string) $sum->toScale($head['decimals']),
        ];
    }

    /** A decimal string, `-?digits[.digits]` only — never an exponent or a float. */
    public static function decimal(string $value): BigRational
    {
        if (! preg_match(self::DECIMAL, $value)) {
            throw new InvalidArgumentException('Not a decimal: '.json_encode($value));
        }

        return BigRational::of($value);
    }

    /**
     * The shortest decimal string: no trailing zeros, `0` for zero (README.md step 7). brick/math 1.0 already gives
     * the fewest places here; stripping says so outright rather than leaning on how the library builds the decimal.
     */
    public static function short(BigRational $value): string
    {
        return (string) $value->toBigDecimal()->strippedOfTrailingZeros();
    }

    /** Step 1: the plan's list, then each list it extends. @param array<string, array> $lists */
    private function chain(array $lists, string $plan): array
    {
        $out = [];
        $seen = [];
        for ($id = $plan; $id !== null; $id = $lists[$id]['extends'] ?? null) {
            if (! isset($lists[$id])) {
                throw new PricingRefused('unknown_price_list', $id);
            }
            if (isset($seen[$id])) {
                throw new PricingRefused('price_list_cycle', $id);
            }
            $seen[$id] = true;
            if ($out && $lists[$id]['currency'] !== $out[0]['currency']) {
                throw new PricingRefused('currency_mismatch', $id);
            }
            $out[] = $lists[$id];
        }

        return $out;
    }

    private static function matches(array $rule, string $kind, array $attributes): bool
    {
        if ($rule['match']['item'] !== $kind) {
            return false;
        }
        foreach ($rule['match'] as $key => $want) {
            if ($key === 'item') {
                continue;
            }
            $have = $attributes[$key] ?? null;
            if ($have === null) {
                return false;
            }
            if (is_array($want) ? ! in_array($have, $want, true) : $have !== $want) {
                return false;
            }
        }

        return true;
    }
}
