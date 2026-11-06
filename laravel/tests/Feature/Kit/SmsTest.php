<?php

namespace Tests\Feature\Kit;

use App\Kit\Settings\Settings;
use App\Kit\Sms\Sms;
use App\Kit\Sms\SmsSenders;
use App\Models\OutgoingSms;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Texts (App\Kit\Sms) through the built-in HTTP driver, against a provider faked with Http::fake: a sender is saved
 * only after it texted the number staff give, in the provider's own words when it fails; the driver sends as the
 * operator described (POST JSON or form, or GET; the number with or without `+`; the key as a parameter or a header;
 * a sender id; an answer that must say it was sent); senders are tried in order at once and a text's words are never
 * kept; secrets are stored encrypted, never answered, and kept when left empty; Admin → SMS is the staff's alone.
 */
class SmsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://sms.example.test/v1/send';

    private User $staff;

    /** What the provider answers next, by URL (default: 200 {"status":"success"}). */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        app(Settings::class)->put('brand.name', 'Acme Voice');
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->answers[strtok($r->url(), '?')] ?? Http::response(['status' => 'success', 'id' => 'msg_1']));
    }

    private function form(array $over = []): array
    {
        return array_merge(['driver' => 'http', 'url' => self::URL, 'method' => 'POST', 'format' => 'json', 'toParam' => 'to', 'numberFormat' => 'e164',
            'textParam' => 'text', 'headerName' => 'Authorization', 'headerValue' => 'Bearer sk_sms_123', 'testNumber' => '+91 98765 43210'], $over);
    }

    /** @return list<Request> what reached the provider */
    private function sent(): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])->values()->all();
    }

    public function test_a_sender_is_saved_only_after_it_texted_the_test_number(): void
    {
        $add = fn (array $body) => $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/senders', $body);
        $add($this->form(['testNumber' => '98765 43210']))->assertStatus(422)
            ->assertJsonPath('errors.testNumber.0', 'A phone number with its country code, such as +1 415 555 0123.');
        $add($this->form(['headerValue' => '']))->assertStatus(422)->assertJsonValidationErrors(['headerValue']);
        $this->answers[self::URL] = Http::response(['error' => 'Invalid API key'], 401);
        $add($this->form())->assertStatus(422)
            ->assertJsonPath('message', 'The test text was not sent: The provider answered 401: {"error":"Invalid API key"}');
        $this->assertSame([], app(SmsSenders::class)->all());

        unset($this->answers[self::URL]);
        $shown = $add($this->form())->assertCreated()->json();
        $last = $this->sent()[count($this->sent()) - 1];
        $this->assertSame(['POST', self::URL, ['Bearer sk_sms_123']], [$last->method(), $last->url(), $last->header('Authorization')]);
        $this->assertSame(['to' => '+919876543210', 'text' => 'Acme Voice: this is a test text. There is nothing to do.'], $last->data());
        $this->assertTrue($shown['on']);
        $this->assertSame(['http', 'SMS provider (HTTP API)', 'POST sms.example.test'], [$shown['senders'][0]['driver'], $shown['senders'][0]['driverName'], $shown['senders'][0]['label']]);
        $secret = collect($shown['senders'][0]['fields'])->firstWhere('name', 'headerValue');
        $this->assertSame([null, true], [$secret['value'], $secret['secretSet']]);
        $this->assertStringNotContainsString('sk_sms_123', (string) DB::table('settings')->where('key', SmsSenders::LIST)->value('value'));
        $this->assertArrayNotHasKey('testNumber', app(SmsSenders::class)->all()[0]['config'], 'the test number is not kept');
    }

    public function test_the_http_driver_sends_as_the_operator_described(): void
    {
        $sms = app(Sms::class);
        $senders = app(SmsSenders::class);
        $use = function (array $config) use ($senders) {
            $senders->put([['id' => 's1', 'driver' => 'http', 'config' => $senders->check('http', array_merge($this->form(), $config))]]);
        };

        $use(['method' => 'GET', 'numberFormat' => 'digits', 'toParam' => 'mobiles', 'textParam' => 'message', 'keyParam' => 'authkey',
            'keyValue' => 'k-42', 'headerName' => '', 'headerValue' => '', 'fromParam' => 'sender', 'from' => 'ACMEVO']);
        $this->assertSame('sent', $sms->send('test', '+919876543210')->status);
        $get = $this->sent()[0];
        parse_str((string) parse_url($get->url(), PHP_URL_QUERY), $query);
        $this->assertSame(['GET', ['mobiles' => '919876543210', 'message' => 'Acme Voice: this is a test text. There is nothing to do.', 'sender' => 'ACMEVO', 'authkey' => 'k-42']],
            [$get->method(), $query]);
        $this->assertSame([], $get->header('Authorization'));

        $use(['format' => 'form']);
        $sms->send('test', '+14155550101');
        $form = $this->sent()[1];
        $this->assertStringStartsWith('application/x-www-form-urlencoded', $form->header('Content-Type')[0]);
        $this->assertSame(['to' => '+14155550101', 'text' => 'Acme Voice: this is a test text. There is nothing to do.'], $form->data());

        // A provider that answers 200 when it fails: only an answer with the operator's word counts.
        $use(['successText' => '"status":"success"']);
        $this->answers[self::URL] = Http::response(['status' => 'error', 'reason' => 'DLT template not approved']);
        $failed = $sms->send('test', '+14155550101');
        $this->assertSame(['failed', 'Sender 1: The answer did not say it was sent: {"status":"error","reason":"DLT template not approved"}'],
            [$failed->status, $failed->last_error]);
        unset($this->answers[self::URL]);
        $this->assertSame('sent', $sms->send('test', '+14155550101')->status);
    }

    public function test_senders_are_tried_in_order_at_once_and_a_texts_words_are_never_kept(): void
    {
        $senders = app(SmsSenders::class);
        $second = 'https://backup.example.test/send';
        $senders->put([
            ['id' => 'a', 'driver' => 'http', 'config' => $senders->check('http', $this->form())],
            ['id' => 'b', 'driver' => 'http', 'config' => $senders->check('http', $this->form(['url' => $second]))],
        ]);
        $first = app(Sms::class)->send('test', '+919876543210');
        $this->assertSame(['sent', 1], [$first->status, $first->attempts]);
        $this->assertSame([self::URL], array_map(fn (Request $r) => $r->url(), $this->sent()), 'the first sender took it: no other is tried');
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn (Request $r) => $this->answers[strtok($r->url(), '?')] ?? Http::response(['status' => 'success', 'id' => 'msg_1']));

        $this->answers[self::URL] = Http::response('Service Unavailable', 503);
        $sms = app(Sms::class)->send('sign_in_code', '+919876543210', ['code' => '483920', 'minutes' => 10]);
        $this->assertSame(['sent', 2, 'Sender 1: The provider answered 503: Service Unavailable'], [$sms->status, $sms->attempts, $sms->last_error]);
        $this->assertSame([self::URL, $second], array_map(fn (Request $r) => $r->url(), $this->sent()));
        $this->assertSame('483920 is your Acme Voice sign-in code. It works for 10 minutes. Never share it.', $this->sent()[1]->data()['text']);
        $this->assertStringNotContainsString('483920', json_encode(DB::table('sms_messages')->get()->all()), 'the words are never kept');

        $this->answers[$second] = Http::response('nope', 500);
        $this->assertSame('failed', app(Sms::class)->send('test', '+919876543210')->status);
        $recent = $this->actingAs($this->staff)->getJson('/api/kit/admin/sms')->assertOk()->json('recent');
        $this->assertSame([['test', 'Test', 'failed', 2], ['sign_in_code', 'Sign-in code', 'sent', 2], ['test', 'Test', 'sent', 1]],
            array_map(fn (array $r) => [$r['kind'], $r['kindName'], $r['status'], $r['attempts']], $recent));
    }

    public function test_senders_are_edited_keeping_secrets_ordered_and_removed(): void
    {
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/senders', $this->form())->assertCreated();
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/senders', $this->form(['url' => 'https://backup.example.test/send']))->assertCreated();
        [$a, $b] = array_column(app(SmsSenders::class)->all(), 'id');

        $this->actingAs($this->staff)->putJson("/api/kit/admin/sms/senders/{$a}", $this->form(['headerValue' => '', 'from' => 'ACMEVO', 'fromParam' => 'sender']))->assertOk();
        $config = app(SmsSenders::class)->all()[0]['config'];
        $this->assertSame(['Bearer sk_sms_123', 'ACMEVO'], [$config['headerValue'], $config['from']]);
        $this->assertSame(['Bearer sk_sms_123'], $this->sent()[count($this->sent()) - 1]->header('Authorization'), 'the kept secret sent the test');

        $this->actingAs($this->staff)->putJson('/api/kit/admin/sms/order', ['ids' => [$b]])->assertStatus(422);
        $this->actingAs($this->staff)->putJson('/api/kit/admin/sms/order', ['ids' => [$b, $a]])->assertOk()->assertJsonPath('senders.0.id', $b);
        $this->actingAs($this->staff)->deleteJson("/api/kit/admin/sms/senders/{$b}")->assertNoContent();
        $this->assertSame([$a], array_column(app(SmsSenders::class)->all(), 'id'));
        $this->actingAs($this->staff)->putJson('/api/kit/admin/sms/senders/no-such', $this->form())->assertNotFound();

        config(['kit.sms.max_senders' => 1]);
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/senders', $this->form())->assertStatus(409);
    }

    public function test_a_test_goes_through_the_line_and_admin_sms_is_the_staffs_alone(): void
    {
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/test', ['testNumber' => '+919876543210'])->assertStatus(409)
            ->assertJsonPath('message', 'Add a sender first.');
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/senders', $this->form())->assertCreated();
        $this->actingAs($this->staff)->postJson('/api/kit/admin/sms/test', ['testNumber' => '0091 98765 43210'])->assertOk()
            ->assertJsonPath('status', 'sent')->assertJsonPath('to', '+919876543210');

        $ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->actingAs($ana)->getJson('/api/kit/admin/sms')->assertForbidden();
        $this->actingAs($ana)->postJson('/api/kit/admin/sms/senders', $this->form())->assertForbidden();
        $this->assertCount(1, app(SmsSenders::class)->all());
    }
}
