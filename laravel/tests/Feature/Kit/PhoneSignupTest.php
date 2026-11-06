<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Kit\Sms\SmsSenders;
use App\Models\PriceList;
use App\Models\Signup;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordedTelenow;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * Signing up with a phone (App\Kit\Signup\Signups; owner 11-01: no email needed), with texts sent through the HTTP
 * driver to a provider faked with Http::fake and Telenow's recorded answers behind it: offered only while texts and the
 * `phone_code` way in are on, in every open mode but domains; the form has a number instead of an email and a password;
 * a sign-up is held — not a user — and confirmed by a texted code (once, for its minutes and tries; a newer code
 * replaces it), and only then are its user (no email, the number proven), customer account, Telenow sub-account and
 * trial credit made; that person signs in again with a texted code and cannot remove the number; a number someone
 * already proved answers the same and is texted that instead; approval mode waits for staff, and the person is texted
 * the decision; asking is slowed per number.
 */
class PhoneSignupTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private const PROVIDER = 'https://sms.example.test/v1/send';

    private const ANA = '+919876543210';

    private User $staff;

    private SmtpSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        app(Settings::class)->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        app(Settings::class)->put('brand.name', 'Acme Voice');
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->sink = SmtpSink::start();
        $senders = app(Senders::class);
        $senders->putSiteUrl(self::SITE);
        $senders->putFrom('hello@voice.example', null);
        $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $this->sink->port, 'encryption' => 'none']]]);
        $telenow = RecordedTelenow::responder();
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $r->url() === self::PROVIDER ? Http::response(['status' => 'success']) : $telenow($r));
        $sms = app(SmsSenders::class);
        $sms->put([['id' => 's1', 'driver' => 'http', 'config' => $sms->check('http', ['url' => self::PROVIDER, 'method' => 'POST',
            'format' => 'json', 'toParam' => 'to', 'numberFormat' => 'e164', 'textParam' => 'text'])]]);
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private function open(array $over = []): void
    {
        app(SignupSettings::class)->save($over + ['mode' => 'open', 'methods' => ['password', 'phone_code']]);
    }

    private function form(array $over = []): array
    {
        return array_merge(['name' => 'Ana', 'phone' => '+91 98765-43210', 'accountName' => 'Acme Dental'], $over);
    }

    /** @return list<array{to: string, code: string, text: string}> the texts the provider got */
    private function texts(): array
    {
        return Http::recorded(fn (Request $r) => $r->url() === self::PROVIDER)->map(fn (array $pair) => $pair[0]->data())->values()
            ->map(fn (array $d) => ['to' => $d['to'], 'code' => preg_match('/\b(\d{6})\b/', $d['text'], $m) ? $m[1] : '', 'text' => $d['text']])->all();
    }

    private function lastCode(): string
    {
        $texts = $this->texts();

        return $texts[count($texts) - 1]['code'];
    }

    private function confirm(string $code, string $phone = self::ANA): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/kit/signup/phone/verify', ['phone' => $phone, 'code' => $code]);
    }

    public function test_signing_up_with_a_phone_is_offered_only_with_texts_and_codes_on_and_never_in_domains_mode(): void
    {
        $this->open(['methods' => ['password']]);
        $this->getJson('/api/kit/signup')->assertOk()->assertJsonPath('open', true)->assertJsonPath('phoneFields', null);
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(409)->assertExactJson(['message' => __('signup.no_phone_signups')]);
        $this->confirm('000000')->assertStatus(409);

        $this->open(['terms' => ['url' => 'https://voice.example/terms', 'version' => '2026-11'],
            'fields' => [['key' => 'size', 'label' => 'Team size', 'type' => 'text', 'required' => false, 'options' => []]]]);
        $form = $this->getJson('/api/kit/signup')->assertOk();
        $fields = collect($form->json('phoneFields'))->keyBy('name');
        $this->assertSame(['name', 'phone', 'accountName', 'answers.size', 'terms'], $fields->keys()->all(), 'a number instead of an email and a password');
        $this->assertSame(['tel', true], [$fields['phone']['type'], $fields['phone']['required']]);
        $this->assertSame(['name', 'email', 'password', 'accountName', 'answers.size', 'terms'], array_column($form->json('fields'), 'name'), 'the email form as before');

        $this->open(['mode' => 'domains', 'domains' => ['dental.example']]);
        $this->getJson('/api/kit/signup')->assertJsonPath('phoneFields', null);
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(409);

        $this->open(['mode' => 'approval']);
        $this->assertNotNull($this->getJson('/api/kit/signup')->json('phoneFields'));
        app(SmsSenders::class)->put([]);
        $this->getJson('/api/kit/signup')->assertJsonPath('phoneFields', null);
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(409);
        $this->assertSame([0, []], [Signup::query()->count(), $this->texts()]);
    }

    public function test_a_phone_sign_up_is_held_confirmed_by_its_code_and_becomes_an_account_with_no_email(): void
    {
        $settings = app(Settings::class);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']]]]]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
        config(['kit.phone.per_minute_per_number' => 100]); // the sign-up, not the throttle
        $this->open(['trialCredit' => '100.00']);

        $this->postJson('/api/kit/signup/phone', $this->form(['accountName' => '']))->assertStatus(422)->assertJsonValidationErrors(['accountName']);
        $this->postJson('/api/kit/signup/phone', $this->form(['phone' => '98765 43210']))->assertStatus(422)
            ->assertJsonPath('errors.phone', [__('sms.number_shape')]);
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(202)
            ->assertExactJson(['message' => __('signup.check_phone', ['phone' => self::ANA])]);
        $this->assertSame(1, User::query()->count(), 'not a user yet');
        $signup = Signup::query()->sole();
        $this->assertSame([null, self::ANA, Signup::UNVERIFIED, null], [$signup->email, $signup->phone, $signup->status, $signup->password]);
        [$text] = $this->texts();
        $this->assertSame(self::ANA, $text['to']);
        $this->assertSame(__('sms.kinds.signup_code', ['code' => $text['code'], 'brand' => 'Acme Voice', 'minutes' => config('kit.phone.code_minutes')]), $text['text']);
        $this->assertSame(hash_hmac('sha256', self::ANA.'|'.$text['code'], (string) config('app.key')), $signup->token_hash, 'only its keyed hash is held');
        $this->assertCount(0, Http::recorded(fn (Request $r) => $r->url() !== self::PROVIDER), 'no Telenow org for a form');

        $wrong = $text['code'] === '000000' ? '111111' : '000000';
        $this->confirm($wrong)->assertStatus(422)->assertJsonPath('errors.code', [__('sms.code_wrong')]);
        $this->assertSame(1, User::query()->count());
        $this->confirm($text['code'], '0091 98765 43210')->assertOk()->assertJsonPath('state', 'active')
            ->assertJsonPath('user.email', null)->assertJsonPath('user.name', 'Ana');
        $ana = User::query()->where('phone', self::ANA)->sole();
        $this->assertSame($ana->id, Auth::id(), 'signed in');
        $this->assertSame([null, null], [$ana->email, $ana->email_verified_at]);
        $this->assertNotNull($ana->phone_verified_at, 'the number is proven');
        $workspace = Workspace::query()->where('name', 'Acme Dental')->sole();
        $this->assertSame(config('kit.team.managers')[0], WorkspaceMember::query()->where('user_id', $ana->id)->sole()->role);
        $this->assertNotNull($workspace->fresh()->telenow_sub_account_id, 'its Telenow sub-account, through the outbox');
        $credit = WalletEntry::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(['credit', '100.00', "signup:{$signup->id}"], [$credit->kind, (string) $credit->amount, $credit->reference]);
        $this->assertSame([Signup::DONE, $ana->id, null], [$signup->fresh()->status, $signup->fresh()->user_id, $signup->fresh()->token_hash]);
        $this->assertSame([], $this->sink->messages(), 'no email anywhere');

        $this->confirm($text['code'])->assertStatus(422);
        $this->assertSame(1, WalletEntry::query()->count());

        // Their account: no email, and the number is their way in.
        $this->getJson('/api/kit/account')->assertOk()->assertExactJson(['name' => 'Ana', 'email' => null, 'phone' => self::ANA, 'texts' => true]);
        $this->deleteJson('/api/kit/account/phone')->assertStatus(409)->assertExactJson(['message' => __('sms.only_way_in')]);
        $this->assertSame(self::ANA, $ana->fresh()->phone);

        // And back in with a texted code.
        $this->deleteJson('/api/kit/session')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/kit/session/code', ['phone' => self::ANA])->assertStatus(202);
        $this->postJson('/api/kit/session/code/verify', ['phone' => self::ANA, 'code' => $this->lastCode()])->assertOk()->assertJsonPath('user.email', null);
        $this->assertSame($ana->id, Auth::id());
    }

    public function test_a_code_works_once_for_its_tries_and_minutes_and_a_newer_one_replaces_it(): void
    {
        config(['kit.phone.per_minute_per_number' => 100, 'kit.phone.per_minute_per_address' => 100, 'kit.signup.per_minute_per_address' => 100]);
        $this->open();
        $tries = (int) config('kit.phone.code_attempts');
        $wrong = fn (string $code) => $code === '000000' ? '111111' : '000000';

        // The last try still counts.
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(202);
        $code = $this->lastCode();
        for ($i = 1; $i < $tries; $i++) {
            $this->confirm($wrong($code))->assertStatus(422);
        }
        $this->confirm($code)->assertOk()->assertJsonPath('state', 'active');
        $this->deleteJson('/api/kit/session')->assertNoContent();
        $this->app['auth']->forgetGuards();

        // Out of tries, the right code fails too.
        $ben = '+919876543211';
        $this->postJson('/api/kit/signup/phone', $this->form(['name' => 'Ben', 'phone' => $ben, 'accountName' => 'Ben Co']))->assertStatus(202);
        $code = $this->lastCode();
        for ($i = 0; $i < $tries; $i++) {
            $this->confirm($wrong($code), $ben)->assertStatus(422);
        }
        $this->confirm($code, $ben)->assertStatus(422)->assertJsonPath('errors.code', [__('sms.code_wrong')]);

        // A newer code replaces the older one, until it runs out.
        $this->postJson('/api/kit/signup/phone', $this->form(['name' => 'Ben', 'phone' => $ben, 'accountName' => 'Ben Co']))->assertStatus(202);
        $older = $this->lastCode();
        $this->postJson('/api/kit/signup/phone', $this->form(['name' => 'Ben', 'phone' => $ben, 'accountName' => 'Ben Two']))->assertStatus(202);
        $newer = $this->lastCode();
        $this->assertSame(['Ben Two'], Signup::query()->where('phone', $ben)->pluck('account_name')->all(), 'one sign-up per number');
        if ($older !== $newer) {
            $this->confirm($older, $ben)->assertStatus(422);
        }
        $this->travel((int) config('kit.phone.code_minutes') + 1)->minutes();
        $this->confirm($newer, $ben)->assertStatus(422);
        $this->travelBack();
        $this->assertFalse(User::query()->where('phone', $ben)->exists());
    }

    public function test_a_number_that_has_an_account_answers_the_same_and_is_texted_that_instead(): void
    {
        $this->open();
        $ben = User::create(['name' => 'Ben', 'email' => 'ben@dental.example', 'password' => 'a long password 8']);
        $ben->forceFill(['phone' => self::ANA, 'phone_verified_at' => now()])->save();
        $known = $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(202);
        $new = $this->postJson('/api/kit/signup/phone', $this->form(['phone' => '+919876543211']))->assertStatus(202);
        $this->assertSame(__('signup.check_phone', ['phone' => self::ANA]), $known->json('message'));
        $this->assertSame(__('signup.check_phone', ['phone' => '+919876543211']), $new->json('message'));
        $this->assertSame(['+919876543211'], Signup::query()->pluck('phone')->all());
        [$exists, $code] = $this->texts();
        $this->assertSame([self::ANA, __('sms.kinds.signup_exists', ['brand' => 'Acme Voice', 'site' => self::SITE.'/console'])], [$exists['to'], $exists['text']]);
        $this->assertSame('+919876543211', $code['to']);
    }

    public function test_approval_mode_waits_for_staff_and_the_person_is_texted_the_decision(): void
    {
        config(['kit.signup.per_minute_per_address' => 100, 'kit.phone.per_minute_per_address' => 100]);
        $this->open(['mode' => 'approval']);
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(202);
        $this->confirm($this->lastCode())->assertOk()
            ->assertExactJson(['state' => 'waiting', 'user' => null, 'message' => __('signup.waiting_phone')]);
        $this->postJson('/api/kit/signup/phone', $this->form(['name' => 'Ben', 'phone' => '+919876543211', 'accountName' => 'Ben Co']))->assertStatus(202);
        $this->confirm($this->lastCode(), '+919876543211')->assertOk()->assertJsonPath('state', 'waiting');
        $this->assertSame([1, 0], [User::query()->count(), Workspace::query()->count()], 'nothing made before approval');
        $this->assertNull(Auth::id());

        $sent = count($this->texts());
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(202);
        $this->assertCount($sent, $this->texts(), 'a number already waiting changes nothing');

        $waiting = $this->actingAs($this->staff)->getJson('/api/kit/admin/signups')->assertOk()->json('waiting');
        $this->assertSame([[null, self::ANA, null], [null, '+919876543211', null]],
            array_map(fn (array $s) => [$s['email'], $s['phone'], $s['provider']], $waiting));
        $ana = Signup::query()->where('phone', self::ANA)->sole();
        $ben = Signup::query()->where('phone', '+919876543211')->sole();

        $this->actingAs($this->staff)->postJson("/api/kit/admin/signups/{$ana->id}/approve")->assertOk()->assertJsonCount(1, 'waiting');
        $user = User::query()->where('phone', self::ANA)->sole();
        $this->assertSame([null, Signup::DONE], [$user->email, $ana->fresh()->status]);
        $approved = $this->texts()[count($this->texts()) - 1];
        $this->assertSame([self::ANA, __('sms.kinds.signup_approved', ['brand' => 'Acme Voice', 'site' => self::SITE.'/console'])], [$approved['to'], $approved['text']]);

        $this->actingAs($this->staff)->postJson("/api/kit/admin/signups/{$ben->id}/reject", ['reason' => 'Not a fit'])->assertOk();
        $refused = $this->texts()[count($this->texts()) - 1];
        $this->assertSame(['+919876543211', __('sms.kinds.signup_rejected', ['brand' => 'Acme Voice'])], [$refused['to'], $refused['text']]);
        $this->assertFalse(User::query()->where('phone', '+919876543211')->exists());
        $this->assertSame([], $this->sink->messages(), 'texted, never emailed');
    }

    public function test_a_number_proven_meanwhile_is_refused_when_the_account_would_be_made(): void
    {
        $this->open();
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(202);
        $code = $this->lastCode();
        $ben = User::create(['name' => 'Ben', 'email' => 'ben@dental.example', 'password' => 'a long password 8']);
        $ben->forceFill(['phone' => self::ANA, 'phone_verified_at' => now()])->save();
        $this->confirm($code)->assertStatus(409)->assertExactJson(['message' => __('signup.number_registered')]);
        $this->assertSame([0, Signup::REJECTED], [Workspace::query()->count(), Signup::query()->sole()->status]);
    }

    public function test_asking_is_slowed_per_number(): void
    {
        config(['kit.signup.per_minute_per_address' => 100, 'kit.phone.per_minute_per_address' => 100]);
        $this->open();
        $limit = (int) config('kit.phone.per_minute_per_number');
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/kit/signup/phone', $this->form(['phone' => $i % 2 ? '+91 98765 43210' : self::ANA]))->assertStatus(202);
        }
        $this->postJson('/api/kit/signup/phone', $this->form())->assertStatus(429);
        $this->postJson('/api/kit/signup/phone', $this->form(['phone' => '+919876543211']))->assertStatus(202);
    }
}
