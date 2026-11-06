<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Kit\Sms\Sms;
use App\Kit\Sms\SmsSenders;
use App\Models\OutgoingSms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Codes by WhatsApp (App\Kit\Sms\Drivers\WhatsAppCloudDriver), as a sender in the same line as SMS, against the request
 * Meta's documentation gives for a copy code Authentication template: saved only once it delivered a test; a code goes in the
 * body and the button; a notice needs its own template or goes to the next sender; a refusal falls through; the pages know
 * where to tell people to look. Meta itself is not reached: its answers here are the documented ones.
 */
class WhatsAppSenderTest extends TestCase
{
    use RefreshDatabase;

    private const NUMBER_ID = '1234567890';

    private const GRAPH = 'https://graph.facebook.com/v26.0/1234567890/messages';

    private const SMS = 'https://sms.example.test/send';

    private const ANA = '+919876543210';

    private User $staff;

    /** @var array<string, mixed> what each address answers, over a success */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        app(Settings::class)->put('brand.name', 'Acme Voice');
        Http::preventStrayRequests();
        Http::fake(function (Request $r) {
            $answer = $this->answers[strtok($r->url(), '?')] ?? null;
            if ($answer instanceof \Closure) {
                return $answer($r);
            }

            return $answer ?? Http::response($r->url() === self::GRAPH
                ? ['messaging_product' => 'whatsapp', 'contacts' => [['input' => '919876543210', 'wa_id' => '919876543210']], 'messages' => [['id' => 'wamid.HBg']]]
                : ['status' => 'success']);
        });
    }

    private function form(array $over = []): array
    {
        return array_merge(['driver' => 'whatsapp_cloud', 'phoneNumberId' => self::NUMBER_ID, 'accessToken' => 'EAAG-token', 'codeTemplate' => 'login_code',
            'language' => 'en_US', 'testNumber' => '+91 98765 43210'], $over);
    }

    private function add(array $over = [])
    {
        return $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/senders', $this->form($over));
    }

    private function line(array ...$senders): void
    {
        app(SmsSenders::class)->put(array_map(fn (array $s, int $i) => ['id' => "s{$i}"] + $s, $senders, array_keys($senders)));
    }

    private static function whatsapp(array $over = []): array
    {
        return ['driver' => 'whatsapp_cloud', 'config' => array_merge(['phoneNumberId' => self::NUMBER_ID, 'accessToken' => 'EAAG-token', 'codeTemplate' => 'login_code', 'language' => 'en_US'], $over)];
    }

    private static function sms(): array
    {
        return ['driver' => 'http', 'config' => ['url' => self::SMS, 'method' => 'POST', 'format' => 'json', 'toParam' => 'to', 'numberFormat' => 'e164', 'textParam' => 'text']];
    }

    /** @return list<Request> */
    private function to(string $url): array
    {
        return Http::recorded()->map(fn (array $p) => $p[0])->filter(fn (Request $r) => $r->url() === $url)->values()->all();
    }

    private function templateCode(Request $r): string
    {
        return $r->data()['template']['components'][0]['parameters'][0]['text'];
    }

    public function test_a_sender_is_saved_only_after_meta_took_a_test_through_the_template(): void
    {
        $this->answers[self::GRAPH] = Http::response(['error' => ['message' => 'Template name does not exist in the translation', 'code' => 132001]], 404);
        $this->add()->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'WhatsApp answered 404') && str_contains($m, 'does not exist'));
        $this->assertSame([], app(SmsSenders::class)->all());

        $this->answers[self::GRAPH] = Http::response(['messaging_product' => 'whatsapp']);
        $this->add()->assertStatus(422)->assertJsonPath('message', fn (string $m) => str_contains($m, 'did not say it took'));
        $this->assertSame([], app(SmsSenders::class)->all());

        $this->answers = [];
        $shown = $this->add()->assertCreated()->json();
        $last = $this->to(self::GRAPH)[count($this->to(self::GRAPH)) - 1];
        $this->assertSame(['Bearer EAAG-token'], $last->header('Authorization'));
        $this->assertSame([
            'messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => '919876543210', 'type' => 'template',
            'template' => ['name' => 'login_code', 'language' => ['code' => 'en_US'], 'components' => [
                ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '000000']]],
                ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => '000000']]],
            ]],
        ], $last->data());
        $this->assertSame(['whatsapp_cloud', 'WhatsApp (Meta Cloud API)', 'WhatsApp 1234567890, template login_code'],
            [$shown['senders'][0]['driver'], $shown['senders'][0]['driverName'], $shown['senders'][0]['label']]);
        $token = collect($shown['senders'][0]['fields'])->firstWhere('name', 'accessToken');
        $this->assertSame([null, true], [$token['value'], $token['secretSet']]);
        $this->assertStringNotContainsString('EAAG-token', (string) DB::table('settings')->where('key', SmsSenders::LIST)->value('value'), 'kept encrypted');
        $this->assertArrayNotHasKey('testNumber', app(SmsSenders::class)->all()[0]['config']);
        $this->assertContains('whatsapp_cloud', array_column($shown['drivers'], 'id'), 'the form draws it');
    }

    public function test_what_it_takes_is_checked_and_a_token_left_empty_is_kept(): void
    {
        $this->add(['phoneNumberId' => '+91 98765'])->assertStatus(422)->assertJsonValidationErrors(['phoneNumberId']);
        $this->add(['codeTemplate' => 'Login Code'])->assertStatus(422)->assertJsonValidationErrors(['codeTemplate']);
        $this->add(['language' => 'english'])->assertStatus(422)->assertJsonValidationErrors(['language']);
        $this->add(['accessToken' => ''])->assertStatus(422)->assertJsonValidationErrors(['accessToken']);
        $this->add(['noticeTemplate' => 'Not Valid'])->assertStatus(422)->assertJsonValidationErrors(['noticeTemplate']);
        $this->assertSame([], Http::recorded()->all(), 'nothing is sent for a form that is wrong');

        $id = $this->add()->assertCreated()->json('senders.0.id');
        $this->actingAs($this->staff)->putJson("/api/kit/admin/sms/senders/{$id}", $this->form(['accessToken' => '', 'codeTemplate' => 'other_code']))->assertOk();
        $config = app(SmsSenders::class)->all()[0]['config'];
        $this->assertSame(['EAAG-token', 'other_code'], [$config['accessToken'], $config['codeTemplate']]);
    }

    public function test_a_customer_signs_in_with_a_code_sent_by_whatsapp(): void
    {
        $this->line(self::whatsapp());
        app(SignupSettings::class)->save(['methods' => ['password', 'phone_code']]);
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $ana->forceFill(['phone' => self::ANA, 'phone_verified_at' => now()])->save();

        $this->getJson('/api/kit/brand')->assertJsonPath('signInCode', true)->assertJsonPath('signInCodeChannel', 'whatsapp');
        $this->postJson('/api/kit/session/code', ['phone' => self::ANA])->assertStatus(202);
        [$sent] = $this->to(self::GRAPH);
        $code = $this->templateCode($sent);
        $this->assertMatchesRegularExpression('/^\d{'.config('kit.phone.code_length').'}$/', $code);
        $this->assertSame($code, $sent->data()['template']['components'][1]['parameters'][0]['text'], 'Meta wants it twice');
        $this->assertSame('login_code', $sent->data()['template']['name']);

        $this->postJson('/api/kit/session/code/verify', ['phone' => self::ANA, 'code' => $code])->assertOk()->assertJsonPath('user.email', 'ana@dental.example');
        $this->assertAuthenticatedAs($ana);
    }

    public function test_a_notice_needs_its_own_template_or_goes_to_the_next_sender(): void
    {
        $sms = app(Sms::class);
        $this->line(self::whatsapp());
        $this->assertSame('failed', $sms->send('signup_rejected', self::ANA)->status, 'it cannot send free text');
        $this->assertSame([], $this->to(self::GRAPH), 'and does not try');
        $this->assertStringContainsString(__('sms.drivers.whatsapp_cloud.no_notice_template'), (string) OutgoingSms::query()->latest('id')->first()->last_error);

        $this->line(self::whatsapp(), self::sms());
        $this->assertSame('sent', $sms->send('signup_rejected', self::ANA)->status);
        $this->assertCount(1, $this->to(self::SMS));

        Http::fake();
        $this->line(self::whatsapp(['noticeTemplate' => 'account_notice']));
        $this->assertSame('sent', $sms->send('signup_approved', self::ANA, ['site' => "https://voice.acme.example\n\n   /console"])->status);
        $sent = $this->to(self::GRAPH);
        $data = $sent[count($sent) - 1]->data();
        $this->assertSame('account_notice', $data['template']['name']);
        $this->assertCount(1, $data['template']['components'], 'no button on a notice');
        $text = $data['template']['components'][0]['parameters'][0]['text'];
        $this->assertStringContainsString('your sign-up is approved', $text);
        $this->assertDoesNotMatchRegularExpression('/\s{2,}|\n/', $text, 'a variable may hold no line breaks or runs of spaces');
    }

    public function test_a_refusal_or_a_network_failure_falls_through_to_the_next_sender_with_the_same_code(): void
    {
        $this->line(self::whatsapp(), self::sms());
        $this->answers[self::GRAPH] = Http::response(['error' => ['message' => 'Recipient is not a WhatsApp user', 'code' => 131026]], 400);
        $sms = app(Sms::class)->send('sign_in_code', self::ANA, ['code' => '424242', 'minutes' => 10]);
        $this->assertSame(['sent', 2], [$sms->status, $sms->attempts]);
        $this->assertStringContainsString('Recipient is not a WhatsApp user', (string) $sms->last_error);
        $this->assertStringContainsString('424242', $this->to(self::SMS)[0]->data()['text']);
        $this->assertArrayNotHasKey('code', $this->to(self::SMS)[0]->data(), 'an SMS provider gets the words');

        $this->answers[self::GRAPH] = fn () => throw new ConnectionException('Connection timed out');
        $this->assertSame('sent', app(Sms::class)->send('sign_in_code', self::ANA, ['code' => '424242', 'minutes' => 10])->status);
        $this->assertCount(2, $this->to(self::SMS));
    }

    public function test_the_pages_know_where_to_tell_people_to_look(): void
    {
        $channel = fn () => $this->getJson('/api/kit/brand')->json('signInCodeChannel');
        $this->assertNull($channel());
        $this->line(self::sms());
        $this->assertSame('sms', $channel());
        $this->line(self::whatsapp());
        $this->assertSame('whatsapp', $channel());
        $this->line(self::whatsapp(), self::sms());
        $this->assertSame('mixed', $channel());
    }

    public function test_the_test_through_the_whole_line_uses_the_template_too(): void
    {
        $this->line(self::whatsapp());
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/test', ['testNumber' => self::ANA])->assertOk()->assertJsonPath('status', 'sent');
        $this->assertSame('000000', $this->templateCode($this->to(self::GRAPH)[0]));
    }

    public function test_the_version_and_address_are_settings(): void
    {
        config(['kit.sms.whatsapp_cloud.graph_url' => 'https://graph.example.test/', 'kit.sms.whatsapp_cloud.graph_version' => 'v30.0']);
        $this->answers['https://graph.example.test/v30.0/1234567890/messages'] = Http::response(['messages' => [['id' => 'wamid.X']]]);
        $this->line(self::whatsapp());
        $this->assertSame('sent', app(Sms::class)->send('sign_in_code', self::ANA, ['code' => '424242', 'minutes' => 10])->status);
    }
}
