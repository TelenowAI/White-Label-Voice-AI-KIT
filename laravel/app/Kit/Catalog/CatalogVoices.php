<?php

namespace App\Kit\Catalog;

use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\CatalogVoice;
use Illuminate\Support\Facades\DB;

/**
 * Every voice customers may hear and pick, in the kit's own table (`catalog_voices`):
 *
 * - refresh(): each voice provider's whole list from Telenow (`listProviderVoices`, its default model's voices), keeping
 *   only the fields row() names — never a sample's address, an owner's id or a field Telenow adds later. Telenow's
 *   hand-picked voices (getCatalog) add their marks and details to the same voice, and stand in only while no list has ever
 *   been read. A voice no longer listed is gone at the vendor too (Telenow's own sync removes it), so it is not kept.
 * - A list that cannot be read, or that says nothing (`origin: none`), leaves what the kit has: "told nothing" never means
 *   "no voices". Only `offered: false` empties a provider. A list is replaced in one transaction, so a reader sees the old
 *   one or the new one.
 * - page(), find(), counts(): reading them. A voice whose name carries the platform's name (Catalog::namesPlatform) is left
 *   out when copied, and any other text that does is dropped; a change of that name applies from the next copy.
 *
 * Not here: which providers are offered, an agent's stack (Catalog); previews (VoicePreview); the pages
 * (Console\VoicesController, Admin\CatalogController).
 */
final class CatalogVoices
{
    /** What a page of voices can be narrowed by (contract/kit-api.yaml listVoices, listCatalogVoices). */
    public const FILTERS = [
        'provider' => ['nullable', 'string'], 'language' => ['nullable', 'string', 'max:35'], 'gender' => ['nullable', 'string', 'max:20'],
        'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'model' => ['nullable', 'string', 'max:64'],
        'starred' => ['nullable', 'boolean'],
    ];

    private const ID_MAX_LENGTH = 255;

    public function __construct(private readonly TelenowClient $telenow) {}

    /**
     * Copy each provider's list, with the operator's key.
     *
     * @param  list<array>  $providers  the catalog's voice providers, as the kit copied them
     * @param  array<string, mixed>  $handPicked  the catalog's voices, by provider id
     * @return list<string> the names of the providers whose list could not be read
     */
    public function refresh(string $key, array $providers, array $handPicked): array
    {
        CatalogVoice::query()->whereNotIn('provider_id', array_column($providers, 'id'))->delete();
        $failed = [];
        foreach ($providers as $p) {
            if (($p['enabled'] ?? true) === false) {
                continue;
            }
            $picked = is_array($handPicked[$p['id']] ?? null) ? $handPicked[$p['id']] : [];
            $models = self::modelsOf($p);
            $listed = [];
            $offered = true;
            foreach ($models as $model) {
                try {
                    $answer = $this->telenow->providerVoices($key, $p['id'], $model);
                } catch (TelenowError) {
                    $failed[] = $p['name'] ?? $p['id'];
                    $listed = [];

                    break;
                }
                if (($answer['offered'] ?? null) === false) {
                    $offered = false;

                    break;
                }
                foreach (is_array($answer['data'] ?? null) ? $answer['data'] : [] as $v) {
                    $listed[] = [$v, $model];
                }
            }
            if (! $offered) {
                $this->replace($p['id'], []);
            } elseif ($listed !== []) {
                $this->replace($p['id'], $this->rows($listed, $p, $picked, count($models) > 1));
            } elseif (! CatalogVoice::query()->where('provider_id', $p['id'])->exists()) {
                $this->replace($p['id'], $this->rows(array_map(fn ($v) => [$v, is_array($v) && is_string($v['model'] ?? null) ? $v['model'] : null], $picked), $p, $picked, count($models) > 1));
            }
        }

        return $failed;
    }

