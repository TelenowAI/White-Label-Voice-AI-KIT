<?php

namespace App\Http\Middleware;

use App\Kit\Console\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The console's account routes: the signed-in user's current customer account (CurrentWorkspace), put on the request
 * as `kit.membership`. Signed out answers 401; a user in no account (the operator's staff, say) 403 — each with the
 * kit API's `{message}`. Applied in routes/api.php.
 */
final class EnsureMember
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return response()->json(['message' => __('customers.sign_in')], 401);
        }
        $membership = $this->current->membership($request);
        if ($membership === null) {
            return response()->json(['message' => __('console.no_account')], 403);
        }
        // Staff looking at a customer's console only read (App\Kit\Console\ViewAs).
        if (! $membership->exists && $membership->role === config('kit.team.viewer_role') && ! $request->isMethodSafe()) {
            return response()->json(['message' => __('console.view_as_read_only')], 403);
        }
        $request->attributes->set('kit.membership', $membership);

        return $next($request);
    }
}
