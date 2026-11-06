<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Setup\Installation;
use App\Kit\Setup\SetupRefused;
use App\Kit\Setup\SetupWizard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/kit/setup (`getSetup`) and POST /api/kit/setup/steps/{step} (`submitSetupStep`) — contract/kit-api.yaml.
 * Both answer 404 once the install is finished: the installer locks itself. A refused submission answers
 * `{message}` with its status; a refused field, Laravel's 422 `{message, errors}`.
 *
 * Not here: the steps and their rules (App\Kit\Setup\SetupWizard).
 */
final class SetupController
{
    public function __construct(private readonly SetupWizard $wizard, private readonly Installation $installation) {}

    public function show(Request $request): JsonResponse
    {
        if ($this->installation->installed()) {
            return $this->refuse(404, __('setup.refusals.installed'));
        }

        return response()->json($this->wizard->state($request));
    }

    public function submit(Request $request, string $step): JsonResponse
    {
        if ($this->installation->installed()) {
            return $this->refuse(404, __('setup.refusals.installed'));
        }
        try {
            $this->wizard->submit($request, $step);
        } catch (SetupRefused $e) {
            return $this->refuse($e->status, $e->getMessage());
        }

        return response()->json($this->wizard->state($request, installed: $this->installation->installed()));
    }

    private function refuse(int $status, string $message): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
