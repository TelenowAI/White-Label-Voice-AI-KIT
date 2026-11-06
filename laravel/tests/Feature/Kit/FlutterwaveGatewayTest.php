<?php

namespace Tests\Feature\Kit;

use App\Kit\Payments\Drivers\FlutterwaveDriver;
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
 * Flutterwave (App\Kit\Payments\Drivers\FlutterwaveDriver) through the kit's real routes, against a fake of its documented v3
 * API: the hosted page, verifying, refunds, chargebacks and the secret-hash webhook. The bodies follow Flutterwave's published
 * samples read 11-03. They are not recordings from a real account: none has been run yet.
 */
class FlutterwaveGatewayTest extends TestCase
{
    use RefreshDatabase;
    use TopsUpThroughAGateway;

    private const LIVE_KEY = 'FLWSECK-acmeAcmeAcme-X';

    private const TEST_KEY = 'FLWSECK_TEST-acmeAcmeAcme-X';

    private const HASH = 'a long random hash the operator chose';

    private const LINK = 'https://checkout.flutterwave.com/v3/hosted/pay/4b5e7a1c';

    /** @var array<string, array> transactions by tx_ref */
    private array $transactions = [];

    /** @var array<int, array> refunds by id */
    private array $refunds = [];

    /** @var list<array> */
    private array $chargebacks = [];

    private bool $refundListRefuses = false;

    private bool $down = false;

    /** Flutterwave answering 200 with `status: error`, or with no page to send the payer to. */
    private bool $saysNo = false;

