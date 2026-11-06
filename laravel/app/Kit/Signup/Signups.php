<?php

namespace App\Kit\Signup;

use App\Kit\Customers\Customers;
use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Oidc\OidcProviders;
use App\Kit\Pricing\Pricing;
use App\Kit\Sms\PhoneNumber;
use App\Kit\Sms\Sms;
use App\Kit\Wallet\Wallet;
use App\Models\Signup;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-ups (SELF_HOSTED_KIT_PLAN.md §4.5). A sign-up from the public form is checked against the form the operator set
 * (SignupSettings), then held — not a user yet — and a link to verify the address is emailed (or, with the `email_code` way
 * in on, a code: `verifyEmail`; config('kit.signup.email_code') has its length, minutes and tries, and it is kept only as a
 * keyed hash). Opening the link and confirming (a POST: mail scanners only GET) verifies it; in approval mode it then waits for staff, otherwise its
 * account is made at once. Making the account: the user (the address verified), the customer account and, through the
 * outbox, its Telenow sub-account, the user as its first manager, and the trial credit — once, on the sign-up's own
 * reference.
 *
 * A sign-up with an address that already has an account answers the same and emails that person instead; one with an
 * address already waiting for approval changes nothing. Both are decided after the answer is sent (the caller defers
 * `hold`), so neither the answer nor its timing says whether an address is known.
 *
 * Two more ways in (owner 11-01), each ending the same way:
 * - **With a phone** (`checkPhone`, `holdPhone`, `verifyPhone`): the same, keyed by the number, with a code texted to it
 *   instead of a link — config('kit.phone.code_length') digits, kept only as a keyed hash, working once, for
 *   config('kit.phone.code_minutes') and config('kit.phone.code_attempts') tries; a number someone already proved is
 *   texted that it has an account. The account has no email: its person signs in with a code texted to that number.
 * - **With a sign-in provider** (`offer`, `offered`, `signUpWithProvider`): someone a provider vouches for (an email it
 *   verified) with no account is kept in their session for config('kit.signup.provider_minutes') while they fill the
 *   form; the address counts as confirmed, and the provider's identity is remembered with the account.
 * Whoever the sign-up tells (approved, refused) hears by email, or by text when it has no email.
 *
 * Not here: the settings and the form (SignupSettings), sign-in links (SignInLinks), checking a provider's answer
 * (App\Kit\Oidc\OidcSignIn), the HTTP answers.
 */
final class Signups
{
    /** Where a provider's newcomer waits in their session (offer → signUpWithProvider). */
    public const OFFER = 'kit.signup.offer';

    public function __construct(
        private readonly SignupSettings $settings,
        private readonly Emails $emails,
        private readonly Senders $senders,
        private readonly Customers $customers,
        private readonly Wallet $wallet,
        private readonly Pricing $pricing,
        private readonly Sms $sms,
        private readonly OidcProviders $providers,
    ) {}

    /** Check a sign-up from the public form. Answers the values to hold (`hold`), or throws. */
    public function check(array $input): array
    {
        if (! $this->settings->open()) {
            throw new SignupRefused(409, __('signup.closed'));
        }
        [$rules, $names] = $this->settings->rules();
        $values = Validator::make($input, $rules, [], $names)->validate();
        $values['email'] = mb_strtolower(trim($values['email']));
        if (! $this->settings->allows($values['email'])) {
            throw ValidationException::withMessages(['email' => [__('signup.domain_not_allowed')]]);
        }

        return $values;
    }

