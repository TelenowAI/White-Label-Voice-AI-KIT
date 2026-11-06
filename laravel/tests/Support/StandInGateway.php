<?php

namespace Tests\Support;

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
 * A stand-in online gateway, for tests only: the payments module's money rules are proven against a driver that does
 * everything a real one does — keys checked by a call, a hosted checkout reached by redirect, the payer's return
 * answered by asking the gateway, signed webhooks (an HMAC-SHA256 of "<timestamp>.<body>", refused when stale),
 * refunds with an idempotency key, disputes — over HTTP to `standin_gateway.php` (the e2e copy runs it) or Http::fake
 * (PaymentsTest). It is never listed in config/kit.php: a test registers it as `config(['kit.payments.drivers.standin'
 * => StandInGateway::class])`. The real drivers have tests of their own.
 */
final class StandInGateway implements PaymentDriver
{
    /** How old a webhook's timestamp may be. */
    public const TOLERANCE_SECONDS = 300;

    public static function baseUrl(): string
    {
        return rtrim((string) (getenv('KIT_STANDIN_GATEWAY_URL') ?: 'http://127.0.0.1:8814'), '/');
    }

    /** The signature header a webhook body carries, as the stand-in signs it. */
    public static function sign(string $body, string $secret, ?int $at = null): string
    {
        $at ??= time();

        return "t={$at},v1=".hash_hmac('sha256', "{$at}.{$body}", $secret);
    }

    public function fields(array $config): array
    {
        return [
            new Field('keyId', 'Key id', 'text', true, $config['keyId'] ?? null),
            new Field('keySecret', 'Key secret', 'secret', true, null, null, [], null, ($config['keySecret'] ?? '') !== ''),
            new Field('webhookSecret', 'Webhook secret', 'secret', true, null, null, [], null, ($config['webhookSecret'] ?? '') !== ''),
        ];
    }

    public function rules(): array
    {
        return ['keyId' => ['required', 'string', 'max:100'], 'keySecret' => ['required', 'string', 'max:200'], 'webhookSecret' => ['required', 'string', 'max:200']];
    }

    public function secrets(): array
    {
        return ['keySecret', 'webhookSecret'];
    }

    public function currencies(): ?array
    {
        return ['INR', 'USD'];
    }

    public function methods(): array
    {
        return ['card', 'upi', 'netbanking'];
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
        return ['payment.paid', 'payment.failed', 'refund.processed', 'dispute.opened', 'dispute.closed'];
    }

    public function checkAfterMinutes(): int
    {
        return 2;
    }

    public function verify(PaymentGateway $gateway): void
    {
        $account = $this->call($gateway, fn (PendingRequest $h) => $h->get(self::baseUrl().'/v1/account'));
        if (($account['mode'] ?? null) !== $gateway->mode) {
            throw new PaymentFailed("These are {$account['mode']} keys; the gateway is in {$gateway->mode} mode.");
        }
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        $order = $this->call($gateway, fn (PendingRequest $h) => $h->post(self::baseUrl().'/v1/orders', [
            'amount' => Money::toMinor($payment->amount, $payment->currency), 'currency' => $payment->currency, 'reference' => $payment->id,
            'return_url' => $returnUrl, 'notify_url' => $notifyUrl, 'methods' => $gateway->methods,
        ]));

        return Checkout::redirect($order['checkout_url'], $order['id']);
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return $this->fetch($gateway, $payment);
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        if (! preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $headers['x-standin-signature'] ?? '', $m)
            || abs(time() - (int) $m[1]) > self::TOLERANCE_SECONDS
            || ! hash_equals(hash_hmac('sha256', "{$m[1]}.{$body}", (string) $gateway->config['webhookSecret']), $m[2])) {
            throw new PaymentFailed('Bad signature');
        }
        $event = json_decode($body, true);
        $order = $event['data']['order'] ?? [];
        $currency = $order['currency'] ?? null;
        $money = fn (array $o) => isset($o['amount'], $currency) ? Money::fromMinor($o['amount'], $currency) : null;
        $kind = match ($event['type'] ?? null) {
            'payment.paid' => GatewayEvent::PAID,
            'payment.failed' => GatewayEvent::FAILED,
            'refund.processed' => GatewayEvent::REFUNDED,
            'dispute.opened' => GatewayEvent::DISPUTE_OPENED,
            'dispute.closed' => ($event['data']['dispute']['status'] ?? null) === 'won' ? GatewayEvent::DISPUTE_WON : GatewayEvent::DISPUTE_LOST,
            default => null,
        };
        if ($kind === null) {
            return [];
        }
        $part = $event['data']['refund'] ?? $event['data']['dispute'] ?? null;

        return [new GatewayEvent($kind, $order['reference'] ?? null, $order['id'] ?? null, $money($part ?? $order), $currency, $part['id'] ?? null, $event['id'] ?? null)];
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        if ($payment->gateway_ref === null) {
            return null;
        }
        $order = $this->call($gateway, fn (PendingRequest $h) => $h->get(self::baseUrl()."/v1/orders/{$payment->gateway_ref}"));
        $kind = match ($order['status'] ?? null) {
            'paid' => GatewayEvent::PAID,
            'failed' => GatewayEvent::FAILED,
            default => null,
        };

        return $kind === null ? null : new GatewayEvent($kind, $order['reference'], $order['id'], Money::fromMinor($order['amount'], $order['currency']), $order['currency']);
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        $refund = $this->call($gateway, fn (PendingRequest $h) => $h->withHeaders(['Idempotency-Key' => $reference])
            ->post(self::baseUrl()."/v1/orders/{$payment->gateway_ref}/refunds", ['amount' => Money::toMinor($amount, $payment->currency)]));

        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, $payment->gateway_ref, Money::fromMinor($refund['amount'], $payment->currency), $payment->currency, $refund['id']);
    }

    /** One authenticated call; a refusal or no answer is a PaymentFailed in the gateway's words. */
    private function call(PaymentGateway $gateway, \Closure $send): array
    {
        try {
            /** @var Response $r */
            $r = $send(Http::withBasicAuth((string) $gateway->config['keyId'], (string) $gateway->config['keySecret'])->acceptJson()->timeout(10));
        } catch (ConnectionException $e) {
            throw new PaymentFailed($e->getMessage(), 0, $e);
        }
        if ($r->failed()) {
            throw new PaymentFailed((string) ($r->json('error') ?? "HTTP {$r->status()}"));
        }

        return $r->json() ?? [];
    }
}
