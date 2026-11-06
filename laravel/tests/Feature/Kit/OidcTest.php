<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Oidc\OidcProviders;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * Signing in with an OpenID Connect provider (App\Kit\Oidc) against a provider faked with Http::fake, with real RSA keys
 * made here: staff add a provider only once its issuer's configuration reads (a preset, or an https issuer); the
 * browser goes to the provider with state, nonce and a PKCE challenge, and comes back to one address — only to a state
 * this session started, once; the code is exchanged with the verifier and the client secret, and the ID token is
 * believed only when its signature (by a published key, read again when rotated), issuer, audience, expiry and nonce
 * check out; then a customer gets in by the subject they used before or by an email the provider verified — never staff,
 * never an unknown person; a refusal sends the person back with a reason, never with text of anyone's choosing.
 */
class OidcTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private const ISSUER = 'https://id.example.test';

    private User $staff;

    private User $ana;

    private OpenSSLAsymmetricKey $key;

    /** @var list<array{kid: string, key: OpenSSLAsymmetricKey}> the keys the provider publishes */
    private array $published = [];

    /** The claims the provider's next ID token carries (the nonce is the one the sign-in sent, unless set). */
    private array $claims = [];

    /** How the next ID token is made: [header, key]. */
    private ?array $signWith = null;

    private ?string $configuredIssuer = null;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Senders::class)->putSiteUrl(self::SITE);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->published = [['kid' => 'k1', 'key' => $this->key]];
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->provider($r));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** The provider: its configuration, its keys, its token endpoint. */
    private function provider(Request $r): mixed
    {
        $url = $r->url();
        foreach ([self::ISSUER, 'https://accounts.google.com'] as $issuer) {
            if ($url === "{$issuer}/.well-known/openid-configuration") {
                return Http::response(['issuer' => $this->configuredIssuer ?? $issuer, 'authorization_endpoint' => "{$issuer}/authorize",
                    'token_endpoint' => "{$issuer}/token", 'jwks_uri' => "{$issuer}/jwks", 'token_endpoint_auth_methods_supported' => ['client_secret_post']]);
            }
        }
        if ($url === self::ISSUER.'/jwks') {
            return Http::response(['keys' => array_map(function (array $k) {
                $rsa = openssl_pkey_get_details($k['key'])['rsa'];

                return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $k['kid'], 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])];
            }, $this->published)]);
        }
        if ($url === self::ISSUER.'/token') {
            return Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->idToken()]);
        }

        return Http::response(['error' => 'not found'], 404);
    }

    private function idToken(): string
    {
        [$header, $key] = $this->signWith ?? [['alg' => 'RS256', 'kid' => 'k1'], $this->key];
        $input = self::b64(json_encode($header)).'.'.self::b64(json_encode($this->claims));
        if ($key === null) {
            return $input.'.';
        }
        if ($key === 'hmac') {
            return $input.'.'.self::b64(hash_hmac('sha256', $input, 'client-secret', true));
        }
        openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    private function addProvider(array $over = []): TestResponse
    {
        return $this->actingAs($this->staff)->postJson('/api/kit/admin/sso/providers', array_merge(
            ['preset' => null, 'issuer' => self::ISSUER, 'name' => 'Acme ID', 'clientId' => 'kit-client', 'clientSecret' => 'client-secret'], $over));
    }

    private function providerId(): string
    {
        return app(OidcProviders::class)->all()[0]['id'];
    }

    /** Start a sign-in as a signed-out visitor; answers the authorize request's parameters. */
    private function start(): array
    {
        $this->app['auth']->forgetGuards();
        $r = $this->get('/api/kit/oidc/'.$this->providerId().'/start')->assertStatus(302);
        $location = $r->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/authorize?', $location);
        $this->carry($r);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    /** Send the next request with this response's session cookie, as a browser would. */
    private function carry(TestResponse $r): void
    {
        $cookie = $r->getCookie(config('session.cookie'), false);
        if ($cookie !== null) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    /** Come back from the provider with a code; answers where the browser is sent. */
    private function back(array $query): string
    {
        $r = $this->get('/api/kit/oidc/callback?'.http_build_query($query))->assertStatus(303);
        $this->carry($r);

        return (string) $r->headers->get('Location');
    }

    /** A whole sign-in whose ID token carries `$claims` (over good ones for Ana). */
    private function signInWith(array $claims = []): string
    {
        $q = $this->start();
        $this->claims = array_merge(['iss' => self::ISSUER, 'aud' => 'kit-client', 'sub' => 'sub-ana', 'email' => 'Ana@Dental.example',
            'email_verified' => true, 'iat' => now()->getTimestamp(), 'exp' => now()->getTimestamp() + 300, 'nonce' => $q['nonce']], $claims);

        return $this->back(['state' => $q['state'], 'code' => 'the-code']);
    }

    public function test_staff_add_a_provider_only_once_its_issuer_reads(): void
    {
        $this->addProvider(['issuer' => 'https://nowhere.example.test'])->assertStatus(422)
            ->assertJsonPath('errors.issuer.0', "This provider's OpenID configuration could not be read: https://nowhere.example.test/.well-known/openid-configuration answered 404");
        $this->configuredIssuer = 'https://evil.example.test';
        $this->addProvider()->assertStatus(422)->assertJsonValidationErrors(['issuer']);
        $this->configuredIssuer = null;
        $this->addProvider(['issuer' => 'http://id.example.test'])->assertStatus(422)->assertJsonPath('errors.issuer.0', 'The issuer must be an https address.');
        $this->addProvider(['clientSecret' => ''])->assertStatus(422)->assertJsonValidationErrors(['clientSecret']);

        $shown = $this->addProvider()->assertCreated()->json();
        $this->assertSame([self::SITE.'/api/kit/oidc/callback', 'Acme ID', self::ISSUER, true, true],
            [$shown['redirectUri'], $shown['providers'][0]['name'], $shown['providers'][0]['issuer'], $shown['providers'][0]['secretSet'], $shown['providers'][0]['enabled']]);
        $this->assertArrayNotHasKey('clientSecret', $shown['providers'][0]);
        $this->assertStringNotContainsString('client-secret', json_encode(DB::table('settings')->get()->all()), 'the secret is stored encrypted');
        $google = $this->addProvider(['preset' => 'google', 'issuer' => null, 'name' => 'Google'])->assertCreated()->json('providers.1');
        $this->assertSame(['google', 'https://accounts.google.com'], [$google['preset'], $google['issuer']]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInWith', [['id' => $shown['providers'][0]['id'], 'name' => 'Acme ID'], ['id' => $google['id'], 'name' => 'Google']]);

        $id = $shown['providers'][0]['id'];
        $this->actingAs($this->staff)->putJson("/api/kit/admin/sso/providers/{$id}", ['preset' => null, 'issuer' => self::ISSUER, 'name' => 'Acme',
            'clientId' => 'kit-client', 'clientSecret' => '', 'enabled' => false])->assertOk();
        $this->assertSame(['client-secret', false], [app(OidcProviders::class)->find($id)['clientSecret'], app(OidcProviders::class)->find($id)['enabled']]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInWith', [['id' => $google['id'], 'name' => 'Google']]);
        $this->actingAs($this->staff)->putJson('/api/kit/admin/sso/order', ['ids' => [$id]])->assertStatus(422);
        $this->actingAs($this->staff)->putJson('/api/kit/admin/sso/order', ['ids' => [$google['id'], $id]])->assertOk()->assertJsonPath('providers.0.id', $google['id']);
        $this->actingAs($this->staff)->deleteJson("/api/kit/admin/sso/providers/{$id}")->assertNoContent();
        $this->assertCount(1, app(OidcProviders::class)->all());
    }

    public function test_a_customer_signs_in_and_is_known_again_by_their_subject(): void
    {
        $this->addProvider()->assertCreated();
        $q = $this->start();
        $this->assertSame(['code', 'kit-client', self::SITE.'/api/kit/oidc/callback', 'openid email profile', 'S256'],
            [$q['response_type'], $q['client_id'], $q['redirect_uri'], $q['scope'], $q['code_challenge_method']]);
        $this->claims = ['iss' => self::ISSUER, 'aud' => 'kit-client', 'sub' => 'sub-ana', 'email' => 'Ana@Dental.example', 'email_verified' => true,
            'iat' => now()->getTimestamp(), 'exp' => now()->getTimestamp() + 300, 'nonce' => $q['nonce']];
        $this->assertSame(self::SITE.'/console', $this->back(['state' => $q['state'], 'code' => 'the-code']));
        $this->assertAuthenticatedAs($this->ana);

        $exchange = Http::recorded(fn (Request $r) => $r->url() === self::ISSUER.'/token')->last()[0]->data();
        $this->assertSame(['authorization_code', 'the-code', self::SITE.'/api/kit/oidc/callback', 'kit-client', 'client-secret'],
            [$exchange['grant_type'], $exchange['code'], $exchange['redirect_uri'], $exchange['client_id'], $exchange['client_secret']]);
        $this->assertSame($q['code_challenge'], self::b64(hash('sha256', $exchange['code_verifier'], true)), 'the PKCE verifier answers the challenge');
        $this->assertSame([['sub-ana', 'ana@dental.example']], UserIdentity::query()->get(['subject', 'email'])->map(fn ($i) => [$i->subject, $i->email])->all());

        // Known by the subject, whatever email the provider says now.
        $this->assertSame(self::SITE.'/console', $this->signInWith(['email' => 'ana.new@elsewhere.example', 'email_verified' => false]));
        $this->assertAuthenticatedAs($this->ana);
        $this->assertSame('ana.new@elsewhere.example', UserIdentity::query()->sole()->email);
    }

    public function test_a_token_that_does_not_check_out_signs_nobody_in(): void
    {
        config(['kit.oidc.per_minute_per_address' => 100]); // the token's checks, not the throttle
        $this->addProvider()->assertCreated();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cases = [
            'signed by another key' => [[], [['alg' => 'RS256', 'kid' => 'k1'], $other]],
            'another issuer' => [['iss' => 'https://evil.example.test'], null],
            'another client' => [['aud' => 'someone-else'], null],
            'several audiences, not for this client' => [['aud' => ['kit-client', 'other'], 'azp' => 'other'], null],
            'expired' => [['exp' => now()->getTimestamp() - 120], null],
            'another nonce' => [['nonce' => 'replayed'], null],
            'no subject' => [['sub' => ''], null],
            'unsigned' => [[], [['alg' => 'none'], null]],
            'a shared-secret signature' => [[], [['alg' => 'HS256', 'kid' => 'k1'], 'hmac']],
            'a key nobody published' => [[], [['alg' => 'RS256', 'kid' => 'k9'], $this->key]],
        ];
        foreach ($cases as $what => [$claims, $signWith]) {
            $this->signWith = $signWith;
            $this->assertSame(self::SITE.'/console?signInError=failed', $this->signInWith($claims), $what);
            $this->assertGuest();
        }
        $this->signWith = null;
        $this->assertSame(self::SITE.'/console', $this->signInWith(['aud' => ['kit-client', 'other'], 'azp' => 'kit-client']), 'several audiences, for this client');
    }

    public function test_a_rotated_key_is_read_again(): void
    {
        $this->addProvider()->assertCreated();
        $this->assertSame(self::SITE.'/console', $this->signInWith()); // k1 is now kept
        $rotated = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->published[] = ['kid' => 'k2', 'key' => $rotated];
        $this->signWith = [['alg' => 'RS256', 'kid' => 'k2'], $rotated];
        $this->assertSame(self::SITE.'/console', $this->signInWith());
        $this->assertAuthenticatedAs($this->ana);
    }

    public function test_the_way_back_must_be_one_this_session_started_once(): void
    {
        $this->addProvider()->assertCreated();
        $this->assertSame(self::SITE.'/console?signInError=expired', $this->back(['state' => 'made-up', 'code' => 'c']));
        $q = $this->start();
        $this->claims = ['iss' => self::ISSUER, 'aud' => 'kit-client', 'sub' => 'sub-ana', 'email' => 'ana@dental.example', 'email_verified' => true,
            'exp' => now()->getTimestamp() + 300, 'nonce' => $q['nonce']];
        $this->assertSame(self::SITE.'/console?signInError=cancelled', $this->back(['state' => $q['state'], 'error' => 'access_denied']));
        $this->assertSame(self::SITE.'/console?signInError=expired', $this->back(['state' => $q['state'], 'code' => 'c']), 'the state is spent');

        $q = $this->start();
        $this->travel(config('kit.oidc.state_minutes') + 1)->minutes();
        $this->assertSame(self::SITE.'/console?signInError=expired', $this->back(['state' => $q['state'], 'code' => 'c']));
        $this->travelBack();

        $q = $this->start();
        $id = $this->providerId();
        app(OidcProviders::class)->update($id, ['preset' => null, 'issuer' => self::ISSUER, 'name' => 'Acme ID', 'clientId' => 'kit-client', 'enabled' => false]);
        $this->assertSame(self::SITE.'/console?signInError=unavailable', $this->back(['state' => $q['state'], 'code' => 'c']));
        $this->assertSame(self::SITE.'/console?signInError=unavailable', $this->get("/api/kit/oidc/{$id}/start")->assertStatus(303)->headers->get('Location'));
        $this->assertGuest();
    }

    public function test_only_a_customer_with_a_verified_email_gets_in(): void
    {
        $this->addProvider()->assertCreated();
        $this->assertSame(self::SITE.'/console?signInError=not_verified', $this->signInWith(['email_verified' => false]));
        $this->assertSame(self::SITE.'/console?signInError=no_account', $this->signInWith(['sub' => 'sub-x', 'email' => 'nobody@else.example']));
        $this->assertSame(self::SITE.'/console?signInError=staff', $this->signInWith(['sub' => 'sub-op', 'email' => 'op@voice.example']));
        $this->assertGuest();
        $this->assertSame(0, UserIdentity::query()->count());
        $this->assertSame(self::SITE.'/console', $this->signInWith(['email_verified' => 'true']));
        $this->assertAuthenticatedAs($this->ana);
    }

    public function test_admin_sign_in_providers_is_the_staffs_alone(): void
    {
        $this->actingAs($this->ana)->getJson('/api/kit/admin/sso')->assertForbidden();
        $this->actingAs($this->ana)->postJson('/api/kit/admin/sso/providers', ['preset' => 'google', 'name' => 'G', 'clientId' => 'x', 'clientSecret' => 'y'])->assertForbidden();
        $this->actingAs($this->staff)->getJson('/api/kit/admin/sso')->assertOk()->assertJsonPath('presets.0', ['value' => 'google', 'label' => 'Google', 'issuer' => 'https://accounts.google.com', 'help' => null]);
        $this->assertSame([], app(OidcProviders::class)->all());
    }
}
