<?php

namespace Tests\Feature\Kit;

use App\Http\Middleware\PrepareInstallation;
use App\Providers\AppServiceProvider;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PrepareInstallation: a fresh release (no APP_KEY, no tables) can still open the wizard — the key is made and
 * saved before the cookie middleware needs it, and a database session store waits for its table — and once the
 * install is finished it does nothing.
 */
class PrepareInstallationTest extends TestCase
{
    public function test_with_no_app_key_one_is_made_saved_and_used_for_the_cookies(): void
    {
        config(['app.key' => '']);

        $response = $this->getJson('/api/kit/setup')->assertOk();

        $this->assertMatchesRegularExpression('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=\n$/', (string) @file_get_contents(config('kit.setup.env_file')));
        $this->assertStringStartsWith('base64:', config('app.key'));
        $this->assertNotNull($response->getCookie('XSRF-TOKEN', decrypt: false));
    }

    public function test_a_database_session_store_waits_for_its_table(): void
    {
        if (Schema::hasTable('sessions')) {
            $this->markTestSkipped('Needs a database with no tables: on a shared test database, run this test first.');
        }
        config(['session.driver' => 'database', 'cache.default' => 'database']);

        $this->getJson('/api/kit/session')->assertOk()->assertExactJson(['user' => null, 'viewingAs' => null]);
        $this->assertSame('file', config('session.driver'));
        $this->assertSame('file', config('cache.default'));
    }

    public function test_signing_in_before_there_is_a_database_is_refused_not_an_error(): void
    {
        // As on a fresh release: the cache (and so the sign-in limiter, made at boot) is on the database.
        config(['cache.default' => 'database', 'session.driver' => 'database']);
        $this->app->forgetInstance(RateLimiter::class);
        Facade::clearResolvedInstance(RateLimiter::class);
        (new AppServiceProvider($this->app))->boot();

        $this->postJson('/api/kit/session', ['email' => 'op@example.com', 'password' => 'whatever'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_once_installed_it_changes_nothing(): void
    {
        touch(config('kit.installed_marker'));
        config(['app.key' => '', 'session.driver' => 'database']);

        app(PrepareInstallation::class)->handle(Request::create('/api/kit/session'), fn () => new Response);

        $this->assertSame('', config('app.key'));
        $this->assertSame('database', config('session.driver'));
        $this->assertFileDoesNotExist(config('kit.setup.env_file'));
    }
}
