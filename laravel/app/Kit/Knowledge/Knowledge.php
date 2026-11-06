<?php

namespace App\Kit\Knowledge;

use App\Kit\Knowledge\Telenow\BaseWrite;
use App\Kit\Knowledge\Telenow\DocumentWrite;
use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\Outbox;
use App\Kit\Telenow\TelenowClient;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeDocument;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * A customer's knowledge (console → Knowledge; contract/resources.yaml `knowledge_bases`, `knowledge_documents`;
 * SELF_HOSTED_KIT_PLAN.md §3.8, §4.4), written to the kit's DB and the outbox in one transaction, then sent to Telenow at
 * once with the customer's own key:
 *
 * - a base is made, and deleted (every agent stops answering from it);
 * - a document is added to a base as text, a web page (https; Telenow fetches it now and says when it cannot) or a file
 *   (the kinds and size Telenow takes — the size also no more than this host lets PHP receive), and deleted; one
 *   Telenow could not read is tried again;
 * - feed() brings each base's document changes from Telenow: whether a document was read (it reads in the background),
 *   and deletions; bases() brings the bases Telenow has, so one gone there goes here too.
 *
 * Every write to one row says its whole outcome, so it replaces a write Telenow gave up on. Not here: which bases an
 * agent answers from (AgentKnowledge), sending (Telenow/*).
 */
final class Knowledge
{
    public function __construct(private readonly Outbox $outbox, private readonly Manifest $manifest, private readonly TelenowClient $telenow) {}

    public function createBase(Workspace $workspace, array $input): KnowledgeBase
    {
        if ($workspace->telenow_sub_account_id === null) {
            throw new KnowledgeRefused(409, __('knowledge.account_not_ready'));
        }
        $values = $this->check($input, [
            'name' => ['required', 'string', 'max:'.config('kit.knowledge.name_max_length')],
            'description' => ['nullable', 'string', 'max:'.config('kit.knowledge.description_max_length')],
        ]);
        $base = DB::transaction(function () use ($workspace, $values) {
            $base = (new KnowledgeBase)->forceFill(['workspace_id' => $workspace->id, 'name' => $values['name'], 'description' => $values['description'] ?? null]);
            $base->save();
            $this->outbox->enqueue(BaseWrite::RESOURCE, $base->id, 'create',
                $this->manifest->writeBody(BaseWrite::RESOURCE, 'create', ['name' => $base->name, 'description' => $base->description]));

            return $base;
        });
        $this->outbox->runFor(BaseWrite::RESOURCE, $base->id);

        return $base->fresh();
    }

    public function deleteBase(KnowledgeBase $base): void
    {
        DB::transaction(function () use ($base) {
            $base->agents()->detach(); // Telenow detaches it from every agent when it goes
            if ($base->telenow_id === null && $this->outbox->hasFailed(BaseWrite::RESOURCE, $base->id)) {
                $this->outbox->cancel(BaseWrite::RESOURCE, $base->id, __('knowledge.cancelled'));
                $base->forceFill(['deleted_at' => now(), 'sync_state' => 'synced', 'sync_error' => null])->save();

                return;
            }
            $this->replaceGivenUp(BaseWrite::RESOURCE, $base->id);
            $base->forceFill(['deleted_at' => now(), 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(BaseWrite::RESOURCE, $base->id, 'delete', null);
        });
        $this->outbox->runFor(BaseWrite::RESOURCE, $base->id);
    }

    public function addText(KnowledgeBase $base, array $input): KnowledgeDocument
    {
        $values = $this->check($input, [
            'title' => ['required', 'string', 'max:'.config('kit.knowledge.title_max_length')],
            'text' => ['required', 'string', 'max:'.config('kit.knowledge.text_max_length')],
        ]);

        return $this->add($base, ['title' => $values['title'], 'source_type' => 'inline'], ['kind' => 'text', 'title' => $values['title'], 'text' => $values['text']]);
    }

    public function addPage(KnowledgeBase $base, array $input): KnowledgeDocument
    {
        $values = $this->check($input, [
            'url' => ['required', 'string', 'url', 'starts_with:https://', 'max:2048'],
            'title' => ['nullable', 'string', 'max:'.config('kit.knowledge.title_max_length')],
        ], ['url.starts_with' => __('knowledge.https_only')]);

        return $this->add($base, ['title' => $values['title'] ?? $values['url'], 'source_type' => 'url', 'source_uri' => $values['url']],
            ['kind' => 'url', 'url' => $values['url'], 'title' => $values['title'] ?? null]);
    }

    public function addFile(KnowledgeBase $base, array $input): KnowledgeDocument
    {
        $values = $this->check($input, [
            'file' => ['required', 'file', 'max:'.intdiv(self::fileMaxBytes(), 1024), 'extensions:'.implode(',', config('kit.knowledge.file_types'))],
            'title' => ['nullable', 'string', 'max:'.config('kit.knowledge.title_max_length')],
        ]);
        /** @var UploadedFile $file */
        $file = $values['file'];
        $name = $file->getClientOriginalName();
        $doc = (new KnowledgeDocument)->forceFill(['id' => (string) \Illuminate\Support\Str::uuid()]);
        $path = $file->storeAs('knowledge-uploads', $doc->id, config('kit.knowledge.upload_disk'));

        return $this->add($base, ['id' => $doc->id, 'title' => $values['title'] ?? pathinfo($name, PATHINFO_FILENAME), 'source_type' => 'upload', 'source_uri' => $name],
            ['kind' => 'file', 'path' => $path, 'name' => $name, 'title' => $values['title'] ?? null]);
    }

    public function retry(KnowledgeDocument $doc): KnowledgeDocument
    {
        if ($doc->sync_state === 'failed') {
            $doc->forceFill(['sync_state' => 'pending'])->save();
            $this->outbox->retry(DocumentWrite::RESOURCE, $doc->id);

            return $doc->fresh();
        }
        if ($doc->telenow_id === null || ! in_array($doc->status, config('kit.knowledge.statuses.failed'), true)) {
            throw new KnowledgeRefused(409, __('knowledge.nothing_to_retry'));
        }
        DB::transaction(function () use ($doc) {
            $doc->forceFill(['sync_state' => 'pending'])->save();
            $this->outbox->enqueue(DocumentWrite::RESOURCE, $doc->id, 'retry', null);
        });
        $this->outbox->runFor(DocumentWrite::RESOURCE, $doc->id);

        return $doc->fresh();
    }

    public function deleteDocument(KnowledgeDocument $doc): void
    {
        if ($doc->telenow_id === null && $this->outbox->hasFailed(DocumentWrite::RESOURCE, $doc->id)) {
            $this->outbox->cancel(DocumentWrite::RESOURCE, $doc->id, __('knowledge.cancelled'));
            $doc->forceFill(['deleted_at' => now(), 'sync_state' => 'synced', 'sync_error' => null])->save();

            return;
        }
        DB::transaction(function () use ($doc) {
            $this->replaceGivenUp(DocumentWrite::RESOURCE, $doc->id);
            $doc->forceFill(['deleted_at' => now(), 'sync_state' => 'pending'])->save();
            $this->outbox->enqueue(DocumentWrite::RESOURCE, $doc->id, 'delete', null);
        });
        $this->outbox->runFor(DocumentWrite::RESOURCE, $doc->id);
    }

    /** Every document of the base changed at Telenow since the last read (deletions too). Answers how many it saw. */
    public function feed(KnowledgeBase $base): int
    {
        [$key, $org] = BaseWrite::account($base->workspace);
        $after = $base->documents_after ?? $base->created_at->utc()->format('Y-m-d\TH:i:s.v\Z');
        $rows = $this->telenow->knowledgeDocumentsChangedAfter($key, $org, $base->telenow_id, $after);
        foreach ($rows as $row) {
            if (! is_string($row['id'] ?? null)) {
                continue;
            }
            $doc = KnowledgeDocument::query()->where('telenow_id', $row['id'])->first()
                ?? (new KnowledgeDocument)->forceFill(['knowledge_base_id' => $base->id, 'workspace_id' => $base->workspace_id, 'telenow_id' => $row['id']]);
            if ($doc->exists && $doc->sync_state === 'pending') {
                continue; // the kit is changing it: its own write's answer comes first
            }
            $doc->forceFill($this->manifest->columns(DocumentWrite::RESOURCE, $row) + [
                'deleted_at' => is_string($row['deleted_at'] ?? null) ? ($doc->deleted_at ?? now()) : null,
                'sync_state' => 'synced', 'sync_error' => null,
            ])->save();
            if (is_string($row['updated_at'] ?? null) && $row['updated_at'] > $after) {
                $after = $row['updated_at'];
            }
        }
        $base->forceFill(['documents_after' => $after, 'documents_read_at' => now()])->save();

        return count($rows);
    }

    /** The bases Telenow has for the account: one gone there goes here; one made there (not by the kit) is kept here. */
    public function bases(Workspace $workspace): void
    {
        [$key, $org] = BaseWrite::account($workspace);
        $theirs = collect($this->telenow->knowledgeBases($key, $org))->filter(fn ($b) => is_string($b['id'] ?? null))->keyBy('id');
        $ours = KnowledgeBase::query()->where('workspace_id', $workspace->id)->whereNotNull('telenow_id')->get()->keyBy('telenow_id');
        foreach ($ours as $id => $base) {
            if (! $theirs->has($id) && $base->deleted_at === null && $base->sync_state === 'synced') {
                $base->agents()->detach();
                $base->forceFill(['deleted_at' => now()])->save();
            }
        }
        foreach ($theirs as $id => $object) {
            if (! $ours->has($id)) {
                (new KnowledgeBase)->forceFill(['workspace_id' => $workspace->id, 'telenow_id' => $id, 'sync_state' => 'synced']
                    + $this->manifest->columns(BaseWrite::RESOURCE, $object))->save();
            }
        }
    }

    /** The largest file the kit takes: Telenow's limit, no more than this host lets PHP receive. */
    public static function fileMaxBytes(): int
    {
        $limits = [config('kit.knowledge.file_max_bytes')];
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $bytes = self::bytes((string) ini_get($ini));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    private static function bytes(string $ini): int
    {
        $ini = trim($ini);
        $unit = strtolower(substr($ini, -1));
        $n = (int) $ini;

        return match ($unit) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    }

    private function add(KnowledgeBase $base, array $columns, array $body): KnowledgeDocument
    {
        $doc = DB::transaction(function () use ($base, $columns, $body) {
            $doc = (new KnowledgeDocument)->forceFill($columns + ['knowledge_base_id' => $base->id, 'workspace_id' => $base->workspace_id]);
            $doc->save();
            $this->outbox->enqueue(DocumentWrite::RESOURCE, $doc->id, 'create', $body);

            return $doc;
        });
        $this->outbox->runFor(DocumentWrite::RESOURCE, $doc->id);

        return $doc->fresh();
    }

    /** A write Telenow gave up on would hold every later one back: the new write says the whole outcome instead. */
    private function replaceGivenUp(string $resource, string $id): void
    {
        if ($this->outbox->hasFailed($resource, $id)) {
            $this->outbox->cancel($resource, $id, __('knowledge.cancelled'));
        }
    }

    private function check(array $input, array $rules, array $messages = []): array
    {
        $names = array_map(fn (string $f) => __("knowledge.fields.{$f}"), array_combine(array_keys($rules), array_keys($rules)));

        return Validator::make($input, $rules, $messages, $names)->validate();
    }
}
