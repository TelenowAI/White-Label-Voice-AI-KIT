<?php

namespace App\Kit\Catalog;

use Illuminate\Validation\ValidationException;

/**
 * Each provider's own settings, as Telenow's catalog describes them (getCatalog `configFields`): a voice's model, quality, speed or language, a listening model and its language,
 * a language model's temperature. Drawn from the catalog, so a setting Telenow adds is offered without a change here.
 *
 * - copied(): what the kit keeps of a provider's fields (config('kit.catalog.settings.field')), never one Telenow marks `sensitive` (a key, a token), nor of a kind never offered
 *   (a vendor descriptor), nor one Telenow keeps as a secret or reads as where a call is sent whatever the catalog says (`never_keys`, `never_key_endings`: an access key
 *   id, a base URL, an endpoint, a region), nor one whose key names the platform (customers name a setting by its key); the field that holds the provider's speech model
 *   (`models[].configKey`) is marked `model`.
 * - curated(): with the operator's choices (setting `catalog.curation`): which settings customers may change and, of one with options, which options. Until the operator says,
 *   the bounded kinds (config('kit.catalog.settings.offered_kinds')) are offered and free text is not: free text can carry an address or an id. A word that names the platform
 *   is never shown.
 * - offered(), forCustomers(), checked(): what an agent's form offers (customers name an option by the kit's own name, publicValue(): a model's id can carry the
 *   platform's name), and a customer's values checked against it and turned into Telenow's. Telenow keeps whatever it is sent, a temperature of 7 or a speed of
 *   9 included (recorded, contract_k13settings.py), so every value is checked here.
 *
 * Used by Catalog (the copy, the operator's page, the choices customers see) and App\Kit\Agents\AgentProviderSettings (an agent's values). Not here: writing them to Telenow.
 */
final class ProviderSettings
{
    /** @return list<array> a provider's fields as the kit keeps them */
    public function copied(array $provider): array
    {
        $c = config('kit.catalog.settings');
        $modelKey = is_array($provider['models'][0] ?? null) && is_string($provider['models'][0]['configKey'] ?? null) ? $provider['models'][0]['configKey'] : $c['model_key'];
        $fields = [];
        foreach (is_array($provider['configFields'] ?? null) ? $provider['configFields'] : [] as $f) {
            if (! is_array($f) || ! is_string($f['key'] ?? null) || ! is_string($f['kind'] ?? null) || ($f['sensitive'] ?? false) === true || in_array($f['kind'], $c['never_kinds'], true)
                || self::secretOrDestination($f['key']) || Catalog::namesPlatform($f['key'])) {
                continue;
            }
            $kept = array_intersect_key($f, array_flip($c['field']));
            if (array_key_exists('options', $kept)) {
                $kept['options'] = array_values(array_filter(array_map(fn ($o) => is_array($o) && is_scalar($o['value'] ?? null)
                    ? ['value' => $o['value'], 'label' => is_string($o['label'] ?? null) ? $o['label'] : (string) $o['value']] : null, is_array($kept['options']) ? $kept['options'] : [])));
            }
            $fields[] = $kept + ['model' => $f['key'] === $modelKey];
        }

        return $fields;
    }

    /**
     * The fields with the operator's choices, as Admin → Catalog shows them.
     *
     * @param  list<array>  $fields  (copied)
     * @param  array<string, array>  $mine  the operator's choices for this provider's settings, by key
     */
    public function curated(array $fields, array $mine): array
    {
        $bounded = config('kit.catalog.settings.offered_kinds');

        // A label is the catalog's, else the field's key (copied() keeps none that names the platform) or the option's value, else a word of the kit's: never one that names
        // the platform (`telenow-v1` does).
        return array_map(function (array $f) use ($mine, $bounded) {
            $own = is_array($mine[$f['key']] ?? null) ? $mine[$f['key']] : [];
            $f['label'] = $this->said($f['label'] ?? null) ?? $f['key'];
            $f['help'] = $this->said($f['help'] ?? null);
            $f['offered'] = array_key_exists('offered', $own) ? $own['offered'] === true : in_array($f['kind'], $bounded, true);
            if (isset($f['options'])) {
                $f['options'] = array_map(fn (array $o, int $i) => ['value' => $o['value'], 'label' => $this->said($o['label']) ?? $this->said(self::optionKey($o['value'])) ?? __('catalog.option_numbered', ['n' => $i + 1]),
                    'offered' => (($own['options'] ?? [])[self::optionKey($o['value'])] ?? true) === true], $f['options'], array_keys($f['options']));
            }

            return $f;
        }, $fields);
    }

