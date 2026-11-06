<?php

namespace App\Kit\Settings;

use App\Kit\Setup\Installation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The operator's settings: one `settings` row per key, the value JSON-encoded, a secret's value encrypted with
 * the install's APP_KEY (SELF_HOSTED_KIT_PLAN.md §3.5).
 *
 * Before the install is finished a read never fails: with no database yet it answers as if nothing were set,
 * so the wizard and the brand can answer from the first visit. After the install a failed read is an error.
 * Writes always need the database.
 *
 * Not here: defaults (config/kit.php), what a key means (its reader). Called by the wizard's steps and Brand.
 */
final class Settings
{
    private const TABLE = 'settings';

    public function __construct(private readonly Installation $installation) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->row($key);
        if ($row === null) {
            return $default;
        }
        $json = $row->is_secret ? Crypt::decryptString($row->value) : $row->value;

        return json_decode($json, true);
    }

    public function has(string $key): bool
    {
        return $this->row($key) !== null;
    }

    public function put(string $key, mixed $value, bool $secret = false): void
    {
        $json = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $now = now();
        DB::table(self::TABLE)->upsert(
            [['key' => $key, 'value' => $secret ? Crypt::encryptString($json) : $json, 'is_secret' => $secret, 'created_at' => $now, 'updated_at' => $now]],
            ['key'],
            ['value', 'is_secret', 'updated_at'],
        );
    }

    /** Remove `$key`: it reads as never set. */
    public function forget(string $key): void
    {
        DB::table(self::TABLE)->where('key', $key)->delete();
    }

    /**
     * Write `$key` only if nobody has: true when this call wrote it. Two requests racing for the same key get
     * one true and one false (the key is the table's primary key).
     */
    public function claim(string $key, mixed $value): bool
    {
        $now = now();
        try {
            return DB::table(self::TABLE)->insertOrIgnore([
                'key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'is_secret' => false,
                'created_at' => $now, 'updated_at' => $now,
            ]) === 1;
        } catch (QueryException) {
            return false;
        }
    }

    private function row(string $key): ?object
    {
        try {
            return DB::table(self::TABLE)->where('key', $key)->first();
        } catch (Throwable $e) {
            if ($this->installation->installed()) {
                throw $e;
            }

            return null;
        }
    }
}
