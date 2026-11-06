<?php

namespace App\Kit\Team;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Signup\SignupSettings;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvite;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * A customer account's team (SELF_HOSTED_KIT_PLAN.md §4.4 "Team", kit-owned): its members and the invites that let
 * people join. The operator's staff manage any account's team (admin); an account's managers
 * (config('kit.team.managers')) manage their own (console). An invite is emailed when the kit can send email
 * (App\Kit\Email) and the email goes at once; otherwise its link is shown to whoever made it, once. Only the token's
 * hash is kept.
 *
 * - A new invite for an email replaces that email's earlier pending one in the account, so one link is live.
 * - Accepting signs the person in. Someone new sets a name, and a password while customers may use one. Someone who
 *   already has an account proves it and is added to this one too: by being signed in to it here, however they signed
 *   in, or with its password while that works. The link alone is no proof, since it may have been forwarded.
 * - An account always keeps at least one manager.
 *
 * Not here: who may call what (the controllers' middleware), signing up without an invite (K1 slice 7).
 */
final class Team
{
    public function __construct(private readonly Emails $emails, private readonly Senders $senders, private readonly SignupSettings $settings) {}

    /** @return array{members: list<array>, invites: list<array>, roles: list<string>} */
    public function list(Workspace $workspace): array
    {
        $members = WorkspaceMember::query()->with('user')->where('workspace_id', $workspace->id)->orderBy('created_at')->orderBy('id')->get()
            ->map(fn (WorkspaceMember $m) => [
                'userId' => $m->user_id, 'name' => $m->user->name, 'email' => $m->user->email, 'role' => $m->role,
                'joinedAt' => $m->created_at->toIso8601String(),
            ])->all();
        $invites = WorkspaceInvite::query()->pending()->where('workspace_id', $workspace->id)->orderByDesc('created_at')->get()
            ->map($this->presentInvite(...))->all();

        return ['members' => $members, 'invites' => $invites, 'roles' => config('kit.team.roles')];
    }

    /**
     * @return array{invite: array, token: ?string, emailed: bool} the token is the link's secret: answered this once,
     *                                                               and only when the invite was not emailed
     */
    public function invite(Workspace $workspace, array $input, ?User $by): array
    {
        $values = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(config('kit.team.roles'))],
        ], [], ['email' => __('team.fields.email'), 'role' => __('team.fields.role')])->validate();
        if (in_array($workspace->status, Workspace::ENDED, true)) {
            throw new TeamRefused(409, __("customers.already_{$workspace->status}"));
        }
        $email = mb_strtolower($values['email']);
        $already = WorkspaceMember::query()->where('workspace_id', $workspace->id)
            ->whereHas('user', fn ($q) => $q->whereRaw('lower(email) = ?', [$email]))->exists();
        if ($already) {
            throw new TeamRefused(409, __('team.already_member'));
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $invite = DB::transaction(function () use ($workspace, $email, $values, $token, $by) {
            WorkspaceInvite::query()->pending()->where('workspace_id', $workspace->id)->where('email', $email)
                ->update(['revoked_at' => now()]);

            return WorkspaceInvite::create([
                'workspace_id' => $workspace->id, 'email' => $email, 'role' => $values['role'],
                'token_hash' => self::hash($token), 'invited_by' => $by?->id,
                'expires_at' => now()->addHours(config('kit.team.invite_hours')),
            ]);
        });

        $emailed = $this->emails->on() && $this->emails->send('team_invite', $email, [
            'account' => $workspace->name, 'expires' => $invite->expires_at->format(config('kit.email.date_format')),
        ], $this->senders->link("console/invite/{$token}"))->status === 'sent';

        return ['invite' => $this->presentInvite($invite), 'token' => $emailed ? null : $token, 'emailed' => $emailed];
    }

    public function revoke(Workspace $workspace, string $inviteId): void
    {
        $revoked = WorkspaceInvite::query()->pending()->where('workspace_id', $workspace->id)->whereKey($inviteId)
            ->update(['revoked_at' => now()]);
        if ($revoked !== 1) {
            throw new TeamRefused(404, __('team.no_invite'));
        }
    }

    public function remove(Workspace $workspace, int $userId): void
    {
        DB::transaction(function () use ($workspace, $userId) {
            $member = WorkspaceMember::query()->where('workspace_id', $workspace->id)->where('user_id', $userId)->lockForUpdate()->first();
            if ($member === null) {
                throw new TeamRefused(404, __('team.no_member'));
            }
            $managers = config('kit.team.managers');
            if (in_array($member->role, $managers, true)
                && WorkspaceMember::query()->where('workspace_id', $workspace->id)->whereIn('role', $managers)->count() === 1) {
                throw new TeamRefused(409, __('team.last_manager'));
            }
            $member->delete();
        });
    }

    /** The pending invite this token opens, or null — the same for unknown, used, revoked and expired. */
    public function pending(string $token): ?WorkspaceInvite
    {
        return WorkspaceInvite::query()->pending()->with('workspace')->where('token_hash', self::hash($token))->first();
    }

    /** @return array{accountExists: bool, choosesPassword: bool, signedInAs: ?string, canJoinNow: bool} what accepting this invite asks of whoever has it open */
    public function acceptance(WorkspaceInvite $invite): array
    {
        $existing = $this->invited($invite);
        $signedIn = Auth::user();

        return [
            'accountExists' => $existing !== null,
            'choosesPassword' => $existing === null && $this->settings->uses('password'),
            'signedInAs' => $signedIn?->email ?? $signedIn?->phone,
            'canJoinNow' => $existing !== null && $existing->is($signedIn),
        ];
    }

    /** Join with this invite and sign in. Answers the user. */
    public function accept(WorkspaceInvite $invite, array $input): User
    {
        $existing = $this->invited($invite);
        $values = [];
        if ($existing && ! $existing->is(Auth::user())) {
            if (! $this->settings->passwordWorksFor($existing)) {
                throw ValidationException::withMessages(['password' => __('team.sign_in_first')]);
            }
            $values = Validator::make($input, ['password' => ['required', 'string']])->validate();
            if (! Hash::check($values['password'], $existing->password)) {
                throw ValidationException::withMessages(['password' => __('session.failed')]);
            }
        } elseif (! $existing) {
            $values = Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],
                ...($this->settings->uses('password') ? ['password' => ['required', 'string', Password::min(config('kit.auth.password_min_length'))]] : []),
            ], [], ['name' => __('team.fields.name'), 'password' => __('team.fields.password')])->validate();
        }

        $user = DB::transaction(function () use ($invite, $existing, $values) {
            // Claim the invite first: of two tabs accepting one link, one wins.
            $claimed = WorkspaceInvite::query()->pending()->whereKey($invite->id)->update(['accepted_at' => now()]);
            if ($claimed !== 1) {
                throw new TeamRefused(404, __('team.no_invite'));
            }
            $user = $existing ?? User::create(['name' => $values['name'], 'email' => $invite->email, 'password' => $values['password'] ?? Str::random(64)]);
            WorkspaceMember::query()->firstOrCreate(
                ['workspace_id' => $invite->workspace_id, 'user_id' => $user->id],
                ['role' => $invite->role],
            );

            return $user;
        });
        Auth::login($user);
        request()->session()->regenerate();

        return $user;
    }

    public function presentInvite(WorkspaceInvite $invite): array
    {
        return [
            'id' => $invite->id, 'email' => $invite->email, 'role' => $invite->role,
            'expiresAt' => $invite->expires_at->toIso8601String(), 'createdAt' => $invite->created_at->toIso8601String(),
        ];
    }

    private function invited(WorkspaceInvite $invite): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [$invite->email])->first();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
