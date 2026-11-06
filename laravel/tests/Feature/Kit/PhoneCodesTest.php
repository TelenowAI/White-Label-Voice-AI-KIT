<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Kit\Sms\SmsSenders;
use App\Models\PhoneCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phone numbers and sign-in codes (App\Kit\Auth\PhoneCodes, AccountController, PhoneCodeController), with texts sent
 * through the HTTP driver to a provider faked with Http::fake: a person adds a number only by proving it with a texted
 * code, and proving it moves it off any account that held it; a customer then signs in with a code texted to that
 * number; asking answers the same whatever the number, and texts only a customer's proven number (never staff's); a
 * code works once, for its minutes and its tries, and a newer one replaces it; codes need SMS and the operator's
 * `phone_code` way in; numbers are E.164; asking is slowed.
 */
class PhoneCodesTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER = 'https://sms.example.test/v1/send';

    private const ANA = '+919876543210';

    private User $staff;

    private User $ana;

    private ?\Closure $provider = null;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        app(Settings::class)->put('brand.name', 'Acme Voice');
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => ($this->provider ?? fn () => Http::response(['status' => 'success']))($r));
        $senders = app(SmsSenders::class);
        $senders->put([['id' => 's1', 'driver' => 'http', 'config' => $senders->check('http', ['url' => self::PROVIDER, 'method' => 'POST',
            'format' => 'json', 'toParam' => 'to', 'numberFormat' => 'e164', 'textParam' => 'text'])]]);
        app(SignupSettings::class)->save(['mode' => 'closed', 'methods' => ['password', 'phone_code']]);
    }

    /** @return list<array{to: string, code: string, text: string}> the texts the provider got */
    private function texts(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->data())->values()
            ->map(fn (array $d) => ['to' => $d['to'], 'code' => preg_match('/\b(\d{6})\b/', $d['text'], $m) ? $m[1] : '', 'text' => $d['text']])->all();
    }

    private function lastCode(): string
    {
        $texts = $this->texts();

        return $texts[count($texts) - 1]['code'];
    }

    /** Ana has proven her number (as the account page does). */
    private function proven(User $user, string $phone): void
    {
        $user->forceFill(['phone' => $phone, 'phone_verified_at' => now()])->save();
    }

    public function test_a_person_proves_a_number_with_a_texted_code(): void
    {
        $this->actingAs($this->ana)->getJson('/api/kit/account')->assertOk()
            ->assertExactJson(['name' => 'Ana', 'email' => 'ana@dental.example', 'phone' => null, 'texts' => true]);
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone', ['phone' => '+91 98765-43210'])->assertStatus(202)
            ->assertJsonPath('message', 'We sent a code to +919876543210.');
        $this->assertSame([self::ANA, 'is your Acme Voice code to confirm this number. It works for 10 minutes.'],
            [$this->texts()[0]['to'], substr($this->texts()[0]['text'], 7)]);
        $this->assertNull($this->ana->fresh()->phone, 'not hers until proven');

        $wrong = $this->lastCode() === '000000' ? '111111' : '000000';
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone/verify', ['phone' => self::ANA, 'code' => $wrong])->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'This code is wrong, used or expired. Ask for a new one.');
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone/verify', ['phone' => '+919876543211', 'code' => $this->lastCode()])->assertStatus(422);
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone/verify', ['phone' => self::ANA, 'code' => $this->lastCode()])->assertOk()
            ->assertJsonPath('phone', self::ANA);
        $this->assertNotNull($this->ana->fresh()->phone_verified_at);
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone/verify', ['phone' => self::ANA, 'code' => $this->lastCode()])->assertStatus(422);

        $this->actingAs($this->ana)->deleteJson('/api/kit/account/phone')->assertOk()->assertJsonPath('phone', null);
        $this->assertNull($this->ana->fresh()->phone_verified_at);
    }

    public function test_proving_a_number_moves_it_off_the_account_that_held_it(): void
    {
        $bo = User::create(['name' => 'Bo', 'email' => 'bo@old.example', 'password' => 'a long password 8']);
        $this->proven($bo, self::ANA);
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone', ['phone' => self::ANA])->assertStatus(202);
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone/verify', ['phone' => self::ANA, 'code' => $this->lastCode()])->assertOk();
        $this->assertSame([self::ANA, null, null], [$this->ana->fresh()->phone, $bo->fresh()->phone, $bo->fresh()->phone_verified_at]);
    }

    public function test_a_customer_signs_in_with_a_code_texted_to_their_proven_number(): void
    {
        $this->proven($this->ana, self::ANA);
        $this->postJson('/api/kit/session/code', ['phone' => '+91 98765 43210'])->assertStatus(202)
            ->assertExactJson(['message' => 'If an account uses that number, a code is on its way.']);
        $this->assertSame(self::ANA, $this->texts()[0]['to']);
        $this->assertStringContainsString('is your Acme Voice sign-in code', $this->texts()[0]['text']);

        $this->postJson('/api/kit/session/code/verify', ['phone' => self::ANA, 'code' => $this->lastCode()])->assertOk()
            ->assertJsonPath('user.email', 'ana@dental.example');
        $this->getJson('/api/kit/session')->assertJsonPath('user.email', 'ana@dental.example');
        $this->assertAuthenticatedAs($this->ana);
    }

    public function test_asking_answers_the_same_and_texts_only_a_customers_proven_number(): void
    {
        $this->proven($this->staff, '+14155550101');
        $this->ana->forceFill(['phone' => self::ANA])->save(); // set, never proven
        foreach (['+14155550101', self::ANA, '+447700900123'] as $number) {
            $this->postJson('/api/kit/session/code', ['phone' => $number])->assertStatus(202)
                ->assertExactJson(['message' => 'If an account uses that number, a code is on its way.']);
        }
        $this->assertSame([], $this->texts(), 'no text to staff, an unproven number, or nobody');
        $this->assertSame(0, PhoneCode::query()->count());
    }

    public function test_a_code_works_once_for_its_minutes_and_its_tries_and_a_newer_one_replaces_it(): void
    {
        config(['kit.phone.per_minute_per_number' => 100, 'kit.phone.per_minute_per_address' => 100]); // the code's own rules, not the throttle
        $this->proven($this->ana, self::ANA);
        $ask = fn () => $this->postJson('/api/kit/session/code', ['phone' => self::ANA])->assertStatus(202);
        $use = fn (string $code) => $this->postJson('/api/kit/session/code/verify', ['phone' => self::ANA, 'code' => $code]);

        $ask();
        $first = $this->lastCode();
        $ask();
        $use($first === $this->lastCode() ? 'x' : $first)->assertStatus(422); // replaced
        $use($this->lastCode())->assertOk();
        $this->app['auth']->forgetGuards();
        $use($this->lastCode())->assertStatus(422); // once

        $ask();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';
        for ($i = 0; $i < config('kit.phone.code_attempts'); $i++) {
            $use($wrong)->assertStatus(422);
        }
        $use($code)->assertStatus(422); // out of tries
        $this->app['auth']->forgetGuards();

        $ask();
        $code = $this->lastCode();
        $this->travel(config('kit.phone.code_minutes'))->minutes();
        $this->travel(1)->seconds();
        $use($code)->assertStatus(422); // expired

        // A replaced code is dead, not just hidden behind the newer one: it stays dead once the newer one is worn out.
        $ask();
        $older = $this->lastCode();
        $ask();
        $newer = $this->lastCode();
        $wrong = collect(['000000', '111111', '222222'])->first(fn (string $c) => $c !== $older && $c !== $newer);
        for ($i = 0; $i < config('kit.phone.code_attempts'); $i++) {
            $use($wrong)->assertStatus(422);
        }
        $use($older)->assertStatus(422);
    }

    public function test_codes_need_texts_and_the_operators_way_in(): void
    {
        $this->getJson('/api/kit/brand')->assertJsonPath('signInCode', true);
        $senders = app(SmsSenders::class)->all();
        app(SmsSenders::class)->put([]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInCode', false); // the way in is on, but nothing can text
        app(SmsSenders::class)->put($senders);
        app(SignupSettings::class)->save(['methods' => ['password']]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInCode', false);
        $this->postJson('/api/kit/session/code', ['phone' => self::ANA])->assertStatus(409)
            ->assertJsonPath('message', 'Sign-in codes are not available. Sign in another way.');
        $this->postJson('/api/kit/session/code/verify', ['phone' => self::ANA, 'code' => '123456'])->assertStatus(409);

        app(SmsSenders::class)->put([]);
        $this->actingAs($this->staff)->putJson('/api/kit/admin/signups/settings', ['mode' => 'closed', 'domains' => [], 'methods' => ['password', 'phone_code'],
            'fields' => [], 'terms' => null, 'trialCredit' => null])->assertStatus(422)
            ->assertJsonPath('errors.methods.0', 'Add a way to send codes first (Admin → Texts and WhatsApp).');
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone', ['phone' => self::ANA])->assertStatus(409);
        $this->actingAs($this->ana)->getJson('/api/kit/account')->assertJsonPath('texts', false);
    }

    public function test_numbers_are_e164_and_a_text_no_sender_took_says_so(): void
    {
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone', ['phone' => '98765 43210'])->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'A phone number with its country code, such as +1 415 555 0123.');
        $this->postJson('/api/kit/session/code', ['phone' => '+0123456789'])->assertStatus(422)->assertJsonValidationErrors(['phone']);
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone', ['phone' => '0091 (98765) 43210'])->assertStatus(202)
            ->assertJsonPath('message', 'We sent a code to +919876543210.');

        $this->provider = fn () => Http::response('down', 500);
        $this->actingAs($this->ana)->postJson('/api/kit/account/phone', ['phone' => self::ANA])->assertStatus(503)
            ->assertJsonPath('message', 'The text could not be sent just now. Try again in a little while.');
    }

    public function test_the_account_is_the_signed_in_persons_and_asking_and_using_are_slowed(): void
    {
        $this->getJson('/api/kit/account')->assertUnauthorized();
        $this->postJson('/api/kit/account/phone', ['phone' => '+447700900999'])->assertUnauthorized();
        $this->deleteJson('/api/kit/account/phone')->assertUnauthorized();

        $this->proven($this->ana, self::ANA);
        for ($i = 0; $i < config('kit.phone.per_minute_per_number'); $i++) {
            $this->postJson('/api/kit/session/code', ['phone' => self::ANA])->assertStatus(202);
        }
        $this->postJson('/api/kit/session/code', ['phone' => '+91 98765 43210'])->assertStatus(429);
        $this->postJson('/api/kit/session/code', ['phone' => '+447700900123'])->assertStatus(202);
        // Using codes counts per address, not against the number's asks.
        for ($i = 0; $i < config('kit.phone.per_minute_per_address'); $i++) {
            $this->postJson('/api/kit/session/code/verify', ['phone' => self::ANA, 'code' => '000001'])->assertStatus(422);
        }
        $this->postJson('/api/kit/session/code/verify', ['phone' => self::ANA, 'code' => '000001'])->assertStatus(429);
    }
}
