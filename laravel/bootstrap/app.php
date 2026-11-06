<?php

use App\Http\Middleware\PrepareInstallation;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The public site and the console's pages: no session or cookie middleware (routes/site.php).
        then: fn () => Route::group([], __DIR__.'/../routes/site.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // PrepareInstallation must run before the cookie and session middleware it prepares for: on the priority
        // list, or Laravel's priority sort moves them ahead of it (routes/api.php).
        $middleware->prependToPriorityList(before: EncryptCookies::class, prepend: PrepareInstallation::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
