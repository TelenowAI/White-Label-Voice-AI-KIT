<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Pricing\Pricing;
use App\Kit\Support\Json;
use App\Kit\Wallet\BalanceAlerts;
use App\Kit\Wallet\CallCharges;
use App\Kit\Wallet\FeeCharges;
use App\Models\PriceList;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin → Pricing (contract/kit-api.yaml getPricing, updatePricing, savePriceList, deletePriceList,
 * setLowBalanceDefault): the currency, the USD rate and the default plan; the price lists, each in the pricing spec's
 * format (contract/pricing/README.md) with what uses it; and the balance customers are warned at unless they chose
 * their own (App\Kit\Wallet\BalanceAlerts). Saving a list tries again the calls and number fees no list could price.
 */
final class PricingController
{
    public function __construct(
        private readonly Pricing $pricing,
        private readonly CallCharges $charges,
        private readonly FeeCharges $fees,
        private readonly BalanceAlerts $alerts,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->present());
    }

    public function update(Request $request): JsonResponse
    {
        $this->pricing->updateSettings($request->only(['currency', 'usdRate', 'defaultPlan']) + ['defaultPlan' => null]);

        return response()->json($this->present());
    }

    /** The default warning level (`lowBalanceAt`: empty or null for none). */
    public function lowBalance(Request $request): JsonResponse
    {
        $this->alerts->setDefault($request->input('lowBalanceAt'));

        return response()->json($this->present());
    }

    public function saveList(Request $request, string $listId): JsonResponse
    {
        // Read the body as sent: `{}` must stay an object for the format check (App\Kit\Support\Json).
        $body = (array) Json::decode($request->getContent() ?: '{}');
        $list = $this->pricing->saveList($listId, (string) ($body['name'] ?? ''), $body['definition'] ?? null);
        $this->charges->retryRefused();
        $this->fees->retryRefused();

        return response()->json($this->list($list));
    }

    public function deleteList(string $listId): JsonResponse|Response
    {
        if (! PriceList::query()->whereKey($listId)->exists()) {
            return response()->json(['message' => __('pricing.not_found')], 404);
        }
        $why = $this->pricing->deleteList($listId);

        return $why === null ? response()->noContent() : response()->json(['message' => $why], 409);
    }

    private function present(): array
    {
        return $this->pricing->settings() + [
            'lowBalanceAt' => $this->alerts->defaultLevel(),
            'inForce' => $this->pricing->inForce(),
            'lists' => PriceList::query()->orderBy('id')->get()->map($this->list(...))->all(),
        ];
    }

    private function list(PriceList $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'definition' => $l->definition,
            'customers' => Workspace::query()->where('price_list_id', $l->id)->count(),
            'updatedAt' => $l->updated_at->toIso8601String(),
        ];
    }
}
