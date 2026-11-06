<?php

namespace Tests\Feature\Kit;

use App\Kit\Payments\Drivers\PayPalDriver;
use App\Kit\Payments\PaymentFailed;
use App\Kit\Settings\Settings;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Models\PriceList;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TopsUpThroughAGateway;
use Tests\TestCase;

/**
 * PayPal (App\Kit\Payments\Drivers\PayPalDriver) through the kit's real routes, against a fake of its documented API: Orders
 * v2 with a capture after approval, its own signature check, refunds by request id, disputes. The bodies follow PayPal's
 * published schemas read 11-03. They are not recordings from a real account: none has been run yet.
 */
class PayPalGatewayTest extends TestCase
{
    use RefreshDatabase;
    use TopsUpThroughAGateway;

    private const LIVE = ['live-client', 'live-secret'];

    private const SANDBOX = ['sandbox-client', 'sandbox-secret'];

    private const WEBHOOK_ID = '8PT597110X687430LKGECATA';

    private const LIVE_API = 'https://api-m.paypal.com';

    private const SANDBOX_API = 'https://api-m.sandbox.paypal.com';

    /** @var array<string, array> orders by id */
    private array $orders = [];

    /** @var array<string, array> captures by id */
    private array $captures = [];

    /** @var array<string, array> refunds by PayPal-Request-Id */
    private array $refunds = [];

    /** @var array<string, array> captures made, by PayPal-Request-Id */
    private array $capturedBy = [];

    private string $captureStatus = 'COMPLETED';

    private string $refundStatus = 'COMPLETED';

    private string $approvalRel = 'payer-action';

    private bool $tokensRefused = false;

    private bool $tokensSayNothing = false;

    private int $tokensIssued = 0;

