<?php

namespace App\Kit\Dnc;

use App\Kit\Dnc\Telenow\DncWrite;
use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\Outbox;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Call;
use App\Models\DncEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A customer's do-not-call list (console → Do not call; contract/resources.yaml `dnc`). Telenow enforces it — a number on
 * the list is never dialled, and pending campaign calls to it are suppressed — so the kit's copy is for the customer to
 * see and change:
 *
 * - add(): one number, saved here and sent through the outbox at once (Telenow writes the number its own way and says
 *   when it cannot dial it);
 * - remove(): marked deleted here and at Telenow; one Telenow never took (refused) is simply closed;
 * - import(): a CSV straight to Telenow (it alone knows which lines it can dial), answering its added / skipped
 *   counts; the numbers then come back through the feed;
 * - feed(): Telenow's changes since the account's cursor — its own entries (a caller's opt-out, with the call it was
 *   asked on), the import's, and every removal.
 * - readNumber(): one number now, for a change the kit has just caused elsewhere (consent withdrawn).
 *
 * Not here: sending (Telenow\*), what the console shows (DncController).
 */
final class Dnc
{
    public function __construct(private readonly Outbox $outbox, private readonly Manifest $manifest, private readonly TelenowClient $telenow) {}

    public function add(Workspace $workspace, array $input): DncEntry
    {
        $this->ready($workspace);
        $values = Validator::make($input, [
            'phoneNumber' => ['required', 'string', 'max:'.config('kit.dnc.phone_max_length')],
            'reason' => ['nullable', 'string', 'max:'.config('kit.dnc.reason_max_length')],
        ], [], ['phoneNumber' => __('dnc.fields.phoneNumber'), 'reason' => __('dnc.fields.reason')])->validate();
        $columns = ['phone_number' => trim($values['phoneNumber']), 'reason' => $values['reason'] ?? null];
        $row = DB::transaction(function () use ($workspace, $columns) {
            $row = new DncEntry($columns);
            $row->forceFill(['workspace_id' => $workspace->id, 'source' => 'manual', 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(DncWrite::RESOURCE, $row->id, 'create', $this->manifest->writeBody(DncWrite::RESOURCE, 'create', $columns));

            return $row;
        });
        $this->outbox->runFor(DncWrite::RESOURCE, $row->id);
        $row->refresh();

        // Already on the list: the entry the kit had takes the new reason (Telenow\AddAtTelenow) and is the answer.
        return $row->deleted_at !== null
            ? DncEntry::query()->where('workspace_id', $workspace->id)->whereNull('deleted_at')->where('phone_number', $row->phone_number)->first() ?? $row
            : $row;
    }

    public function remove(DncEntry $row): void
    {
        if ($row->telenow_id === null && $row->sync_state === 'failed') {
            $this->outbox->cancel(DncWrite::RESOURCE, $row->id, __('dnc.cancelled'));
            $row->forceFill(['deleted_at' => now(), 'sync_state' => 'synced', 'sync_error' => null])->save();

            return;
        }
        DB::transaction(function () use ($row) {
            $row->forceFill(['deleted_at' => now(), 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(DncWrite::RESOURCE, $row->id, 'delete', null);
        });
        $this->outbox->runFor(DncWrite::RESOURCE, $row->id);
    }

    /** @return array{added: int, skipped: int} */
    public function import(Workspace $workspace, string $csv): array
    {
        [$key, $org] = $this->ready($workspace);
        if (strlen($csv) > config('kit.dnc.import_max_bytes')) {
            throw new DncRefused(422, __('dnc.import_too_big', ['mb' => config('kit.dnc.import_max_bytes') / 1048576]));
        }
        try {
            $counts = $this->telenow->importDnc($key, $org, $csv);
        } catch (TelenowError $e) {
            throw $e->status >= 400 && $e->status < 500 && $e->status !== 429
                ? new DncRefused(422, __('dnc.import_refused'))
                : new DncRefused(503, __('dnc.import_unavailable'));
        }
        try {
            $this->feed($workspace); // the imported numbers, now
        } catch (TelenowError) {
            // they come with the next feed run
        }

        return ['added' => (int) ($counts['added'] ?? 0), 'skipped' => (int) ($counts['skipped'] ?? 0)];
    }

    /** Read the account's DNC feed on from where it stopped. Answers how many changes it saw. */
    public function feed(Workspace $workspace): int
    {
        [$key, $org] = $this->ready($workspace);
        $backfill = $this->manifest->resource(DncWrite::RESOURCE)['backfill'];
        $seen = 0;
        for ($page = 0; $page < config('kit.dnc.feed_pages_per_run'); $page++) {
            $position = $workspace->dnc_cursor !== null
                ? [$backfill['cursorParameter'] => $workspace->dnc_cursor]
                : [$backfill['startParameter'] => $workspace->created_at->utc()->format('Y-m-d\TH:i:s.v\Z')];
            $answer = $this->telenow->dncFeed($key, $org, $position);
            $rows = $answer['entries'] ?? [];
            foreach ($rows as $change) {
                $this->apply($workspace, $change);
                $seen++;
            }
            if (is_string($answer['nextCursor'] ?? null)) {
                $workspace->forceFill(['dnc_cursor' => $answer['nextCursor']])->save();
            }
            if ($rows === []) {
                break;
            }
        }
        $workspace->forceFill(['dnc_synced_at' => now()])->save();

        return $seen;
    }

    /** One number's entry now, by a search of the list: the feed holds back what has only just changed (recorded), so it would not say yet. */
    public function readNumber(Workspace $workspace, string $phone): void
    {
        [$key, $org] = $this->ready($workspace);
        foreach ($this->telenow->dncFeed($key, $org, ['search' => $phone, 'limit' => config('kit.dnc.search_limit')])['entries'] ?? [] as $entry) {
            $this->apply($workspace, $entry);
        }
    }

    /** One change from the feed: a removal marks the kit's row deleted; an entry is kept (Telenow's own ones too). */
    private function apply(Workspace $workspace, array $change): void
    {
        $id = $change['id'] ?? null;
        if (! is_string($id)) {
            return;
        }
        $row = DncEntry::query()->where('telenow_id', $id)->first();
        if (($change['deleted'] ?? false) === true) {
            $row?->forceFill(['deleted_at' => $row->deleted_at ?? now(), 'sync_state' => 'synced', 'telenow_updated_at' => $change['updated_at'] ?? null])->save();

            return;
        }
        if ($row !== null && $row->deleted_at !== null && $row->sync_state === 'pending') {
            return; // the kit is removing it: Telenow's copy is about to go
        }
        $row ??= (new DncEntry)->forceFill(['workspace_id' => $workspace->id, 'telenow_id' => $id]);
        $session = $change['session_id'] ?? null;
        $row->forceFill($this->manifest->columns(DncWrite::RESOURCE, $change) + [
            'call_id' => is_string($session) ? Call::query()->where('workspace_id', $workspace->id)->where('telenow_id', $session)->value('id') : null,
            'deleted_at' => null, 'sync_state' => 'synced', 'sync_error' => null, 'telenow_updated_at' => $change['updated_at'] ?? null,
        ])->save();
    }

    /** @return array{0: string, 1: string} the account's key and sub-account, or the refusal while it is being set up */
    private function ready(Workspace $workspace): array
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '' || ! is_string($workspace->telenow_sub_account_id)) {
            throw new DncRefused(409, __('dnc.account_not_ready'));
        }

        return [$workspace->telenow_key, $workspace->telenow_sub_account_id];
    }
}
