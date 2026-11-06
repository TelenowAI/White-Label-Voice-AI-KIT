<?php

namespace App\Http\Middleware;

use App\Kit\Setup\EnvironmentFile;
use App\Kit\Setup\Installation;
use Closure;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Lets the kit's session routes (the setup wizard, signing in) work on a fresh install, before the wizard has set
 * anything up. Runs before the session and cookie middleware, and does nothing once the install is finished.
 * - No APP_KEY yet (a release has no `.env`): make one and save it to the environment file, so cookies and the
 *   session can be encrypted from the first visit. If the file cannot be written the key lasts this request
 *   only, and the wizard's environment step reports the file as a blocking problem.
 * - Session or cache on the database before its tables exist: use files for this request instead.
 *
 * Not here: the wizard itself (App\Kit\Setup). Applied in routes/api.php.
 */
final class PrepareInstallation
{
    public function __construct(private readonly Installation $installation, private readonly EnvironmentFile $env) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->installation->installed()) {
            $this->ensureAppKey();
            $this->ensureStores();
        }

        return $next($request);
    }

    private function ensureAppKey(): void
    {
        if (config('app.key')) {
            return;
        }
        $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
        try {
            $this->env->set(['APP_KEY' => $key]);
        } catch (Throwable) {
            // The environment step shows the file as not writable.
        }
        config(['app.key' => $key]);
        app()->forgetInstance('encrypter');
        Facade::clearResolvedInstance('encrypter');
    }

    private function ensureStores(): void
    {
        if (config('session.driver') === 'database' && ! $this->installation->tableUsable(config('session.table'))) {
            config(['session.driver' => 'file']);
        }
        if (config('cache.default') === 'database' && ! $this->installation->tableUsable(config('cache.stores.database.table'))) {
            config(['cache.default' => 'file']);
        }
    }
}
