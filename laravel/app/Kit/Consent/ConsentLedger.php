<?php

namespace App\Kit\Consent;

use App\Kit\Consent\Telenow\ConsentWrite;
use App\Kit\Outbox\Outbox;
use App\Kit\Outbox\OutboxEntry;
use App\Models\ConsentRecord;
use App\Models\DncEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A customer's record of who agreed to be called (console → Consent; contract/resources.yaml `consent`). Telenow holds the records its dialler checks, and has no list of them, so the kit keeps its own copy for the page. A record is never changed or
 * removed: it is what the customer shows after a complaint. It can only be withdrawn.
 *
 * - record(), import(): saved here, then sent through the outbox (Telenow\PushConsentAtTelenow). Telenow stores a date in the future, or an end before the start, without saying, so they are refused here; it matches a call by the number exactly, and +1415…
 *   and 1415… are two numbers to it (recorded), so a number here always has its + and country code.
 * - withdraw(): by number, as Telenow does: all of that number's records, onto the do-not-call list, with its waiting calls dropped. It has its own place in the outbox, so a long import cannot hold it up. Records of the number not yet sent are never sent.
 *
 * Not here: whether calls need it (ConsentRule), sending (Telenow\*), the page (ConsentController).
 */
final class ConsentLedger
{
    public function __construct(private readonly Outbox $outbox) {}

    public function record(Workspace $workspace, array $input, ?User $by, ?string $ip, ?string $agent): ConsentRecord
    {
        $this->ready($workspace);
        $c = config('kit.consent');
        $values = Validator::make($input, $this->rules($input) + [
            'phoneNumber' => ['required', 'string', 'max:'.$c['phone_max_length'], function (string $field, mixed $value, \Closure $fail) {
                if (! is_string($value) || $this->number($value) === null) {
                    $fail(__('consent.number_needs_plus'));
                }
            }],
            'source' => ['required', Rule::in($c['sources'])],
            'note' => ['nullable', 'string', 'max:'.$c['note_max_length']],
        ], ['disclosureText.required_if' => __('consent.written_needs_words')], __('consent.fields'))->validate();
        $phone = $this->number($values['phoneNumber']);
        if ($this->withdrawing($workspace, $phone)) {
            throw new ConsentRefused(409, __('consent.still_withdrawing'));
        }
        $row = DB::transaction(function () use ($workspace, $values, $phone, $by, $ip, $agent, $c) {
            $row = (new ConsentRecord)->forceFill($this->columns($values) + [
                'workspace_id' => $workspace->id, 'phone_number' => $phone, 'source' => $values['source'], 'note' => $values['note'] ?? null,
                'captured_at' => isset($values['capturedAt']) ? Carbon::parse($values['capturedAt'])->utc() : now(),
                'recorded_by' => $by?->id, 'recorded_ip' => $ip, 'recorded_agent' => $agent === null ? null : mb_substr($agent, 0, $c['note_max_length']),
            ]);
            $row->save();
            $this->queue($workspace);

            return $row;
        });
        $this->sendIfFew($workspace);

        return $row->fresh();
    }

