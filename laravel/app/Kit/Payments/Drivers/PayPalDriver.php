<?php

namespace App\Kit\Payments\Drivers;

use App\Kit\Brand\Brand;
use App\Kit\Payments\Checkout;
use App\Kit\Payments\GatewayEvent;
use App\Kit\Payments\Money;
use App\Kit\Payments\PaymentDriver;
use App\Kit\Payments\PaymentFailed;
use App\Kit\Setup\Field;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Brick\Math\BigDecimal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * PayPal, through Orders v2: the payer approves on PayPal's own page, and an approved order only moves money once captured,
 * so the return, the webhook and kit:payments each capture it, once, by a request id. The kit's payment id travels as the
 * purchase unit's `custom_id`, which PayPal copies to the capture. PayPal checks its own signatures: the event's exact bytes
 * are sent back to its verify call. It does not take rupees. Written from PayPal's documents (11-03): not yet run against a
 * real PayPal account.
 *
 * Not here: the money rules (Payments), the addresses and timings (config('kit.payments.paypal')), keeping its token
 * (GatewayToken).
 */
final class PayPalDriver implements PaymentDriver
{
    private const CURRENCIES = ['AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'GBP', 'HKD', 'HUF', 'ILS', 'JPY', 'MXN', 'MYR', 'NOK', 'NZD', 'PHP', 'PLN', 'SEK', 'CHF', 'SGD', 'THB', 'TWD', 'USD'];

    /** PayPal takes these with no decimals, whatever ISO 4217 says. */
    private const NO_DECIMALS = ['HUF', 'JPY', 'TWD'];

    public function fields(array $config): array
    {
        $text = fn (string $key) => __("payments.drivers.paypal.{$key}");

        return [
            new Field('clientId', $text('fields.clientId'), 'text', true, $config['clientId'] ?? null, $text('help.clientId')),
            new Field('clientSecret', $text('fields.clientSecret'), 'secret', true, null, $text('help.clientSecret'), [], null, ($config['clientSecret'] ?? '') !== ''),
            new Field('webhookId', $text('fields.webhookId'), 'text', true, $config['webhookId'] ?? null, $text('help.webhookId')),
        ];
    }

    public function rules(): array
    {
        return ['clientId' => ['required', 'string', 'max:200'], 'clientSecret' => ['required', 'string', 'max:300'], 'webhookId' => ['required', 'string', 'max:50']];
    }

    public function secrets(): array
    {
        return ['clientSecret'];
    }

    public function currencies(): ?array
    {
        return self::CURRENCIES;
    }

    public function methods(): array
    {
        return [];
    }

    public function payerNeeds(): array
    {
        return [];
    }

    public function online(): bool
    {
        return true;
    }

    public function partialRefunds(): bool
    {
        return true;
    }

    public function webhookEvents(): array
    {
        return ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED', 'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED', 'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED',
            'CUSTOMER.DISPUTE.CREATED', 'CUSTOMER.DISPUTE.RESOLVED'];
    }

    public function checkAfterMinutes(): int
    {
        return (int) config('kit.payments.paypal.check_after_minutes');
    }

