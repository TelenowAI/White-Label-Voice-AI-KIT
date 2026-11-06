<?php

namespace App\Kit\Manifest;

use App\Kit\Support\Json;
use Illuminate\Support\Arr;

/**
 * The resource manifest (contract/resources.yaml, read here from its JSON copy resources/kit/manifest.json): for each
 * Telenow-backed resource, which kit column is which Telenow field when read and when written, which writes carry it,
 * the constant parts of a write, and where the kit's own id travels. The engine never names a Telenow field itself.
 *
 * Not here: sending (the outbox handlers), the manifest's checks against Telenow's document (contract/tests).
 */
final class Manifest
{
    private ?array $data = null;

    public function resource(string $name): array
    {
        $this->data ??= Json::decode((string) file_get_contents(resource_path('kit/manifest.json')));

        return $this->data['resources'][$name] ?? throw new \LogicException("No resource {$name} in the manifest");
    }

    /**
     * The body of a `create` or `update` write: each written field the write carries, from `$columns` (only the
     * columns given), plus the write's constant fields, plus the kit's id in its slot (create only).
     *
     * @param  array<string, mixed>  $columns
     */
    public function writeBody(string $resource, string $kind, array $columns, ?string $kitId = null): array
    {
        $r = $this->resource($resource);
        $body = (array) ($r['telenow'][$kind]['body'] ?? []);
        foreach ($r['fields'] as $f) {
            if (isset($f['write']) && (! isset($f['on']) || in_array($kind, $f['on'], true)) && array_key_exists($f['column'], $columns)) {
                $body[$f['write']] = $columns[$f['column']];
            }
        }
        if ($kitId !== null && $kind === 'create' && ($r['kitIdSlot'] ?? null)) {
            Arr::set($body, $r['kitIdSlot'], $kitId);
        }

        return $body;
    }

    /** The name a column is written under (its field's `write`), or null when the kit never writes it. */
    public function writeName(string $resource, string $column): ?string
    {
        foreach ($this->resource($resource)['fields'] as $f) {
            if ($f['column'] === $column) {
                return $f['write'] ?? null;
            }
        }

        return null;
    }

    /** The resource's object in an operation's answer (`objectAt`). */
    public function object(string $resource, string $kind, array $answer): array
    {
        $at = $this->resource($resource)['telenow'][$kind]['objectAt'] ?? '';

        return $at === '' ? $answer : (array) Arr::get($answer, $at, []);
    }

    /**
     * The kit columns as Telenow's object has them (each field's read name).
     *
     * @return array<string, mixed>
     */
    public function columns(string $resource, array $object): array
    {
        $out = [];
        foreach ($this->resource($resource)['fields'] as $f) {
            if (Arr::has($object, $f['telenow'])) {
                $out[$f['column']] = Arr::get($object, $f['telenow']);
            }
        }

        return $out;
    }
}
