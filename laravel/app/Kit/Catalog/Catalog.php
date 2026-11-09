<?php

namespace App\Kit\Catalog;

use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Manifest\Manifest;
use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Kit\Voices\OwnVoiceNames;
use App\Models\Agent;
use App\Models\CatalogVoice;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The voice platform's catalog as customers may use it (SELF_HOSTED_KIT_PLAN.md §3.7, §3.8 b, §4.1 step 11, §4.2):
 *
 * - refresh(): a copy of Telenow's catalog (`getCatalog`, the operator's key) and of its recommended new-agent stack
 *   (`getAgentDefaults`), keeping only the fields config('kit.catalog.fields') names — never a price, a key's name or a
 *   field Telenow adds later — in settings (`catalog.copy`). `kit:catalog` and Admin → Catalog call it.
 * - The operator's curation (setting `catalog.curation`): which providers customers may pick — none until offered —
 *   and, of an offered language model provider, which models (all until switched off), each under a name of the
 *   operator's or the catalog's. The open-model provider (OpenModels::provider(), OpenRouter) also carries the models the
 *   operator added from its own list (`extraModels`: id => offered, name, and the list's name for it), after the catalog's. A name that carries the platform's own name (config('kit.telenow.name')) is never
 *   offered. The new-agent defaults are the setting `agents.defaults` (by column), which agent creation reads.
 * - What customers see: offered providers under keys of the kit's own (publicKey(): never Telenow's ids), and an agent's
 *   stack checked against them and their voices (stack()), and each offered provider's own settings (App\Kit\Catalog\ProviderSettings), or a voice of the account's own cloned on that provider
 *   (App\Kit\Voices\OwnVoiceNames: customers name it `own:<id>`, the agent holds Telenow's `profile:<id>`).
 *
 * Not here: the voices themselves (CatalogVoices, which refresh() fills), realtime voices (RealtimeCatalog, whose roster refresh() copies too), previews (VoicePreview), the pages
 * (Admin\CatalogController, Console\CatalogController, VoicesController).
 */
final class Catalog
{
    public const COPY = 'catalog.copy';

    public const CURATION = 'catalog.curation';

    public const ERROR = 'catalog.error';

    public const DEFAULTS = 'agents.defaults';

    public function __construct(
        private readonly Settings $settings,
        private readonly TelenowClient $telenow,
        private readonly Manifest $manifest,
        private readonly CatalogVoices $voices,
        private readonly OwnVoiceNames $ownVoices,
        private readonly ProviderSettings $providerSettings,
        private readonly RealtimeCatalog $realtime,
        private readonly OpenModels $openModels,
    ) {}

    /** Copy Telenow's catalog now. */
    public function refresh(): array
    {
        $key = $this->settings->get('telenow.api_key');
        if (! is_string($key) || $key === '') {
            throw new CatalogRefused(409, __('catalog.no_key'));
        }
        try {
            $catalog = $this->telenow->catalog($key);
            $recommended = $this->telenow->agentDefaults($key);
        } catch (TelenowError $e) {
            $this->settings->put(self::ERROR, ['at' => now()->toIso8601String(), 'message' => $e->getMessage()]);
            throw new CatalogRefused(502, __('catalog.refresh_failed'));
        }
        $copy = ['fetchedAt' => now()->toIso8601String(), 'kinds' => [], 'recommended' => []];
        foreach (array_keys(config('kit.catalog.kinds')) as $kind) {
            $copy['kinds'][$kind] = array_values(array_map(
                fn (array $p) => $this->copied($p),
                array_filter($catalog[$kind] ?? [], fn ($p) => is_array($p) && is_string($p['id'] ?? null)),
            ));
        }
        $failed = $this->voices->refresh($key, $copy['kinds']['tts'], array_column(array_filter($catalog['tts'] ?? [], fn ($p) => is_array($p) && is_string($p['id'] ?? null)), 'voices', 'id'));
        foreach (config('kit.agents.stack') as $column) {
            $value = $recommended[$this->manifest->writeName(AgentWrite::RESOURCE, $column)] ?? null;
            $copy['recommended'][$column] = is_string($value) && $value !== '' ? $value : null;
        }
        // Realtime voices (RealtimeCatalog): a roster that cannot be read leaves the one the kit has, and is read again at the next run, as a voice list is.
        $realtimeUnread = false;
        try {
            $copy['realtime'] = $this->realtime->copied($this->telenow->realtimeCatalog($key));
        } catch (TelenowError) {
            $copy['realtime'] = $this->copy()['realtime'] ?? ['enabled' => false, 'providers' => []];
            $realtimeUnread = true;
        }
        $this->settings->put(self::COPY, $copy);
        $unread = array_filter([$failed === [] ? null : __('catalog.voices_unread', ['names' => implode(', ', $failed)]), $realtimeUnread ? __('catalog.realtime_unread') : null]);
        $this->settings->put(self::ERROR, $unread === [] ? null : ['at' => now()->toIso8601String(), 'message' => implode(' ', $unread)]);

        return $copy;
    }

    /** The stored copy, or null before the first refresh. */
    public function copy(): ?array
    {
        $copy = $this->settings->get(self::COPY);

        return is_array($copy) ? $copy : null;
    }

    /** Whether the copy is recent and whole: nothing to read again yet. A list that could not be read is tried at the next run. */
    public function current(): bool
    {
        $at = $this->copy()['fetchedAt'] ?? null;

        return is_string($at) && Carbon::parse($at)->gt(now()->subHours(config('kit.catalog.refresh_hours'))) && $this->settings->get(self::ERROR) === null;
    }

    public function curation(): array
    {
        return (array) $this->settings->get(self::CURATION, []);
    }

    /** @return array<string, ?string> the new-agent defaults, by stack column */
    public function defaults(): array
    {
        $saved = (array) $this->settings->get(self::DEFAULTS, []);

        return array_combine(config('kit.agents.stack'), array_map(fn (string $c) => is_string($saved[$c] ?? null) && $saved[$c] !== '' ? $saved[$c] : null, config('kit.agents.stack')));
    }

    /** Whether text names the platform (customers never see it). */
    public static function namesPlatform(?string $text): bool
    {
        $name = trim((string) config('kit.telenow.name'));

        return $name !== '' && is_string($text) && mb_stripos($text, $name) !== false;
    }

    /** The kit's own key for a provider: stable for this install, never Telenow's id. */
    public static function publicKey(string $kind, string $id): string
    {
        return 'p'.substr(hash_hmac('sha256', "{$kind}:{$id}", (string) config('app.key')), 0, 16);
    }

    /**
     * Every provider of a kind in the copy, with the operator's choices — what Admin → Catalog shows.
     *
     * @return list<array>
     */
    public function providers(string $kind): array
    {
        $chosen = $this->curation()[$kind] ?? [];

        return array_map(function (array $p) use ($kind, $chosen) {
            $mine = $chosen[$p['id']] ?? [];
            $name = $this->ownName($mine['name'] ?? null) ?? $p['name'] ?? $p['id'];
            $models = array_map(function (array $m) use ($mine) {
                $own = ($m['extra'] ?? false) ? $mine['extraModels'][$m['id']] : $mine['models'][$m['id']] ?? [];
                $modelName = $this->ownName($own['name'] ?? null) ?? $m['name'] ?? $m['id'];

                return $m + ['displayName' => $modelName, 'offered' => ($own['offered'] ?? true) === true && ($m['enabled'] ?? true) !== false
                    && ! self::namesPlatform($modelName), 'ownName' => $this->ownName($own['name'] ?? null), 'namesPlatform' => self::namesPlatform($modelName)];
            }, $kind === 'llm' ? [...($p['models'] ?? []), ...$this->extraModels($p, $mine)] : []);

            // The copy's own `settings` are the fields as Telenow has them; what is shown and offered is them with the operator's choices.
            return ['settings' => $this->providerSettings->curated($p['settings'] ?? [], is_array($mine['settings'] ?? null) ? $mine['settings'] : [])] + $p + [
                'key' => self::publicKey($kind, $p['id']),
                'displayName' => $name,
                'ownName' => $this->ownName($mine['name'] ?? null),
                'namesPlatform' => self::namesPlatform($name),
                'offered' => ($mine['offered'] ?? false) === true && ($p['enabled'] ?? true) !== false && ! self::namesPlatform($name),
                'curatedModels' => $models,
                'openModels' => $kind === 'llm' && $p['id'] === OpenModels::provider(),
            ];
        }, $this->copy()['kinds'][$kind] ?? []);
    }

    /** @return list<array> the models the operator added to the open-model provider, as the copy's are, marked `extra` (none that the copy has) */
    private function extraModels(array $p, array $mine): array
    {
        if ($p['id'] !== OpenModels::provider()) {
            return [];
        }
        $inCopy = array_column($p['models'] ?? [], 'id');
        $extra = [];
        foreach ((array) ($mine['extraModels'] ?? []) as $id => $m) {
            if (is_array($m) && ! in_array((string) $id, $inCopy, true)) {
                $extra[] = ['id' => (string) $id, 'name' => $m['listName'] ?? (string) $id, 'extra' => true];
            }
        }

        return $extra;
    }

    /** @return list<array> the providers of a kind customers may pick */
    public function offered(string $kind): array
    {
        return array_values(array_filter($this->providers($kind), fn (array $p) => $p['offered']));
    }

    /** An offered provider by the kit's key, or null. */
    public function offeredByKey(string $kind, ?string $key): ?array
    {
        foreach ($this->offered($kind) as $p) {
            if ($p['key'] === $key) {
                return $p;
            }
        }

        return null;
    }

    /** One voice of a provider customers may hear and pick, or null. */
    public function voice(array $provider, mixed $id): ?CatalogVoice
    {
        return $this->voices->find($provider['id'], $id);
    }

    /**
     * A customer's whole stack (the kit's provider keys, a model id, a voice id) checked against what is offered, as
     * the agent's columns. A voice of the account's own (`own:<id>`) is taken when it is cloned ready on that provider.
     *
     * @return array<string, string>
     */
    public function stack(array $input, ?Workspace $workspace = null): array
    {
        $errors = [];
        $columns = [];
        foreach (config('kit.catalog.kinds') as $kind => $parts) {
            $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts['provider']);
            $provider = $this->offeredByKey($kind, is_string($input[$field] ?? null) ? $input[$field] : null);
            if ($provider === null) {
                $errors[$field] = [__('catalog.not_offered')];

                continue;
            }
            $columns[$parts['provider']] = $provider['id'];
            if (isset($parts['model'])) {
                $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts['model']);
                $model = collect($provider['curatedModels'])->first(fn (array $m) => $m['offered'] && $m['id'] === ($input[$field] ?? null));
                $model === null ? $errors[$field] = [__('catalog.not_offered')] : $columns[$parts['model']] = $model['id'];
            }
            if (isset($parts['voice'])) {
                $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts['voice']);
                if (OwnVoiceNames::isOwn($input[$field] ?? null)) {
                    $own = $workspace === null ? null : $this->ownVoices->ready($workspace, $provider['id'], $input[$field]);
                    $own === null ? $errors[$field] = [__('voices.unknown_voice')] : $columns[$parts['voice']] = OwnVoiceNames::telenowName($own);

                    continue;
                }
                $voice = $this->voice($provider, $input[$field] ?? null);
                $voice === null ? $errors[$field] = [__('voices.unknown_voice')] : $columns[$parts['voice']] = $voice->voice_id;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $columns;
    }

    /**
     * An agent's stack as customers read it: the kit's provider keys and Telenow's model and voice ids, each with the
     * name customers know it by (null when it is not offered).
     *
     * @return array{stack: array<string, ?string>, names: array<string, ?string>}
     */
    public function presentStack(Agent $agent): array
    {
        $stack = [];
        $names = [];
        foreach (config('kit.catalog.kinds') as $kind => $parts) {
            $id = $agent->getAttribute($parts['provider']);
            $provider = is_string($id) ? collect($this->offered($kind))->firstWhere('id', $id) : null;
            $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts['provider']);
            $stack[$field] = is_string($id) ? self::publicKey($kind, $id) : null;
            $names[$field] = $provider['displayName'] ?? null;
            foreach (['model' => fn () => collect($provider['curatedModels'] ?? [])->first(fn (array $m) => $m['offered'] && $m['id'] === $agent->getAttribute($parts['model']))['displayName'] ?? null,
                'voice' => fn () => $provider ? $this->voice($provider, $agent->getAttribute($parts['voice']))?->name : null] as $part => $name) {
                if (isset($parts[$part])) {
                    $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts[$part]);
                    $stack[$field] = $agent->getAttribute($parts[$part]);
                    $names[$field] = $name();
                }
            }
            $own = isset($parts['voice']) && $provider !== null ? $this->ownVoices->onAgent($agent, $provider['id'], $agent->getAttribute($parts['voice'])) : null;
            if ($own !== null) {
                $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts['voice']);
                $stack[$field] = OwnVoiceNames::kitName($own);
                $names[$field] = $own->name;
            }
        }

        return ['stack' => $stack, 'names' => $names];
    }

    /** Whether customers may pick the whole of what the agent has of this kind: its provider, and its model or voice. */
    public function offersStackOf(Agent $agent, string $kind): bool
    {
        $names = $this->presentStack($agent)['names'];
        foreach (config('kit.catalog.kinds')[$kind] as $column) {
            if ($names[$this->manifest->writeName(AgentWrite::RESOURCE, $column)] === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save the operator's choices and the new-agent defaults (Telenow's ids, as Admin → Catalog sends them).
     *
     * @param  array<string, array<string, array>>  $kinds
     * @param  array<string, ?string>  $defaults  by the agent's API names
     */
    public function save(array $kinds, array $defaults): void
    {
        if ($this->copy() === null) {
            throw new CatalogRefused(409, __('catalog.not_copied'));
        }
        $errors = [];
        $max = config('kit.agents.name_max_length');
        $curation = $this->curation();
        foreach ($kinds as $kind => $providers) {
            $known = collect($this->copy()['kinds'][$kind] ?? [])->keyBy('id');
            if (! array_key_exists($kind, config('kit.catalog.kinds')) || ! is_array($providers)) {
                $errors["kinds.{$kind}"] = [__('catalog.unknown')];

                continue;
            }
            foreach ($providers as $id => $choice) {
                $p = $known[$id] ?? null;
                if ($p === null || ! is_array($choice)) {
                    $errors["kinds.{$kind}.{$id}"] = [__('catalog.unknown')];

                    continue;
                }
                $entry = ['offered' => (bool) ($choice['offered'] ?? false), 'name' => $this->ownName($choice['name'] ?? null), 'models' => []];
                $name = $entry['name'] ?? $p['name'] ?? $id;
                if ($entry['name'] !== null && mb_strlen($entry['name']) > $max) {
                    $errors["kinds.{$kind}.{$id}.name"] = [__('catalog.name_max', ['max' => $max])];
                } elseif ($entry['offered'] && self::namesPlatform($name)) {
                    $errors["kinds.{$kind}.{$id}.name"] = [__('catalog.names_platform', ['platform' => config('kit.telenow.name')])];
                }
                $models = collect($p['models'] ?? [])->keyBy('id');
                foreach ((array) ($choice['models'] ?? []) as $modelId => $m) {
                    if ($kind !== 'llm' || ! isset($models[$modelId]) || ! is_array($m)) {
                        $errors["kinds.{$kind}.{$id}.models.{$modelId}"] = [__('catalog.unknown')];

                        continue;
                    }
                    $own = ['offered' => (bool) ($m['offered'] ?? true), 'name' => $this->ownName($m['name'] ?? null)];
                    $modelName = $own['name'] ?? $models[$modelId]['name'] ?? $modelId;
                    if ($own['name'] !== null && mb_strlen($own['name']) > $max) {
                        $errors["kinds.{$kind}.{$id}.models.{$modelId}.name"] = [__('catalog.name_max', ['max' => $max])];
                    } elseif ($entry['offered'] && $own['offered'] && self::namesPlatform($modelName)) {
                        $errors["kinds.{$kind}.{$id}.models.{$modelId}.name"] = [__('catalog.names_platform', ['platform' => config('kit.telenow.name')])];
                    }
                    $entry['models'][$modelId] = $own;
                }
                // The models added to the open-model provider: those sent are its added models now; none sent, those added before stay.
                $entry['extraModels'] = array_key_exists('extraModels', $choice)
                    ? $this->extraChoices($p, $choice['extraModels'], $curation[$kind][$id]['extraModels'] ?? [], $entry['offered'], "kinds.{$kind}.{$id}.extraModels", $errors)
                    : ($curation[$kind][$id]['extraModels'] ?? []);
                // A provider's settings sent are its settings now; none sent, those chosen before stay.
                $entry['settings'] = array_key_exists('settings', $choice)
                    ? $this->providerSettings->choices($p['settings'] ?? [], $choice['settings'], "kinds.{$kind}.{$id}.settings", $errors)
                    : ($curation[$kind][$id]['settings'] ?? []);
                $curation[$kind][$id] = $entry;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $saved = $this->settings->get(self::CURATION);
        $this->settings->put(self::CURATION, $curation);
        try {
            $columns = $this->defaultColumns($defaults);
        } catch (ValidationException $e) {
            $this->settings->put(self::CURATION, $saved ?? []); // all or nothing

            throw $e;
        }
        $this->settings->put(self::DEFAULTS, $columns);
    }

    /**
     * The models the operator sends as added to the open-model provider, checked: each a model of OpenRouter's list when added (one added before
     * stays, under the list's name of then, whether the list can be read now or not), none the catalog has, at most
     * config('kit.catalog.open_models.models_max'), each name as a model's.
     *
     * @return array<string, array{offered: bool, name: ?string, listName: string}>
     */
    private function extraChoices(array $p, mixed $sent, array $before, bool $offered, string $at, array &$errors): array
    {
        if ($p['id'] !== OpenModels::provider() || ! is_array($sent) || array_is_list($sent) && $sent !== []) {
            $errors[$at] = [__('catalog.open_models_not_here')];

            return $before;
        }
        $max = (int) config('kit.catalog.open_models.models_max');
        if (count($sent) > $max) {
            $errors[$at] = [__('catalog.open_models_max', ['max' => $max])];

            return $before;
        }
        $inCopy = array_column($p['models'] ?? [], 'id');
        $nameMax = config('kit.agents.name_max_length');
        $list = null;
        $out = [];
        foreach ($sent as $id => $m) {
            $id = (string) $id;
            if (! OpenModels::shaped($id) || ! is_array($m)) {
                $errors["{$at}.{$id}"] = [__('catalog.open_model_unknown')];

                continue;
            }
            if (in_array($id, $inCopy, true)) {
                $errors["{$at}.{$id}"] = [__('catalog.open_model_in_catalog')];

                continue;
            }
            // Added before: kept under OpenRouter's name of then, the list not read. New: it must be in the list, read once for all of them.
            $listName = is_array($before[$id] ?? null) && is_string($before[$id]['listName'] ?? null) ? $before[$id]['listName'] : null;
            if ($listName === null) {
                $list ??= $this->openModels->all() ?? [];
                $listName = $list[$id] ?? null;
            }
            if ($listName === null) {
                $errors["{$at}.{$id}"] = [$list === [] ? __('catalog.open_models_unread') : __('catalog.open_model_unknown')];

                continue;
            }
            $own = ['offered' => (bool) ($m['offered'] ?? true), 'name' => $this->ownName($m['name'] ?? null), 'listName' => $listName];
            if ($own['name'] !== null && mb_strlen($own['name']) > $nameMax) {
                $errors["{$at}.{$id}.name"] = [__('catalog.name_max', ['max' => $nameMax])];
            } elseif ($offered && $own['offered'] && self::namesPlatform($own['name'] ?? $listName)) {
                $errors["{$at}.{$id}.name"] = [__('catalog.names_platform', ['platform' => config('kit.telenow.name')])];
            }
            $out[$id] = $own;
        }

        return $out;
    }

    /** @return array<string, ?string> the defaults by column, each one offered (or none) */
    private function defaultColumns(array $defaults): array
    {
        $errors = [];
        $columns = [];
        foreach (config('kit.catalog.kinds') as $kind => $parts) {
            $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts['provider']);
            $id = is_string($defaults[$field] ?? null) && $defaults[$field] !== '' ? $defaults[$field] : null;
            $provider = $id === null ? null : collect($this->offered($kind))->firstWhere('id', $id);
            if ($id !== null && $provider === null) {
                $errors["defaults.{$field}"] = [__('catalog.default_not_offered')];
            }
            $columns[$parts['provider']] = $provider['id'] ?? null;
            foreach (['model' => fn (string $v) => collect($provider['curatedModels'] ?? [])->first(fn (array $m) => $m['offered'] && $m['id'] === $v),
                'voice' => fn (string $v) => $provider ? $this->voice($provider, $v) : null] as $part => $find) {
                if (! isset($parts[$part])) {
                    continue;
                }
                $field = $this->manifest->writeName(AgentWrite::RESOURCE, $parts[$part]);
                $value = is_string($defaults[$field] ?? null) && $defaults[$field] !== '' ? $defaults[$field] : null;
                if ($value !== null && $find($value) === null) {
                    $errors["defaults.{$field}"] = [__('catalog.default_not_offered')];
                }
                $columns[$parts[$part]] = $value;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $columns;
    }

    /** A provider as the kit keeps it: the allow-listed fields, its models', and its default model. */
    private function copied(array $p): array
    {
        $keep = fn (array $from, string $what) => array_intersect_key($from, array_flip(config("kit.catalog.fields.{$what}")));
        $models = [];
        foreach ($p['models'] ?? [] as $m) {
            $id = $m['id'] ?? $m['key'] ?? null; // language models name themselves `id`; speech models `key`
            if (is_array($m) && is_string($id)) {
                $models[] = ['id' => $id] + $keep($m, 'model');
            }
        }
        $configKey = $p['models'][0]['configKey'] ?? null;
        $default = null;
        foreach (is_array($p['configFields'] ?? null) ? $p['configFields'] : [] as $f) {
            if (is_array($f) && ($f['key'] ?? null) === $configKey && is_string($f['defaultValue'] ?? null)) {
                $default = $f['defaultValue'];
            }
        }

        return $keep($p, 'provider') + ['models' => $models, 'defaultModel' => $default, 'settings' => $this->providerSettings->copied($p)];
    }

    private function ownName(mixed $name): ?string
    {
        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
