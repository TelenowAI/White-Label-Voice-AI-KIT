<?php

namespace App\Kit\Reports;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Charges added up for one group of one report (CostReport, Dashboard): how many, the customers' price, Telenow's
 * charge in USD and in the price's currency, and the margin. Shown with the price's own decimal places.
 */
final class Totals
{
    /** Decimal places shown for a cost in USD. */
    private const USD_SCALE = 4;

    private int $count = 0;

    private BigDecimal $price;

    private BigDecimal $costUsd;

    private BigDecimal $cost;

    public function __construct(public readonly string $currency)
    {
        $this->price = BigDecimal::zero();
        $this->costUsd = BigDecimal::zero();
        $this->cost = BigDecimal::zero();
    }

    /** Add one row of the daily totals. */
    public function add(object $row): void
    {
        $this->count += (int) $row->count;
        $this->price = $this->price->plus($row->price);
        $this->costUsd = $this->costUsd->plus($row->cost_usd);
        $this->cost = $this->cost->plus($row->cost);
    }

    public function price(): BigDecimal
    {
        return $this->price;
    }

    /** @return array{currency: string, count: int, price: string, cost: string, costUsd: string, margin: string, marginPercent: ?string} */
    public function toArray(): array
    {
        $scale = $this->price->getScale();
        $cost = $this->cost->toScale($scale, RoundingMode::HalfUp);
        $margin = $this->price->minus($cost);

        return [
            'currency' => $this->currency,
            'count' => $this->count,
            'price' => (string) $this->price,
            'cost' => (string) $cost,
            'costUsd' => (string) $this->costUsd->toScale(self::USD_SCALE, RoundingMode::HalfUp),
            'margin' => (string) $margin,
            'marginPercent' => $this->price->isZero() ? null
                : (string) $margin->multipliedBy(100)->dividedBy($this->price, 1, RoundingMode::HalfUp),
        ];
    }
}
