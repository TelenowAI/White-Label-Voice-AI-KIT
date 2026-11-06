<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Setup\Field;
use App\Kit\Signup\SignupRefused;
use App\Kit\Signup\Signups;
use App\Kit\Signup\SignupSettings;
use App\Kit\Sms\PhoneNumber;
use App\Models\Signup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Signing up (contract/kit-api.yaml getSignupForm, signUp, getSignupVerification, verifySignup, verifyEmailSignup, signUpByPhone,
 * verifyPhoneSignup, getProviderSignup, signUpWithProvider). Public. The form is whatever the operator set
 * (SignupSettings), and closed answers `open: false` with no fields. A sign-up answers the same 202 whether or not the
 * address is known; what happens next is decided after the answer (Signups::hold). Opening a verification link reads it
 * (GET); confirming verifies it (POST) — signed in at once, or waiting for approval. Unknown, used and expired links all
 * answer the same 404. With the `email_code` way in on (`emailCode`), the email holds a code in place of a link, and a wrong,
 * used, expired or worn-out one answers the same 422 on `code`. Slowed by the `kit-signup` limiter.
 *
 * With a phone: the same form with a number instead of an email (`phoneFields`, null when not offered: 409), answered
 * the same 202 whatever the number, then the texted code confirms it (a wrong, used, expired or worn-out code answers
 * the same 422 on `code`); slowed also by `kit-phone-code` and `kit-phone-verify`. With a sign-in provider: the newcomer
 * the provider sent back (OidcController) reads their form here and sends it; none waiting in this session answers 404.
 */
final class SignupController
{
    public function __construct(private readonly Signups $signups, private readonly SignupSettings $settings) {}

    public function form(): JsonResponse
    {
        $open = $this->settings->open();

        return response()->json([
            'open' => $open,
            'fields' => $open ? array_map(fn (Field $f) => $f->toArray(), $this->settings->form()) : [],
            'terms' => $open ? $this->settings->current()['terms'] : null,
            'emailCode' => $open && $this->settings->uses('email_code'),
            'phoneFields' => $this->settings->byPhone()
                ? array_map(fn (Field $f) => $f->toArray(), $this->settings->form(SignupSettings::BY_PHONE)) : null,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $values = $this->signups->check($request->all());
        } catch (SignupRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
        $ip = $request->ip();
        defer(fn () => $this->signups->hold($values, $ip));

        return response()->json(['message' => __($this->settings->uses('email_code') ? 'signup.check_email_code' : 'signup.check_email')], 202);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        if (! $this->settings->open() || ! $this->settings->uses('email_code')) {
            return response()->json(['message' => __('signup.no_email_codes')], 409);
        }
        $values = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'code' => ['required', 'string', 'max:20']],
            [], ['email' => __('signup.form.email'), 'code' => __('sms.fields.code')]);
        try {
            $result = $this->signups->verifyEmail(mb_strtolower(trim($values['email'])), trim($values['code']));
        } catch (SignupRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
        if ($result === null) {
            throw ValidationException::withMessages(['code' => [__('sms.code_wrong')]]);
        }

        return $this->confirmed($request, $result, __('signup.waiting'));
    }

    public function showVerification(string $token): JsonResponse
    {
        $signup = $this->signups->pending($token);

        return $signup === null ? $this->gone()
            : response()->json(['email' => $signup->email, 'name' => $signup->name, 'accountName' => $signup->account_name]);
    }

    public function verify(Request $request, string $token): JsonResponse
    {
        try {
            $result = $this->signups->verify($token);
        } catch (SignupRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return $result === null ? $this->gone() : $this->confirmed($request, $result, __('signup.waiting'));
    }

    public function storePhone(Request $request): JsonResponse
    {
        try {
            $values = $this->signups->checkPhone($request->all());
        } catch (SignupRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
        $ip = $request->ip();
        defer(fn () => $this->signups->holdPhone($values, $ip));

        return response()->json(['message' => __('signup.check_phone', ['phone' => $values['phone']])], 202);
    }

    public function verifyPhone(Request $request): JsonResponse
    {
        if (! $this->settings->byPhone()) {
            return response()->json(['message' => __('signup.no_phone_signups')], 409);
        }
        $phone = PhoneNumber::normalize($request->input('phone')) ?? throw ValidationException::withMessages(['phone' => [__('sms.number_shape')]]);
        $code = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => __('sms.fields.code')])['code'];
        try {
            $result = $this->signups->verifyPhone($phone, trim($code));
        } catch (SignupRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
        if ($result === null) {
            throw ValidationException::withMessages(['code' => [__('sms.code_wrong')]]);
        }

        return $this->confirmed($request, $result, __('signup.waiting_phone'));
    }

    public function showProvider(Request $request): JsonResponse
    {
        $offer = $this->signups->offered($request->session());
        if ($offer === null) {
            return response()->json(['message' => __('signup.offer_gone')], 404);
        }

        return response()->json([
            'provider' => $offer['providerName'],
            'email' => $offer['email'],
            'fields' => array_map(fn (Field $f) => $f->toArray(), $this->settings->form(SignupSettings::BY_PROVIDER, $offer['name'])),
            'terms' => $this->settings->current()['terms'],
        ]);
    }

    public function storeProvider(Request $request): JsonResponse
    {
        $offer = $this->signups->offered($request->session());
        if ($offer === null) {
            return response()->json(['message' => __('signup.offer_gone')], 404);
        }
        $values = $this->signups->checkProvider($request->all());
        try {
            $result = $this->signups->signUpWithProvider($offer, $values, $request->session(), $request->ip());
        } catch (SignupRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return $this->confirmed($request, $result, __('signup.waiting'));
    }

    /** A confirmed sign-up's answer: signed in when its account was made, else waiting for approval (`$waiting` says what next). */
    private function confirmed(Request $request, User|Signup $result, string $waiting): JsonResponse
    {
        if (! $result instanceof User) {
            return response()->json(['state' => 'waiting', 'user' => null, 'message' => $waiting]);
        }
        Auth::login($result);
        $request->session()->regenerate();

        return response()->json(['state' => 'active', 'user' => SessionController::present($result), 'message' => null]);
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => __('signup.link_gone')], 404);
    }
}
