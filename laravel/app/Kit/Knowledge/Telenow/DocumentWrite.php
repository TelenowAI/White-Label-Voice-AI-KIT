<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Manifest\Manifest;
use App\Kit\Outbox\OutboxEntry;
use App\Kit\Outbox\OutboxHandler;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\KnowledgeDocument;
use Illuminate\Support\Facades\Storage;

/**
 * What every document write to Telenow shares: the document and its base (which must be made at Telenow first: until
 * then the write waits), the customer's own key, and how the outcome shows — `pending` with the kit's "will retry" words
 * while it is tried again, `failed` with the kit's words for what went wrong once given up (Telenow's own text names the
 * vendor's address), `synced` with Telenow's document. A file waiting to be sent is deleted once its write is done or
 * given up.
 *
 * Used by AddDocumentAtTelenow, RetryDocumentAtTelenow, DeleteDocumentAtTelenow.
 */
abstract class DocumentWrite implements OutboxHandler
{
    public const RESOURCE = 'knowledge_documents';

    public function __construct(protected readonly TelenowClient $telenow, protected readonly Manifest $manifest) {}

    public function waiting(OutboxEntry $entry, TelenowError $error): void
    {
        $this->document($entry)->forceFill(['sync_state' => 'pending', 'sync_error' => __('knowledge.will_retry')])->save();
    }

    public function failed(OutboxEntry $entry, TelenowError $error): void
    {
        $this->document($entry)->forceFill(['sync_state' => 'failed', 'sync_error' => $this->words($entry, $error)])->save();
        self::forget($entry);
    }

    protected function document(OutboxEntry $entry): KnowledgeDocument
    {
        return KnowledgeDocument::query()->with('base.workspace')->findOrFail($entry->kit_id);
    }

    /** @return array{0: string, 1: string, 2: string} the customer's key, sub-account, and Telenow's base */
    protected function where(KnowledgeDocument $doc): array
    {
        [$key, $org] = BaseWrite::account($doc->base->workspace);
        if (! is_string($doc->base->telenow_id)) {
            throw new TelenowError(0, __('knowledge.not_ready'));
        }

        return [$key, $org, $doc->base->telenow_id];
    }

    /** Telenow's document onto the row: its fields by the manifest. */
    protected function synced(KnowledgeDocument $doc, array $object): void
    {
        $doc->forceFill($this->manifest->columns(self::RESOURCE, $object) + [
            'telenow_id' => $object['id'] ?? $doc->telenow_id, 'sync_state' => 'synced', 'sync_error' => null,
        ])->save();
    }

    /** A file the write was waiting to send, gone. */
    protected static function forget(OutboxEntry $entry): void
    {
        if (($entry->body['kind'] ?? null) === 'file' && is_string($entry->body['path'] ?? null)) {
            Storage::disk(config('kit.knowledge.upload_disk'))->delete($entry->body['path']);
        }
    }

    private function words(OutboxEntry $entry, TelenowError $error): string
    {
        if ($entry->operation !== 'create' || $error->status < 400 || $error->status >= 500) {
            return __('knowledge.not_done');
        }
        $max = (string) intdiv(config('kit.knowledge.file_max_bytes'), 1024 * 1024);

        return match ($entry->body['kind'] ?? null) {
            'url' => __('knowledge.page_refused'),
            'file' => $error->status === 413 ? __('knowledge.file_too_big', ['max' => $max])
                : __('knowledge.file_refused', ['types' => implode(', ', config('kit.knowledge.file_types')), 'max' => $max]),
            default => __('knowledge.text_refused'),
        };
    }
}
