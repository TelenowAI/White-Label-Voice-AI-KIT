<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Console\ViewAs;
use App\Kit\Setup\Installation;
use App\Kit\Signup\SignupSettings;
use App\Kit\Staff\Abilities;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The signed-in session (contract/kit-api.yaml `getSession`, `signIn`, `signOut`): a session cookie on the
 * console's own origin. A wrong email or password is one refusal on `email`, never saying which was wrong, and so is a
 * customer's right password while the operator has passwords off (staff always use theirs); repeated tries are slowed by
 * the `kit-sign-in` limiter (AppServiceProvider).
 *
 * Not here: two-factor sign-in, password reset (PasswordController), customer sign-up (K1 slice 7b).
 */
final class SessionController
{
    public function __construct(private readonly Installation $installation) {}

    public function show(Request $request, ViewAs $viewAs): JsonResponse
    {
        return response()->json(['user' => self::present($request->user()), 'viewingAs' => $request->user() === null ? null : $viewAs->describe($request)]);
    }

    /** Stop looking at a customer's console as staff (App\Kit\Console\ViewAs). Answers who it was, or null. */
    public function stopViewingAs(Request $request, ViewAs $viewAs): JsonResponse
    {
        $workspace = $viewAs->stop($request);

        return response()->json(['customerId' => $workspace?->id]);
    }

    public function store(Request $request, SignupSettings $settings): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);
        // Before the database is set up there is nobody to sign in as.
        if (! $this->installation->tableUsable('users') || ! Auth::validate($credentials)) {
            throw ValidationException::withMessages(['email' => __('session.failed')]);
        }
        $user = Auth::getLastAttempted();
        if (! $settings->passwordWorksFor($user)) {
            throw ValidationException::withMessages(['email' => __('session.failed')]);
        }
        Auth::login($user);
        $request->session()->regenerate();

        return response()->json(['user' => self::present($request->user())]);
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    /** A user as every session answer shows them (getSession, signIn, resetPassword, acceptInvite). */
    public static function present(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role,
            'abilities' => Abilities::of($user)];
    }
}
