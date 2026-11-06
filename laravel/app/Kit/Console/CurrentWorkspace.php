<?php

namespace App\Kit\Console;

use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;

/**
 * Which customer account the signed-in user is working in: the one they picked (kept in their session), else the
 * first they joined. A person can belong to several accounts; every console route works in exactly one, and only
 * ever one the user is a member of right now — or, for staff looking at a customer's console, that customer (ViewAs).
 *
 * Used by EnsureMember and the console's workspace routes.
 */
final class CurrentWorkspace
{
    private const SESSION_KEY = 'kit.workspace';

    public function __construct(private readonly ViewAs $viewAs) {}

    public function membership(Request $request): ?WorkspaceMember
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return null;
        }
        // Staff looking at a customer's console (read-only): that customer, whatever else the user belongs to.
        $viewing = $this->viewAs->membership($request);
        if ($viewing !== null) {
            return $viewing;
        }
        $chosen = $request->session()->get(self::SESSION_KEY);
        $query = WorkspaceMember::query()->with('workspace')->where('user_id', $user->id);

        return ($chosen ? $query->clone()->where('workspace_id', $chosen)->first() : null)
            ?? $query->orderBy('created_at')->orderBy('id')->first();
    }

    /** Work in this account from now on. False when the user is not a member of it. */
    public function choose(Request $request, string $workspaceId): bool
    {
        $member = WorkspaceMember::query()->where('user_id', $request->user()?->id)->where('workspace_id', $workspaceId)->exists();
        if ($member) {
            $request->session()->put(self::SESSION_KEY, $workspaceId);
        }

        return $member;
    }

    /** @return list<array{id: string, name: string, role: string, current: bool}> */
    public function all(Request $request): array
    {
        $viewing = $this->viewAs->membership($request);
        if ($viewing !== null) {
            return [['id' => $viewing->workspace_id, 'name' => $viewing->workspace->name, 'role' => $viewing->role, 'current' => true]];
        }
        $current = $this->membership($request)?->workspace_id;

        return WorkspaceMember::query()->with('workspace')->where('user_id', $request->user()->id)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (WorkspaceMember $m) => [
                'id' => $m->workspace_id, 'name' => $m->workspace->name, 'role' => $m->role, 'current' => $m->workspace_id === $current,
            ])->all();
    }
}
