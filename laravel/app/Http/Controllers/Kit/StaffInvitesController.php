<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Staff\Staff;
use App\Kit\Staff\StaffRefused;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A staff invite link (contract/kit-api.yaml getStaffInvite, acceptStaffInvite): what it is for, and accepting it.
 * Public — the token is the secret; unknown, used, revoked and expired links all answer the same 404. Accepting signs
 * the person in (Staff::accept). Slowed by the `kit-invite-accept` limiter, like a customer's invite.
 */
final class StaffInvitesController
{
    public function __construct(private readonly Staff $staff) {}

    public function show(string $token): JsonResponse
    {
        $invite = $this->staff->pending($token);
        if ($invite === null) {
            return $this->gone();
        }

        return response()->json([
            'email' => $invite->email,
            'role' => $invite->role,
            'roleLabel' => __("staff.roles.{$invite->role}"),
            'expiresAt' => $invite->expires_at->toIso8601String(),
            'accountExists' => User::query()->whereRaw('lower(email) = ?', [$invite->email])->exists(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invite = $this->staff->pending($token);
        if ($invite === null) {
            return $this->gone();
        }
        try {
            return response()->json(['user' => SessionController::present($this->staff->accept($invite, $request->all()))]);
        } catch (StaffRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => __('staff.no_invite')], 404);
    }
}
