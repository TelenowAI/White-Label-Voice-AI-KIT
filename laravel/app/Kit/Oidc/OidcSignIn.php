<?php

namespace App\Kit\Oidc;

use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Signing a customer in with an OpenID Connect provider (the authorization code flow with PKCE, OpenID Connect Core
 * §3.1):
 * - start(): a fresh `state`, `nonce` and PKCE verifier are kept in the person's session (config('kit.oidc.state_minutes'),
 *   at most config('kit.oidc.max_pending') at once) and the browser goes to the provider;
 * - finish(): the `state` must be one this session started (it is spent at once); the code is exchanged with the
 *   provider's token endpoint (with the verifier and the client secret) and its ID token checked (IdToken) — nothing
 *   the browser brought back is believed beyond the code;
 * - who it is: the person this provider's `sub` signed in as before, else the customer whose email the provider says it
 *   has verified, by its own rule (OidcProviders::emailVerified; that identity is then remembered). Staff never sign in this way, and nobody is made an account here:
 *   someone with a verified email and no account comes back as a `newcomer`, whom the caller may offer to sign up.
 * A refusal is one of a few reasons (expired, cancelled, unavailable, failed, not_verified, no_account, staff) the
 * sign-in page says in its own words; what went wrong technically goes to the log, for staff.
 *
 * Not here: the providers (OidcProviders), the HTTP around it (OidcController), signing a newcomer up
 * (App\Kit\Signup\Signups).
 */
final class OidcSignIn
{
    public const PENDING = 'kit.oidc.pending';

    public function __construct(private readonly OidcProviders $providers, private readonly IdToken $tokens) {}

    /** Where the browser goes to sign in at this provider. Throws OidcFailed when the provider cannot be read. */
    public function start(array $provider, Session $session): string
    {
        $configuration = $this->providers->configuration($provider);
        $state = self::random();
        $nonce = self::random();
        $verifier = self::random();
        $pending = array_filter($session->get(self::PENDING, []), fn (array $p) => $p['expires'] > now()->getTimestamp());
        $pending[$state] = ['provider' => $provider['id'], 'nonce' => $nonce, 'verifier' => $verifier,
            'expires' => now()->addMinutes((int) config('kit.oidc.state_minutes'))->getTimestamp()];
        $session->put(self::PENDING, array_slice($pending, -(int) config('kit.oidc.max_pending'), null, true));

        return $configuration['authorization_endpoint'].(str_contains($configuration['authorization_endpoint'], '?') ? '&' : '?').http_build_query([
            'response_type' => 'code',
            'client_id' => $provider['clientId'],
            'redirect_uri' => $this->providers->redirectUri(),
            'scope' => config('kit.oidc.scopes'),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * The person coming back from the provider.
     *
     * @return array{user: User}|array{newcomer: array{provider: string, subject: string, email: string, name: ?string}}|array{error: string}
     */
    public function finish(array $query, Session $session): array
    {
        $pending = $session->get(self::PENDING, []);
        $state = is_string($query['state'] ?? null) ? $query['state'] : '';
        $mine = $pending[$state] ?? null;
        unset($pending[$state]);
        $session->put(self::PENDING, $pending);
        if ($mine === null || $mine['expires'] <= now()->getTimestamp()) {
            return ['error' => 'expired'];
        }
        if (isset($query['error']) || ! is_string($query['code'] ?? null)) {
            return ['error' => 'cancelled'];
        }
        $provider = $this->providers->find($mine['provider']);
        if ($provider === null || ! $provider['enabled']) {
            return ['error' => 'unavailable'];
        }
        try {
            $configuration = $this->providers->configuration($provider);
            $claims = $this->tokens->verify($this->exchange($provider, $configuration, $query['code'], $mine['verifier']),
                $configuration, $provider['clientId'], $mine['nonce']);
        } catch (OidcFailed $e) {
            Log::warning('OpenID Connect sign-in failed', ['provider' => $provider['name'], 'reason' => $e->getMessage()]);

            return ['error' => 'failed'];
        }

        return $this->person($provider, $claims);
    }

    /** @return array{user: User}|array{newcomer: array{provider: string, subject: string, email: string, name: ?string}}|array{error: string} */
    private function person(array $provider, array $claims): array
    {
        $email = is_string($claims['email'] ?? null) ? mb_strtolower(trim($claims['email'])) : null;
        $identity = UserIdentity::query()->where('provider_id', $provider['id'])->where('subject', $claims['sub'])->first();
        if ($identity !== null) {
            $user = $identity->user;
            if ($user->role !== null) {
                return ['error' => 'staff'];
            }
            $identity->forceFill(['email' => $email])->save();

            return ['user' => $user];
        }
        if ($email === null) {
            return ['error' => 'no_account'];
        }
        if (! $this->providers->emailVerified($provider, $claims)) {
            return ['error' => 'not_verified'];
        }
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($user === null) {
            $name = is_string($claims['name'] ?? null) ? mb_substr(trim($claims['name']), 0, 120) : '';

            return ['newcomer' => ['provider' => $provider['id'], 'subject' => $claims['sub'], 'email' => $email, 'name' => $name === '' ? null : $name]];
        }
        if ($user->role !== null) {
            return ['error' => 'staff'];
        }
        UserIdentity::create(['user_id' => $user->id, 'provider_id' => $provider['id'], 'subject' => $claims['sub'], 'email' => $email]);

        return ['user' => $user];
    }

    /** The code for tokens at the provider's token endpoint; answers the ID token. */
    private function exchange(array $provider, array $configuration, string $code, string $verifier): string
    {
        $body = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->providers->redirectUri(), 'code_verifier' => $verifier];
        $request = Http::asForm()->acceptJson()->timeout((int) config('kit.oidc.timeout_seconds'));
        // The client authenticates in the body when the provider takes that, else with HTTP Basic (the default, §9).
        if (in_array('client_secret_post', $configuration['token_endpoint_auth_methods_supported'] ?? [], true)) {
            $body += ['client_id' => $provider['clientId'], 'client_secret' => $provider['clientSecret']];
        } else {
            $request = $request->withBasicAuth(urlencode($provider['clientId']), urlencode($provider['clientSecret']));
        }
        try {
            $response = $request->post($configuration['token_endpoint'], $body);
        } catch (ConnectionException $e) {
            throw new OidcFailed($e->getMessage(), 0, $e);
        }
        $idToken = $response->json('id_token');
        if (! $response->successful() || ! is_string($idToken)) {
            throw new OidcFailed("The token endpoint answered {$response->status()}: ".mb_substr((string) $response->body(), 0, 200));
        }

        return $idToken;
    }

    private static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
