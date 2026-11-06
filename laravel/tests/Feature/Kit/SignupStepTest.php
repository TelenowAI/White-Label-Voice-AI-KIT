<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The wizard's Sign-ups step (App\Kit\Setup\Steps\SignupStep): who may sign up, closed to start with, and the email
 * domains for that mode (one text field, comma or space separated, lowercased); every mode but closed needs email.
 */
class SignupStepTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(Settings::class);
        $settings->put('setup.database.done_at', now()->toIso8601String());
        $settings->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $settings->put('brand.saved_at', now()->toIso8601String());
        $settings->put(Senders::SKIPPED, true);
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
    }

    private function step(): array
    {
        return collect($this->actingAs($this->owner)->getJson('/api/kit/setup')->assertOk()->json('steps'))->firstWhere('id', 'signup');
    }

    public function test_it_starts_closed_and_needs_email_for_anything_else(): void
    {
        $fields = collect($this->step()['fields'])->keyBy('name');
        $this->assertSame(['closed', config('kit.signup.modes')], [$fields['mode']['value'], array_column($fields['mode']['options'], 'value')]);
        $this->assertSame(['field' => 'mode', 'values' => ['domains']], $fields['domains']['showWhen']);
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/signup', ['mode' => 'open'])->assertStatus(422)
            ->assertJsonPath('errors.mode', [__('signup.needs_email')]);
        $this->assertSame('todo', $this->step()['status']);
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/signup', ['mode' => 'closed'])->assertOk();
        $this->assertSame('done', $this->step()['status']);
    }

    public function test_the_domains_mode_takes_its_domains_from_one_field(): void
    {
        app(Senders::class)->putFrom('hello@voice.example', null);
        app(Senders::class)->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => 25, 'encryption' => 'none']]]);
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/signup', ['mode' => 'domains', 'domains' => 'acme.com,  bad_domain'])->assertStatus(422)
            ->assertJsonValidationErrors(['domains']);
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/signup', ['mode' => 'domains', 'domains' => 'Acme.com, acme.co.in acme.com'])->assertOk();
        $this->assertSame(['domains', ['acme.com', 'acme.co.in']], [app(SignupSettings::class)->current()['mode'], app(SignupSettings::class)->current()['domains']]);
        $this->assertSame('acme.com, acme.co.in', collect($this->step()['fields'])->firstWhere('name', 'domains')['value']);
    }
}
