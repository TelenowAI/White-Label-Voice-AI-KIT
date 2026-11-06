<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\EmailTemplate;
use App\Kit\Email\Emails;
use App\Kit\Email\Senders;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin → Email → Templates (App\Kit\Email\EmailTexts, EmailTemplatesController): staff see every email with the words
 * it is sent with, the kit's own and the placeholders it can use; their own words are what the email is sent with,
 * placeholders filled (`:Name` capitalised) and what they typed escaped; going back gives the kit's words; words the
 * email could not send as meant (a placeholder it cannot fill, no subject, a button with no words or one it does not
 * have) are refused; the preview is the email in the brand, placeholders by name, nothing saved; saving and going back
 * are in the audit log; only staff who may change settings use it.
 */
class EmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->owner = User::create(['name' => 'Op', 'email' => 'op@acme.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        app(Senders::class)->putSiteUrl('https://voice.acme.example');
    }

    private function words(array $over = []): array
    {
        return array_merge([
            'subject' => 'Thanks :Name, :amount is in',
            'lines' => ['We got <b>:amount</b> for :account.', 'Happy calling!'],
            'action' => 'See it',
            'after' => [],
        ], $over);
    }

    public function test_staff_see_every_email_with_its_words_its_own_words_and_what_it_can_fill(): void
    {
        $list = $this->actingAs($this->owner)->getJson('/api/kit/admin/email/templates')->assertOk()->json('templates');

        $this->assertSame(array_keys(__('emails.kinds')), array_column($list, 'kind'));
        $paid = collect($list)->firstWhere('kind', 'payment_received');
        $this->assertSame(['brand', 'amount', 'name', 'account'], array_column($paid['placeholders'], 'name'), "brand, then in the order the kit's words use them");
        $this->assertSame(__('emails.placeholders.amount'), $paid['placeholders'][1]['about']);
        $this->assertSame([__('emails.kind_names.payment_received'), true, false], [$paid['name'], $paid['hasButton'], $paid['edited']]);
        $this->assertSame($paid['defaults'], $paid['texts']);
        $this->assertSame(__('emails.kinds.payment_received.subject'), $paid['texts']['subject']);
        $this->assertSame([false, null], [collect($list)->firstWhere('kind', 'test')['hasButton'], collect($list)->firstWhere('kind', 'test')['texts']['action']]);
    }

    public function test_the_operators_words_are_what_the_email_is_sent_with_until_they_go_back_to_the_kits(): void
    {
        $saved = $this->actingAs($this->owner)->putJson('/api/kit/admin/email/templates/payment_received', $this->words(['lines' => ['  We got <b>:amount</b> for :account.  ', 'Happy calling!']]))
            ->assertOk()->json();
        $this->assertTrue($saved['edited']);
        $this->assertSame('We got <b>:amount</b> for :account.', $saved['texts']['lines'][0], 'trimmed');
        $this->assertSame(__('emails.kinds.payment_received.subject'), $saved['defaults']['subject']);

        $vars = ['name' => 'ana', 'amount' => '500.00 INR', 'account' => 'Acme Dental'];
        $email = app(EmailTemplate::class)->render('payment_received', $vars, 'https://voice.acme.example/console/wallet');
        $this->assertSame('Thanks Ana, 500.00 INR is in', $email['subject']);
        $this->assertStringContainsString('We got &lt;b&gt;500.00 INR&lt;/b&gt; for Acme Dental.', $email['html'], 'escaped');
        $this->assertStringContainsString('See it: https://voice.acme.example/console/wallet', $email['text']);
        app(Senders::class)->putFrom('hello@acme.example', null);
        $this->assertSame('Thanks Ana, 500.00 INR is in', app(Emails::class)->send('payment_received', 'ana@example.com', $vars)->subject, 'sent with them');

        $this->actingAs($this->owner)->deleteJson('/api/kit/admin/email/templates/payment_received')->assertOk()
            ->assertJsonPath('edited', false)->assertJsonPath('texts.subject', __('emails.kinds.payment_received.subject'));
        $this->assertSame(__('emails.kinds.payment_received.subject', ['amount' => '500.00 INR']), app(EmailTemplate::class)->render('payment_received', $vars)['subject']);
        $this->actingAs($this->owner)->deleteJson('/api/kit/admin/email/templates/payment_received')->assertOk();

        $this->assertSame([['email.template_saved', $this->owner->id], ['email.template_reset', $this->owner->id]],
            AuditEvent::query()->orderBy('id')->get()->map(fn ($e) => [$e->action, $e->user_id])->all(), 'going back twice is logged once');
        $this->assertSame(['email' => __('emails.kind_names.payment_received')], AuditEvent::query()->first()->details);
    }

    public function test_words_the_email_could_not_send_as_meant_are_refused(): void
    {
        $put = fn (string $kind, array $words) => $this->actingAs($this->owner)->putJson("/api/kit/admin/email/templates/{$kind}", $words);
        $known = ':brand, :amount, :name, :account';

        $put('payment_received', $this->words(['lines' => ['Hi :nme, :Amount is in.']]))->assertStatus(422)
            ->assertJsonPath('errors.lines.0', __('emails.template.unknown_placeholder', ['placeholders' => ':nme', 'known' => $known]));
        $put('payment_received', $this->words(['subject' => 'Hi :nAme']))->assertStatus(422)
            ->assertJsonPath('errors.subject.0', __('emails.template.unknown_placeholder', ['placeholders' => ':nAme', 'known' => $known]));
        $put('payment_received', $this->words(['subject' => "Two\nlines"]))->assertStatus(422)->assertJsonValidationErrors(['subject']);
        $put('payment_received', $this->words(['subject' => '  ']))->assertStatus(422)->assertJsonValidationErrors(['subject']);
        $put('payment_received', $this->words(['lines' => []]))->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $put('payment_received', $this->words(['lines' => ['ok', '']]))->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $put('payment_received', $this->words(['lines' => array_fill(0, config('kit.email.template.max_lines') + 1, 'x')]))->assertStatus(422)->assertJsonValidationErrors(['lines']);
        $put('payment_received', $this->words(['after' => ['ok', str_repeat('x', config('kit.email.template.line_max_length') + 1)]]))->assertStatus(422)->assertJsonValidationErrors(['after']);
        $put('payment_received', $this->words(['action' => '']))->assertStatus(422)
            ->assertJsonPath('errors.action.0', __('emails.template.action_required', ['max' => config('kit.email.template.action_max_length')]));
        $put('test', ['subject' => 'Hello from :brand', 'lines' => ['It works.'], 'action' => 'Click'])->assertStatus(422)
            ->assertJsonPath('errors.action.0', __('emails.template.no_action'));
        $put('no_such_email', $this->words())->assertNotFound()->assertExactJson(['message' => __('emails.template.unknown_kind')]);

        $this->actingAs($this->owner)->getJson('/api/kit/admin/email/templates')->assertOk()
            ->assertJsonPath('templates.'.array_search('payment_received', array_keys(__('emails.kinds'))).'.edited', false);
        $this->assertSame(0, AuditEvent::query()->count());

        $put('test', ['subject' => 'Hello from :BRAND', 'lines' => ['It works.'], 'action' => null])->assertOk()->assertJsonPath('texts.action', null);
    }

    public function test_the_preview_is_the_email_in_the_brand_with_placeholders_by_name_and_saves_nothing(): void
    {
        $preview = $this->actingAs($this->owner)->postJson('/api/kit/admin/email/templates/payment_received/preview', $this->words())->assertOk()->json();

        $this->assertSame(['subject', 'html', 'text'], array_keys($preview));
        $this->assertSame('Thanks [name], [amount] is in', $preview['subject']);
        $this->assertStringContainsString(e(config('kit.brand.name')), $preview['html']);
        $this->assertStringContainsString('for [account].', $preview['html']);
        $this->assertStringContainsString('See it: https://voice.acme.example/', $preview['text']);
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/templates/payment_received/preview', $this->words(['subject' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['subject']);
        $this->actingAs($this->owner)->postJson('/api/kit/admin/email/templates/test/preview', ['subject' => 'Hi', 'lines' => ['x']])
            ->assertOk()->assertJsonPath('text', fn (string $text) => ! str_contains($text, 'https://voice.acme.example'));

        $this->assertNull(app(\App\Kit\Email\EmailTexts::class)->edited('payment_received'));
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_only_staff_who_may_change_settings_use_the_templates(): void
    {
        $support = User::create(['name' => 'Sam', 'email' => 'sam@acme.example', 'password' => 'a long password 2', 'role' => 'support']);
        $customer = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a long password 3']);

        foreach ([$support, $customer] as $who) {
            $this->actingAs($who)->getJson('/api/kit/admin/email/templates')->assertForbidden();
            $this->actingAs($who)->putJson('/api/kit/admin/email/templates/payment_received', $this->words())->assertForbidden();
            $this->actingAs($who)->deleteJson('/api/kit/admin/email/templates/payment_received')->assertForbidden();
            $this->actingAs($who)->postJson('/api/kit/admin/email/templates/payment_received/preview', $this->words())->assertForbidden();
        }
        $this->assertSame(0, AuditEvent::query()->count());
    }
}
