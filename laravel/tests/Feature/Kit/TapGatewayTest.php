<?php

namespace Tests\Feature\Kit;

use App\Kit\Payments\Drivers\TapDriver;
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
 * Tap (App\Kit\Payments\Drivers\TapDriver) through the kit's real routes, against a fake of its documented API: charges on its
 * hosted page, refunds and the `hashstring` webhook. The hashes are written out here from Tap's formula, independently of the
 * driver's code. The bodies follow Tap's published samples read 11-03. They are not recordings from a real account: none has
 * been run yet.
 */
class TapGatewayTest extends TestCase
{
    use RefreshDatabase;
    use TopsUpThroughAGateway;

    private const LIVE_KEY = 'sk_live_acmeAcmeAcme';

    private const TEST_KEY = 'sk_test_acmeAcmeAcme';

    private const PAGE = 'https://checkout.tap.company/?mode=page&token=abc';

    /** @var array<string, array> charges by id */
    private array $charges = [];

    /** @var array<string, array> refunds by their idempotent reference */
    private array $refunds = [];

    private string $refundStatus = 'PENDING';

    private bool $noPage = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->install('KWD');
    }

    private function install(string $currency): void
    {
        config(['kit.payments.currency_decimals' => ['KWD' => 3, 'SAR' => 2]]);
        $this->installWith(function (Request $r) {
            if (! str_starts_with($r->url(), 'https://api.tap.company/')) {
                return null;
            }
            $this->record($r);

            return $this->tap($r);
        }, $currency);
    }

    private function tap(Request $r): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        if (str_contains($r->header('Authorization')[0] ?? '', 'wrong')) {
            return Http::response(['errors' => [['code' => '1111', 'description' => 'Invalid API key']]], 401);
        }
        if ($path === '/v2/charges/list') {
            return Http::response(['object' => 'list', 'charges' => [], 'count' => 0, 'has_more' => false]);
        }
        if ($path === '/v2/charges') {
            $data = $r->data();
            $id = 'chg_TS'.Str::random(16);
            $this->charges[$id] = ['id' => $id, 'object' => 'charge', 'status' => 'INITIATED', 'amount' => $data['amount'], 'currency' => $data['currency'],
                'reference' => ['track' => 'tck_1', 'payment' => '5407', 'gateway' => '1234', 'transaction' => $data['reference']['transaction'], 'order' => $data['reference']['order']],
                'transaction' => ['url' => self::PAGE, 'created' => '1696500000000'], 'metadata' => []];

            return Http::response($this->noPage ? ['id' => $id, 'status' => 'INITIATED'] : $this->charges[$id]);
        }
        if (preg_match('#^/v2/charges/(chg_\w+)$#', $path, $m)) {
            return isset($this->charges[$m[1]]) ? Http::response($this->charges[$m[1]]) : Http::response(['errors' => [['description' => 'Charge not found']]], 404);
        }
        if ($path === '/v2/refunds') {
            $data = $r->data();
            $key = $data['reference']['idempotent'];
            $this->refunds[$key] ??= ['id' => 're_'.Str::random(14), 'object' => 'refund', 'amount' => $data['amount'], 'currency' => $data['currency'], 'charge_id' => $data['charge_id'], 'status' => $this->refundStatus,
                'reference' => ['gateway' => '77', 'payment' => '88', 'merchant' => $data['reference']['merchant']], 'created' => 1696500099000];

            return Http::response($this->refunds[$key]);
        }

        return Http::response(['errors' => [['description' => 'Not found']]], 404);
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
        $this->add('tap', $mode, ['secretKey' => $mode === 'live' ? self::LIVE_KEY : self::TEST_KEY])->assertCreated();

        return PaymentGateway::query()->where('driver', 'tap')->latest('position')->firstOrFail();
    }

    private function started(PaymentGateway $gateway, string $amount = '5.250'): Payment
    {
        return Payment::query()->findOrFail($this->topUp($gateway, $amount)->assertCreated()->json('payment.id'));
    }

    /** The payer pays on Tap's page: answers the charge as it now stands. */
    private function pays(Payment $payment, string $status = 'CAPTURED', ?float $amount = null, ?string $currency = null): array
    {
        $charge = &$this->charges[$payment->gateway_ref];
        $charge['status'] = $status;
        $charge['amount'] = $amount ?? $charge['amount'];
        $charge['currency'] = $currency ?? $charge['currency'];

        return $charge;
    }

    private function paid(PaymentGateway $gateway): Payment
    {
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->comeBack($payment->id);

        return $payment;
    }

    private function send(PaymentGateway $gateway, array $body, string $hash): TestResponse
    {
        return $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_HASHSTRING' => $hash], json_encode($body));
    }

    /** A charge's event, its hash built from Tap's formula: the amount at the given places. */
    private function deliverCharge(PaymentGateway $gateway, array $charge, string $amount, string $key = self::LIVE_KEY): TestResponse
    {
        $text = "x_id{$charge['id']}x_amount{$amount}x_currency{$charge['currency']}x_gateway_reference{$charge['reference']['gateway']}x_payment_reference{$charge['reference']['payment']}x_status{$charge['status']}x_created{$charge['transaction']['created']}";

        return $this->send($gateway, $charge, hash_hmac('sha256', $text, $key));
    }

    private function deliverRefund(PaymentGateway $gateway, array $refund, string $amount): TestResponse
    {
        $text = "x_id{$refund['id']}x_amount{$amount}x_currency{$refund['currency']}x_gateway_reference{$refund['reference']['gateway']}x_payment_reference{$refund['reference']['payment']}x_status{$refund['status']}x_created{$refund['created']}";

        return $this->send($gateway, $refund, hash_hmac('sha256', $text, self::LIVE_KEY));
    }

    private function refund(Payment $payment, string $amount, ?string $reference = null): TestResponse
    {
        return $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/refunds", ['amount' => $amount, 'reference' => $reference ?? (string) Str::uuid()]);
    }

    private function refunded(Payment $payment): string
    {
        return (string) BigDecimal::of($payment->fresh()->refunded)->toScale(3);
    }

    public function test_it_is_a_way_to_pay_drawn_from_its_own_form_with_nothing_to_tick(): void
    {
        $driver = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('drivers'))->firstWhere('id', 'tap');
        $this->assertSame(['Tap', true, []], [$driver['name'], $driver['online'], $driver['methods']]);
        $this->assertSame(['secretKey'], array_column($driver['fields'], 'name'));
        $this->assertSame(['secret'], array_column($driver['fields'], 'type'));

        $gateway = $this->gateway();
        $saved = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('gateways'))->firstWhere('id', $gateway->id);
        $this->assertSame([], $saved['webhookEvents']);
        $this->assertStringNotContainsString(self::LIVE_KEY, json_encode($saved));
    }

    public function test_keys_are_tried_and_the_wrong_modes_keys_are_refused_before_any_call(): void
    {
        $this->add('tap', 'live', ['secretKey' => 'sk_live_wrong'])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Invalid API key');
        $this->sent = [];
        $this->add('tap', 'live', ['secretKey' => self::TEST_KEY])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Test keys, and this way to pay is set to Live.');
        $this->add('tap', 'test', ['secretKey' => self::LIVE_KEY])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are Live keys, and this way to pay is set to Test.');
        $this->assertSame([], $this->sent, 'refused before any call');
    }

    public function test_a_secret_left_empty_is_kept(): void
    {
        $gateway = $this->gateway();
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['values' => ['secretKey' => '']])->assertOk();
        $this->assertSame(self::LIVE_KEY, $gateway->fresh()->config['secretKey']);
    }

    public function test_an_answer_with_no_page_is_refused_and_a_charge_tap_will_not_make_tells_staff_its_words(): void
    {
        $gateway = $this->gateway();
        $this->noPage = true;
        $this->topUp($gateway, '5.250')->assertStatus(503);
        $this->assertSame('failed', Payment::query()->firstOrFail()->status);
        $this->noPage = false;
        $gateway->forceFill(['config' => ['secretKey' => 'sk_live_wrong_now']])->save();
        $this->topUp($gateway, '5.250')->assertStatus(503)->assertExactJson(['message' => __('payments.gateway_down')]);
        $this->assertContains('Invalid API key', array_column($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('recent'), 'error'));
    }

    public function test_a_payer_goes_to_taps_page_and_each_charge_says_where_its_events_go(): void
    {
        $gateway = $this->gateway();
        $started = $this->topUp($gateway, '5.250')->assertCreated();
        $id = $started->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->assertSame(['redirect', self::PAGE], [$started->json('checkout.kind'), $started->json('checkout.url')]);
        $call = $this->sentTo('POST', '/v2/charges')[0];
        $this->assertSame([5.25, 'KWD', 'src_all', self::SITE."/api/kit/payments/webhooks/{$gateway->id}", self::SITE."/api/kit/payments/{$id}/return"],
            [$call['body']['amount'], $call['body']['currency'], $call['body']['source']['id'], $call['body']['post']['url'], $call['body']['redirect']['url']]);
        $this->assertSame(['Ana', 'ana@dental.example', $id], [$call['body']['customer']['first_name'], $call['body']['customer']['email'], $call['body']['reference']['transaction']]);
        $this->assertSame('Bearer '.self::LIVE_KEY, $call['request']->header('Authorization')[0]);
        $this->assertStringStartsWith('chg_', $payment->gateway_ref);
    }

    public function test_a_currency_it_does_not_list_is_not_offered(): void
    {
        $this->chargeIn('NGN');
        $gateway = $this->gateway();
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertOk()->assertJsonPath('gateways', []);
        $this->topUp($gateway, '10')->assertStatus(409);
    }

    public function test_someone_with_no_email_is_asked_for_one_for_this_payment_alone(): void
    {
        $gateway = $this->gateway();
        $phoneOnly = User::create(['name' => 'Ben', 'email' => null, 'password' => 'a long password 5']);
        $phoneOnly->forceFill(['phone' => '+96550000001'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $phoneOnly->id, 'role' => 'member']);

        $this->actingAs($phoneOnly)->getJson('/api/kit/console/payments')->assertOk()->assertJsonPath('gateways.0.asks', ['email']);
        $this->topUp($gateway, '5.250', $phoneOnly)->assertStatus(422)->assertJsonValidationErrors(['payer.email']);
        $id = $this->topUp($gateway, '5.250', $phoneOnly, ['email' => 'ben@clinic.example'])->assertCreated()->json('payment.id');
        $this->assertSame(['Ben', 'ben@clinic.example'], [$this->sentTo('POST', '/v2/charges')[0]['body']['customer']['first_name'], $this->sentTo('POST', '/v2/charges')[0]['body']['customer']['email']]);
        $this->assertNull($phoneOnly->fresh()->email);
        $this->assertStringNotContainsString('ben@clinic.example', json_encode(Payment::query()->findOrFail($id)->details), 'not kept on the payment');
    }

    public function test_coming_back_asks_tap_and_what_the_return_says_is_never_believed(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->comeBack($payment->id, ['tap_id' => 'chg_forged', 'status' => 'CAPTURED']);
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);
        $this->assertSame([], $this->sentTo('GET', '/v2/charges/chg_forged'), 'it asks by its own id for the charge');

        $this->pays($payment);
        $this->comeBack($payment->id);
        $this->assertSame(['paid', '5.250', '5.250'], [$payment->fresh()->status, (string) BigDecimal::of($payment->fresh()->received)->toScale(3), (string) BigDecimal::of($this->balance())->toScale(3)]);
        $this->comeBack($payment->id);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_each_way_a_charge_ends_badly_fails_and_unknown_stays_open(): void
    {
        $gateway = $this->gateway();
        foreach (['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT'] as $status) {
            $payment = $this->started($gateway);
            $this->pays($payment, $status);
            $this->comeBack($payment->id);
            $this->assertSame('failed', $payment->fresh()->status, $status);
        }
        foreach (['INITIATED', 'UNKNOWN', 'SOMETHING_NEW'] as $status) {
            $payment = $this->started($gateway);
            $this->pays($payment, $status);
            $this->comeBack($payment->id);
            $this->assertSame('open', $payment->fresh()->status, $status);
        }
        $this->assertSame([], $this->entries());
    }

    public function test_kit_payments_asks_about_one_the_payer_never_came_back_from(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $this->pays($payment);
        $this->travel((new TapDriver)->checkAfterMinutes() + 1)->minutes();
        $this->artisan('kit:payments')->assertSuccessful();
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_the_hash_is_needed_three_decimals_in_kuwait_and_an_event_credits_once(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $charge = $this->pays($payment);

        $this->send($gateway, $charge, 'forged')->assertUnauthorized();
        $this->send($gateway, $charge, '')->assertUnauthorized();
        $this->deliverCharge($gateway, $charge, '5.250', 'sk_live_another')->assertUnauthorized();
        $this->deliverCharge($gateway, $charge, '5.25')->assertUnauthorized();
        $this->assertSame(['open', []], [$payment->fresh()->status, $this->entries()]);

        $this->deliverCharge($gateway, $charge, '5.250')->assertStatus(200);
        $this->deliverCharge($gateway, $charge, '5.250')->assertStatus(200);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertCount(1, $this->entries(), 'credited once');
    }

    public function test_two_decimals_where_the_currency_has_two(): void
    {
        $this->chargeIn('SAR');
        $gateway = $this->gateway();
        $payment = $this->started($gateway, '75.50');
        $charge = $this->pays($payment);
        $this->deliverCharge($gateway, $charge, '75.500')->assertUnauthorized();
        $this->deliverCharge($gateway, $charge, '75.50')->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_what_the_signature_does_not_cover_decides_nothing(): void
    {
        $gateway = $this->gateway();
        $mine = $this->started($gateway);
        $victim = $this->started($gateway);
        $charge = $this->pays($mine);
        $charge['reference']['transaction'] = $victim->id;
        $charge['metadata'] = ['kit_payment_id' => $victim->id];

        $this->deliverCharge($gateway, $charge, '5.250')->assertOk();
        $this->assertSame(['paid', 'open'], [$mine->fresh()->status, $victim->fresh()->status], 'the signed id found the payment');
    }

    public function test_a_change_to_anything_the_signature_covers_is_refused(): void
    {
        $gateway = $this->gateway();
        $payment = $this->started($gateway);
        $charge = $this->pays($payment, 'DECLINED');
        $text = "x_id{$charge['id']}x_amount5.250x_currency{$charge['currency']}x_gateway_reference{$charge['reference']['gateway']}x_payment_reference{$charge['reference']['payment']}x_status{$charge['status']}x_created{$charge['transaction']['created']}";
        $hash = hash_hmac('sha256', $text, self::LIVE_KEY);
        foreach ([['status' => 'CAPTURED'], ['amount' => 1.0], ['currency' => 'USD'], ['id' => 'chg_other'], ['reference' => ['gateway' => '9'] + $charge['reference']], ['reference' => ['payment' => '9'] + $charge['reference']], ['transaction' => ['created' => '1'] + $charge['transaction']]] as $change) {
            $this->send($gateway, array_replace($charge, $change), $hash)->assertUnauthorized();
        }
        $this->send($gateway, $charge, $hash)->assertOk();
        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_a_body_that_is_not_an_event_is_refused_without_a_crash(): void
    {
        $gateway = $this->gateway();
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_HASHSTRING' => 'x'];
        $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [], $headers, 'not json')->assertUnauthorized();
        foreach ([['amount' => 'a lot', 'currency' => 'KWD'], ['amount' => 1, 'currency' => 'en@junk=1'], ['amount' => 1, 'currency' => str_repeat('A', 200000)], ['amount' => 1, 'currency' => ['KWD']], ['amount' => 1, 'currency' => 'KWD', 'transaction' => ['created' => ['1']]], ['amount' => 1, 'currency' => 'KWD', 'status' => ['x']], ['amount' => 1, 'currency' => 'KWD', 'reference' => ['gateway' => ['x'], 'payment' => ['y']]]] as $body) {
            $this->send($gateway, $body + ['id' => 'chg_1', 'object' => 'charge'], 'x')->assertUnauthorized();
        }
        $this->send($gateway, ['id' => ['chg_1'], 'object' => 'charge', 'amount' => 1, 'currency' => 'KWD'], 'x')->assertUnauthorized();
        $this->assertNull($gateway->fresh()->last_event_at);
    }

    public function test_another_amount_waits_for_staff_and_another_currency_too(): void
    {
        $gateway = $this->gateway();
        $one = $this->started($gateway);
        $this->deliverCharge($gateway, $this->pays($one, 'CAPTURED', 1.000), '1.000')->assertOk();
        $this->assertSame(['review', []], [$one->fresh()->status, $this->entries()]);

        $two = $this->started($gateway);
        $this->deliverCharge($gateway, $this->pays($two, 'CAPTURED', 5.25, 'USD'), '5.25')->assertOk();
        $this->assertSame(['review', 'USD', '5.25'], [$two->fresh()->status, $two->fresh()->details['receivedCurrency'], (string) $two->fresh()->received], 'at the places of the currency it came in');
    }

    public function test_a_refund_is_made_safe_to_send_twice_and_its_event_counts_nothing_more(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $reference = (string) Str::uuid();

        $this->refund($payment, '1.250', $reference)->assertOk();
        $call = $this->sentTo('POST', '/v2/refunds')[0];
        $this->assertSame([$payment->gateway_ref, 1.25, 'KWD', 'requested_by_customer', $reference, $reference, self::SITE."/api/kit/payments/webhooks/{$gateway->id}"],
            [$call['body']['charge_id'], $call['body']['amount'], $call['body']['currency'], $call['body']['reason'], $call['body']['reference']['merchant'], $call['body']['reference']['idempotent'], $call['body']['post']['url']]);
        $this->assertSame(['1.250', '4.000'], [$this->refunded($payment), (string) BigDecimal::of($this->balance())->toScale(3)]);

        $this->refund($payment, '1.250', $reference)->assertOk();
        $this->assertCount(1, $this->sentTo('POST', '/v2/refunds'), 'one click is one refund');

        $done = ['status' => 'REFUNDED'] + $this->refunds[$reference];
        $this->deliverRefund($gateway, $done, '1.250')->assertOk();
        $this->assertSame(['1.250', '4.000'], [$this->refunded($payment), (string) BigDecimal::of($this->balance())->toScale(3)]);
    }

    public function test_a_refund_that_fails_later_goes_back_on_the_wallet_once_and_one_that_says_nothing_changes_nothing(): void
    {
        foreach (['DECLINED', 'FAILED', 'RESTRICTED', 'REJECTED'] as $status) {
            $gateway = $this->gateway();
            $payment = $this->paid($gateway);
            $reference = (string) Str::uuid();
            $this->refund($payment, '1.250', $reference)->assertOk();
            $this->assertSame('1.250', $this->refunded($payment));

            foreach (['UNKNOWN', 'TIMED_OUT'] as $silent) {
                $this->deliverRefund($gateway, ['status' => $silent] + $this->refunds[$reference], '1.250')->assertOk();
                $this->assertSame('1.250', $this->refunded($payment), $silent);
            }
            $this->deliverRefund($gateway, ['status' => $status] + $this->refunds[$reference], '1.250')->assertOk();
            $this->deliverRefund($gateway, ['status' => $status] + $this->refunds[$reference], '1.250')->assertOk();
            $this->assertSame('0.000', $this->refunded($payment), $status);
        }
    }

    public function test_a_refund_made_on_taps_dashboard_counts_once_by_its_id(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $refund = ['id' => 're_dashboard', 'object' => 'refund', 'amount' => 1.25, 'currency' => 'KWD', 'charge_id' => $payment->gateway_ref, 'status' => 'REFUNDED', 'reference' => ['gateway' => '1', 'payment' => '2'], 'created' => 1696500099000];
        $this->deliverRefund($gateway, ['status' => 'UNKNOWN'] + $refund, '1.250')->assertOk();
        $this->assertSame('0.000', $this->refunded($payment), 'UNKNOWN says nothing yet');

        $this->deliverRefund($gateway, $refund, '1.250')->assertOk();
        $this->deliverRefund($gateway, $refund, '1.250')->assertOk();
        $this->assertSame('1.250', $this->refunded($payment));
    }

    public function test_a_refund_tap_answers_as_failed_counts_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $this->refundStatus = 'DECLINED';
        $this->refund($payment, '1.250')->assertStatus(502);
        $this->assertSame('0.000', $this->refunded($payment));
    }

    public function test_events_about_payments_that_are_not_the_kits_change_nothing(): void
    {
        $gateway = $this->gateway();
        $payment = $this->paid($gateway);
        $charge = ['id' => 'chg_not_ours', 'object' => 'charge', 'status' => 'CAPTURED', 'amount' => 9.0, 'currency' => 'KWD', 'reference' => ['gateway' => '1', 'payment' => '2', 'transaction' => $payment->id], 'transaction' => ['created' => '5']];
        $this->deliverCharge($gateway, $charge, '9.000')->assertOk();
        $this->deliverRefund($gateway, ['id' => 're_x', 'object' => 'refund', 'amount' => 1.0, 'currency' => 'KWD', 'charge_id' => 'chg_not_ours', 'status' => 'REFUNDED', 'reference' => ['gateway' => '1', 'payment' => '2'], 'created' => 5], '1.000')->assertOk();
        $this->assertSame(['5.250', '0.000'], [(string) BigDecimal::of($this->balance())->toScale(3), $this->refunded($payment)]);

        $open = $this->started($gateway);
        $this->deliverCharge($gateway, ['object' => 'authorize'] + $this->pays($open), '5.250')->assertOk();
        $this->assertSame('open', $open->fresh()->status, 'an authorization is not a charge');
    }

    public function test_a_test_payment_runs_through_it_and_credits_no_one(): void
    {
        $gateway = $this->gateway('test');
        $id = $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$gateway->id}/test", ['amount' => '1'])->assertCreated()->json('payment.id');
        $payment = Payment::query()->findOrFail($id);
        $this->deliverCharge($gateway, $this->pays($payment), '1.000', self::TEST_KEY)->assertOk();
        $this->assertSame(['paid', []], [$payment->fresh()->status, $this->entries()]);
    }

    public function test_the_address_and_currencies_are_settings(): void
    {
        config(['kit.payments.tap.api_url' => 'https://tap.standin.example/', 'kit.payments.tap.currencies' => ['MAD']]);
        Http::swap(new Factory);
        Http::fake(['tap.standin.example/*' => Http::response(['charges' => []])]);
        $gateway = new PaymentGateway;
        $gateway->forceFill(['mode' => 'live', 'config' => ['secretKey' => self::LIVE_KEY]]);
        (new TapDriver)->verify($gateway);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://tap.standin.example/v2/charges/list'));
        $this->assertSame(['MAD'], (new TapDriver)->currencies());
    }
}
