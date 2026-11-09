<?php

namespace App\Kit\Calls;

use App\Kit\Exports\Csv;
use App\Models\Call;
use App\Models\Workspace;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An account's calls as a spreadsheet (contract/kit-api.yaml exportCalls), newest first, with the list's filters (CallFilters): when each
 * started (in the viewer's time zone), its agent, direction and channel, the numbers, how long it lasted, its outcome, who answered, its
 * sentiment and summary, and what the account was charged, in its currency. From the kit's own copy, a page at a time: nothing of the voice
 * platform is in it.
 *
 * Not here: reading calls (Calls), the list (CallsController).
 */
final class CallsExport
{
    public function download(Workspace $workspace, array $query): StreamedResponse
    {
        $calls = Call::query()->with('agent')->where('workspace_id', $workspace->id);
        $period = CallFilters::apply($calls, $query);
        $zone = $period['timeZone'] ?? (is_string($query['timeZone'] ?? null) && in_array($query['timeZone'], \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true) ? $query['timeZone'] : 'UTC');
        $words = (array) __('calls.export_columns');
        $header = array_map(fn (string $key) => $words[$key], ['started', 'agent', 'direction', 'channel', 'from', 'to', 'seconds', 'outcome', 'answeredBy', 'sentiment', 'summary', 'charge', 'currency']);
        $rows = (function () use ($calls, $zone) {
            foreach ($calls->orderByDesc('started_at')->orderByDesc('id')->lazy(config('kit.insights.chunk')) as $call) {
                $charged = $call->charge_state === 'charged';
                yield [
                    $call->started_at?->setTimezone($zone)->format('Y-m-d H:i:s'),
                    $call->agent?->name,
                    $call->direction,
                    $call->channel,
                    $call->from_number,
                    $call->to_number,
                    $call->duration_sec,
                    $call->outcome,
                    $call->answered_by,
                    $call->sentiment,
                    is_string($call->analysis['summary'] ?? null) ? $call->analysis['summary'] : '',
                    $charged ? $call->charge_amount : '',
                    $charged ? $call->charge_currency : '',
                ];
            }
        })();

        return Csv::download(__('calls.export_file').'-'.now($zone)->format('Y-m-d').'.csv', $header, $rows);
    }
}
