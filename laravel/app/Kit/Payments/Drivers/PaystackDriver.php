<?php

namespace App\Kit\Payments\Drivers;

use App\Kit\Payments\Checkout;
use App\Kit\Payments\GatewayEvent;
use App\Kit\Payments\PaymentDriver;
use App\Kit\Payments\PaymentFailed;
use App\Kit\Setup\Field;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Paystack, through its hosted checkout: the payer is sent to Paystack's own page and comes back with a plain GET, with no
 * script and no card data here. The kit's payment id is the transaction's `reference`, which every later call and event
 * names. One secret key does everything: its prefix says test or live, and it signs the webhooks.
 *
 * A refund event names the bank's reference, not the refund's id, and a refund has no key that makes sending it twice safe.
 * So that event only makes the kit ask Paystack for the transaction's refunds, each counted once by its own id, and a refund
 * carries the kit's reference as its `merchant_note`, looked for first. A refund a dispute made is that dispute's money and
 * not counted again. Written from Paystack's documents (11-03): not yet run against a real Paystack account.
 *
 * Not here: the money rules (Payments), the address, currencies and timings (config('kit.payments.paystack')).
 */
final class PaystackDriver implements PaymentDriver
{
    /** Paystack takes every amount multiplied by 100, whatever the currency: also XOF, which has no subunit. */
    private const SUBUNIT_PLACES = 2;

