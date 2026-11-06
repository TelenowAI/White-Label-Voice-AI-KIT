<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Signup\SignupSettings;
use App\Kit\Team\Team;
use App\Models\EmailCode;
use App\Models\Signup;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\Support\RecordedTelenow;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * Email and a one-time code, the operator's choice (App\Kit\Auth\EmailCodes, Signups::verifyEmail), over real SMTP with
 * Telenow's recorded answers behind a new account: a customer signs in with a code that works once, for a while, for a few
 * tries, and only for their address; staff and strangers get nothing and the same answer; a sign-up is confirmed by a code
 * instead of a link, with or without a password. And a way that is off is off: a customer's right password stops working
 * when the operator switches passwords off.
 */
class EmailCodeTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private User $staff;

    private User $ana;

    private SmtpSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->sink = SmtpSink::start();
        $senders = app(Senders::class);
        $senders->putSiteUrl(self::SITE);
        $senders->putFrom('hello@voice.example', null);
        $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $this->sink->port, 'encryption' => 'none']]]);
        RecordedTelenow::fake();
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private function on(array $over = []): void
    {
        app(SignupSettings::class)->save($over + ['methods' => ['password', 'email_code']]);
    }

    private function ask(string $email = 'ana@dental.example'): void
    {
        $this->postJson('/api/kit/session/email-code', ['email' => $email])->assertStatus(202)->assertExactJson(['message' => __('signup.email_code_on_its_way')]);
    }

    /** The code in the newest email. */
    private function code(): string
    {
        $messages = $this->sink->messages();
        $this->assertNotSame([], $messages);
        $last = $messages[count($messages) - 1];
        $this->assertSame(1, preg_match('/\b(\d{'.config('kit.signup.email_code.length').'})\b/', $last['text'], $m), $last['text']);

        return $m[1];
    }

    private function use(string $code, string $email = 'ana@dental.example')
    {
        return $this->postJson('/api/kit/session/email-code/verify', ['email' => $email, 'code' => $code]);
    }

    private function refused($response): void
    {
        $response->assertStatus(422)->assertJsonPath('errors.code', [__('sms.code_wrong')]);
    }

    private function wrong(string $code): string
    {
        return $code === '000000' ? '000001' : '000000';
    }

    public function test_only_when_the_operator_has_it_on_and_email_works(): void
    {
        $this->getJson('/api/kit/brand')->assertJsonPath('signInEmailCode', false);
        $this->postJson('/api/kit/session/email-code', ['email' => 'ana@dental.example'])->assertStatus(409)->assertExactJson(['message' => __('signup.email_codes_unavailable')]);
        $this->use('123456')->assertStatus(409);
        $this->on();
        $this->getJson('/api/kit/brand')->assertJsonPath('signInEmailCode', true);
        app(Senders::class)->put([]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInEmailCode', false);
        $this->postJson('/api/kit/session/email-code', ['email' => 'ana@dental.example'])->assertStatus(409);
        $this->use('123456')->assertStatus(409);
        $this->assertSame([], $this->sink->messages());
    }

    public function test_the_way_in_cannot_be_chosen_while_email_is_off(): void
    {
        app(Senders::class)->put([]);
        try {
            $this->on();
            $this->fail('saved with no way to send it');
        } catch (ValidationException $e) {
            $this->assertSame([__('signup.needs_email_for_codes')], $e->errors()['methods']);
        }
    }

    public function test_a_customer_gets_a_code_that_signs_in_once(): void
    {
        $this->on();
        $this->ask('  ANA@Dental.example ');
        [$m] = $this->sink->messages();
        $this->assertSame(['ana@dental.example'], (array) $m['to']);
        $code = $this->code();
        $this->assertStringContainsString("is {$code}", $m['text']);
        $this->assertStringNotContainsString(self::SITE.'/signin/', $m['text'], 'a code, not a link');
        $this->assertNotSame($code, EmailCode::query()->sole()->code_hash, 'only its hash is kept');

        $this->refused($this->use($this->wrong($code)));
        $this->assertGuest();
        $this->use($code)->assertOk()->assertJsonPath('user.email', 'ana@dental.example');
        $this->assertAuthenticatedAs($this->ana);
        $this->app['auth']->forgetGuards();
        $this->refused($this->use($code));
    }

    public function test_staff_and_strangers_get_nothing_and_the_same_answer(): void
    {
        $this->on();
        $this->ask('op@voice.example');
        $this->ask('nobody@dental.example');
        $this->assertSame([], $this->sink->messages());
        $this->assertSame(0, EmailCode::query()->count());
        $this->refused($this->use('123456', 'op@voice.example'));
    }

    public function test_the_code_is_sent_after_the_answer_so_the_time_it_takes_tells_nothing(): void
    {
        $this->on();
        $sentAtTheAnswer = null;
        Event::listen(RequestHandled::class, function () use (&$sentAtTheAnswer) {
            $sentAtTheAnswer = count($this->sink->messages());
        });
        $this->ask();
        $this->assertSame(0, $sentAtTheAnswer);
        $this->assertCount(1, $this->sink->messages());
    }

    public function test_asking_and_trying_are_slowed(): void
    {
        config(['kit.signup.per_minute_per_email' => 1, 'kit.phone.per_minute_per_address' => 2]);
        $this->on(['mode' => 'open']);
        $this->ask();
        $this->postJson('/api/kit/session/email-code', ['email' => 'ana@dental.example'])->assertStatus(429);
        $try = fn (string $path) => $this->postJson("/api/kit/{$path}", ['email' => 'ana@dental.example', 'code' => '000000']);
        $this->refused($try('session/email-code/verify'));
        $this->refused($try('signup/email/verify'));
        $try('session/email-code/verify')->assertStatus(429);
        $try('signup/email/verify')->assertStatus(429);
    }

    public function test_a_staff_member_cannot_use_a_code_made_before_they_became_staff(): void
    {
        $this->on();
        $this->ask();
        $code = $this->code();
        $this->ana->forceFill(['role' => User::ROLE_OWNER])->save();
        $this->refused($this->use($code));
        $this->assertGuest();
    }

    public function test_a_code_runs_out_of_tries_and_time_and_a_newer_one_replaces_it(): void
    {
        config(['kit.signup.email_code.attempts' => 2]);
        $this->on();
        $this->ask();
        $code = $this->code();
        $this->refused($this->use($this->wrong($code)));
        $this->refused($this->use($this->wrong($code)));
        $this->refused($this->use($code));
        $this->assertGuest();

        $this->ask();
        $first = $this->code();
        $this->ask();
        $second = $this->code();
        if ($first !== $second) {
            $this->refused($this->use($first));
        }
        $this->assertSame(1, EmailCode::query()->live()->count(), 'only the newest is live');

        $this->travel(config('kit.signup.email_code.minutes') + 1)->minutes();
        $this->refused($this->use($second));
        $this->assertGuest();
    }

    public function test_a_code_opens_only_its_own_address(): void
    {
        $this->on();
        User::create(['name' => 'Ben', 'email' => 'ben@dental.example', 'password' => 'a long password 8']);
        $this->ask();
        $code = $this->code();
        $this->refused($this->use($code, 'ben@dental.example'));
        $this->assertGuest();
        $this->use($code)->assertOk();
    }

    public function test_someone_who_only_uses_codes_signs_in_and_joins_a_second_account(): void
    {
        $this->on(['methods' => ['email_code']]);
        $second = Workspace::create(['name' => 'Second Co', 'status' => 'active', 'sync_state' => 'synced']);
        app(Team::class)->invite($second, ['email' => 'ana@dental.example', 'role' => 'member'], $this->staff);
        $messages = $this->sink->messages();
        $link = SmtpSink::link($messages[count($messages) - 1], self::SITE.'/console/invite');
        $this->assertNotNull($link);
        $token = substr($link, strlen(self::SITE.'/console/invite/'));

        $this->getJson("/api/kit/invites/{$token}")->assertOk()->assertJsonPath('accountExists', true)->assertJsonPath('canJoinNow', false);
        $this->postJson("/api/kit/invites/{$token}/accept", ['password' => 'a long password 9'])
            ->assertStatus(422)->assertJsonPath('errors.password.0', __('team.sign_in_first'));

        $this->ask();
        $this->use($this->code())->assertOk();
        $this->getJson("/api/kit/invites/{$token}")->assertOk()->assertJsonPath('canJoinNow', true);
        $this->postJson("/api/kit/invites/{$token}/accept", [])->assertOk();
        $this->assertSame('member', WorkspaceMember::query()->where('workspace_id', $second->id)->where('user_id', $this->ana->id)->value('role'));
    }

    public function test_a_code_asked_before_the_address_changed_opens_nothing(): void
    {
        $this->on();
        $this->ask();
        $code = $this->code();
        $this->ana->forceFill(['email' => 'ana@newdental.example'])->save();
        $this->refused($this->use($code));
        $this->assertGuest();
    }

    public function test_what_is_kept_of_a_code_is_no_use_on_another_address(): void
    {
        $this->on();
        $ben = User::create(['name' => 'Ben', 'email' => 'ben@dental.example', 'password' => 'a long password 8']);
        $this->ask();
        $code = $this->code();
        EmailCode::query()->sole()->forceFill(['email' => 'ben@dental.example', 'user_id' => $ben->id])->save();
        $this->refused($this->use($code, 'ben@dental.example'));
        $this->assertGuest();
    }

    public function test_a_sign_up_is_confirmed_by_a_code_with_no_password_and_nothing_is_made_before(): void
    {
        $this->on(['mode' => 'open', 'methods' => ['email_code']]);
        $form = $this->getJson('/api/kit/signup')->assertOk()->assertJsonPath('emailCode', true);
        $this->assertNotContains('password', array_column($form->json('fields'), 'name'));
        $body = ['name' => 'Cy', 'email' => 'Cy@Clinic.example', 'accountName' => 'Clinic Co'];
        $this->postJson('/api/kit/signup', $body)->assertStatus(202)->assertExactJson(['message' => __('signup.check_email_code')]);
        [$m] = $this->sink->messages();
        $this->assertSame(['cy@clinic.example'], (array) $m['to']);
        $code = $this->code();
        $this->assertStringNotContainsString('/signup/verify/', $m['text'], 'a code, not a link');
        $this->assertNull(User::query()->where('email', 'cy@clinic.example')->first(), 'not a user yet');
        $this->assertNotSame($code, Signup::query()->sole()->token_hash);

        $verify = fn (string $c, string $e = 'cy@clinic.example') => $this->postJson('/api/kit/signup/email/verify', ['email' => $e, 'code' => $c]);
        $this->refused($verify($this->wrong($code)));
        $this->refused($verify($code, 'other@clinic.example'));
        $verify($code)->assertOk()->assertJsonPath('state', 'active')->assertJsonPath('user.email', 'cy@clinic.example');
        $cy = User::query()->where('email', 'cy@clinic.example')->sole();
        $this->assertAuthenticatedAs($cy);
        $this->postJson('/api/kit/session', ['email' => 'cy@clinic.example', 'password' => ''])->assertStatus(422);
        $this->assertNotNull($cy->email_verified_at);
        $this->app['auth']->forgetGuards();
        $this->refused($verify($code));
    }

    public function test_with_passwords_on_too_the_form_asks_for_one_and_the_code_still_confirms(): void
    {
        $this->on(['mode' => 'open']);
        $this->assertContains('password', array_column($this->getJson('/api/kit/signup')->json('fields'), 'name'));
        $this->postJson('/api/kit/signup', ['name' => 'Cy', 'email' => 'cy@clinic.example', 'accountName' => 'Clinic Co', 'password' => 'a long password 7'])->assertStatus(202);
        $this->postJson('/api/kit/signup/email/verify', ['email' => 'cy@clinic.example', 'code' => $this->code()])->assertOk()->assertJsonPath('state', 'active');
        $this->assertTrue(Hash::check('a long password 7', User::query()->where('email', 'cy@clinic.example')->sole()->password));
    }

    public function test_signing_up_again_makes_a_new_code_with_its_tries_back(): void
    {
        config(['kit.signup.email_code.attempts' => 2]);
        $this->on(['mode' => 'open']);
        $signUp = fn () => $this->postJson('/api/kit/signup', ['name' => 'Cy', 'email' => 'cy@clinic.example', 'accountName' => 'Clinic Co', 'password' => 'a long password 7'])->assertStatus(202);
        $verify = fn (string $c) => $this->postJson('/api/kit/signup/email/verify', ['email' => 'cy@clinic.example', 'code' => $c]);

        $signUp();
        $first = $this->code();
        $this->refused($verify($this->wrong($first)));
        $this->refused($verify($this->wrong($first)));
        $signUp();
        $second = $this->code();
        $this->assertSame(1, Signup::query()->where('email', 'cy@clinic.example')->count());
        if ($second !== $first) {
            $this->refused($verify($first));
        }
        $verify($second)->assertOk();
        $this->assertNotNull(User::query()->where('email', 'cy@clinic.example')->first());
    }

    public function test_a_sign_up_runs_out_of_tries_and_time_and_a_known_address_only_hears_it_has_an_account(): void
    {
        config(['kit.signup.email_code.attempts' => 2]);
        $this->on(['mode' => 'open']);
        $signUp = fn (string $email) => $this->postJson('/api/kit/signup', ['name' => 'Cy', 'email' => $email, 'accountName' => 'Clinic Co', 'password' => 'a long password 7'])->assertStatus(202);
        $verify = fn (string $c, string $e = 'cy@clinic.example') => $this->postJson('/api/kit/signup/email/verify', ['email' => $e, 'code' => $c]);

        $signUp('cy@clinic.example');
        $code = $this->code();
        $this->refused($verify($this->wrong($code)));
        $this->refused($verify($this->wrong($code)));
        $this->refused($verify($code));
        $this->assertNull(User::query()->where('email', 'cy@clinic.example')->first());

        $signUp('cy@clinic.example');
        $again = $this->code();
        $this->travel(config('kit.signup.email_code.minutes') + 1)->minutes();
        $this->refused($verify($again));
        $this->travelBack();

        $before = count($this->sink->messages());
        $signUp('ana@dental.example');
        $messages = $this->sink->messages();
        $this->assertCount($before + 1, $messages);
        $this->assertStringContainsString('already', $messages[$before]['subject']);
        $this->assertStringNotContainsString('Your code', $messages[$before]['text'], 'no code for an address that has an account');
        $this->refused($verify('123456', 'ana@dental.example'));
    }

    public function test_in_approval_mode_a_confirmed_sign_up_waits(): void
    {
        $this->on(['mode' => 'approval']);
        $this->postJson('/api/kit/signup', ['name' => 'Cy', 'email' => 'cy@clinic.example', 'accountName' => 'Clinic Co', 'password' => 'a long password 7'])->assertStatus(202);
        $this->postJson('/api/kit/signup/email/verify', ['email' => 'cy@clinic.example', 'code' => $this->code()])->assertOk()
            ->assertExactJson(['state' => 'waiting', 'user' => null, 'message' => __('signup.waiting')]);
        $this->assertGuest();
        $this->assertSame(Signup::WAITING, Signup::query()->sole()->status);
    }

    public function test_with_codes_off_a_sign_up_is_confirmed_by_a_link_as_before(): void
    {
        app(SignupSettings::class)->save(['mode' => 'open', 'methods' => ['password']]);
        $this->getJson('/api/kit/signup')->assertJsonPath('emailCode', false);
        $this->postJson('/api/kit/signup', ['name' => 'Cy', 'email' => 'cy@clinic.example', 'accountName' => 'Clinic Co', 'password' => 'a long password 7'])
            ->assertStatus(202)->assertExactJson(['message' => __('signup.check_email')]);
        $this->assertNotNull(SmtpSink::link($this->sink->messages()[0], self::SITE.'/signup/verify'));
        $this->postJson('/api/kit/signup/email/verify', ['email' => 'cy@clinic.example', 'code' => '123456'])->assertStatus(409);
    }

    public function test_old_codes_are_forgotten(): void
    {
        $this->on();
        $this->ask();
        $this->artisan('kit:signups')->assertSuccessful();
        $this->assertSame(1, EmailCode::query()->count(), 'not yet');
        $this->travel(config('kit.signup.purge_days') + 1)->days();
        $this->artisan('kit:signups')->assertSuccessful();
        $this->assertSame(0, EmailCode::query()->count());
    }

    public function test_a_way_that_is_off_is_off_a_customers_password_stops_and_staff_keep_theirs(): void
    {
        $signIn = fn (string $email, string $password) => $this->postJson('/api/kit/session', ['email' => $email, 'password' => $password]);
        $signIn('ana@dental.example', 'a long password 9')->assertOk();
        $this->assertAuthenticatedAs($this->ana);
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        app(SignupSettings::class)->save(['methods' => ['email_code']]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInPassword', false);
        $signIn('ana@dental.example', 'a long password 9')->assertStatus(422)->assertJsonPath('errors.email.0', __('session.failed'));
        $this->assertGuest();
        $signIn('ana@dental.example', 'wrong')->assertStatus(422)->assertJsonPath('errors.email.0', __('session.failed'));
        $signIn('op@voice.example', 'a long password 1')->assertOk()->assertJsonPath('user.email', 'op@voice.example');
        $this->assertAuthenticatedAs($this->staff);
    }

    public function test_with_passwords_off_forgot_password_is_no_way_in_for_a_customer_and_staff_keep_it(): void
    {
        $token = function (): string {
            $messages = $this->sink->messages();
            $link = SmtpSink::link($messages[count($messages) - 1], self::SITE.'/reset');
            $this->assertNotNull($link);

            return substr($link, strlen(self::SITE.'/reset/'));
        };
        $forgot = fn (string $email) => $this->postJson('/api/kit/password/forgot', ['email' => $email])->assertStatus(202)->assertExactJson(['message' => __('session.reset_sent')]);
        $newPassword = fn (string $t) => $this->postJson("/api/kit/password/reset/{$t}", ['password' => 'another long password 1']);

        $forgot('ana@dental.example');
        $earlier = $token();
        $this->getJson("/api/kit/password/reset/{$earlier}")->assertOk();

        app(SignupSettings::class)->save(['methods' => ['email_code']]);
        $this->getJson("/api/kit/password/reset/{$earlier}")->assertNotFound();
        $newPassword($earlier)->assertNotFound();
        $this->assertGuest();
        $this->assertTrue(Hash::check('a long password 9', (string) $this->ana->fresh()->password), 'the password did not change');

        $before = count($this->sink->messages());
        $forgot('ana@dental.example');
        $this->assertCount($before, $this->sink->messages());

        $forgot('op@voice.example');
        $this->assertCount($before + 1, $this->sink->messages());
        $newPassword($token())->assertOk()->assertJsonPath('user.email', 'op@voice.example');
        $this->assertAuthenticatedAs($this->staff);
    }
}
