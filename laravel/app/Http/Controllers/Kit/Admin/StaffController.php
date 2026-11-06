<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Http\Controllers\Kit\SessionController;
use App\Kit\Staff\Staff;
use App\Kit\Staff\StaffRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Admin → Staff (contract/kit-api.yaml listStaff, inviteStaff, revokeStaffInvite, updateStaffRole, removeStaff): the
 * operator's team, its invites and roles, with the roles and what each may do. The owner's alone (`staff` ability).
 *
 * Not here: the rules (App\Kit\Staff\Staff), accepting an invite (StaffInvitesController).
 */
final class StaffController
{
    public function __construct(private readonly Staff $staff) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->staff->list($request->user()) + [
            'roles' => array_map(fn (string $r) => ['value' => $r, 'label' => __("staff.roles.{$r}"), 'about' => __("staff.about.{$r}")], config('kit.admin.staff_roles')),
        ]);
    }

    public function invite(Request $request): JsonResponse
    {
        return $this->refusable(fn () => response()->json($this->staff->invite($request->all(), $request->user()), 201));
    }

    public function revokeInvite(Request $request, string $inviteId): JsonResponse|Response
    {
        return $this->refusable(function () use ($request, $inviteId) {
            $this->staff->revoke($inviteId, $request->user());

            return response()->noContent();
        });
    }

    public function update(Request $request, string $userId): JsonResponse
    {
        return $this->refusable(fn () => response()->json(SessionController::present(
            $this->staff->changeRole(ctype_digit($userId) ? (int) $userId : 0, $request->all(), $request->user()))));
    }

    public function remove(Request $request, string $userId): JsonResponse|Response
    {
        return $this->refusable(function () use ($request, $userId) {
            $this->staff->remove(ctype_digit($userId) ? (int) $userId : 0, $request->user());

            return response()->noContent();
        });
    }

    private function refusable(\Closure $do): JsonResponse|Response
    {
        try {
            return $do();
        } catch (StaffRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
