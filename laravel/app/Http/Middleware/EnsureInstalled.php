<?php

namespace App\Http\Middleware;

use App\Kit\Setup\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes that need a finished install (the admin panel, later the console): before it they answer 409 with a
 * message, never run against a half-set-up kit. Applied in routes/api.php.
 */
final class EnsureInstalled
{
    public function __construct(private readonly Installation $installation) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installation->installed()) {
            return response()->json(['message' => __('customers.not_installed')], 409);
        }

        return $next($request);
    }
}
