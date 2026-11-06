<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Auth\EmailCodes;
use App\Kit\Email\Emails;
use App\Kit\Signup\SignupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Signing in with a code emailed each time (contract/kit-api.yaml requestEmailCode, useEmailCode): customers only, when the
 * operator has the `email_code` way in on and the kit can send email (else 409). Asking answers the same whether or not a
 * customer uses that address; the code is made and emailed after the answer. Using it signs the person in; a wrong, used,
 * expired or worn-out code answers the same. Slowed by the `kit-signup` (asking) and `kit-phone-verify` (using) limiters.
 */
final class EmailCodeController
{
    public function __construct(private readonly EmailCodes $codes, private readonly SignupSettings $settings, private readonly Emails $emails) {}

    public function request(Request $request): JsonResponse
    {
        if (! $this->on()) {
            return response()->json(['message' => __('signup.email_codes_unavailable')], 409);
        }
        $email = $request->validate(['email' => ['required', 'string', 'email', 'max:255']], [], ['email' => __('signup.form.email')])['email'];
        defer(fn () => $this->codes->requestSignIn($email));

        return response()->json(['message' => __('signup.email_code_on_its_way')], 202);
    }

    public function use(Request $request): JsonResponse
    {
        if (! $this->on()) {
            return response()->json(['message' => __('signup.email_codes_unavailable')], 409);
        }
        $values = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'code' => ['required', 'string', 'max:20']],
            [], ['email' => __('signup.form.email'), 'code' => __('sms.fields.code')]);
        $user = $this->codes->signIn($values['email'], trim($values['code']));
        if ($user === null) {
            throw ValidationException::withMessages(['code' => [__('sms.code_wrong')]]);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => SessionController::present($user)]);
    }

    private function on(): bool
    {
        return $this->emails->on() && $this->settings->uses('email_code');
    }
}
