<?php

namespace Tests\Feature\Kit;

use App\Kit\Payments\Drivers\StripeDriver;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TopsUpThroughAGateway;
use Tests\TestCase;

/**
 * Stripe (App\Kit\Payments\Drivers\StripeDriver) through the kit's real routes, against a fake of its documented API:
 * hosted Checkout Sessions, signed snapshot events, refunds with an idempotency key, disputes. The bodies follow the
 * samples in Stripe's documents read 11-03. They are not recordings from a real account: none has been run yet.
 */
class StripeGatewayTest extends TestCase
{
    use RefreshDatabase;
    use TopsUpThroughAGateway;

    private const LIVE_KEY = 'sk_live_acmeAcmeAcme';

    private const TEST_KEY = 'sk_test_acmeAcmeAcme';

    private const WEBHOOK_SECRET = 'whsec_acmeAcmeAcme';

    private const API = 'https://api.stripe.com';

    /** @var array<string, array> sessions by id */
    private array $sessions = [];

    /** @var array<string, array> PaymentIntents by id */
    private array $intents = [];

    /** @var array<string, array> refunds by idempotency key */
    private array $refunds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->installWith(fn (Request $r) => $this->stripe($r));
    }

    private function stripe(Request $r): mixed
    {
        if (! str_starts_with($r->url(), self::API)) {
            return null;
        }
        $this->record($r);
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if (! in_array($r->header('Authorization')[0] ?? '', ['Bearer '.self::LIVE_KEY, 'Bearer '.self::TEST_KEY, 'Bearer rk_live_acmeAcmeAcme'], true)) {
            return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided']], 401);
        }
        if ($path === '/v1/checkout/sessions' && $r->method() === 'GET') {
            return Http::response(['object' => 'list', 'data' => [], 'has_more' => false]);
        }
        if ($path === '/v1/checkout/sessions') {
            $data = $r->data();
            $item = $data['line_items'][0]['price_data'];
            $id = 'cs_test_'.Str::random(20);
            $session = ['id' => $id, 'object' => 'checkout.session', 'amount_total' => (int) $item['unit_amount'], 'currency' => $item['currency'], 'client_reference_id' => $data['client_reference_id'],
                'metadata' => $data['metadata'], 'mode' => 'payment', 'payment_intent' => null, 'payment_status' => 'unpaid', 'status' => 'open', 'url' => "https://checkout.stripe.com/c/pay/{$id}"];
            $this->sessions[$id] = $session;

            return Http::response($session);
        }
        if (preg_match('#^/v1/checkout/sessions/(cs_\w+)$#', $path, $m) && isset($this->sessions[$m[1]])) {
            return Http::response($this->sessions[$m[1]]);
        }
        if (preg_match('#^/v1/payment_intents/(pi_\w+)$#', $path, $m) && isset($this->intents[$m[1]])) {
            return Http::response($this->intents[$m[1]]);
        }
        if ($path === '/v1/refunds') {
            $key = $r->header('Idempotency-Key')[0];
            $this->refunds[$key] ??= ['id' => 're_'.Str::random(14), 'object' => 'refund', 'amount' => (int) $r->data()['amount'], 'currency' => 'inr', 'metadata' => $r->data()['metadata'] ?? [],
                'payment_intent' => $r->data()['payment_intent'], 'status' => 'succeeded'];

            return Http::response($this->refunds[$key]);
        }

        return Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'No such object']], 404);
    }

    private function gateway(string $mode = 'live'): PaymentGateway
    {
        $this->add('stripe', $mode, ['secretKey' => $mode === 'live' ? self::LIVE_KEY : self::TEST_KEY, 'webhookSecret' => self::WEBHOOK_SECRET])->assertCreated();

        return PaymentGateway::query()->where('driver', 'stripe')->latest('position')->firstOrFail();
    }

    private function started(PaymentGateway $gateway, string $amount = '500'): Payment
    {
        return Payment::query()->findOrFail($this->topUp($gateway, $amount)->assertCreated()->json('payment.id'));
    }

    /** The payer pays on Stripe's page: answers the session as it now stands. */
    private function pays(Payment $payment, string $status = 'paid', ?int $total = null): array
    {
        $session = &$this->sessions[$payment->gateway_ref];
        $intent = 'pi_'.substr($session['id'], 8);
        $session['payment_status'] = $status;
        $session['status'] = 'complete';
        $session['payment_intent'] = $intent;
        $session['amount_total'] = $total ?? $session['amount_total'];
        $this->intents[$intent] = ['id' => $intent, 'object' => 'payment_intent', 'metadata' => ['kit_payment_id' => $payment->id], 'status' => 'succeeded'];

        return $session;
    }

    private function deliver(PaymentGateway $gateway, string $type, array $object, ?string $signature = null, ?string $eventId = null): TestResponse
    {
        $body = json_encode(['id' => $eventId ?? 'evt_'.Str::random(20), 'object' => 'event', 'api_version' => '2026-10-28.endive', 'created' => time(), 'livemode' => false, 'type' => $type, 'data' => ['object' => $object]]);

        return $this->postSigned($gateway, $body, $signature ?? $this->sign($body));
    }

    private function postSigned(PaymentGateway $gateway, string $body, string $signature): TestResponse
    {
        return $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $signature], $body);
    }

    private function sign(string $body, string $secret = self::WEBHOOK_SECRET, ?int $at = null): string
    {
        $at ??= time();

        return "t={$at},v1=".hash_hmac('sha256', "{$at}.{$body}", $secret).',v0='.hash_hmac('sha256', "{$at}.{$body}", 'an older scheme');
    }

    private function refundObject(array $session, string $id, int $amount, string $status = 'succeeded', array $metadata = []): array
    {
        return ['id' => $id, 'object' => 'refund', 'amount' => $amount, 'charge' => 'ch_1NirD82eZvKYlo2CIvbtLWuY', 'currency' => 'inr', 'metadata' => $metadata, 'payment_intent' => $session['payment_intent'], 'status' => $status];
    }

    private function disputeObject(array $session, string $status, int $amount = 39000): array
    {
        return ['id' => 'du_1MtJUT2eZvKYlo2CNaw2HvEv', 'object' => 'dispute', 'amount' => $amount, 'charge' => 'ch_1AZtxr2eZvKYlo2CJDX8whov', 'currency' => 'inr', 'metadata' => [], 'payment_intent' => $session['payment_intent'], 'status' => $status];
    }

    private function refund(Payment $payment, string $amount, ?string $reference = null): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/refunds", ['amount' => $amount, 'reference' => $reference ?? (string) Str::uuid()]);
    }

    private function refunded(Payment $payment): string
    {
        return (string) BigDecimal::of($payment->fresh()->refunded)->toScale(2);
    }

    public function test_it_is_a_way_to_pay_drawn_from_its_own_form_with_no_page_change(): void
    {
        $driver = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('drivers'))->firstWhere('id', 'stripe');
        $this->assertSame(['Stripe', true, []], [$driver['name'], $driver['online'], $driver['methods']]);
        $this->assertSame(['secretKey', 'webhookSecret'], array_column($driver['fields'], 'name'));
        $this->assertSame(['secret', 'secret'], array_column($driver['fields'], 'type'));
    }

    public function test_keys_are_tried_and_refused_in_stripes_words_and_the_wrong_modes_keys_are_refused(): void
    {
        $add = fn (string $mode, string $key, string $secret = self::WEBHOOK_SECRET) => $this->add('stripe', $mode, ['secretKey' => $key, 'webhookSecret' => $secret]);
        $add('live', 'sk_live_wrong')->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Invalid API Key provided');
        $this->sent = [];
        $add('live', self::TEST_KEY)->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Test keys, and this way to pay is set to Live.');
        $add('test', self::LIVE_KEY)->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Live keys, and this way to pay is set to Test.');
        $this->assertSame([], $this->sent, 'refused before any call');
        $add('live', 'pk_live_a_publishable_key')->assertStatus(422)->assertJsonValidationErrors(['values.secretKey']);
        $add('live', self::LIVE_KEY, 'not_a_signing_secret')->assertStatus(422)->assertJsonValidationErrors(['values.webhookSecret']);
        $this->assertSame(0, PaymentGateway::query()->where('driver', 'stripe')->count());

        $add('live', 'rk_live_acmeAcmeAcme')->assertCreated();
    }

    public function test_saved_keys_are_never_answered_and_the_page_says_where_events_go(): void
    {
        $gateway = $this->gateway();
        $saved = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('gateways'))->firstWhere('id', $gateway->id);
        $this->assertSame((new StripeDriver)->webhookEvents(), $saved['webhookEvents']);
        $this->assertSame(self::SITE."/api/kit/payments/webhooks/{$gateway->id}", $saved['webhookUrl']);
        $text = json_encode($saved);
        $this->assertStringNotContainsString(self::LIVE_KEY, $text);
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $text);
    }

    public function test_a_payer_goes_to_a_session_that_carries_the_payment_and_comes_back_here(): void
    {
        $gateway = $this->gateway();
        $started = $this->topUp($gateway, '500')->assertCreated();
        $id = $started->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->assertSame(['redirect', $this->sessions[$payment->gateway_ref]['url']], [$started->json('checkout.kind'), $started->json('checkout.url')]);
        $call = $this->sentTo('POST', '/v1/checkout/sessions')[0];
        $back = self::SITE."/api/kit/payments/{$id}/return";
        $this->assertSame(['payment', $id, $back, $back], [$call['body']['mode'], $call['body']['client_reference_id'], $call['body']['success_url'], $call['body']['cancel_url']]);
        $item = $call['body']['line_items'][0];
        $this->assertSame(['1', 'inr', '50000'], [(string) $item['quantity'], $item['price_data']['currency'], (string) $item['price_data']['unit_amount']]);
        $this->assertSame(['kit_payment_id' => $id], $call['body']['metadata']);
        $this->assertSame(['kit_payment_id' => $id], $call['body']['payment_intent_data']['metadata']);
        $this->assertSame('ana@dental.example', $call['body']['customer_email']);
        $this->assertStringContainsString('application/x-www-form-urlencoded', $call['request']->header('Content-Type')[0]);
        $this->assertSame('Bearer '.self::LIVE_KEY, $call['request']->header('Authorization')[0]);
        $this->assertSame(config('kit.payments.stripe.api_version'), $call['request']->header('Stripe-Version')[0]);
        $this->assertSame([], $this->entries(), 'nothing credited until Stripe says so');
    }

    public function test_coming_back_asks_stripe_and_what_the_return_says_is_never_believed(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->comeBack($payment->id, ['payment_status' => 'paid', 'session_id' => 'cs_forged']);
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->pays($payment);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '500.00', '500.00'], [$payment->fresh()->status, $payment->fresh()->received, $this->balance()]);
        $this->comeBack($payment->id);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_a_signature_must_be_stripes_recent_and_in_its_current_scheme(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $body = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
        $at = time();
        $hash = fn (int $when, string $secret = self::WEBHOOK_SECRET) => hash_hmac('sha256', "{$when}.{$body}", $secret);

        $this->postSigned($gateway, $body, 'forged')->assertUnauthorized();
        $this->postSigned($gateway, $body, '')->assertUnauthorized();
        $this->postSigned($gateway, $body, "t={$at},v1=".$hash($at, 'another secret'))->assertUnauthorized();
        $this->postSigned($gateway, $body, "t={$at},v0=".$hash($at))->assertUnauthorized();
        $this->postSigned($gateway, $body, 'v1='.$hash($at))->assertUnauthorized();
        $this->postSigned($gateway, $body, "t={$at}x,v1=".hash_hmac('sha256', "{$at}x.{$body}", self::WEBHOOK_SECRET))->assertUnauthorized();
        $stale = $at - config('kit.payments.stripe.signature_tolerance_seconds') - 5;
        $this->postSigned($gateway, $body, "t={$stale},v1=".$hash($stale))->assertUnauthorized();
        $this->postSigned($gateway, $body.' ', "t={$at},v1=".$hash($at))->assertUnauthorized();
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->postSigned($gateway, $body, "t={$at},v1=".$hash($at, 'a secret being rolled').',v1='.$hash($at))->assertOk();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
        $this->postSigned($gateway, $body, "t={$at},v1=".$hash($at))->assertOk();
        $this->assertCount(1, $this->entries(), 'the same event twice credits once');
    }

    public function test_a_delayed_method_counts_only_when_it_is_paid(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment, 'unpaid');
        $this->deliver($gateway, 'checkout.session.completed', $session)->assertOk();
        $this->comeBack($payment->id);
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $session = $this->pays($payment);
        $this->deliver($gateway, 'checkout.session.async_payment_succeeded', $session)->assertOk();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);

        $failed = $this->started($gateway);
        $session = $this->pays($failed, 'unpaid');
        $this->deliver($gateway, 'checkout.session.async_payment_failed', $session)->assertOk();
        $this->assertSame('failed', $failed->fresh()->status);
    }

    public function test_another_amount_waits_for_staff_and_another_currency_too(): void
    {
        $gateway = $this->gateway();
        $less = $this->started($gateway);
        $this->deliver($gateway, 'checkout.session.completed', $this->pays($less, 'paid', 40000))->assertOk();
        $this->assertSame(['review', '400.00', []], [$less->fresh()->status, $less->fresh()->received, $this->entries()]);

        $other = $this->started($gateway);
        $session = $this->pays($other);
        $session['currency'] = 'usd';
        $this->deliver($gateway, 'checkout.session.completed', $session)->assertOk();
        $this->assertSame(['review', 'USD', []], [$other->fresh()->status, $other->fresh()->details['receivedCurrency'] ?? null, $this->entries()]);
    }

    public function test_a_session_that_ran_out_is_left_for_the_kit_to_give_up(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->sessions[$payment->gateway_ref]['status'] = 'expired';
        $this->comeBack($payment->id);
        $this->assertSame('open', $payment->fresh()->status);
        $this->travel(config('kit.payments.expire_hours') + 1)->hours();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame('expired', $payment->fresh()->status);
    }

    public function test_kit_payments_asks_about_an_open_one_after_its_wait(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->travel((new StripeDriver)->checkAfterMinutes() - 1)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame('open', $payment->fresh()->status, 'not yet its time');
        $this->travel(2)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
    }

    public function test_a_thin_event_carries_no_object_and_changes_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $body = json_encode(['id' => 'evt_thin', 'object' => 'v2.core.event', 'type' => 'checkout.session.completed', 'related_object' => ['id' => $payment->gateway_ref, 'type' => 'checkout.session']]);
        $this->postSigned($gateway, $body, $this->sign($body))->assertOk();
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);
    }

    public function test_staff_refund_the_paymentintent_with_one_key_per_click_counted_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $this->comeBack($payment->id);
        $reference = (string) Str::uuid();

        $this->refund($payment, '100', $reference)->assertOk();
        $call = $this->sentTo('POST', '/v1/refunds')[0];
        $this->assertSame($reference, $call['request']->header('Idempotency-Key')[0]);
        $this->assertSame([$session['payment_intent'], '10000', ['kit_payment_id' => $payment->id]], [$call['body']['payment_intent'], (string) $call['body']['amount'], $call['body']['metadata']]);
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);
        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertSame('100.00', $this->refunded($payment), 'one click is one refund');

        $made = $this->refunds[$reference];
        $before = count($this->sent);
        $this->deliver($gateway, 'refund.created', $made)->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'Stripe telling of the same refund counts nothing more');
        $this->assertCount($before, $this->sent, 'a refund the kit made says its payment: nothing to ask');
    }

    public function test_a_refund_stripe_answers_as_failed_counts_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->comeBack($payment->id);
        $reference = (string) Str::uuid();
        $this->refunds[$reference] = ['id' => 're_failed', 'object' => 'refund', 'amount' => 10000, 'currency' => 'inr', 'status' => 'failed'];
        $this->refund($payment, '100', $reference)->assertStatus(502);
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_made_in_stripes_dashboard_finds_its_payment_through_the_paymentintent(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $this->comeBack($payment->id);

        $this->deliver($gateway, 'refund.created', $this->refundObject($session, 're_dashboard', 5000))->assertOk();
        $this->assertCount(1, $this->sentTo('GET', "/v1/payment_intents/{$session['payment_intent']}"));
        $this->assertSame(['50.00', '450.00'], [$this->refunded($payment), $this->balance()]);
        $this->deliver($gateway, 'refund.created', $this->refundObject($session, 're_dashboard', 5000))->assertOk();
        $this->assertSame('450.00', $this->balance(), 'counted once by its own id');
    }

    public function test_a_refund_that_fails_or_is_cancelled_later_goes_back_on_the_wallet_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $this->comeBack($payment->id);

        $this->deliver($gateway, 'refund.created', $this->refundObject($session, 're_a', 5000, 'pending'))->assertOk();
        $this->deliver($gateway, 'refund.updated', $this->refundObject($session, 're_a', 5000, 'succeeded'))->assertOk();
        $this->assertSame('450.00', $this->balance(), 'an update that is no failure changes nothing');
        $this->deliver($gateway, 'refund.failed', $this->refundObject($session, 're_a', 5000, 'failed'))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
        $this->deliver($gateway, 'refund.updated', $this->refundObject($session, 're_a', 5000, 'failed'))->assertOk();
        $this->assertSame('500.00', $this->balance(), 'told twice, given back once');

        $this->deliver($gateway, 'refund.created', $this->refundObject($session, 're_b', 5000, 'pending'))->assertOk();
        $this->assertSame('450.00', $this->balance());
        $this->deliver($gateway, 'refund.updated', $this->refundObject($session, 're_b', 5000, 'canceled'))->assertOk();
        $this->assertSame('500.00', $this->balance());

        $this->deliver($gateway, 'refund.created', $this->refundObject($session, 're_c', 5000, 'failed'))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()], 'created already failed: never counted');
    }

    public function test_an_inquiry_closed_or_a_dispute_prevented_gives_the_held_amount_back_like_one_won(): void
    {
        foreach (['warning_closed', 'prevented'] as $status) {
            $gateway = $this->gateway();
            $payment = $this->started($gateway);
            $session = $this->pays($payment);
            $this->comeBack($payment->id);
            $before = BigDecimal::of($this->balance());
            $this->deliver($gateway, 'charge.dispute.created', $this->disputeObject($session, 'warning_needs_response'))->assertOk();
            $this->assertTrue($before->minus('390')->isEqualTo($this->balance()), $status);
            $this->deliver($gateway, 'charge.dispute.closed', $this->disputeObject($session, $status))->assertOk();
            $this->assertSame('won', $payment->fresh()->dispute, $status);
            $this->assertTrue($before->isEqualTo($this->balance()), $status);
        }
    }

    public function test_a_dispute_closed_as_won_gives_the_held_amount_back(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->deliver($gateway, 'charge.dispute.created', $this->disputeObject($session, 'needs_response'))->assertOk();
        $this->assertSame(['open', '110.00'], [$payment->fresh()->dispute, $this->balance()]);
        $this->deliver($gateway, 'charge.dispute.closed', $this->disputeObject($session, 'won'))->assertOk();
        $this->assertSame(['won', '500.00'], [$payment->fresh()->dispute, $this->balance()]);
    }

    public function test_a_dispute_lost_keeps_what_was_held_and_one_not_opened_here_comes_off(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->deliver($gateway, 'charge.dispute.closed', $this->disputeObject($session, 'lost'))->assertOk();
        $this->assertSame(['lost', '110.00'], [$payment->fresh()->dispute, $this->balance()]);
    }

    public function test_a_closing_status_it_does_not_know_changes_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->deliver($gateway, 'charge.dispute.closed', $this->disputeObject($session, 'under_review'))->assertOk();
        $this->assertSame([null, '500.00'], [$payment->fresh()->dispute, $this->balance()]);
    }

    public function test_events_about_payments_that_are_not_the_kits_change_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $session = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->intents[$session['payment_intent']]['metadata'] = ['order' => 'of another shop'];
        $this->deliver($gateway, 'refund.created', $this->refundObject($session, 're_elsewhere', 5000))->assertOk();
        $this->deliver($gateway, 'charge.dispute.created', $this->disputeObject($session, 'needs_response'))->assertOk();
        $this->assertSame(['0.00', null, '500.00'], [$this->refunded($payment), $payment->fresh()->dispute, $this->balance()]);

        $this->deliver($gateway, 'payment_intent.succeeded', ['id' => 'pi_x'])->assertOk();
        $this->deliver($gateway, 'refund.created', ['object' => 'refund'])->assertOk();
        $this->deliver($gateway, 'charge.dispute.created', ['id' => 'du_x', 'payment_intent' => null])->assertOk();
        $this->intents[$session['payment_intent']]['metadata'] = ['kit_payment_id' => $payment->id];
        $noId = $this->refundObject($session, 're_x', 5000);
        unset($noId['id']);
        $this->deliver($gateway, 'refund.created', $noId)->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()], 'an event it cannot tie to a refund changes nothing');
    }

    public function test_a_session_stripe_will_not_make_tells_staff_its_words_and_the_customer_only_that_it_is_down(): void
    {
        $gateway = $this->gateway();
        $gateway->forceFill(['config' => ['secretKey' => 'sk_live_revoked', 'webhookSecret' => self::WEBHOOK_SECRET]])->save();
        $this->topUp($gateway, '500')->assertStatus(503)->assertExactJson(['message' => __('payments.gateway_down')]);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->first();
        $this->assertSame(['failed', 'Invalid API Key provided'], [$row['status'], $row['error']]);
    }

    public function test_a_test_payment_runs_through_it_and_credits_no_one(): void
    {
        $gateway = $this->gateway('test');
        $id = $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$gateway->id}/test", ['amount' => '10'])->assertCreated()->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->deliver($gateway, 'checkout.session.completed', $this->pays($payment))->assertOk();
        $this->assertSame(['paid', []], [$payment->fresh()->status, $this->entries()]);
    }

    public function test_the_address_and_timings_are_settings(): void
    {
        config(['kit.payments.stripe.api_url' => 'https://stripe.standin.example/', 'kit.payments.stripe.api_version' => '2030-01-01.test']);
        Http::swap(new Factory);
        Http::fake(['stripe.standin.example/*' => Http::response(['data' => []])]);
        $gateway = new PaymentGateway;
        $gateway->forceFill(['mode' => 'live', 'config' => ['secretKey' => 'sk_live_x', 'webhookSecret' => 'whsec_x']]);
        (new StripeDriver)->verify($gateway);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://stripe.standin.example/v1/checkout/sessions') && $r->header('Stripe-Version')[0] === '2030-01-01.test');
    }
}
