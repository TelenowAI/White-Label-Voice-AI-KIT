<?php

namespace App\Kit\Catalog;

use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use Illuminate\Support\Facades\Cache;

/**
 * The models the operator may add to the open-model provider (config('kit.catalog.open_models.provider'), OpenRouter), beyond the three or so the
 * catalog copy has (contract/kit-api.yaml listOpenModels, getOpenModelPrice; owner's choice 11-09). Telenow takes any OpenRouter model id on an
 * agent (createAgent 201 even for an id OpenRouter has not got, recorded), so the kit offers only ids in OpenRouter's own list as Telenow reads it
 * (`listOpenRouterModels`), text to text, and the operator picks them in Admin → Catalog (App\Kit\Catalog\Catalog keeps them as `extraModels`).
 *
 * - all(): the list, kept for list_minutes (an empty list — OpenRouter out of reach — is never kept); null when it cannot be read.
 * - page(): a page of it, searched by id or name, with which are added or in the catalog already.
 * - price(): what Telenow charges the operator per million tokens of a model (`getOpenRouterPrice`, its margin in: an estimate, as Telenow bills
 *   what OpenRouter reports for each request plus that margin). The operator's own cost, for Admin only: never shown to a customer.
 *
 * Not here: which models are added (Catalog), the pages (Admin\OpenModelsController).
 */
final class OpenModels
{
    private const CACHE = 'kit.catalog.open-models';

    public function __construct(private readonly Settings $settings, private readonly TelenowClient $telenow) {}

    /** The open-model provider's Telenow id, or null when adding models is off. */
    public static function provider(): ?string
    {
        $id = trim((string) config('kit.catalog.open_models.provider'));

        return $id === '' ? null : $id;
    }

    /** Whether an id has a model id's shape. */
    public static function shaped(mixed $id): bool
    {
        return is_string($id) && preg_match(config('kit.catalog.open_models.id_pattern'), $id) === 1;
    }

    /** @return ?array<string, string> OpenRouter's text-to-text models, name by id; null when the list cannot be read now */
    public function all(): ?array
    {
        $kept = Cache::get(self::CACHE);
        if (is_array($kept) && $kept !== []) {
            return $kept;
        }
        try {
            $rows = $this->telenow->openRouterModels($this->key());
        } catch (TelenowError) {
            return null;
        }
        $models = [];
        foreach ($rows as $m) {
            if (($m['textToText'] ?? false) === true && self::shaped($m['id'] ?? null)) {
                $models[$m['id']] = is_string($m['name'] ?? null) && trim($m['name']) !== '' ? trim($m['name']) : $m['id'];
            }
        }
        if ($models === []) {
            return null; // OpenRouter out of reach: Telenow answers an empty list
        }
        Cache::put(self::CACHE, $models, now()->addMinutes((int) config('kit.catalog.open_models.list_minutes')));

        return $models;
    }

    /**
     * A page of the list, by id or name containing the search (any case), each with whether it is added (`added`) or in the catalog already
     * (`inCatalog`, offered as the catalog's own).
     *
     * @param  list<string>  $added
     * @param  list<string>  $inCatalog
     */
    public function page(?string $search, int $page, array $added, array $inCatalog): array
    {
        $all = $this->all();
        if ($all === null) {
            throw new CatalogRefused(502, __('catalog.open_models_unread'));
        }
        $search = trim((string) $search);
        $found = array_filter($all, fn (string $name, string $id) => $search === '' || mb_stripos($id, $search) !== false || mb_stripos($name, $search) !== false, ARRAY_FILTER_USE_BOTH);
        $perPage = (int) config('kit.catalog.open_models.per_page');
        $page = max(1, $page);

        return [
            'provider' => self::provider(),
            'models' => array_map(fn (string $id) => ['id' => $id, 'name' => $found[$id], 'added' => in_array($id, $added, true), 'inCatalog' => in_array($id, $inCatalog, true)],
                array_slice(array_keys($found), ($page - 1) * $perPage, $perPage)),
            'page' => $page,
            'perPage' => $perPage,
            'total' => count($found),
            'max' => (int) config('kit.catalog.open_models.models_max'),
        ];
    }

    /** @return array{model: string, found: bool, inputPerM: ?float, outputPerM: ?float} the operator's cost per million tokens, in US dollars */
    public function price(string $model): array
    {
        try {
            $price = $this->telenow->openRouterPrice($this->key(), $model);
        } catch (TelenowError) {
            throw new CatalogRefused(502, __('catalog.open_model_price_unread'));
        }
        $found = ($price['found'] ?? false) === true;

        return ['model' => $model, 'found' => $found, 'inputPerM' => $found && is_numeric($price['inputPerM'] ?? null) ? (float) $price['inputPerM'] : null,
            'outputPerM' => $found && is_numeric($price['outputPerM'] ?? null) ? (float) $price['outputPerM'] : null];
    }

    private function key(): string
    {
        $key = $this->settings->get('telenow.api_key');
        if (! is_string($key) || $key === '') {
            throw new CatalogRefused(409, __('catalog.no_key'));
        }

        return $key;
    }
}
