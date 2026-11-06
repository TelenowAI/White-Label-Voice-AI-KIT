<?php

namespace App\Kit\Payments\Drivers;

use App\Kit\Brand\Brand;
use App\Kit\Payments\Checkout;
use App\Kit\Payments\GatewayEvent;
use App\Kit\Payments\Gateways;
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
 * Tap, through its hosted page (`src_all`): the payer is sent to Tap's own page and comes back with a plain GET, with no
 * script and no card data here. The charge's id is the gateway's id for the payment. Each charge and refund says where its
 * events go, so the operator sets up no webhook, and one secret key does everything: its prefix says test or live.
 *
 * Tap's signature covers only an event's id, amount, currency, references, status and time, not its metadata or
 * `reference.transaction`. So a charge's event finds the payment by that id, and nothing unsigned decides anything; a
 * refund's event can only name its charge in the unsigned `charge_id`, which someone would need a real signed event to
 * change. Tap sends no dispute events. Written from Tap's documents (11-03): not yet run against a real Tap account.
 *
 * Not here: the money rules (Payments), the address, currencies and timings (config('kit.payments.tap')).
 */
final class TapDriver implements PaymentDriver
{
    /** Its refund statuses that mean the money did not go back. UNKNOWN and TIMED_OUT say nothing, so they change nothing. */
    private const REFUND_FAILED = ['DECLINED', 'FAILED', 'RESTRICTED', 'REJECTED'];

    public function fields(array $config): array
    {
        $text = fn (string $key) => __("payments.drivers.tap.{$key}");

        return [
            new Field('secretKey', $text('fields.secretKey'), 'secret', true, null, $text('help.secretKey'), [], null, ($config['secretKey'] ?? '') !== ''),
        ];
    }

    public function rules(): array
    {
        return ['secretKey' => ['required', 'string', 'max:300']];
    }

    public function secrets(): array
    {
        return ['secretKey'];
    }

    public function currencies(): ?array
    {
        return config('kit.payments.tap.currencies');
    }

    public function methods(): array
    {
        return [];
    }

    public function payerNeeds(): array
    {
        return ['email'];
    }

    public function online(): bool
    {
        return true;
    }

    public function partialRefunds(): bool
    {
        return true;
    }

    /** Each charge and refund names its own address: there is nothing to tick. */
    public function webhookEvents(): array
    {
        return [];
    }

    public function checkAfterMinutes(): int
    {
        return (int) config('kit.payments.tap.check_after_minutes');
    }