    /** What the verify call was last sent, as bytes. */
    private ?string $verifiedBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->installWith(fn (Request $r) => $this->paypal($r), 'USD');
    }

    private function paypal(Request $r): mixed
    {
        if (! str_starts_with($r->url(), self::LIVE_API) && ! str_starts_with($r->url(), self::SANDBOX_API)) {
            return null;
        }
        $this->record($r);
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if ($path === '/v1/oauth2/token') {
            [$id, $secret] = array_pad(explode(':', (string) base64_decode(substr($r->header('Authorization')[0] ?? '', 6)), 2), 2, null);
            $known = str_starts_with($r->url(), self::LIVE_API) ? [self::LIVE[0] => self::LIVE[1]] : [self::SANDBOX[0] => self::SANDBOX[1]];
            if ($this->tokensRefused || ($known[$id] ?? null) !== $secret) {
                return Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401);
            }
            if ($this->tokensSayNothing) {
                return Http::response(['token_type' => 'Bearer']);
            }
            $this->tokensIssued++;

            return Http::response(['access_token' => "token-{$this->tokensIssued}", 'token_type' => 'Bearer', 'expires_in' => 32400]);
        }
        if (! str_starts_with($r->header('Authorization')[0] ?? '', 'Bearer token-')) {
            return Http::response(['name' => 'AUTHENTICATION_FAILURE', 'message' => 'Authentication failed'], 401);
        }
        if ($path === '/v1/notifications/verify-webhook-signature') {
            $this->verifiedBody = $r->body();

            return Http::response(['verification_status' => ($r->data()['transmission_sig'] ?? null) === 'good-signature' ? 'SUCCESS' : 'FAILURE']);
        }
        if ($path === '/v2/checkout/orders') {
            $id = strtoupper(Str::random(17));
            $this->orders[$id] = $r->data() + ['id' => $id, 'status' => 'PAYER_ACTION_REQUIRED',
                'links' => [['href' => "https://www.paypal.com/checkoutnow?token={$id}", 'rel' => $this->approvalRel, 'method' => 'GET']]];

            return Http::response($this->orders[$id], 200);
        }
        if (preg_match('#^/v2/checkout/orders/(\w+)/capture$#', $path, $m) && isset($this->orders[$m[1]])) {
            return Http::response($this->capture($m[1], $r->header('PayPal-Request-Id')[0] ?? ''), 201);
        }
        if (preg_match('#^/v2/checkout/orders/(\w+)$#', $path, $m) && isset($this->orders[$m[1]])) {
            return Http::response($this->orders[$m[1]]);
        }
        if (preg_match('#^/v2/payments/captures/(\w+)/refund$#', $path, $m) && isset($this->captures[$m[1]])) {
            $key = $r->header('PayPal-Request-Id')[0];
            $this->refunds[$key] ??= ['id' => strtoupper(Str::random(17)), 'status' => $this->refundStatus, 'amount' => $r->data()['amount'], 'custom_id' => $r->data()['custom_id'] ?? null,
                'links' => [['rel' => 'up', 'href' => self::LIVE_API."/v2/payments/captures/{$m[1]}", 'method' => 'GET']]];

            return Http::response($this->refunds[$key], 201);
        }
        if (preg_match('#^/v2/payments/captures/(\w+)$#', $path, $m) && isset($this->captures[$m[1]])) {
            return Http::response($this->captures[$m[1]]);
        }

        return Http::response(['name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.'], 404);
    }

    /** PayPal gives the same answer to the same request id, and captures an order once. */
    private function capture(string $orderId, string $requestId): array
    {
        if (isset($this->capturedBy[$requestId])) {
            return $this->capturedBy[$requestId];
        }
        $order = &$this->orders[$orderId];
        $unit = $order['purchase_units'][0];
        $id = strtoupper(Str::random(17));
        $this->captures[$id] = ['id' => $id, 'status' => $this->captureStatus, 'amount' => $unit['amount'], 'custom_id' => $unit['custom_id'], 'supplementary_data' => ['related_ids' => ['order_id' => $orderId]]];
        $order['status'] = 'COMPLETED';
        $order['purchase_units'][0]['payments'] = ['captures' => [$this->captures[$id]]];

        return $this->capturedBy[$requestId] = $order;
    }

    private function gateway(string $mode = 'live'): PaymentGateway
    {
        [$id, $secret] = $mode === 'live' ? self::LIVE : self::SANDBOX;
        $this->add('paypal', $mode, ['clientId' => $id, 'clientSecret' => $secret, 'webhookId' => self::WEBHOOK_ID])->assertCreated();

        return PaymentGateway::query()->where('driver', 'paypal')->latest('position')->firstOrFail();
    }

    private function started(PaymentGateway $gateway, string $amount = '500'): Payment
    {
        return Payment::query()->findOrFail($this->topUp($gateway, $amount)->assertCreated()->json('payment.id'));
    }

    /** The payer approves on PayPal's page. No money has moved. */
    private function approves(Payment $payment): array
    {
        $this->orders[$payment->gateway_ref]['status'] = 'APPROVED';

        return $this->orders[$payment->gateway_ref];
    }

    /** The payer approves and the kit captures: answers the capture. */
    private function paid(Payment $payment): array
    {
        $this->approves($payment);
        $this->comeBack($payment->id);

        return $this->orders[$payment->gateway_ref]['purchase_units'][0]['payments']['captures'][0];
    }

    private function deliver(PaymentGateway $gateway, string $type, array $resource, string $signature = 'good-signature', ?string $eventId = null): TestResponse
    {
        return $this->deliverBody($gateway, json_encode(['id' => $eventId ?? 'WH-'.Str::random(20), 'event_version' => '1.0', 'resource_type' => 'x', 'event_type' => $type, 'resource' => $resource]), $signature);
    }

    private function deliverBody(PaymentGateway $gateway, string $body, string $signature = 'good-signature', bool $headers = true): TestResponse
    {
        return $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'] + ($headers ? [
            'HTTP_PAYPAL_AUTH_ALGO' => 'SHA256withRSA', 'HTTP_PAYPAL_CERT_URL' => 'https://api.paypal.com/v1/notifications/certs/CERT-360caa42', 'HTTP_PAYPAL_TRANSMISSION_ID' => 'tr-1',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => $signature, 'HTTP_PAYPAL_TRANSMISSION_TIME' => '2026-11-03T10:00:00Z',
        ] : []), $body);
    }

    private function refundEvent(array $capture, string $id, string $value, ?string $customId = null): array
    {
        return array_filter(['id' => $id, 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'USD', 'value' => $value], 'custom_id' => $customId,
            'links' => [['rel' => 'self', 'href' => self::LIVE_API."/v2/payments/refunds/{$id}"], ['rel' => 'up', 'href' => self::LIVE_API."/v2/payments/captures/{$capture['id']}"]]]);
    }

    private function dispute(array $capture, ?string $outcome = null, string $value = '390.00', ?string $custom = null): array
    {
        return array_filter(['dispute_id' => 'PP-D-27803', 'status' => $outcome === null ? 'OPEN' : 'RESOLVED', 'dispute_amount' => ['currency_code' => 'USD', 'value' => $value],
            'disputed_transactions' => [array_filter(['seller_transaction_id' => $capture['id'], 'custom' => $custom])],
            'dispute_outcome' => $outcome === null ? null : ['outcome_code' => $outcome]]);
    }

    private function refund(Payment $payment, string $amount, ?string $reference = null): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/refunds", ['amount' => $amount, 'reference' => $reference ?? (string) Str::uuid()]);
    }

    private function refunded(Payment $payment): string
    {
        return (string) BigDecimal::of($payment->fresh()->refunded)->toScale(2);
    }

    /** The operator charges in another currency. */
    private function chargeIn(string $currency): void
    {
        app(Settings::class)->put('pricing.currency', $currency);
        PriceList::query()->whereKey('standard')->firstOrFail()->forceFill(['definition' => ['currency' => $currency] + PriceList::query()->whereKey('standard')->firstOrFail()->definition])->save();
    }

    public function test_it_is_a_way_to_pay_drawn_from_its_own_form_with_no_page_change(): void
    {
        $driver = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('drivers'))->firstWhere('id', 'paypal');
        $this->assertSame(['PayPal', true, []], [$driver['name'], $driver['online'], $driver['methods']]);
        $this->assertSame(['clientId', 'clientSecret', 'webhookId'], array_column($driver['fields'], 'name'));
        $this->assertSame(['text', 'secret', 'text'], array_column($driver['fields'], 'type'));
    }

    public function test_keys_are_tried_where_their_mode_says_and_refused_in_paypals_words(): void
    {
        $add = fn (string $mode, array $key) => $this->add('paypal', $mode, ['clientId' => $key[0], 'clientSecret' => $key[1], 'webhookId' => self::WEBHOOK_ID]);
        $add('live', ['live-client', 'wrong'])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Client Authentication failed');
        $add('live', self::SANDBOX)->assertStatus(422);
        $add('test', self::LIVE)->assertStatus(422);
        $this->assertSame(0, PaymentGateway::query()->where('driver', 'paypal')->count());

        $this->sent = [];
        $add('test', self::SANDBOX)->assertCreated();
        $this->assertStringStartsWith(self::SANDBOX_API, $this->sent[0]['request']->url());
        $this->sent = [];
        $add('live', self::LIVE)->assertCreated();
        $this->assertStringStartsWith(self::LIVE_API, $this->sent[0]['request']->url());
    }

    public function test_saved_keys_are_never_answered_and_the_page_says_where_events_go(): void
    {
        $gateway = $this->gateway();
        $saved = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('gateways'))->firstWhere('id', $gateway->id);
        $this->assertSame((new PayPalDriver)->webhookEvents(), $saved['webhookEvents']);
        $this->assertSame(self::SITE."/api/kit/payments/webhooks/{$gateway->id}", $saved['webhookUrl']);
        $this->assertStringNotContainsString(self::LIVE[1], json_encode($saved));
    }

    public function test_a_secret_left_empty_is_kept_and_keys_are_tried_afresh_even_with_a_token_kept(): void
    {
        $gateway = $this->gateway();
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['values' => ['clientId' => self::LIVE[0], 'clientSecret' => '', 'webhookId' => 'WH-NEW']])->assertOk();
        $this->assertSame([self::LIVE[1], 'WH-NEW'], [$gateway->fresh()->config['clientSecret'], $gateway->fresh()->config['webhookId']]);

        $this->started($gateway->fresh());
        $issued = $this->tokensIssued;
        $this->tokensRefused = true;
        try {
            (new PayPalDriver)->verify($gateway->fresh());
            $this->fail('keys PayPal now refuses passed on a kept token');
        } catch (PaymentFailed $e) {
            $this->assertSame('Client Authentication failed', $e->getMessage());
        }
        $this->assertSame($issued, $this->tokensIssued);
    }

    public function test_a_token_answer_that_holds_no_token_tells_staff_and_starts_nothing(): void
    {
        $gateway = $this->gateway();
        Cache::flush();
        $this->tokensSayNothing = true;
        $this->topUp($gateway, '500')->assertStatus(503);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->firstWhere('status', 'failed');
        $this->assertSame(__('payments.gateway_unclear'), $row['error']);
    }

    public function test_it_is_offered_only_in_a_currency_paypal_takes(): void
    {
        $gateway = $this->gateway();
        $this->assertSame(true, collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('gateways'))->firstWhere('id', $gateway->id)['takesCurrency']);
        $this->assertSame([$gateway->id], array_column($this->actingAs($this->ana)->getJson('/api/kit/console/payments')->json('gateways'), 'id'));

        $this->chargeIn('INR');
        $this->assertSame(false, collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('gateways'))->firstWhere('id', $gateway->id)['takesCurrency']);
        $this->assertSame([], $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->json('gateways'));
    }

    public function test_its_token_is_kept_encrypted_until_near_its_end_and_never_outlives_the_keys(): void
    {
        $gateway = $this->gateway();
        $issued = $this->tokensIssued;
        $this->started($gateway);
        $this->started($gateway);
        $this->assertSame($issued + 1, $this->tokensIssued, 'one token for both calls');
        $key = 'kit.payments.token.'.hash('sha256', json_encode([$gateway->id, $gateway->mode, $gateway->fresh()->config]));
        $kept = Cache::get($key);
        $this->assertIsString($kept);
        $this->assertStringNotContainsString('token-', $kept, 'encrypted');

        $this->travel(32400 - config('kit.payments.token_margin_seconds') + 1)->seconds();
        $this->started($gateway);
        $this->assertSame($issued + 2, $this->tokensIssued, 'asked again before it ran out');

        $issued = $this->tokensIssued;
        $gateway->forceFill(['config' => ['clientId' => self::LIVE[0], 'clientSecret' => self::LIVE[1], 'webhookId' => 'another-webhook']])->save();
        $this->started($gateway);
        $this->assertSame($issued + 1, $this->tokensIssued, 'changed settings never use an old token');
    }

    public function test_a_payer_goes_to_an_order_that_carries_the_payment_and_comes_back_here(): void
    {
        $gateway = $this->gateway();
        $started = $this->topUp($gateway, '500')->assertCreated();
        $id = $started->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $order = $this->orders[$payment->gateway_ref];
        $this->assertSame(['redirect', "https://www.paypal.com/checkoutnow?token={$payment->gateway_ref}"], [$started->json('checkout.kind'), $started->json('checkout.url')]);
        $this->assertSame('CAPTURE', $order['intent']);
        $unit = $order['purchase_units'][0];
        $this->assertSame([$id, $id, ['currency_code' => 'USD', 'value' => '500.00']], [$unit['reference_id'], $unit['custom_id'], $unit['amount']]);
        $context = $order['payment_source']['paypal']['experience_context'];
        $back = self::SITE."/api/kit/payments/{$id}/return";
        $this->assertSame([$back, $back, 'NO_SHIPPING', 'PAY_NOW'], [$context['return_url'], $context['cancel_url'], $context['shipping_preference'], $context['user_action']]);
        $call = $this->sentTo('POST', '/v2/checkout/orders')[0]['request'];
        $this->assertSame([$id, 'return=representation'], [$call->header('PayPal-Request-Id')[0], $call->header('Prefer')[0]]);
        $this->assertSame([], $this->entries(), 'nothing credited until PayPal says so');
    }

    public function test_an_older_approve_link_is_followed_too_and_an_order_with_neither_is_refused(): void
    {
        $this->approvalRel = 'approve';
        $gateway = $this->gateway();
        $this->assertStringContainsString('paypal.com', $this->topUp($gateway, '500')->assertCreated()->json('checkout.url'));

        $this->approvalRel = 'self';
        $this->topUp($gateway, '500')->assertStatus(503);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->firstWhere('status', 'failed');
        $this->assertSame(__('payments.gateway_unclear'), $row['error']);
    }

    public function test_nothing_moves_until_approved_and_coming_back_then_captures_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->comeBack($payment->id, ['token' => $payment->gateway_ref, 'PayerID' => 'forged']);
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);
        $this->assertSame([], $this->sentTo('POST', "/v2/checkout/orders/{$payment->gateway_ref}/capture"), 'not approved: not captured, whatever the return says');

        $this->approves($payment);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '500.00', '500.00'], [$payment->fresh()->status, $payment->fresh()->received, $this->balance()]);
        $captures = $this->sentTo('POST', "/v2/checkout/orders/{$payment->gateway_ref}/capture");
        $this->assertCount(1, $captures);
        $this->assertSame("capture-{$payment->gateway_ref}", $captures[0]['request']->header('PayPal-Request-Id')[0]);
        $this->assertSame('{}', $captures[0]['request']->body());
        $this->comeBack($payment->id);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_a_payer_who_never_comes_back_is_captured_by_kit_payments(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->approves($payment);
        $this->travel((new PayPalDriver)->checkAfterMinutes() - 1)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame('open', $payment->fresh()->status, 'not yet its time');
        $this->travel(2)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
    }

    public function test_coming_back_to_an_order_already_captured_credits_it_without_capturing_again(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->paidWithoutReturn($payment);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
        $this->assertSame([], $this->sentTo('POST', "/v2/checkout/orders/{$payment->gateway_ref}/capture"));
    }

    public function test_a_capture_still_pending_waits_and_one_declined_fails(): void
    {
        $gateway = $this->gateway();
        $this->captureStatus = 'PENDING';
        $pending = $this->started($gateway);
        $this->approves($pending);
        $this->comeBack($pending->id);
        $this->assertSame(['open', []], [$pending->fresh()->status, $this->entries()]);

        $capture = $this->orders[$pending->gateway_ref]['purchase_units'][0]['payments']['captures'][0];
        $this->deliver($gateway, 'PAYMENT.CAPTURE.COMPLETED', ['status' => 'COMPLETED'] + $capture)->assertOk();
        $this->assertSame(['paid', '500.00'], [$pending->fresh()->status, $this->balance()], 'its completion comes as an event');

        $this->captureStatus = 'DECLINED';
        $declined = $this->started($gateway);
        $this->approves($declined);
        $this->comeBack($declined->id);
        $this->assertSame('failed', $declined->fresh()->status);

        $denied = $this->started($gateway);
        $this->deliver($gateway, 'PAYMENT.CAPTURE.DENIED', ['custom_id' => $denied->id, 'status' => 'DECLINED'])->assertOk();
        $this->assertSame('failed', $denied->fresh()->status);
    }

    public function test_an_order_voided_at_paypal_fails_here(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->orders[$payment->gateway_ref]['status'] = 'VOIDED';
        $this->comeBack($payment->id);
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_an_event_counts_only_when_paypal_itself_verifies_its_exact_bytes(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $capture = $this->paidWithoutReturn($payment);
        // Spaces and a number's trailing zero that parsing and writing again would lose.
        $body = '{ "id": "WH-1", "event_type": "PAYMENT.CAPTURE.COMPLETED",  "resource": '.json_encode($capture).', "extra": 1.10 }';

        $this->deliverBody($gateway, $body, 'forged')->assertUnauthorized();
        $this->deliverBody($gateway, $body, 'good-signature', headers: false)->assertUnauthorized();
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->deliverBody($gateway, $body)->assertOk();
        $this->assertStringEndsWith(',"webhook_event":'.$body.'}', (string) $this->verifiedBody);
        $sent = json_decode((string) $this->verifiedBody, true);
        $this->assertSame([self::WEBHOOK_ID, 'SHA256withRSA', 'tr-1', '2026-11-03T10:00:00Z'], [$sent['webhook_id'], $sent['auth_algo'], $sent['transmission_id'], $sent['transmission_time']]);
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);

        $asked = count($this->sentTo('POST', '/v1/notifications/verify-webhook-signature'));
        $this->deliverBody($gateway, 'not json')->assertUnauthorized();
        $this->assertCount($asked, $this->sentTo('POST', '/v1/notifications/verify-webhook-signature'), 'not an event: nothing to ask PayPal');
    }

    /** The money was captured, and the kit has not heard yet. */
    private function paidWithoutReturn(Payment $payment): array
    {
        return $this->capture($payment->gateway_ref, "elsewhere-{$payment->gateway_ref}")['purchase_units'][0]['payments']['captures'][0];
    }

    public function test_the_approved_event_captures_and_credits_once_by_its_event_id(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $order = $this->approves($payment);

        $this->deliver($gateway, 'CHECKOUT.ORDER.APPROVED', $order, eventId: 'WH-same')->assertOk();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
        $this->deliver($gateway, 'CHECKOUT.ORDER.APPROVED', $order, eventId: 'WH-same')->assertOk();
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->deliver($gateway, 'CHECKOUT.ORDER.APPROVED', $order, eventId: 'WH-later')->assertOk();
        $this->assertCount(1, $this->sentTo('POST', "/v2/checkout/orders/{$payment->gateway_ref}/capture"), 'an order already captured is not captured again');
        $this->deliver($gateway, 'PAYMENT.CAPTURE.COMPLETED', $this->orders[$payment->gateway_ref]['purchase_units'][0]['payments']['captures'][0], eventId: 'WH-next')->assertOk();
        $this->assertCount(1, $this->entries());
        $this->assertSame('500.00', $this->balance());
    }

    public function test_another_amount_waits_for_staff_and_another_currency_too(): void
    {
        $gateway = $this->gateway();
        $less = $this->started($gateway);
        $capture = $this->paidWithoutReturn($less);
        $capture['amount']['value'] = '400.00';
        $this->deliver($gateway, 'PAYMENT.CAPTURE.COMPLETED', $capture)->assertOk();
        $this->assertSame(['review', '400.00', []], [$less->fresh()->status, $less->fresh()->received, $this->entries()]);

        $other = $this->started($gateway);
        $capture = $this->paidWithoutReturn($other);
        $capture['amount']['currency_code'] = 'EUR';
        $this->deliver($gateway, 'PAYMENT.CAPTURE.COMPLETED', $capture)->assertOk();
        $this->assertSame(['review', []], [$other->fresh()->status, $this->entries()]);
    }

    public function test_a_currency_paypal_takes_with_no_decimals_is_sent_without_them(): void
    {
        // ISO 4217 gives HUF and TWD two places, and whether ICU does depends on its version.
        config(['kit.payments.currency_decimals' => ['HUF' => 2, 'TWD' => 2]]);
        $gateway = $this->gateway();
        foreach (['JPY', 'HUF', 'TWD'] as $currency) {
            $this->chargeIn($currency);
            $payment = $this->started($gateway);
            $this->assertSame('500', $this->orders[$payment->gateway_ref]['purchase_units'][0]['amount']['value'], $currency);
            $this->paid($payment);
            $this->assertSame('paid', $payment->fresh()->status, "{$currency}: what comes back is the amount asked");
        }

        $this->chargeIn('HUF');
        $this->topUp($gateway, '500.50')->assertStatus(503);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->firstWhere('status', 'failed');
        $this->assertSame('PayPal takes only whole amounts in HUF.', $row['error']);
    }

    public function test_staff_refund_the_capture_with_one_request_id_per_click_counted_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $capture = $this->paid($payment);
        $reference = (string) Str::uuid();

        $this->refund($payment, '100', $reference)->assertOk();
        $call = $this->sentTo('POST', "/v2/payments/captures/{$capture['id']}/refund")[0];
        $this->assertSame($reference, $call['request']->header('PayPal-Request-Id')[0]);
        $this->assertSame(['amount' => ['currency_code' => 'USD', 'value' => '100.00'], 'custom_id' => $payment->id], $call['body']);
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);
        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertSame('100.00', $this->refunded($payment), 'one click is one refund');

        $made = $this->refunds[$reference];
        $before = count($this->sent);
        $this->deliver($gateway, 'PAYMENT.CAPTURE.REFUNDED', $made)->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'PayPal telling of the same refund counts nothing more');
        $this->assertCount($before + 1, $this->sent, 'only its signature was checked: a refund the kit made says its payment');
    }

    public function test_a_refund_paypal_answers_as_failed_or_cannot_find_counts_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->paid($payment);
        $this->refundStatus = 'FAILED';
        $this->refund($payment, '100')->assertStatus(502)->assertJsonFragment(['message' => __('payments.refund_failed', ['reason' => __('payments.gateway_refund_failed')])]);
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);

        $this->refundStatus = 'CANCELLED';
        $this->refund($payment, '100')->assertStatus(502);
        unset($this->orders[$payment->gateway_ref]['purchase_units'][0]['payments']);
        $this->refund($payment, '100')->assertStatus(502)->assertJsonFragment(['message' => __('payments.refund_failed', ['reason' => __('payments.gateway_unclear')])]);
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_or_reversal_made_at_paypal_finds_its_payment_through_the_capture(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $capture = $this->paid($payment);

        $this->deliver($gateway, 'PAYMENT.CAPTURE.REFUNDED', $this->refundEvent($capture, 'REF1', '50.00'))->assertOk();
        $this->assertCount(1, $this->sentTo('GET', "/v2/payments/captures/{$capture['id']}"));
        $this->assertSame(['50.00', '450.00'], [$this->refunded($payment), $this->balance()]);
        $this->deliver($gateway, 'PAYMENT.CAPTURE.REFUNDED', $this->refundEvent($capture, 'REF1', '50.00'))->assertOk();
        $this->assertSame('450.00', $this->balance(), 'counted once by its own id');

        $this->deliver($gateway, 'PAYMENT.CAPTURE.REVERSED', $this->refundEvent($capture, 'REV1', '25.00'))->assertOk();
        $this->assertSame(['75.00', '425.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_dispute_holds_and_each_outcome_the_seller_keeps_gives_it_back(): void
    {
        foreach (['RESOLVED_SELLER_FAVOUR', 'RESOLVED_WITH_PAYOUT', 'CANCELED_BY_BUYER', 'DENIED'] as $outcome) {
            $gateway = $this->gateway();
            $payment = $this->started($gateway);
            $capture = $this->paid($payment);
            $before = BigDecimal::of($this->balance());
            $this->deliver($gateway, 'CUSTOMER.DISPUTE.CREATED', ['dispute_id' => "D-{$outcome}"] + $this->dispute($capture))->assertOk();
            $this->assertSame('open', $payment->fresh()->dispute, $outcome);
            $this->assertTrue($before->minus('390')->isEqualTo($this->balance()), $outcome);
            $this->deliver($gateway, 'CUSTOMER.DISPUTE.RESOLVED', ['dispute_id' => "D-{$outcome}"] + $this->dispute($capture, $outcome))->assertOk();
            $this->assertSame('won', $payment->fresh()->dispute, $outcome);
            $this->assertTrue($before->isEqualTo($this->balance()), $outcome);
        }
    }

    public function test_a_dispute_the_buyer_wins_comes_off_and_no_outcome_changes_nothing(): void
    {
        foreach (['RESOLVED_BUYER_FAVOUR', 'ACCEPTED'] as $outcome) {
            $gateway = $this->gateway();
            $payment = $this->started($gateway);
            $capture = $this->paid($payment);
            $before = BigDecimal::of($this->balance());
            $this->deliver($gateway, 'CUSTOMER.DISPUTE.RESOLVED', ['dispute_id' => "D-{$outcome}"] + $this->dispute($capture, $outcome))->assertOk();
            $this->assertSame('lost', $payment->fresh()->dispute, $outcome);
            $this->assertTrue($before->minus('390')->isEqualTo($this->balance()), $outcome);
        }

        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $capture = $this->paid($payment);
        $this->deliver($gateway, 'CUSTOMER.DISPUTE.RESOLVED', $this->dispute($capture, 'NONE'))->assertOk();
        $this->assertNull($payment->fresh()->dispute);
    }

    public function test_a_dispute_that_names_the_payment_needs_no_asking(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $capture = $this->paid($payment);
        $this->deliver($gateway, 'CUSTOMER.DISPUTE.CREATED', $this->dispute($capture, null, '390.00', $payment->id))->assertOk();
        $this->assertSame([], $this->sentTo('GET', "/v2/payments/captures/{$capture['id']}"));
        $this->assertSame('open', $payment->fresh()->dispute);
    }

    public function test_events_about_payments_that_are_not_the_kits_change_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $capture = $this->paid($payment);
        $mine = $this->captures[$capture['id']]['custom_id'];
        $this->captures[$capture['id']]['custom_id'] = 'an invoice of another shop';
        $this->deliver($gateway, 'PAYMENT.CAPTURE.REFUNDED', $this->refundEvent($capture, 'REF9', '50.00'))->assertOk();
        $this->deliver($gateway, 'CUSTOMER.DISPUTE.CREATED', $this->dispute($capture))->assertOk();
        $this->assertSame(['0.00', null, '500.00'], [$this->refunded($payment), $payment->fresh()->dispute, $this->balance()]);
        $this->captures[$capture['id']]['custom_id'] = $mine;

        $this->deliver($gateway, 'BILLING.SUBSCRIPTION.CREATED', ['id' => 'I-1'])->assertOk();
        $this->deliver($gateway, 'CHECKOUT.ORDER.APPROVED', ['status' => 'APPROVED'])->assertOk();
        $noId = $this->refundEvent($capture, 'REF10', '50.00');
        unset($noId['id']);
        $this->deliver($gateway, 'PAYMENT.CAPTURE.REFUNDED', $noId)->assertOk();
        $noDispute = $this->dispute($capture);
        unset($noDispute['dispute_id']);
        $this->deliver($gateway, 'CUSTOMER.DISPUTE.CREATED', $noDispute)->assertOk();
        $this->assertSame(['0.00', null, '500.00'], [$this->refunded($payment), $payment->fresh()->dispute, $this->balance()], 'an event it cannot tie to a refund or dispute changes nothing');
    }

    public function test_an_order_paypal_will_not_make_tells_staff_its_words_and_the_customer_only_that_it_is_down(): void
    {
        $gateway = $this->gateway();
        Cache::flush();
        $this->tokensRefused = true;
        $this->topUp($gateway, '500')->assertStatus(503)->assertExactJson(['message' => __('payments.gateway_down')]);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->first();
        $this->assertSame(['failed', 'Client Authentication failed'], [$row['status'], $row['error']]);
    }

    public function test_a_test_payment_runs_through_the_sandbox_and_credits_no_one(): void
    {
        $gateway = $this->gateway('test');
        $id = $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$gateway->id}/test", ['amount' => '10'])->assertCreated()->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->assertStringStartsWith(self::SANDBOX_API, $this->sentTo('POST', '/v2/checkout/orders')[0]['request']->url());
        $this->paid($payment);
        $this->assertSame(['paid', []], [$payment->fresh()->status, $this->entries()]);
    }

    public function test_the_address_and_timings_are_settings(): void
    {
        config(['kit.payments.paypal.api_urls.live' => 'https://paypal.standin.example/']);
        Http::swap(new Factory);
        Http::fake(['paypal.standin.example/*' => Http::response(['access_token' => 't', 'expires_in' => 3600])]);
        $gateway = new PaymentGateway;
        $gateway->forceFill(['mode' => 'live', 'config' => ['clientId' => 'a', 'clientSecret' => 'b', 'webhookId' => 'c']]);
        (new PayPalDriver)->verify($gateway);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://paypal.standin.example/v1/oauth2/token');
    }
}
