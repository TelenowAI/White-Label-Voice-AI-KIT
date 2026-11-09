<?php

namespace App\Kit\Reports;

use App\Kit\Calls\CallFilters;
use App\Models\Agent;
use App\Models\Call;
use App\Models\Workspace;
use Brick\Math\BigDecimal;

/**
 * How an account's calls went over a period (contract/kit-api.yaml getInsights; console Insights): how many, how many a person was on, how
 * long they lasted, their outcomes, sentiments and channels, what the account was charged in each currency, each day of the period (days with
 * no call too) and each agent. From the kit's own copy of the calls, read once, a page at a time and only the columns it needs, so the figures
 * are the same as the calls list and the calls file; amounts are added as decimals, never as floating point.
 *
 * Not here: the operator's reports (Dashboard, CostReport), reading calls (App\Kit\Calls\Calls).
 */
final class Insights
{
    /** @return array<string, mixed> the period's figures (Insights) */
    public function of(Workspace $workspace, array $query): array
    {
        $calls = Call::query()->where('workspace_id', $workspace->id);
        $period = CallFilters::apply($calls, array_intersect_key($query, array_flip(['agentId', 'from', 'to', 'timeZone'])), always: true);
        $zone = $period['timeZone'];
        $days = [];
        for ($day = $period['from']; $day->lte($period['to']); $day = $day->addDay()) {
            $days[$day->format('Y-m-d')] = ['day' => $day->format('Y-m-d'), 'calls' => 0, 'answered' => 0, 'seconds' => 0];
        }
        $total = ['calls' => 0, 'answered' => 0, 'seconds' => 0, 'answeredSeconds' => 0];
        $counts = ['outcomes' => [], 'sentiments' => [], 'channels' => []];
        $spend = [];
        $agents = [];
        $columns = ['id', 'agent_id', 'started_at', 'duration_sec', 'answered_by', 'outcome', 'sentiment', 'channel', 'charge_state', 'charge_amount', 'charge_currency'];
        foreach ($calls->select($columns)->lazyById(config('kit.insights.chunk')) as $call) {
            $seconds = (int) $call->duration_sec;
            $answered = CallFilters::answered($call->duration_sec, $call->answered_by);
            $total['calls']++;
            $total['seconds'] += $seconds;
            if ($answered) {
                $total['answered']++;
                $total['answeredSeconds'] += $seconds;
            }
            $day = $call->started_at?->setTimezone($zone)->format('Y-m-d');
            if (isset($days[$day])) {
                $days[$day]['calls']++;
                $days[$day]['answered'] += $answered ? 1 : 0;
                $days[$day]['seconds'] += $seconds;
            }
            foreach (['outcomes' => $call->outcome, 'channels' => $call->channel] as $kind => $key) {
                $counts[$kind][(string) $key] = ($counts[$kind][(string) $key] ?? 0) + 1;
            }
            if ($call->sentiment !== null) {
                $counts['sentiments'][$call->sentiment] = ($counts['sentiments'][$call->sentiment] ?? 0) + 1;
            }
            $agent = (string) $call->agent_id;
            $agents[$agent] ??= ['calls' => 0, 'answered' => 0, 'seconds' => 0, 'spend' => []];
            $agents[$agent]['calls']++;
            $agents[$agent]['answered'] += $answered ? 1 : 0;
            $agents[$agent]['seconds'] += $seconds;
            if ($call->charge_state === 'charged' && is_string($call->charge_currency) && $call->charge_amount !== null) {
                $amount = BigDecimal::of((string) $call->charge_amount);
                $spend[$call->charge_currency] = ($spend[$call->charge_currency] ?? BigDecimal::zero())->plus($amount);
                $agents[$agent]['spend'][$call->charge_currency] = ($agents[$agent]['spend'][$call->charge_currency] ?? BigDecimal::zero())->plus($amount);
            }
        }
        $names = Agent::query()->whereIn('id', array_filter(array_keys($agents)))->pluck('name', 'id');
        $rows = [];
        foreach ($agents as $id => $figures) {
            $rows[] = ['agentId' => $id === '' ? null : $id, 'name' => $id === '' ? null : $names[$id] ?? null, 'calls' => $figures['calls'], 'answered' => $figures['answered'],
                'seconds' => $figures['seconds'], 'spend' => self::money($figures['spend'])];
        }
        usort($rows, fn (array $a, array $b) => [$b['calls'], (string) $a['name']] <=> [$a['calls'], (string) $b['name']]);

        return [
            'from' => $period['from']->format('Y-m-d'),
            'to' => $period['to']->format('Y-m-d'),
            'timeZone' => $zone,
            'calls' => $total['calls'],
            'answered' => $total['answered'],
            'seconds' => $total['seconds'],
            'averageSeconds' => $total['answered'] === 0 ? 0 : intdiv($total['answeredSeconds'], $total['answered']),
            'outcomes' => self::counted($counts['outcomes']),
            'sentiments' => self::counted($counts['sentiments']),
            'channels' => self::counted($counts['channels']),
            'spend' => self::money($spend),
            'days' => array_values($days),
            'agents' => $rows,
        ];
    }

    /** @param array<string, int> $counts @return list<array{key: ?string, count: int}> most first, then by name; '' (none) as null */
    private static function counted(array $counts): array
    {
        $list = [];
        foreach ($counts as $key => $count) {
            $list[] = ['key' => $key === '' ? null : (string) $key, 'count' => $count];
        }
        usort($list, fn (array $a, array $b) => [$b['count'], (string) $a['key']] <=> [$a['count'], (string) $b['key']]);

        return $list;
    }

    /** @param array<string, BigDecimal> $totals @return list<array{currency: string, amount: string}> by currency */
    private static function money(array $totals): array
    {
        ksort($totals);

        return array_map(fn (string $currency, BigDecimal $amount) => ['currency' => $currency, 'amount' => (string) $amount], array_keys($totals), array_values($totals));
    }
}
