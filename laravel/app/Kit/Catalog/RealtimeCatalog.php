<?php

namespace App\Kit\Catalog;

use App\Kit\Settings\Settings;
use Illuminate\Validation\ValidationException;

/**
 * Realtime voices: one model that listens, thinks and speaks on a call in place of the voice pipeline (a language model, listening and a voice). Telenow runs them only
 * while its platform has them on (getRealtimeCatalog `enabled`), from its own roster: providers, their models, and each model's voices (recorded, contract_k14realtime.py).
 *
 * - copied(): what the kit keeps of Telenow's answer (config('kit.catalog.realtime.fields')), as the catalog copy's `realtime` (Catalog::refresh). A voice whose name
 *   names the platform is left out, and so is any other text that does.
 * - providers(): the copy with the operator's choices (setting catalog.curation, `realtime`): which providers customers may pick — none until offered — and of those
 *   which models (all until switched off), each under a name of the operator's or the copy's. A name that names the platform is never offered. Nothing at all is offered
 *   while the platform runs no realtime voices: Telenow saves a realtime setup then and runs every call on the pipeline (recorded), and a customer would not know.
 * - checkedChoices() and saveChoices(): the operator's choices, checked before anything is saved and then kept; forCustomers(): what customers pick among, under the
 *   kit's keys (Catalog::publicKey), never Telenow's ids; choice(): a customer's pick, checked, in Telenow's ids; names(): what customers call a pick.
 *
 * Not here: an agent's setup (App\Kit\Agents\AgentRealtime), hearing a voice (VoicePreview), the pages (Admin\CatalogController, Console\CatalogController).
 */
final class RealtimeCatalog
{
    public const KIND = 'realtime';

    public function __construct(private readonly Settings $settings) {}

    /** @return array{enabled: bool, providers: list<array>} Telenow's answer as the kit keeps it */
    public function copied(array $answer): array
    {
        $fields = config('kit.catalog.realtime.fields');
        $providers = [];
        foreach ($this->rows($answer['providers'] ?? null) as $p) {
            $models = [];
            foreach ($this->rows($p['models'] ?? null) as $m) {
                $voices = [];
                foreach (is_array($m['voices'] ?? null) ? $m['voices'] : [] as $v) {
                    $v = is_string($v) ? ['id' => $v] : $v; // a roster written before voices were described lists their ids
                    if (is_array($v) && is_string($v['id'] ?? null) && $v['id'] !== '' && ! Catalog::namesPlatform((string) ($v['name'] ?? $v['id']))) {
                        $voices[] = $this->kept($v, $fields['voice']);
                    }
                }
                $models[] = ['voices' => $voices] + $this->kept($m, $fields['model']);
            }
            $providers[] = ['models' => $models] + $this->kept($p, $fields['provider']);
        }

        return ['enabled' => ($answer['enabled'] ?? false) === true, 'providers' => $providers];
    }

    /** Whether the platform runs realtime voices, as the last copy says. */
    public function enabled(): bool
    {
        return $this->copy()['enabled'];
    }

    /**
     * Every provider in the copy with the operator's choices — what Admin → Catalog shows.
     *
     * @return list<array>
     */
    public function providers(): array
    {
        $copy = $this->copy();
        $curation = (array) $this->settings->get(Catalog::CURATION, []);
        $chosen = is_array($curation[self::KIND] ?? null) ? $curation[self::KIND] : [];

        return array_map(function (array $p) use ($copy, $chosen) {
            $mine = is_array($chosen[$p['id']] ?? null) ? $chosen[$p['id']] : [];
            $name = $this->ownName($mine['name'] ?? null) ?? $p['name'] ?? $p['id'];
            $models = array_map(function (array $m) use ($mine) {
                $own = is_array($mine['models'][$m['id']] ?? null) ? $mine['models'][$m['id']] : [];
                $modelName = $this->ownName($own['name'] ?? null) ?? $m['name'] ?? $m['id'];

                return $m + ['displayName' => $modelName, 'ownName' => $this->ownName($own['name'] ?? null), 'namesPlatform' => Catalog::namesPlatform($modelName),
                    'offered' => ($own['offered'] ?? true) === true && ! Catalog::namesPlatform($modelName)];
            }, $p['models']);

            return $p + [
                'key' => Catalog::publicKey(self::KIND, $p['id']),
                'displayName' => $name,
                'ownName' => $this->ownName($mine['name'] ?? null),
                'namesPlatform' => Catalog::namesPlatform($name),
                // What the operator chose, and whether customers may pick it now (not while the platform runs no realtime voices).
                'chosen' => ($mine['offered'] ?? false) === true,
                'offered' => $copy['enabled'] && ($mine['offered'] ?? false) === true && ! Catalog::namesPlatform($name),
                'curatedModels' => $models,
            ];
        }, $copy['providers']);
    }

