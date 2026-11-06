<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Oidc\OidcProviders;
use App\Models\User;
use App\Models\UserIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * Signing in with Microsoft (config('kit.oidc.presets.microsoft'), App\Kit\Oidc). The provider's configuration here is
 * Microsoft's real answer, read 2026-11-03 and kept in tests/Support: its issuer names {tenantid}, so a token's issuer is
 * checked against the token's own tenant. Microsoft states no email_verified and warns its `email` may be neither correct
 * nor stable, so an address counts only for a personal account or with `xms_edov`: without them, an existing customer with
 * that address is never signed in (the "nOAuth" takeover). No real Microsoft sign-in happens here: the tokens are signed by
 * a test key published where Microsoft publishes its own.
 */
class MicrosoftSignInTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private const HOST = 'https://login.microsoftonline.com';

    private const CONTOSO = 'aaaabbbb-0000-cccc-1111-dddd2222eeee';

    private const PERSONAL = '9188040d-6c67-4c5b-b112-36a304b66dad';

    private User $staff;

    private User $ana;

    private OpenSSLAsymmetricKey $key;

    private array $claims = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Senders::class)->putSiteUrl(self::SITE);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => match ($r->url()) {
            self::HOST.'/common/v2.0/.well-known/openid-configuration' => Http::response(json_decode((string) file_get_contents(base_path('tests/Support/microsoft_openid_configuration.json')), true)),
            self::HOST.'/common/discovery/v2.0/keys' => Http::response(['keys' => [$this->jwk()]]),
            self::HOST.'/common/oauth2/v2.0/token' => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->idToken()]),
            default => Http::response(['error' => 'not found'], 404),
        });
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function jwk(): array
    {
        $rsa = openssl_pkey_get_details($this->key)['rsa'];

        return ['kty' => 'RSA', 'use' => 'sig', 'kid' => 'ms1', 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])];
    }

    private function idToken(): string
    {
        $input = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'ms1'])).'.'.self::b64(json_encode($this->claims));
        openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    private function add(array $over = []): TestResponse
    {
        return $this->actingAs($this->staff)->postJson('/api/kit/admin/sso/providers', array_merge(
            ['preset' => 'microsoft', 'issuer' => null, 'name' => 'Microsoft', 'clientId' => 'kit-client', 'clientSecret' => 'client-secret'], $over));
    }

    private function carry(TestResponse $r): void
    {
        $cookie = $r->getCookie(config('session.cookie'), false);
        if ($cookie !== null) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    /** A whole sign-in whose ID token carries `$claims` over a good one from a work tenant; answers where the browser is sent. */
    private function signIn(array $claims = [], ?string $tenant = self::CONTOSO): string
    {
        $this->app['auth']->forgetGuards();
        $r = $this->get('/api/kit/oidc/'.app(OidcProviders::class)->all()[0]['id'].'/start')->assertStatus(302);
        $this->assertStringStartsWith(self::HOST.'/common/oauth2/v2.0/authorize?', (string) $r->headers->get('Location'));
        $this->carry($r);
        parse_str((string) parse_url((string) $r->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->claims = array_filter(array_merge(['iss' => self::HOST."/{$tenant}/v2.0", 'aud' => 'kit-client', 'tid' => $tenant, 'sub' => 'sub-ana', 'ver' => '2.0',
            'email' => 'Ana@Dental.example', 'name' => 'Ana', 'iat' => now()->getTimestamp(), 'exp' => now()->getTimestamp() + 300, 'nonce' => $q['nonce']], $claims), fn ($v) => $v !== null);
        $back = $this->get('/api/kit/oidc/callback?'.http_build_query(['state' => $q['state'], 'code' => 'the-code']))->assertStatus(303);
        $this->carry($back);

        return (string) $back->headers->get('Location');
    }

    private function assertRefused(string $reason, string $where): void
    {
        $this->assertStringContainsString("signInError={$reason}", $where);
        $this->assertGuest();
    }

    public function test_staff_add_it_from_the_preset_and_are_told_what_to_set_up_there(): void
    {
        $shown = $this->add()->assertCreated()->json();
        $preset = collect($shown['presets'])->firstWhere('value', 'microsoft');
        $this->assertSame(['Microsoft', self::HOST.'/common/v2.0'], [$preset['label'], $preset['issuer']]);
        $this->assertStringContainsString('xms_edov', $preset['help']);
        $this->assertSame(['microsoft', self::HOST.'/common/v2.0'], [$shown['providers'][0]['preset'], $shown['providers'][0]['issuer']]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInWith.0.name', 'Microsoft');
    }

    public function test_the_same_issuer_typed_by_hand_is_refused_it_needs_the_preset(): void
    {
        $this->add(['preset' => null, 'issuer' => self::HOST.'/common/v2.0'])->assertStatus(422)->assertJsonValidationErrors(['issuer']);
    }

    public function test_a_personal_account_counts_as_verified_and_signs_in_the_customer(): void
    {
        $this->add();
        $this->assertSame(self::SITE.'/console', rtrim($this->signIn([], self::PERSONAL), '/'));
        $this->assertAuthenticatedAs($this->ana);
        $this->assertSame('sub-ana', UserIdentity::query()->sole()->subject);
    }

    public function test_a_work_account_counts_only_with_xms_edov(): void
    {
        $this->add();
        foreach ([true, 'true', 1, '1'] as $yes) {
            UserIdentity::query()->delete();
            $this->signIn(['xms_edov' => $yes]);
            $this->assertAuthenticatedAs($this->ana);
        }
    }

    public function test_a_work_accounts_email_alone_never_opens_an_existing_account(): void
    {
        $this->add();
        foreach ([[], ['xms_edov' => false], ['xms_edov' => 'false'], ['xms_edov' => 0], ['email_verified' => true]] as $claims) {
            $this->assertRefused('not_verified', $this->signIn($claims));
        }
        $this->assertSame(0, UserIdentity::query()->count(), 'and is not remembered');
    }

    public function test_once_known_the_subject_is_enough_even_with_no_email(): void
    {
        $this->add();
        $this->signIn(['xms_edov' => true]);
        $this->assertAuthenticatedAs($this->ana);
        $this->post('/api/kit/session/logout');
        $this->signIn(['email' => null]);
        $this->assertAuthenticatedAs($this->ana);
    }

    public function test_the_tokens_issuer_must_be_its_own_tenants(): void
    {
        $this->add();
        $other = 'bbbbcccc-1111-dddd-2222-eeee3333ffff';
        foreach ([
            ['iss' => self::HOST."/{$other}/v2.0"],
            ['tid' => $other],
            ['tid' => null],
            ['tid' => 'not-a-tenant', 'iss' => self::HOST.'/not-a-tenant/v2.0'],
            ['iss' => self::HOST.'/{tenantid}/v2.0'],
            ['iss' => null],
            ['iss' => 'https://evil.example.test/'.self::CONTOSO.'/v2.0'],
            ['aud' => 'another-client'],
        ] as $claims) {
            $this->assertRefused('failed', $this->signIn($claims + ['xms_edov' => true]));
        }
    }

    public function test_staff_never_sign_in_this_way(): void
    {
        $this->add();
        $this->assertRefused('staff', $this->signIn(['email' => 'op@voice.example', 'xms_edov' => true]));
    }

    public function test_another_provider_keeps_the_standard_rule(): void
    {
        $this->assertTrue(app(OidcProviders::class)->emailVerified(['preset' => null], ['email_verified' => true]));
        $this->assertFalse(app(OidcProviders::class)->emailVerified(['preset' => null], ['xms_edov' => true, 'tid' => self::PERSONAL]));
        $this->assertTrue(app(OidcProviders::class)->emailVerified(['preset' => 'google'], ['email_verified' => 'true']));
        $this->assertFalse(app(OidcProviders::class)->emailVerified(['preset' => 'google'], ['xms_edov' => true]));
    }
}
