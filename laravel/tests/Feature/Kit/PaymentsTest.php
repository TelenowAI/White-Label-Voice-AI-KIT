<?php

namespace Tests\Feature\Kit;

use App\Kit\Email\Senders;
use App\Kit\Payments\Money;
use App\Kit\Payments\Payments;
use App\Kit\Payments\PaymentSettings;
use App\Kit\Settings\Settings;
use App\Kit\Signup\SignupSettings;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentGateway;
use App\Models\PriceList;
use App\Models\User;
use App\Models\WalletEntry;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordedTelenow;
use Tests\Support\SmtpSink;
use Tests\Support\StandInGateway;
use Tests\TestCase;

/**
 * Paying the operator (App\Kit\Payments): the operator adds ways to pay (a bank transfer built in; an online gateway's
 * keys tried first, never enabled in test mode) and sets the top-up rules; a customer is offered the enabled ones for
 * its currency and plan and tops up within the rules; the wallet is credited only by what the gateway says — a signed
 * webhook, the gateway asked when the payer comes back (never the return's own query), or asked again by
 * kit:payments — or by staff for a bank transfer, and once only; another amount waits for review; refunds and
 * disputes come off the wallet once each by the gateway's id, as the operator's dispute rule says; staff test a
 * gateway end to end with no one credited; the payer is emailed. The online gateway is StandInGateway over Http::fake
 * (its e2e twin is standin_gateway.php); Telenow (each credit sets the spend limit) answers from recordings.
 */
