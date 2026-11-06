<?php

namespace Tests\Feature\Kit;

use App\Kit\Auth\PasswordResets;
use App\Kit\Email\Senders;
use App\Models\PasswordReset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * "Forgot your password?" (PasswordController, App\Kit\Auth\PasswordResets) over real SMTP to a local sink: asking
 * answers the same for an address with an account and one without, and only the account gets a link — built from the
 * site's address even when the request names another Host; the link works once, until it expires, and a newer one
 * replaces it; the new password signs the person in and ends their other sessions; no email, no reset; asking is
 * slowed.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private User $ana;

    private SmtpSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@acme.example', 'password' => 'the old password 1']);
        $this->sink = SmtpSink::start();
        $senders = app(Senders::class);
        $senders->putSiteUrl(self::SITE);
        $senders->putFrom('hello@acme.example', null);
        $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $this->sink->port, 'encryption' => 'none']]]);
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private function token(int $message = 0): string
    {
        $link = SmtpSink::link($this->sink->messages()[$message], self::SITE);
        $this->assertNotNull($link);

        return substr($link, strlen(self::SITE.'/reset/'));
    }

    public function test_asking_answers_the_same_with_or_without_an_account_and_the_link_is_the_sites_whatever_the_host(): void
    {
        $known = $this->postJson('http://evil.example/api/kit/password/forgot', ['email' => 'ANA@acme.example'])->assertStatus(202);
        $unknown = $this->postJson('/api/kit/password/forgot', ['email' => 'nobody@acme.example'])->assertStatus(202);
        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame(['message' => __('session.reset_sent')], $known->json());

        [$m] = $this->sink->messages();
        $this->assertCount(1, $this->sink->messages(), 'only the account gets an email');
        $this->assertSame(['ana@acme.example'], $m['to']);
        $this->assertStringNotContainsString('evil.example', $m['text']);
        $token = $this->token();
        $this->assertSame([hash('sha256', $token)], PasswordReset::query()->pluck('token_hash')->all(), 'only its hash is kept');
        $this->getJson("/api/kit/password/reset/{$token}")->assertOk()->assertJsonPath('email', 'ana@acme.example');
        $this->postJson('/api/kit/password/forgot', ['email' => 'not an email'])->assertStatus(422);

        // The link is made after the answer, so a fault there never shows in it: an address nobody uses is a no-op.
        app(PasswordResets::class)->request('nobody@acme.example');
        $this->assertCount(1, $this->sink->messages());
        $this->assertSame(1, PasswordReset::query()->count());
    }

    public function test_the_new_password_signs_in_ends_other_sessions_and_the_link_works_once(): void
    {
        $this->postJson('/api/kit/password/forgot', ['email' => 'ana@acme.example'])->assertStatus(202);
        $token = $this->token();
        config(['session.driver' => 'database']); // as a release runs (phpunit.xml uses the array driver)
        DB::table('sessions')->insert(['id' => 'elsewhere', 'user_id' => $this->ana->id, 'ip_address' => '10.0.0.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => time()]);

        $this->postJson("/api/kit/password/reset/{$token}", ['password' => 'short'])->assertStatus(422)->assertJsonValidationErrors(['password']);
        $this->postJson("/api/kit/password/reset/{$token}", ['password' => 'a brand new password 2'])->assertOk()
            ->assertJsonPath('user.email', 'ana@acme.example');
        $this->assertSame($this->ana->id, Auth::id(), 'signed in');
        $this->assertTrue(Hash::check('a brand new password 2', $this->ana->fresh()->password));
        $this->assertSame(0, DB::table('sessions')->where('id', 'elsewhere')->count(), 'other sessions ended');

        $this->postJson("/api/kit/password/reset/{$token}", ['password' => 'yet another password 3'])->assertNotFound()->assertExactJson(['message' => __('session.reset_gone')]);
        $this->getJson("/api/kit/password/reset/{$token}")->assertNotFound();
        $this->getJson('/api/kit/password/reset/no-such-token')->assertNotFound();
    }

    public function test_a_newer_link_replaces_the_older_and_a_link_expires(): void
    {
        $this->postJson('/api/kit/password/forgot', ['email' => 'ana@acme.example']);
        $this->postJson('/api/kit/password/forgot', ['email' => 'ana@acme.example']);
        [$older, $newer] = [$this->token(0), $this->token(1)];
        $this->getJson("/api/kit/password/reset/{$older}")->assertNotFound();
        $this->getJson("/api/kit/password/reset/{$newer}")->assertOk();

        $this->travel(config('kit.auth.reset_minutes') + 1)->minutes();
        $this->getJson("/api/kit/password/reset/{$newer}")->assertNotFound();
        $this->postJson("/api/kit/password/reset/{$newer}", ['password' => 'a brand new password 2'])->assertNotFound();
        $this->assertTrue(Hash::check('the old password 1', $this->ana->fresh()->password));
    }

    public function test_no_email_no_reset_and_the_brand_says_so(): void
    {
        $this->getJson('/api/kit/brand')->assertJsonPath('passwordReset', true);
        app(Senders::class)->put([]);
        $this->getJson('/api/kit/brand')->assertJsonPath('passwordReset', false);
        $this->postJson('/api/kit/password/forgot', ['email' => 'ana@acme.example'])->assertStatus(409)
            ->assertExactJson(['message' => __('session.reset_unavailable')]);
        $this->assertSame(0, PasswordReset::query()->count());
    }

    public function test_asking_is_slowed(): void
    {
        $limit = config('kit.auth.reset_requests_per_minute');
        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/kit/password/forgot', ['email' => 'ana@acme.example'])->assertStatus(202);
        }
        $this->postJson('/api/kit/password/forgot', ['email' => 'ana@acme.example'])->assertStatus(429)->assertExactJson(['message' => __('session.throttled')]);
        $this->assertCount($limit, $this->sink->messages());
    }
}
