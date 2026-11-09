<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\CatalogRefused;
use App\Kit\Catalog\OpenModels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Catalog → Add models (contract/kit-api.yaml listOpenModels, getOpenModelPrice): OpenRouter's own model list, a page at a time, searched,
 * with which are added or in the catalog already, and what Telenow charges the operator for one (App\Kit\Catalog\OpenModels). Adding them is the
 * catalog's save (saveCatalogCuration, `extraModels`). The operator's page: the price is theirs, never a customer's. Ability `settings`.
 */
final class OpenModelsController
{
    public function __construct(private readonly OpenModels $models, private readonly Catalog $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']]);
        $provider = $this->provider();
        if ($provider === null) {
            return response()->json(['message' => __('catalog.open_models_not_here')], 404);
        }
        $added = array_column(array_filter($provider['curatedModels'], fn (array $m) => ($m['extra'] ?? false) === true), 'id');

        return $this->refusals(fn () => $this->models->page($q['search'] ?? null, (int) ($q['page'] ?? 1), $added, array_column($provider['models'] ?? [], 'id')));
    }

    public function price(Request $request): JsonResponse
    {
        $q = $request->validate(['model' => ['required', 'string', 'max:200']]);
        if ($this->provider() === null) {
            return response()->json(['message' => __('catalog.open_models_not_here')], 404);
        }
        if (! OpenModels::shaped($q['model'])) {
            return response()->json(['message' => __('catalog.open_model_unknown'), 'errors' => ['model' => [__('catalog.open_model_unknown')]]], 422);
        }

        return $this->refusals(fn () => $this->models->price($q['model']));
    }

    /** The open-model provider as Admin → Catalog has it, or null when adding models is off or the catalog has no such provider. */
    private function provider(): ?array
    {
        return collect($this->catalog->providers('llm'))->first(fn (array $p) => $p['openModels']);
    }

    private function refusals(\Closure $read): JsonResponse
    {
        try {
            return response()->json($read());
        } catch (CatalogRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
