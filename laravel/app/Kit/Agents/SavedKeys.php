<?php

namespace App\Kit\Agents;

use Illuminate\Support\Facades\Crypt;

/**
 * A customer's API keys on tools and look-ups, and the rules Telenow holds them to (utils/secrets.rs, recorded by contract_k3tools.py):
 * - a read never hands a key out: it shows a mask, which a save may hand back to keep the key. The kit must never ask for `view=edit`,
 *   which answers the keys in plain text;
 * - a mask is only put back while everything the key is sent to reads as it did: its address, and any text holding a web address, in
 *   the same row (destinations) or in the blob above the rows. Otherwise Telenow refuses the whole save;
 * - only fields named here (secret) are masked: any other header is stored and shown as typed.
 *
 * A key typed in the kit is sealed the moment it is checked, and only opened to send it (seal, open): the kit's own columns never hold it.
 * Rows carry it under `sealed`, which never reaches the agent's `settings` column or an answer (without).
 *
 * Not here: the rows themselves (AgentTools, AgentLookups), other settings (AgentSettings).
 */
final class SavedKeys
{
    private const DESTINATION_ENDINGS = ['url', 'urls', 'uri', 'uris', 'endpoint', 'endpoints', 'host', 'hosts', 'hostname', 'domain', 'domains', 'server', 'servers', 'region', 'location', 'apibase'];

    public function secret(string $field): bool
    {
        return in_array(mb_strtolower($field), array_map('mb_strtolower', config('kit.agents.secret_fields')), true);
    }

    public function isMask(string $value): bool
    {
        return str_starts_with($value, config('kit.agents.secret_mask'));
    }

    /** Telenow's own mask: the last characters only of a key long enough to spare them. */
    public function mask(string $key): string
    {
        $prefix = config('kit.agents.secret_mask');
        $length = mb_strlen($key);

        return $length < config('kit.agents.secret_hint_min_chars') ? $prefix : $prefix.mb_substr($key, $length - config('kit.agents.secret_hint_chars'));
    }

    /** What to show of a stored key, whatever was read: never the key itself. */
    public function hint(mixed $stored): ?string
    {
        if (! is_string($stored) || $stored === '') {
            return null;
        }

        return $this->isMask($stored) ? $stored : $this->mask($stored);
    }

    public function seal(string $key): string
    {
        return Crypt::encryptString($key);
    }

    public function open(string $sealed): string
    {
        return Crypt::decryptString($sealed);
    }

    /** `$value` without any key typed in this save: what may be kept and answered. */
    public function without(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        unset($value['sealed']);

        return array_map(fn ($inner) => $this->without($inner), $value);
    }

    public function typed(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        if (($value['sealed'] ?? []) !== []) {
            return true;
        }

        return array_filter($value, fn ($inner) => $this->typed($inner)) !== [];
    }

    /**
     * Every place a row sends to, by its path in the row: the same reading as Telenow's, so a save it would refuse is refused here first,
     * beside the field, rather than failing later with its words.
     *
     * @return list<string> sorted
     */
    public function destinations(mixed $row): array
    {
        $found = [];
        $this->within($row, '', $found);
        sort($found);

        return $found;
    }

    private function within(mixed $value, string $at, array &$found): void
    {
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $inner) {
            if (is_string($key) && $this->secret($key)) {
                continue;
            }
            $here = is_string($key) ? "{$at}.{$key}" : "{$at}[{$key}]";
            $destination = is_string($key) ? $this->destination($key, $inner) : null;
            if ($destination !== null) {
                $found[] = "{$here}={$destination}";
            } else {
                $this->within($inner, $here, $found);
            }
        }
    }

    private function destination(string $key, mixed $value): ?string
    {
        if ($this->holdsSecret($value) || $value === null || $value === '') {
            return null;
        }
        $normal = strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $key));
        $named = $normal === 'provider' || array_filter(self::DESTINATION_ENDINGS, fn (string $end) => str_ends_with($normal, $end)) !== [];
        if (is_string($value)) {
            $bareRegion = (str_ends_with($normal, 'region') || str_ends_with($normal, 'location')) && preg_match('/^[A-Za-z0-9_-]*$/', $value) === 1;

            return ($named && ! $bareRegion) || str_contains($value, '://') ? $value : null;
        }

        return $named ? json_encode($value) : null;
    }

    private function holdsSecret(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $key => $inner) {
            if ((is_string($key) && $this->secret($key)) || $this->holdsSecret($inner)) {
                return true;
            }
        }

        return false;
    }
}