    /** @return list<array> the providers customers may pick, each with only its offered models; none while the platform runs no realtime voices */
    public function offered(): array
    {
        $out = [];
        foreach ($this->providers() as $p) {
            $p['curatedModels'] = array_values(array_filter($p['curatedModels'], fn (array $m) => $m['offered']));
            if ($p['offered'] && $p['curatedModels'] !== []) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /** @return list<array> what customers pick among, under the kit's keys */
    public function forCustomers(): array
    {
        return array_map(fn (array $p) => ['key' => $p['key'], 'name' => $p['displayName'], 'languages' => $p['languages'] ?? [],
            'models' => array_map(fn (array $m) => ['id' => $m['id'], 'name' => $m['displayName'], 'voices' => $m['voices']], $p['curatedModels'])], $this->offered());
    }

    /**
     * A customer's pick (`realtime`): null for none, else a provider by the kit's key, one of its offered models, one of that model's voices (none: the model's own
     * default), and whether a call falls back to the pipeline when the realtime voice cannot start (yes unless said; Telenow reads anything but `false` as yes).
     *
     * @return ?array{provider: string, model: string, voice: ?string, fallback: bool} in Telenow's ids
     */
    public function choice(mixed $input, string $at = self::KIND): ?array
    {
        $field = fn (string $key) => $at === '' ? $key : "{$at}.{$key}";
        if ($input === null) {
            return null;
        }
        if (! is_array($input) || array_is_list($input)) {
            throw ValidationException::withMessages([$at === '' ? 'provider' : $at => [__('catalog.realtime_not_a_choice')]]);
        }
        if (! $this->enabled()) {
            throw ValidationException::withMessages([$at === '' ? 'provider' : $at => [__('catalog.realtime_off')]]);
        }
        $provider = collect($this->offered())->first(fn (array $p) => $p['key'] === ($input['provider'] ?? null));
        if ($provider === null) {
            throw ValidationException::withMessages([$field('provider') => [__('catalog.not_offered')]]);
        }
        $model = collect($provider['curatedModels'])->first(fn (array $m) => $m['id'] === ($input['model'] ?? null));
        if ($model === null) {
            throw ValidationException::withMessages([$field('model') => [__('catalog.not_offered')]]);
        }
        $voice = $input['voice'] ?? null;
        if ($voice !== null && ! in_array($voice, array_column($model['voices'], 'id'), true)) {
            throw ValidationException::withMessages([$field('voice') => [__('voices.unknown_voice')]]);
        }
        $fallback = $input['fallback'] ?? true;
        if (! is_bool($fallback)) {
            throw ValidationException::withMessages([$field('fallback') => [__('catalog.setting_not_a_switch')]]);
        }

        return ['provider' => $provider['id'], 'model' => $model['id'], 'voice' => $voice, 'fallback' => $fallback];
    }

    /** @return array{provider: ?string, model: ?string, voice: ?string} what customers call a pick in Telenow's ids (null for what is not offered now) */
    public function names(array $pick): array
    {
        $provider = collect($this->offered())->first(fn (array $p) => $p['id'] === $pick['provider']);
        $model = collect($provider['curatedModels'] ?? [])->first(fn (array $m) => $m['id'] === $pick['model']);
        $voice = $pick['voice'] === null ? null : collect($model['voices'] ?? [])->first(fn (array $v) => $v['id'] === $pick['voice']);

        return ['provider' => $provider['displayName'] ?? null, 'model' => $model['displayName'] ?? null, 'voice' => $voice === null ? null : ($voice['name'] ?? $voice['id'])];
    }

    /**
     * The operator's choices (`realtime` of saveCatalogCuration: Telenow's provider ids, each `{offered, name, models: {id: {offered, name}}}`), checked: an unknown
     * provider or model, an over-long name, or an offered one whose name names the platform is refused, before anything is saved.
     *
     * @return array<string, array> as kept
     */
    public function checkedChoices(mixed $given): array
    {
        $errors = [];
        $max = config('kit.agents.name_max_length');
        $known = collect($this->copy()['providers'])->keyBy('id');
        $out = [];
        foreach (is_array($given) ? $given : [] as $id => $choice) {
            $p = $known[$id] ?? null;
            if ($p === null || ! is_array($choice)) {
                $errors["realtime.{$id}"] = [__('catalog.unknown')];

                continue;
            }
            $entry = ['offered' => (bool) ($choice['offered'] ?? false), 'name' => $this->ownName($choice['name'] ?? null), 'models' => []];
            $this->nameRefused($entry['name'], $entry['name'] ?? $p['name'] ?? $id, $entry['offered'], "realtime.{$id}.name", $max, $errors);
            $models = collect($p['models'])->keyBy('id');
            foreach (is_array($choice['models'] ?? null) ? $choice['models'] : [] as $modelId => $m) {
                if (! isset($models[$modelId]) || ! is_array($m)) {
                    $errors["realtime.{$id}.models.{$modelId}"] = [__('catalog.unknown')];

                    continue;
                }
                $own = ['offered' => (bool) ($m['offered'] ?? true), 'name' => $this->ownName($m['name'] ?? null)];
                $this->nameRefused($own['name'], $own['name'] ?? $models[$modelId]['name'] ?? $modelId, $entry['offered'] && $own['offered'], "realtime.{$id}.models.{$modelId}.name", $max, $errors);
                $entry['models'][$modelId] = $own;
            }
            $out[$id] = $entry;
        }
        if (! is_array($given)) {
            $errors['realtime'] = [__('catalog.unknown')];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    /** Keep the operator's checked choices: the providers sent are their choices now, any other keeps its own. */
    public function saveChoices(array $checked): void
    {
        $curation = (array) $this->settings->get(Catalog::CURATION, []);
        $curation[self::KIND] = $checked + (is_array($curation[self::KIND] ?? null) ? $curation[self::KIND] : []);
        $this->settings->put(Catalog::CURATION, $curation);
    }

    /** @return array{enabled: bool, providers: list<array>} the copy's realtime voices; none in a copy made before they were read */
    private function copy(): array
    {
        $copy = $this->settings->get(Catalog::COPY);
        $realtime = is_array($copy) && is_array($copy['realtime'] ?? null) ? $copy['realtime'] : [];

        return ['enabled' => ($realtime['enabled'] ?? false) === true, 'providers' => is_array($realtime['providers'] ?? null) ? $realtime['providers'] : []];
    }

    /** One row as the kit keeps it: only the fields named, text that names the platform dropped, tags and languages as lists of text. */
    private function kept(array $row, array $fields): array
    {
        $out = [];
        foreach (array_intersect_key($row, array_flip($fields)) as $field => $value) {
            $out[$field] = match (true) {
                $field === 'recommended' => $value === true,
                is_array($value) => array_values(array_filter($value, fn ($t) => is_string($t) && ! Catalog::namesPlatform($t))),
                is_string($value) => Catalog::namesPlatform($value) && $field !== 'id' && $field !== 'name' ? null : $value,
                default => null,
            };
        }

        return array_filter($out, fn ($v) => $v !== null);
    }

    /** @return list<array> the rows of a list that have an id */
    private function rows(mixed $list): array
    {
        return array_values(array_filter(is_array($list) ? $list : [], fn ($row) => is_array($row) && is_string($row['id'] ?? null) && $row['id'] !== ''));
    }

    private function nameRefused(?string $own, string $shown, bool $offered, string $at, int $max, array &$errors): void
    {
        if ($own !== null && mb_strlen($own) > $max) {
            $errors[$at] = [__('catalog.name_max', ['max' => $max])];
        } elseif ($offered && Catalog::namesPlatform($shown)) {
            $errors[$at] = [__('catalog.names_platform', ['platform' => config('kit.telenow.name')])];
        }
    }

    private function ownName(mixed $name): ?string
    {
        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
