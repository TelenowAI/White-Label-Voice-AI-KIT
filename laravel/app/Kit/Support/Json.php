<?php

namespace App\Kit\Support;

/**
 * JSON read the way the kit sends it on: objects become PHP arrays, except an EMPTY object, which stays an object
 * (`\stdClass`). PHP's arrays cannot tell `{}` from `[]`, and Telenow stores what it is sent — an agent's
 * `sessionConfig` must reach it as `{}` (contract/resources.yaml), not `[]`.
 *
 * Used by Manifest (the manifest's constant write fields) and JsonBody (the outbox's stored bodies).
 */
final class Json
{
    public static function decode(string $json): mixed
    {
        return self::arrays(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    private static function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $fields = get_object_vars($value);

            return $fields === [] ? $value : array_map(self::arrays(...), $fields);
        }

        return is_array($value) ? array_map(self::arrays(...), $value) : $value;
    }
}
