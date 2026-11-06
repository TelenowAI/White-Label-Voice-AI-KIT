<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Signup\SignupSettings;
use App\Models\SignInLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * Signing in with an emailed link (App\Kit\Signup\SignInLinks) over real SMTP to a local sink: only when the operator
 * has that way in on (the brand says so); asking answers the same for a customer, a staff member and nobody, and only
 * the customer gets a link — staff always use their password; the link signs in once, until it expires, and a newer one
 * replaces it.
 */
class SignInLinkTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private User $ana;

    private SmtpSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->sink = SmtpSink::start();
        $senders = app(Senders::class);
        $senders->putSiteUrl(self::SITE);
        $senders->putFrom('hello@voice.example', null);
        $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $this->sink->port, 'encryption' => 'none']]]);
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private function token(int $message): string
    {
        $link = SmtpSink::link($this->sink->messages()[$message], self::SITE.'/signin');
        $this->assertNotNull($link);

        return substr($link, strlen(self::SITE.'/signin/'));
    }

    public function test_only_when_the_operator_has_it_on(): void
    {
        $this->getJson('/api/kit/brand')->assertJsonPath('signInLink', false);
        $this->postJson('/api/kit/session/link', ['email' => 'ana@dental.example'])->assertStatus(409)->assertExactJson(['message' => __('signup.link_unavailable')]);
        app(SignupSettings::class)->save(['methods' => ['password', 'magic_link']]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInLink', true);
        app(Senders::class)->put([]);
        $this->getJson('/api/kit/brand')->assertJsonPath('signInLink', false);
        $this->postJson('/api/kit/session/link', ['email' => 'ana@dental.example'])->assertStatus(409);
        $this->assertSame([], $this->sink->messages());
    }

    public function test_a_customer_gets_a_link_that_signs_in_once_and_staff_never_do(): void
    {
        app(SignupSettings::class)->save(['methods' => ['password', 'magic_link']]);
        $answers = array_map(fn (string $e) => $this->postJson('/api/kit/session/link', ['email' => $e])->assertStatus(202)->getContent(),
            ['ANA@dental.example', 'op@voice.example', 'nobody@dental.example']);
        $this->assertCount(1, array_unique($answers), 'the same answer for all three');
        [$m] = $this->sink->messages();
        $this->assertCount(1, $this->sink->messages(), 'only the customer');
        $this->assertSame(['ana@dental.example'], $m['to']);
        $token = $this->token(0);
        $this->assertSame([hash('sha256', $token)], SignInLink::query()->pluck('token_hash')->all());

        $this->postJson("/api/kit/session/link/{$token}")->assertOk()->assertJsonPath('user.email', 'ana@dental.example');
        $this->assertSame($this->ana->id, Auth::id());
        $this->postJson("/api/kit/session/link/{$token}")->assertNotFound()->assertExactJson(['message' => __('signup.link_gone')]);
    }

    public function test_a_newer_link_replaces_the_older_and_a_link_expires(): void
    {
        app(SignupSettings::class)->save(['methods' => ['magic_link']]);
        $this->postJson('/api/kit/session/link', ['email' => 'ana@dental.example'])->assertStatus(202);
        $this->postJson('/api/kit/session/link', ['email' => 'ana@dental.example'])->assertStatus(202);
        [$older, $newer] = [$this->token(0), $this->token(1)];
        $this->postJson("/api/kit/session/link/{$older}")->assertNotFound();
        $this->travel(config('kit.signup.link_minutes') + 1)->minutes();
        $this->postJson("/api/kit/session/link/{$newer}")->assertNotFound();
        $this->assertNull(Auth::id());
    }
}
