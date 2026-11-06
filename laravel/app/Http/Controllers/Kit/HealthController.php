<?php

namespace App\Http\Controllers\Kit;

use Illuminate\Http\JsonResponse;

/**
 * GET /api/kit/health (contract/kit-api.yaml `getHealth`): the backend is up, whether the setup wizard has
 * finished, and the kit's version. Answers with no credentials, before and after install.
 *
 * Not here: the framework's own `/up` probe (bootstrap/app.php), which says nothing about the install.
 */
class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'installed' => is_file(config('kit.installed_marker')),
            'version' => config('kit.version'),
        ]);
    }
}
