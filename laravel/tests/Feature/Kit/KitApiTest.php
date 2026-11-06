<?php

namespace Tests\Feature\Kit;

use Tests\TestCase;

/**
 * The kit API's first two routes (contract/kit-api.yaml): health and brand. The cross-stack check — the same
 * document held to a running backend — is contract/tests; these pin the Laravel behaviour behind it.
 */
class KitApiTest extends TestCase
{
    public function test_health_answers_up_with_the_version_and_not_installed_without_the_marker(): void
    {
        config(['kit.installed_marker' => sys_get_temp_dir().'/voice-kit-no-such-marker-'.uniqid()]);

        $this->getJson('/api/kit/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok', 'installed' => false, 'version' => config('kit.version')]);
    }

    public function test_health_says_installed_once_the_wizard_has_written_its_marker(): void
    {
        $marker = tempnam(sys_get_temp_dir(), 'voice-kit-installed-');
        config(['kit.installed_marker' => $marker]);

        try {
            $this->getJson('/api/kit/health')->assertOk()->assertJsonPath('installed', true);
        } finally {
            @unlink($marker);
        }
    }

    public function test_the_version_is_the_version_file(): void
    {
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), config('kit.version'));
        $this->assertNotSame('', config('kit.version'));
    }

    public function test_brand_answers_the_configured_brand_with_colors_as_an_object(): void
    {
        config(['kit.brand' => [
            'name' => 'Acme Voice',
            'logo_url' => null,
            'colors' => ['primary' => '#123456', 'accent' => '#abcdef'],
        ]]);

        $this->getJson('/api/kit/brand')
            ->assertOk()
            ->assertExactJson([
                'name' => 'Acme Voice',
                'logoUrl' => null,
                'supportEmail' => null,
                'colors' => ['primary' => '#123456', 'accent' => '#abcdef'],
                'passwordReset' => false, // no way to send email yet
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

    public function test_brand_colors_stay_an_object_when_there_are_none(): void
    {
        config(['kit.brand.colors' => []]);

        $body = $this->getJson('/api/kit/brand')->assertOk()->getContent();

        // An empty PHP array would serialize as [] and break the console's `colors` map.
        $this->assertStringContainsString('"colors":{}', $body);
    }
}
