<?php

namespace App\Http\Controllers\Kit;

use App\Kit\Email\Senders;
use App\Kit\Oidc\OidcFailed;
use App\Kit\Oidc\OidcProviders;
use App\Kit\Oidc\OidcSignIn;
use App\Kit\Signup\Signups;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Signing in with an OpenID Connect provider (contract/kit-api.yaml startSso, finishSso): the browser is sent to the
 * provider (302), and comes back to the one callback address; then it is sent on (303) to the console signed in, to
 * `/signup?with=provider` to finish signing up (someone the provider vouches for with no account, while sign-ups are
 * open to them; App\Kit\Signup\Signups::offer), or to `/console?signInError=<reason>` (expired, cancelled,
 * unavailable, failed, not_verified, no_account, not_allowed, staff), which the sign-in page says in its words — a
 * reason, never text from the address, so nobody can make the page say what they like. Links use the site address the
 * operator confirmed. Slowed per address (`kit-oidc`).
 */
final class OidcController
{
    public function __construct(
        private readonly OidcProviders $providers,
        private readonly OidcSignIn $signIn,
        private readonly Senders $senders,
        private readonly Signups $signups,
    ) {}

    public function start(Request $request, string $id): Response
    {
        $provider = $this->providers->find($id);
        if ($provider === null || ! $provider['enabled']) {
            return $this->back('unavailable');
        }
        try {
            return response('', 302, ['Location' => $this->signIn->start($provider, $request->session())]);
        } catch (OidcFailed $e) {
            Log::warning('OpenID Connect sign-in could not start', ['provider' => $provider['name'], 'reason' => $e->getMessage()]);

            return $this->back('unavailable');
        }
    }

    public function callback(Request $request): Response
    {
        $result = $this->signIn->finish($request->query(), $request->session());
        if (isset($result['error'])) {
            return $this->back($result['error']);
        }
        if (isset($result['newcomer'])) {
            $refused = $this->signups->offer($result['newcomer'], $request->session());

            return $refused !== null ? $this->back($refused) : response('', 303, ['Location' => $this->senders->link('signup?with=provider')]);
        }
        Auth::login($result['user']);
        $request->session()->regenerate();

        return response('', 303, ['Location' => $this->senders->link('console')]);
    }

    private function back(string $reason): Response
    {
        return response('', 303, ['Location' => $this->senders->link('console?signInError='.$reason)]);
    }
}
