<?php

namespace App\Kit\Knowledge\Telenow;

use App\Kit\Outbox\OutboxEntry;
use Illuminate\Support\Facades\Storage;

/**
 * Adds the document to the base at Telenow, as the customer gave it (the write's `kind`): text (`addKnowledgeText`), a
 * web page Telenow fetches now (`addKnowledgeUrl`), or a file streamed from where it waits (`uploadKnowledgeFile`;
 * then deleted). Telenow reads it in the background (a table at once); the feed brings how that went.
 */
final class AddDocumentAtTelenow extends DocumentWrite
{
    public function send(OutboxEntry $entry): void
    {
        $doc = $this->document($entry);
        [$key, $org, $kb] = $this->where($doc);
        $b = $entry->body ?? [];
        $answer = match ($b['kind'] ?? null) {
            'url' => $this->telenow->addKnowledgeUrl($key, $org, $kb, array_filter(['url' => $b['url'], 'title' => $b['title'] ?? null])),
            'file' => $this->upload($key, $org, $kb, $b),
            default => $this->telenow->addKnowledgeText($key, $org, $kb, ['title' => $b['title'], 'body' => $b['text']]),
        };
        $this->synced($doc, $this->manifest->object(self::RESOURCE, 'create', $answer));
        self::forget($entry);
    }

    private function upload(string $key, string $org, string $kb, array $b): array
    {
        $stream = Storage::disk(config('kit.knowledge.upload_disk'))->readStream($b['path']);
        try {
            return $this->telenow->uploadKnowledgeFile($key, $org, $kb, $stream, $b['name'], $b['title'] ?? null);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