    public function verify(PaymentGateway $gateway): void
    {
        $this->token($gateway, fresh: true);
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        $brand = app(Brand::class)->current()['name'];
        $order = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->withHeaders(['PayPal-Request-Id' => $payment->id, 'Prefer' => 'return=representation'])
            ->post($this->url($gateway, '/v2/checkout/orders'), [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => $payment->id,
                    'custom_id' => $payment->id,
                    'description' => mb_substr(__('payments.top_up_description', ['brand' => $brand]), 0, 127),
                    'amount' => ['currency_code' => $payment->currency, 'value' => $this->value($payment->amount, $payment->currency)],
                ]],
                'payment_source' => ['paypal' => ['experience_context' => [
                    'brand_name' => mb_substr((string) $brand, 0, 127), 'shipping_preference' => 'NO_SHIPPING', 'user_action' => 'PAY_NOW',
                    'return_url' => $returnUrl, 'cancel_url' => $returnUrl,
                ]]],
            ]));
        $approve = collect($order['links'] ?? [])->first(fn ($l) => in_array($l['rel'] ?? null, ['payer-action', 'approve'], true));
        if (! is_string($approve['href'] ?? null) || ! is_string($order['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }

        return Checkout::redirect($approve['href'], $order['id']);
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return $this->fetch($gateway, $payment);
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        $this->checkSignature($gateway, $body, $headers);
        $event = json_decode($body, true);
        $resource = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : null;

        return array_values(array_filter([match ($event['event_type'] ?? null) {
            'CHECKOUT.ORDER.APPROVED' => $this->approved($gateway, $resource, $eventId),
            'PAYMENT.CAPTURE.COMPLETED' => $this->ofCapture($resource, $resource['supplementary_data']['related_ids']['order_id'] ?? null, $eventId),
            'PAYMENT.CAPTURE.DENIED', 'PAYMENT.CAPTURE.DECLINED' => new GatewayEvent(GatewayEvent::FAILED, $resource['custom_id'] ?? null, $resource['supplementary_data']['related_ids']['order_id'] ?? null, null, null, null, $eventId),
            'PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED' => $this->refunded($gateway, $resource, $eventId),
            'CUSTOMER.DISPUTE.CREATED' => $this->disputed($gateway, GatewayEvent::DISPUTE_OPENED, $resource, $eventId),
            'CUSTOMER.DISPUTE.RESOLVED' => $this->resolved($gateway, $resource, $eventId),
            default => null,
        }]));
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        return $payment->gateway_ref === null ? null : $this->orderNow($gateway, $payment->gateway_ref);
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        $order = $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url($gateway, '/v2/checkout/orders/'.rawurlencode((string) $payment->gateway_ref))));
        $capture = $this->captureIn($order)['id'] ?? null;
        if (! is_string($capture)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }
        $refund = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->withHeaders(['PayPal-Request-Id' => $reference, 'Prefer' => 'return=representation'])
            ->post($this->url($gateway, '/v2/payments/captures/'.rawurlencode($capture).'/refund'), [
                'amount' => ['currency_code' => $payment->currency, 'value' => $this->value($amount, $payment->currency)],
                'custom_id' => $payment->id,
            ]));
        if (in_array($refund['status'] ?? null, ['FAILED', 'CANCELLED'], true) || ! is_string($refund['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_refund_failed'));
        }

        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, $payment->gateway_ref, Money::exact($amount, $payment->currency), $payment->currency, $refund['id']);
    }

    /** How an order stands now. One the payer approved is captured first, and only then: a late or repeated event finds it already done. */
    private function orderNow(PaymentGateway $gateway, string $orderId): ?GatewayEvent
    {
        $order = $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url($gateway, '/v2/checkout/orders/'.rawurlencode($orderId))));

        return match ($order['status'] ?? null) {
            'APPROVED' => $this->ofOrder($this->capture($gateway, $orderId)),
            'COMPLETED' => $this->ofOrder($order),
            'VOIDED' => new GatewayEvent(GatewayEvent::FAILED, $order['purchase_units'][0]['custom_id'] ?? null, $orderId),
            default => null,
        };
    }

    /** An approved order moves no money until captured; PayPal gives the same answer to the same request id. */
    private function capture(PaymentGateway $gateway, string $orderId): array
    {
        return $this->call($gateway, fn (PendingRequest $h) => $h->withBody('{}', 'application/json')
            ->withHeaders(['PayPal-Request-Id' => "capture-{$orderId}", 'Prefer' => 'return=representation'])
            ->post($this->url($gateway, '/v2/checkout/orders/'.rawurlencode($orderId).'/capture')));
    }

    private function approved(PaymentGateway $gateway, array $order, ?string $eventId): ?GatewayEvent
    {
        if (! is_string($order['id'] ?? null)) {
            return null;
        }
        $captured = $this->orderNow($gateway, $order['id']);

        return $captured === null ? null : new GatewayEvent($captured->kind, $captured->paymentId, $captured->gatewayRef, $captured->amount, $captured->currency, null, $eventId);
    }

    private function captureIn(array $order): ?array
    {
        $capture = $order['purchase_units'][0]['payments']['captures'][0] ?? null;

        return is_array($capture) ? $capture : null;
    }

    private function ofOrder(array $order): ?GatewayEvent
    {
        $capture = $this->captureIn($order);

        return $capture === null ? null : $this->ofCapture($capture, $order['id'] ?? null, null);
    }

    /** A capture's money: completed, or refused, or null while it is pending. */
    private function ofCapture(array $capture, ?string $orderId, ?string $eventId): ?GatewayEvent
    {
        $currency = $capture['amount']['currency_code'] ?? null;

        return match ($capture['status'] ?? null) {
            'COMPLETED' => new GatewayEvent(GatewayEvent::PAID, $capture['custom_id'] ?? null, $orderId, is_string($currency) && is_numeric($capture['amount']['value'] ?? null) ? Money::exact((string) $capture['amount']['value'], $currency) : null, $currency, null, $eventId),
            'DECLINED', 'FAILED' => new GatewayEvent(GatewayEvent::FAILED, $capture['custom_id'] ?? null, $orderId, null, null, null, $eventId),
            default => null,
        };
    }

    /** A refund or reversal: tied to the payment by its own custom_id, or by the capture it links up to. */
    private function refunded(PaymentGateway $gateway, array $refund, ?string $eventId): ?GatewayEvent
    {
        if (! is_string($refund['id'] ?? null)) {
            return null;
        }
        $paymentId = $refund['custom_id'] ?? null;
        if (! is_string($paymentId)) {
            $up = collect($refund['links'] ?? [])->firstWhere('rel', 'up')['href'] ?? null;
            $paymentId = is_string($up) ? $this->paymentIdOfCapture($gateway, basename($up)) : null;
        }
        $currency = $refund['amount']['currency_code'] ?? null;

        return new GatewayEvent(GatewayEvent::REFUNDED, $paymentId, null, is_string($currency) && is_numeric($refund['amount']['value'] ?? null) ? Money::exact((string) $refund['amount']['value'], $currency) : null, $currency, $refund['id'], $eventId);
    }

    private function resolved(PaymentGateway $gateway, array $dispute, ?string $eventId): ?GatewayEvent
    {
        $kind = match ($dispute['dispute_outcome']['outcome_code'] ?? null) {
            'RESOLVED_SELLER_FAVOUR', 'RESOLVED_WITH_PAYOUT', 'CANCELED_BY_BUYER', 'DENIED' => GatewayEvent::DISPUTE_WON,
            'RESOLVED_BUYER_FAVOUR', 'ACCEPTED' => GatewayEvent::DISPUTE_LOST,
            default => null,
        };

        return $kind === null ? null : $this->disputed($gateway, $kind, $dispute, $eventId);
    }

    private function disputed(PaymentGateway $gateway, string $kind, array $dispute, ?string $eventId): ?GatewayEvent
    {
        if (! is_string($dispute['dispute_id'] ?? null)) {
            return null;
        }
        $transaction = $dispute['disputed_transactions'][0] ?? [];
        $paymentId = $transaction['custom'] ?? null;
        if (! is_string($paymentId) && is_string($transaction['seller_transaction_id'] ?? null)) {
            $paymentId = $this->paymentIdOfCapture($gateway, $transaction['seller_transaction_id']);
        }
        $currency = $dispute['dispute_amount']['currency_code'] ?? null;

        return new GatewayEvent($kind, is_string($paymentId) ? $paymentId : null, null, is_string($currency) && is_numeric($dispute['dispute_amount']['value'] ?? null) ? Money::exact((string) $dispute['dispute_amount']['value'], $currency) : null, $currency, $dispute['dispute_id'], $eventId);
    }

    private function paymentIdOfCapture(PaymentGateway $gateway, string $captureId): ?string
    {
        $capture = $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url($gateway, '/v2/payments/captures/'.rawurlencode($captureId))));

        return is_string($capture['custom_id'] ?? null) ? $capture['custom_id'] : null;
    }

    /** PayPal's own verify call, sent the event's exact bytes: parsing and writing it again can change them and fail the check. */
    private function checkSignature(PaymentGateway $gateway, string $body, array $headers): void
    {
        $fields = [];
        foreach (['auth_algo' => 'paypal-auth-algo', 'cert_url' => 'paypal-cert-url', 'transmission_id' => 'paypal-transmission-id', 'transmission_sig' => 'paypal-transmission-sig', 'transmission_time' => 'paypal-transmission-time'] as $name => $header) {
            if (($headers[$header] ?? '') === '') {
                throw new PaymentFailed(__('payments.signature'));
            }
            $fields[$name] = $headers[$header];
        }
        if (! is_array(json_decode($body, true))) {
            throw new PaymentFailed(__('payments.signature'));
        }
        $start = substr((string) json_encode($fields + ['webhook_id' => $gateway->config['webhookId']]), 0, -1);
        $answer = $this->call($gateway, fn (PendingRequest $h) => $h->withBody("{$start},\"webhook_event\":{$body}}", 'application/json')
            ->post($this->url($gateway, '/v1/notifications/verify-webhook-signature')));
        if (($answer['verification_status'] ?? null) !== 'SUCCESS') {
            throw new PaymentFailed(__('payments.signature'));
        }
    }

    private function value(string $amount, string $currency): string
    {
        if (! in_array($currency, self::NO_DECIMALS, true)) {
            return Money::exact($amount, $currency);
        }
        $whole = BigDecimal::of($amount);
        if (! $whole->remainder(1)->isZero()) {
            throw new PaymentFailed(__('payments.drivers.paypal.whole_amounts', ['currency' => $currency]));
        }

        return (string) $whole->toScale(0);
    }

    private function url(PaymentGateway $gateway, string $path): string
    {
        return rtrim((string) config("kit.payments.paypal.api_urls.{$gateway->mode}"), '/').$path;
    }

    private function token(PaymentGateway $gateway, bool $fresh = false): string
    {
        return GatewayToken::get($gateway, function () use ($gateway) {
            $answer = $this->answer(fn () => Http::acceptJson()->asForm()->timeout((int) config('kit.payments.paypal.timeout_seconds'))
                ->withBasicAuth((string) $gateway->config['clientId'], (string) $gateway->config['clientSecret'])
                ->post($this->url($gateway, '/v1/oauth2/token'), ['grant_type' => 'client_credentials']));
            if (! is_string($answer['access_token'] ?? null)) {
                throw new PaymentFailed(__('payments.gateway_unclear'));
            }

            return [$answer['access_token'], (int) ($answer['expires_in'] ?? 0)];
        }, $fresh);
    }

    /** One authenticated call; a refusal or no answer is a PaymentFailed in PayPal's words. */
    private function call(PaymentGateway $gateway, \Closure $send): array
    {
        $token = $this->token($gateway);

        return $this->answer(fn () => $send(Http::acceptJson()->timeout((int) config('kit.payments.paypal.timeout_seconds'))->withToken($token)));
    }

    private function answer(\Closure $send): array
    {
        try {
            /** @var Response $r */
            $r = $send();
        } catch (ConnectionException $e) {
            throw new PaymentFailed($e->getMessage(), 0, $e);
        }
        if ($r->failed()) {
            throw new PaymentFailed((string) ($r->json('message') ?? $r->json('error_description') ?? "HTTP {$r->status()}"));
        }

        return $r->json() ?? [];
    }
}