    public function verify(PaymentGateway $gateway): void
    {
        $key = (string) $gateway->config['secretKey'];
        $keyMode = match (true) {
            str_starts_with($key, 'sk_live_') => PaymentGateway::LIVE,
            str_starts_with($key, 'sk_test_') => PaymentGateway::TEST,
            default => null,
        };
        if ($keyMode !== null && $keyMode !== $gateway->mode) {
            throw new PaymentFailed(__('payments.wrong_mode_keys', ['keys' => __("payments.modes.{$keyMode}"), 'mode' => __("payments.modes.{$gateway->mode}")]));
        }
        $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/v2/charges/list'), ['limit' => 1]));
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        $email = $payment->payer('email') ?? throw new PaymentFailed(__('payments.payer_detail_missing'));
        $charge = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/v2/charges'), [
            'amount' => (float) $payment->amount,
            'currency' => $payment->currency,
            'description' => __('payments.top_up_description', ['brand' => app(Brand::class)->current()['name']]),
            'reference' => ['transaction' => $payment->id, 'order' => $payment->id],
            'customer' => ['first_name' => (string) $payment->user?->name, 'email' => $email],
            'source' => ['id' => 'src_all'],
            'post' => ['url' => $notifyUrl],
            'redirect' => ['url' => $returnUrl],
        ]));
        if (! is_string($charge['transaction']['url'] ?? null) || ! is_string($charge['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }

        return Checkout::redirect($charge['transaction']['url'], $charge['id']);
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return $this->fetch($gateway, $payment);
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        $event = json_decode($body, true);
        if (! is_array($event)) {
            throw new PaymentFailed(__('payments.signature'));
        }
        $isRefund = ($event['object'] ?? null) === 'refund';
        $this->checkSignature($event, $isRefund ? ($event['created'] ?? '') : ($event['transaction']['created'] ?? ''), $headers['hashstring'] ?? '', (string) $gateway->config['secretKey']);

        return $isRefund ? $this->ofRefund($event) : array_values(array_filter([($event['object'] ?? null) === 'charge' ? $this->ofCharge($event) : null]));
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        if ($payment->gateway_ref === null) {
            return null;
        }

        return $this->ofCharge($this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v2/charges/'.rawurlencode($payment->gateway_ref)))));
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        $refund = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/v2/refunds'), [
            'charge_id' => $payment->gateway_ref,
            'amount' => (float) $amount,
            'currency' => $payment->currency,
            'reason' => 'requested_by_customer',
            'reference' => ['merchant' => $reference, 'idempotent' => $reference],
            'post' => ['url' => app(Gateways::class)->notifyUrl($gateway)],
        ]));
        if (in_array($refund['status'] ?? null, self::REFUND_FAILED, true) || ! is_string($refund['id'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_refund_failed'));
        }

        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, $payment->gateway_ref, (string) ($refund['amount'] ?? $amount), $payment->currency, $refund['id']);
    }

    /** How a charge stands: paid, failed, or null while it is waiting for the payer (and when Tap says UNKNOWN: the kit asks again). */
    private function ofCharge(array $charge): ?GatewayEvent
    {
        $kind = match ($charge['status'] ?? null) {
            'CAPTURED' => GatewayEvent::PAID,
            'ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT' => GatewayEvent::FAILED,
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $paid = $kind === GatewayEvent::PAID && isset($charge['amount'], $charge['currency']);

        return new GatewayEvent($kind, null, $charge['id'] ?? null, $paid ? (string) $charge['amount'] : null, $paid ? $charge['currency'] : null);
    }

    /** @return list<GatewayEvent> a refund: counted unless it failed, each once by its own id */
    private function ofRefund(array $refund): array
    {
        if (! is_string($refund['id'] ?? null) || in_array($refund['status'] ?? null, ['UNKNOWN', 'TIMED_OUT'], true)) {
            return [];
        }

        return [new GatewayEvent(in_array($refund['status'] ?? null, self::REFUND_FAILED, true) ? GatewayEvent::REFUND_FAILED : GatewayEvent::REFUNDED,
            null, $refund['charge_id'] ?? null, isset($refund['amount']) ? (string) $refund['amount'] : null, $refund['currency'] ?? null, $refund['id'])];
    }

    /** Tap's `hashstring`: an HMAC-SHA256, by the secret key, of these fields, the amount at its currency's places. */
    private function checkSignature(array $event, mixed $created, string $header, string $secret): void
    {
        $currency = $event['currency'] ?? null;
        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || ! is_scalar($created)) {
            throw new PaymentFailed(__('payments.signature'));
        }
        $part = fn (mixed $value) => is_scalar($value) ? (string) $value : ''; // nothing is known of this body yet
        $amount = number_format((float) ($event['amount'] ?? 0), Money::decimals($currency), '.', '');
        $text = 'x_id'.$part($event['id'] ?? null).'x_amount'.$amount.'x_currency'.$currency
            .'x_gateway_reference'.$part($event['reference']['gateway'] ?? null).'x_payment_reference'.$part($event['reference']['payment'] ?? null)
            .'x_status'.$part($event['status'] ?? null).'x_created'.$created;
        if (! hash_equals(hash_hmac('sha256', $text, $secret), $header)) {
            throw new PaymentFailed(__('payments.signature'));
        }
    }

    private function url(string $path): string
    {
        return rtrim((string) config('kit.payments.tap.api_url'), '/').$path;
    }

    /** One authenticated call; a refusal or no answer is a PaymentFailed in Tap's words. */
    private function call(PaymentGateway $gateway, \Closure $send): array
    {
        try {
            /** @var Response $r */
            $r = $send(Http::withToken((string) $gateway->config['secretKey'])->acceptJson()->timeout((int) config('kit.payments.tap.timeout_seconds')));
        } catch (ConnectionException $e) {
            throw new PaymentFailed($e->getMessage(), 0, $e);
        }
        if ($r->failed()) {
            throw new PaymentFailed((string) ($r->json('errors.0.description') ?? $r->json('message') ?? "HTTP {$r->status()}"));
        }

        return $r->json() ?? [];
    }
}
