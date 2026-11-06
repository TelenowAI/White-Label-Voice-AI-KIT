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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stripe, through hosted Checkout Sessions: the payer is sent to Stripe's own page, with no script and no card data here.
 * The kit's payment id travels as the session's `client_reference_id` and in its PaymentIntent's metadata, and the
 * session's id is the gateway's id for the payment. Delayed methods pay after the session completes, so only a
 * `payment_status` of paid counts.
 *
 * A refund or dispute event names only the PaymentIntent, so it asks Stripe for its metadata (a refund the kit made says
 * the payment in its own metadata). It reads snapshot events: a thin event carries no object and changes nothing. Which
 * methods show is the operator's Dashboard setting. Written from Stripe's documents (11-03): not yet run against a real
 * Stripe account.
 *
 * Not here: the money rules (Payments), the address, API version and timings (config('kit.payments.stripe')).
 */
final class StripeDriver implements PaymentDriver
{
    private const METADATA = 'kit_payment_id';

    public function fields(array $config): array
    {
        $text = fn (string $key) => __("payments.drivers.stripe.{$key}");

        return [
            new Field('secretKey', $text('fields.secretKey'), 'secret', true, null, $text('help.secretKey'), [], null, ($config['secretKey'] ?? '') !== ''),
            new Field('webhookSecret', $text('fields.webhookSecret'), 'secret', true, null, $text('help.webhookSecret'), [], null, ($config['webhookSecret'] ?? '') !== ''),
        ];
    }

    public function rules(): array
    {
        return [
            'secretKey' => ['required', 'string', 'max:300', 'regex:/^(sk|rk)_(test|live)_/'],
            'webhookSecret' => ['required', 'string', 'max:300', 'regex:/^whsec_/'],
        ];
    }

    public function secrets(): array
    {
        return ['secretKey', 'webhookSecret'];
    }

