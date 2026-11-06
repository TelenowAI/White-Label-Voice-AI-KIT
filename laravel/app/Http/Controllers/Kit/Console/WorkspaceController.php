<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Console\CurrentWorkspace;
use App\Kit\Console\ServicePause;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The console's account routes (contract/kit-api.yaml listMyWorkspaces, selectWorkspace, getConsoleHome): the
 * accounts the signed-in user belongs to, picking one, and its home — its name and status, the user's role in it,
 * and whether the service is paused for it (ServicePause). Read from the kit's DB only.
 */
final class WorkspaceController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ServicePause $pause) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['message' => __('customers.sign_in')], 401);
        }

        return response()->json(['workspaces' => $this->current->all($request)]);
    }

    public function select(Request $request): JsonResponse
    {
        if ($request->user() === null) {
            return response()->json(['message' => __('customers.sign_in')], 401);
        }
        $values = $request->validate(['id' => ['required', 'string']]);
        if (! $this->current->choose($request, $values['id'])) {
            return response()->json(['message' => __('console.not_your_account')], 404);
        }

        return response()->json(['workspaces' => $this->current->all($request)]);
    }

    public function home(Request $request): JsonResponse
    {
        /** @var WorkspaceMember $membership */
        $membership = $request->attributes->get('kit.membership');
        $workspace = $membership->workspace;

        return response()->json([
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name, 'status' => $workspace->status],
            'role' => $membership->role,
            'canManageTeam' => in_array($membership->role, config('kit.team.managers'), true),
            'paused' => $this->pause->for($workspace),
        ]);
    }
}
