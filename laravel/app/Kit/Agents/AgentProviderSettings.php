<?php

namespace App\Kit\Agents;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\ProviderSettings;

/**
 * An agent's own settings for each provider it uses (its voice's model, quality and speed; its listening model and language; its language model's temperature), kept by Telenow
 * in `llm_config`, `stt_config` and `tts_config` (config('kit.catalog.settings.blobs')). The kit names them, per kind, `{provider, values}` (llmSettings, sttSettings, ttsSettings
 * in an agent's settings).
 *
 * What was recorded (contract_k13settings.py): Telenow replaces a blob whole when it is sent, keeps any value and any key it is sent, drops on its own a model that is another
 * provider's, and, when the provider changes and the blob is not sent, keeps some of the last provider's settings. So:
 * - checked(): a customer's values against what the operator offers for that provider (ProviderSettings);
 * - write(): into Telenow's blob as it is now, only those keys changed (null takes one back to the provider's default); a new provider starts from nothing, so none of the last
 *   one's settings carry over;
 * - read(): only the settings the catalog names for that provider, never a key (the catalog copies no `sensitive` field).
 *
 * Used by AgentSettings (reading and writing every setting) and Agents (checking what a customer sends). Not here: the fields themselves (ProviderSettings).
 */
final class AgentProviderSettings
{
    /** The settings key of each kind. */
    public const KEYS = ['llm' => 'llmSettings', 'stt' => 'sttSettings', 'tts' => 'ttsSettings'];

    public function __construct(private readonly Catalog $catalog, private readonly ProviderSettings $settings) {}

    public function has(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }

    /** @return array<string, array{provider: ?string, values: array<string, mixed>}> each kind's provider and its values, as the agent at Telenow holds them */
    public function read(array $object): array
    {
        $out = [];
        foreach (self::KEYS as $kind => $key) {
            $provider = $object[config("kit.catalog.kinds.{$kind}.provider")] ?? null;
            $blob = $this->blob($object[config("kit.catalog.settings.blobs.{$kind}.read")] ?? null);
            $known = array_column($this->fieldsOf($kind, $provider), 'key');
            $out[$key] = ['provider' => is_string($provider) ? $provider : null, 'values' => array_intersect_key($blob, array_flip($known))];
        }

        return $out;
    }

    /**
     * A customer's values for one kind, checked against what is offered for its provider now.
     *
     * @return array{provider: string, values: array<string, mixed>}
     */
    public function checked(string $kind, string $providerId, mixed $values): array
    {
        $provider = collect($this->catalog->offered($kind))->firstWhere('id', $providerId);

        return ['provider' => $providerId, 'values' => $this->settings->checked($provider === null ? [] : $this->settings->offered($provider['settings']), $values, "providerSettings.{$kind}")];
    }

    /**
     * Into its blob: Telenow's own when the provider stays, nothing when it changed; only the keys sent changed.
     *
     * @param  array<string, mixed>  $blobs  by write name, added to
     * @param  array{provider: string, values: array<string, mixed>}  $value
     * @return string the blob's write name
     */
    public function write(array &$blobs, array $object, string $key, array $value): string
    {
        $kind = array_search($key, self::KEYS, true);
        $names = config("kit.catalog.settings.blobs.{$kind}");
        $same = ($object[config("kit.catalog.kinds.{$kind}.provider")] ?? null) === $value['provider'];
        $blob = $blobs[$names['write']] ?? ($same ? $this->blob($object[$names['read']] ?? null) : []);
        foreach ($value['values'] as $setting => $v) {
            if ($v === null) {
                unset($blob[$setting]);
            } else {
                $blob[$setting] = $v;
            }
        }
        $blobs[$names['write']] = $blob;

        return $names['write'];
    }

    /** @return array<string, array{provider: ?string, values: array<string, mixed>}> an agent's settings of each kind as customers see them (ProviderSettings::valuesForCustomers) */
    public function forCustomers(array $settings): array
    {
        $out = [];
        foreach (self::KEYS as $kind => $key) {
            $now = is_array($settings[$key] ?? null) ? $settings[$key] : [];
            $values = $this->settings->valuesForCustomers($this->fieldsOf($kind, $now['provider'] ?? null), is_array($now['values'] ?? null) ? $now['values'] : []);
            $out[$key] = ['values' => $values === [] ? new \stdClass : $values]; // an object even when empty (contract: ProviderSettingsNow)
        }

        return $out;
    }

    /** The speech model an agent's kind's settings name (its provider's model setting), else that setting's default, the model the provider then uses; null when it has no such setting. */
    public static function modelOf(array $fields, array $values): ?string
    {
        $field = ProviderSettings::modelField($fields);
        $model = $field === null ? null : ($values[$field['key']] ?? $field['defaultValue'] ?? null);

        return is_string($model) && $model !== '' ? $model : null;
    }

    /** @return list<array> a provider's settings as the catalog copy has them (offered or not) */
    public function fieldsOf(string $kind, mixed $providerId): array
    {
        $provider = is_string($providerId) ? collect($this->catalog->providers($kind))->firstWhere('id', $providerId) : null;

        return is_array($provider['settings'] ?? null) ? $provider['settings'] : [];
    }

    private function blob(mixed $value): array
    {
        return is_array($value) ? $value : ($value instanceof \stdClass ? get_object_vars($value) : []);
    }
}
