<?php

namespace App\Kit\Auth;

use App\Kit\Email\Emails;
use App\Models\EmailCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Signing in with a one-time code emailed each time, for customers only: the operator's staff always use their password.
 * - A code is config('kit.signup.email_code.length') digits; only an HMAC of it (with the address, keyed by APP_KEY) is kept.
 * - It works once, for config('kit.signup.email_code.minutes'), for config('kit.signup.email_code.attempts') tries, counted
 *   atomically; a newer code for the same person replaces it.
 * - Asking with an address no customer uses sends nothing, and the caller answers the same either way (and defers the asking).
 *
 * Not here: whether codes are on (email on and the operator's `email_code` way in; the controller asks), a sign-up's own
 * code (App\Kit\Signup\Signups), the throttle.
 */
final class EmailCodes
{
    public function __construct(private readonly Emails $emails) {}

    /** Email a sign-in code to the customer using this address, if any. */
    public function requestSignIn(string $email): void
    {
        $email = self::normal($email);
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->whereNull('role')->first();
        if ($user === null) {
            return;
        }
        $c = config('kit.signup.email_code');
        $code = str_pad((string) random_int(0, 10 ** $c['length'] - 1), $c['length'], '0', STR_PAD_LEFT);
        DB::transaction(function () use ($user, $email, $code, $c) {
            EmailCode::query()->live()->where('user_id', $user->id)->update(['used_at' => now()]);
            EmailCode::create(['user_id' => $user->id, 'email' => $email, 'code_hash' => self::hash($email, $code), 'expires_at' => now()->addMinutes($c['minutes'])]);
        });
        $this->emails->send('sign_in_code', $user->email, ['code' => $code, 'minutes' => $c['minutes']]);
    }

    /** Sign in with a code: answers the customer, or null when the code is wrong, used, expired or out of tries. */
    public function signIn(string $email, string $code): ?User
    {
        $email = self::normal($email);
        $row = EmailCode::query()->live()->where('email', $email)->latest('id')->first();
        // The try is spent before the code is compared, and only while tries are left, so racing tries cannot exceed it.
        if ($row === null || EmailCode::query()->whereKey($row->id)->live()->increment('attempts') !== 1) {
            return null;
        }
        if (! hash_equals($row->code_hash, self::hash($email, $code))) {
            return null;
        }
        if (EmailCode::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) !== 1) {
            return null;
        }
        $user = $row->user;

        return $user !== null && $user->role === null && self::normal((string) $user->email) === $email ? $user : null;
    }

    /** Forget codes that have run out. Answers how many. */
    public function purge(): int
    {
        return EmailCode::query()->where('expires_at', '<', now()->subDays(config('kit.signup.purge_days')))->delete();
    }

    private static function normal(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private static function hash(string $email, string $code): string
    {
        return hash_hmac('sha256', "{$email}|{$code}", (string) config('app.key'));
    }
}
