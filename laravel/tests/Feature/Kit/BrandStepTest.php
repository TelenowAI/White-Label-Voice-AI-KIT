<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The wizard's brand step: what it saves is what GET /api/kit/brand answers, every colour is a hex value (nothing
 * else reaches the console's CSS), and only the brand's own colour roles are taken.
 */
class BrandStepTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(Settings::class);
        $settings->put('setup.database.done_at', now()->toIso8601String());
        $settings->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
    }

    private function form(array $over = []): array
    {
        $form = ['name' => 'Acme Voice', 'logoUrl' => 'https://acme.example/logo.svg', 'supportEmail' => 'help@acme.example'];
        foreach (config('kit.brand.colors') as $role => $value) {
            $form["colors.{$role}"] = $value;
        }

        return array_merge($form, $over);
    }

    public function test_the_saved_brand_is_what_the_brand_route_answers(): void
    {
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/brand', $this->form(['colors.primary' => '#0A0B0C']))->assertOk();

        $this->getJson('/api/kit/brand')->assertExactJson([
            'name' => 'Acme Voice',
            'logoUrl' => 'https://acme.example/logo.svg',
            'supportEmail' => 'help@acme.example',
            'colors' => array_merge(config('kit.brand.colors'), ['primary' => '#0A0B0C']),
            'passwordReset' => false,
            'signUp' => false,
            'signInPassword' => true,
            'signInLink' => false,
            'signInEmailCode' => false,
            'signInCode' => false,
            'signInCodeChannel' => null,
            'signInWith' => [],
            'captcha' => null,
        ]);
    }

    public function test_a_colour_that_is_not_a_hex_value_is_refused(): void
    {
        foreach (['red', 'url(https://x)', '#12345', 'rgb(1,2,3)'] as $bad) {
            $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/brand', $this->form(['colors.primary' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors(['colors.primary']);
        }
    }

    public function test_a_colour_role_the_brand_does_not_have_is_refused(): void
    {
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/brand', $this->form(['colors.surprise' => '#000000']))
            ->assertStatus(422)->assertJsonValidationErrors(['colors']);
    }

    public function test_a_logo_address_must_be_http(): void
    {
        // ftp:// and file:// are addresses Laravel's plain `url` rule accepts; the logo must be http(s).
        foreach (['javascript:alert(1)', 'ftp://acme.example/logo.svg', 'file:///etc/passwd'] as $bad) {
            $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/brand', $this->form(['logoUrl' => $bad]))
                ->assertStatus(422)->assertJsonValidationErrors(['logoUrl']);
        }
    }

    public function test_empty_optional_fields_are_saved_as_none(): void
    {
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/brand', $this->form(['logoUrl' => '', 'supportEmail' => '']))->assertOk();

        $this->getJson('/api/kit/brand')->assertJsonPath('logoUrl', null)->assertJsonPath('supportEmail', null);
    }
}
