<?php

namespace App\Http\Middleware;

use App\Kit\Staff\Abilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin route the signed-in staff role may use (`EnsureAbility::class.':money'`, config('kit.admin.abilities')):
 * otherwise 403 with the kit API's `{message}`. Runs after EnsureStaff; every admin route names one (routes/api.php,
 * held to that by a test).
 */
final class EnsureAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        if (! Abilities::may($request->user(), $ability)) {
            return response()->json(['message' => __('staff.not_allowed')], 403);
        }

        return $next($request);
    }
}
