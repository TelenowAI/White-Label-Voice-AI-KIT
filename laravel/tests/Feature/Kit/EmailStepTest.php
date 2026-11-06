<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * The wizard's Email step (App\Kit\Setup\Steps\EmailStep) over real SMTP to a local sink: its form draws each
 * driver's fields under `<driver>.` (shown while that driver is chosen) with "not now" beside them, and starts from the
 * address the wizard is open on; a sender is saved only after it sent the owner a test, its refusals shown on its own
 * fields or on the driver; "not now" finishes the step with no email.
 */
class EmailStepTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private SmtpSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $settings = app(Settings::class);
        $settings->put('setup.database.done_at', now()->toIso8601String());
        $settings->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $settings->put('brand.saved_at', now()->toIso8601String());
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@acme.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->sink = SmtpSink::start();
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private function step(string $host = 'localhost'): array
    {
        return collect($this->actingAs($this->owner)->getJson("http://{$host}/api/kit/setup")->assertOk()->json('steps'))->firstWhere('id', 'email');
    }

    private function form(array $over = []): array
    {
        return array_merge(['siteUrl' => 'https://voice.acme.example', 'driver' => 'smtp', 'fromAddress' => 'hello@acme.example', 'fromName' => 'Acme',
            'smtp.host' => '127.0.0.1', 'smtp.port' => (string) $this->sink->port, 'smtp.encryption' => 'none', 'smtp.username' => '', 'smtp.password' => ''], $over);
    }

    public function test_its_form_draws_each_drivers_fields_and_starts_from_the_wizards_own_address(): void
    {
        $step = $this->step('setup.acme.example');
        $this->assertSame('todo', $step['status']);
        $fields = collect($step['fields'])->keyBy('name');
        $this->assertSame('http://setup.acme.example', $fields['siteUrl']['value'], 'a suggestion for the owner to confirm, never used unconfirmed');
        $this->assertSame([...array_keys(config('kit.email.drivers')), 'none'], array_column($fields['driver']['options'], 'value'));
        $this->assertSame(['field' => 'driver', 'values' => ['smtp']], $fields['smtp.host']['showWhen']);
        $this->assertSame(['smtp.host', 'smtp.port', 'smtp.encryption', 'smtp.username', 'smtp.password'],
            array_values(array_filter($fields->keys()->all(), fn ($n) => str_starts_with($n, 'smtp.'))));
        $this->assertSame((string) config('kit.email.smtp.default_port'), $fields['smtp.port']['value']);
    }

    public function test_a_sender_is_saved_only_after_it_sent_the_owner_a_test(): void
    {
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/email', $this->form(['smtp.port' => 'x']))->assertStatus(422)
            ->assertJsonValidationErrors(['smtp.port']);
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/email', $this->form(['smtp.port' => (string) SmtpSink::closedPort()]))->assertStatus(422)
            ->assertJsonValidationErrors(['driver']);
        $this->assertSame([], app(Senders::class)->all());

        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/email', $this->form(['smtp.username' => 'relay', 'smtp.password' => 'relay secret 1']))->assertOk();
        [$m] = $this->sink->messages();
        $this->assertSame([['op@acme.example'], 'hello@acme.example', 'relay secret 1'], [$m['to'], $m['from'], $m['auth']['password']]);
        $this->assertSame('done', $this->step()['status']);
        $fields = collect($this->step()['fields'])->keyBy('name');
        $this->assertSame(['https://voice.acme.example', 'smtp', 'Acme', null, true],
            [$fields['siteUrl']['value'], $fields['driver']['value'], $fields['fromName']['value'], $fields['smtp.password']['value'], $fields['smtp.password']['secretSet']]);

        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/email', $this->form(['smtp.username' => 'relay']))->assertOk();
        $this->assertSame('relay secret 1', $this->sink->messages()[1]['auth']['password'], 'saved again: the password left empty is kept');
        $this->assertCount(1, app(Senders::class)->all());
    }

    public function test_not_now_finishes_the_step_with_no_email(): void
    {
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/email', $this->form())->assertOk();
        $this->assertTrue(app(Senders::class)->on());
        $this->sink->stop();
        $this->sink = SmtpSink::start();

        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/email', ['siteUrl' => 'not a url', 'driver' => 'none'])->assertStatus(422)
            ->assertJsonValidationErrors(['siteUrl']);
        $this->actingAs($this->owner)->postJson('/api/kit/setup/steps/email', ['siteUrl' => 'https://voice.acme.example', 'driver' => 'none'])->assertOk();
        $step = $this->step();
        $this->assertSame(['done', 'none'], [$step['status'], collect($step['fields'])->firstWhere('name', 'driver')['value']]);
        $this->assertFalse(app(Senders::class)->on());
        $this->assertSame('https://voice.acme.example/reset/x', app(Senders::class)->link('reset/x'));
        $this->assertSame([], $this->sink->messages());
    }
}