    /**
     * The operator's choices for one provider's settings, checked: only its own fields and options.
     *
     * @param  list<array>  $fields  (copied)
     * @param  array<string, list<string>>  $errors  added to, by field path under `$at`
     * @return array<string, array>
     */
    public function choices(array $fields, mixed $given, string $at, array &$errors): array
    {
        $byKey = array_column($fields, null, 'key');
        $out = [];
        foreach (is_array($given) ? $given : [] as $key => $choice) {
            $f = $byKey[$key] ?? null;
            if ($f === null || ! is_array($choice)) {
                $errors["{$at}.{$key}"] = [__('catalog.unknown')];

                continue;
            }
            $entry = ['offered' => (bool) ($choice['offered'] ?? false)];
            $known = array_map(fn (array $o) => self::optionKey($o['value']), $f['options'] ?? []);
            foreach (is_array($choice['options'] ?? null) ? $choice['options'] : [] as $option => $on) {
                if (! in_array((string) $option, $known, true)) {
                    $errors["{$at}.{$key}.options.{$option}"] = [__('catalog.unknown')];

                    continue;
                }
                $entry['options'][(string) $option] = (bool) $on;
            }
            $out[$key] = $entry;
        }

        return $out;
    }

    /** @return list<array> the settings customers may change, each with only its offered options (Telenow's own values) and its default when that is one of them */
    public function offered(array $curated): array
    {
        $out = [];
        foreach ($curated as $f) {
            if (! $f['offered']) {
                continue;
            }
            $options = isset($f['options']) ? array_values(array_map(fn (array $o) => ['value' => $o['value'], 'label' => $o['label']], array_filter($f['options'], fn (array $o) => $o['offered']))) : null;
            if ($options === []) {
                continue;
            }
            $default = $f['defaultValue'] ?? null;
            if ($options !== null && ! in_array($default, array_column($options, 'value'), true)) {
                $default = null;
            }
            $out[] = array_filter(['key' => $f['key'], 'kind' => $f['kind'], 'label' => $f['label'], 'help' => $f['help'], 'options' => $options,
                'min' => $f['min'] ?? null, 'max' => $f['max'] ?? null, 'step' => $f['step'] ?? null], fn ($v) => $v !== null)
                + ['default' => $default, 'advanced' => ($f['advanced'] ?? false) === true, 'model' => $f['model'] === true];
        }

        return $out;
    }

    /** @return list<array> the settings customers may change, as they see them: an option named by the kit (publicValue), never by Telenow's value */
    public function forCustomers(array $curated): array
    {
        return array_map(function (array $f) {
            if (isset($f['options'])) {
                $f['default'] = $f['default'] === null ? null : self::publicValue($f['key'], $f['default']);
                $f['options'] = array_map(fn (array $o) => ['value' => self::publicValue($f['key'], $o['value']), 'label' => $o['label']], $f['options']);
            }

            return $f;
        }, $this->offered($curated));
    }

    /**
     * An agent's values as customers see them: an option by the kit's name, one not offered (or not known) as none.
     *
     * @param  list<array>  $curated
     * @param  array<string, mixed>  $values  Telenow's
     */
    public function valuesForCustomers(array $curated, array $values): array
    {
        $out = [];
        foreach ($this->offered($curated) as $f) {
            if (! array_key_exists($f['key'], $values)) {
                continue;
            }
            $v = $values[$f['key']];
            $out[$f['key']] = isset($f['options']) ? (in_array($v, array_column($f['options'], 'value'), true) ? self::publicValue($f['key'], $v) : null) : $v;
        }

        return $out;
    }