    public function fields(array $config): array
    {
        $text = fn (string $key) => __("payments.drivers.paystack.{$key}");

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
        return config('kit.payments.paystack.currencies');
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

    /** Paystack sends every event to the one address: there are none to tick. */
    public function webhookEvents(): array
    {
        return [];
    }

    public function checkAfterMinutes(): int
    {
        return (int) config('kit.payments.paystack.check_after_minutes');
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
        $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/transaction'), ['perPage' => 1]));
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        $email = $payment->payer('email') ?? throw new PaymentFailed(__('payments.payer_detail_missing'));
        $started = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/transaction/initialize'), [
            'email' => $email,
            'amount' => (string) $this->toSubunits($payment->amount),
            'currency' => $payment->currency,
            'reference' => $payment->id,
            'callback_url' => $returnUrl,
        ]));
        if (! is_string($started['authorization_url'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }

        return Checkout::redirect($started['authorization_url'], $payment->id);
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return $this->fetch($gateway, $payment);
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        $signature = $headers['x-paystack-signature'] ?? '';
        if ($signature === '' || ! hash_equals(hash_hmac('sha512', $body, (string) $gateway->config['secretKey']), $signature)) {
            throw new PaymentFailed(__('payments.signature'));
        }
        $event = json_decode($body, true);
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $name = is_string($event['event'] ?? null) ? $event['event'] : '';

        return match (true) {
            $name === 'charge.success' => array_values(array_filter([$this->ofTransaction($data)])),
            str_starts_with($name, 'refund.') => $this->refundsNow($gateway, $data['transaction_reference'] ?? null),
            $name === 'charge.dispute.create' => $this->disputed(GatewayEvent::DISPUTE_OPENED, $data),
            $name === 'charge.dispute.resolve' => match ($data['resolution'] ?? null) {
                'merchant-accepted', 'auto-accepted' => $this->disputed(GatewayEvent::DISPUTE_LOST, $data),
                'declined' => $this->disputed(GatewayEvent::DISPUTE_WON, $data),
                default => [],
            },
            default => [],
        };
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        return $this->ofTransaction($this->transaction($gateway, $payment->id));
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        $transaction = $this->transaction($gateway, $payment->id);
        if (! isset($transaction['id'])) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }
        $refund = collect($this->refundsOf($gateway, $transaction))->firstWhere('merchant_note', $reference)
            ?? $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/refund'), [
                'transaction' => $transaction['id'],
                'amount' => $this->toSubunits($amount),
                'currency' => $payment->currency,
                'merchant_note' => $reference,
            ]));
        if (($refund['status'] ?? null) === 'failed' || ! isset($refund['id'], $refund['amount'])) {
            throw new PaymentFailed(__('payments.gateway_refund_failed'));
        }

        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, $payment->gateway_ref, $this->fromSubunits($refund['amount']), $payment->currency, (string) $refund['id']);
    }

    /** How a transaction stands: paid, failed, or null while the payer can still pay it (`abandoned` too: the kit gives up by itself). */
    private function ofTransaction(array $transaction): ?GatewayEvent
    {
        $kind = match ($transaction['status'] ?? null) {
            'success' => GatewayEvent::PAID,
            'failed' => GatewayEvent::FAILED,
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $paid = $kind === GatewayEvent::PAID && isset($transaction['amount'], $transaction['currency']);

        return new GatewayEvent($kind, $transaction['reference'] ?? null, $transaction['reference'] ?? null,
            $paid ? $this->fromSubunits($transaction['amount']) : null, $paid ? $transaction['currency'] : null);
    }

    /** @return list<GatewayEvent> each refund of one of the kit's transactions as Paystack has it now, except those a dispute made */
    private function refundsNow(PaymentGateway $gateway, mixed $reference): array
    {
        if (! is_string($reference) || ! Str::isUuid($reference)) {
            return []; // not a payment of the kit's
        }
        $events = [];
        foreach ($this->refundsOf($gateway, $this->transaction($gateway, $reference)) as $refund) {
            if (isset($refund['id'], $refund['amount']) && ! isset($refund['dispute'])) {
                $events[] = new GatewayEvent(($refund['status'] ?? null) === 'failed' ? GatewayEvent::REFUND_FAILED : GatewayEvent::REFUNDED,
                    $reference, null, $this->fromSubunits($refund['amount']), $refund['currency'] ?? null, (string) $refund['id']);
            }
        }

        return $events;
    }

    /** @return list<GatewayEvent> a dispute, tied to the kit's payment by its transaction's reference */
    private function disputed(string $kind, array $dispute): array
    {
        if (! isset($dispute['id'])) {
            return [];
        }
        $reference = $dispute['transaction']['reference'] ?? $dispute['transaction_reference'] ?? null;

        return [new GatewayEvent($kind, is_string($reference) ? $reference : null, null,
            isset($dispute['refund_amount']) ? $this->fromSubunits($dispute['refund_amount']) : null, $dispute['currency'] ?? null, (string) $dispute['id'])];
    }

    private function transaction(PaymentGateway $gateway, string $reference): array
    {
        return $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/transaction/verify/'.rawurlencode($reference))));
    }

    /** @return list<array> */
    private function refundsOf(PaymentGateway $gateway, array $transaction): array
    {
        if (! isset($transaction['id'], $transaction['currency'])) {
            return [];
        }

        return $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/refund'), ['transaction' => $transaction['id'], 'currency' => $transaction['currency']]));
    }

    private function toSubunits(string $amount): int
    {
        return BigDecimal::of($amount)->toScale(self::SUBUNIT_PLACES)->getUnscaledValue()->toInt();
    }

    private function fromSubunits(int|string $subunits): string
    {
        return (string) BigDecimal::ofUnscaledValue(BigInteger::of($subunits), self::SUBUNIT_PLACES);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('kit.payments.paystack.api_url'), '/').$path;
    }

    /** One authenticated call, answering its `data`; a refusal or no answer is a PaymentFailed in Paystack's words. */
    private function call(PaymentGateway $gateway, \Closure $send): array
    {
        try {
            /** @var Response $r */
            $r = $send(Http::withToken((string) $gateway->config['secretKey'])->acceptJson()->timeout((int) config('kit.payments.paystack.timeout_seconds')));
        } catch (ConnectionException $e) {
            throw new PaymentFailed($e->getMessage(), 0, $e);
        }
        if ($r->failed() || $r->json('status') === false) {
            throw new PaymentFailed((string) ($r->json('message') ?? "HTTP {$r->status()}"));
        }
        $data = $r->json('data');

        return is_array($data) ? $data : [];
    }
}
