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
 * Razorpay, through Payment Links: the payer is sent to Razorpay's own page and comes back with a plain GET, with no
 * script and no card data here. The kit's payment id travels as the link's `reference_id`, and the link's id is the
 * gateway's id for the payment.
 *
 * A refund or dispute event names only the payment's order, and a link's order holds the reference as its `receipt`, so
 * that event asks Razorpay for the order (a refund the kit made says the payment in its own notes). Which methods show is
 * the operator's account setting. Written from Razorpay's documents (11-03): not yet run against a real Razorpay account.
 *
 * Not here: the money rules (Payments), the address and timings (config('kit.payments.razorpay')).
 */
final class RazorpayDriver implements PaymentDriver
{
    private const NOTE = 'kit_payment_id';

    /** The length Razorpay takes for a customer's number, country code included. */
    private const CONTACT_LENGTHS = [8, 14];

    public function fields(array $config): array
    {
        $text = fn (string $key) => __("payments.drivers.razorpay.{$key}");

        return [
            new Field('keyId', $text('fields.keyId'), 'text', true, $config['keyId'] ?? null, $text('help.keyId')),
            new Field('keySecret', $text('fields.keySecret'), 'secret', true, null, $text('help.keySecret'), [], null, ($config['keySecret'] ?? '') !== ''),
            new Field('webhookSecret', $text('fields.webhookSecret'), 'secret', true, null, $text('help.webhookSecret'), [], null, ($config['webhookSecret'] ?? '') !== ''),
        ];
    }

    public function rules(): array
    {
        return [
            'keyId' => ['required', 'string', 'max:100'],
            'keySecret' => ['required', 'string', 'max:200'],
            'webhookSecret' => ['required', 'string', 'max:200'],
        ];
    }

    public function secrets(): array
    {
        return ['keySecret', 'webhookSecret'];
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
        return ['payment_link.paid', 'payment_link.cancelled', 'refund.processed', 'refund.failed',
            'payment.dispute.created', 'payment.dispute.won', 'payment.dispute.lost'];
    }

    public function checkAfterMinutes(): int
    {
        return (int) config('kit.payments.razorpay.check_after_minutes');
    }