    /** Hold a checked sign-up and email its verification link — or, for an address already known, what to do instead. */
    public function hold(array $values, ?string $ip): void
    {
        $email = $values['email'];
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            $this->emails->send('signup_exists', $email, [], $this->senders->link('console'));

            return;
        }
        $earlier = Signup::query()->where('email', $email)->whereIn('status', [Signup::UNVERIFIED, Signup::WAITING])->latest()->first();
        if ($earlier?->status === Signup::WAITING) {
            return;
        }
        $terms = $this->settings->current()['terms'];
        $byCode = $this->settings->uses('email_code');
        $codes = config('kit.signup.email_code');
        $code = str_pad((string) random_int(0, 10 ** $codes['length'] - 1), $codes['length'], '0', STR_PAD_LEFT);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        ($earlier ?? new Signup)->forceFill([
            'email' => $email, 'name' => $values['name'], 'account_name' => $values['accountName'],
            'password' => isset($values['password']) ? Hash::make($values['password']) : null,
            'answers' => Arr::where($values['answers'] ?? [], fn ($v) => $v !== null && $v !== ''),
            'terms_version' => $terms['version'] ?? null, 'terms_accepted_at' => $terms !== null ? now() : null,
            'ip' => $ip, 'token_hash' => $byCode ? self::codeHash($email, $code) : self::hash($token), 'code_attempts' => 0,
            'expires_at' => $byCode ? now()->addMinutes($codes['minutes']) : now()->addHours(config('kit.signup.verify_hours')),
            'status' => Signup::UNVERIFIED,
        ])->save();
        if ($byCode) {
            $this->emails->send('signup_code', $email, ['name' => $values['name'], 'code' => $code, 'minutes' => $codes['minutes']]);

            return;
        }
        $this->emails->send('signup_verify', $email, ['name' => $values['name'], 'hours' => config('kit.signup.verify_hours')],
            $this->senders->link("signup/verify/{$token}"));
    }

    /**
     * Confirm an emailed sign-up with its code. Answers the user when the account was made, the sign-up when it now waits
     * for approval, or null when the code does not work — wrong, used, expired or out of tries, all the same.
     */
    public function verifyEmail(string $email, string $code): User|Signup|null
    {
        return $this->byCode('email', $email, $code, (int) config('kit.signup.email_code.attempts'));
    }

    /** The unverified sign-up this token opens, or null — the same for unknown, used and expired. */
    public function pending(string $token): ?Signup
    {
        return Signup::query()->where('status', Signup::UNVERIFIED)->where('token_hash', self::hash($token))
            ->where('expires_at', '>', now())->first();
    }

    /**
     * Verify the address with the link. Answers the user when the account was made (open and domains modes), the
     * sign-up when it now waits for approval, or null when the link no longer works.
     */
    public function verify(string $token): User|Signup|null
    {
        $signup = $this->pending($token);

        return $signup === null ? null : $this->confirm($signup, self::hash($token));
    }

    /** Check a sign-up with a phone (the form with a number instead of an email). Answers the values to hold (`holdPhone`), or throws. */
    public function checkPhone(array $input): array
    {
        if (! $this->settings->byPhone()) {
            throw new SignupRefused(409, __('signup.no_phone_signups'));
        }
        [$rules, $names] = $this->settings->rules(SignupSettings::BY_PHONE);
        $values = Validator::make($input, $rules, [], $names)->validate();
        $values['phone'] = PhoneNumber::normalize($values['phone']) ?? throw ValidationException::withMessages(['phone' => [__('sms.number_shape')]]);

        return $values;
    }

    /**
     * Hold a checked phone sign-up and text its code — or, for a number someone already proved, text them that it has an
     * account. A number already waiting for approval changes nothing. Deferred by the caller, like `hold`.
     */
    public function holdPhone(array $values, ?string $ip): void
    {
        $phone = $values['phone'];
        if (User::query()->where('phone', $phone)->exists()) {
            $this->sms->send('signup_exists', $phone, ['site' => $this->senders->link('console')]);

            return;
        }
        $earlier = Signup::query()->where('phone', $phone)->whereIn('status', [Signup::UNVERIFIED, Signup::WAITING])->latest()->first();
        if ($earlier?->status === Signup::WAITING) {
            return;
        }
        $terms = $this->settings->current()['terms'];
        $length = (int) config('kit.phone.code_length');
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        ($earlier ?? new Signup)->forceFill([
            'email' => null, 'phone' => $phone, 'name' => $values['name'], 'account_name' => $values['accountName'], 'password' => null,
            'answers' => Arr::where($values['answers'] ?? [], fn ($v) => $v !== null && $v !== ''),
            'terms_version' => $terms['version'] ?? null, 'terms_accepted_at' => $terms !== null ? now() : null,
            'ip' => $ip, 'token_hash' => self::codeHash($phone, $code), 'code_attempts' => 0,
            'expires_at' => now()->addMinutes(config('kit.phone.code_minutes')), 'status' => Signup::UNVERIFIED,
        ])->save();
        $this->sms->send('signup_code', $phone, ['code' => $code, 'minutes' => config('kit.phone.code_minutes')]);
    }

    /**
     * Confirm a phone sign-up with its texted code. Answers the user when the account was made, the sign-up when it now
     * waits for approval, or null when the code does not work — wrong, used, expired or out of tries, all the same.
     */
    public function verifyPhone(string $phone, string $code): User|Signup|null
    {
        return $this->byCode('phone', $phone, $code, (int) config('kit.phone.code_attempts'));
    }

    /** Spend one try on the unverified sign-up for this address or number; confirm it when the code matches. */
    private function byCode(string $column, string $address, string $code, int $tries): User|Signup|null
    {
        $signup = Signup::query()->where('status', Signup::UNVERIFIED)->where($column, $address)->where('expires_at', '>', now())
            ->where('code_attempts', '<', $tries)->latest()->first();
        // The try is spent before the code is compared, and only while tries are left, so racing tries cannot exceed it.
        if ($signup === null || Signup::query()->whereKey($signup->id)->where('code_attempts', '<', $tries)->increment('code_attempts') !== 1) {
            return null;
        }
        $hash = self::codeHash($address, $code);

        return hash_equals((string) $signup->token_hash, $hash) ? $this->confirm($signup, $hash) : null;
    }

    /**
     * Someone a sign-in provider vouches for (`provider`, `subject`, a verified `email`, maybe a `name`) has no account.
     * When they may sign up now, keep them in this session so the sign-up page can finish it. Answers null when kept,
     * else why not, as the sign-in page's reason: `no_account` (sign-ups are closed) or `not_allowed` (not this address).
     */
    public function offer(array $newcomer, Session $session): ?string
    {
        $session->forget(self::OFFER);
        if (! $this->settings->open()) {
            return 'no_account';
        }
        if (! $this->settings->allows($newcomer['email'])) {
            return 'not_allowed';
        }
        $session->put(self::OFFER, Arr::only($newcomer, ['provider', 'subject', 'email', 'name'])
            + ['expires' => now()->addMinutes((int) config('kit.signup.provider_minutes'))->getTimestamp()]);

        return null;
    }

    /**
     * The provider's newcomer waiting in this session, with the provider's name (`providerName`) — or null: none, run out,
     * the provider since switched off, or sign-ups no longer open to them.
     */
    public function offered(Session $session): ?array
    {
        $offer = $session->get(self::OFFER);
        if (! is_array($offer) || $offer['expires'] <= now()->getTimestamp()) {
            return null;
        }
        $provider = $this->providers->find($offer['provider']);
        if ($provider === null || ! $provider['enabled'] || ! $this->settings->open() || ! $this->settings->allows($offer['email'])) {
            return null;
        }

        return $offer + ['providerName' => $provider['name']];
    }

    /** Check a provider sign-up's form (no email or password: the provider vouched for the address). */
    public function checkProvider(array $input): array
    {
        [$rules, $names] = $this->settings->rules(SignupSettings::BY_PROVIDER);

        return Validator::make($input, $rules, [], $names)->validate();
    }

    /**
     * Sign up as the newcomer offered() answered, with the form's checked values. The offer is spent at once. Answers
     * the user when the account was made (open and domains modes), or the sign-up waiting for approval (also one this
     * address already had waiting). Refused (409) when the address or the provider's identity got an account meanwhile.
     */
    public function signUpWithProvider(array $offer, array $values, Session $session, ?string $ip): User|Signup
    {
        $session->forget(self::OFFER);
        $email = $offer['email'];
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()
            || UserIdentity::query()->where('provider_id', $offer['provider'])->where('subject', $offer['subject'])->exists()) {
            throw new SignupRefused(409, __('signup.already_registered'));
        }
        $earlier = Signup::query()->where('email', $email)->whereIn('status', [Signup::UNVERIFIED, Signup::WAITING])->latest()->first();
        if ($earlier?->status === Signup::WAITING) {
            return $earlier;
        }
        $terms = $this->settings->current()['terms'];
        $signup = ($earlier ?? new Signup)->forceFill([
            'email' => $email, 'phone' => null, 'name' => $values['name'], 'account_name' => $values['accountName'], 'password' => null,
            'answers' => Arr::where($values['answers'] ?? [], fn ($v) => $v !== null && $v !== ''),
            'terms_version' => $terms['version'] ?? null, 'terms_accepted_at' => $terms !== null ? now() : null,
            'ip' => $ip, 'token_hash' => null, 'expires_at' => null, 'status' => Signup::WAITING, 'verified_at' => now(),
            'provider_id' => $offer['provider'], 'provider_subject' => $offer['subject'],
        ]);
        $signup->save();

        return $this->settings->current()['mode'] === SignupSettings::APPROVAL ? $signup : $this->complete($signup);
    }

    /** Staff approve a sign-up waiting for approval: its account is made, and the person is told. */
    public function approve(Signup $signup, User $by): User
    {
        if ($signup->status !== Signup::WAITING) {
            throw new SignupRefused(409, __('signup.not_waiting'));
        }
        $signup->forceFill(['decided_by' => $by->id, 'decided_at' => now()])->save();
        $user = $this->complete($signup);
        if ($user->email !== null) {
            $this->emails->send('signup_approved', $user->email, ['name' => $user->name], $this->senders->link('console'));
        } else {
            $this->sms->send('signup_approved', $user->phone, ['site' => $this->senders->link('console')]);
        }

        return $user;
    }

    /** Staff refuse a sign-up (waiting, or not yet verified): its password is wiped, and a verified person is told. */
    public function reject(Signup $signup, User $by, ?string $reason): void
    {
        if (! in_array($signup->status, [Signup::UNVERIFIED, Signup::WAITING], true)) {
            throw new SignupRefused(409, __('signup.not_waiting'));
        }
        $verified = $signup->status === Signup::WAITING;
        $signup->forceFill(['status' => Signup::REJECTED, 'decided_by' => $by->id, 'decided_at' => now(), 'reason' => $reason,
            'password' => null, 'token_hash' => null])->save();
        if ($verified && $signup->email !== null) {
            $this->emails->send('signup_rejected', $signup->email, ['name' => $signup->name]);
        } elseif ($verified) {
            $this->sms->send('signup_rejected', $signup->phone);
        }
    }

    /** Delete unverified sign-ups whose link expired more than config('kit.signup.purge_days') ago. Answers how many. */
    public function purge(): int
    {
        return Signup::query()->where('status', Signup::UNVERIFIED)
            ->where('expires_at', '<', now()->subDays(config('kit.signup.purge_days')))->delete();
    }

    /**
     * Confirm an unverified sign-up whose link or code (`$hash`) checked out: in approval mode it then waits for staff,
     * else its account is made. Null when another try confirmed it first.
     */
    private function confirm(Signup $signup, string $hash): User|Signup|null
    {
        // Claim it: of two tabs confirming one link, one wins.
        $claimed = Signup::query()->whereKey($signup->id)->where('status', Signup::UNVERIFIED)
            ->where('token_hash', $hash)->update(['status' => Signup::WAITING, 'verified_at' => now(), 'token_hash' => null]);
        if ($claimed !== 1) {
            return null;
        }
        $signup = $signup->fresh();

        return $this->settings->current()['mode'] === SignupSettings::APPROVAL ? $signup : $this->complete($signup);
    }

    /** Make the account of a verified (and, if needed, approved) sign-up. */
    private function complete(Signup $signup): User
    {
        $taken = $this->taken($signup);
        if ($taken !== null) {
            $signup->forceFill(['status' => Signup::REJECTED, 'reason' => $taken, 'password' => null])->save();
            throw new SignupRefused(409, $taken);
        }
        $workspace = $this->customers->create($signup->account_name);
        $user = DB::transaction(function () use ($signup, $workspace) {
            $user = new User(['name' => $signup->name, 'email' => $signup->email, 'password' => $signup->password ?? Str::random(64)]);
            $user->forceFill([
                'email_verified_at' => $signup->email === null ? null : $signup->verified_at,
                'phone' => $signup->phone, 'phone_verified_at' => $signup->phone === null ? null : $signup->verified_at,
            ])->save();
            if ($signup->provider_id !== null) {
                UserIdentity::create(['user_id' => $user->id, 'provider_id' => $signup->provider_id, 'subject' => $signup->provider_subject,
                    'email' => $signup->email]);
            }
            WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'role' => config('kit.team.managers')[0]]);
            $signup->forceFill(['status' => Signup::DONE, 'user_id' => $user->id, 'workspace_id' => $workspace->id, 'password' => null])->save();

            return $user;
        });
        $this->trial($workspace, $signup);

        return $user;
    }

    /** Why this sign-up's account cannot be made (its address, number or provider identity has one already), or null. */
    private function taken(Signup $signup): ?string
    {
        if ($signup->email !== null && User::query()->whereRaw('lower(email) = ?', [$signup->email])->exists()) {
            return __('signup.already_registered');
        }
        if ($signup->phone !== null && User::query()->where('phone', $signup->phone)->exists()) {
            return __('signup.number_registered');
        }
        if ($signup->provider_id !== null && UserIdentity::query()->where('provider_id', $signup->provider_id)
            ->where('subject', $signup->provider_subject)->exists()) {
            return __('signup.already_registered');
        }

        return null;
    }

    private function trial(Workspace $workspace, Signup $signup): void
    {
        $amount = $this->settings->current()['trialCredit'];
        if ($amount === null || ! $this->pricing->inForce()) {
            return;
        }
        $this->wallet->credit($workspace, $amount, $this->pricing->settings()['currency'], __('signup.trial_note'), null, "signup:{$signup->id}");
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** A texted code's hash: keyed by APP_KEY and bound to its number, as App\Kit\Auth\PhoneCodes keeps its codes. */
    private static function codeHash(string $address, string $code): string
    {
        return hash_hmac('sha256', "{$address}|{$code}", (string) config('app.key'));
    }
}
