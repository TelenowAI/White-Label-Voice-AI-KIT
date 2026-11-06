<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel is the operator's staff's alone: signed out answers 401, a user whose role is not in
 * config('kit.admin.staff_roles') answers 403 — each with the kit API's `{message}`. Applied in routes/api.php.
 */
final class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['message' => __('customers.sign_in')], 401);
        }
        if (! in_array($user->role, config('kit.admin.staff_roles'), true)) {
            return response()->json(['message' => __('customers.staff_only')], 403);
        }

        return $next($request);
    }
}
