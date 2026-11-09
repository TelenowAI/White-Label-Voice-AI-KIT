<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Calls\CallHealth;
use App\Kit\Calls\CallHealthRefused;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Admin → a customer's call health (contract/kit-api.yaml getCustomerCallHealth; App\Kit\Calls\CallHealth): the providers that failed on the
 * customer's recent calls, with the platform's provider id, the cause as the platform says it and its last error. Ability `customers_read`. Every
 * customer's, added up: Admin → Voice platform (`callHealth`).
 */
final class CallHealthController
{
    public function __construct(private readonly CallHealth $health) {}

    public function customer(Request $request, string $id): JsonResponse
    {
        $workspace = Str::isUuid($id) ? Workspace::query()->find($id) : null;
        if ($workspace === null) {
            return response()->json(['message' => __('customers.not_found')], 404);
        }
        $minutes = CallHealth::minutes($request->query('minutes'));
        try {
            return response()->json($this->health->account($workspace, $minutes, staff: true));
        } catch (CallHealthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
