<?php

namespace Tests\Feature\Kit;

use App\Kit\Payments\Drivers\PaystackDriver;
use App\Kit\Settings\Settings;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WorkspaceMember;
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
 * Paystack (App\Kit\Payments\Drivers\PaystackDriver) through the kit's real routes, against a fake of its documented API:
 * initialize, verify, refunds and the signed webhook. The bodies follow Paystack's published samples read 11-03. They are not
 * recordings from a real account: none has been run yet.
 */
class PaystackGatewayTest extends TestCase
{
    use RefreshDatabase;
    use TopsUpThroughAGateway;

    private const LIVE_KEY = 'sk_live_acmeAcmeAcme';

    private const TEST_KEY = 'sk_test_acmeAcmeAcme';

    /** @var array<string, array> transactions by reference */
    private array $transactions = [];

    /** @var list<array> refunds, as Paystack's list answers them */
    private array $refunds = [];

    private bool $refundsDown = false;

    /** Paystack answering 200 with `status: false`, or with no page to send the payer to. */
    private bool $saysNo = false;

    private bool $noPage = false;

    private function install(string $currency = 'NGN'): void
    {
        $this->installWith(function (Request $r) {
            if (! str_starts_with($r->url(), 'https://api.paystack.co/')) {
                return null;
            }
            $this->record($r);

            return $this->paystack($r);
        }, $currency);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
    }

    private function paystack(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if (! str_starts_with($r->header('Authorization')[0] ?? '', 'Bearer sk_') || str_contains($r->header('Authorization')[0], 'wrong')) {
            return Http::response(['status' => false, 'message' => 'Invalid key'], 401);
        }
        if ($this->saysNo) {
            return Http::response(['status' => false, 'message' => 'Not today']);
        }
        if ($path === '/transaction') {
            return Http::response(['status' => true, 'message' => 'Transactions retrieved', 'data' => []]);
        }
        if ($path === '/transaction/initialize') {
            $data = $r->data();
            if ($this->noPage) {
                return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => ['reference' => $data['reference']]]);
            }
            $this->transactions[$data['reference']] = ['id' => random_int(1000, 9_000_000), 'status' => 'abandoned', 'reference' => $data['reference'], 'amount' => (int) $data['amount'], 'currency' => $data['currency']];

