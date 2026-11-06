<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Kit\Settings\Settings;
use App\Models\OutgoingEmail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SmtpSink;
use Tests\TestCase;

/**
 * How the kit sends email (App\Kit\Email), over real SMTP to a local sink (tests/Support/smtp_sink.php): a sender is
 * saved only after it has sent the staff member a test, its password kept encrypted and never answered; STARTTLS
 * refuses a server that cannot upgrade; emails go through the first sender that takes them, wait and are tried again
 * when none can, and are given up after the last wait, their body (encrypted while it waits) wiped once done; the
 * email is in the brand and escapes what people typed; Admin → Email is the staff's alone.
 */
class EmailTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private User $owner;

    private SmtpSink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@acme.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->sink = SmtpSink::start();
    }

    protected function tearDown(): void
    {
        $this->sink->stop();
        parent::tearDown();
    }

    private function site(): void
    {
        $this->actingAs($this->owner)->putJson('/api/kit/admin/email', ['siteUrl' => self::SITE, 'fromAddress' => 'hello@acme.example', 'fromName' => ''])->assertOk();
    }

    private function smtp(int $port, array $over = []): array
    {
        return array_merge(['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => (string) $port, 'encryption' => 'none', 'username' => 'relay', 'password' => 'relay secret 1'], $over);
    }

    public function test_a_sender_is_saved_only_after_it_sent_the_staff_member_a_test_and_its_password_is_never_answered(): void
    {
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port))->assertStatus(409)
            ->assertExactJson(['message' => __('emails.no_from')]);
        $this->site();

        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp(SmtpSink::closedPort()))->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, __('emails.test_failed', ['reason' => ''])));
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port, ['port' => 'x']))->assertStatus(422)
            ->assertJsonValidationErrors(['port']);
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', ['driver' => 'carrier-pigeon'])->assertStatus(422)->assertJsonValidationErrors(['driver']);
        $this->assertSame([], app(Senders::class)->all(), 'nothing saved');
        $this->assertSame([], $this->sink->messages());

        $made = $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port))->assertCreated();
        [$test] = $this->sink->messages();
        $this->assertSame([['op@acme.example'], 'hello@acme.example', ['username' => 'relay', 'password' => 'relay secret 1']], [$test['to'], $test['from'], $test['auth']]);
        $this->assertSame(__('emails.kinds.test.subject', ['brand' => config('kit.brand.name')]), $test['subject']);
        $this->assertSame([], OutgoingEmail::all()->all(), 'a sender\'s test is not kept');

        $sender = $made->json('senders.0');
        $this->assertSame(['smtp', "127.0.0.1, port {$this->sink->port} (".__('emails.drivers.smtp.encryptions.none').')'], [$sender['driver'], $sender['label']]);
        $password = collect($sender['fields'])->firstWhere('name', 'password');
        $this->assertSame([null, true], [$password['value'], $password['secretSet']]);
        $this->assertStringNotContainsString('relay secret 1', $made->getContent());
        $row = DB::table('settings')->where('key', Senders::LIST)->first();
        $this->assertTrue((bool) $row->is_secret);
        $this->assertStringNotContainsString('relay secret 1', $row->value, 'stored encrypted');
        $this->actingAs($this->owner)->getJson('/api/kit/admin/email')->assertJsonPath('on', true)->assertJsonPath('fromName', config('kit.brand.name'))
            ->assertJsonPath('siteUrl', self::SITE);
    }

    public function test_starttls_refuses_a_server_that_cannot_upgrade_so_no_password_goes_in_the_clear(): void
    {
        $this->site();
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port, ['encryption' => 'tls']))->assertStatus(422);
        $this->assertSame([], $this->sink->messages());
        $this->assertSame([], app(Senders::class)->all());
    }

    public function test_an_edit_keeps_a_password_left_empty_and_senders_are_ordered_capped_and_removed(): void
    {
        $this->site();
        $first = $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port))->json('senders.0.id');
        $this->actingAs($this->owner)->putJson("/api/kit/admin/email/senders/{$first}", $this->smtp($this->sink->port, ['username' => 'relay2', 'password' => '']))->assertOk();
        $this->assertSame(['username' => 'relay2', 'password' => 'relay secret 1'], $this->sink->messages()[1]['auth'], 'the saved password, kept');
        $this->actingAs($this->owner)->putJson('/api/kit/admin/email/senders/no-such', $this->smtp($this->sink->port))->assertNotFound();

        $second = $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port, ['username' => 'backup']))->json('senders.1.id');
        $this->actingAs($this->owner)->putJson('/api/kit/admin/email/order', ['ids' => [$second]])->assertStatus(422)->assertExactJson(['message' => __('emails.order_mismatch')]);
        $this->actingAs($this->owner)->putJson('/api/kit/admin/email/order', ['ids' => [$second, $second]])->assertStatus(422);
        $this->actingAs($this->owner)->putJson('/api/kit/admin/email/order', ['ids' => [$second, $first]])->assertOk()->assertJsonPath('senders.0.id', $second);

        config(['kit.email.max_senders' => 2]);
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port))->assertStatus(409)
            ->assertExactJson(['message' => __('emails.too_many', ['max' => 2])]);
        $this->actingAs($this->owner)->deleteJson("/api/kit/admin/email/senders/{$second}")->assertNoContent();
        $this->assertSame([$first], array_column(app(Senders::class)->all(), 'id'));
        $this->actingAs($this->owner)->deleteJson("/api/kit/admin/email/senders/{$second}")->assertNotFound();
    }

    public function test_an_email_goes_through_the_first_sender_that_takes_it_waits_when_none_can_and_is_given_up_after_the_last_wait(): void
    {
        $this->site();
        $down = ['id' => 'down', 'driver' => 'smtp', 'config' => $this->smtp(SmtpSink::closedPort())];
        $up = ['id' => 'up', 'driver' => 'smtp', 'config' => $this->smtp($this->sink->port)];
        unset($down['config']['driver'], $up['config']['driver']);
        $senders = app(Senders::class);
        $senders->put([$down, $up]);

        $sent = app(Emails::class)->send('password_reset', 'ana@acme.example', ['email' => 'ana@acme.example', 'minutes' => 60], self::SITE.'/reset/secret-token');
        $this->assertSame(['sent', 1, 'up', null], [$sent->status, $sent->attempts, $sent->sender_id, $sent->fresh()->body]);
        $this->assertSame(['ana@acme.example'], $this->sink->messages()[0]['to']);
        $this->assertSame(self::SITE.'/reset/secret-token', SmtpSink::link($this->sink->messages()[0], self::SITE));

        $senders->put([$down]);
        $waiting = app(Emails::class)->send('password_reset', 'ben@acme.example', ['email' => 'ben@acme.example', 'minutes' => 60], self::SITE.'/reset/other-token');
        $waits = config('kit.email.retry_minutes');
        $this->assertSame(['queued', 1], [$waiting->status, $waiting->attempts]);
        $this->assertEquals(now()->addMinutes($waits[0])->toDateTimeString(), $waiting->next_attempt_at->toDateTimeString());
        $this->assertStringStartsWith("127.0.0.1, port {$down['config']['port']}", $waiting->last_error, 'which sender failed, and why');
        $raw = DB::table('emails')->where('id', $waiting->id)->value('body');
        $this->assertStringNotContainsString('other-token', $raw, 'the body is encrypted while it waits');
        $this->assertStringContainsString('other-token', $waiting->fresh()->body['text']);

        $this->artisan('kit:emails')->assertSuccessful();
        $this->assertSame(1, $waiting->fresh()->attempts, 'not before its wait is over');
        foreach ($waits as $i => $wait) {
            $this->travel($wait)->minutes();
            $this->artisan('kit:emails')->assertSuccessful();
            $this->assertSame($i + 2, $waiting->fresh()->attempts);
        }
        $this->assertSame(['failed', null, null], [$waiting->fresh()->status, $waiting->fresh()->body, $waiting->fresh()->next_attempt_at]);

        $senders->put([$up]);
        $late = app(Emails::class)->send('test', 'cy@acme.example');
        $senders->put([$down, $up]);
        $this->travel(1)->hours();
        $this->artisan('kit:emails')->assertSuccessful();
        $this->assertSame('sent', $late->fresh()->status, 'sent at once: nothing to retry');
        $this->assertCount(2, $this->sink->messages());
    }

    public function test_the_test_button_sends_through_the_line_and_the_recent_list_never_shows_a_body(): void
    {
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/test')->assertStatus(409);
        $this->site();
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port))->assertCreated();
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/test')->assertOk()->assertJsonPath('status', 'sent')->assertJsonPath('to', 'op@acme.example');
        $recent = $this->actingAs($this->owner)->getJson('/api/kit/admin/email')->json('recent');
        $this->assertSame([['test', __('emails.kind_names.test'), 'op@acme.example', 'sent', 1]],
            array_map(fn ($e) => [$e['kind'], $e['kindName'], $e['to'], $e['status'], $e['attempts']], $recent));
        $this->assertArrayNotHasKey('body', $recent[0]);
    }

    public function test_an_email_is_in_the_brand_and_escapes_what_people_typed(): void
    {
        $settings = app(Settings::class);
        $settings->put('brand.name', 'Acme Voice');
        $settings->put('brand.support_email', 'help@acme.example');
        $settings->put('brand.colors', ['primary' => '#0a7f5a', 'muted' => 'red;background:url(x)']);
        $this->site();
        app(Senders::class)->put([['id' => 'up', 'driver' => 'smtp', 'config' => array_diff_key($this->smtp($this->sink->port), ['driver' => 1])]]);

        $email = app(Emails::class)->send('team_invite', 'ana@acme.example', ['account' => '<b>Dental & Co</b>', 'expires' => 'soon'], self::SITE.'/console/invite/t');
        $this->assertSame('Join <b>Dental & Co</b> on Acme Voice', $email->subject);
        [$m] = $this->sink->messages();
        $this->assertStringContainsString('You are invited to join &lt;b&gt;Dental &amp; Co&lt;/b&gt; on Acme Voice.</p>', $m['text'], 'escaped in the HTML body');
        $this->assertStringNotContainsString('join <b>Dental & Co</b> on Acme Voice.</p>', $m['text']);
        $this->assertStringContainsString('background:#0a7f5a', $m['text'], 'the brand\'s colour');
        $this->assertStringNotContainsString('url(x)', $m['text'], 'a value that is not a colour falls back');
        $this->assertStringContainsString(config('kit.brand.colors.muted'), $m['text']);
        $this->assertStringContainsString(__('emails.support', ['email' => 'help@acme.example']), $m['text']);
        $this->assertSame('Join <b>Dental & Co</b> on Acme Voice', $m['subject']);
    }

    public function test_admin_email_is_the_staffs_alone(): void
    {
        $this->getJson('/api/kit/admin/email')->assertUnauthorized();
        $customer = User::create(['name' => 'Ana', 'email' => 'ana@acme.example', 'password' => 'a long password 9']);
        $w = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $w->id, 'user_id' => $customer->id, 'role' => 'owner']);
        $this->actingAs($customer)->getJson('/api/kit/admin/email')->assertForbidden();
        $this->actingAs($customer)->postJson('/api/kit/admin/email/senders', $this->smtp($this->sink->port))->assertForbidden();
    }
}