class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://voice.acme.example';

    private const TEST_KEY = ['sk_test_acme', 'test secret'];

    private const LIVE_KEY = ['sk_live_acme', 'live secret'];

    private const WEBHOOK_SECRET = 'whsec_acme';

    private User $staff;

    private User $ana;

    private Workspace $acme;

    /** The stand-in gateway's orders, by id. */
    private array $orders = [];

    /** @var list<array{method: string, path: string, body: array, headers: array}> what the kit sent the stand-in */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        touch(config('kit.installed_marker'));
        config(['kit.payments.drivers.standin' => StandInGateway::class]);
        $settings = app(Settings::class);
        $settings->put('telenow.api_key', 'vai_test_operator_admin', secret: true);
        $settings->put('telenow.billing', ['mode' => 'prepaid', 'state' => 'ok', 'canSpend' => true, 'stoppedBy' => null]);
        app(Senders::class)->putSiteUrl(self::SITE);
        $this->pricing();
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        $this->ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->acme = Workspace::create(['name' => 'Acme Dental', 'status' => 'active', 'sync_state' => 'synced']);
        $this->acme->forceFill(['telenow_sub_account_id' => '3e1cd17a-d25e-40c4-b0b0-0694f052f6da', 'telenow_key' => 'vai_test_customer_acme'])->save();
        WorkspaceMember::create(['workspace_id' => $this->acme->id, 'user_id' => $this->ana->id, 'role' => 'member']);
        RecordedTelenow::fake();
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $recorded = RecordedTelenow::responder();
        Http::fake(fn (Request $r) => $this->standIn($r) ?? $recorded($r));
    }

    private function pricing(): void
    {
        $settings = app(Settings::class);
        PriceList::create(['id' => 'standard', 'name' => 'Standard', 'definition' => ['id' => 'standard', 'currency' => 'INR', 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']]]]]);
        PriceList::create(['id' => 'gold', 'name' => 'Gold', 'definition' => ['id' => 'gold', 'currency' => 'INR', 'decimals' => 2,
            'rounding' => 'half-up', 'billingIncrementSeconds' => 60, 'rules' => [['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '20']]]]]);
        $settings->put('pricing.currency', 'INR');
        $settings->put('pricing.usd_rate', '83.00');
        $settings->put('pricing.default_plan', 'standard');
    }

    /** The stand-in gateway's API, as standin_gateway.php answers it. */
    private function standIn(Request $r): mixed
    {
        if (! str_starts_with($r->url(), StandInGateway::baseUrl())) {
            return null;
        }
        $path = parse_url($r->url(), PHP_URL_PATH);
        $this->sent[] = ['method' => $r->method(), 'path' => $path, 'body' => $r->data(), 'headers' => $r->headers()];
        [$id, $secret] = array_pad(explode(':', (string) base64_decode(substr($r->header('Authorization')[0] ?? '', 6)), 2), 2, null);
        $keys = [self::TEST_KEY[0] => self::TEST_KEY[1], self::LIVE_KEY[0] => self::LIVE_KEY[1]];
        if (($keys[$id] ?? null) !== $secret) {
            return Http::response(['error' => 'Authentication failed'], 401);
        }
        if ($path === '/v1/account') {
            return Http::response(['id' => 'acct_standin', 'mode' => str_starts_with($id, 'sk_live_') ? 'live' : 'test']);
        }
        if ($path === '/v1/orders' && $r->method() === 'POST') {
            $order = ['id' => 'ord_'.bin2hex(random_bytes(4)), 'status' => 'created'] + $r->data();
            $this->orders[$order['id']] = $order;

            return Http::response(['id' => $order['id'], 'status' => 'created', 'checkout_url' => StandInGateway::baseUrl()."/pay/{$order['id']}"], 201);
        }
        if (preg_match('#^/v1/orders/(ord_\w+)$#', $path, $m) && isset($this->orders[$m[1]])) {
            return Http::response(array_intersect_key($this->orders[$m[1]], array_flip(['id', 'reference', 'amount', 'currency', 'status'])));
        }
        if (preg_match('#^/v1/orders/(ord_\w+)/refunds$#', $path, $m) && isset($this->orders[$m[1]])) {
            $key = $r->header('Idempotency-Key')[0];
            $this->orders[$m[1]]['refunds'][$key] ??= ['id' => 'rfnd_'.substr(md5($key), 0, 8), 'amount' => $r->data()['amount'], 'status' => 'processed'];

            return Http::response($this->orders[$m[1]]['refunds'][$key], 201);
        }

        return Http::response(['error' => 'Not found'], 404);
    }

    private function addManual(bool $enabled = true): PaymentGateway
    {
        $this->actingAs($this->staff)->postJson('/api/kit/admin/payments/gateways', [
            'driver' => 'manual', 'name' => 'Bank transfer (NEFT)', 'enabled' => $enabled,
            'values' => ['instructions' => "Pay to Acme Voice Pvt Ltd\nIFSC HDFC0000001, account 50100012345678"],
        ])->assertCreated();

        return PaymentGateway::query()->where('driver', 'manual')->latest('position')->firstOrFail();
    }

    private function addStandIn(string $mode = 'live', bool $enabled = true): PaymentGateway
    {
        [$id, $secret] = $mode === 'live' ? self::LIVE_KEY : self::TEST_KEY;
        $this->actingAs($this->staff)->postJson('/api/kit/admin/payments/gateways', [
            'driver' => 'standin', 'name' => 'Cards and UPI', 'mode' => $mode, 'enabled' => $enabled, 'methods' => ['card', 'upi'],
            'values' => ['keyId' => $id, 'keySecret' => $secret, 'webhookSecret' => self::WEBHOOK_SECRET],
        ])->assertCreated();

        return PaymentGateway::query()->where('driver', 'standin')->latest('position')->firstOrFail();
    }

    private function topUp(PaymentGateway $gateway, string $amount): TestResponse
    {
        return $this->actingAs($this->ana)->postJson('/api/kit/console/payments', ['gatewayId' => $gateway->id, 'amount' => $amount]);
    }

    private function orderOf(Payment $payment): array
    {
        return $this->orders[$payment->gateway_ref];
    }

    /** Deliver a webhook the stand-in signed (or `$signature`). */
    private function webhook(PaymentGateway $gateway, string $type, array $order, array $extra = [], ?string $signature = null, ?string $id = null): TestResponse
    {
        $body = json_encode(['id' => $id ?? 'evt_'.Str::random(8), 'type' => $type,
            'data' => ['order' => array_intersect_key($order, array_flip(['id', 'reference', 'amount', 'currency', 'status']))] + $extra]);

        return $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_STANDIN_SIGNATURE' => $signature ?? StandInGateway::sign($body, self::WEBHOOK_SECRET)], $body);
    }

    /** @return list<array{kind: string, amount: string, reference: string}> */
    private function entries(): array
    {
        return WalletEntry::query()->where('workspace_id', $this->acme->id)->orderBy('id')->get()
            ->map(fn (WalletEntry $e) => ['kind' => $e->kind, 'amount' => $e->amount, 'reference' => $e->reference])->all();
    }

    private function balance(): string
    {
        return $this->acme->fresh()->balance;
    }

    public function test_a_bank_transfer_is_offered_started_with_its_reference_and_credited_once_when_staff_confirm(): void
    {
        $this->actingAs($this->staff)->postJson('/api/kit/admin/payments/gateways', ['driver' => 'manual', 'values' => []])
            ->assertStatus(422)->assertJsonValidationErrors(['values.instructions']);
        $gateway = $this->addManual(enabled: false);
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertOk()->assertJsonPath('gateways', []);
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['enabled' => true])->assertOk()
            ->assertJsonPath('gateways.0.enabled', true)->assertJsonPath('gateways.0.webhookUrl', null);

        $offer = $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertOk();
        $this->assertSame([['id' => $gateway->id, 'name' => 'Bank transfer (NEFT)', 'online' => false, 'methods' => [], 'asks' => []]], $offer->json('gateways'));
        $this->assertSame(['INR', 2], [$offer->json('currency'), $offer->json('decimals')]);

        $started = $this->topUp($gateway, '500')->assertCreated();
        $this->assertSame('instructions', $started->json('checkout.kind'));
        $this->assertStringContainsString('IFSC HDFC0000001', $started->json('checkout.instructions'));
        $reference = $started->json('checkout.reference');
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $reference);
        $this->assertSame(['500.00', 'INR', 'open', $reference], [$started->json('payment.amount'), $started->json('payment.currency'),
            $started->json('payment.status'), $started->json('payment.reference')]);
        $id = $started->json('payment.id');
        $this->actingAs($this->ana)->getJson("/api/kit/console/payments/{$id}")->assertOk()
            ->assertJsonPath('reference', $reference)->assertJsonPath('instructions', $started->json('checkout.instructions'));
        $this->assertSame([], $this->entries(), 'nothing credited until staff confirm');

        $admin = $this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk();
        $this->assertSame([$id], array_column($admin->json('waiting'), 'id'));
        $this->assertSame([true, true, false], [$admin->json('waiting.0.canConfirm'), $admin->json('waiting.0.canCancel'), $admin->json('waiting.0.canRefund')]);
        $this->assertSame(['id' => $this->acme->id, 'name' => 'Acme Dental'], $admin->json('waiting.0.customer'));

        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => '499.505'])
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => '499.50'])->assertOk()
            ->assertJsonPath('waiting', []);
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => '499.50'])->assertStatus(409);
        $this->assertSame([['kind' => 'credit', 'amount' => '499.50', 'reference' => "payment:{$id}"]], $this->entries());
        $this->assertSame('499.50', $this->balance());
        $paid = Payment::query()->findOrFail($id);
        $this->assertSame([Payment::PAID, '499.50', $this->staff->id], [$paid->status, $paid->received, $paid->decided_by]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/wallet')->assertOk()->assertJsonPath('entries.0.paymentId', $id)
            ->assertJsonPath('entries.0.kind', 'credit');
        $this->actingAs($this->ana)->getJson("/api/kit/console/payments/{$id}")->assertJsonPath('status', 'paid')->assertJsonPath('instructions', null);
    }

    public function test_top_ups_follow_the_operators_rules(): void
    {
        $gateway = $this->addManual();
        $this->actingAs($this->staff)->putJson('/api/kit/admin/payments/settings', ['minAmount' => '100', 'maxAmount' => '50000', 'disputeAction' => 'hold'])->assertOk();

        $this->topUp($gateway, '99.99')->assertStatus(422)->assertJsonPath('errors.amount.0', 'The smallest top-up is 100.00 INR.');
        $this->topUp($gateway, '50000.01')->assertStatus(422)->assertJsonPath('errors.amount.0', 'The largest top-up is 50000.00 INR.');
        $this->topUp($gateway, '100.001')->assertStatus(422)->assertJsonPath('errors.amount.0', 'An amount above zero, with at most 2 decimal places.');
        $this->topUp($gateway, '1e3')->assertStatus(422)->assertJsonValidationErrors(['amount']);
        $this->actingAs($this->ana)->postJson('/api/kit/console/payments', ['gatewayId' => (string) Str::uuid(), 'amount' => '100'])
            ->assertStatus(422)->assertJsonValidationErrors(['gatewayId']);
        $this->topUp($gateway, '100')->assertCreated();
        $this->topUp($gateway, '50000')->assertCreated();

        // Offered by plan.
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['plans' => ['gold']])->assertOk();
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('gateways', []);
        $this->topUp($gateway, '100')->assertStatus(409)->assertJsonPath('message', 'There is no way to pay yet. Contact support to add credit.');
        $this->acme->forceFill(['price_list_id' => 'gold'])->save();
        $this->topUp($gateway, '100')->assertCreated();

        // Out of credit is when a top-up is needed; a suspended account cannot start one.
        $this->assertSame('0', $this->balance());
        $this->acme->forceFill(['status' => Workspace::SUSPENDED])->save();
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('paused', __('console.paused.account_suspended'));
        $this->topUp($gateway, '100')->assertStatus(409)->assertJsonPath('message', __('console.paused.account_suspended'));

        // No wallet, no top-ups.
        app(Settings::class)->put('pricing.default_plan', null);
        $this->acme->forceFill(['status' => Workspace::ACTIVE, 'price_list_id' => null])->save();
        $this->topUp($gateway, '100')->assertStatus(409)->assertJsonPath('message', __('wallet.not_in_force'));
    }

    public function test_the_top_up_rules_are_checked(): void
    {
        $put = fn (array $body) => $this->actingAs($this->staff)->putJson('/api/kit/admin/payments/settings', $body + ['minAmount' => null, 'maxAmount' => null, 'disputeAction' => 'hold']);
        $put(['minAmount' => '10.001'])->assertStatus(422)->assertJsonValidationErrors(['minAmount']);
        $put(['minAmount' => '0'])->assertStatus(422)->assertJsonValidationErrors(['minAmount']);
        $put(['minAmount' => '500', 'maxAmount' => '100'])->assertStatus(422)->assertJsonPath('errors.maxAmount.0', 'The largest top-up cannot be smaller than the smallest.');
        $put(['disputeAction' => 'shrug'])->assertStatus(422)->assertJsonValidationErrors(['disputeAction']);
        $put(['minAmount' => '100', 'maxAmount' => '', 'disputeAction' => 'suspend'])->assertOk()
            ->assertJsonPath('settings', ['minAmount' => '100', 'maxAmount' => null, 'disputeAction' => 'suspend']);
    }

    public function test_staff_cancel_a_bank_transfer_and_can_still_confirm_one_given_up_on_when_the_money_comes(): void
    {
        $gateway = $this->addManual();
        $first = $this->topUp($gateway, '300')->json('payment.id');
        $second = $this->topUp($gateway, '200')->json('payment.id');

        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$first}/cancel")->assertOk();
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$first}/cancel")->assertStatus(409);
        $this->assertSame(Payment::CANCELED, Payment::query()->findOrFail($first)->status);

        $this->travel(config('kit.payments.manual.expire_days'))->days();
        $this->travel(1)->minutes();
        $this->artisan('kit:payments')->expectsOutputToContain('0 payment(s) checked, 1 given up')->assertSuccessful();
        $this->assertSame(Payment::EXPIRED, Payment::query()->findOrFail($second)->status);

        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$first}/confirm", ['amount' => '300'])->assertOk();
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$second}/confirm", ['amount' => '200'])->assertOk();
        $this->assertSame(['300.00', '200.00'], array_column($this->entries(), 'amount'));
        $this->assertSame('500.00', $this->balance());
    }

    public function test_a_gateways_number_is_written_with_the_currencys_places_and_another_amount_stays_another(): void
    {
        config(['kit.payments.currency_decimals' => ['XOF' => 0, 'NGN' => 2, 'KWD' => 3]]);
        $this->assertSame('500.00', Money::written('500', 'NGN'));
        $this->assertSame('500.50', Money::written('500.5', 'NGN'));
        $this->assertSame('5.250', Money::written('5.25', 'KWD'));
        $this->assertSame('1000', Money::written('1000.00', 'XOF'));
        $this->assertSame('500.004', Money::written('500.004', 'NGN'), 'not rounded down to what was asked');
        $this->assertSame('1000.50', Money::written('1000.50', 'XOF'));
    }

    public function test_a_second_refund_that_waited_for_the_first_reads_the_payment_as_it_now_is(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($payment))->assertOk();
        $reference = (string) Str::uuid();
        $stale = Payment::query()->findOrFail($payment->id);

        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/refunds", ['amount' => '100', 'reference' => $reference])->assertOk();
        $this->sent = [];
        app(Payments::class)->refund($stale, $this->staff, ['amount' => '100', 'reference' => $reference]);
        $this->assertSame([], $this->sent, 'it was read before the first was sent, and sends nothing');
        $this->assertSame('100.00', $payment->fresh()->refunded);
    }

    public function test_a_payment_sends_one_refund_at_a_time(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($payment))->assertOk();
        $url = "/api/kit/admin/payments/{$payment->id}/refunds";
        $key = "kit.payments.refund.{$payment->id}";
        $refund = fn () => $this->actingAs($this->staff)->postJson($url, ['amount' => '100', 'reference' => (string) Str::uuid()]);

        $held = Cache::lock($key, 60);
        $this->assertTrue($held->get());
        $this->sent = [];
        $refund()->assertStatus(409)->assertJsonPath('message', __('payments.refund_in_progress'));
        $this->assertSame([], $this->sent, 'nothing reaches the gateway while another is being sent');
        $held->release();

        $refund()->assertOk();
        $this->assertSame('100.00', $payment->fresh()->refunded);
        $this->assertTrue(($free = Cache::lock($key, 60))->get(), 'let go after a refund');
        $free->release();

        $gateway->forceFill(['config' => ['keyId' => 'sk_live_revoked', 'keySecret' => 'x', 'webhookSecret' => self::WEBHOOK_SECRET]])->save();
        $refund()->assertStatus(502);
        $this->assertTrue(($free = Cache::lock($key, 60))->get(), 'let go after a refusal');
        $free->release();
    }

    public function test_a_refund_comes_off_once_and_never_more_than_was_received(): void
    {
        $gateway = $this->addManual();
        $id = $this->topUp($gateway, '500')->json('payment.id');
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/refunds", ['amount' => '100', 'reference' => (string) Str::uuid()])->assertStatus(409);
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => '499.50'])->assertOk();

        $reference = (string) Str::uuid();
        $refund = fn (string $amount, ?string $ref = null) => $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/refunds",
            ['amount' => $amount, 'reference' => $ref ?? (string) Str::uuid()]);
        $refund('200', $reference)->assertOk();
        $refund('200', $reference)->assertOk(); // sent twice
        $refund('300')->assertStatus(422)->assertJsonPath('message', 'At most 299.50 INR of this payment is left to refund.');
        $rest = (string) Str::uuid();
        $refund('299.50', $rest)->assertOk();
        $refund('299.50', $rest)->assertOk(); // the whole rest, sent twice: answered, not refused as too much

        $this->assertSame([['credit', '499.50'], ['refund', '-200.00'], ['refund', '-299.50']],
            array_map(fn (array $e) => [$e['kind'], $e['amount']], $this->entries()));
        $this->assertSame('0.00', $this->balance());
        $payment = Payment::query()->findOrFail($id);
        $this->assertSame(['499.50', Payment::PAID], [$payment->refunded, $payment->status]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('payments.0.refunded', '499.50');
    }

    public function test_an_online_gateway_is_saved_only_with_keys_it_accepts_and_never_enabled_in_test_mode(): void
    {
        $add = fn (array $over) => $this->actingAs($this->staff)->postJson('/api/kit/admin/payments/gateways', array_replace_recursive([
            'driver' => 'standin', 'name' => 'Cards and UPI', 'mode' => 'test', 'methods' => ['card'],
            'values' => ['keyId' => self::TEST_KEY[0], 'keySecret' => self::TEST_KEY[1], 'webhookSecret' => self::WEBHOOK_SECRET],
        ], $over));
        $add(['values' => ['keySecret' => 'wrong']])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: Authentication failed');
        $add(['mode' => 'live'])->assertStatus(422)->assertJsonPath('message', 'The gateway did not accept these details: These are test keys; the gateway is in live mode.');
        $add(['enabled' => true])->assertStatus(422)->assertJsonPath('message', 'Switch to live before enabling it: a payment in test mode moves no money.');
        $add(['methods' => ['crypto']])->assertStatus(422)->assertJsonValidationErrors(['methods.0']);
        $this->assertSame(0, PaymentGateway::query()->count());

        $shown = $add([])->assertCreated()->json('gateways.0');
        $gateway = PaymentGateway::query()->sole();
        $this->assertSame(['standin', 'test', false, ['card']], [$shown['driver'], $shown['mode'], $shown['enabled'], $shown['methods']]);
        $this->assertSame(self::SITE."/api/kit/payments/webhooks/{$gateway->id}", $shown['webhookUrl']);
        $this->assertSame(['payment.paid', 'payment.failed', 'refund.processed', 'dispute.opened', 'dispute.closed'], $shown['webhookEvents']);
        $this->assertNull($shown['lastEventAt']);
        $secret = collect($shown['fields'])->firstWhere('name', 'keySecret');
        $this->assertSame([null, true], [$secret['value'], $secret['secretSet']]);
        $this->assertStringNotContainsString(self::TEST_KEY[1], (string) DB::table('payment_gateways')->value('config'), 'keys are stored encrypted');
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('gateways', []);

        // Live keys, then enabled; a secret left empty is kept (the keys are tried with it).
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['mode' => 'live', 'values' => [
            'keyId' => self::LIVE_KEY[0], 'keySecret' => self::LIVE_KEY[1], 'webhookSecret' => '']])->assertOk();
        $this->assertSame(self::WEBHOOK_SECRET, $gateway->fresh()->config['webhookSecret']);
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['enabled' => true])->assertOk();
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')
            ->assertJsonPath('gateways.0', ['id' => $gateway->id, 'name' => 'Cards and UPI', 'online' => true, 'methods' => ['Cards'], 'asks' => []]);
        $this->actingAs($this->staff)->putJson("/api/kit/admin/payments/gateways/{$gateway->id}", ['mode' => 'test', 'values' => [
            'keyId' => self::TEST_KEY[0], 'keySecret' => self::TEST_KEY[1]]])->assertStatus(422);
        $this->assertSame('live', $gateway->fresh()->mode);

        // Should one be enabled in test mode anyway (by hand, in the database), no customer is offered it.
        PaymentGateway::query()->whereKey($gateway->id)->update(['mode' => 'test']);
        $this->assertTrue($gateway->fresh()->enabled);
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('gateways', []);
    }

    public function test_an_online_top_up_goes_to_the_gateway_and_is_credited_once_by_its_signed_webhook(): void
    {
        $gateway = $this->addStandIn();
        $started = $this->topUp($gateway, '500')->assertCreated();
        $payment = Payment::query()->findOrFail($started->json('payment.id'));
        $order = $this->orderOf($payment);
        $this->assertSame(['redirect', StandInGateway::baseUrl()."/pay/{$order['id']}"], [$started->json('checkout.kind'), $started->json('checkout.url')]);
        $this->assertNull($started->json('payment.reference'), "the gateway's own id is never shown to a customer");
        $this->assertSame([50000, 'INR', $payment->id, self::SITE."/api/kit/payments/{$payment->id}/return", self::SITE."/api/kit/payments/webhooks/{$gateway->id}", ['card', 'upi']],
            [$order['amount'], $order['currency'], $order['reference'], $order['return_url'], $order['notify_url'], $order['methods']]);

        $order['status'] = 'paid';
        $this->webhook($gateway, 'payment.paid', $order, id: 'evt_1')->assertOk();
        $this->webhook($gateway, 'payment.paid', $order, id: 'evt_1')->assertOk(); // delivered again
        $this->webhook($gateway, 'payment.paid', $order, id: 'evt_2')->assertOk(); // said again
        $this->assertSame([['kind' => 'credit', 'amount' => '500.00', 'reference' => "payment:{$payment->id}"]], $this->entries());
        $this->assertSame([Payment::PAID, '500.00'], [$payment->fresh()->status, $payment->fresh()->received]);
        $this->assertSame(2, PaymentEvent::query()->count());
        $this->assertStringNotContainsString($payment->id, (string) DB::table('payment_events')->value('payload'), 'event bodies are stored encrypted');
        $this->assertNotNull($gateway->fresh()->last_event_at);
    }

    public function test_a_webhook_that_does_not_check_out_changes_nothing(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $order = ['status' => 'paid'] + $this->orderOf($payment);
        $body = json_encode(['id' => 'evt_x', 'type' => 'payment.paid', 'data' => ['order' => $order]]);

        $this->webhook($gateway, 'payment.paid', $order, signature: StandInGateway::sign($body, 'not the secret'))->assertStatus(401)
            ->assertJsonPath('message', 'This event could not be verified.');
        $this->webhook($gateway, 'payment.paid', $order, signature: 't='.time().',v1=nothex')->assertStatus(401);
        $stale = time() - StandInGateway::TOLERANCE_SECONDS - 1;
        $this->call('POST', "/api/kit/payments/webhooks/{$gateway->id}", [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_STANDIN_SIGNATURE' => StandInGateway::sign($body, self::WEBHOOK_SECRET, $stale)], $body)->assertStatus(401);
        $this->call('POST', '/api/kit/payments/webhooks/'.Str::uuid(), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertNotFound();
        $this->assertSame([], $this->entries());
        $this->assertSame([Payment::OPEN, 0, null], [$payment->fresh()->status, PaymentEvent::query()->count(), $gateway->fresh()->last_event_at]);
    }

    public function test_the_payers_return_asks_the_gateway_and_never_trusts_what_the_return_says(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));

        $this->get("http://evil.example/api/kit/payments/{$payment->id}/return?status=paid&amount=50000")
            ->assertStatus(303)->assertRedirect(self::SITE."/console/wallet?payment={$payment->id}");
        $this->assertSame(Payment::OPEN, $payment->fresh()->status, 'the gateway still says created');
        $this->assertSame([], $this->entries());
        $this->assertSame(['GET', "/v1/orders/{$payment->gateway_ref}"], [end($this->sent)['method'], end($this->sent)['path']]);

        $this->orders[$payment->gateway_ref]['status'] = 'paid';
        $this->post("/api/kit/payments/{$payment->id}/return", ['anything' => 'at all'])->assertStatus(303);
        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertSame('500.00', $this->balance());
        $this->get('/api/kit/payments/'.Str::uuid().'/return')->assertNotFound();
    }

    public function test_a_payment_for_another_amount_waits_for_review_and_staff_confirm_what_arrived(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid', 'amount' => 40000] + $this->orderOf($payment))->assertOk();
        $this->assertSame([Payment::REVIEW, '400.00'], [$payment->fresh()->status, $payment->fresh()->received]);
        $this->assertSame([], $this->entries());
        $this->actingAs($this->ana)->getJson("/api/kit/console/payments/{$payment->id}")->assertJsonPath('status', 'review');
        $waiting = $this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->json('waiting.0');
        $this->assertSame([$payment->id, '400.00', true, false], [$waiting['id'], $waiting['received'], $waiting['canConfirm'], $waiting['canCancel']]);

        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/confirm", ['amount' => '400'])->assertOk();
        $this->assertSame([['kind' => 'credit', 'amount' => '400.00', 'reference' => "payment:{$payment->id}"]], $this->entries());
    }

    public function test_a_failed_payment_that_is_paid_after_all_is_still_credited(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'payment.failed', ['status' => 'failed'] + $this->orderOf($payment))->assertOk();
        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($payment))->assertOk();
        $this->webhook($gateway, 'payment.failed', ['status' => 'failed'] + $this->orderOf($payment))->assertOk();
        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertSame('500.00', $this->balance());
    }

    public function test_a_refund_through_the_gateway_comes_off_once_even_when_its_webhook_follows(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->orders[$payment->gateway_ref]['status'] = 'paid';
        $this->webhook($gateway, 'payment.paid', $this->orderOf($payment))->assertOk();

        $reference = (string) Str::uuid();
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$payment->id}/refunds", ['amount' => '100', 'reference' => $reference])->assertOk();
        $call = collect($this->sent)->firstWhere('path', "/v1/orders/{$payment->gateway_ref}/refunds");
        $this->assertSame([['amount' => 10000], [$reference]], [$call['body'], $call['headers']['Idempotency-Key']]);
        $refund = $this->orders[$payment->gateway_ref]['refunds'][$reference];
        $this->webhook($gateway, 'refund.processed', $this->orderOf($payment), ['refund' => $refund])->assertOk();
        // One made at the gateway's own dashboard arrives only as an event.
        $this->webhook($gateway, 'refund.processed', $this->orderOf($payment), ['refund' => ['id' => 'rfnd_dash', 'amount' => 5000]])->assertOk();
        $this->webhook($gateway, 'refund.processed', $this->orderOf($payment), ['refund' => ['id' => 'rfnd_dash', 'amount' => 5000]])->assertOk();

        $this->assertSame([['credit', '500.00'], ['refund', '-100.00'], ['refund', '-50.00']], array_map(fn (array $e) => [$e['kind'], $e['amount']], $this->entries()));
        $this->assertSame(['150.00', '350.00'], [$payment->fresh()->refunded, $this->balance()]);
    }

    public function test_a_refund_counted_before_the_payment_is_known_to_be_paid_comes_off_once_it_is(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'refund.processed', $this->orderOf($payment), ['refund' => ['id' => 'rfnd_early', 'amount' => 20000]])->assertOk();
        $this->assertSame([], $this->entries());
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($payment))->assertOk();
        $this->assertSame([['credit', '500.00'], ['refund', '-200.00']], array_map(fn (array $e) => [$e['kind'], $e['amount']], $this->entries()));
    }

    public function test_disputes_follow_the_operators_rule(): void
    {
        $gateway = $this->addStandIn();
        $pay = function (string $amount) use ($gateway): Payment {
            $payment = Payment::query()->findOrFail($this->topUp($gateway, $amount)->json('payment.id'));
            $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($payment))->assertOk();

            return $payment->fresh();
        };
        $dispute = fn (Payment $p, string $type, string $id, ?string $status = null) => $this->webhook($gateway, $type, $this->orderOf($p),
            ['dispute' => ['id' => $id, 'amount' => $this->orderOf($p)['amount'], 'status' => $status ?? 'open']])->assertOk();
        $kinds = fn () => array_map(fn (array $e) => [$e['kind'], $e['amount']], array_slice($this->entries(), -1));

        // hold (the default): off at once, back when won.
        $a = $pay('100');
        $dispute($a, 'dispute.opened', 'dp_a');
        $dispute($a, 'dispute.opened', 'dp_a');
        $this->assertSame([['hold', '-100.00']], $kinds());
        $dispute($a, 'dispute.closed', 'dp_a', 'won');
        $dispute($a, 'dispute.closed', 'dp_a', 'won');
        $this->assertSame([['release', '100.00']], $kinds());
        $this->assertSame('won', $a->fresh()->dispute);
        // A delivery that gave the amount back but stopped before the payment row was saved comes again: once still.
        $details = $a->fresh()->details;
        $details['disputes']['dp_a'] = ['state' => 'open', 'held' => true] + $details['disputes']['dp_a'];
        Payment::query()->whereKey($a->id)->update(['details' => json_encode($details)]);
        $dispute($a, 'dispute.closed', 'dp_a', 'won');
        $this->assertSame(1, WalletEntry::query()->where('kind', WalletEntry::RELEASE)->count());

        // nothing: off only when lost.
        app(PaymentSettings::class)->save(['minAmount' => null, 'maxAmount' => null, 'disputeAction' => 'nothing']);
        $b = $pay('200');
        $dispute($b, 'dispute.opened', 'dp_b');
        $this->assertSame([['credit', '200.00']], $kinds());
        $dispute($b, 'dispute.closed', 'dp_b', 'lost');
        $this->assertSame([['hold', '-200.00']], $kinds());
        $this->assertSame('lost', $b->fresh()->dispute);

        // suspend: held, and the account suspended; lost keeps the hold, once.
        app(PaymentSettings::class)->save(['minAmount' => null, 'maxAmount' => null, 'disputeAction' => 'suspend']);
        $c = $pay('300');
        $dispute($c, 'dispute.opened', 'dp_c');
        $this->assertSame(Workspace::SUSPENDED, $this->acme->fresh()->status);
        $dispute($c, 'dispute.closed', 'dp_c', 'lost');
        $this->assertSame([['hold', '-300.00']], $kinds());
        $this->assertSame('100.00', $this->balance(), '100 + 200 + 300 paid; 200 and 300 lost');
    }

    public function test_kit_payments_asks_about_open_online_payments_and_gives_up_expired_ones(): void
    {
        $gateway = $this->addStandIn();
        $paidLater = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $abandoned = Payment::query()->findOrFail($this->topUp($gateway, '250')->json('payment.id'));
        $this->orders[$paidLater->gateway_ref]['status'] = 'paid';

        $this->artisan('kit:payments')->expectsOutputToContain('0 payment(s) checked, 0 given up')->assertSuccessful(); // too young to ask
        $this->travel((new StandInGateway)->checkAfterMinutes() + 1)->minutes();
        $this->artisan('kit:payments')->expectsOutputToContain('2 payment(s) checked, 0 given up')->assertSuccessful();
        $this->assertSame([Payment::PAID, Payment::OPEN], [$paidLater->fresh()->status, $abandoned->fresh()->status]);
        $this->artisan('kit:payments')->expectsOutputToContain('0 payment(s) checked')->assertSuccessful(); // asked a moment ago

        $this->travel(config('kit.payments.expire_hours'))->hours();
        $this->artisan('kit:payments')->expectsOutputToContain('1 payment(s) checked, 1 given up')->assertSuccessful();
        $this->assertSame(Payment::EXPIRED, $abandoned->fresh()->status);
        $this->assertSame('500.00', $this->balance());
    }

    public function test_a_staff_test_payment_runs_end_to_end_and_credits_no_one(): void
    {
        $live = $this->addStandIn('live');
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$live->id}/test", ['amount' => '1'])->assertStatus(409);
        $manual = $this->addManual();
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$manual->id}/test", ['amount' => '1'])->assertStatus(409);
        $gateway = $this->addStandIn('test', enabled: false);

        $started = $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/gateways/{$gateway->id}/test", ['amount' => '1'])->assertCreated();
        $this->assertSame(['redirect', null, '1.00'], [$started->json('checkout.kind'), $started->json('payment.customer'), $started->json('payment.amount')]);
        $payment = Payment::query()->findOrFail($started->json('payment.id'));
        $this->orders[$payment->gateway_ref]['status'] = 'paid';
        $this->webhook($gateway, 'payment.paid', $this->orderOf($payment))->assertOk();
        $this->get("/api/kit/payments/{$payment->id}/return")->assertRedirect(self::SITE."/admin/payments?payment={$payment->id}");

        $this->assertSame(Payment::PAID, $payment->fresh()->status);
        $this->assertSame(0, WalletEntry::query()->count());
        $this->assertNotNull($gateway->fresh()->last_event_at);
    }

    public function test_a_removed_gateway_still_takes_its_late_refund(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($payment))->assertOk();
        $this->actingAs($this->staff)->deleteJson("/api/kit/admin/payments/gateways/{$gateway->id}")->assertNoContent();
        $this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertJsonPath('gateways', []);
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('gateways', []);

        $this->webhook($gateway, 'refund.processed', $this->orderOf($payment), ['refund' => ['id' => 'rfnd_late', 'amount' => 50000]])->assertOk();
        $this->assertSame('0.00', $this->balance());
    }

    public function test_payments_through_a_driver_taken_out_of_the_install_are_still_listed_and_nothing_more_is_done(): void
    {
        $gateway = $this->addStandIn();
        $paid = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($paid))->assertOk();
        $open = Payment::query()->findOrFail($this->topUp($gateway, '250')->json('payment.id'));
        config(['kit.payments.drivers' => array_diff_key(config('kit.payments.drivers'), ['standin' => true])]);

        $recent = collect($this->actingAs($this->staff)->getJson('/api/kit/admin/payments')->assertOk()->json('recent'))->keyBy('id');
        $this->assertSame([false, false, false], [$recent[$paid->id]['canRefund'], $recent[$paid->id]['canConfirm'], $recent[$paid->id]['partialRefunds']]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertOk()->assertJsonCount(2, 'payments');
        $this->actingAs($this->ana)->getJson("/api/kit/console/payments/{$paid->id}")->assertOk()->assertJsonPath('reference', null);
        $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$paid->id}/refunds", ['amount' => '1', 'reference' => (string) Str::uuid()])
            ->assertStatus(409);
        $this->get("/api/kit/payments/{$open->id}/return")->assertStatus(303);
        $this->assertSame(Payment::OPEN, $open->fresh()->status);

        $this->travel(config('kit.payments.expire_hours') + 1)->hours();
        $this->artisan('kit:payments')->expectsOutputToContain('0 payment(s) checked, 1 given up')->assertSuccessful();
        $this->assertSame([Payment::EXPIRED, '500.00'], [$open->fresh()->status, $this->balance()]);
    }

    public function test_gateways_are_ordered_as_the_operator_says(): void
    {
        $manual = $this->addManual();
        $online = $this->addStandIn();
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('gateways.*.id', [$manual->id, $online->id]);
        $this->actingAs($this->staff)->putJson('/api/kit/admin/payments/order', ['ids' => [$online->id]])->assertStatus(422);
        $this->actingAs($this->staff)->putJson('/api/kit/admin/payments/order', ['ids' => [$online->id, $manual->id]])->assertOk()
            ->assertJsonPath('gateways.*.id', [$online->id, $manual->id]);
        $this->actingAs($this->ana)->getJson('/api/kit/console/payments')->assertJsonPath('gateways.*.id', [$online->id, $manual->id]);
    }

    public function test_payments_are_the_staff_and_each_accounts_own(): void
    {
        $gateway = $this->addManual();
        $id = $this->topUp($gateway, '500')->json('payment.id');
        $other = User::create(['name' => 'Bo', 'email' => 'bo@other.example', 'password' => 'a long password 8']);
        $bo = Workspace::create(['name' => 'Other Co', 'status' => 'active', 'sync_state' => 'synced']);
        WorkspaceMember::create(['workspace_id' => $bo->id, 'user_id' => $other->id, 'role' => 'owner']);

        $this->actingAs($other)->getJson("/api/kit/console/payments/{$id}")->assertNotFound();
        $this->actingAs($other)->getJson('/api/kit/console/payments')->assertJsonPath('payments', []);
        $this->actingAs($this->ana)->getJson('/api/kit/admin/payments')->assertForbidden();
        $this->actingAs($this->ana)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => '500'])->assertForbidden();
        $this->assertSame([], $this->entries());
    }

    public function test_the_payer_is_emailed_when_their_credit_arrives(): void
    {
        $sink = SmtpSink::start();
        try {
            $senders = app(Senders::class);
            $senders->putFrom('hello@voice.example', null);
            $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $sink->port, 'encryption' => 'none']]]);
            $gateway = $this->addManual();
            $id = $this->topUp($gateway, '500')->json('payment.id');
            $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => '500'])->assertOk();

            $messages = $sink->messages();
            $this->assertCount(1, $messages);
            $this->assertSame([['ana@dental.example'], 'Payment received: 500.00 INR'], [$messages[0]['to'], $messages[0]['subject']]);
            $this->assertStringContainsString('we received your payment of 500.00 INR for Acme Dental', $messages[0]['text']);
            // It links to the payment's invoice (App\Kit\Invoices\CustomerInvoices).
            $this->assertSame(self::SITE.'/console/invoices/'.\App\Models\CustomerInvoice::query()->where('payment_id', $id)->sole()->id, SmtpSink::link($messages[0], self::SITE));
        } finally {
            $sink->stop();
        }
    }

    public function test_a_payer_who_signed_up_with_a_phone_is_credited_and_not_emailed(): void
    {
        $sink = SmtpSink::start();
        try {
            $senders = app(Senders::class);
            $senders->putFrom('hello@voice.example', null);
            $senders->put([['id' => 'up', 'driver' => 'smtp', 'config' => ['host' => '127.0.0.1', 'port' => $sink->port, 'encryption' => 'none']]]);
            $this->ana->forceFill(['email' => null, 'phone' => '+919876543210', 'phone_verified_at' => now()])->save();
            $id = $this->topUp($this->addManual(), '500')->json('payment.id');
            $this->actingAs($this->staff)->postJson("/api/kit/admin/payments/{$id}/confirm", ['amount' => '500'])->assertOk();
            $this->assertSame('500.00', $this->balance());
            $this->assertSame([], $sink->messages());
        } finally {
            $sink->stop();
        }
    }

    public function test_the_wizard_adds_the_first_way_to_pay_or_none(): void
    {
        $step = fn (array $body) => $this->actingAs($this->staff)->postJson('/api/kit/setup/steps/payments', $body);
        unlink(config('kit.installed_marker'));
        $settings = app(Settings::class);
        $settings->put('setup.database.done_at', now()->toIso8601String());
        $settings->put('brand.saved_at', now()->toIso8601String());
        $settings->put(Senders::SKIPPED, true);
        $settings->put(SignupSettings::MODE, 'closed');
        $fields = collect($this->actingAs($this->staff)->getJson('/api/kit/setup')->json('steps'))->firstWhere('id', 'payments')['fields'];
        $this->assertSame(['driver', 'name', 'mode', 'manual.instructions', 'razorpay.keyId', 'razorpay.keySecret', 'razorpay.webhookSecret', 'stripe.secretKey', 'stripe.webhookSecret', 'paypal.clientId', 'paypal.clientSecret', 'paypal.webhookId', 'paystack.secretKey', 'flutterwave.secretKey', 'flutterwave.secretHash', 'tap.secretKey', 'standin.keyId', 'standin.keySecret', 'standin.webhookSecret'], array_column($fields, 'name'));
        $this->assertSame(['manual', 'razorpay', 'stripe', 'paypal', 'paystack', 'flutterwave', 'tap', 'standin', 'none'], array_column($fields[0]['options'], 'value'));

        $step(['driver' => 'manual'])->assertStatus(422)->assertJsonValidationErrors(['manual.instructions']);
        $step(['driver' => 'none'])->assertOk();
        $this->assertSame(0, PaymentGateway::query()->count());
        $step(['driver' => 'manual', 'manual.instructions' => 'Pay to account 123'])->assertOk();
        $step(['driver' => 'manual', 'name' => 'NEFT', 'manual.instructions' => 'Pay to account 456'])->assertOk();
        $gateway = PaymentGateway::query()->sole();
        $this->assertSame(['NEFT', true, 'Pay to account 456'], [$gateway->name, $gateway->enabled, $gateway->config['instructions']]);

        $step(['driver' => 'standin', 'mode' => 'test', 'standin.keyId' => self::TEST_KEY[0], 'standin.keySecret' => 'wrong', 'standin.webhookSecret' => 'w'])
            ->assertStatus(422)->assertJsonValidationErrors(['driver']);
        $step(['driver' => 'standin', 'mode' => 'test', 'standin.keyId' => self::TEST_KEY[0], 'standin.keySecret' => self::TEST_KEY[1], 'standin.webhookSecret' => 'w'])->assertOk();
        $online = PaymentGateway::query()->where('driver', 'standin')->sole();
        $this->assertSame(['test', false], [$online->mode, $online->enabled], 'a gateway in test mode is added but not enabled');
    }

    public function test_old_gateway_events_are_deleted(): void
    {
        $gateway = $this->addStandIn();
        $payment = Payment::query()->findOrFail($this->topUp($gateway, '500')->json('payment.id'));
        $this->webhook($gateway, 'payment.paid', ['status' => 'paid'] + $this->orderOf($payment))->assertOk();
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->travel(config('kit.payments.events_keep_days') + 1)->days();
        $this->artisan('kit:payments')->expectsOutputToContain('1 old event(s) deleted')->assertSuccessful();
        $this->assertSame(0, PaymentEvent::query()->count());
    }
}
