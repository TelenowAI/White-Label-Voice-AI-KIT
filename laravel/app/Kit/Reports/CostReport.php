<?php

namespace App\Kit\Reports;

use App\Models\PriceList;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Admin → Cost vs price (SELF_HOSTED_KIT_PLAN.md §4.6 c): over a Period, the customers' price beside what Telenow
 * charged the operator, and the margin, grouped by customer, by what was charged (a priced line's subject: a call all
 * in, a call's component, a number fee), by plan or by day (config('kit.reports.groups')), with the totals — of every
 * customer, or of one (`customer`: their components, say). Read from
 * the daily totals (App\Kit\Wallet\ChargeRollups), a few rows at a time, so the cost of a report is days × customers
 * whatever the number of calls. A call by call view is each customer's wallet (Admin\CustomerWalletController).
 *
 * Used by Admin\ReportsController.
 */
final class CostReport
{
    /** @return array{period: array, by: string, rows: list<array>, totals: list<array>, page: int, perPage: int, total: int} */
    public function build(Period $period, array $input): array
    {
        $values = Validator::make($input, [
            'by' => ['nullable', Rule::in(config('kit.reports.groups'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'customer' => ['nullable', 'string', 'uuid', Rule::exists('workspaces', 'id')],
        ], [], ['customer' => __('reports.fields.customer')])->validate();
        $customer = isset($values['customer']) ? Workspace::query()->find($values['customer']) : null;
        $by = $values['by'] ?? config('kit.reports.groups')[0];
        $groups = [];
        $totals = [];
        $wholes = $by !== 'component';
        $this->read($period, $wholes, function (object $row) use ($by, &$groups) {
            $key = match ($by) {
                'customer' => $row->workspace_id,
                'plan' => $row->plan,
                'day' => $row->day,
                'component' => $row->subject,
            };
            ($groups[$key][$row->currency] ??= new Totals($row->currency))->add($row);
        }, $customer?->id);
        $this->read($period, true, function (object $row) use (&$totals) {
            ($totals[$row->currency] ??= new Totals($row->currency))->add($row);
        }, $customer?->id);

        $rows = [];
        foreach ($groups as $key => $byCurrency) {
            foreach ($byCurrency as $t) {
                $rows[] = ['key' => (string) $key] + $t->toArray() + ['sort' => $t->price()];
            }
        }
        usort($rows, $by === 'day'
            ? fn (array $a, array $b) => strcmp($a['key'], $b['key'])
            : fn (array $a, array $b) => $b['sort']->compareTo($a['sort']) ?: strcmp($a['key'], $b['key']));
        $perPage = config('kit.admin.per_page');
        $page = (int) ($values['page'] ?? 1);
        $shown = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $labels = $this->labels($by, array_column($shown, 'key'));

        return [
            'period' => $period->toArray(),
            'by' => $by,
            'customer' => $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name],
            'rows' => array_map(fn (array $r) => ['key' => $r['key'], 'label' => $labels[$r['key']]] + array_diff_key($r, ['key' => 1, 'sort' => 1]), $shown),
            'totals' => array_values(array_map(fn (Totals $t) => $t->toArray(), $totals)),
            'page' => $page,
            'perPage' => $perPage,
            'total' => count($rows),
        ];
    }

    /** Each daily-total row of the period: the charges as a whole, or their priced lines; one customer's, or every customer's. */
    public function read(Period $period, bool $wholes, callable $each, ?string $customer = null): void
    {
        DB::table('charge_rollups')
            ->whereBetween('day', [$period->from->toDateString(), $period->to->toDateString()])
            ->when($customer !== null, fn ($q) => $q->where('workspace_id', $customer))
            ->when($wholes, fn ($q) => $q->where('subject', ''), fn ($q) => $q->where('subject', '!=', ''))
            ->lazyById(config('kit.reports.read_chunk'))
            ->each($each);
    }

    /** @return array<string, string> each key's words */
    private function labels(string $by, array $keys): array
    {
        return match ($by) {
            'customer' => array_replace(array_fill_keys($keys, __('reports.unknown_customer')),
                Workspace::query()->whereIn('id', $keys)->pluck('name', 'id')->all()),
            'plan' => array_replace(array_combine($keys, array_map(fn (string $id) => $id === '' ? __('reports.no_plan') : $id, $keys)),
                PriceList::query()->whereIn('id', $keys)->pluck('name', 'id')->all()),
            'day' => array_combine($keys, $keys),
            'component' => array_combine($keys, array_map(fn (string $s) => self::subject($s), $keys)),
        };
    }

    /** A price line's subject in words (lang/<locale>/reports.php); one with no words shows as it is. */
    public static function subject(string $subject): string
    {
        [$kind, $name] = array_pad(explode(':', $subject, 2), 2, null);
        $key = match ($kind) {
            'component' => "reports.components.{$name}",
            'fee' => "reports.fees.{$name}",
            default => "reports.subjects.{$subject}",
        };

        return __($key) === $key ? $subject : __($key);
    }
}
