<?php

namespace App\Kit\Oidc;

use Illuminate\Support\Str;

/**
 * Checking an ID token (OpenID Connect Core §3.1.3.7) before anything in it is believed: a JWT signed with one of
 * config('kit.oidc.algorithms') (never `none`, never a shared-secret HS*) by a key in the provider's JWKS (read again
 * once when the token names a key not in it), issued by the provider's own issuer, for this client (`aud`, and `azp`
 * when there are several audiences), not expired, and carrying the nonce this sign-in sent. Clock skew of
 * config('kit.oidc.leeway_seconds') is allowed. Answers its claims; throws OidcFailed saying what did not check out.
 */
final class IdToken
{
    private const HASHES = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];

    public function __construct(private readonly Discovery $discovery) {}

    /** @return array<string, mixed> the token's claims */
    public function verify(string $jwt, array $configuration, string $clientId, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OidcFailed('Not a signed JWT');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(Jwk::base64url($h), true);
        $claims = json_decode(Jwk::base64url($p), true);
        if (! is_array($header) || ! is_array($claims)) {
            throw new OidcFailed('Not a JWT');
        }
        $alg = $header['alg'] ?? null;
        if (! in_array($alg, config('kit.oidc.algorithms'), true) || ! isset(self::HASHES[$alg])) {
            throw new OidcFailed('Signed with '.json_encode($alg));
        }
        $key = $this->key($configuration, $header['kid'] ?? null);
        if (openssl_verify("{$h}.{$p}", Jwk::base64url($s), Jwk::toPem($key), self::HASHES[$alg]) !== 1) {
            throw new OidcFailed('The signature does not check out');
        }

        $leeway = (int) config('kit.oidc.leeway_seconds');
        $now = now()->getTimestamp();
        $audiences = (array) ($claims['aud'] ?? []);
        $checks = [
            'issuer' => ($issuer = self::issuer($configuration, $claims)) !== null && ($claims['iss'] ?? null) === $issuer,
            'audience' => in_array($clientId, $audiences, true) && (count($audiences) === 1 || ($claims['azp'] ?? null) === $clientId),
            'expiry' => is_int($claims['exp'] ?? null) && $claims['exp'] + $leeway > $now,
            'issued at' => ! isset($claims['iat']) || (is_int($claims['iat']) && $claims['iat'] - $leeway <= $now),
            'nonce' => is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']),
            'subject' => is_string($claims['sub'] ?? null) && $claims['sub'] !== '',
        ];
        foreach ($checks as $what => $ok) {
            if (! $ok) {
                throw new OidcFailed("The token's {$what} does not check out");
            }
        }

        return $claims;
    }

    /** The issuer this token must name: the provider's, with a multi-tenant provider's {tenantid} filled by the token's `tid`. */
    private static function issuer(array $configuration, array $claims): ?string
    {
        $issuer = $configuration['issuer'];
        if (! str_contains($issuer, '{tenantid}')) {
            return $issuer;
        }
        $tenant = $claims['tid'] ?? null;

        return is_string($tenant) && Str::isUuid($tenant) ? str_replace('{tenantid}', $tenant, $issuer) : null;
    }

    /** The provider's key this token names (its only RSA key when the token names none); its keys are read again once. */
    private function key(array $configuration, mixed $kid): array
    {
        foreach ([false, true] as $fresh) {
            $rsa = array_values(array_filter($this->discovery->keys($configuration, $fresh), fn (array $k) => ($k['kty'] ?? null) === 'RSA'
                && ($k['use'] ?? 'sig') === 'sig'));
            $match = $kid === null ? (count($rsa) === 1 ? $rsa : []) : array_values(array_filter($rsa, fn (array $k) => ($k['kid'] ?? null) === $kid));
            if ($match !== []) {
                return $match[0];
            }
        }
        throw new OidcFailed('The token names a key the provider does not publish: '.json_encode($kid));
    }
}