    /**
     * Each line one person, on the file's kind of consent and words. Saved here and sent by `kit:outbox`: one record is one request, so a request does not wait for them.
     *
     * @return array{added: int, skipped: int, duplicates: int} skipped: no + and country code, no date, or still being withdrawn; duplicates: already on file, withdrawn or not
     */
    public function import(Workspace $workspace, array $input, ?User $by, ?string $ip, ?string $agent): array
    {
        $this->ready($workspace);
        $c = config('kit.consent');
        $values = Validator::make($input, $this->rules($input) + ['csv' => ['required', 'string', 'max:'.$c['import_max_bytes']]],
            ['disclosureText.required_if' => __('consent.written_needs_words')], __('consent.fields'))->validate();
        $lines = preg_split('/\r\n|\n|\r/', $values['csv']);
        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv((string) array_shift($lines), escape: '\\'));
        $phoneAt = null;
        foreach (config('kit.campaigns.phone_columns') as $name) {
            if (($at = array_search($name, $header, true)) !== false) {
                $phoneAt = $at;
                break;
            }
        }
        if ($phoneAt === null) {
            throw ValidationException::withMessages(['csv' => [__('consent.no_phone_column', ['columns' => implode(', ', config('kit.campaigns.phone_columns'))])]]);
        }
        $capturedAt = array_search($c['captured_column'], $header, true);
        $expiresAt = array_search($c['expires_column'], $header, true);
        if ($capturedAt === false && ! isset($values['capturedAt'])) {
            throw ValidationException::withMessages(['capturedAt' => [__('consent.import_needs_date', ['column' => $c['captured_column']])]]);
        }
        $common = $this->columns($values) + ['workspace_id' => $workspace->id, 'source' => $c['import_source'], 'recorded_by' => $by?->id, 'recorded_ip' => $ip,
            'recorded_agent' => $agent === null ? null : mb_substr($agent, 0, $c['note_max_length'])];
        [$rows, $skipped, $duplicates, $seen] = [[], 0, 0, []];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, escape: '\\');
            $phone = $this->number((string) ($cells[$phoneAt] ?? ''));
            $captured = $this->date($capturedAt === false ? null : ($cells[$capturedAt] ?? null)) ?? (isset($values['capturedAt']) ? Carbon::parse($values['capturedAt'])->utc() : null);
            $expires = $this->date($expiresAt === false ? null : ($cells[$expiresAt] ?? null)) ?? (isset($values['expiresAt']) ? Carbon::parse($values['expiresAt'])->utc() : null);
            if ($phone === null || $captured === null || $captured->isFuture() || ($expires !== null && ! $expires->gt($captured))) {
                $skipped++;

                continue;
            }
            $same = $phone.'|'.$captured->toIso8601String();
            if (isset($seen[$same])) {
                $duplicates++;

                continue;
            }
            $seen[$same] = true;
            $rows[] = ['phone' => $phone, 'captured' => $captured, 'expires' => $expires];
        }
        if (count($rows) + $skipped + $duplicates > $c['import_max_rows']) {
            throw ValidationException::withMessages(['csv' => [__('consent.too_many_rows', ['max' => $c['import_max_rows']])]]);
        }
        $added = 0;
        foreach (array_chunk($rows, $c['insert_batch']) as $chunk) {
            $phones = array_column($chunk, 'phone');
            // Withdrawn ones too: an old file sent again must not bring back someone who has since said no.
            $held = ConsentRecord::query()->where('workspace_id', $workspace->id)->whereIn('phone_number', $phones)
                ->where('consent_type', $common['consent_type'])->where('scope', $common['scope'])->get(['phone_number', 'captured_at'])
                ->mapWithKeys(fn (ConsentRecord $r) => [$r->phone_number.'|'.$r->captured_at->toIso8601String() => true]);
            $going = ConsentRecord::query()->where('workspace_id', $workspace->id)->whereIn('phone_number', $phones)->whereNotNull('revoked_at')->whereNull('revoke_sent_at')->pluck('phone_number')->flip();
            DB::transaction(function () use ($chunk, $held, $going, $common, $workspace, &$added, &$skipped, &$duplicates) {
                $now = now();
                $insert = [];
                foreach ($chunk as $r) {
                    if ($going->has($r['phone'])) {
                        $skipped++;
                    } elseif ($held->has($r['phone'].'|'.$r['captured']->toIso8601String())) {
                        $duplicates++;
                    } else {
                        $insert[] = ['id' => (string) Str::uuid(), 'phone_number' => $r['phone'], 'captured_at' => $r['captured'], 'expires_at' => $r['expires'], 'sync_state' => 'pending', 'created_at' => $now, 'updated_at' => $now] + $common;
                    }
                }
                if ($insert !== []) {
                    ConsentRecord::query()->insert($insert);
                    $added += count($insert);
                    $this->queue($workspace);
                }
            });
        }

        return ['added' => $added, 'skipped' => $skipped, 'duplicates' => $duplicates];
    }

    /** Withdraw all of a number's consent, whatever its records say: it goes onto the do-not-call list. */
    public function withdraw(Workspace $workspace, array $input, ?User $by): void
    {
        $this->ready($workspace);
        $c = config('kit.consent');
        $values = Validator::make($input, ['phoneNumber' => ['required', 'string', 'max:'.$c['phone_max_length']], 'note' => ['nullable', 'string', 'max:'.$c['note_max_length']]], [], __('consent.fields'))->validate();
        $phone = $this->number($values['phoneNumber']);
        $live = $phone === null ? collect() : ConsentRecord::query()->where('workspace_id', $workspace->id)->where('phone_number', $phone)->whereNull('revoked_at')->orderByDesc('created_at')->orderByDesc('id')->get();
        if ($live->isEmpty()) {
            throw new ConsentRefused(404, __('consent.nothing_to_withdraw'));
        }
        $first = $live->first();
        DB::transaction(function () use ($workspace, $live, $first, $phone, $values, $by) {
            ConsentRecord::query()->whereIn('id', $live->pluck('id'))->update(['revoked_at' => now(), 'revoke_note' => $values['note'] ?? null, 'revoked_by' => $by?->id, 'updated_at' => now()]);
            // One not sent yet never will be.
            ConsentRecord::query()->whereIn('id', $live->pluck('id'))->where('sync_state', 'pending')->update(['sync_state' => 'synced', 'sync_error' => null]);
            $this->outbox->enqueue(ConsentWrite::RESOURCE, $first->id, 'revoke', ['phone' => $phone, 'note' => $values['note'] ?? null, 'workspaceId' => $workspace->id]);
        });
        $this->outbox->runFor(ConsentWrite::RESOURCE, $first->id);
    }

    /** One page, newest first, by any part of the number. */
    public function page(Workspace $workspace, string $search, int $page, int $perPage): LengthAwarePaginator
    {
        $digits = preg_replace('/\D/', '', $search);

        return ConsentRecord::query()->with('recorder')->where('workspace_id', $workspace->id)
            ->when($digits !== '', fn ($q) => $q->whereLike('phone_number', "%{$digits}%"))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return array<int, array<string, mixed>> as a customer reads them: a number on the do-not-call list is not called, whatever its consent says */
    public function present(iterable $rows, string $workspaceId): array
    {
        $rows = collect($rows);
        $listed = DncEntry::query()->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereIn('phone_number', $rows->pluck('phone_number')->unique()->values())->pluck('phone_number')->flip();

        return $rows->map(fn (ConsentRecord $r) => [
            'id' => $r->id, 'phoneNumber' => $r->phone_number, 'consentType' => $r->consent_type, 'scope' => $r->scope, 'source' => $r->source,
            'disclosureText' => $r->disclosure_text, 'disclosureVersion' => $r->disclosure_version, 'note' => $r->note,
            'capturedAt' => $r->captured_at->toIso8601String(), 'expiresAt' => $r->expires_at?->toIso8601String(),
            'state' => $r->revoked_at !== null ? 'withdrawn' : ($r->expires_at !== null && $r->expires_at->isPast() ? 'expired' : 'live'),
            'withdrawnAt' => $r->revoked_at?->toIso8601String(), 'withdrawnNote' => $r->revoke_note,
            'onDoNotCall' => $listed->has($r->phone_number),
            'recordedBy' => $r->recorder?->name,
            'syncState' => $r->sync_state, 'syncError' => $r->sync_error,
            'createdAt' => $r->created_at->toIso8601String(),
        ])->values()->all();
    }

    /** Writes given up long enough ago: a legal record must arrive. */
    public function retryGivenUp(): int
    {
        $c = config('kit.consent');
        $due = OutboxEntry::query()->whereIn('resource', [ConsentWrite::RESOURCE, ConsentWrite::RULE_RESOURCE])->whereNull('done_at')->whereNotNull('failed_at')
            ->where('failed_at', '<=', now()->subMinutes($c['retry_given_up_minutes']))->orderBy('failed_at')->limit($c['retry_given_up_per_run'])->get();
        foreach ($due as $entry) {
            $this->outbox->retry($entry->resource, $entry->kit_id);
        }

        return $due->count();
    }

    /** What a record and a file share. */
    private function rules(array $input): array
    {
        $c = config('kit.consent');
        $hasDate = is_string($input['capturedAt'] ?? null) && $input['capturedAt'] !== '';

        return [
            'consentType' => ['required', Rule::in($c['types'])],
            'scope' => ['nullable', Rule::in($c['scopes'])],
            'disclosureText' => ['nullable', 'string', 'max:'.$c['text_max_length'], 'required_if:consentType,'.$c['written_type']],
            'disclosureVersion' => ['nullable', 'string', 'max:'.$c['version_max_length']],
            'capturedAt' => ['nullable', 'date', 'before_or_equal:now'],
            'expiresAt' => ['nullable', 'date', $hasDate ? 'after:capturedAt' : 'after:now'],
        ];
    }

    /** @return array<string, mixed> */
    private function columns(array $values): array
    {
        return [
            'consent_type' => $values['consentType'], 'scope' => $values['scope'] ?? config('kit.consent.default_scope'),
            'disclosure_text' => $values['disclosureText'] ?? null, 'disclosure_version' => $values['disclosureVersion'] ?? null,
            'expires_at' => isset($values['expiresAt']) ? Carbon::parse($values['expiresAt'])->utc() : null,
        ];
    }

    /** The number as Telenow keeps it, with its + and country code, or null. */
    private function number(string $raw): ?string
    {
        $raw = trim($raw);
        $digits = preg_replace('/\D/', '', $raw);
        $limits = config('kit.consent.phone_digits');
        if (! str_starts_with($raw, '+') || strlen($digits) < $limits['min'] || strlen($digits) > $limits['max']) {
            return null;
        }

        return "+{$digits}";
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse(trim($value))->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function withdrawing(Workspace $workspace, string $phone): bool
    {
        return ConsentRecord::query()->where('workspace_id', $workspace->id)->where('phone_number', $phone)->whereNotNull('revoked_at')->whereNull('revoke_sent_at')->exists();
    }

    /** One write sends all that are waiting, so one is enough. */
    private function queue(Workspace $workspace): void
    {
        $waiting = OutboxEntry::query()->where('resource', ConsentWrite::RESOURCE)->where('kit_id', $workspace->id)->whereNull('done_at')->exists();
        if (! $waiting) {
            $this->outbox->enqueue(ConsentWrite::RESOURCE, $workspace->id, 'create', null);
        }
    }

    private function sendIfFew(Workspace $workspace): void
    {
        $ahead = ConsentRecord::query()->where('workspace_id', $workspace->id)->where('sync_state', 'pending')->limit(config('kit.consent.send_at_once_max') + 1)->count();
        if ($ahead > config('kit.consent.send_at_once_max')) {
            return;
        }
        if ($this->outbox->hasFailed(ConsentWrite::RESOURCE, $workspace->id)) {
            $this->outbox->retry(ConsentWrite::RESOURCE, $workspace->id);

            return;
        }
        $this->outbox->runFor(ConsentWrite::RESOURCE, $workspace->id);
    }

    private function ready(Workspace $workspace): void
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '' || ! is_string($workspace->telenow_sub_account_id)) {
            throw new ConsentRefused(409, __('consent.account_not_ready'));
        }
    }
}
