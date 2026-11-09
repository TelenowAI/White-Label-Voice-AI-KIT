<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Memory\CallerMemory;
use App\Kit\Memory\MemoryRefused;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Console → Caller memory (contract/kit-api.yaml getCallerMemory, forgetCaller): what the account's agents remember, for every member; forgetting, for those who manage the account. */
final class MemoryController
{
    public function __construct(private readonly CallerMemory $memory) {}

    public function index(Request $request): JsonResponse
    {
        return $this->answer(fn () => response()->json($this->memory->list($this->member($request))));
    }

    public function destroy(Request $request): JsonResponse
    {
        return $this->answer(fn () => response()->json(['removed' => $this->memory->forget($this->member($request), $request->query())]));
    }

    private function member(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function answer(\Closure $act): JsonResponse
    {
        try {
            return $act();
        } catch (MemoryRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
