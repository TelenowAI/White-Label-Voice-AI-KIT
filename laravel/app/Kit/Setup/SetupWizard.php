<?php

namespace App\Kit\Setup;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Throwable;

/**
 * The setup wizard (SELF_HOSTED_KIT_PLAN.md §4.1): its steps in config('kit.setup.steps') order, the state the
 * console shows (contract/kit-api.yaml SetupState), and the rules for submitting a step:
 * - steps run in order: a step is taken only when every step before it is done (409);
 * - a step that runs before there is an owner (database, admin) closes once there is one (409);
 * - an owner step is the signed-in owner's alone (401 signed out, 403 someone else).
 * Every step can be submitted again until the install is finished, so the wizard can be resumed and corrected.
 *
 * Not here: what a step does (Steps/*), the lock and the HTTP answers (Installation, SetupController).
 */
final class SetupWizard
{
    /** @var list<SetupStep>|null */
    private ?array $steps = null;

    public function __construct(private readonly Container $container) {}

    /** @return list<SetupStep> */
    public function steps(): array
    {
        return $this->steps ??= array_map(fn (string $class) => $this->container->make($class), config('kit.setup.steps'));
    }

    public function ownerExists(): bool
    {
        try {
            return User::query()->where('role', User::ROLE_OWNER)->exists();
        } catch (Throwable) {
            return false; // no database yet
        }
    }

    public function state(Request $request, bool $installed = false): array
    {
        $owner = $this->ownerExists();
        $isOwner = $request->user()?->role === User::ROLE_OWNER;

        return [
            'installed' => $installed,
            'ownerExists' => $owner,
            'steps' => array_map(function (SetupStep $step) use ($request, $owner, $isOwner) {
                $mayRead = $isOwner || ($step->access() === SetupStep::ACCESS_ANYONE && ! $owner);
                $checks = $step->checks($request, $mayRead);

                return [
                    'id' => $step->id(),
                    'title' => $step->title(),
                    'description' => $step->description(),
                    'status' => $this->status($step, $checks),
                    'access' => $step->access(),
                    'submittable' => $step->submittable(),
                    'fields' => array_map(fn (Field $f) => $f->toArray(), $step->fields($request, $mayRead)),
                    'checks' => array_map(fn (Check $c) => $c->toArray(), $checks),
                    'facts' => $mayRead ? $step->facts($request) : [],
                ];
            }, $this->steps()),
        ];
    }

    public function submit(Request $request, string $id): void
    {
        $steps = $this->steps();
        $at = array_search($id, array_map(fn (SetupStep $s) => $s->id(), $steps), true);
        if ($at === false) {
            throw new SetupRefused(404, __('setup.refusals.unknown_step', ['step' => $id]));
        }
        $step = $steps[$at];
        if (! $step->submittable()) {
            throw new SetupRefused(404, __('setup.refusals.nothing_to_submit', ['step' => $step->title()]));
        }
        foreach (array_slice($steps, 0, $at) as $before) {
            if ($this->status($before, $before->checks($request, false)) !== 'done') {
                throw new SetupRefused(409, __('setup.refusals.earlier_step', ['step' => $before->title()]));
            }
        }
        if ($step->access() === SetupStep::ACCESS_ANYONE && $this->ownerExists()) {
            throw new SetupRefused(409, __('setup.refusals.closed', ['step' => $step->title()]));
        }
        if ($step->access() === SetupStep::ACCESS_OWNER) {
            $user = $request->user();
            if ($user === null) {
                throw new SetupRefused(401, __('setup.refusals.sign_in'));
            }
            if ($user->role !== User::ROLE_OWNER) {
                throw new SetupRefused(403, __('setup.refusals.owner_only'));
            }
        }
        $step->submit($request, $request->json()->all());
    }

    /** @param list<Check> $checks */
    private function status(SetupStep $step, array $checks): string
    {
        foreach ($checks as $check) {
            if ($check->level === Check::BLOCKING) {
                return 'blocked';
            }
        }

        return $step->done() ? 'done' : 'todo';
    }
}
