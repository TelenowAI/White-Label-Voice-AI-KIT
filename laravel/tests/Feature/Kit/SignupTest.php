<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Models\PriceList;
use App\Models\Signup;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Support\RecordedTelenow;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * Signing up (App\Kit\Signup) over real SMTP to a local sink and recorded Telenow answers: closed until the operator
 * opens it (and never without email); the form is the operator's (their fields, the terms box); a sign-up is checked,
 * held — not a user — and verified by an emailed link (a POST), and only then are its user, customer account (a Telenow
 * sub-account through the outbox) and trial credit made, once; an address that already has an account answers the
 * same and gets an email instead; approval mode waits for staff, who approve (the account is made, the person told) or
 * refuse; domains mode takes only listed domains; a newer sign-up replaces the older link; unverified ones are purged;
 * the settings are checked; Admin → Sign-ups is the staff's alone; asking is slowed.
 */
class SignupTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private User $staff;

    private SmtpSink $sink;

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
        RecordedTelenow::fake();
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private function open(array $over = []): void
    {
        app(SignupSettings::class)->save($over + ['mode' => 'open']);
    }

    private function pricing(): void
    {
        $settings = app(Settings::class);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']]]]]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
    }

    private function form(array $over = []): array
    {
        return array_merge(['name' => 'Ana', 'email' => 'Ana@Dental.example', 'password' => 'a long password 9', 'accountName' => 'Acme Dental'], $over);
    }

    /** The token of the link to this path in the sink's message (the last one by default). */
    private function token(string $path, int $message = -1): string
    {
        $messages = $this->sink->messages();
        $link = SmtpSink::link($messages[$message < 0 ? count($messages) + $message : $message], self::SITE."/{$path}");
        $this->assertNotNull($link, "a link to {$path}");

        return substr($link, strlen(self::SITE."/{$path}/"));
    }

    public function test_sign_ups_are_closed_until_the_operator_opens_them_and_never_without_email(): void
    {
        $this->getJson('/api/kit/signup')->assertOk()->assertExactJson(['open' => false, 'fields' => [], 'terms' => null, 'emailCode' => false, 'phoneFields' => null]);
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(409)->assertExactJson(['message' => __('signup.closed')]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signUp', false);

        $this->open();
        $this->getJson('/api/kit/signup')->assertJsonPath('open', true);
        $this->getJson('/api/kit/brand')->assertJsonPath('signUp', true);
        app(Senders::class)->put([]);
        $this->getJson('/api/kit/signup')->assertJsonPath('open', false);
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(409);
        $this->assertSame([0, 0], [Signup::query()->count(), count($this->sink->messages())]);
    }

    public function test_the_form_is_the_operators_with_its_fields_and_terms(): void
    {
        $this->open(['methods' => ['magic_link'], 'terms' => ['url' => 'https://voice.example/terms', 'version' => '2026-10'], 'fields' => [
            ['key' => 'phone', 'label' => 'Phone', 'type' => 'text', 'required' => true, 'options' => []],
            ['key' => 'size', 'label' => 'Team size', 'type' => 'select', 'required' => false, 'options' => ['1-10', '11-50']],
        ]]);
        $fields = collect($this->getJson('/api/kit/signup')->json('fields'))->keyBy('name');
        $this->assertSame(['name', 'email', 'accountName', 'answers.phone', 'answers.size', 'terms'], $fields->keys()->all(), 'no password: sign-in links only');
        $this->assertSame([['value' => '1-10', 'label' => '1-10'], ['value' => '11-50', 'label' => '11-50']], $fields['answers.size']['options']);
        $this->assertSame(['checkbox', true, 'https://voice.example/terms'], [$fields['terms']['type'], $fields['terms']['required'], $fields['terms']['help']]);

        $this->postJson('/api/kit/signup', $this->form(['accountName' => '', 'answers' => ['size' => '9000']]))->assertStatus(422)
            ->assertJsonValidationErrors(['accountName', 'answers.phone', 'answers.size', 'terms']);
        $this->postJson('/api/kit/signup', $this->form(['answers' => ['phone' => '+14155550101', 'size' => '1-10'], 'terms' => '1']))->assertStatus(202);
        $signup = Signup::query()->sole();
        $this->assertSame([['phone' => '+14155550101', 'size' => '1-10'], '2026-10', null], [$signup->answers, $signup->terms_version, $signup->password]);
        $this->assertNotNull($signup->terms_accepted_at);
    }

    public function test_a_sign_up_is_held_verified_by_its_link_and_only_then_becomes_an_account_with_its_trial_credit_once(): void
    {
        $this->pricing();
        $this->open(['trialCredit' => '100.00']);
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(202)->assertExactJson(['message' => __('signup.check_email')]);
        $this->assertSame(1, User::query()->count(), 'not a user yet');
        $signup = Signup::query()->sole();
        $this->assertSame(['ana@dental.example', Signup::UNVERIFIED], [$signup->email, $signup->status]);
        $this->assertTrue(Hash::check('a long password 9', $signup->password), 'only its hash is held');
        [$m] = $this->sink->messages();
        $this->assertSame(['ana@dental.example'], $m['to']);
        $token = $this->token('signup/verify');
        $this->assertSame(hash('sha256', $token), $signup->token_hash);
        $this->assertSame([], Http::recorded()->all(), 'no Telenow org for a form');

        $this->getJson("/api/kit/signup/verify/{$token}")->assertOk()->assertExactJson(['email' => 'ana@dental.example', 'name' => 'Ana', 'accountName' => 'Acme Dental']);
        $this->postJson("/api/kit/signup/verify/{$token}")->assertOk()->assertJsonPath('state', 'active')->assertJsonPath('user.email', 'ana@dental.example');
        $ana = User::query()->where('email', 'ana@dental.example')->sole();
        $this->assertSame($ana->id, Auth::id(), 'signed in');
        $this->assertNotNull($ana->email_verified_at);
        $this->assertTrue(Hash::check('a long password 9', $ana->password));
        $workspace = Workspace::query()->where('name', 'Acme Dental')->sole();
        $this->assertSame(config('kit.team.managers')[0], WorkspaceMember::query()->where('user_id', $ana->id)->sole()->role);
        $this->assertNotNull($workspace->fresh()->telenow_sub_account_id, 'its Telenow sub-account, through the outbox');
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/sub-accounts')));
        $credit = WalletEntry::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(['credit', '100.00', 'INR', "signup:{$signup->id}"], [$credit->kind, (string) $credit->amount, $credit->currency, $credit->reference]);
        $this->assertSame([Signup::DONE, $ana->id, $workspace->id, null, null], [$signup->fresh()->status, $signup->fresh()->user_id,
            $signup->fresh()->workspace_id, $signup->fresh()->password, $signup->fresh()->token_hash]);

        $this->postJson("/api/kit/signup/verify/{$token}")->assertNotFound()->assertExactJson(['message' => __('signup.link_gone')]);
        $this->getJson("/api/kit/signup/verify/{$token}")->assertNotFound();
        $this->assertSame(1, WalletEntry::query()->count());
    }

    public function test_a_trial_credit_waits_for_pricing_and_is_skipped_without_it(): void
    {
        $this->pricing();
        $this->open(['trialCredit' => '100.00']);
        app(Settings::class)->put('pricing.default_plan', 'gone'); // pricing no longer in force
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(202);
        $this->postJson('/api/kit/signup/verify/'.$this->token('signup/verify'))->assertOk()->assertJsonPath('state', 'active');
        $this->assertSame(0, WalletEntry::query()->count(), 'no credit without a currency to give it in');
    }

    public function test_an_address_that_has_an_account_answers_the_same_and_gets_an_email_instead(): void
    {
        $this->open();
        User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'anas own password']);
        $known = $this->postJson('/api/kit/signup', $this->form())->assertStatus(202);
        $new = $this->postJson('/api/kit/signup', $this->form(['email' => 'ben@dental.example']))->assertStatus(202);
        $this->assertSame($known->getContent(), $new->getContent());
        $this->assertSame(['ben@dental.example'], Signup::query()->pluck('email')->all());
        [$exists, $verify] = $this->sink->messages();
        $this->assertSame(['ana@dental.example'], $exists['to']);
        $this->assertSame(__('emails.kinds.signup_exists.subject', ['brand' => config('kit.brand.name')]), $exists['subject']);
        $this->assertSame(['ben@dental.example'], $verify['to']);
    }

    public function test_approval_mode_waits_for_staff_who_approve_or_refuse_and_the_person_is_told(): void
    {
        $this->open(['mode' => 'approval']);
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(202);
        $this->postJson('/api/kit/signup', $this->form(['email' => 'ben@dental.example', 'name' => 'Ben', 'accountName' => 'Ben Co']))->assertStatus(202);
        [$a, $b] = [$this->token('signup/verify', 0), $this->token('signup/verify', 1)];
        $this->postJson("/api/kit/signup/verify/{$a}")->assertOk()->assertExactJson(['state' => 'waiting', 'user' => null, 'message' => __('signup.waiting')]);
        $this->postJson("/api/kit/signup/verify/{$b}")->assertOk()->assertJsonPath('state', 'waiting');
        $this->assertSame([1, 0], [User::query()->count(), Workspace::query()->count()], 'nothing made before approval');
        $this->assertNull(Auth::id());

        $this->postJson('/api/kit/signup', $this->form())->assertStatus(202);
        $this->assertCount(2, $this->sink->messages(), 'a sign-up already waiting changes nothing');

        $waiting = $this->actingAs($this->staff)->getJson('/api/kit/admin/signups')->assertOk()->json('waiting');
        $this->assertSame(['ana@dental.example', 'ben@dental.example'], array_column($waiting, 'email'));
        $this->assertArrayNotHasKey('password', $waiting[0]);
        $ana = Signup::query()->where('email', 'ana@dental.example')->sole();
        $ben = Signup::query()->where('email', 'ben@dental.example')->sole();

        $this->actingAs($this->staff)->postJson("/api/kit/admin/signups/{$ana->id}/approve")->assertOk()->assertJsonCount(1, 'waiting');
        $this->assertSame([Signup::DONE, $this->staff->id], [$ana->fresh()->status, $ana->fresh()->decided_by]);
        $this->assertTrue(Hash::check('a long password 9', User::query()->where('email', 'ana@dental.example')->sole()->password));
        $approved = $this->sink->messages()[2];
        $this->assertSame([['ana@dental.example'], self::SITE.'/console'], [$approved['to'], SmtpSink::link($approved, self::SITE)]);

        $this->actingAs($this->staff)->postJson("/api/kit/admin/signups/{$ben->id}/reject", ['reason' => 'Not a fit'])->assertOk()->assertJsonCount(0, 'waiting');
        $this->assertSame([Signup::REJECTED, 'Not a fit', null], [$ben->fresh()->status, $ben->fresh()->reason, $ben->fresh()->password]);
        $this->assertSame(['ben@dental.example'], $this->sink->messages()[3]['to']);
        $this->assertFalse(User::query()->where('email', 'ben@dental.example')->exists());
        $this->actingAs($this->staff)->postJson("/api/kit/admin/signups/{$ben->id}/approve")->assertStatus(409)->assertExactJson(['message' => __('signup.not_waiting')]);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/signups/no-such/approve')->assertNotFound();
    }

    public function test_domains_mode_takes_only_the_listed_domains(): void
    {
        $this->open(['mode' => 'domains', 'domains' => ['Dental.example']]);
        $this->postJson('/api/kit/signup', $this->form(['email' => 'cy@elsewhere.example']))->assertStatus(422)
            ->assertJsonPath('errors.email', [__('signup.domain_not_allowed')]);
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(202);
        $this->assertSame(['ana@dental.example'], Signup::query()->pluck('email')->all());
    }

    public function test_a_newer_sign_up_replaces_the_older_link_and_unverified_ones_are_purged(): void
    {
        $this->open();
        $this->postJson('/api/kit/signup', $this->form(['accountName' => 'First']))->assertStatus(202);
        $this->postJson('/api/kit/signup', $this->form(['accountName' => 'Second']))->assertStatus(202);
        [$older, $newer] = [$this->token('signup/verify', 0), $this->token('signup/verify', 1)];
        $this->assertSame(['Second'], Signup::query()->pluck('account_name')->all(), 'one sign-up per address');
        $this->getJson("/api/kit/signup/verify/{$older}")->assertNotFound();
        $this->getJson("/api/kit/signup/verify/{$newer}")->assertOk();

        $this->travel(config('kit.signup.verify_hours'))->hours();
        $this->travel(1)->minutes();
        $this->postJson("/api/kit/signup/verify/{$newer}")->assertNotFound();
        $this->artisan('kit:signups')->assertSuccessful();
        $this->assertSame(1, Signup::query()->count(), 'kept for a while after its link expires');
        $this->travel(config('kit.signup.purge_days'))->days();
        $this->artisan('kit:signups')->assertSuccessful();
        $this->assertSame(0, Signup::query()->count());
    }

    public function test_the_settings_are_checked_and_the_page_is_the_staffs_alone(): void
    {
        $save = fn (array $s) => $this->actingAs($this->staff)->putJson('/api/kit/admin/signups/settings', $s);
        $save(['mode' => 'domains', 'domains' => []])->assertStatus(422)->assertJsonPath('errors.domains', [__('signup.needs_domains')]);
        $save(['mode' => 'open', 'fields' => [['key' => 'email', 'label' => 'Email again', 'type' => 'text', 'required' => true, 'options' => []]]])
            ->assertStatus(422)->assertJsonValidationErrors(['fields.0.key']);
        $save(['mode' => 'open', 'fields' => [['key' => 'size', 'label' => 'Size', 'type' => 'select', 'required' => true, 'options' => []]]])
            ->assertStatus(422)->assertJsonPath('errors', ['fields.0.options' => [__('signup.needs_options')]]);
        $save(['mode' => 'open', 'methods' => []])->assertStatus(422)->assertJsonValidationErrors(['methods']);
        $save(['mode' => 'open', 'trialCredit' => '50'])->assertStatus(422)->assertJsonPath('errors.trialCredit', [__('signup.needs_pricing')]);
        $save(['mode' => 'open', 'terms' => ['url' => 'not a url', 'version' => '1']])->assertStatus(422)->assertJsonValidationErrors(['terms.url']);
        $saved = $save(['mode' => 'open', 'methods' => ['password', 'magic_link']])->assertOk();
        $this->assertSame(['open', ['password', 'magic_link'], true], [$saved->json('settings.mode'), $saved->json('settings.methods'), $saved->json('open')]);

        app(Senders::class)->put([]);
        $save(['mode' => 'approval'])->assertStatus(422)->assertJsonPath('errors.mode', [__('signup.needs_email')]);
        $save(['mode' => 'closed'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/kit/admin/signups')->assertUnauthorized();
        $customer = User::create(['name' => 'Cy', 'email' => 'cy@dental.example', 'password' => 'a long password 8']);
        $this->actingAs($customer)->getJson('/api/kit/admin/signups')->assertForbidden();
        $this->actingAs($customer)->putJson('/api/kit/admin/signups/settings', ['mode' => 'open'])->assertForbidden();
    }

    public function test_signing_up_is_slowed(): void
    {
        $this->open();
        $limit = config('kit.signup.per_minute_per_email');
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/kit/signup', $this->form())->assertStatus(202);
        }
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(429);
    }

    public function test_a_raced_address_is_refused_when_the_account_would_be_made(): void
    {
        $this->open();
        $this->postJson('/api/kit/signup', $this->form())->assertStatus(202);
        $token = $this->token('signup/verify');
        User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'anas own password']);
        $this->postJson("/api/kit/signup/verify/{$token}")->assertStatus(409)->assertExactJson(['message' => __('signup.already_registered')]);
        $this->assertSame([0, Signup::REJECTED], [Workspace::query()->count(), Signup::query()->sole()->status]);
        $this->assertSame(0, DB::table('workspace_members')->count());
    }
}
