<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Auth\PhoneCodes;
use App\Kit\Signup\SignupSettings;
use App\Kit\Sms\PhoneNumber;
use App\Kit\Sms\Sms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Signing in with a code texted to a phone (contract/kit-api.yaml requestSignInCode, useSignInCode): customers only,
 * when the operator has the `phone_code` way in on and the kit can send texts (else 409). Asking answers the same
 * whether or not a customer has proven that number; the code is made and texted after the answer. Using it signs the
 * person in; a wrong, used, expired or worn-out code answers the same. Slowed by the `kit-phone-code` (asking) and
 * `kit-phone-verify` (using) limiters.
 */
final class PhoneCodeController
{
    public function __construct(private readonly PhoneCodes $codes, private readonly SignupSettings $settings, private readonly Sms $sms) {}

    public function request(Request $request): JsonResponse
    {
        if (! $this->on()) {
            return response()->json(['message' => __('sms.codes_unavailable')], 409);
        }
        $phone = $this->number($request);
        defer(fn () => $this->codes->requestSignIn($phone));

        return response()->json(['message' => __('sms.code_on_its_way')], 202);
    }

    public function use(Request $request): JsonResponse
    {
        if (! $this->on()) {
            return response()->json(['message' => __('sms.codes_unavailable')], 409);
        }
        $phone = $this->number($request);
        $code = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => __('sms.fields.code')])['code'];
        $user = $this->codes->signIn($phone, trim($code));
        if ($user === null) {
            throw ValidationException::withMessages(['code' => [__('sms.code_wrong')]]);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => SessionController::present($user)]);
    }

    private function on(): bool
    {
        return $this->sms->on() && $this->settings->uses('phone_code');
    }

    private function number(Request $request): string
    {
        return PhoneNumber::normalize($request->input('phone')) ?? throw ValidationException::withMessages(['phone' => [__('sms.number_shape')]]);
    }
}
