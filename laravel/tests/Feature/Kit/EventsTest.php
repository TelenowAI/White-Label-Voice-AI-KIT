<?php

namespace Tests\Feature\Kit;

use App\Kit\Events\TelenowEvent;
use App\Kit\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\TestCase;

/**
 * Telenow's events reach the kit (App\Kit\Events): the kit registers ONE org-wide endpoint on the operator's org for
 * exactly the events it handles, keeps it alive (a disabled endpoint drops out of Telenow's list: a new one is
 * registered, and the secret before it still verifies), and stores each signed delivery once, answering at once —
 * never 410, which would stop Telenow sending for good. Telenow answers from real recordings (contract_k1calls.py).
 */
class EventsTest extends TestCase
{
    use RefreshDatabase;

    private const OPERATOR = 'vai_test_operator_admin';

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        config(['app.url' => 'https://kit.example.com', 'kit.events.url' => null]);
        app(Settings::class)->put('telenow.api_key', self::OPERATOR, secret: true);
        RecordedTelenow::fake();
    }

    /** Telenow answers with `$first` where it says something, and from the recordings otherwise. */
    private function answering(\Closure $first): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $first($r) ?? $recorded($r));
    }

    private static function is(Request $r, string $method, string $path): bool
    {
        return $r->method() === $method && parse_url($r->url(), PHP_URL_PATH) === $path;
    }

    /** @return list<Request> */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => self::is($r, $method, $path))->map(fn (array $pair) => $pair[0])->values()->all();
    }

    private function deliver(array $event, array $headers = []): TestResponse
    {
        return $this->call('POST', '/api/kit/telenow/events', [], [], [], $this->server($headers + $event['headers']), $event['body']);
    }

    /** @param array<string, string> $headers */
    private function server(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    public function test_the_kit_registers_one_org_wide_endpoint_for_the_events_it_handles(): void
    {
        $this->artisan('kit:events')->expectsOutputToContain('events endpoint ok')->assertSuccessful();
        [$create] = $this->sent('POST', '/api/v1/hooks');
        $this->assertSame([self::OPERATOR], $create->header('X-API-Key'));
        $this->assertSame([
            'target_url' => 'https://kit.example.com/api/kit/telenow/events',
            'events' => array_keys(config('kit.events.handlers')),
            'source' => config('kit.events.source'),
        ], json_decode($create->body(), true), 'org-wide: no agent_id');
        $hook = RecordedTelenow::body('createHook.operator');
        $this->assertSame($hook['id'], app(Settings::class)->get('telenow.events')['id']);
        $this->assertSame(['current' => $hook['signing_secret'], 'previous' => null], app(Settings::class)->get('telenow.events_secrets'));
        $this->assertTrue(\DB::table('settings')->where('key', 'telenow.events_secrets')->value('is_secret') == true, 'kept encrypted');

        $this->artisan('kit:events')->assertSuccessful();
        $this->assertCount(1, $this->sent('POST', '/api/v1/hooks'), 'not checked again before check_minutes');
        $this->travel(config('kit.events.check_minutes'))->minutes();
        $this->artisan('kit:events')->assertSuccessful();
        $this->assertCount(1, $this->sent('GET', '/api/v1/hooks'), 'checked: still listed');
        $this->assertCount(1, $this->sent('POST', '/api/v1/hooks'), 'so not registered again');
    }

    public function test_a_disabled_endpoint_is_registered_again_and_the_secret_before_still_verifies(): void
    {
        $this->artisan('kit:events')->assertSuccessful();
        $old = app(Settings::class)->get('telenow.events_secrets')['current'];
        $this->answering(fn (Request $r) => match (true) {
            self::is($r, 'GET', '/api/v1/hooks') => Http::response(RecordedTelenow::body('listHooks.after-delete')),
            self::is($r, 'POST', '/api/v1/hooks') => Http::response(['signing_secret' => 'wh_secret_test_second'] + RecordedTelenow::body('createHook.operator'), 201),
            default => null,
        });
        $this->travel(config('kit.events.check_minutes'))->minutes();
        $this->artisan('kit:events')->assertSuccessful();
        $this->assertSame(['current' => 'wh_secret_test_second', 'previous' => $old], app(Settings::class)->get('telenow.events_secrets'));
        $this->assertSame([], $this->sent('DELETE', '/api/v1/hooks/'.RecordedTelenow::body('createHook.operator')['id']), 'a disabled one is not deleted');

        $this->deliver(RecordedTelenow::event('call.completed.operator-own', $old))->assertNoContent();
        $this->deliver(RecordedTelenow::event('call.completed.unanswered', 'wh_secret_test_second'))->assertNoContent();
        $this->assertSame(2, TelenowEvent::query()->count());
    }

    public function test_a_new_url_registers_anew_and_deletes_the_endpoint_still_sending_to_the_old_one(): void
    {
        $this->artisan('kit:events')->assertSuccessful();
        config(['kit.events.url' => 'https://voice.example.com/api/kit/telenow/events']);
        $this->travel(config('kit.events.check_minutes'))->minutes();
        $this->artisan('kit:events')->assertSuccessful();
        $creates = $this->sent('POST', '/api/v1/hooks');
        $this->assertCount(2, $creates);
        $this->assertSame('https://voice.example.com/api/kit/telenow/events', json_decode($creates[1]->body(), true)['target_url']);
        [$delete] = $this->sent('DELETE', '/api/v1/hooks/'.RecordedTelenow::body('createHook.operator')['id']);
        $this->assertSame([self::OPERATOR], $delete->header('X-API-Key'));
    }

    public function test_a_refused_registration_is_kept_for_the_admin(): void
    {
        $this->answering(fn (Request $r) => self::is($r, 'POST', '/api/v1/hooks') ? Http::response(RecordedTelenow::body('createHook.http-refused'), 400) : null);
        $this->artisan('kit:events')->expectsOutputToContain('events endpoint:')->assertSuccessful();
        $this->assertSame(RecordedTelenow::body('createHook.http-refused')['error'], app(Settings::class)->get('telenow.events_error'));
        $this->assertSame([], app(Settings::class)->get('telenow.events_secrets', []));

        $this->answering(fn () => null);
        $this->artisan('kit:events')->expectsOutputToContain('events endpoint ok')->assertSuccessful();
        $this->assertCount(1, $this->sent('POST', '/api/v1/hooks'), 'tried again on the next run, not after check_minutes');
        $this->assertNull(app(Settings::class)->get('telenow.events_error'));
    }

    public function test_a_failed_re_registration_is_tried_again_on_the_next_run(): void
    {
        $this->artisan('kit:events')->assertSuccessful();
        $refuse = true;
        $this->answering(function (Request $r) use (&$refuse) {
            return match (true) {
                self::is($r, 'GET', '/api/v1/hooks') => Http::response(RecordedTelenow::body('listHooks.after-delete')),
                $refuse && self::is($r, 'POST', '/api/v1/hooks') => Http::response(RecordedTelenow::body('createHook.http-refused'), 400),
                default => null,
            };
        });
        $this->travel(config('kit.events.check_minutes'))->minutes();
        $this->artisan('kit:events')->expectsOutputToContain('events endpoint:')->assertSuccessful();
        $refuse = false;
        $this->artisan('kit:events')->expectsOutputToContain('events endpoint ok')->assertSuccessful();
        $this->assertCount(2, $this->sent('POST', '/api/v1/hooks'), 'the old endpoint is gone: no waiting for check_minutes');
    }

    public function test_a_signed_delivery_is_stored_once_and_answered_at_once(): void
    {
        $this->artisan('kit:events')->assertSuccessful();
        $event = RecordedTelenow::event('call.completed.operator-own');
        $this->deliver($event)->assertNoContent();
        $this->deliver($event)->assertNoContent();
        $stored = TelenowEvent::query()->sole();
        $this->assertSame([$event['headers']['X-VoiceAI-Delivery'], 'call.completed', $event['body'], null],
            [$stored->delivery_id, $stored->event, $stored->body, $stored->sub_account_id]);
        $this->assertNotNull($stored->fresh()->applied_at, 'applied right after the answer');
    }

    public function test_what_telenow_is_refused_and_never_with_410(): void
    {
        $event = RecordedTelenow::event('call.completed.answered');
        $this->deliver($event)->assertStatus(503)->assertExactJson(['message' => __('events.refused.503')]);
        $this->artisan('kit:events')->assertSuccessful();

        $this->deliver($event, ['X-VoiceAI-Signature' => 'sha256='.hash_hmac('sha256', $event['body'], 'not the secret')])->assertStatus(401);
        $this->deliver($event, ['X-VoiceAI-Signature' => ''])->assertStatus(401);
        $tampered = $event;
        $tampered['body'] = str_replace('"no_answer"', '"completed"', str_replace('"completed"', '"booked"', $event['body']));
        $this->deliver($tampered)->assertStatus(401);
        $notJson = ['body' => 'not json', 'headers' => ['X-VoiceAI-Signature' => 'sha256='.hash_hmac('sha256', 'not json', $event['secret'])] + $event['headers']];
        $this->deliver($notJson)->assertStatus(400);
        $this->deliver($event, ['X-VoiceAI-Delivery' => ''])->assertStatus(400);
        $this->assertSame(0, TelenowEvent::query()->count());

        unlink(config('kit.installed_marker'));
        $this->assertNotSame(410, $this->deliver($event)->status());
    }
}
