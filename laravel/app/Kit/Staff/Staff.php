<?php

namespace App\Kit\Staff;

use App\Kit\Audit\Audit;
use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Models\StaffInvite;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The operator's staff (SELF_HOSTED_KIT_PLAN.md §4.2 "Staff"): the people who use the admin panel, each with a role
 * from config('kit.admin.staff_roles') whose abilities are config('kit.admin.abilities').
 * - Invite by email: the link is emailed when email is set up, else answered once for staff to pass on; a new invite
 *   to the same address replaces the one before. The link works for config('kit.admin.invite_hours').
 * - Staff accounts and customer accounts are apart: an address that belongs to a customer's account cannot be staff.
 * - There is always an owner: the last one cannot be removed or given another role.
 * - Removing someone takes their role away (they keep their account, so the audit log still names them).
 * Every change is in the audit log (App\Kit\Audit\Audit).
 *
 * Used by Admin\StaffController and StaffInvitesController.
 */
final class Staff
{
    public function __construct(private readonly Emails $emails, private readonly Senders $senders, private readonly Audit $audit) {}

    /** @return array{members: list<array>, invites: list<array>} */
    public function list(?User $viewer): array
    {
        return [
            'members' => User::query()->whereIn('role', config('kit.admin.staff_roles'))->orderBy('name')->get()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'role' => $u->role, 'you' => $u->id === $viewer?->id])->all(),
            'invites' => StaffInvite::query()->pending()->orderBy('created_at')->get()->map(fn (StaffInvite $i) => $this->presentInvite($i))->all(),
        ];
    }

    /** @return array{invite: array, token: ?string, emailed: bool} the token only when the invite was not emailed */
    public function invite(array $input, User $by): array
    {
        $values = Validator::make($input, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::in(config('kit.admin.staff_roles'))],
        ], [], ['email' => __('staff.fields.email'), 'role' => __('staff.fields.role')])->validate();
        $email = mb_strtolower($values['email']);
        $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($existing !== null && in_array($existing->role, config('kit.admin.staff_roles'), true)) {
            throw new StaffRefused(409, __('staff.already_staff'));
        }
        if ($existing !== null && WorkspaceMember::query()->where('user_id', $existing->id)->exists()) {
            throw new StaffRefused(409, __('staff.customer_address'));
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $invite = DB::transaction(function () use ($email, $values, $token, $by) {
            StaffInvite::query()->pending()->where('email', $email)->update(['revoked_at' => now()]);

            return StaffInvite::create(['email' => $email, 'role' => $values['role'], 'token_hash' => self::hash($token),
                'invited_by' => $by->id, 'expires_at' => now()->addHours(config('kit.admin.invite_hours'))]);
        });
        $this->audit->record($by, 'staff.invited', null, null, ['email' => $email, 'role' => $invite->role]);

        $emailed = $this->emails->on() && $this->emails->send('staff_invite', $email, [
            'role' => __("staff.roles.{$invite->role}"), 'expires' => $invite->expires_at->format(config('kit.email.date_format')),
        ], $this->senders->link("admin/invite/{$token}"))->status === 'sent';

        return ['invite' => $this->presentInvite($invite), 'token' => $emailed ? null : $token, 'emailed' => $emailed];
    }

    public function revoke(string $inviteId, User $by): void
    {
        $invite = StaffInvite::query()->pending()->whereKey($inviteId)->first();
        if ($invite === null || StaffInvite::query()->pending()->whereKey($inviteId)->update(['revoked_at' => now()]) !== 1) {
            throw new StaffRefused(404, __('staff.no_invite'));
        }
        $this->audit->record($by, 'staff.invite_revoked', null, null, ['email' => $invite->email]);
    }

    public function changeRole(int $userId, array $input, User $by): User
    {
        $values = Validator::make($input, ['role' => ['required', 'string', Rule::in(config('kit.admin.staff_roles'))]],
            [], ['role' => __('staff.fields.role')])->validate();

        return DB::transaction(function () use ($userId, $values, $by) {
            $user = $this->staffMember($userId);
            if ($user->role === User::ROLE_OWNER && $values['role'] !== User::ROLE_OWNER) {
                $this->keepAnOwner();
            }
            $before = $user->role;
            $user->forceFill(['role' => $values['role']])->save();
            $this->audit->record($by, 'staff.role_changed', Audit::STAFF, (string) $user->id, ['from' => $before, 'to' => $user->role]);

            return $user;
        });
    }

    public function remove(int $userId, User $by): void
    {
        DB::transaction(function () use ($userId, $by) {
            $user = $this->staffMember($userId);
            if ($user->role === User::ROLE_OWNER) {
                $this->keepAnOwner();
            }
            $before = $user->role;
            $user->forceFill(['role' => null])->save();
            $this->audit->record($by, 'staff.removed', Audit::STAFF, (string) $user->id, ['role' => $before]);
        });
    }

    /** The pending invite this token opens, or null — the same for unknown, used, revoked and expired. */
    public function pending(string $token): ?StaffInvite
    {
        return StaffInvite::query()->pending()->where('token_hash', self::hash($token))->first();
    }

    /** Join the staff with this invite and sign in. Answers the user. */
    public function accept(StaffInvite $invite, array $input): User
    {
        $existing = User::query()->whereRaw('lower(email) = ?', [$invite->email])->first();
        if ($existing !== null && WorkspaceMember::query()->where('user_id', $existing->id)->exists()) {
            throw new StaffRefused(409, __('staff.customer_address'));
        }
        if ($existing !== null) {
            $values = Validator::make($input, ['password' => ['required', 'string']])->validate();
            if (! Hash::check($values['password'], $existing->password)) {
                throw ValidationException::withMessages(['password' => __('session.failed')]);
            }
        } else {
            $values = Validator::make($input, [
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', Password::min(config('kit.auth.password_min_length'))],
            ], [], ['name' => __('staff.fields.name'), 'password' => __('staff.fields.password')])->validate();
        }

        $user = DB::transaction(function () use ($invite, $existing, $values) {
            // Claim the invite first: of two tabs accepting one link, one wins.
            if (StaffInvite::query()->pending()->whereKey($invite->id)->update(['accepted_at' => now()]) !== 1) {
                throw new StaffRefused(404, __('staff.no_invite'));
            }
            $user = $existing ?? User::create(['name' => $values['name'], 'email' => $invite->email, 'password' => $values['password']]);
            // Never lower an owner who follows an old invite for a lesser role.
            if ($user->role !== User::ROLE_OWNER) {
                $user->forceFill(['role' => $invite->role])->save();
            }
            $this->audit->record($user, 'staff.joined', Audit::STAFF, (string) $user->id, ['role' => $user->role]);

            return $user;
        });
        Auth::login($user);
        request()->session()->regenerate();

        return $user;
    }

    public function presentInvite(StaffInvite $invite): array
    {
        return ['id' => $invite->id, 'email' => $invite->email, 'role' => $invite->role,
            'expiresAt' => $invite->expires_at->toIso8601String(), 'createdAt' => $invite->created_at->toIso8601String()];
    }

    private function staffMember(int $userId): User
    {
        $user = User::query()->whereKey($userId)->whereIn('role', config('kit.admin.staff_roles'))->lockForUpdate()->first();
        if ($user === null) {
            throw new StaffRefused(404, __('staff.no_member'));
        }

        return $user;
    }

    private function keepAnOwner(): void
    {
        if (User::query()->where('role', User::ROLE_OWNER)->lockForUpdate()->pluck('id')->count() <= 1) {
            throw new StaffRefused(409, __('staff.last_owner'));
        }
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
