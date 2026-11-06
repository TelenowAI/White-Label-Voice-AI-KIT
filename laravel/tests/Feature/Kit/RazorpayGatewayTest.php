<?php

namespace Tests\Feature\Kit;

use App\Kit\Payments\Drivers\RazorpayDriver;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Models\WorkspaceMember;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TopsUpThroughAGateway;
use Tests\TestCase;

/**
 * Razorpay (App\Kit\Payments\Drivers\RazorpayDriver) through the kit's real routes, against a fake of its documented API:
 * Payment Links, webhooks signed with the operator's secret, refunds with a receipt, disputes. The bodies follow the samples
 * in Razorpay's documents read 11-03. They are not recordings from a real account: none has been run yet.
 */
class RazorpayGatewayTest extends TestCase
{
    use RefreshDatabase;
    use TopsUpThroughAGateway;

    private const LIVE_KEY = ['rzp_live_acmeAcmeAcme12', 'live key secret'];

    private const TEST_KEY = ['rzp_test_acmeAcmeAcme12', 'test key secret'];

    private const WEBHOOK_SECRET = 'webhook secret the operator chose';

    private const API = 'https://api.razorpay.com';

    /** @var array<string, array> payment links by id */
    private array $links = [];

    /** @var array<string, array> orders by id */
    private array $orders = [];

    /** @var array<string, list<array>> refunds by payment id */
    private array $refunds = [];

    /** Answer the next refund's call with no answer, though Razorpay did make it. */
    private bool $loseRefundAnswer = false;

    private ?array $refuseLink = null;

    /** Answer a new link without what it should say. */
    private bool $linkSaysNothing = false;

    /** How the next refund's answer says it went. */
    private string $refundStatus = 'processed';

    protected function setUp(): void
    {
        parent::setUp();
        $this->installWith(fn (Request $r) => $this->razorpay($r));
    }

    private function razorpay(Request $r): mixed
    {
        if (! str_starts_with($r->url(), self::API)) {
            return null;
        }
        $this->record($r);
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        [$id, $secret] = array_pad(explode(':', (string) base64_decode(substr($r->header('Authorization')[0] ?? '', 6)), 2), 2, null);
        if (([self::LIVE_KEY[0] => self::LIVE_KEY[1], self::TEST_KEY[0] => self::TEST_KEY[1]][$id] ?? null) !== $secret) {
            return Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Authentication failed']], 401);
        }
        if ($path === '/v1/orders') {
            return Http::response(['entity' => 'collection', 'count' => 0, 'items' => []]);
        }
        if ($path === '/v1/payment_links' && $r->method() === 'POST') {
            if ($this->refuseLink !== null) {
                return Http::response(['error' => $this->refuseLink], 400);
            }
            if ($this->linkSaysNothing) {
                return Http::response(['status' => 'created']);
            }
            $suffix = Str::random(14);
            $link = $r->data() + ['id' => "plink_{$suffix}", 'short_url' => "https://rzp.io/rzp/{$suffix}", 'status' => 'created', 'amount_paid' => 0, 'payments' => null];
            $this->links[$link['id']] = $link;

            return Http::response($link);
        }
        if (preg_match('#^/v1/payment_links/(plink_\w+)$#', $path, $m) && isset($this->links[$m[1]])) {
            return Http::response($this->links[$m[1]]);
        }
        if (preg_match('#^/v1/orders/(order_\w+)$#', $path, $m) && isset($this->orders[$m[1]])) {
            return Http::response($this->orders[$m[1]]);
        }
        if (preg_match('#^/v1/payments/(pay_\w+)/refund$#', $path, $m)) {
            $receipt = $r->data()['receipt'];
            if (collect($this->refunds[$m[1]] ?? [])->firstWhere('receipt', $receipt) !== null) {
                return Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The receipt has already been used for this payment']], 400);
            }
            $refund = ['id' => 'rfnd_'.Str::random(14), 'entity' => 'refund', 'amount' => $r->data()['amount'], 'currency' => 'INR', 'payment_id' => $m[1],
                'notes' => $r->data()['notes'] ?? [], 'receipt' => $receipt, 'status' => $this->refundStatus];
            $this->refunds[$m[1]][] = $refund;
            if ($this->loseRefundAnswer) {
                $this->loseRefundAnswer = false;
                throw new ConnectionException('Timed out');
            }

            return Http::response($refund);
        }
        if (preg_match('#^/v1/payments/(pay_\w+)/refunds$#', $path, $m)) {
            return Http::response(['entity' => 'collection', 'count' => count($this->refunds[$m[1]] ?? []), 'items' => $this->refunds[$m[1]] ?? []]);
        }

        return Http::response(['error' => ['code' => 'BAD_REQUEST_ERROR', 'description' => 'The id provided does not exist']], 400);
    }

