<?php

namespace App\Kit\Signup;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Models\SignInLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Signing in with a link emailed each time (the magic-link method, SELF_HOSTED_KIT_PLAN.md §4.5) — for customers only:
 * the operator's staff always use their password. Asking answers the same whether or not the address is a customer's
 * (the caller defers `request`); the link works once, for config('kit.signup.link_minutes'), and a newer one replaces
 * it; only its token's hash is kept.
 *
 * Not here: whether the method is on and email works (the controller asks SignupSettings and Emails), the throttle.
 */
final class SignInLinks
{
    public function __construct(private readonly Emails $emails, private readonly Senders $senders) {}

    /** Email a sign-in link to the customer using this address, if any. */
    public function request(string $email): void
    {
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])->whereNull('role')->first();
        if ($user === null) {
            return;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        DB::transaction(function () use ($user, $token) {
            SignInLink::query()->pending()->where('user_id', $user->id)->update(['used_at' => now()]);
            SignInLink::create(['user_id' => $user->id, 'token_hash' => self::hash($token), 'expires_at' => now()->addMinutes(config('kit.signup.link_minutes'))]);
        });
        $this->emails->send('sign_in_link', $user->email, ['minutes' => config('kit.signup.link_minutes')], $this->senders->link("signin/{$token}"));
    }

    /** Use a link: answers its customer, or null when it no longer works. */
    public function use(string $token): ?User
    {
        $claimed = SignInLink::query()->pending()->where('token_hash', self::hash($token))->update(['used_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }

        return SignInLink::query()->where('token_hash', self::hash($token))->sole()->user()->whereNull('role')->first();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
