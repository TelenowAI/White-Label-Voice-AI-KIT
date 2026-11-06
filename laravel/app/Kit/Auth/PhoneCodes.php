<?php

namespace App\Kit\Auth;

use App\Kit\Sms\Sms;
use App\Models\PhoneCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One-time codes texted to a phone (SELF_HOSTED_KIT_PLAN.md §4.5, phone sign-in): for a customer signing in with their
 * proven number, and for anyone proving a number they are adding to their account.
 * - A code is config('kit.phone.code_length') digits; only an HMAC of it (with the number, keyed by APP_KEY) is kept.
 * - It works once, for config('kit.phone.code_minutes'), for config('kit.phone.code_attempts') tries, counted
 *   atomically; a newer code for the same person and purpose replaces it.
 * - Asking to sign in with a number no customer has proven sends nothing — the caller answers the same either way
 *   (and defers the asking); the operator's staff never sign in by code.
 * - Proving a number moves it off any other account that held it: whoever holds the phone now owns the number (numbers
 *   are reissued).
 *
 * Not here: whether codes are on (SMS on and the operator's `phone_code` way in; the controllers ask), the throttle.
 */
final class PhoneCodes
{
    public function __construct(private readonly Sms $sms) {}

    /** Text a sign-in code to the customer whose proven number this is, if any. */
    public function requestSignIn(string $phone): void
    {
        $user = User::query()->where('phone', $phone)->whereNotNull('phone_verified_at')->whereNull('role')->first();
        if ($user !== null) {
            $this->issue($user, PhoneCode::SIGN_IN, $phone, 'sign_in_code');
        }
    }

    /** Text a code to a number this person is adding. False when no sender could send it. */
    public function requestVerify(User $user, string $phone): bool
    {
        return $this->issue($user, PhoneCode::VERIFY, $phone, 'verify_code');
    }

    /** Sign in with a code: answers the customer, or null when the code is wrong, used, expired or out of tries. */
    public function signIn(string $phone, string $code): ?User
    {
        $user = $this->claim(PhoneCode::SIGN_IN, $phone, $code, null)?->user;

        return $user !== null && $user->role === null && $user->phone === $phone ? $user : null;
    }

    /** Prove a number with its code: it becomes this person's. False when the code does not work. */
    public function verify(User $user, string $phone, string $code): bool
    {
        if ($this->claim(PhoneCode::VERIFY, $phone, $code, $user) === null) {
            return false;
        }
        DB::transaction(function () use ($user, $phone) {
            User::query()->where('phone', $phone)->whereKeyNot($user->id)->update(['phone' => null, 'phone_verified_at' => null]);
            $user->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();
        });

        return true;
    }

    /** Make a code (replacing the person's last one for this purpose) and text it; whether a sender took it. */
    private function issue(User $user, string $purpose, string $phone, string $kind): bool
    {
        $length = (int) config('kit.phone.code_length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        DB::transaction(function () use ($user, $purpose, $phone, $code) {
            PhoneCode::query()->live()->where('user_id', $user->id)->where('purpose', $purpose)->update(['used_at' => now()]);
            PhoneCode::create(['user_id' => $user->id, 'purpose' => $purpose, 'phone' => $phone, 'code_hash' => self::hash($phone, $code),
                'expires_at' => now()->addMinutes(config('kit.phone.code_minutes'))]);
        });
        return $this->sms->send($kind, $phone, ['code' => $code, 'minutes' => config('kit.phone.code_minutes')])->status === 'sent';
    }

    /** Spend one try on the newest live code for this number; answers it when the code matches (it is then used). */
    private function claim(string $purpose, string $phone, string $code, ?User $user): ?PhoneCode
    {
        $row = PhoneCode::query()->live()->where('purpose', $purpose)->where('phone', $phone)
            ->when($user !== null, fn ($q) => $q->where('user_id', $user->id))->latest('id')->first();
        if ($row === null) {
            return null;
        }
        // The try is spent before the code is compared, and only while tries are left, so racing tries cannot exceed it.
        if (PhoneCode::query()->whereKey($row->id)->live()->increment('attempts') !== 1) {
            return null;
        }
        if (! hash_equals($row->code_hash, self::hash($phone, $code))) {
            return null;
        }

        return PhoneCode::query()->whereKey($row->id)->whereNull('used_at')->update(['used_at' => now()]) === 1 ? $row : null;
    }

    private static function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', "{$phone}|{$code}", (string) config('app.key'));
    }
}
