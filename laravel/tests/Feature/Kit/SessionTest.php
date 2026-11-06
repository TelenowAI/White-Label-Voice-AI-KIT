<?php

namespace Tests\Feature\Kit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Signing in and out (contract/kit-api.yaml getSession, signIn, signOut): a wrong email or password is one refusal
 * that does not say which, and repeated tries are slowed with the kit API's error shape.
 */
class SessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
    }

    public function test_sign_in_read_the_session_and_sign_out(): void
    {
        $this->getJson('/api/kit/session')->assertExactJson(['user' => null, 'viewingAs' => null]);

        $this->postJson('/api/kit/session', ['email' => 'op@example.com', 'password' => 'a long password 1'])
            ->assertOk()->assertJsonPath('user.email', 'op@example.com')->assertJsonPath('user.role', 'owner');
        $this->getJson('/api/kit/session')->assertJsonPath('user.email', 'op@example.com');

        $this->deleteJson('/api/kit/session')->assertNoContent();
        $this->assertGuest();
    }

    public function test_a_wrong_password_and_an_unknown_email_get_the_same_refusal(): void
    {
        $wrong = $this->postJson('/api/kit/session', ['email' => 'op@example.com', 'password' => 'not it'])->assertStatus(422)->json('errors');
        $unknown = $this->postJson('/api/kit/session', ['email' => 'who@example.com', 'password' => 'not it'])->assertStatus(422)->json('errors');

        $this->assertSame(['email' => ['These details do not match an account.']], $wrong);
        $this->assertSame($wrong, $unknown);
        $this->assertGuest();
    }

    public function test_repeated_tries_are_slowed(): void
    {
        config(['kit.auth.sign_in_per_minute' => 2]);
        $try = fn () => $this->postJson('/api/kit/session', ['email' => 'op@example.com', 'password' => 'not it']);

        $try()->assertStatus(422);
        $try()->assertStatus(422);
        $try()->assertStatus(429)->assertExactJson(['message' => 'Too many tries. Wait a minute and try again.']);
    }
}
