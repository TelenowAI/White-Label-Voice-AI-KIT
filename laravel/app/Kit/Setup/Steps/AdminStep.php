<?php

namespace App\Kit\Setup\Steps;

use App\Kit\Settings\Settings;
use App\Kit\Setup\Field;
use App\Kit\Setup\SetupRefused;
use App\Kit\Setup\SetupStep;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Wizard step 3: the operator's own account, the install's owner (SELF_HOSTED_KIT_PLAN.md §4.1.6). It runs before
 * any secret is entered: from here on every step is the signed-in owner's alone, so the visitor who makes the
 * owner is the only one who can go on. Exactly one owner is made — two visitors racing get one owner and a 409.
 * The new owner is signed in.
 *
 * Not here: two-factor sign-in (K1), other staff (admin).
 */
final class AdminStep extends SetupStep
{
    public function __construct(private readonly Settings $settings) {}

    public function id(): string
    {
        return 'admin';
    }

    public function access(): string
    {
        return self::ACCESS_ANYONE;
    }

    public function done(): bool
    {
        try {
            return User::query()->where('role', User::ROLE_OWNER)->exists();
        } catch (\Throwable) {
            return false; // no database yet
        }
    }

    public function fields(Request $request, bool $mayRead): array
    {
        return [
            new Field('name', $this->text('fields.name'), 'text', true),
            new Field('email', $this->text('fields.email'), 'email', true),
            new Field('password', $this->text('fields.password'), 'password', true, null,
                $this->text('help.password', ['min' => config('kit.auth.password_min_length')])),
        ];
    }

    public function submit(Request $request, array $input): void
    {
        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(config('kit.auth.password_min_length'))],
        ], [], [
            'name' => $this->text('fields.name'), 'email' => $this->text('fields.email'), 'password' => $this->text('fields.password'),
        ])->validate();

        $user = DB::transaction(function () use ($values) {
            if (! $this->settings->claim('setup.admin.claimed', $values['email'])) {
                throw new SetupRefused(409, __('setup.refusals.closed', ['step' => $this->title()]));
            }

            return User::create([...$values, 'role' => User::ROLE_OWNER]);
        });

        Auth::login($user);
        $request->session()->regenerate();
    }
}
