<?php

namespace App\Kit\Calls;

use App\Models\Call;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Which of an account's calls a page or a file asks for (contract/kit-api.yaml listCalls, exportCalls, getInsights): an agent, a number at
 * either end, an outcome, a channel, and a period of whole days in the viewer's time zone (an IANA name; UTC when none). A period is at most
 * config('kit.insights.max_days') days; the insights always have one (the last config('kit.insights.default_days') when none is asked), the
 * list and the file only when one is asked. A time zone may be an old name too (`Asia/Calcutta`, which some browsers still report). A filter
 * that is not one is a 422 naming it.
 *
 * Not here: the list (CallsController), the file (CallsExport), the figures (App\Kit\Reports\Insights).
 */
final class CallFilters
{
    /** @return array{from: CarbonImmutable, to: CarbonImmutable, timeZone: string, start: CarbonImmutable, end: CarbonImmutable}|null whole days, and the instants they start and end */
    public static function period(array $query, bool $always): ?array
    {
        $values = Validator::make($query, [
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'timeZone' => ['sometimes', 'nullable', 'string', 'max:64', Rule::in(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC))],
        ], [], __('calls.filter_fields'))->validate();
        $zone = $values['timeZone'] ?? null ?: 'UTC';
        $from = $values['from'] ?? null;
        $to = $values['to'] ?? null;
        if (! $always && $from === null && $to === null) {
            return null;
        }
        $to = $to === null ? CarbonImmutable::now($zone)->startOfDay() : CarbonImmutable::createFromFormat('!Y-m-d', $to, $zone);
        $from = $from === null ? $to->subDays(config('kit.insights.default_days') - 1) : CarbonImmutable::createFromFormat('!Y-m-d', $from, $zone);
        if ($from->gt($to)) {
            throw ValidationException::withMessages(['to' => [__('calls.period_backwards')]]);
        }
        if ($from->diffInDays($to) + 1 > config('kit.insights.max_days')) {
            throw ValidationException::withMessages(['to' => [__('calls.period_too_long', ['days' => config('kit.insights.max_days')])]]);
        }

        return ['from' => $from, 'to' => $to, 'timeZone' => $zone, 'start' => $from->utc(), 'end' => $to->addDay()->utc()];
    }

    /** `$calls` narrowed to what `$query` asks for; its period, when it asks for one, is answered too. */
    public static function apply(Builder $calls, array $query, bool $always = false): ?array
    {
        $agent = $query['agentId'] ?? null;
        if (is_string($agent) && $agent !== '') {
            // Not an agent's id: no call (a null here would ask for the calls with no agent).
            Str::isUuid($agent) ? $calls->where('agent_id', $agent) : $calls->whereRaw('1 = 0');
        }
        // One person's calls: their number as either end, however it was written.
        $number = $query['number'] ?? null;
        if (is_string($number) && trim($number) !== '') {
            $digits = Call::digits($number);
            $calls->where(fn ($q) => $q->where('from_digits', $digits)->orWhere('to_digits', $digits));
        }
        foreach (['outcome' => 'outcome', 'channel' => 'channel'] as $name => $column) {
            $value = $query[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $calls->where($column, mb_substr($value, 0, 64));
            }
        }
        $period = self::period($query, $always);
        if ($period !== null) {
            $calls->where('started_at', '>=', $period['start'])->where('started_at', '<', $period['end']);
        }

        return $period;
    }

    /** A person was on it: it lasted, and no machine picked up (the same as App\Kit\Agents\AfterCallEmail's). */
    public static function answered(?int $seconds, ?string $answeredBy): bool
    {
        return (int) $seconds > 0 && ! str_starts_with((string) $answeredBy, config('kit.agents.machine_answer_prefix'));
    }
}