    /** Telenow's value of an option a customer names (publicValue), among a setting's offered options, or null. */
    public static function realValue(array $field, mixed $public): mixed
    {
        foreach ($field['options'] ?? [] as $o) {
            if (self::publicValue($field['key'], $o['value']) === $public) {
                return $o['value'];
            }
        }

        return null;
    }

    /** How customers name an option: a model's id can carry the platform's name, so never Telenow's value. */
    public static function publicValue(string $settingKey, mixed $value): string
    {
        return 'o'.substr(hash('sha256', $settingKey."\n".self::optionKey($value)), 0, 16);
    }

    /**
     * A customer's values for one provider's settings, checked against what is offered: each key one of them, each value of its kind (an offered option, a number within
     * its bounds, a switch, text no longer than allowed). Null takes a setting back to the provider's own default.
     *
     * @param  list<array>  $offered  (offered: Telenow's own option values)
     * @return array<string, mixed>
     */
    public function checked(array $offered, mixed $values, string $at): array
    {
        if ($values === null) {
            return [];
        }
        if (! is_array($values) || (array_is_list($values) && $values !== [])) {
            throw ValidationException::withMessages([$at => [__('catalog.settings_not_a_map')]]);
        }
        $byKey = array_column($offered, null, 'key');
        $out = [];
        $errors = [];
        foreach ($values as $key => $value) {
            $f = $byKey[$key] ?? null;
            $path = "{$at}.{$key}";
            if ($f === null) {
                $errors[$path] = [__('catalog.not_a_setting')];

                continue;
            }
            if ($value === null) {
                $out[$key] = null;

                continue;
            }
            $said = match ($f['kind']) {
                'select' => self::realValue($f, $value) !== null ? null : __('catalog.setting_not_an_option'),
                'number' => (is_int($value) || is_float($value)) && (! isset($f['min']) || $value >= $f['min']) && (! isset($f['max']) || $value <= $f['max']) ? null
                    : __('catalog.setting_out_of_range', ['min' => $f['min'] ?? '-', 'max' => $f['max'] ?? '-']),
                'toggle' => is_bool($value) ? null : __('catalog.setting_not_a_switch'),
                default => is_string($value) && mb_strlen($value) <= config('kit.catalog.settings.text_max_length') ? null
                    : __('catalog.setting_too_long', ['max' => config('kit.catalog.settings.text_max_length')]),
            };
            if ($said !== null) {
                $errors[$path] = [$said];

                continue;
            }
            // Text comes trimmed (Laravel's TrimStrings, on every request).
            $out[$key] = $f['kind'] === 'select' ? self::realValue($f, $value) : $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** The field that holds the provider's speech model, among these (copied, curated or forCustomers), or null. */
    public static function modelField(array $fields): ?array
    {
        foreach ($fields as $f) {
            if (($f['model'] ?? false) === true) {
                return $f;
            }
        }

        return null;
    }

    /** How an option is named in the operator's choices: its value, as text. */
    public static function optionKey(mixed $value): string
    {
        return is_string($value) ? $value : (string) json_encode($value);
    }

    /** Whether Telenow keeps a field of this name as a secret or reads it as where a call is sent (config('kit.catalog.settings.never_keys'), `never_key_endings`). */
    private static function secretOrDestination(string $key): bool
    {
        $name = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        return in_array($name, config('kit.catalog.settings.never_keys'), true)
            || array_filter(config('kit.catalog.settings.never_key_endings'), fn (string $end) => str_ends_with($name, $end)) !== [];
    }

    private function said(mixed $text): ?string
    {
        return is_string($text) && trim($text) !== '' && ! Catalog::namesPlatform($text) ? $text : null;
    }
}
