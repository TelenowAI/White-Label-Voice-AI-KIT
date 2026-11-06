<?php

namespace App\Kit\Agents;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\RealtimeCatalog;

/**
 * An agent's realtime voice (the setting `realtime`): the provider, model and voice of a realtime engine that listens, thinks and speaks on its calls, and whether a call
 * falls back to the agent's voice pipeline when the engine cannot start. Telenow keeps it in `s2s_config` (config('kit.catalog.realtime.blob')).
 *
 * What was recorded (contract_k14realtime.py), with the platform's engine on and off alike:
 * - the blob is replaced whole when it is sent, `{}` is no realtime voice, and `null` leaves it as it is;
 * - a voice left out is the engine's own default; `fallback` must be true or false, and left out it is true;
 * - other keys in the blob stay, and a key Telenow shows masked (`••••1234`) goes back as it came while the engine stays, but is refused when the engine changes;
 * - Telenow saves a realtime voice while its platform runs none, and every call then runs on the pipeline: so customers pick only while it runs them (RealtimeCatalog).
 *
 * So: read() takes the provider, model, voice and fallback Telenow holds; checked() is a customer's pick (RealtimeCatalog::choice); write() starts from Telenow's blob as it is
 * while the engine stays (only the kit's keys change), and from nothing when it changes; none is `{}`. forCustomers(): the pick under the kit's keys and names.
 *
 * Used by AgentSettings (reading and writing every setting), Agents (what a customer sends), AgentsController (what is shown). Not here: the roster (RealtimeCatalog).
 */
final class AgentRealtime
{
    public const KEY = 'realtime';

    public function __construct(private readonly RealtimeCatalog $catalog) {}

    public function has(string $key): bool
    {
        return $key === self::KEY;
    }

    /** @return array{realtime: ?array{provider: string, model: string, voice: ?string, fallback: bool}} the agent's realtime voice as Telenow holds it, null for none */
    public function read(array $object): array
    {
        $blob = $this->blob($object[config('kit.catalog.realtime.blob.read')] ?? null);
        $text = fn (string $k) => is_string($blob[$k] ?? null) && trim($blob[$k]) !== '' ? trim($blob[$k]) : null;
        $provider = $text('provider');
        $model = $text('model');

        return [self::KEY => $provider === null || $model === null ? null : ['provider' => $provider, 'model' => $model, 'voice' => $text('voice'), 'fallback' => ($blob['fallback'] ?? true) !== false]];
    }

    /** @return ?array{provider: string, model: string, voice: ?string, fallback: bool} a customer's pick, checked, in Telenow's ids */
    public function checked(mixed $input): ?array
    {
        return $this->catalog->choice($input);
    }

    /**
     * Into the blob: Telenow's own while the engine stays (only the kit's keys changed), nothing when it changes; none is `{}`.
     *
     * @param  array<string, mixed>  $blobs  by write name, added to
     */
    public function write(array &$blobs, array $object, ?array $value): void
    {
        $names = config('kit.catalog.realtime.blob');
        if ($value === null) {
            $blobs[$names['write']] = [];

            return;
        }
        $stored = $this->blob($object[$names['read']] ?? null);
        $kept = ($stored['provider'] ?? null) === $value['provider'] ? array_diff_key($stored, array_flip(config('kit.catalog.realtime.keys'))) : [];
        $blobs[$names['write']] = ['provider' => $value['provider'], 'model' => $value['model']] + ($value['voice'] === null ? [] : ['voice' => $value['voice']])
            + ['fallback' => $value['fallback']] + $kept;
    }

    /**
     * The agent's realtime voice as customers see it: the kit's key for the provider, Telenow's model and voice ids, their names (null for what is no longer offered),
     * and whether calls use it now (the platform runs realtime voices and the pick is still offered).
     *
     * @return ?array{provider: string, model: string, voice: ?string, fallback: bool, names: array, available: bool}
     */
    public function forCustomers(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $names = $this->catalog->names($value);

        return ['provider' => Catalog::publicKey(RealtimeCatalog::KIND, $value['provider']), 'model' => $value['model'], 'voice' => $value['voice'], 'fallback' => $value['fallback'],
            'names' => $names, 'available' => $names['provider'] !== null && $names['model'] !== null]; // offered only while the platform runs them (RealtimeCatalog)
    }

    private function blob(mixed $value): array
    {
        return is_array($value) ? $value : ($value instanceof \stdClass ? get_object_vars($value) : []);
    }
}
