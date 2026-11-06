<?php

namespace App\Kit\Console;

use App\Kit\Audit\Audit;
use App\Kit\Staff\Abilities;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Staff looking at the console as one customer sees it (SELF_HOSTED_KIT_PLAN.md §4.2 "log in as the customer"), to
 * help them: kept in the staff member's own session — never the customer's — for config('kit.admin.view_as_minutes'),
 * then it ends by itself, and also as soon as the staff member's role may no longer do it (`view_as`). It is
 * read-only: EnsureMember refuses every console write while it lasts. Starting and stopping are in the audit log.
 *
 * Read by CurrentWorkspace (the stand-in membership, role config('kit.team.viewer_role')) and the session answer.
 */
final class ViewAs
{
    private const SESSION_KEY = 'kit.view_as';

    public function __construct(private readonly Audit $audit) {}

    /** @return array{customerId: string, name: string, until: string} */
    public function start(Request $request, Workspace $workspace, User $by): array
    {
        $until = CarbonImmutable::now()->addMinutes(config('kit.admin.view_as_minutes'));
        $request->session()->put(self::SESSION_KEY, ['workspace' => $workspace->id, 'until' => $until->toIso8601String()]);
        $this->audit->record($by, 'customer.viewed_as', Audit::CUSTOMER, $workspace->id, ['until' => $until->toIso8601String()]);

        return $this->present($workspace, $until);
    }

    /** The customer this staff member is looking at now, or null (none, ended, or no longer allowed). */
    public function current(Request $request): ?Workspace
    {
        $state = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (! is_array($state) || ! Abilities::may($request->user(), 'view_as')) {
            return null;
        }
        if (CarbonImmutable::parse($state['until'])->isPast()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return Workspace::query()->find($state['workspace']);
    }

    /** What the session answer says about it, or null. */
    public function describe(Request $request): ?array
    {
        $workspace = $this->current($request);

        return $workspace === null ? null : $this->present($workspace, CarbonImmutable::parse($request->session()->get(self::SESSION_KEY)['until']));
    }

    /** Stop now. Answers the customer it was, or null when there was none. */
    public function stop(Request $request): ?Workspace
    {
        $workspace = $this->current($request);
        $request->session()->forget(self::SESSION_KEY);
        if ($workspace !== null) {
            $this->audit->record($request->user(), 'customer.view_ended', Audit::CUSTOMER, $workspace->id);
        }

        return $workspace;
    }

    /** A membership that is not saved anywhere: the console works in this customer, as a viewer. */
    public function membership(Request $request): ?WorkspaceMember
    {
        $workspace = $this->current($request);
        if ($workspace === null) {
            return null;
        }
        $member = (new WorkspaceMember)->forceFill(['workspace_id' => $workspace->id, 'user_id' => $request->user()->id, 'role' => config('kit.team.viewer_role')]);
        $member->setRelation('workspace', $workspace);

        return $member;
    }

    private function present(Workspace $workspace, CarbonImmutable $until): array
    {
        return ['customerId' => $workspace->id, 'name' => $workspace->name, 'until' => $until->toIso8601String()];
    }
}
