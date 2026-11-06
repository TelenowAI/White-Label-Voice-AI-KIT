<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * The setup wizard's rules (App\Kit\Setup\SetupWizard) and its whole run: steps in order, the pre-owner steps
 * closing once there is an owner, owner steps for the signed-in owner only, and the lock after `finish` — the
 * wizard's routes answer 404 from then on and the kit says it is installed.
 */
class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::create(['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
    }

    private function databaseDone(): void
    {
        app(Settings::class)->put('setup.database.done_at', now()->toIso8601String());
    }

    private function statuses(): array
    {
        return array_column($this->getJson('/api/kit/setup')->assertOk()->json('steps'), 'status', 'id');
    }

    public function test_a_fresh_install_lists_the_steps_in_order_with_nothing_done(): void
    {
        $state = $this->getJson('/api/kit/setup')->assertOk()->json();

        $this->assertSame(['environment', 'database', 'admin', 'telenow', 'brand', 'email', 'signup', 'payments', 'finish'], array_column($state['steps'], 'id'));
        $this->assertSame(
            ['environment' => 'done', 'database' => 'todo', 'admin' => 'todo', 'telenow' => 'todo', 'brand' => 'todo', 'email' => 'todo', 'signup' => 'todo', 'payments' => 'todo', 'finish' => 'todo'],
            array_column($state['steps'], 'status', 'id'),
        );
        $this->assertFalse($state['installed']);
        $this->assertFalse($state['ownerExists']);
    }

    public function test_an_environment_file_it_cannot_write_blocks_the_wizard(): void
    {
        config(['kit.setup.env_file' => $this->scratch.'/no-such-folder/.env']);

        $this->assertSame('blocked', $this->statuses()['environment']);
        $this->postJson('/api/kit/setup/steps/database', ['connection' => 'sqlite', 'database' => 'x.sqlite'])
            ->assertStatus(409)->assertJsonPath('message', 'Finish "Server check" first.');
    }

    public function test_the_server_check_says_which_folder_the_site_is_served_from(): void
    {
        $check = fn (array $server) => collect($this->withServerVariables($server)->getJson('/api/kit/setup')->json('steps.0.checks'))->firstWhere('id', 'site_folder');
        $this->assertNull($check([]), 'served some other way: nothing to say');
        $this->assertNull($check(['SCRIPT_FILENAME' => base_path('server.php'), 'SCRIPT_NAME' => '/public/index.php', 'SERVER_SOFTWARE' => 'nginx/1.27.1']));
        $public = ['SCRIPT_FILENAME' => public_path('index.php'), 'SCRIPT_NAME' => '/index.php'];
        $root = ['SCRIPT_FILENAME' => public_path('index.php'), 'SCRIPT_NAME' => '/public/index.php'];
        $this->assertSame(__('setup.steps.environment.site_folder_public'), $check($public + ['SERVER_SOFTWARE' => 'nginx/1.27.1'])['label']);
        $this->assertSame(['label' => __('setup.steps.environment.site_folder_root'), 'level' => 'ok'], array_intersect_key(
            $check($root + ['SERVER_SOFTWARE' => 'Apache/2.4.62 (Unix)']), ['label' => 1, 'level' => 1]));
        $this->assertSame('ok', $check($root + ['SERVER_SOFTWARE' => 'LiteSpeed'])['level']);
        $unsafe = $check($root + ['SERVER_SOFTWARE' => 'nginx/1.27.1']);
        $this->assertSame('blocking', $unsafe['level']);
        $this->assertSame(__('setup.steps.environment.site_folder_root_unsafe_detail'), $unsafe['detail']);
        $this->withServerVariables($root + ['SERVER_SOFTWARE' => 'nginx/1.27.1']);
        $this->assertSame('blocked', collect($this->getJson('/api/kit/setup')->json('steps'))->firstWhere('id', 'environment')['status']);
    }

    public function test_a_step_it_does_not_have_or_that_only_reports_answers_404(): void
    {
        $this->postJson('/api/kit/setup/steps/no-such-step', [])->assertNotFound()->assertJsonStructure(['message']);
        $this->postJson('/api/kit/setup/steps/environment', [])->assertNotFound();
    }

    public function test_a_step_is_taken_only_after_every_step_before_it(): void
    {
        $this->postJson('/api/kit/setup/steps/finish', [])->assertStatus(409)->assertJsonPath('message', 'Finish "Database" first.');
        $this->databaseDone();
        $this->postJson('/api/kit/setup/steps/telenow', ['apiKey' => 'vai_test_operator_admin'])
            ->assertStatus(409)->assertJsonPath('message', 'Finish "Your account" first.');
    }

    public function test_owner_steps_are_the_signed_in_owners_alone(): void
    {
        $this->databaseDone();
        $this->owner();
        $this->postJson('/api/kit/setup/steps/telenow', [])->assertUnauthorized()->assertJsonPath('message', 'Sign in as the owner to continue.');

        $someone = User::create(['name' => 'S', 'email' => 's@example.com', 'password' => 'a long password 2']);
        $this->actingAs($someone)->postJson('/api/kit/setup/steps/telenow', [])->assertForbidden();
    }

    public function test_the_admin_step_makes_one_owner_signs_them_in_and_then_closes(): void
    {
        $this->databaseDone();
        $this->postJson('/api/kit/setup/steps/admin', ['name' => 'Op', 'email' => 'op@example.com', 'password' => 'short'])
            ->assertStatus(422)->assertJsonValidationErrors(['password']);

        $state = $this->postJson('/api/kit/setup/steps/admin', ['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1'])
            ->assertOk()->json();

        $this->assertTrue($state['ownerExists']);
        $this->assertSame(User::ROLE_OWNER, User::where('email', 'op@example.com')->value('role'));
        $this->assertAuthenticatedAs(User::where('email', 'op@example.com')->first());
        $this->postJson('/api/kit/setup/steps/admin', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'a long password 3'])
            ->assertStatus(409);
        $this->postJson('/api/kit/setup/steps/database', ['connection' => 'sqlite', 'database' => 'x.sqlite'])->assertStatus(409);
        $this->assertSame(1, User::count());
    }

    public function test_an_admin_claim_already_taken_makes_no_second_owner(): void
    {
        $this->databaseDone();
        app(Settings::class)->claim('setup.admin.claimed', 'first@example.com');

        $this->postJson('/api/kit/setup/steps/admin', ['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1'])
            ->assertStatus(409);
        $this->assertSame(0, User::count());
    }

    public function test_what_an_owner_step_saved_is_shown_to_the_owner_only(): void
    {
        RecordedTelenow::fake();
        $this->databaseDone();
        $owner = $this->owner();
        $this->actingAs($owner)->postJson('/api/kit/setup/steps/telenow', ['apiKey' => 'vai_test_operator_admin'])->assertOk();

        $asOwner = collect($this->actingAs($owner)->getJson('/api/kit/setup')->json('steps'))->firstWhere('id', 'telenow');
        $this->assertNotEmpty($asOwner['facts']);
        $this->assertTrue($asOwner['fields'][0]['secretSet']);

        $this->app['auth']->forgetGuards();
        $asVisitor = collect($this->getJson('/api/kit/setup')->json('steps'))->firstWhere('id', 'telenow');
        $this->assertSame([], $asVisitor['facts']);
        $this->assertSame([], $asVisitor['checks']);
        $this->assertFalse($asVisitor['fields'][0]['secretSet']);
    }

    public function test_the_whole_run_ends_installed_and_locked(): void
    {
        RecordedTelenow::fake();
        $this->databaseDone();
        $this->postJson('/api/kit/setup/steps/admin', ['name' => 'Op', 'email' => 'op@example.com', 'password' => 'a long password 1'])->assertOk();
        $this->postJson('/api/kit/setup/steps/telenow', ['apiKey' => 'vai_test_operator_admin'])->assertOk();
        $this->postJson('/api/kit/setup/steps/brand', ['name' => 'Acme Voice', 'colors.primary' => '#123456'] + $this->defaultColors())->assertOk();
        $this->postJson('/api/kit/setup/steps/finish', [])->assertStatus(409); // email first
        $this->postJson('/api/kit/setup/steps/email', ['siteUrl' => 'https://voice.acme.example', 'driver' => 'none'])->assertOk();
        $this->postJson('/api/kit/setup/steps/signup', ['mode' => 'open'])->assertStatus(422)->assertJsonValidationErrors(['mode']); // no email
        $this->postJson('/api/kit/setup/steps/signup', ['mode' => 'closed'])->assertOk();
        $this->postJson('/api/kit/setup/steps/payments', ['driver' => 'none'])->assertOk();

        $this->assertSame(
            ['environment' => 'done', 'database' => 'done', 'admin' => 'done', 'telenow' => 'done', 'brand' => 'done', 'email' => 'done', 'signup' => 'done', 'payments' => 'done', 'finish' => 'todo'],
            $this->statuses(),
        );
        $this->getJson('/api/kit/health')->assertJsonPath('installed', false);

        $this->postJson('/api/kit/setup/steps/finish', [])->assertOk()->assertJsonPath('installed', true);

        $this->assertFileExists(config('kit.installed_marker'));
        $this->getJson('/api/kit/health')->assertJsonPath('installed', true);
        $this->getJson('/api/kit/setup')->assertNotFound()->assertJsonPath('message', 'The platform is already set up.');
        $this->postJson('/api/kit/setup/steps/brand', ['name' => 'Taken over'])->assertNotFound();
        $this->getJson('/api/kit/brand')->assertJsonPath('name', 'Acme Voice')->assertJsonPath('colors.primary', '#123456');
    }

    /** The brand step's colour fields with their default values, as the console would send them. */
    private function defaultColors(): array
    {
        $out = [];
        foreach (config('kit.brand.colors') as $role => $value) {
            $out["colors.{$role}"] = $value;
        }

        return $out;
    }
}
