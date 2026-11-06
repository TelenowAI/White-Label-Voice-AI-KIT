<?php

namespace Tests\Feature\Kit;

use App\Kit\Captcha\Captcha;
use App\Kit\Email\Senders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bot check (App\Kit\Captcha\Captcha, EnsureCaptcha) against a provider faked with Http::fake: with none chosen
 * every form passes; staff choose a provider whose secret it accepts (a refused secret, or an unreachable provider, is
 * not saved) and the forms it guards; a guarded form then needs a token the provider confirms — none, a refused one, or
 * no answer from the provider is refused (it fails closed) — while unguarded forms pass; the browser is told the widget
 * and the guarded forms, never the secret; Admin → Bot check is the staff's alone.
 */
class CaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private const SECRET = '0x4AAAAAAAkit-secret';

    private User $staff;

    /** When set, the provider cannot be reached. */
    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $senders = app(Senders::class);
        $senders->putFrom('hello@voice.example', null);
        $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none']]]); // email "on"
        Http::preventStrayRequests();
        Http::fake([self::VERIFY => function (Request $r) {
            if ($this->down) {
                throw new ConnectionException('Connection timed out');
            }
            if ($r['secret'] !== self::SECRET) {
                return Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']]);
            }

            return Http::response($r['response'] === 'good-token'
                ? ['success' => true, 'hostname' => 'voice.example']
                : ['success' => false, 'error-codes' => ['invalid-input-response']]);
        }]);
    }

    private function choose(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->staff)->putJson('/api/kit/admin/captcha', array_merge(
            ['provider' => 'turnstile', 'siteKey' => '0x4AAAAAAAsite', 'secret' => self::SECRET, 'forms' => ['password_reset', 'sign_in_link']], $over));
    }

    public function test_with_no_provider_every_form_passes(): void
    {
        $this->postJson('/api/kit/password/forgot', ['email' => 'ana@dental.example'])->assertStatus(202);
        $this->getJson('/api/kit/brand')->assertJsonPath('captcha', null);
        Http::assertNothingSent();
    }

    public function test_staff_choose_a_provider_whose_secret_it_accepts(): void
    {
        $this->choose(['secret' => 'wrong'])->assertStatus(422)->assertJsonPath('errors.secret.0', 'The provider did not accept this secret key.');
        $this->choose(['secret' => ''])->assertStatus(422)->assertJsonPath('errors.secret.0', 'Enter the secret key.');
        $this->down = true;
        $this->choose()->assertStatus(422)->assertJsonPath('errors.provider.0', 'The provider could not be reached to check the keys. Try again.');
        $this->down = false;
        $this->choose(['forms' => ['nonsense']])->assertStatus(422)->assertJsonValidationErrors(['forms.0']);
        $this->assertNull(app(Captcha::class)->current()['provider']);

        $this->choose()->assertOk()->assertJsonPath('provider', 'turnstile')->assertJsonPath('secretSet', true)
            ->assertJsonPath('forms', ['password_reset', 'sign_in_link'])->assertJsonMissingPath('secret');
        $this->assertStringNotContainsString(self::SECRET, json_encode(DB::table('settings')->get()->all()), 'the secret is stored encrypted');
        $this->getJson('/api/kit/brand')->assertJsonPath('captcha', [
            'provider' => 'turnstile', 'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit',
            'global' => 'turnstile', 'siteKey' => '0x4AAAAAAAsite', 'forms' => ['password_reset', 'sign_in_link'],
        ]);

        // A secret left empty is kept (and checked again with the provider).
        $this->choose(['secret' => '', 'forms' => ['sign_up']])->assertOk()->assertJsonPath('forms', ['sign_up']);
        $this->assertTrue(app(Captcha::class)->passes('good-token', null), 'the kept secret still confirms tokens');
        $this->actingAs($this->staff)->putJson('/api/kit/admin/captcha', ['provider' => null, 'siteKey' => null, 'secret' => '', 'forms' => []])->assertOk()
            ->assertJsonPath('provider', null)->assertJsonPath('siteKey', null);
        $this->getJson('/api/kit/brand')->assertJsonPath('captcha', null);
    }

    public function test_a_guarded_form_needs_a_token_the_provider_confirms_and_fails_closed(): void
    {
        config(['kit.auth.reset_requests_per_minute' => 100, 'kit.auth.reset_requests_per_minute_per_address' => 100]); // the check, not the throttle
        $this->choose()->assertOk();
        $forgot = fn (array $body) => $this->postJson('/api/kit/password/forgot', ['email' => 'ana@dental.example'] + $body);
        $forgot([])->assertStatus(422)->assertJsonPath('errors.captchaToken.0', 'Please complete the check that you are not a robot, then try again.');
        $forgot(['captchaToken' => 'forged'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->down = true;
        $forgot(['captchaToken' => 'good-token'])->assertStatus(422);
        $this->down = false;
        $forgot(['captchaToken' => 'good-token'])->assertStatus(202);

        $sent = Http::recorded(fn (Request $r) => $r['response'] === 'good-token')->last()[0];
        $this->assertSame(['secret' => self::SECRET, 'response' => 'good-token', 'remoteip' => '127.0.0.1'], $sent->data());

        // An unguarded form passes untouched.
        $before = count(Http::recorded());
        $this->postJson('/api/kit/session', ['email' => 'op@voice.example', 'password' => 'a long password 1'])->assertOk();
        $this->assertCount($before, Http::recorded());
    }

    public function test_each_form_can_be_guarded(): void
    {
        $this->choose(['forms' => config('kit.captcha.forms')])->assertOk();
        $this->postJson('/api/kit/session', ['email' => 'op@voice.example', 'password' => 'a long password 1'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->postJson('/api/kit/signup', ['email' => 'ana@dental.example'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->postJson('/api/kit/signup/phone', ['phone' => '+919876543210'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->postJson('/api/kit/signup/provider', ['name' => 'Ana'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->postJson('/api/kit/session/link', ['email' => 'ana@dental.example'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->postJson('/api/kit/session/code', ['phone' => '+919876543210'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->postJson('/api/kit/session/email-code', ['email' => 'ana@dental.example'])->assertStatus(422)->assertJsonValidationErrors(['captchaToken']);
        $this->postJson('/api/kit/session', ['email' => 'op@voice.example', 'password' => 'a long password 1', 'captchaToken' => 'good-token'])->assertOk();
    }

    public function test_admin_bot_check_is_the_staffs_alone(): void
    {
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->actingAs($ana)->getJson('/api/kit/admin/captcha')->assertForbidden();
        $this->actingAs($ana)->putJson('/api/kit/admin/captcha', ['provider' => null, 'forms' => []])->assertForbidden();
        $shown = $this->actingAs($this->staff)->getJson('/api/kit/admin/captcha')->assertOk();
        $this->assertSame(['turnstile', 'hcaptcha', 'recaptcha'], array_column($shown->json('providers'), 'value'));
        $this->assertSame(config('kit.captcha.forms'), array_column($shown->json('formChoices'), 'value'));
    }
}
