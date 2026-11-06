<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Team\Team;
use App\Kit\Team\TeamRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * An invite link (contract/kit-api.yaml getInvite, acceptInvite): what it is for, and accepting it. Public — the
 * token is the secret. Unknown, used, revoked and expired links all answer the same 404. Accepting signs the person
 * in (Team::accept). Slowed by the `kit-invite-accept` limiter (AppServiceProvider).
 */
final class InvitesController
{
    public function __construct(private readonly Team $team) {}

    public function show(string $token): JsonResponse
    {
        $invite = $this->team->pending($token);
        if ($invite === null) {
            return $this->gone();
        }

        return response()->json([
            'email' => $invite->email,
            'customerName' => $invite->workspace->name,
            'role' => $invite->role,
            'expiresAt' => $invite->expires_at->toIso8601String(),
            ...$this->team->acceptance($invite),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invite = $this->team->pending($token);
        if ($invite === null) {
            return $this->gone();
        }
        try {
            $user = $this->team->accept($invite, $request->all());
        } catch (TeamRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json(['user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role]]);
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => __('team.no_invite')], 404);
    }
}