    private function gateway(string $mode = 'live'): PaymentGateway
    {
        [$id, $secret] = $mode === 'live' ? self::LIVE_KEY : self::TEST_KEY;
        $this->add('razorpay', $mode, ['keyId' => $id, 'keySecret' => $secret, 'webhookSecret' => self::WEBHOOK_SECRET])->assertCreated();

        return PaymentGateway::query()->where('driver', 'razorpay')->latest('position')->firstOrFail();
    }

    /** A customer starts paying 500.00: answers the payment. */
    private function started(PaymentGateway $gateway, string $amount = '500'): Payment
    {
        return Payment::query()->findOrFail($this->topUp($gateway, $amount)->assertCreated()->json('payment.id'));
    }

    /** The payer pays on Razorpay's page: the link says so, and it gets its order and payment. */
    private function pays(Payment $payment, ?int $paid = null): array
    {
        $link = &$this->links[$payment->gateway_ref];
        $suffix = substr($link['id'], 6);
        $paid ??= $link['amount'];
        $link['status'] = 'paid';
        $link['amount_paid'] = $paid;
        $link['order_id'] = "order_{$suffix}";
        $link['payments'] = [['amount' => $paid, 'created_at' => 1749618371, 'method' => 'upi', 'payment_id' => "pay_{$suffix}", 'plink_id' => $link['id'], 'status' => 'captured', 'updated_at' => 1749618372]];
        $this->orders[$link['order_id']] = ['id' => $link['order_id'], 'entity' => 'order', 'amount' => $link['amount'], 'amount_paid' => $paid, 'currency' => $link['currency'], 'receipt' => $link['reference_id'], 'status' => 'paid'];

        return $link;
    }

    /** payment_link.paid as Razorpay's documents show it. */
    private function paidEvent(array $link): array
    {
        return ['account_id' => 'acc_OU2H3nkLn9jDVo', 'contains' => ['payment_link', 'order', 'payment'], 'created_at' => 1749618314, 'entity' => 'event', 'event' => 'payment_link.paid', 'payload' => [
            'order' => ['entity' => $this->orders[$link['order_id']]],
            'payment' => ['entity' => $this->paymentEntity($link)],
            'payment_link' => ['entity' => array_diff_key($link, array_flip(['payments']))],
        ]];
    }

    private function paymentEntity(array $link): array
    {
        return ['id' => $link['payments'][0]['payment_id'], 'entity' => 'payment', 'amount' => $link['amount_paid'], 'currency' => $link['currency'], 'status' => 'captured', 'order_id' => $link['order_id'], 'notes' => [], 'captured' => true];
    }

    /** refund.processed or refund.failed: `notes` as a refund made elsewhere has them, empty. */
    private function refundEvent(string $type, array $link, string $refundId, int $amount, array $notes = []): array
    {
        return ['entity' => 'event', 'event' => $type, 'contains' => ['refund', 'payment'], 'created_at' => 1597734071, 'payload' => [
            'refund' => ['entity' => ['id' => $refundId, 'entity' => 'refund', 'amount' => $amount, 'currency' => 'INR', 'payment_id' => $link['payments'][0]['payment_id'], 'notes' => $notes, 'status' => $type === 'refund.failed' ? 'failed' : 'processed']],
            'payment' => ['entity' => $this->paymentEntity($link)],
        ]];
    }

    private function disputeEvent(string $type, array $link, int $amount): array
    {
        return ['entity' => 'event', 'event' => $type, 'contains' => ['payment', 'dispute'], 'payload' => [
            'payment' => ['entity' => $this->paymentEntity($link)],
            'dispute' => ['entity' => ['id' => 'disp_EsIAlDcoUr8CaQ', 'entity' => 'dispute', 'payment_id' => $link['payments'][0]['payment_id'], 'amount' => $amount, 'currency' => 'INR', 'status' => str_replace('payment.dispute.', '', $type), 'phase' => 'chargeback']],
        ]];
    }

