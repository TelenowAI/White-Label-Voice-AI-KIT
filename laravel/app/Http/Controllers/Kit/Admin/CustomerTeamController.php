<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Staff\Abilities;
use App\Kit\Team\Team;
use App\Kit\Team\TeamRefused;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Admin → a customer's team (contract/kit-api.yaml listCustomerTeam, inviteCustomerMember, revokeCustomerInvite,
 * removeCustomerMember): the operator's staff see and manage any account's members and invites — how a new account
 * gets its first user. Staff only, after the install (routes/api.php).
 */
final class CustomerTeamController
{
    public function __construct(private readonly Team $team) {}

    public function index(Request $request, string $id): JsonResponse
    {
        return $this->with($id, fn (Workspace $w) => response()->json($this->team->list($w) + [
            'canManage' => Abilities::may($request->user(), 'customers_manage') && ! in_array($w->status, Workspace::ENDED, true),
        ]));
    }

    public function invite(Request $request, string $id): JsonResponse
    {
        return $this->with($id, fn (Workspace $w) => response()->json($this->team->invite($w, $request->all(), $request->user()), 201));
    }

    public function revokeInvite(string $id, string $inviteId): JsonResponse|Response
    {
        return $this->with($id, function (Workspace $w) use ($inviteId) {
            $this->team->revoke($w, $inviteId);

            return response()->noContent();
        });
    }

    public function removeMember(string $id, string $userId): JsonResponse|Response
    {
        return $this->with($id, function (Workspace $w) use ($userId) {
            $this->team->remove($w, (int) $userId);

            return response()->noContent();
        });
    }

    private function with(string $id, \Closure $act): JsonResponse|Response
    {
        $workspace = Str::isUuid($id) ? Workspace::query()->find($id) : null;
        if ($workspace === null) {
            return response()->json(['message' => __('customers.not_found')], 404);
        }
        try {
            return $act($workspace);
        } catch (TeamRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