            return Http::response(['status' => true, 'message' => 'Authorization URL created', 'data' => ['authorization_url' => 'https://checkout.paystack.com/3ni8kdavz62431k', 'access_code' => '3ni8kdavz62431k', 'reference' => $data['reference']]]);
        }
        if (preg_match('#^/transaction/verify/(.+)$#', $path, $m)) {
            return isset($this->transactions[$m[1]])
                ? Http::response(['status' => true, 'message' => 'Verification successful', 'data' => $this->transactions[$m[1]]])
                : Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400);
        }
        if ($path === '/refund' && $r->method() === 'GET') {
            if ($this->refundsDown) {
                return Http::response(['status' => false, 'message' => 'Try again'], 500);
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return Http::response(['status' => true, 'message' => 'Refunds retrieved', 'data' => array_values(array_filter($this->refunds, fn (array $f) => (string) $f['transaction'] === (string) $query['transaction']))]);
        }
        if ($path === '/refund') {
            $data = $r->data();
            $refund = ['id' => 3018000 + count($this->refunds), 'transaction' => $data['transaction'], 'dispute' => null, 'amount' => (int) $data['amount'], 'currency' => $data['currency'], 'status' => 'pending', 'merchant_note' => $data['merchant_note'] ?? null];
            $this->refunds[] = $refund;

            return Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => $refund]);
        }

        return Http::response(['status' => false, 'message' => 'Not found'], 404);
    }

    /** The kit charges in this currency from now on. */
    private function chargeIn(string $currency): void
    {
        app(Settings::class)->put('pricing.currency', $currency);
        $list = PriceList::query()->findOrFail('standard');
        $list->definition = ['currency' => $currency] + $list->definition;
        $list->save();
    }

    private function gateway(string $mode = 'live'): PaymentGateway
    {
        $this->add('paystack', $mode, ['secretKey' => $mode === 'live' ? self::LIVE_KEY : self::TEST_KEY])->assertCreated();

        return PaymentGateway::query()->where('driver', 'paystack')->latest('position')->firstOrFail();
    }

    private function started(PaymentGateway $gateway, string $amount = '500'): Payment
    {
        return Payment::query()->findOrFail($this->topUp($gateway, $amount)->assertCreated()->json('payment.id'));
    }

    /** The payer pays on Paystack's page: answers the transaction as it now stands. */
    private function pays(Payment $payment, ?int $subunits = null, ?string $currency = null): array
    {
        $t = &$this->transactions[$payment->id];
        $t['status'] = 'success';
        $t['amount'] = $subunits ?? $t['amount'];
        $t['currency'] = $currency ?? $t['currency'];

        return $t;
    }

    private function paid(PaymentGateway $gateway): Payment
    {
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->comeBack($payment->id);

        return $payment;
    }

    private function deliver(PaymentGateway $gateway, string $event, array $data, ?string $signature = null, string $key = self::LIVE_KEY): TestResponse
    {
        $body = json_encode(['event' => $event, 'data' => $data]);

        return $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $signature ?? hash_hmac('sha512', $body, $key)], $body);
    }

    private function refund(Payment $payment, string $amount, ?string $reference = null): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/refunds", ['amount' => $amount, 'reference' => $reference ?? (string) Str::uuid()]);
    }

    private function refunded(Payment $payment): string
    {
        return (string) BigDecimal::of($payment->fresh()->refunded)->toScale(2);
    }

    /** What Paystack sends about a refund: the bank's reference, and not the refund's id. */
    private function refundEvent(Payment $payment, string $status = 'processed', string $bankReference = '132013318360'): array
    {
        return ['status' => $status, 'transaction_reference' => $payment->id, 'refund_reference' => $bankReference, 'amount' => '5000', 'currency' => 'NGN', 'processor' => 'mpgs_zen', 'integration' => 412829, 'domain' => 'live'];
    }

    private function dispute(Payment $payment, ?string $resolution = null, int $id = 358949): array
    {
        return ['id' => $id, 'refund_amount' => null, 'currency' => 'NGN', 'status' => $resolution === null ? 'awaiting-merchant-feedback' : 'resolved', 'resolution' => $resolution,
            'transaction' => ['id' => $this->transactions[$payment->id]['id'], 'status' => 'success', 'reference' => $payment->id, 'amount' => 50000, 'currency' => 'NGN'], 'transaction_reference' => null, 'category' => 'chargeback'];
    }

    public function test_it_is_a_way_to_pay_drawn_from_its_own_form_with_nothing_to_tick(): void
    {
        $driver = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('drivers'))->firstWhere('id', 'paystack');
        $this->assertSame(['Paystack', true, []], [$driver['name'], $driver['online'], $driver['methods']]);
        $this->assertSame(['secretKey'], array_column($driver['fields'], 'name'));
        $this->assertSame(['secret'], array_column($driver['fields'], 'type'));

        $gateway = $this->gateway();
        $saved = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('gateways'))->firstWhere('id', $gateway->id);
        $this->assertSame([], $saved['webhookEvents']);
        $this->assertStringNotContainsString(self::LIVE_KEY, json_encode($saved));
    }

    public function test_keys_are_tried_and_the_wrong_modes_keys_are_refused_before_any_call(): void
    {
        $this->add('paystack', 'live', ['secretKey' => 'sk_live_wrong'])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Invalid key');
        $this->sent = [];
        $this->add('paystack', 'live', ['secretKey' => self::TEST_KEY])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Test keys, and this way to pay is set to Live.');
        $this->add('paystack', 'test', ['secretKey' => self::LIVE_KEY])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Live keys, and this way to pay is set to Test.');
        $this->assertSame([], $this->sent, 'refused before any call');
        $this->assertSame(0, PaymentGateway::query()->where('driver', 'paystack')->count());
    }

    public function test_a_secret_left_empty_is_kept(): void
    {
        $gateway = $this->gateway();
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['values' => ['secretKey' => '']])->assertOk();
        $this->assertSame(self::LIVE_KEY, $gateway->fresh()->config['secretKey']);
    }

    public function test_a_200_that_says_no_is_a_no_and_an_answer_with_no_page_is_refused(): void
    {
        $this->saysNo = true;
        $this->add('paystack', 'live', ['secretKey' => self::LIVE_KEY])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Not today');
        $this->saysNo = false;
        $gateway = $this->gateway();
        $this->noPage = true;
        $this->topUp($gateway, '500')->assertStatus(503);
        $this->assertSame('failed', Payment::query()->firstOrFail()->status);
    }

    public function test_a_payer_goes_to_paystacks_page_with_the_amount_in_subunits_and_the_payment_as_its_reference(): void
    {
        $gateway = $this->gateway();
        $started = $this->topUp($gateway, '500')->assertCreated();
        $id = $started->json('payment.id');
        $this->assertSame(['redirect', 'https://checkout.paystack.com/3ni8kdavz62431k'], [$started->json('checkout.kind'), $started->json('checkout.url')]);
        $call = $this->sentTo('POST', '/transaction/initialize')[0];
        $this->assertSame(['ana@dental.example', '50000', 'NGN', $id, self::SITE."/api/kit/payments/{$id}/return"],
            [$call['body']['email'], $call['body']['amount'], $call['body']['currency'], $call['body']['reference'], $call['body']['callback_url']]);
        $this->assertSame('Bearer '.self::LIVE_KEY, $call['request']->header('Authorization')[0]);
        $this->assertSame($id, Payment::query()->findOrFail($id)->gateway_ref);
        $this->assertSame([], $this->entries(), 'nothing credited until Paystack says so');
    }

    public function test_west_african_francs_have_no_subunit_and_paystack_still_takes_them_times_100(): void
    {
        $this->chargeIn('XOF');
        config(['kit.payments.currency_decimals' => ['XOF' => 0]]);
        $gateway = $this->gateway();
        $payment = $this->started($gateway, '1000');
        $this->assertSame('100000', $this->sentTo('POST', '/transaction/initialize')[0]['body']['amount']);

        $this->pays($payment);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '1000'], [$payment->fresh()->status, (string) BigDecimal::of($payment->fresh()->received)->toScale(0)]);

        $this->refund($payment, '200')->assertOk();
        $this->assertSame(20000, $this->sentTo('POST', '/refund')[0]['body']['amount']);
        $this->assertSame('200', (string) BigDecimal::of($payment->fresh()->refunded)->toScale(0));
    }

    public function test_a_currency_it_does_not_list_is_not_offered(): void
    {
        $this->chargeIn('EUR');
        $gateway = $this->gateway();
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertOk()->assertJsonPath('gateways', []);
        $this->assertFalse(collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('gateways'))->firstWhere('id', $gateway->id)['takesCurrency']);
        $this->topUp($gateway, '10')->assertStatus(409);
    }

    public function test_someone_with_no_email_is_asked_for_one_for_this_payment_alone(): void
    {
        $gateway = $this->gateway();
        $phoneOnly = User::create(['name' => 'Ben', 'email' => null, 'password' => 'a long password 5']);
        $phoneOnly->forceFill(['phone' => '+2348000000001'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $phoneOnly->id, 'role' => 'member']);

        $this->actingAs($phoneOnly)->getJson('/api/kit/console/payments')->assertOk()->assertJsonPath('gateways.0.asks', ['email']);
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertOk()->assertJsonPath('gateways.0.asks', []);

        $this->topUp($gateway, '500', $phoneOnly)->assertStatus(422)->assertJsonValidationErrors(['payer.email']);
        $this->topUp($gateway, '500', $phoneOnly, ['email' => 'not an address'])->assertStatus(422)->assertJsonValidationErrors(['payer.email']);
        $this->assertSame(0, Payment::query()->count());

        $id = $this->topUp($gateway, '500', $phoneOnly, ['email' => 'ben@clinic.example'])->assertCreated()->json('payment.id');
        $this->assertSame('ben@clinic.example', $this->sentTo('POST', '/transaction/initialize')[0]['body']['email']);
        $this->assertNull($phoneOnly->fresh()->email, 'not put on the account');
        $this->assertStringNotContainsString('ben@clinic.example', json_encode(Payment::query()->findOrFail($id)->details), 'not kept on the payment');
    }

    public function test_an_email_on_the_account_is_used_whatever_the_page_sends(): void
    {
        $gateway = $this->gateway();
        $this->topUp($gateway, '500', null, ['email' => 'someone@else.example'])->assertCreated();
        $this->assertSame('ana@dental.example', $this->sentTo('POST', '/transaction/initialize')[0]['body']['email']);
    }

    public function test_a_staff_test_payment_asks_the_same_and_credits_no_one(): void
    {
        $gateway = $this->gateway('test');
        $this->staff->forceFill(['email' => null, 'phone' => '+2348000000002'])->save();
        $url = "/api/kit/admin/payments/gateways/{$gateway->id}/test";
        $listed = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('gateways'))->firstWhere('id', $gateway->id);
        $this->assertSame(['email'], $listed['asks']);
        $this->actingAs($this->staff)->postJson($url, ['amount' => '10'])->assertStatus(422)->assertJsonValidationErrors(['payer.email']);
        $id = $this->actingAs($this->staff)->postJson($url, ['amount' => '10', 'payer' => ['email' => 'op@test.example']])->assertCreated()->json('payment.id');
        $this->assertSame('op@test.example', $this->sentTo('POST', '/transaction/initialize')[0]['body']['email']);

        $payment = Payment::query()->findOrFail($id);
        $this->deliver($gateway, 'charge.success', $this->pays($payment), null, self::TEST_KEY)->assertOk();
        $this->assertSame(['paid', []], [$payment->fresh()->status, $this->entries()]);
    }

    public function test_coming_back_asks_paystack_and_what_the_return_says_is_never_believed(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->comeBack($payment->id, ['trxref' => $payment->id, 'reference' => $payment->id, 'status' => 'success']);
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()], 'abandoned at Paystack: the payer can still pay');

        $this->pays($payment);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '500.00', '500.00'], [$payment->fresh()->status, $payment->fresh()->received, $this->balance()]);
        $this->comeBack($payment->id);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_a_failed_transaction_fails_and_a_lookup_paystack_cannot_answer_leaves_it_open(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        unset($this->transactions[$payment->id]);
        $this->comeBack($payment->id);
        $this->assertSame('open', $payment->fresh()->status);

        $this->transactions[$payment->id] = ['id' => 7, 'status' => 'failed', 'reference' => $payment->id, 'amount' => 50000, 'currency' => 'NGN'];
        $this->comeBack($payment->id);
        $this->assertSame('failed', $payment->fresh()->status);

        $this->deliver($gateway, 'charge.success', $this->pays($payment))->assertOk();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()], 'money taken is money credited');
    }

    public function test_kit_payments_asks_about_one_the_payer_never_came_back_from(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->travel((new PaystackDriver)->checkAfterMinutes() + 1)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
    }

    public function test_the_webhook_counts_only_with_its_signature_taken_with_a_plain_200_and_credits_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $data = $this->pays($payment);

        $this->deliver($gateway, 'charge.success', $data, 'forged')->assertUnauthorized();
        $this->deliver($gateway, 'charge.success', $data, '')->assertUnauthorized();
        $this->deliver($gateway, 'charge.success', $data, null, 'sk_live_another')->assertUnauthorized();
        $body = json_encode(['event' => 'charge.success', 'data' => $data]);
        $this->deliver($gateway, 'charge.success', $data, hash_hmac('sha256', $body, self::LIVE_KEY))->assertUnauthorized();
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->deliver($gateway, 'charge.success', $data)->assertStatus(200);
        $this->deliver($gateway, 'charge.success', $data)->assertStatus(200);
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_another_amount_waits_for_staff_and_another_currency_too(): void
    {
        $gateway = $this->gateway();
        $one = $this->started($gateway);
        $this->deliver($gateway, 'charge.success', $this->pays($one, 40000))->assertOk();
        $this->assertSame(['review', '400.00', []], [$one->fresh()->status, $one->fresh()->received, $this->entries()]);

        $two = $this->started($gateway);
        $this->deliver($gateway, 'charge.success', $this->pays($two, 50000, 'USD'))->assertOk();
        $this->assertSame(['review', 'USD', []], [$two->fresh()->status, $two->fresh()->details['receivedCurrency'], $this->entries()]);
    }

    public function test_a_refund_carries_its_reference_and_paystacks_event_about_it_counts_nothing_more(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $reference = (string) Str::uuid();

        $this->refund($payment, '100', $reference)->assertOk();
        $call = $this->sentTo('POST', '/refund')[0];
        $this->assertSame([$this->transactions[$payment->id]['id'], 10000, 'NGN', $reference], [$call['body']['transaction'], $call['body']['amount'], $call['body']['currency'], $call['body']['merchant_note']]);
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);

        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertCount(1, $this->sentTo('POST', '/refund'), 'one click is one refund');

        // Its event names the bank's reference, never the id the kit counted the refund by.
        foreach (['refund.pending', 'refund.processing', 'refund.processed'] as $event) {
            $this->deliver($gateway, $event, $this->refundEvent($payment))->assertOk();
        }
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'the same refund, however Paystack tells of it');
    }

    public function test_a_refund_whose_answer_was_lost_is_found_by_its_reference_and_not_sent_again(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $reference = (string) Str::uuid();
        $this->refunds[] = ['id' => 3017999, 'transaction' => $this->transactions[$payment->id]['id'], 'dispute' => null, 'amount' => 10000, 'currency' => 'NGN', 'status' => 'pending', 'merchant_note' => $reference];

        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertSame([], $this->sentTo('POST', '/refund'), 'Paystack already has it');
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_made_on_paystacks_dashboard_counts_and_each_refund_of_the_payment_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $id = $this->transactions[$payment->id]['id'];
        $this->refunds[] = ['id' => 11, 'transaction' => $id, 'dispute' => null, 'amount' => 5000, 'currency' => 'NGN', 'status' => 'processed', 'merchant_note' => 'Refund for transaction'];
        $this->refunds[] = ['id' => 12, 'transaction' => $id, 'dispute' => null, 'amount' => 5000, 'currency' => 'NGN', 'status' => 'pending', 'merchant_note' => 'Refund for transaction'];
        $this->refunds[] = ['id' => 13, 'transaction' => 999999, 'dispute' => null, 'amount' => 7000, 'currency' => 'NGN', 'status' => 'processed', 'merchant_note' => 'another payment'];

        $this->deliver($gateway, 'refund.processed', $this->refundEvent($payment))->assertOk();
        $this->deliver($gateway, 'refund.processed', $this->refundEvent($payment))->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'two of the same amount are two refunds; delivered twice, still two');
    }

    public function test_a_refund_that_fails_later_goes_back_on_the_wallet_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->refund($payment, '100')->assertOk();
        $this->assertSame('400.00', $this->balance());

        $this->refunds[0]['status'] = 'failed';
        $this->deliver($gateway, 'refund.failed', $this->refundEvent($payment, 'failed', 'TRF_9vgfawjnoz58uxy'))->assertOk();
        $this->deliver($gateway, 'refund.failed', $this->refundEvent($payment, 'failed', 'TRF_9vgfawjnoz58uxy'))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_paystack_answers_as_failed_counts_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $reference = (string) Str::uuid();
        $this->refunds[] = ['id' => 14, 'transaction' => $this->transactions[$payment->id]['id'], 'dispute' => null, 'amount' => 10000, 'currency' => 'NGN', 'status' => 'failed', 'merchant_note' => $reference];
        $this->refund($payment, '100', $reference)->assertStatus(502);
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_asking_about_a_refund_while_paystack_is_down_is_refused_so_it_is_sent_again(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->refundsDown = true;
        $this->deliver($gateway, 'refund.processed', $this->refundEvent($payment))->assertUnauthorized();

        $this->refundsDown = false;
        $this->refunds[] = ['id' => 15, 'transaction' => $this->transactions[$payment->id]['id'], 'dispute' => null, 'amount' => 5000, 'currency' => 'NGN', 'status' => 'processed', 'merchant_note' => 'x'];
        $this->deliver($gateway, 'refund.processed', $this->refundEvent($payment))->assertOk();
        $this->assertSame(['50.00', '450.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_dispute_holds_and_declining_it_gives_the_amount_back(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->deliver($gateway, 'charge.dispute.create', $this->dispute($payment))->assertOk();
        $this->assertSame(['open', '0.00'], [$payment->fresh()->dispute, $this->balance()]);
        $this->deliver($gateway, 'charge.dispute.remind', $this->dispute($payment))->assertOk();
        $this->assertSame('0.00', $this->balance(), 'a reminder is nothing new');

        $this->deliver($gateway, 'charge.dispute.resolve', $this->dispute($payment, 'declined'))->assertOk();
        $this->assertSame(['won', '500.00'], [$payment->fresh()->dispute, $this->balance()]);

        $this->deliver($gateway, 'charge.dispute.remind', $this->dispute($payment))->assertOk();
        $this->assertSame('won', $payment->fresh()->dispute, 'a reminder sent late opens nothing again');
    }

    public function test_a_dispute_accepted_by_anyone_is_lost_and_the_refund_it_makes_is_not_counted_again(): void
    {
        foreach (['merchant-accepted', 'auto-accepted'] as $i => $resolution) {
            $gateway = $this->gateway();
            $payment = $this->paid($gateway);
            $before = (float) $this->balance();
            $this->deliver($gateway, 'charge.dispute.resolve', $this->dispute($payment, $resolution, 400 + $i))->assertOk();
            $this->assertSame('lost', $payment->fresh()->dispute);
            $this->assertEqualsWithDelta($before - 500, (float) $this->balance(), 0.001, "{$resolution}: comes off when it was never held");

            $this->refunds[] = ['id' => 500 + $i, 'transaction' => $this->transactions[$payment->id]['id'], 'dispute' => 400 + $i, 'amount' => 50000, 'currency' => 'NGN', 'status' => 'processed', 'merchant_note' => 'Refund for dispute'];
            $this->deliver($gateway, 'refund.processed', $this->refundEvent($payment))->assertOk();
            $this->assertSame('0.00', $this->refunded($payment), 'the dispute already counted this money');
            $this->assertEqualsWithDelta($before - 500, (float) $this->balance(), 0.001);
        }
    }

    public function test_a_resolution_it_does_not_know_changes_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->deliver($gateway, 'charge.dispute.create', $this->dispute($payment))->assertOk();
        $this->deliver($gateway, 'charge.dispute.resolve', $this->dispute($payment, 'something-new'))->assertOk();
        $this->assertSame(['open', '0.00'], [$payment->fresh()->dispute, $this->balance()]);
    }

    public function test_events_about_payments_that_are_not_the_kits_change_nothing_and_ask_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->sent = [];
        $notOurs = ['status' => 'processed', 'transaction_reference' => 'T2154954_412829_3be32076_6lcg3', 'refund_reference' => '132013318360', 'amount' => '5000', 'currency' => 'NGN'];
        $this->deliver($gateway, 'refund.processed', $notOurs)->assertOk();
        $this->deliver($gateway, 'refund.processed', ['status' => 'processed'])->assertOk();
        $this->assertSame([], $this->sent, 'someone else\'s sale: nothing to ask');

        $this->deliver($gateway, 'charge.success', ['id' => 302961, 'status' => 'success', 'reference' => 'qTPrJoy9Bx', 'amount' => 10000, 'currency' => 'NGN'])->assertOk();
        $this->deliver($gateway, 'charge.success', ['id' => 302961, 'status' => 'success', 'reference' => (string) Str::uuid(), 'amount' => 10000, 'currency' => 'NGN'])->assertOk();
        $this->deliver($gateway, 'charge.dispute.create', ['id' => 1, 'transaction' => ['reference' => 'qTPrJoy9Bx']])->assertOk();
        $this->deliver($gateway, 'charge.dispute.create', ['transaction' => ['reference' => $payment->id]])->assertOk();
        $this->deliver($gateway, 'invoice.create', ['id' => 9])->assertOk();
        $this->assertSame(['500.00', null], [$this->balance(), $payment->fresh()->dispute]);
    }

    public function test_a_transaction_paystack_will_not_start_tells_staff_its_words_and_the_customer_only_that_it_is_down(): void
    {
        $gateway = $this->gateway();
        $gateway->forceFill(['config' => ['secretKey' => 'sk_live_wrong_now']])->save();
        $this->topUp($gateway, '500')->assertStatus(503)->assertExactJson(['message' => __('payments.gateway_down')]);
        $row = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'))->first();
        $this->assertSame(['failed', 'Invalid key'], [$row['status'], $row['error']]);
    }

    public function test_the_address_and_currencies_are_settings(): void
    {
        config(['kit.payments.paystack.api_url' => 'https://paystack.standin.example/', 'kit.payments.paystack.currencies' => ['EGP']]);
        Http::swap(new Factory);
        Http::fake(['paystack.standin.example/*' => Http::response(['status' => true, 'data' => []])]);
        $gateway = new PaymentGateway;
        $gateway->forceFill(['mode' => 'live', 'config' => ['secretKey' => self::LIVE_KEY]]);
        (new PaystackDriver)->verify($gateway);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://paystack.standin.example/transaction'));
        $this->assertSame(['EGP'], (new PaystackDriver)->currencies());
    }
}
