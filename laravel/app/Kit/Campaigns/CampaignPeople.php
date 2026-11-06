<?php

namespace App\Kit\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignTarget;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A campaign's people as a customer reads them, from the kit's own copy: where each stands, how many stand where, one group at a time, and the same as a file to download.
 *
 * Telenow's outcome is an open set that can hold a carrier's own words ("insufficient balance", recorded), which a customer would read as being about their own balance. So only the outcomes in
 * config('kit.campaigns') reach them, and any other reads as failed. A person being called again waits, whatever their last call did.
 *
 * Not here: reading the results (Campaigns::refresh), trying people again (CampaignRetries).
 */
final class CampaignPeople
{
    /** @return array{waiting: int, answered: int, unanswered: int, failed: int} */
    public function counts(Campaign $campaign): array
    {
        [$answered, $unanswered] = $this->outcomes();
        $marks = implode(',', array_fill(0, count($unanswered), '?'));
        $row = CampaignTarget::query()->where('campaign_id', $campaign->id)->selectRaw(
            'SUM(CASE WHEN completed_at IS NULL OR retried_at IS NOT NULL THEN 1 ELSE 0 END) AS waiting,'
            .' SUM(CASE WHEN completed_at IS NOT NULL AND retried_at IS NULL AND outcome = ? THEN 1 ELSE 0 END) AS answered,'
            ." SUM(CASE WHEN completed_at IS NOT NULL AND retried_at IS NULL AND outcome IN ({$marks}) THEN 1 ELSE 0 END) AS unanswered,"
            .' COUNT(*) AS total',
            [$answered, ...$unanswered],
        )->toBase()->first();
        $counts = ['waiting' => (int) $row->waiting, 'answered' => (int) $row->answered, 'unanswered' => (int) $row->unanswered];

        return $counts + ['failed' => (int) $row->total - array_sum($counts)];
    }

    /** One group of people, in the order they were added; `null` for all of them. */
    public function page(Campaign $campaign, ?string $show, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->people($campaign, $show)->orderBy('created_at')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return array<string, mixed> */
    public function row(CampaignTarget $target): array
    {
        $state = $this->state($target);

        return [
            'id' => $target->id, 'phoneNumber' => $target->phone_number, 'variables' => (object) ($target->variables ?? []),
            'state' => $state,
            'outcome' => $state === 'done' ? $this->outcome($target) : null,
            'attempts' => $state === 'done' ? $target->attempt : null,
            'completedAt' => $state === 'done' ? $target->completed_at?->toIso8601String() : null,
            'tryingAgain' => $target->retried_at !== null,
        ];
    }

    /** The group as a spreadsheet: a list a campaign can take back, with what each column of theirs held beside how the call went. Read a page at a time, so a long list is not held in memory. */
    public function export(Campaign $campaign, ?string $show): StreamedResponse
    {
        $name = __('campaigns.export_file').'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($campaign, $show) {
            $variables = [];
            foreach ($this->people($campaign, $show)->whereNotNull('variables')->orderBy('created_at')->orderBy('id')->lazy(config('kit.campaigns.export_chunk')) as $target) {
                $variables += array_fill_keys(array_keys((array) $target->variables), true);
            }
            $variables = array_map('strval', array_keys($variables));
            $words = (array) __('campaigns.export_columns');
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // Without it a spreadsheet misreads every name not in plain English.
            fputcsv($out, array_map($this->cell(...), [config('kit.campaigns.phone_columns')[0], $words['outcome'], $words['attempts'], $words['finished'], ...$variables]), escape: '');
            foreach ($this->people($campaign, $show)->orderBy('created_at')->orderBy('id')->lazy(config('kit.campaigns.export_chunk')) as $target) {
                $done = $this->state($target) === 'done';
                $held = (array) $target->variables;
                fputcsv($out, array_map($this->cell(...), [
                    $target->phone_number,
                    __('campaigns.outcome_words')[$done ? $this->outcome($target) : 'waiting'],
                    $done ? $target->attempt : '',
                    $done ? $target->completed_at?->toIso8601String() : '',
                    ...array_map(fn (string $name) => $held[$name] ?? '', $variables),
                ]), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** A cell a spreadsheet would run as a formula is written as text. A number, with its leading +, is left as one. */
    private function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && preg_match('/^[+\-]?[\d\s().\-]+$/', $value) !== 1 ? "'{$value}" : $value;
    }

    private function people(Campaign $campaign, ?string $show): Builder
    {
        [$answered, $unanswered] = $this->outcomes();
        $query = CampaignTarget::query()->where('campaign_id', $campaign->id);
        $finished = fn (Builder $q) => $q->whereNotNull('completed_at')->whereNull('retried_at');

        return match ($show) {
            'waiting' => $query->where(fn (Builder $q) => $q->whereNull('completed_at')->orWhereNotNull('retried_at')),
            'answered' => $query->where($finished)->where('outcome', $answered),
            'unanswered' => $query->where($finished)->whereIn('outcome', $unanswered),
            'failed' => $query->where($finished)->where(fn (Builder $q) => $q->whereNull('outcome')->orWhereNotIn('outcome', [$answered, ...$unanswered])),
            default => $query,
        };
    }

    private function state(CampaignTarget $target): string
    {
        return $target->pushed_at === null ? 'saving' : ($target->completed_at === null || $target->retried_at !== null ? 'waiting' : 'done');
    }

    private function outcome(CampaignTarget $target): string
    {
        [$answered, $unanswered] = $this->outcomes();

        return in_array($target->outcome, [$answered, ...$unanswered], true) ? $target->outcome : config('kit.campaigns.failed_outcome');
    }

    /** @return array{0: string, 1: list<string>} */
    private function outcomes(): array
    {
        return [config('kit.campaigns.answered_outcome'), config('kit.campaigns.unanswered_outcomes')];
    }
}