    /**
     * One page of the voices of these providers, in their order, with the languages and genders there are to narrow by among
     * them.
     *
     * @param  list<array>  $providers  (Catalog::providers)
     * @param  array{language?: ?string, gender?: ?string, search?: ?string, model?: ?string, starred?: mixed}  $filters  `model`: Telenow's own value (Console\VoicesController maps a customer's)
     * @param  list<array{0: string, 1: string}>  $starred  the asking member's stars (VoiceStars), as [provider id, voice id]: each voice says whether it is one, and `starred` keeps only those
     */
    public function page(array $providers, array $filters, int $page, int $perPage, array $starred = []): array
    {
        $ids = array_column($providers, 'id');
        $query = CatalogVoice::query()->whereIn('provider_id', $ids);
        if (filter_var($filters['starred'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->where(function ($q) use ($starred) {
                $q->whereRaw('1 = 0');
                foreach ($starred as [$provider, $voice]) {
                    $q->orWhere(fn ($one) => $one->where('provider_id', $provider)->where('voice_id', $voice));
                }
            });
        }
        $stars = array_flip(array_map(fn (array $s) => $s[0]."\n".$s[1], $starred));
        $among = clone $query;
        if (! empty($filters['language'])) {
            $query->whereRaw("language_bases LIKE ? ESCAPE '!'", ['%|'.self::escaped(self::base($filters['language'])).'|%']);
        }
        if (! empty($filters['gender'])) {
            $query->where('gender', mb_strtolower($filters['gender']));
        }
        if (! empty($filters['model'])) {
            $query->where(fn ($q) => $q->where('models', '')->orWhereRaw("models LIKE ? ESCAPE '!'", ['%|'.self::escaped($filters['model']).'|%']));
        }
        $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
        if ($search !== '') {
            $query->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.self::escaped($search).'%']);
        }
        $total = $query->count();
        $rows = $page > ceil($total / $perPage) ? collect() : $query
            ->orderByRaw('CASE provider_id '.implode(' ', array_map(fn (int $place) => "WHEN ? THEN {$place}", array_keys($ids))).' END', $ids)
            ->orderBy('position')->forPage($page, $perPage)->get();
        $byId = array_column($providers, null, 'id');

        return [
            'voices' => $rows->map(fn (CatalogVoice $v) => [
                'provider' => $byId[$v->provider_id]['key'], 'providerName' => $byId[$v->provider_id]['displayName'], 'id' => $v->voice_id, 'name' => $v->name,
                'language' => $v->language, 'gender' => $v->gender, 'accent' => $v->accent, 'style' => $v->style, 'useCase' => $v->use_case,
                'description' => $v->description, 'tags' => $v->tags, 'recommended' => $v->recommended, 'starred' => isset($stars[$v->provider_id."\n".$v->voice_id]),
            ])->all(),
            'total' => $total, 'page' => $page, 'perPage' => $perPage,
            'languages' => (clone $among)->distinct()->pluck('language_bases')->flatMap(fn (string $b) => explode('|', $b))->filter()->unique()->sort()->values()->all(),
            'genders' => (clone $among)->whereNotNull('gender')->distinct()->orderBy('gender')->pluck('gender')->all(),
        ];
    }

    /** Whether a copied voice speaks with this model (null: whichever the provider uses). */
    public static function fits(CatalogVoice $voice, ?string $model): bool
    {
        return $model === null || $voice->models === '' || $voice->models === null || str_contains($voice->models, '|'.$model.'|');
    }

    /**
     * The models to read a provider's voices for: its default first, then every other its model setting offers (ProviderSettings), or just its default when it has no
     * choice of model.
     *
     * @return list<?string>
     */
    public static function modelsOf(array $provider): array
    {
        $field = ProviderSettings::modelField(is_array($provider['settings'] ?? null) ? $provider['settings'] : []);
        $others = array_values(array_filter(array_map(fn (array $o) => $o['value'], $field['options'] ?? []), fn ($m) => is_string($m) && $m !== ''));
        $default = is_string($provider['defaultModel'] ?? null) ? $provider['defaultModel'] : null;

        if ($others === []) {
            return [$default];
        }

        return array_values(array_unique(array_merge($default === null ? [] : [$default], $others)));
    }

    /** One voice of a provider, or null. */
    public function find(string $providerId, mixed $voiceId): ?CatalogVoice
    {
        return is_string($voiceId) ? CatalogVoice::query()->where('provider_id', $providerId)->where('voice_id', $voiceId)->first() : null;
    }