    private bool $noPage = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install();
    }

    private function install(string $currency = 'NGN'): void
    {
        $this->installWith(function (Request $r) {
            if (! str_starts_with($r->url(), 'https://api.flutterwave.com/')) {
                return null;
            }
            $this->record($r);

            return $this->flutterwave($r);
        }, $currency);
    }

    private function flutterwave(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);
        if ($this->down) {
            return Http::response(['status' => 'error', 'message' => 'Try again'], 500);
        }
        if (str_contains($r->header('Authorization')[0] ?? '', 'wrong')) {
            return Http::response(['status' => 'error', 'message' => 'Invalid authorization key', 'data' => null], 401);
        }
        if ($this->saysNo) {
            return Http::response(['status' => 'error', 'message' => 'Not today']);
        }
        $ok = fn (mixed $data) => Http::response(['status' => 'success', 'message' => 'Done', 'data' => $data]);
        if ($path === '/v3/transactions') {
            return $ok([]);
        }
        if ($path === '/v3/payments') {
            return $ok($this->noPage ? [] : ['link' => self::LINK]);
        }
        if ($path === '/v3/transactions/verify_by_reference') {
            return isset($this->transactions[$query['tx_ref']]) ? $ok($this->transactions[$query['tx_ref']])
                : Http::response(['status' => 'error', 'message' => 'No transaction was found for this id', 'data' => null], 400);
        }
        if (preg_match('#^/v3/transactions/(\d+)/verify$#', $path, $m)) {
            foreach ($this->transactions as $t) {
                if ((string) $t['id'] === $m[1]) {
                    return $ok($t);
                }
            }

            return Http::response(['status' => 'error', 'message' => 'No transaction was found for this id', 'data' => null], 400);
        }
        if (preg_match('#^/v3/transactions/(\d+)/refund$#', $path, $m)) {
            $id = 7000 + count($this->refunds);
            $this->refunds[$id] = ['id' => $id, 'tx_id' => (int) $m[1], 'transaction_id' => (int) $m[1], 'flw_ref' => 'ZRIR'.$id, 'amount_refunded' => (float) $r->data()['amount'], 'status' => 'completed', 'comment' => $r->data()['comments'] ?? null, 'meta' => ['source' => 'availablebalance']];

            return $ok($this->refunds[$id]);
        }
        if ($path === '/v3/refunds') {
            return $this->refundListRefuses ? Http::response(['status' => 'error', 'message' => 'Refund Id Not Found', 'data' => null], 400) : $ok(array_values($this->refunds));
        }
        if (preg_match('#^/v3/refunds/(\d+)$#', $path, $m)) {
            // The page's sample has no `data` around it.
            return isset($this->refunds[(int) $m[1]]) ? Http::response($this->refunds[(int) $m[1]])
                : Http::response(['status' => 'error', 'message' => 'Error: Refund Id Not Found', 'data' => null], 400);
        }
        if ($path === '/v3/chargebacks') {
            return $ok(array_values(array_filter($this->chargebacks, fn (array $c) => $c['flw_ref'] === ($query['flw_ref'] ?? null))));
        }

        return Http::response(['status' => 'error', 'message' => 'Not found'], 404);
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
        $this->add('flutterwave', $mode, ['secretKey' => $mode === 'live' ? self::LIVE_KEY : self::TEST_KEY, 'secretHash' => self::HASH])->assertCreated();

        return PaymentGateway::query()->where('driver', 'flutterwave')->latest('position')->firstOrFail();
    }

    private function started(PaymentGateway $gateway, string $amount = '500'): Payment
    {
        return Payment::query()->findOrFail($this->topUp($gateway, $amount)->assertCreated()->json('payment.id'));
    }

    /** The payer tries on Flutterwave's page: answers the transaction as it now stands. */
    private function pays(Payment $payment, string $status = 'successful', string|int|float $amount = 500, string $currency = 'NGN'): array
    {
        $this->transactions[$payment->id] = ['id' => 288000 + count($this->transactions), 'tx_ref' => $payment->id, 'flw_ref' => 'FLW-MOCK-'.count($this->transactions), 'amount' => $amount, 'currency' => $currency, 'charged_amount' => 507, 'status' => $status];

        return $this->transactions[$payment->id];
    }

    private function paid(PaymentGateway $gateway): Payment
    {
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->comeBack($payment->id);

        return $payment;
    }

    private function deliver(PaymentGateway $gateway, array $body, ?string $hash = self::HASH): TestResponse
    {
        return $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => $hash]), json_encode($body));
    }

    private function refund(Payment $payment, string $amount, ?string $reference = null): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/refunds", ['amount' => $amount, 'reference' => $reference ?? (string) Str::uuid()]);
    }

    private function refunded(Payment $payment): string
    {
        return (string) BigDecimal::of($payment->fresh()->refunded)->toScale(2);
    }

    /** Its refund event, flat with no name, its `meta` a JSON string. */
    private function refundEvent(int $id, int $transactionId, string $disburse = 'successful'): array
    {
        return ['id' => $id, 'AmountRefunded' => 100, 'status' => 'completed', 'FlwRef' => '4687213286', 'destination' => 'payment_source', 'comments' => null, 'settlement_id' => '142228',
            'meta' => json_encode(['source' => 'ledgerbalance', 'isPartialRefund' => true, 'disburse_status' => $disburse]), 'AccountId' => 169295, 'TransactionId' => $transactionId];
    }

    private function chargeback(Payment $payment, string $status, int $id = 91, int $amount = 500): string
    {
        $flw = $this->transactions[$payment->id]['flw_ref'];
        $this->chargebacks = array_values(array_filter($this->chargebacks, fn (array $c) => $c['id'] !== $id));
        $this->chargebacks[] = ['id' => $id, 'amount' => $amount, 'flw_ref' => $flw, 'tx_ref' => $payment->id, 'status' => $status, 'stage' => 'new'];

        return $flw;
    }

    public function test_it_is_a_way_to_pay_drawn_from_its_own_form_with_nothing_to_tick(): void
    {
        $driver = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('drivers'))->firstWhere('id', 'flutterwave');
        $this->assertSame(['Flutterwave', true, []], [$driver['name'], $driver['online'], $driver['methods']]);
        $this->assertSame(['secretKey', 'secretHash'], array_column($driver['fields'], 'name'));
        $this->assertSame(['secret', 'secret'], array_column($driver['fields'], 'type'));

        $gateway = $this->gateway();
        $saved = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('gateways'))->firstWhere('id', $gateway->id);
        $this->assertSame([], $saved['webhookEvents']);
        $this->assertStringNotContainsString(self::LIVE_KEY, json_encode($saved));
        $this->assertStringNotContainsString(self::HASH, json_encode($saved));
    }

    public function test_keys_are_tried_and_the_wrong_modes_keys_are_refused_before_any_call(): void
    {
        $add = fn (string $mode, string $key) => $this->add('flutterwave', $mode, ['secretKey' => $key, 'secretHash' => self::HASH]);
        $add('live', 'FLWSECK-wrong-X')->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Invalid authorization key');
        $this->sent = [];
        $add('live', self::TEST_KEY)->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Test keys, and this way to pay is set to Live.');
        $add('test', self::LIVE_KEY)->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Live keys, and this way to pay is set to Test.');
        $this->assertSame([], $this->sent, 'refused before any call');
        $this->add('flutterwave', 'live', ['secretKey' => self::LIVE_KEY])->assertStatus(422)->assertJsonValidationErrors(['values.secretHash']);
    }

    public function test_secrets_left_empty_are_kept(): void
    {
        $gateway = $this->gateway();
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['values' => ['secretKey' => '', 'secretHash' => '']])->assertOk();
        $this->assertSame([self::LIVE_KEY, self::HASH], [$gateway->fresh()->config['secretKey'], $gateway->fresh()->config['secretHash']]);
    }

    public function test_a_200_that_says_error_is_a_no_and_an_answer_with_no_page_is_refused(): void
    {
        $this->saysNo = true;
        $this->add('flutterwave', 'live', ['secretKey' => self::LIVE_KEY, 'secretHash' => self::HASH])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Not today');
        $this->saysNo = false;
        $gateway = $this->gateway();
        $this->noPage = true;
        $this->topUp($gateway, '500')->assertStatus(503);
        $this->assertSame('failed', Payment::query()->firstOrFail()->status);
    }

    public function test_a_payer_goes_to_flutterwaves_page_with_the_amount_in_full_units_and_the_payment_as_its_tx_ref(): void
    {
        $gateway = $this->gateway();
        $started = $this->topUp($gateway, '500')->assertCreated();
        $id = $started->json('payment.id');
        $this->assertSame(['redirect', self::LINK], [$started->json('checkout.kind'), $started->json('checkout.url')]);
        $call = $this->sentTo('POST', '/v3/payments')[0];
        $this->assertSame([$id, '500.00', 'NGN', self::SITE."/api/kit/payments/{$id}/return", 'ana@dental.example', 'Ana'],
            [$call['body']['tx_ref'], $call['body']['amount'], $call['body']['currency'], $call['body']['redirect_url'], $call['body']['customer']['email'], $call['body']['customer']['name']]);
        $this->assertSame('Bearer '.self::LIVE_KEY, $call['request']->header('Authorization')[0]);
        $this->assertNull(Payment::query()->findOrFail($id)->gateway_ref, 'it has no id for the payment until the payer tries');
    }

    public function test_someone_with_no_email_is_asked_for_one_for_this_payment_alone(): void
    {
        $gateway = $this->gateway();
        $phoneOnly = User::create(['name' => 'Ben', 'email' => null, 'password' => 'a long password 5']);
        $phoneOnly->forceFill(['phone' => '+2348000000001'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $phoneOnly->id, 'role' => 'member']);

        $this->actingAs($phoneOnly)->getJson('/api/kit/console/payments')->assertOk()->assertJsonPath('gateways.0.asks', ['email']);
        $this->topUp($gateway, '500', $phoneOnly)->assertStatus(422)->assertJsonValidationErrors(['payer.email']);
        $id = $this->topUp($gateway, '500', $phoneOnly, ['email' => 'ben@clinic.example'])->assertCreated()->json('payment.id');
        $this->assertSame('ben@clinic.example', $this->sentTo('POST', '/v3/payments')[0]['body']['customer']['email']);
        $this->assertNull($phoneOnly->fresh()->email);
        $this->assertStringNotContainsString('ben@clinic.example', json_encode(Payment::query()->findOrFail($id)->details), 'not kept on the payment');
    }

    public function test_coming_back_asks_flutterwave_and_what_the_return_says_is_never_believed(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->comeBack($payment->id, ['status' => 'successful', 'tx_ref' => $payment->id, 'transaction_id' => 288000]);
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()], 'Flutterwave has no such transaction yet');

        $paid = $this->pays($payment);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '500.00', (string) $paid['id']], [$payment->fresh()->status, $this->balance(), $payment->fresh()->gateway_ref]);
        $this->assertSame($payment->id, urldecode(str_replace('tx_ref=', '', $this->sentTo('GET', '/v3/transactions/verify_by_reference')[1]['query'])));
        $this->comeBack($payment->id);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_it_counts_the_amount_asked_and_not_what_was_charged_with_fees(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway, '500.50');
        $this->pays($payment, 'successful', 500.5);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '500.50'], [$payment->fresh()->status, $this->balance()]);
    }

    public function test_an_amount_with_places_its_currency_does_not_have_is_not_rounded_to_what_was_asked(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment, 'successful', 500.004);
        $this->comeBack($payment->id);
        $this->assertSame(['review', '500.004', []], [$payment->fresh()->status, rtrim($payment->fresh()->received, '0'), $this->entries()]);
    }

    public function test_a_failed_try_fails_one_still_going_stays_open_and_a_later_success_still_credits(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment, 'pending');
        $this->comeBack($payment->id);
        $this->assertSame('open', $payment->fresh()->status);
        $this->pays($payment, 'failed');
        $this->comeBack($payment->id);
        $this->assertSame('failed', $payment->fresh()->status);

        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => $this->pays($payment)])->assertOk();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()], 'money taken is money credited');
    }

    public function test_kit_payments_asks_about_one_the_payer_never_came_back_from(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->travel((new FlutterwaveDriver)->checkAfterMinutes() + 1)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
    }

    public function test_the_hash_is_needed_and_an_event_is_taken_with_a_plain_200_and_only_ever_asks(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $paid = $this->pays($payment);
        $event = ['event' => 'charge.completed', 'data' => $paid];

        $this->deliver($gateway, $event, 'forged')->assertUnauthorized();
        $this->deliver($gateway, $event, null)->assertUnauthorized();
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->deliver($gateway, $event)->assertStatus(200);
        $this->deliver($gateway, $event)->assertStatus(200);
        $this->assertSame(['paid', '500.00'], [$payment->fresh()->status, $this->balance()]);
        $this->assertCount(1, $this->entries(), 'credited once');
        $this->assertCount(2, $this->sentTo('GET', "/v3/transactions/{$paid['id']}/verify"));
    }

    public function test_an_event_with_the_right_hash_and_a_made_up_body_moves_no_money(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $failed = $this->pays($payment, 'failed');
        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => ['status' => 'successful', 'amount' => 500] + $failed])->assertOk();
        $this->assertSame(['failed', []], [$payment->fresh()->status, $this->entries()], 'what counts is what Flutterwave answers');
    }

    public function test_another_amount_waits_for_staff_and_another_currency_too(): void
    {
        $gateway = $this->gateway();
        $one = $this->started($gateway);
        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => $this->pays($one, 'successful', 400)])->assertOk();
        $this->assertSame(['review', '400', []], [$one->fresh()->status, (string) BigDecimal::of($one->fresh()->received)->toScale(0), $this->entries()]);

        $two = $this->started($gateway);
        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => $this->pays($two, 'successful', 500, 'USD')])->assertOk();
        $this->assertSame(['review', 'USD'], [$two->fresh()->status, $two->fresh()->details['receivedCurrency']]);
    }

    public function test_a_refund_carries_its_reference_and_its_event_counts_nothing_more(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $tx = $this->transactions[$payment->id]['id'];
        $reference = (string) Str::uuid();

        $this->refund($payment, '100', $reference)->assertOk();
        $call = $this->sentTo('POST', "/v3/transactions/{$tx}/refund")[0];
        $this->assertSame(['100.00', $reference, self::SITE."/api/kit/payments/webhooks/{$gateway->id}"], [$call['body']['amount'], $call['body']['comments'], $call['body']['callbackurl']]);
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);
        $this->assertSame(['refund', '-100.00'], array_slice(collect($this->entries())->last(), 0, 2), 'at the currency\'s places, as Flutterwave\'s 100.0 was not');

        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertCount(1, $this->sentTo('POST', "/v3/transactions/{$tx}/refund"), 'one click is one refund');

        $id = array_key_first($this->refunds);
        $this->deliver($gateway, $this->refundEvent($id, $tx))->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'the same refund, by its own id');
    }

    public function test_a_refund_whose_answer_was_lost_is_found_by_its_reference_and_not_sent_again(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $tx = $this->transactions[$payment->id]['id'];
        $reference = (string) Str::uuid();
        $this->refunds[6999] = ['id' => 6999, 'transaction_id' => $tx, 'amount_refunded' => 100, 'status' => 'completed', 'comment' => $reference];

        $this->refund($payment, '100', $reference)->assertOk();
        $this->assertSame([], $this->sentTo('POST', "/v3/transactions/{$tx}/refund"));
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_list_that_refuses_means_none_was_sent(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->refundListRefuses = true;
        $this->refund($payment, '100')->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_made_on_its_dashboard_finds_its_payment_through_the_transaction(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $tx = $this->transactions[$payment->id]['id'];
        $this->refunds[11] = ['id' => 11, 'transaction_id' => $tx, 'amount_refunded' => 100, 'status' => 'completed-mpgs', 'comment' => null, 'meta' => '{"source":"ledgerbalance"}'];

        $this->deliver($gateway, $this->refundEvent(11, $tx))->assertOk();
        $this->deliver($gateway, $this->refundEvent(11, $tx))->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_events_body_decides_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $tx = $this->transactions[$payment->id]['id'];
        $this->refunds[11] = ['id' => 11, 'transaction_id' => $tx, 'amount_refunded' => 100, 'status' => 'completed-mpgs', 'comment' => null, 'meta' => '{"source":"ledgerbalance"}'];

        $this->deliver($gateway, ['AmountRefunded' => 400] + $this->refundEvent(11, $tx))->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'what counts is what Flutterwave answers');
        $this->deliver($gateway, $this->refundEvent(11, $tx, 'failed'))->assertOk();
        $this->assertSame(['100.00', '400.00'], [$this->refunded($payment), $this->balance()], 'failed in the body, fine at Flutterwave');
    }

    public function test_a_refund_whose_money_could_not_be_sent_goes_back_on_the_wallet_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $tx = $this->transactions[$payment->id]['id'];
        $this->refund($payment, '100')->assertOk();
        $id = array_key_first($this->refunds);

        $this->refunds[$id]['status'] = 'completed';
        $this->refunds[$id]['meta'] = '{"source":"ledgerbalance","disburse_status":"failed"}';
        $this->deliver($gateway, $this->refundEvent($id, $tx, 'failed'))->assertOk();
        $this->deliver($gateway, $this->refundEvent($id, $tx, 'failed'))->assertOk();
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_refund_flutterwave_answers_as_failed_counts_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $reference = (string) Str::uuid();
        $this->refunds[6998] = ['id' => 6998, 'transaction_id' => $this->transactions[$payment->id]['id'], 'amount_refunded' => 100, 'status' => 'failed', 'comment' => $reference];
        $this->refund($payment, '100', $reference)->assertStatus(502);
        $this->assertSame(['0.00', '500.00'], [$this->refunded($payment), $this->balance()]);
    }

    public function test_a_chargeback_holds_and_won_gives_it_back_and_contesting_it_changes_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $flw = $this->chargeback($payment, 'initiated');
        $this->deliver($gateway, ['event' => 'chargeback.initiated', 'data' => ['id' => 91, 'flw_ref' => $flw, 'amount' => 500, 'status' => 'initiated']])->assertOk();
        $this->assertSame(['open', '0.00'], [$payment->fresh()->dispute, $this->balance()]);

        $this->chargeback($payment, 'declined');
        $this->deliver($gateway, ['event' => 'chargeback.declined', 'data' => ['id' => 91, 'flw_ref' => $flw, 'status' => 'declined']])->assertOk();
        $this->assertSame(['open', '0.00'], [$payment->fresh()->dispute, $this->balance()], 'the operator contesting it decides nothing yet');

        $this->chargeback($payment, 'won');
        $this->deliver($gateway, ['event' => 'chargeback.won', 'data' => ['id' => 91, 'flw_ref' => $flw, 'status' => 'won']])->assertOk();
        $this->assertSame(['won', '500.00'], [$payment->fresh()->dispute, $this->balance()]);

        $this->chargeback($payment, 'declined');
        $this->deliver($gateway, ['event' => 'chargeback.declined', 'data' => ['id' => 91, 'flw_ref' => $flw, 'status' => 'declined']])->assertOk();
        $this->assertSame(['won', '500.00'], [$payment->fresh()->dispute, $this->balance()], 'told late, it opens nothing again');
    }

    public function test_a_chargeback_accepted_or_lost_comes_off_once_and_its_outcome_comes_from_flutterwave(): void
    {
        foreach (['accepted', 'lost'] as $i => $status) {
            $gateway = $this->gateway();
            $payment = $this->paid($gateway);
            $before = (float) $this->balance();
            $flw = $this->chargeback($payment, 'initiated', 200 + $i);
            $this->deliver($gateway, ['event' => "chargeback.{$status}", 'data' => ['id' => 200 + $i, 'flw_ref' => $flw, 'status' => $status]])->assertOk();
            $this->assertSame('open', $payment->fresh()->dispute, 'the body says lost, Flutterwave says still initiated');

            $this->chargeback($payment, $status, 200 + $i);
            $this->deliver($gateway, ['event' => "chargeback.{$status}", 'data' => ['id' => 200 + $i, 'flw_ref' => $flw, 'status' => $status]])->assertOk();
            $this->deliver($gateway, ['event' => "chargeback.{$status}", 'data' => ['id' => 200 + $i, 'flw_ref' => $flw, 'status' => $status]])->assertOk();
            $this->assertSame('lost', $payment->fresh()->dispute);
            $this->assertEqualsWithDelta($before - 500, (float) $this->balance(), 0.001, $status);
        }
    }

    public function test_events_about_payments_that_are_not_the_kits_change_nothing_and_a_body_with_no_shape_is_ignored(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->sent = [];
        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => ['id' => 5, 'tx_ref' => 'order-1234', 'status' => 'successful', 'amount' => 100, 'currency' => 'NGN']])->assertOk();
        $this->assertSame([], $this->sent, 'someone else\'s sale: nothing to ask');

        $this->deliver($gateway, ['event' => 'chargeback.initiated', 'data' => ['id' => 5]])->assertOk();
        $this->deliver($gateway, ['event' => 'transfer.completed', 'data' => ['id' => 5]])->assertOk();
        $this->deliver($gateway, ['just' => 'something'])->assertOk();
        $this->deliver($gateway, ['id' => 5])->assertOk();
        $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => self::HASH], 'not json')->assertOk();
        $this->assertSame([], $this->sent);
        $this->assertSame(['500.00', null], [$this->balance(), $payment->fresh()->dispute]);
    }

    public function test_asking_while_flutterwave_is_down_is_refused_so_it_is_sent_again(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $paid = $this->pays($payment);
        $this->down = true;
        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => $paid])->assertUnauthorized();
        $this->assertSame('open', $payment->fresh()->status);

        $this->down = false;
        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => $paid])->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_a_test_payment_runs_through_it_and_credits_no_one(): void
    {
        $gateway = $this->gateway('test');
        $id = $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$gateway->id}/test", ['amount' => '10'])->assertCreated()->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->deliver($gateway, ['event' => 'charge.completed', 'data' => $this->pays($payment, 'successful', 10)])->assertOk();
        $this->assertSame(['paid', []], [$payment->fresh()->status, $this->entries()]);
    }

    public function test_the_address_is_a_setting(): void
    {
        config(['kit.payments.flutterwave.api_url' => 'https://flw.standin.example/']);
        Http::swap(new Factory);
        Http::fake(['flw.standin.example/*' => Http::response(['status' => 'success', 'data' => []])]);
        $gateway = new PaymentGateway;
        $gateway->forceFill(['mode' => 'live', 'config' => ['secretKey' => self::LIVE_KEY, 'secretHash' => self::HASH]]);
        (new FlutterwaveDriver)->verify($gateway);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://flw.standin.example/v3/transactions'));
    }
}
