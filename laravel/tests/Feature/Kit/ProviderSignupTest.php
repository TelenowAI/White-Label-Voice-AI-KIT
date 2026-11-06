<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Oidc\OidcProviders;
use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Models\Signup;
use App\Models\User;
use App\Models\UserIdentity;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\Support\RecordedTelenow;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * Signing up with a sign-in provider (App\Kit\Signup\Signups; owner 11-01: it follows the sign-up mode), against a
 * provider faked with Http::fake and real RSA keys, Telenow's recorded answers and a local SMTP sink: someone the
 * provider vouches for (a verified email) with no account is sent to the sign-up page while sign-ups are open to their
 * address, fills the form without an email or a password, and gets an account — the address confirmed, the provider's
 * identity remembered, so they sign in with it again; closed sign-ups and other domains send them back with a reason;
 * the offer lives in their session only for a while, and dies with its provider or with sign-ups; approval mode holds
 * it for staff; an address that got an account meanwhile is refused.
 */
class ProviderSignupTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private const ISSUER = 'https://id.example.test';

    private User $staff;

    private SmtpSink $sink;

    private OpenSSLAsymmetricKey $key;

    /** The claims the provider's next ID token carries. */
    private array $claims = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->sink = SmtpSink::start();
        $senders = app(Senders::class);
        $senders->putSiteUrl(self::SITE);
        $senders->putFrom('hello@voice.example', null);
        $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $this->sink->port, 'encryption' => 'none']]]);
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $telenow = RecordedTelenow::responder();
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => str_starts_with($r->url(), self::ISSUER) ? $this->provider($r) : $telenow($r));
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sso/providers', ['preset' => null, 'issuer' => self::ISSUER, 'name' => 'Acme ID',
            'clientId' => 'kit-client', 'clientSecret' => 'client-secret'])->assertCreated();
        $this->app['auth']->forgetGuards();
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** The provider: its configuration, its key, its token endpoint. */
    private function provider(Request $r): mixed
    {
        return match ($r->url()) {
            self::ISSUER.'/.well-known/openid-configuration' => Http::response(['issuer' => self::ISSUER, 'authorization_endpoint' => self::ISSUER.'/authorize',
                'token_endpoint' => self::ISSUER.'/token', 'jwks_uri' => self::ISSUER.'/jwks', 'token_endpoint_auth_methods_supported' => ['client_secret_post']]),
            self::ISSUER.'/jwks' => Http::response(['keys' => [(function () {
                $rsa = openssl_pkey_get_details($this->key)['rsa'];

                return ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'k1', 'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e'])];
            })()]]),
            self::ISSUER.'/token' => Http::response(['access_token' => 'at', 'token_type' => 'Bearer', 'id_token' => $this->idToken()]),
            default => Http::response(['error' => 'not found'], 404),
        };
    }

    private function idToken(): string
    {
        $input = self::b64(json_encode(['alg' => 'RS256', 'kid' => 'k1'])).'.'.self::b64(json_encode($this->claims));
        openssl_sign($input, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $input.'.'.self::b64($signature);
    }

    /** Send the next request with this response's session cookie, as a browser would. */
    private function carry(TestResponse $r): void
    {
        $cookie = $r->getCookie(config('session.cookie'), false);
        if ($cookie !== null) {
            $this->withUnencryptedCookie($cookie->getName(), $cookie->getValue());
        }
    }

    /** A whole round trip to the provider whose ID token carries `$claims` (over good ones for Cy); answers where the browser is sent. */
    private function viaProvider(array $claims = []): string
    {
        $start = $this->get('/api/kit/oidc/'.app(OidcProviders::class)->all()[0]['id'].'/start')->assertStatus(302);
        $this->carry($start);
        parse_str((string) parse_url($start->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->claims = array_merge(['iss' => self::ISSUER, 'aud' => 'kit-client', 'sub' => 'sub-cy', 'email' => 'Cy@Clinic.example', 'email_verified' => true,
            'name' => 'Cy Lee', 'iat' => now()->getTimestamp(), 'exp' => now()->getTimestamp() + 300, 'nonce' => $q['nonce']], $claims);
        $back = $this->get('/api/kit/oidc/callback?'.http_build_query(['state' => $q['state'], 'code' => 'the-code']))->assertStatus(303);
        $this->carry($back);

        return (string) $back->headers->get('Location');
    }

    private function open(array $over = []): void
    {
        app(SignupSettings::class)->save($over + ['mode' => 'open']);
    }

    public function test_a_newcomer_the_provider_vouches_for_signs_up_and_signs_in_with_it_again(): void
    {
        $this->open(['terms' => ['url' => 'https://voice.example/terms', 'version' => '2026-11']]);
        $this->assertSame(self::SITE.'/signup?with=provider', $this->viaProvider());
        $this->assertGuest();
        $this->assertSame([1, 0], [User::query()->count(), Signup::query()->count()], 'nothing made by coming back');

        $offer = $this->getJson('/api/kit/signup/provider')->assertOk();
        $this->assertSame(['Acme ID', 'cy@clinic.example', ['url' => 'https://voice.example/terms', 'version' => '2026-11']],
            [$offer->json('provider'), $offer->json('email'), $offer->json('terms')]);
        $this->assertSame(['name', 'accountName', 'terms'], array_column($offer->json('fields'), 'name'), 'no email and no password');
        $this->assertSame('Cy Lee', $offer->json('fields.0.value'), "the provider's name, suggested");

        $this->postJson('/api/kit/signup/provider', ['name' => 'Cy Lee', 'accountName' => ''])->assertStatus(422)->assertJsonValidationErrors(['accountName', 'terms']);
        $done = $this->postJson('/api/kit/signup/provider', ['name' => 'Cy Lee', 'accountName' => 'Clinic Co', 'terms' => '1'])->assertOk();
        $this->assertSame(['active', 'cy@clinic.example', null], [$done->json('state'), $done->json('user.email'), $done->json('message')]);
        $cy = User::query()->where('email', 'cy@clinic.example')->sole();
        $this->assertSame($cy->id, Auth::id(), 'signed in');
        $this->assertNotNull($cy->email_verified_at, 'the provider confirmed the address');
        $this->assertSame([['sub-cy', 'cy@clinic.example']], UserIdentity::query()->where('user_id', $cy->id)->get()->map(fn ($i) => [$i->subject, $i->email])->all());
        $workspace = Workspace::query()->where('name', 'Clinic Co')->sole();
        $this->assertSame(config('kit.team.managers')[0], WorkspaceMember::query()->where('user_id', $cy->id)->sole()->role);
        $this->assertNotNull($workspace->fresh()->telenow_sub_account_id, 'its Telenow sub-account, through the outbox');
        $signup = Signup::query()->sole();
        $this->assertSame([Signup::DONE, '2026-11', null], [$signup->status, $signup->terms_version, $signup->password]);

        $this->getJson('/api/kit/signup/provider')->assertNotFound()->assertExactJson(['message' => __('signup.offer_gone')]);
        $this->postJson('/api/kit/signup/provider', ['name' => 'Cy', 'accountName' => 'Again', 'terms' => '1'])->assertNotFound();
        $this->assertSame(1, Workspace::query()->count(), 'the offer is spent');

        $this->deleteJson('/api/kit/session')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->assertSame(self::SITE.'/console', $this->viaProvider(['email' => 'cy.new@elsewhere.example']), 'known again by the subject');
        $this->assertSame($cy->id, Auth::id());
    }

    public function test_no_offer_while_sign_ups_are_closed_or_not_open_to_the_address(): void
    {
        $this->assertSame(self::SITE.'/console?signInError=no_account', $this->viaProvider(), 'closed');
        $this->open(['mode' => 'domains', 'domains' => ['dental.example']]);
        $this->assertSame(self::SITE.'/console?signInError=not_allowed', $this->viaProvider());
        $this->getJson('/api/kit/signup/provider')->assertNotFound();
        $this->assertSame(self::SITE.'/console?signInError=not_verified', $this->viaProvider(['email' => 'cy@dental.example', 'email_verified' => false]));
        $this->assertSame(self::SITE.'/signup?with=provider', $this->viaProvider(['email' => 'cy@dental.example']));
        $this->assertSame('cy@dental.example', $this->getJson('/api/kit/signup/provider')->assertOk()->json('email'));
        $this->assertGuest();
    }

    public function test_the_offer_runs_out_and_dies_with_its_provider_or_with_sign_ups(): void
    {
        $this->open();
        $form = ['name' => 'Cy', 'accountName' => 'Clinic Co'];
        $this->viaProvider();
        $this->travel((int) config('kit.signup.provider_minutes') + 1)->minutes();
        $this->getJson('/api/kit/signup/provider')->assertNotFound();
        $this->postJson('/api/kit/signup/provider', $form)->assertNotFound();
        $this->travelBack();

        $this->viaProvider();
        $id = app(OidcProviders::class)->all()[0]['id'];
        app(OidcProviders::class)->update($id, ['preset' => null, 'issuer' => self::ISSUER, 'name' => 'Acme ID', 'clientId' => 'kit-client', 'enabled' => false]);
        $this->postJson('/api/kit/signup/provider', $form)->assertNotFound();
        app(OidcProviders::class)->update($id, ['preset' => null, 'issuer' => self::ISSUER, 'name' => 'Acme ID', 'clientId' => 'kit-client', 'enabled' => true]);

        $this->viaProvider();
        $this->open(['mode' => 'closed']);
        $this->postJson('/api/kit/signup/provider', $form)->assertNotFound();
        $this->assertSame([0, 1], [Signup::query()->count(), User::query()->count()]);
    }

    public function test_approval_mode_holds_a_provider_sign_up_for_staff(): void
    {
        $this->open(['mode' => 'approval']);
        $this->viaProvider();
        $this->postJson('/api/kit/signup/provider', ['name' => 'Cy Lee', 'accountName' => 'Clinic Co'])->assertOk()
            ->assertExactJson(['state' => 'waiting', 'user' => null, 'message' => __('signup.waiting')]);
        $this->assertSame([1, 0], [User::query()->count(), UserIdentity::query()->count()], 'nothing made before approval');
        $this->assertGuest();
        $this->viaProvider();
        $this->postJson('/api/kit/signup/provider', ['name' => 'Cy Lee', 'accountName' => 'Other Co'])->assertOk()->assertJsonPath('state', 'waiting');
        $this->assertSame(['Clinic Co'], Signup::query()->pluck('account_name')->all(), 'one already waiting is answered, not doubled or rewritten');

        $waiting = $this->actingAs($this->staff)->getJson('/api/kit/admin/signups')->assertOk()->json('waiting');
        $this->assertSame([['cy@clinic.example', null, 'Acme ID']], array_map(fn (array $s) => [$s['email'], $s['phone'], $s['provider']], $waiting));
        $this->actingAs($this->staff)->postJson('/api/kit/admin/signups/'.Signup::query()->sole()->id.'/approve')->assertOk();
        $cy = User::query()->where('email', 'cy@clinic.example')->sole();
        $this->assertSame('sub-cy', UserIdentity::query()->where('user_id', $cy->id)->sole()->subject, 'the identity is remembered at approval');
        $this->assertSame([['cy@clinic.example'], __('emails.kinds.signup_approved.subject', ['brand' => config('kit.brand.name')])],
            [$this->sink->messages()[0]['to'], $this->sink->messages()[0]['subject']]);

        $this->app['auth']->forgetGuards();
        $this->assertSame(self::SITE.'/console', $this->viaProvider());
        $this->assertSame($cy->id, Auth::id());
    }

    public function test_an_address_that_got_an_account_meanwhile_is_refused(): void
    {
        $this->open();
        $this->viaProvider();
        User::create(['name' => 'Cy', 'email' => 'cy@clinic.example', 'password' => 'cys own password']);
        $this->postJson('/api/kit/signup/provider', ['name' => 'Cy', 'accountName' => 'Clinic Co'])->assertStatus(409)
            ->assertExactJson(['message' => __('signup.already_registered')]);
        $this->assertSame([0, 0], [Workspace::query()->count(), Signup::query()->count()]);
    }
}
