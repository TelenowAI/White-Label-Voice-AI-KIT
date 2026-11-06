<?php

namespace App\Kit\Auth;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Signup\SignupSettings;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * "Forgot your password?" for everyone who signs in — customers and the operator's staff alike. A reset link is
 * emailed to the account with that address, if there is one; asking answers the same either way, and the email is
 * made after the answer is sent, so neither the answer nor its timing says whether an account exists. The link works
 * once, for config('kit.auth.reset_minutes'), and a newer one replaces it; only its token's hash is kept. Setting the
 * new password ends the user's other sessions and signs them in here. With the operator's `password` way in off, a
 * customer gets no link and an earlier link stops working, since it would still sign them in; staff always keep theirs.
 *
 * Not here: whether email is on (Emails), the throttle (the `kit-password` limiter), the HTTP answers
 * (PasswordController).
 */
final class PasswordResets
{
    public function __construct(private readonly Emails $emails, private readonly Senders $senders, private readonly SignupSettings $settings) {}

    /** Email a reset link to the account using this address, if any. */
    public function request(string $email): void
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->first();
        if ($user === null || ! $this->emails->on() || ! $this->settings->passwordWorksFor($user)) {
            return;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::transaction(function () use ($user, $token) {
            PasswordReset::query()->pending()->where('user_id', $user->id)->update(['used_at' => now()]);
            PasswordReset::create(['user_id' => $user->id, 'token_hash' => self::hash($token),
                'expires_at' => now()->addMinutes(config('kit.auth.reset_minutes'))]);
        });
        $this->emails->send('password_reset', $user->email, ['email' => $user->email, 'minutes' => config('kit.auth.reset_minutes')],
            $this->senders->link("reset/{$token}"));
    }

    /** The pending reset this token opens, or null — the same for unknown, used, expired and a way in that is off. */
    public function pending(string $token): ?PasswordReset
    {
        $reset = PasswordReset::query()->pending()->with('user')->where('token_hash', self::hash($token))->first();

        return $reset !== null && $this->settings->passwordWorksFor($reset->user) ? $reset : null;
    }

    /** Set a new password with a reset link. Answers the user, or null when the link no longer works. */
    public function reset(string $token, array $input): ?User
    {
        $values = Validator::make($input, ['password' => ['required', 'string', Password::min(config('kit.auth.password_min_length'))]],
            [], ['password' => __('session.fields.password')])->validate();

        if ($this->pending($token) === null) {
            return null;
        }

        return DB::transaction(function () use ($token, $values) {
            // Claim the link first: of two tabs using one link, one wins.
            $claimed = PasswordReset::query()->pending()->where('token_hash', self::hash($token))->update(['used_at' => now()]);
            if ($claimed !== 1) {
                return null;
            }
            $user = PasswordReset::query()->where('token_hash', self::hash($token))->sole()->user;
            $user->forceFill(['password' => $values['password'], 'remember_token' => Str::random(60)])->save();
            PasswordReset::query()->pending()->where('user_id', $user->id)->update(['used_at' => now()]);
            if (config('session.driver') === 'database') {
                DB::table(config('session.table'))->where('user_id', $user->id)->delete();
            }

            return $user;
        });
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
