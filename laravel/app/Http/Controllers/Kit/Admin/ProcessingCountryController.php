<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Catalog\CatalogRefused;
use App\Kit\Catalog\ProcessingCountry;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The processing country (contract/kit-api.yaml getPlatformProcessingCountry, getCustomerProcessingCountry; App\Kit\Catalog\ProcessingCountry):
 * the operator's account's on Admin → Voice platform (ability `health`), a customer's on their page (`customers_read`). `?fresh=1` reads it from
 * Telenow now. Admin only (owner's choice 11-09): customers never see it.
 */
final class ProcessingCountryController
{
    public function __construct(private readonly ProcessingCountry $country) {}

    public function platform(Request $request): JsonResponse
    {
        return $this->refusals(fn () => $this->country->operator($request->boolean('fresh')));
    }

    public function customer(Request $request, string $id): JsonResponse
    {
        $workspace = Str::isUuid($id) ? Workspace::query()->find($id) : null;
        if ($workspace === null) {
            return response()->json(['message' => __('customers.not_found')], 404);
        }

        return $this->refusals(fn () => $this->country->of($workspace, $request->boolean('fresh')));
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
