<?php

namespace App\Kit\Exports;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A spreadsheet file streamed to the browser a row at a time (the calls file, the do-not-call list, consent records): a byte-order mark first,
 * as without it a spreadsheet misreads every name not in plain English, and every cell a spreadsheet would run as a formula written as text.
 * The same rules as the campaign people file (App\Kit\Campaigns\CampaignPeople::export).
 *
 * Not here: what goes in each file (its exporter), the words of its header (the lang files).
 */
final class Csv
{
    /** @param list<string> $header @param iterable<list<mixed>> $rows */
    public static function download(string $name, array $header, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(self::cell(...), $header), escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::cell(...), $row), escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    /** A cell a spreadsheet would run as a formula is written as text. A number, with its leading +, is left as one. */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 && preg_match('/^[+\-]?[\d\s().\-]+$/', $value) !== 1 ? "'{$value}" : $value;
    }
}
