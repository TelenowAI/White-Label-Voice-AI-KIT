<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Team\Team;
use App\Kit\Team\TeamRefused;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Console → Team (contract/kit-api.yaml getTeam, inviteTeamMember, revokeTeamInvite, removeTeamMember): the current
 * account's members and invites. Every member sees them; only the account's managers (config('kit.team.managers'))
 * change them (403 otherwise).
 */
final class TeamController
{
    public function __construct(private readonly Team $team) {}

    public function index(Request $request): JsonResponse
    {
        $m = $this->membership($request);

        return response()->json($this->team->list($m->workspace) + ['canManage' => $this->manages($m)]);
    }

    public function invite(Request $request): JsonResponse
    {
        return $this->managed($request, fn (WorkspaceMember $m) => response()->json($this->team->invite($m->workspace, $request->all(), $request->user()), 201));
    }

    public function revokeInvite(Request $request, string $inviteId): JsonResponse|Response
    {
        return $this->managed($request, function (WorkspaceMember $m) use ($inviteId) {
            $this->team->revoke($m->workspace, $inviteId);

            return response()->noContent();
        });
    }

    public function removeMember(Request $request, string $userId): JsonResponse|Response
    {
        return $this->managed($request, function (WorkspaceMember $m) use ($userId) {
            $this->team->remove($m->workspace, (int) $userId);

            return response()->noContent();
        });
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }

    private function manages(WorkspaceMember $m): bool
    {
        return in_array($m->role, config('kit.team.managers'), true);
    }

    private function managed(Request $request, \Closure $act): JsonResponse|Response
    {
        $m = $this->membership($request);
        if (! $this->manages($m)) {
            return response()->json(['message' => __('team.managers_only')], 403);
        }
        try {
            return $act($m);
        } catch (TeamRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
