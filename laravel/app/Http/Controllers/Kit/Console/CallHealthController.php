<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Calls\CallHealth;
use App\Kit\Calls\CallHealthRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Console → call health (contract/kit-api.yaml getCallHealth; App\Kit\Calls\CallHealth): the providers that failed on the account's recent calls,
 * under the operator's names, with the agents whose calls they were. Every member sees it, as they see the calls. Never the raw error, the
 * platform's provider id or its key.
 */
final class CallHealthController
{
    public function __construct(private readonly CallHealth $health) {}

    public function show(Request $request): JsonResponse
    {
        $minutes = CallHealth::minutes($request->query('minutes'));
        try {
            return response()->json($this->health->account($request->attributes->get('kit.membership')->workspace, $minutes));
        } catch (CallHealthRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
