<?php

namespace App\Kit\Dnc;

use App\Kit\Exports\Csv;
use App\Models\DncEntry;
use App\Models\Workspace;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** An account's do-not-call list as a spreadsheet (contract/kit-api.yaml exportDnc), from the kit's own copy, newest first, a page at a time. Not here: the list itself (Dnc). */
final class DncExport
{
    public function download(Workspace $workspace): StreamedResponse
    {
        $words = (array) __('dnc.export_columns');
        $rows = (function () use ($workspace) {
            $entries = DncEntry::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->orderByDesc('created_at')->orderByDesc('id');
            foreach ($entries->lazy(config('kit.insights.chunk')) as $entry) {
                yield [$entry->phone_number, $entry->reason, $entry->source, $entry->created_at?->toIso8601String()];
            }
        })();

        return Csv::download(__('dnc.export_file').'-'.now()->format('Y-m-d').'.csv', [$words['number'], $words['reason'], $words['source'], $words['added']], $rows);
    }
}
