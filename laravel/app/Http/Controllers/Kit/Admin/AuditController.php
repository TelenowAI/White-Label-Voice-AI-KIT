<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Audit\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin → Audit log (contract/kit-api.yaml listAuditEvents): a page of it, newest first (App\Kit\Audit\Audit). */
final class AuditController
{
    public function index(Request $request, Audit $audit): JsonResponse
    {
        $values = $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($audit->page((int) ($values['page'] ?? 1)));
    }
}
