<?php

namespace App\Kit\Oidc;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * An OpenID Connect provider as it describes itself: `<issuer>/.well-known/openid-configuration` (whose `issuer` must be
 * exactly the one asked for, or the one a preset says it names, with the authorization, token and JWKS endpoints) and the keys it signs ID tokens with
 * (its JWKS). Both are kept for config('kit.oidc.cache_minutes'); the keys are read again when a token names one not in
 * them (the provider rotated its keys). Used by OidcProviders (checking an issuer) and OidcSignIn.
 */
final class Discovery
{
    /** @return array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string} and the rest */
    public function configuration(string $issuer, bool $fresh = false, ?string $names = null): array
    {
        $key = 'kit.oidc.configuration.'.sha1($issuer);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(config('kit.oidc.cache_minutes')), function () use ($issuer, $names) {
            $doc = $this->json(rtrim($issuer, '/').'/.well-known/openid-configuration');
            if (($doc['issuer'] ?? null) !== ($names ?? $issuer)) {
                throw new OidcFailed("The configuration names another issuer: ".json_encode($doc['issuer'] ?? null));
            }
            foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
                if (! is_string($doc[$endpoint] ?? null) || ! str_starts_with($doc[$endpoint], 'http')) {
                    throw new OidcFailed("The configuration has no {$endpoint}");
                }
            }

            return $doc;
        });
    }

    /** @return list<array<string, mixed>> the provider's signing keys */
    public function keys(array $configuration, bool $fresh = false): array
    {
        $key = 'kit.oidc.jwks.'.sha1($configuration['jwks_uri']);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(config('kit.oidc.cache_minutes')), function () use ($configuration) {
            $keys = $this->json($configuration['jwks_uri'])['keys'] ?? null;
            if (! is_array($keys)) {
                throw new OidcFailed('The key set has no keys');
            }

            return array_values(array_filter($keys, 'is_array'));
        });
    }

    private function json(string $url): array
    {
        try {
            $response = Http::acceptJson()->timeout((int) config('kit.oidc.timeout_seconds'))->get($url);
        } catch (ConnectionException $e) {
            throw new OidcFailed($e->getMessage(), 0, $e);
        }
        if (! $response->successful() || ! is_array($response->json())) {
            throw new OidcFailed("{$url} answered {$response->status()}");
        }

        return $response->json();
    }
}
