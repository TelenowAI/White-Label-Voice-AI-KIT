<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Auth\PhoneCodes;
use App\Kit\Sms\PhoneNumber;
use App\Kit\Sms\Sms;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in person's own account (contract/kit-api.yaml getAccount, requestPhoneCode, verifyPhone, removePhone):
 * their name and email (none for someone who signed up with their phone), and their phone number — added only by
 * proving it with a code texted to it (it then leaves any other account that held it; App\Kit\Auth\PhoneCodes),
 * removed at will unless it is the only way in (no email: 409). Texts need SMS on (else 409); a text no sender could
 * send answers 503. Signed out: 401.
 * Asking for a code is slowed by the `kit-phone-code` limiter, using one by `kit-phone-verify`.
 */
final class AccountController
{
    public function __construct(private readonly PhoneCodes $codes, private readonly Sms $sms) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return $user === null ? $this->signedOut() : response()->json($this->present($user));
    }

    public function requestPhoneCode(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->signedOut();
        }
        if (! $this->sms->on()) {
            return response()->json(['message' => __('sms.no_texts')], 409);
        }
        $phone = $this->number($request);
        if (! $this->codes->requestVerify($user, $phone)) {
            return response()->json(['message' => __('sms.not_sent')], 503);
        }

        return response()->json(['message' => __('sms.code_sent_to', ['phone' => $phone])], 202);
    }

    public function verifyPhone(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->signedOut();
        }
        $phone = $this->number($request);
        $code = $request->validate(['code' => ['required', 'string', 'max:20']], [], ['code' => __('sms.fields.code')])['code'];
        if (! $this->codes->verify($user, $phone, trim($code))) {
            throw ValidationException::withMessages(['code' => [__('sms.code_wrong')]]);
        }

        return response()->json($this->present($user->fresh()));
    }

    public function removePhone(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return $this->signedOut();
        }
        if ($user->email === null) {
            return response()->json(['message' => __('sms.only_way_in')], 409);
        }
        $user->forceFill(['phone' => null, 'phone_verified_at' => null])->save();

        return response()->json($this->present($user));
    }

    private function present(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone, 'texts' => $this->sms->on()];
    }

    private function number(Request $request): string
    {
        return PhoneNumber::normalize($request->input('phone')) ?? throw ValidationException::withMessages(['phone' => [__('sms.number_shape')]]);
    }

    private function signedOut(): JsonResponse
    {
        return response()->json(['message' => __('customers.sign_in')], 401);
    }
}
