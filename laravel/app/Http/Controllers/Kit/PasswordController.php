<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Auth\PasswordResets;
use App\Kit\Email\Emails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use function Illuminate\Support\defer;

/**
 * Password reset (contract/kit-api.yaml forgotPassword, getPasswordReset, resetPassword). Public. Asking for a link
 * answers the same whether or not an account uses the address, and the link is made and emailed after the answer
 * (PasswordResets); with no way to send email it answers 409, and the console offers no "Forgot your password?".
 * Unknown, used and expired links all answer the same 404. Setting the password signs the person in. Slowed by the
 * `kit-password` limiter (AppServiceProvider).
 */
final class PasswordController
{
    public function __construct(private readonly PasswordResets $resets, private readonly Emails $emails) {}

    public function forgot(Request $request): JsonResponse
    {
        $values = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        if (! $this->emails->on()) {
            return response()->json(['message' => __('session.reset_unavailable')], 409);
        }
        defer(fn () => $this->resets->request($values['email']));

        return response()->json(['message' => __('session.reset_sent')], 202);
    }

    public function show(string $token): JsonResponse
    {
        $reset = $this->resets->pending($token);

        return $reset === null ? $this->gone()
            : response()->json(['email' => $reset->user->email, 'expiresAt' => $reset->expires_at->toIso8601String()]);
    }

    public function reset(Request $request, string $token): JsonResponse
    {
        $user = $this->resets->reset($token, $request->all());
        if ($user === null) {
            return $this->gone();
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => SessionController::present($user)]);
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => __('session.reset_gone')], 404);
    }
}
