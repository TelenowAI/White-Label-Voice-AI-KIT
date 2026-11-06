<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Email\Emails;
use App\Kit\Signup\SignInLinks;
use App\Kit\Signup\SignupSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

use function Illuminate\Support\defer;

/**
 * Signing in with an emailed link (contract/kit-api.yaml requestSignInLink, useSignInLink): customers only, when the
 * operator has the magic-link method on and email works (else 409). Asking answers the same whether or not the address
 * is a customer's; the link is made after the answer. Using it (a POST from the page, so a mail scanner's GET spends
 * nothing) signs the person in; unknown, used and expired links answer the same 404. Slowed by the `kit-signup` limiter.
 */
final class SignInLinkController
{
    public function __construct(private readonly SignInLinks $links, private readonly SignupSettings $settings, private readonly Emails $emails) {}

    public function request(Request $request): JsonResponse
    {
        $values = $request->validate(['email' => ['required', 'string', 'email', 'max:255']]);
        if (! $this->settings->uses('magic_link') || ! $this->emails->on()) {
            return response()->json(['message' => __('signup.link_unavailable')], 409);
        }
        defer(fn () => $this->links->request($values['email']));

        return response()->json(['message' => __('signup.link_sent')], 202);
    }

    public function use(Request $request, string $token): JsonResponse
    {
        $user = $this->links->use($token);
        if ($user === null) {
            return response()->json(['message' => __('signup.link_gone')], 404);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => SessionController::present($user)]);
    }
}
