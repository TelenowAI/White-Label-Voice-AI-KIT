<?php

namespace App\Kit\Routing;

use App\Models\RoutingRule;
use Carbon\CarbonInterface;

/**
 * Which of an account's rules takes a call to a routed number, and what Telenow is answered (App\Http\Controllers\Kit\TelenowRoutingController for a
 * real call; RoutingController's try for a pretend one). The first enabled rule, in order, whose every condition holds; a condition left empty holds
 * for any call:
 *
 * - the number called: one of the rule's numbers (the kit's ids);
 * - the line that forwarded the call: one of the rule's lines (E.164). Telenow passes it on as the carrier reported it — `+919876543210`,
 *   `919876543210`, `09876543210` and `9876543210` are all seen — so a line is the same when the reported digits, leading zeros dropped, end the
 *   rule's and leave out at most a country code (1 to 3 digits, ITU-T E.164). A country whose own trunk prefix is not 0 (an 8 for a 7) is not
 *   read as the same line;
 * - the caller's number starts with one of the rule's starts (digits compared; a whole number is its own start). A withheld number starts with none;
 * - the days and hours, in the rule's timezone: ISO days (1 = Monday); a window from `from` until `until` (until not included), past midnight
 *   when `until` comes first, and then its hours after midnight belong to the day it began.
 *
 * And the answer (pool_routing.rs's contract): none of them → `{}`, the number's own agent; a refusal → `reject` (the caller hears a busy line); else
 * `route` with the rule's agent by Telenow's id (left out — the number's own — when it has none, or it was deleted since), the language and greeting
 * as overrides, the cap as `limits.max_duration_secs`.
 *
 * Not here: checking a rule as it is saved (RoutingRules), who sent the request (RoutingConnection::verify).
 */
final class RoutingMatch
{
    /** A country code is 1 to 3 digits (ITU-T E.164): what a reported line may leave out of the rule's and still be the same line. */
    private const COUNTRY_CODE_MAX_DIGITS = 3;

    /**
     * @param  iterable<RoutingRule>  $rules  the account's, in order
     */
    public static function first(iterable $rules, string $numberId, ?string $caller, ?string $forwarded, CarbonInterface $at): ?RoutingRule
    {
        foreach ($rules as $rule) {
            if ($rule->enabled && self::holds($rule, $numberId, $caller, $forwarded, $at)) {
                return $rule;
            }
        }

        return null;
    }

    public static function holds(RoutingRule $rule, string $numberId, ?string $caller, ?string $forwarded, CarbonInterface $at): bool
    {
        $numbers = (array) $rule->numbers;
        if ($numbers !== [] && ! in_array($numberId, $numbers, true)) {
            return false;
        }
        $lines = (array) $rule->forwarded_from;
        if ($lines !== [] && ! array_filter($lines, fn ($line) => self::sameLine((string) $line, $forwarded))) {
            return false;
        }
        $starts = (array) $rule->caller_starts;
        if ($starts !== [] && ! array_filter($starts, fn ($start) => self::startsWith($caller, (string) $start))) {
            return false;
        }

        return self::inHours($rule, $at);
    }

    /** Whether a forwarded-from value, as the carrier reported it, is this line (E.164). */
    public static function sameLine(string $line, ?string $reported): bool
    {
        $rule = self::digits($line);
        $said = ltrim(self::digits($reported), '0');
        if ($rule === '' || $said === '') {
            return false;
        }

        return str_ends_with($rule, $said) && strlen($said) >= strlen($rule) - self::COUNTRY_CODE_MAX_DIGITS;
    }

    /** Whether a caller's number starts with this start ("+44", or a whole number). */
    public static function startsWith(?string $caller, string $start): bool
    {
        $number = self::digits($caller);
        $start = self::digits($start);

        return $number !== '' && $start !== '' && str_starts_with($number, $start);
    }

    public static function inHours(RoutingRule $rule, CarbonInterface $at): bool
    {
        $days = array_map('intval', (array) $rule->days);
        $local = $at->copy()->setTimezone($rule->timezone ?: 'UTC');
        $on = fn (CarbonInterface $day) => $days === [] || in_array($day->dayOfWeekIso, $days, true);
        if ($rule->from_time === null || $rule->until_time === null) {
            return $on($local);
        }
        $now = $local->format('H:i');
        if ($rule->from_time < $rule->until_time) {
            return $on($local) && $now >= $rule->from_time && $now < $rule->until_time;
        }

        return ($now >= $rule->from_time && $on($local)) || ($now < $rule->until_time && $on($local->copy()->subDay()));
    }

    /** What Telenow is answered for this rule, or for none (an object either way: Telenow refuses anything else). */
    public static function answer(?RoutingRule $rule): object
    {
        if ($rule === null) {
            return new \stdClass;
        }
        if ($rule->action === 'refuse') {
            return (object) ['action' => 'reject'];
        }
        $answer = ['action' => 'route'];
        $agent = $rule->agent;
        if ($agent !== null && $agent->deleted_at === null && is_string($agent->telenow_id) && $agent->telenow_id !== '') {
            $answer['agent_id'] = $agent->telenow_id;
        }
        $overrides = array_filter(['language' => $rule->language, 'opener' => $rule->greeting], fn ($v) => is_string($v) && $v !== '');
        if ($overrides !== []) {
            $answer['overrides'] = $overrides;
        }
        if ($rule->max_minutes !== null) {
            $answer['limits'] = ['max_duration_secs' => $rule->max_minutes * 60];
        }

        return (object) $answer;
    }

    private static function digits(?string $number): string
    {
        return (string) preg_replace('/\D/', '', (string) $number);
    }
}