    public function verify(PaymentGateway $gateway): void
    {
        $keyId = (string) $gateway->config['keyId'];
        $keyMode = match (true) {
            str_starts_with($keyId, 'rzp_live_') => PaymentGateway::LIVE,
            str_starts_with($keyId, 'rzp_test_') => PaymentGateway::TEST,
            default => null,
        };
        if ($keyMode !== null && $keyMode !== $gateway->mode) {
            throw new PaymentFailed(__('payments.wrong_mode_keys', ['keys' => __("payments.modes.{$keyMode}"), 'mode' => __("payments.modes.{$gateway->mode}")]));
        }
        $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v1/orders'), ['count' => 1]));
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        $user = $payment->user;
        $contact = $user?->phone;
        $customer = array_filter([
            'name' => $user?->name,
            'email' => $user?->email,
            'contact' => is_string($contact) && strlen($contact) >= self::CONTACT_LENGTHS[0] && strlen($contact) <= self::CONTACT_LENGTHS[1] ? $contact : null,
        ]);
        $link = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/v1/payment_links'), [
            'amount' => Money::toMinor($payment->amount, $payment->currency),
            'currency' => $payment->currency,
            'accept_partial' => false,
            'upi_link' => false,
            'reference_id' => $payment->id,
            'description' => __('payments.top_up_description', ['brand' => app(Brand::class)->current()['name']]),
            'notify' => ['sms' => false, 'email' => false],
            'reminder_enable' => false,
            'notes' => [self::NOTE => $payment->id],
            'expire_by' => $payment->expires_at->copy()->max(now()->addMinutes((int) config('kit.payments.razorpay.min_link_minutes')))->getTimestamp(),
            'callback_url' => $returnUrl,
            'callback_method' => 'get',
            ...($customer === [] ? [] : ['customer' => $customer]),
        ]));
        if (! is_string($link['short_url'] ?? null) || ! is_string($link['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }

        return Checkout::redirect($link['short_url'], $link['id']);
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return $this->fetch($gateway, $payment);
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        $signature = $headers['x-razorpay-signature'] ?? '';
        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $body, (string) $gateway->config['webhookSecret']), $signature)) {
            throw new PaymentFailed(__('payments.signature'));
        }
        $event = json_decode($body, true);
        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $eventId = ($headers['x-razorpay-event-id'] ?? '') !== '' ? $headers['x-razorpay-event-id'] : null;
        $entity = fn (string $name) => is_array($payload[$name]['entity'] ?? null) ? $payload[$name]['entity'] : [];
        $about = fn (string $kind, string $part) => $this->aboutPayment($gateway, $kind, $entity($part), $entity('payment'), $eventId);

        return match ($event['event'] ?? null) {
            'payment_link.paid', 'payment_link.cancelled' => array_values(array_filter([$this->ofLink($entity('payment_link'), $eventId)])),
            'refund.processed' => $about(GatewayEvent::REFUNDED, 'refund'),
            'refund.failed' => $about(GatewayEvent::REFUND_FAILED, 'refund'),
            'payment.dispute.created' => $about(GatewayEvent::DISPUTE_OPENED, 'dispute'),
            'payment.dispute.won' => $about(GatewayEvent::DISPUTE_WON, 'dispute'),
            'payment.dispute.lost' => $about(GatewayEvent::DISPUTE_LOST, 'dispute'),
            default => [],
        };
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        return $payment->gateway_ref === null ? null : $this->ofLink($this->link($gateway, $payment), null);
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        $paid = collect($this->link($gateway, $payment)['payments'] ?? [])->firstWhere('status', 'captured');
        if (! is_string($paid['payment_id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }
        $path = '/v1/payments/'.rawurlencode($paid['payment_id']);
        try {
            $refund = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url("{$path}/refund"), [
                'amount' => Money::toMinor($amount, $payment->currency), 'speed' => 'normal', 'receipt' => $reference,
                'notes' => [self::NOTE => $payment->id],
            ]));
        } catch (PaymentFailed $e) {
            // Razorpay refuses a receipt it has seen, so asking again after a lost answer finds the refund it made.
            $refund = $this->earlierRefund($gateway, $path, $reference) ?? throw $e;
        }
        if (($refund['status'] ?? null) === 'failed' || ! is_string($refund['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_refund_failed'));
        }

        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, $payment->gateway_ref, Money::fromMinor($refund['amount'], $payment->currency), $payment->currency, $refund['id']);
    }

    /** How a link stands: paid, cancelled, or null while the payer can still pay it (and once it ran out: the kit gives up by itself). */
    private function ofLink(array $link, ?string $eventId): ?GatewayEvent
    {
        $kind = match ($link['status'] ?? null) {
            'paid' => GatewayEvent::PAID,
            'cancelled' => GatewayEvent::FAILED,
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $currency = $link['currency'] ?? null;

        return new GatewayEvent($kind, $link['reference_id'] ?? null, $link['id'] ?? null,
            $kind === GatewayEvent::PAID && is_string($currency) && isset($link['amount_paid']) ? Money::fromMinor($link['amount_paid'], $currency) : null,
            $kind === GatewayEvent::PAID ? $currency : null, null, $eventId);
    }

    /** @return list<GatewayEvent> a refund or dispute, tied to the kit's payment by its notes or its order's receipt */
    private function aboutPayment(PaymentGateway $gateway, string $kind, array $part, array $paid, ?string $eventId): array
    {
        if (! is_string($part['id'] ?? null)) {
            return [];
        }
        $paymentId = $part['notes'][self::NOTE] ?? null;
        if (! is_string($paymentId)) {
            if (! is_string($paid['order_id'] ?? null)) {
                return [];
            }
            $paymentId = $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v1/orders/'.rawurlencode($paid['order_id']))))['receipt'] ?? null;
        }
        $currency = $part['currency'] ?? $paid['currency'] ?? null;
        $amount = is_string($currency) && isset($part['amount']) ? Money::fromMinor($part['amount'], $currency) : null;

        return [new GatewayEvent($kind, is_string($paymentId) ? $paymentId : null, null, $amount, $currency, $part['id'], $eventId)];
    }

    private function earlierRefund(PaymentGateway $gateway, string $path, string $reference): ?array
    {
        try {
            return collect($this->call($gateway, fn (PendingRequest $h) => $h->get($this->url("{$path}/refunds")))['items'] ?? [])->firstWhere('receipt', $reference);
        } catch (PaymentFailed) {
            return null;
        }
    }

    private function link(PaymentGateway $gateway, Payment $payment): array
    {
        return $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v1/payment_links/'.rawurlencode((string) $payment->gateway_ref))));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('kit.payments.razorpay.api_url'), '/').$path;
    }

    /** One authenticated call; a refusal or no answer is a PaymentFailed in Razorpay's words. */
    private function call(PaymentGateway $gateway, \Closure $send): array
    {
        try {
            /** @var Response $r */
            $r = $send(Http::withBasicAuth((string) $gateway->config['keyId'], (string) $gateway->config['keySecret'])
                ->acceptJson()->timeout((int) config('kit.payments.razorpay.timeout_seconds')));
        } catch (ConnectionException $e) {
            throw new PaymentFailed($e->getMessage(), 0, $e);
        }
        if ($r->failed()) {
            throw new PaymentFailed((string) ($r->json('error.description') ?? "HTTP {$r->status()}"));
        }

        return $r->json() ?? [];
    }
}