    private function deliver(PaymentGateway $gateway, array $event, ?string $signature = null, ?string $eventId = null): TestResponse
    {
        $body = json_encode($event);

        return $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], array_filter(['CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
            'HTTP_X_RAZORPAY_EVENT_ID' => $eventId ?? Str::random(20)]), $body);
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
        $admin = $this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk();
        $driver = collect($admin->json('drivers'))->firstWhere('id', 'razorpay');
        $this->assertSame(['Razorpay', true, [], true], [$driver['name'], $driver['online'], $driver['methods'], $driver['partialRefunds'] ?? true]);
        $this->assertSame(['keyId', 'keySecret', 'webhookSecret'], array_column($driver['fields'], 'name'));
        $this->assertSame(['text', 'secret', 'secret'], array_column($driver['fields'], 'type'));
    }

    public function test_keys_are_tried_and_refused_in_razorpays_words_and_the_wrong_modes_keys_are_refused(): void
    {
        $this->add('razorpay', 'live', ['keyId' => self::LIVE_KEY[0], 'keySecret' => 'wrong', 'webhookSecret' => 's'])
            ->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Authentication failed');
        $this->assertSame(0, PaymentGateway::query()->where('driver', 'razorpay')->count());

        $this->sent = [];
        $this->add('razorpay', 'live', ['keyId' => self::TEST_KEY[0], 'keySecret' => self::TEST_KEY[1], 'webhookSecret' => 's'])
            ->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Test keys, and this way to pay is set to Live.');
        $this->assertSame([], $this->sent, 'refused before any call');
        $this->add('razorpay', 'test', ['keyId' => self::LIVE_KEY[0], 'keySecret' => self::LIVE_KEY[1], 'webhookSecret' => 's'])
            ->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Live keys, and this way to pay is set to Test.');

        $this->add('razorpay', 'live', ['keyId' => 'an_unfamiliar_key', 'keySecret' => 'x', 'webhookSecret' => 's'])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Authentication failed');
    }

    public function test_saved_keys_are_never_answered_and_the_page_says_where_events_go(): void
    {
        $gateway = $this->gateway();
        $saved = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('gateways'))->firstWhere('id', $gateway->id);
        $this->assertSame((new RazorpayDriver)->webhookEvents(), $saved['webhookEvents']);
        $this->assertSame(self::SITE."/api/kit/payments/webhooks/{$gateway->id}", $saved['webhookUrl']);
        $text = json_encode($saved);
        $this->assertStringNotContainsString(self::LIVE_KEY[1], $text);
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $text);
    }

    public function test_a_payer_goes_to_a_link_that_says_who_they_are_and_comes_back_here(): void
    {
        $gateway = $this->gateway();
        $this->ana->forceFill(['phone' => '+919876543210'])->save();
        $this->travelTo('2026-11-03 10:00:00');
        $started = $this->topUp($gateway, '500')->assertCreated();
        $id = $started->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $link = $this->links[$payment->gateway_ref];
        $this->assertSame(['redirect', $link['short_url']], [$started->json('checkout.kind'), $started->json('checkout.url')]);
        $this->assertSame([50000, 'INR', $id], [$link['amount'], $link['currency'], $link['reference_id']]);
        $this->assertSame([false, false, ['sms' => false, 'email' => false], false], [$link['accept_partial'], $link['upi_link'], $link['notify'], $link['reminder_enable']]);
        $this->assertSame([self::SITE."/api/kit/payments/{$id}/return", 'get'], [$link['callback_url'], $link['callback_method']]);
        $this->assertSame(['name' => 'Ana', 'email' => 'ana@dental.example', 'contact' => '+919876543210'], $link['customer']);
        $this->assertSame(['kit_payment_id' => $id], $link['notes']);
        $this->assertSame($payment->expires_at->getTimestamp(), $link['expire_by']);
        $call = $this->sentTo('POST', '/v1/payment_links')[0]['request'];
        $this->assertSame('Basic '.base64_encode(self::LIVE_KEY[0].':'.self::LIVE_KEY[1]), $call->header('Authorization')[0]);
        $this->assertSame([], $this->entries(), 'nothing credited until Razorpay says so');
    }

    public function test_it_says_only_what_it_knows_of_the_payer_within_razorpays_limits(): void
    {
        $gateway = $this->gateway();
        $byPhone = User::create(['name' => 'Cy', 'email' => null, 'password' => Str::random(64)]);
        $byPhone->forceFill(['phone' => '+1234567890123456'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $byPhone->id, 'role' => 'member']);
        $id = $this->topUp($gateway, '500', $byPhone)->assertCreated()->json('payment.id');
        $this->assertSame(['name' => 'Cy'], $this->links[Payment::query()->findOrFail($id)->gateway_ref]['customer'], 'too long a number is left out');
    }

    public function test_a_link_ends_no_sooner_than_razorpay_allows(): void
    {
        config(['kit.payments.expire_hours' => 0]);
        $gateway = $this->gateway();
        $this->travelTo('2026-11-03 10:00:00');
        $payment = $this->started($gateway);
        $this->assertSame(now()->addMinutes(config('kit.payments.razorpay.min_link_minutes'))->getTimestamp(), $this->links[$payment->gateway_ref]['expire_by']);
    }

    public function test_coming_back_asks_razorpay_and_what_the_return_says_is_never_believed(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->comeBack($payment->id, ['razorpay_payment_link_status' => 'paid', 'razorpay_payment_id' => 'pay_forged', 'razorpay_payment_link_id' => $payment->gateway_ref, 'razorpay_signature' => 'forged']);
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->pays($payment);
        $this->comeBack($payment->id);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('500.00', $payment->fresh()->received);
        $this->assertSame('500.00', $this->balance());
        $this->comeBack($payment->id);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_the_webhook_counts_only_with_its_signature_and_credits_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $event = $this->paidEvent($this->pays($payment));

        $this->deliver($gateway, $event, 'forged')->assertUnauthorized();
        $this->deliver($gateway, $event, hash_hmac('sha256', json_encode($event), self::LIVE_KEY[1]))->assertUnauthorized();
        $body = json_encode($event);
        $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertUnauthorized();
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->deliver($gateway, $event, null, 'evt_same')->assertOk();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
        $this->deliver($gateway, $event, null, 'evt_same')->assertOk();
        $this->assertSame(1, PaymentEvent::query()->count(), 'the same delivery is kept once, by Razorpay\'s event id');
        $this->deliver($gateway, $event, null, 'evt_another')->assertOk();
        $this->assertSame(2, PaymentEvent::query()->count());
        $this->assertCount(1, $this->entries());
        $this->assertSame('500.00', $this->balance());
    }

    public function test_another_amount_waits_for_staff_and_another_currency_too(): void
    {
        $gateway = $this->gateway();
        $less = $this->started($gateway);
        $this->deliver($gateway, $this->paidEvent($this->pays($less, 40000)))->assertOk();
        $this->assertSame(['review', '400.00', []], [$less->fresh()->status, $less->fresh()->received, $this->entries()]);

        $other = $this->started($gateway);
        $link = $this->pays($other);
        $link['currency'] = 'USD';
        $this->orders[$link['order_id']]['currency'] = 'USD';
        $this->deliver($gateway, $this->paidEvent($link))->assertOk();
        $this->assertSame(['review', []], [$other->fresh()->status, $this->entries()]);
    }

    public function test_a_link_cancelled_there_fails_here_and_an_expired_one_is_left_to_run_out(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->links[$payment->gateway_ref];
        $link['status'] = 'cancelled';
        $this->deliver($gateway, ['entity' => 'event', 'event' => 'payment_link.cancelled', 'payload' => ['payment_link' => ['entity' => $link]]])->assertOk();
        $this->assertSame('failed', $payment->fresh()->status);

        $late = $this->started($gateway);
        $link = &$this->links[$late->gateway_ref];
        $link['status'] = 'expired';
        $this->deliver($gateway, ['entity' => 'event', 'event' => 'payment_link.expired', 'payload' => ['payment_link' => ['entity' => $link]]])->assertOk();
        $this->comeBack($late->id);
        $this->assertSame('open', $late->fresh()->status);
    }

    public function test_kit_payments_asks_about_an_open_one_after_its_wait(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->travel((new RazorpayDriver)->checkAfterMinutes() - 1)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame('open', $payment->fresh()->status, 'not yet its time');
        $this->travel(2)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
    }

    public function test_staff_refund_the_captured_payment_with_their_receipt_counted_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);
        $reference = (string) Str::uuid();

        $this->refund($payment, '100', $reference)->assertOk();
        $call = $this->sentTo('POST', "/v1/payments/{$link['payments'][0]['payment_id']}/refund")[0];
        $this->assertSame(['amount' => 10000, 'speed' => 'normal', 'receipt' => $reference, 'notes' => ['kit_payment_id' => $payment->id]], $call['body']);
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);

        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertSame('100.00', $this->refunded($payment), 'one click is one refund');

        $made = $this->refunds[$link['payments'][0]['payment_id']][0];
        $before = count($this->sent);
        $this->deliver($gateway, $this->refundEvent('refund.processed', $link, $made['id'], 10000, ['kit_payment_id' => $payment->id]))->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'Razorpay telling of the same refund counts nothing more');
        $this->assertCount($before, $this->sent, 'a refund the kit made says its payment: nothing to ask');
    }

    public function test_a_refund_whose_answer_was_lost_is_found_by_its_receipt_when_tried_again(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);
        $reference = (string) Str::uuid();

        $this->loseRefundAnswer = true;
        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertCount(1, $this->refunds[$link['payments'][0]['payment_id']]);
        $this->assertSame('100.00', $this->refunded($payment), 'found at once, by the receipt');
        $this->assertSame('400.00', $this->balance());
    }

    public function test_a_refund_whose_answer_was_lost_finds_its_own_receipt_among_others(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->comeBack($payment->id);
        $this->refund($payment, '50')->assertOk();
        $this->loseRefundAnswer = true;
        $this->refund($payment, '100')->assertOk();
        $this->assertSame(['150.00', '350.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_only_the_captured_payment_is_refunded_and_a_link_with_none_refuses(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);
        $captured = $link['payments'][0];
        $this->links[$payment->gateway_ref]['payments'] = [['payment_id' => 'pay_failedEarlier', 'status' => 'failed'], $captured];
        $this->refund($payment, '100')->assertOk();
        $this->assertCount(1, $this->sentTo('POST', "/v1/payments/{$captured['payment_id']}/refund"));
        $this->assertSame([], $this->sentTo('POST', '/v1/payments/pay_failedEarlier/refund'));

        $this->links[$payment->gateway_ref]['payments'] = null;
        $before = count($this->sent);
        $this->refund($payment, '100')->assertStatus(502)->assertJsonFragment(['message' => __('payments.refund_failed', ['reason' => __('payments.gateway_unclear')])]);
        $this->assertCount($before + 1, $this->sent, 'it only asked');
        $this->assertSame('100.00', $this->refunded($payment));
    }

    public function test_a_refund_razorpay_answers_as_failed_counts_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->comeBack($payment->id);
        $this->refundStatus = 'failed';
        $this->refund($payment, '100')->assertStatus(502)->assertJsonFragment(['message' => __('payments.refund_failed', ['reason' => __('payments.gateway_refund_failed')])]);
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_link_answered_without_its_address_tells_staff_and_starts_nothing(): void
    {
        $gateway = $this->gateway();
        $this->linkSaysNothing = true;
        $this->topUp($gateway, '500')->assertStatus(503);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->first();
        $this->assertSame(['failed', __('payments.gateway_unclear')], [$row['status'], $row['error']]);
    }

    public function test_a_refund_razorpay_refuses_is_told_in_its_words_and_counts_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->comeBack($payment->id);
        $payment->forceFill(['gateway_ref' => 'plink_gone'])->save();
        $this->refund($payment, '100')->assertStatus(502)->assertJsonFragment(['message' => __('payments.refund_failed', ['reason' => 'The id provided does not exist'])]);
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_made_on_razorpays_dashboard_finds_its_payment_through_the_order(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);

        $this->deliver($gateway, $this->refundEvent('refund.processed', $link, 'rfnd_FS8TWyPrCsa0OB', 5000))->assertOk();
        $this->assertCount(1, $this->sentTo('GET', "/v1/orders/{$link['order_id']}"));
        $this->assertSame(['50.00', '450.00'], [$this->refunded($payment), $this->balance()]);
        $this->deliver($gateway, $this->refundEvent('refund.processed', $link, 'rfnd_FS8TWyPrCsa0OB', 5000))->assertOk();
        $this->assertSame('450.00', $this->balance(), 'counted once by its own id');
    }

    public function test_a_refund_that_fails_later_goes_back_on_the_wallet_and_staff_are_told(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->deliver($gateway, $this->refundEvent('refund.processed', $link, 'rfnd_later', 5000))->assertOk();
        $this->assertSame('450.00', $this->balance());

        $this->deliver($gateway, $this->refundEvent('refund.failed', $link, 'rfnd_later', 5000))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
        $this->assertSame(['refund', '50.00', "refund-failed:{$payment->id}:rfnd_later"], last($this->entries()));
        $this->assertArrayNotHasKey('rfnd_later', $payment->fresh()->details['refunds'] ?? [], 'it no longer counts as a refund');
        $this->deliver($gateway, $this->refundEvent('refund.failed', $link, 'rfnd_later', 5000))->assertOk();
        $this->assertSame('500.00', $this->balance(), 'given back once');
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->firstWhere('id', $payment->id);
        $this->assertSame([true, true], [$row['refundFailed'], $row['canRefund']]);

        $this->refund($payment, '500')->assertOk();
        $this->assertSame('500.00', $this->refunded($payment), 'it can be refunded again');
    }

    public function test_a_refund_told_as_failed_before_it_was_counted_never_counts(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->deliver($gateway, $this->refundEvent('refund.failed', $link, 'rfnd_early', 5000))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
        $this->deliver($gateway, $this->refundEvent('refund.processed', $link, 'rfnd_early', 5000))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
        $this->assertCount(1, $this->entries());
    }

    public function test_a_dispute_holds_and_won_gives_it_back(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);

        $this->deliver($gateway, $this->disputeEvent('payment.dispute.created', $link, 39000))->assertOk();
        $this->assertSame(['open', '110.00'], [$payment->fresh()->dispute, $this->balance()]);
        $this->deliver($gateway, $this->disputeEvent('payment.dispute.won', $link, 39000))->assertOk();
        $this->assertSame(['won', '500.00'], [$payment->fresh()->dispute, $this->balance()]);
    }

    public function test_a_dispute_lost_keeps_what_was_held_and_one_not_opened_here_comes_off(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->deliver($gateway, $this->disputeEvent('payment.dispute.lost', $link, 39000))->assertOk();
        $this->assertSame(['lost', '110.00'], [$payment->fresh()->dispute, $this->balance()]);
    }

    public function test_events_about_payments_that_are_not_the_kits_change_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $link = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->orders[$link['order_id']]['receipt'] = 'an invoice of another shop';
        $this->deliver($gateway, $this->refundEvent('refund.processed', $link, 'rfnd_elsewhere', 5000))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);

        $this->deliver($gateway, ['entity' => 'event', 'event' => 'payment.authorized', 'payload' => []])->assertOk();
        $this->deliver($gateway, ['entity' => 'event', 'event' => 'refund.processed', 'payload' => []])->assertOk();
        $this->orders[$link['order_id']]['receipt'] = $payment->id;
        $noId = $this->refundEvent('refund.processed', $link, 'rfnd_x', 5000);
        unset($noId['payload']['refund']['entity']['id']);
        $this->deliver($gateway, $noId)->assertOk();
        $noOrder = $this->refundEvent('refund.processed', $link, 'rfnd_y', 5000);
        unset($noOrder['payload']['payment']['entity']['order_id']);
        $this->deliver($gateway, $noOrder)->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()], 'an event it cannot tie to a refund and a payment changes nothing');
    }

    public function test_a_link_razorpay_will_not_make_tells_staff_its_words_and_the_customer_only_that_it_is_down(): void
    {
        $gateway = $this->gateway();
        $this->refuseLink = ['code' => 'BAD_REQUEST_ERROR', 'description' => 'Order amount less than minimum amount allowed'];
        $this->topUp($gateway, '500')->assertStatus(503)->assertExactJson(['message' => __('payments.gateway_down')]);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->first();
        $this->assertSame(['failed', 'Order amount less than minimum amount allowed'], [$row['status'], $row['error']]);
    }

    public function test_a_test_payment_runs_through_it_and_credits_no_one(): void
    {
        $gateway = $this->gateway('test');
        $id = $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$gateway->id}/test", ['amount' => '10'])->assertCreated()->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->deliver($gateway, $this->paidEvent($this->pays($payment)))->assertOk();
        $this->assertSame(['paid', []], [$payment->fresh()->status, $this->entries()]);
        $this->assertSame('Basic '.base64_encode(self::TEST_KEY[0].':'.self::TEST_KEY[1]), $this->sentTo('POST', '/v1/payment_links')[0]['request']->header('Authorization')[0]);
    }

    public function test_the_address_and_timings_are_settings(): void
    {
        config(['kit.payments.razorpay.api_url' => 'https://razorpay.standin.example/']);
        Http::swap(new Factory);
        Http::fake(['razorpay.standin.example/*' => Http::response(['items' => []])]);
        $gateway = new PaymentGateway;
        $gateway->forceFill(['mode' => 'live', 'config' => ['keyId' => 'rzp_live_x', 'keySecret' => 's', 'webhookSecret' => 'w']]);
        (new RazorpayDriver)->verify($gateway);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://razorpay.standin.example/v1/orders') && $r->data()['count'] === 1);
    }
}