    /** @return array<string, int> how many voices each provider has, by provider id */
    public function counts(): array
    {
        return CatalogVoice::query()->selectRaw('provider_id, count(*) as voices')->groupBy('provider_id')->pluck('voices', 'provider_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @param  list<array>  $rows */
    private function replace(string $providerId, array $rows): void
    {
        DB::transaction(function () use ($providerId, $rows) {
            CatalogVoice::query()->where('provider_id', $providerId)->delete();
            foreach (array_chunk($rows, config('kit.catalog.voices_write_chunk')) as $chunk) {
                CatalogVoice::query()->insertOrIgnore($chunk); // ids the database's collation takes for one (MySQL ignores case) stay one voice
            }
        });
    }

    /**
     * The lists as rows: one per voice id, with the models it was listed for (a voice tagged for another model, or naming the models it speaks with and not this one, is
     * not this model's), recommended voices first, otherwise in the lists' order.
     *
     * @param  list<array{0: mixed, 1: ?string}>  $listed  each voice, with the model its list was read for
     */
    private function rows(array $listed, array $provider, array $picked, bool $byModel): array
    {
        $details = array_column(array_filter($picked, fn ($v) => is_array($v) && is_string($v['id'] ?? null)), null, 'id');
        $rows = [];
        $models = [];
        foreach ($listed as [$v, $model]) {
            $id = is_array($v) ? ($v['id'] ?? null) : null;
            if (! is_string($id) || $id === '' || mb_strlen($id) > self::ID_MAX_LENGTH
                || ($model !== null && isset($v['model']) && $v['model'] !== $model)
                || ($model !== null && is_array($v['models'] ?? null) && $v['models'] !== [] && ! in_array($model, $v['models'], true))) {
                continue;
            }
            if ($model !== null) {
                $models[$id][$model] = true;
            }
            if (isset($rows[$id])) {
                continue;
            }
            $row = $this->row($provider['id'], $v + ($details[$id] ?? []));
            if ($row !== null) {
                $rows[$id] = $row;
            }
        }
        foreach ($rows as $id => $row) {
            $rows[$id]['models'] = $byModel && ($models[$id] ?? []) !== [] ? '|'.implode('|', array_keys($models[$id])).'|' : '';
        }
        $ordered = array_merge(array_filter($rows, fn (array $r) => $r['recommended']), array_filter($rows, fn (array $r) => ! $r['recommended']));

        return array_map(fn (array $r, int $i) => $r + ['position' => $i], array_values($ordered), array_keys(array_values($ordered)));
    }

    /** One voice as the kit keeps it: only these fields, none that names the platform. Null when its name does. */
    private function row(string $providerId, array $v): ?array
    {
        $name = is_string($v['name'] ?? null) && trim($v['name']) !== '' ? $v['name'] : $v['id'];
        if (Catalog::namesPlatform($name)) {
            return null;
        }
        $said = fn (mixed $text) => is_string($text) && trim($text) !== '' && ! Catalog::namesPlatform($text) ? $text : null;
        $languages = array_filter(array_merge([$v['language'] ?? null], is_array($v['languages'] ?? null) ? $v['languages'] : []), fn ($l) => is_string($l) && $l !== '');
        $tags = array_values(array_filter(is_array($v['tags'] ?? null) ? $v['tags'] : [], fn ($t) => $said($t) !== null));
        $accent = $said($v['accent'] ?? null);
        $style = $said($v['style'] ?? null);
        $useCase = $said($v['use_case'] ?? $v['useCase'] ?? null);
        $description = $said($v['description'] ?? null);
        $gender = $said($v['gender'] ?? null);

        return [
            'provider_id' => $providerId, 'voice_id' => $v['id'], 'name' => $name, 'language' => $said($v['language'] ?? null),
            'language_bases' => '|'.implode('|', array_unique(array_map(self::base(...), $languages))).'|',
            'gender' => $gender === null ? null : mb_strtolower($gender), 'accent' => $accent, 'style' => $style, 'use_case' => $useCase, 'description' => $description,
            'tags' => json_encode($tags), 'recommended' => ($v['recommended'] ?? false) === true,
            'search_text' => mb_strtolower(implode(' ', array_filter([$name, $description, $accent, $style, $useCase, ...$tags]))),
        ];
    }

    /** A language tag's language: `hi-IN` → `hi` (lower case). */
    private static function base(string $tag): string
    {
        return mb_strtolower(explode('-', str_replace('_', '-', trim($tag)))[0]);
    }

    /** Text taken as it is in a LIKE ... ESCAPE '!'. */
    private static function escaped(string $text): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);
    }
}
