<?php

namespace App\Kit\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The days a report covers, both ends included, in the app's time zone: `from` and `to` (Y-m-d) as asked, else this
 * month so far. At most config('kit.reports.max_days') days. Used by Dashboard and CostReport.
 */
final class Period
{
    private function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to) {}

    /** @throws ValidationException naming `from` or `to` */
    public static function of(array $input): self
    {
        $values = Validator::make($input, [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ], [], ['from' => __('reports.fields.from'), 'to' => __('reports.fields.to')])->validate();
        $zone = config('app.timezone');
        $today = CarbonImmutable::now($zone)->startOfDay();
        $from = isset($values['from']) ? CarbonImmutable::createFromFormat('!Y-m-d', $values['from'], $zone) : $today->startOfMonth();
        $to = isset($values['to']) ? CarbonImmutable::createFromFormat('!Y-m-d', $values['to'], $zone) : $today;
        $max = config('kit.reports.max_days');
        if ($from->diffInDays($to) + 1 > $max) {
            throw ValidationException::withMessages(['to' => [__('reports.too_long', ['days' => $max])]]);
        }

        return new self($from, $to);
    }

    /** The first and the last instant of the period. */
    public function bounds(): array
    {
        return [$this->from->startOfDay(), $this->to->endOfDay()];
    }

    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }
}
