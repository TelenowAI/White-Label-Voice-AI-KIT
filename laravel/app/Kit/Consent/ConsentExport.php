<?php

namespace App\Kit\Consent;

use App\Kit\Exports\Csv;
use App\Models\ConsentRecord;
use App\Models\Workspace;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An account's consent records as a spreadsheet (contract/kit-api.yaml exportConsent), withdrawn ones too, newest first, a page at a time: each
 * number, the kind of consent and what it covers, where it came from, the disclosure's version, when it was given, ends and was withdrawn, and
 * the note. Not who recorded it, nor from where. Not here: the ledger itself (ConsentLedger).
 */
final class ConsentExport
{
    public function download(Workspace $workspace): StreamedResponse
    {
        $words = (array) __('consent.export_columns');
        $rows = (function () use ($workspace) {
            $records = ConsentRecord::query()->where('workspace_id', $workspace->id)->orderByDesc('captured_at')->orderByDesc('id');
            foreach ($records->lazy(config('kit.insights.chunk')) as $r) {
                yield [$r->phone_number, $r->consent_type, $r->scope, $r->source, $r->disclosure_version, $r->captured_at?->toIso8601String(),
                    $r->expires_at?->toIso8601String(), $r->revoked_at?->toIso8601String(), $r->note];
            }
        })();
        $header = array_map(fn (string $k) => $words[$k], ['number', 'type', 'scope', 'source', 'disclosureVersion', 'given', 'expires', 'withdrawn', 'note']);

        return Csv::download(__('consent.export_file').'-'.now()->format('Y-m-d').'.csv', $header, $rows);
    }
}