    public function currencies(): ?array
    {
        return null;
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
        return ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed',
            'refund.created', 'refund.updated', 'refund.failed', 'charge.dispute.created', 'charge.dispute.closed'];
    }

    public function checkAfterMinutes(): int
    {
        return (int) config('kit.payments.stripe.check_after_minutes');
    }

    public function verify(PaymentGateway $gateway): void
    {
        $keyMode = str_contains(substr((string) $gateway->config['secretKey'], 0, 8), '_live_') ? PaymentGateway::LIVE : PaymentGateway::TEST;
        if ($keyMode !== $gateway->mode) {
            throw new PaymentFailed(__('payments.wrong_mode_keys', ['keys' => __("payments.modes.{$keyMode}"), 'mode' => __("payments.modes.{$gateway->mode}")]));
        }
        $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v1/checkout/sessions'), ['limit' => 1]));
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        $email = $payment->user?->email;
        $session = $this->call($gateway, fn (PendingRequest $h) => $h->asForm()->post($this->url('/v1/checkout/sessions'), [
            'mode' => 'payment',
            'client_reference_id' => $payment->id,
            'success_url' => $returnUrl,
            'cancel_url' => $returnUrl,
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => strtolower($payment->currency),
                'unit_amount' => Money::toMinor($payment->amount, $payment->currency),
                'product_data' => ['name' => __('payments.top_up_description', ['brand' => app(Brand::class)->current()['name']])],
            ]]],
            'metadata' => [self::METADATA => $payment->id],
            'payment_intent_data' => ['metadata' => [self::METADATA => $payment->id]],
            ...(is_string($email) && $email !== '' ? ['customer_email' => $email] : []),
        ]));
        if (! is_string($session['url'] ?? null) || ! is_string($session['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }

        return Checkout::redirect($session['url'], $session['id']);
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return $this->fetch($gateway, $payment);
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        $this->checkSignature($body, $headers['stripe-signature'] ?? '', (string) $gateway->config['webhookSecret']);
        $event = json_decode($body, true);
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];
        $eventId = is_string($event['id'] ?? null) ? $event['id'] : null;
        $about = fn (string $kind) => $this->aboutPayment($gateway, $kind, $object, $eventId);

        return match ($event['type'] ?? null) {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => array_values(array_filter([$this->ofSession($object, $eventId)])),
            'checkout.session.async_payment_failed' => [new GatewayEvent(GatewayEvent::FAILED, $object['client_reference_id'] ?? null, $object['id'] ?? null, null, null, null, $eventId)],
            'refund.created' => $this->refundFailed($object) ? [] : $about(GatewayEvent::REFUNDED),
            'refund.failed' => $about(GatewayEvent::REFUND_FAILED),
            'refund.updated' => $this->refundFailed($object) ? $about(GatewayEvent::REFUND_FAILED) : [],
            'charge.dispute.created' => $about(GatewayEvent::DISPUTE_OPENED),
            'charge.dispute.closed' => match ($object['status'] ?? null) {
                'won', 'warning_closed', 'prevented' => $about(GatewayEvent::DISPUTE_WON),
                'lost' => $about(GatewayEvent::DISPUTE_LOST),
                default => [],
            },
            default => [],
        };
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        return $payment->gateway_ref === null ? null : $this->ofSession($this->session($gateway, $payment), null);
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        $intent = $this->session($gateway, $payment)['payment_intent'] ?? null;
        if (! is_string($intent)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }
        $refund = $this->call($gateway, fn (PendingRequest $h) => $h->asForm()->withHeaders(['Idempotency-Key' => $reference])->post($this->url('/v1/refunds'), [
            'payment_intent' => $intent,
            'amount' => Money::toMinor($amount, $payment->currency),
            'metadata' => [self::METADATA => $payment->id],
        ]));
        if ($this->refundFailed($refund) || ! is_string($refund['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_refund_failed'));
        }

        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, $payment->gateway_ref, Money::fromMinor($refund['amount'], $payment->currency), $payment->currency, $refund['id']);
    }

    /** A session's money: paid, or null while it is open, waiting on a delayed method, or run out (the kit gives up by itself). */
    private function ofSession(array $session, ?string $eventId): ?GatewayEvent
    {
        if (($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }
        $currency = is_string($session['currency'] ?? null) ? strtoupper($session['currency']) : null;

        return new GatewayEvent(GatewayEvent::PAID, $session['client_reference_id'] ?? null, $session['id'] ?? null,
            $currency !== null && isset($session['amount_total']) ? Money::fromMinor($session['amount_total'], $currency) : null, $currency, null, $eventId);
    }

    private function refundFailed(array $refund): bool
    {
        return in_array($refund['status'] ?? null, ['failed', 'canceled'], true);
    }

    /** @return list<GatewayEvent> a refund or dispute, tied to the kit's payment by its own metadata or its PaymentIntent's */
    private function aboutPayment(PaymentGateway $gateway, string $kind, array $part, ?string $eventId): array
    {
        if (! is_string($part['id'] ?? null)) {
            return [];
        }
        $paymentId = $part['metadata'][self::METADATA] ?? null;
        if (! is_string($paymentId)) {
            if (! is_string($part['payment_intent'] ?? null)) {
                return [];
            }
            $intent = $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v1/payment_intents/'.rawurlencode($part['payment_intent']))));
            $paymentId = $intent['metadata'][self::METADATA] ?? null;
        }
        $currency = is_string($part['currency'] ?? null) ? strtoupper($part['currency']) : null;
        $amount = $currency !== null && isset($part['amount']) ? Money::fromMinor($part['amount'], $currency) : null;

        return [new GatewayEvent($kind, is_string($paymentId) ? $paymentId : null, null, $amount, $currency, $part['id'], $eventId)];
    }

    /** Stripe's scheme: `t=<time>,v1=<hex>,…`, an HMAC-SHA256 of "<time>.<body>"; while a secret is rolled there can be several v1. */
    private function checkSignature(string $body, string $header, string $secret): void
    {
        $time = null;
        $signatures = [];
        foreach (explode(',', $header) as $item) {
            [$scheme, $value] = array_pad(explode('=', trim($item), 2), 2, '');
            if ($scheme === 't') {
                $time = $value;
            } elseif ($scheme === 'v1') {
                $signatures[] = $value;
            }
        }
        $expected = hash_hmac('sha256', "{$time}.{$body}", $secret);
        $matched = array_filter($signatures, fn (string $s) => hash_equals($expected, $s)) !== [];
        if (! $matched || ! ctype_digit((string) $time) || abs(time() - (int) $time) > (int) config('kit.payments.stripe.signature_tolerance_seconds')) {
            throw new PaymentFailed(__('payments.signature'));
        }
    }

    private function session(PaymentGateway $gateway, Payment $payment): array
    {
        return $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v1/checkout/sessions/'.rawurlencode((string) $payment->gateway_ref))));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('kit.payments.stripe.api_url'), '/').$path;
    }

    /** One authenticated call; a refusal or no answer is a PaymentFailed in Stripe's words. */
    private function call(PaymentGateway $gateway, \Closure $send): array
    {
        try {
            /** @var Response $r */
            $r = $send(Http::withToken((string) $gateway->config['secretKey'])->acceptJson()
                ->withHeaders(['Stripe-Version' => (string) config('kit.payments.stripe.api_version')])
                ->timeout((int) config('kit.payments.stripe.timeout_seconds')));
        } catch (ConnectionException $e) {
            throw new PaymentFailed($e->getMessage(), 0, $e);
        }
        if ($r->failed()) {
            throw new PaymentFailed((string) ($r->json('error.message') ?? "HTTP {$r->status()}"));
        }

        return $r->json() ?? [];
    }
}
