<?php

namespace App\Kit\Support;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored request body (OutboxEntry::$body): an array like Laravel's `array` cast, but a nested `{}` comes back as
 * `{}` (Json::decode), so a write sent later says exactly what was written. The body itself is always an array.
 *
 * Not here: what a body holds (the outbox handlers, the manifest).
 */
final class JsonBody implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }
        $body = Json::decode((string) $value);

        return $body instanceof \stdClass ? [] : $body;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);
    }
}
