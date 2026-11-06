<?php

namespace App\Kit\Payments\Drivers;

use App\Kit\Brand\Brand;
use App\Kit\Payments\Checkout;
use App\Kit\Payments\GatewayEvent;
use App\Kit\Payments\Gateways;
use App\Kit\Payments\PaymentDriver;
use App\Kit\Payments\PaymentFailed;
use App\Kit\Setup\Field;
use App\Models\Payment;
use App\Models\PaymentGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Flutterwave, through its Standard hosted page (API v3): the payer is sent to Flutterwave's own page and comes back with a
 * plain GET, with no script and no card data here. The kit's payment id is the transaction's `tx_ref`. A payment gets
 * Flutterwave's own id only once the payer has tried, so the kit asks by `tx_ref`.
 *
 * Its webhook proof is a secret hash the operator chooses, sent as it is and covering nothing in the body. So an event only
 * says what to ask about, and the money always comes from Flutterwave's answer. A refund has no key that makes sending it
 * twice safe, so it carries the kit's reference as its comment, looked for first. Chargeback webhooks are off until the
 * operator asks Flutterwave. Written from Flutterwave's documents (11-03): not yet run against a real Flutterwave account.
 *
 * Not here: the money rules (Payments), the address and timings (config('kit.payments.flutterwave')).
 */
final class FlutterwaveDriver implements PaymentDriver
{
    public function fields(array $config): array
    {
        $text = fn (string $key) => __("payments.drivers.flutterwave.{$key}");

        return [
            new Field('secretKey', $text('fields.secretKey'), 'secret', true, null, $text('help.secretKey'), [], null, ($config['secretKey'] ?? '') !== ''),
            new Field('secretHash', $text('fields.secretHash'), 'secret', true, null, $text('help.secretHash'), [], null, ($config['secretHash'] ?? '') !== ''),
        ];
    }

    public function rules(): array
    {
        return ['secretKey' => ['required', 'string', 'max:300'], 'secretHash' => ['required', 'string', 'max:300']];
    }

    public function secrets(): array
    {
        return ['secretKey', 'secretHash'];
    }

    /** It depends on the operator's country and Flutterwave's own pages disagree: Flutterwave refuses one it will not take. */
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

    /** Its dashboard has no list of events to tick. */
    public function webhookEvents(): array
    {
        return [];
    }

    public function checkAfterMinutes(): int
    {
        return (int) config('kit.payments.flutterwave.check_after_minutes');
    }

    public function verify(PaymentGateway $gateway): void
    {
        $key = (string) $gateway->config['secretKey'];
        $keyMode = match (true) {
            str_starts_with($key, 'FLWSECK_TEST') => PaymentGateway::TEST,
            str_starts_with($key, 'FLWSECK-') => PaymentGateway::LIVE,
            default => null,
        };
        if ($keyMode !== null && $keyMode !== $gateway->mode) {
            throw new PaymentFailed(__('payments.wrong_mode_keys', ['keys' => __("payments.modes.{$keyMode}"), 'mode' => __("payments.modes.{$gateway->mode}")]));
        }
        $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v3/transactions'), ['page' => 1]));
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        $email = $payment->payer('email') ?? throw new PaymentFailed(__('payments.payer_detail_missing'));
        $brand = app(Brand::class)->current()['name'];
        $started = $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/v3/payments'), [
            'tx_ref' => $payment->id,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'redirect_url' => $returnUrl,
            'customer' => array_filter(['email' => $email, 'name' => $payment->user?->name]),
            'customizations' => ['title' => $brand, 'description' => __('payments.top_up_description', ['brand' => $brand])],
        ]));
        if (! is_string($started['link'] ?? null)) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }

        return Checkout::redirect($started['link'], null);
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return $this->fetch($gateway, $payment);
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        if (! hash_equals((string) $gateway->config['secretHash'], $headers['verif-hash'] ?? '')) {
            throw new PaymentFailed(__('payments.signature'));
        }
        $event = json_decode($body, true);
        if (! is_array($event)) {
            return [];
        }
        $name = $event['event'] ?? null;
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        return match (true) {
            $name === 'charge.completed' => $this->completed($gateway, $data),
            is_string($name) && str_starts_with($name, 'chargeback.') => $this->chargeback($gateway, $data),
            $name === null && isset($event['id'], $event['TransactionId']) => $this->refundNow($gateway, $event['id']), // its refund event has no name
            default => [],
        };
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        return $this->ofTransaction($this->byReference($gateway, $payment));
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        $id = $this->byReference($gateway, $payment)['id'] ?? null;
        if ($id === null) {
            throw new PaymentFailed(__('payments.gateway_unclear'));
        }
        $refund = $this->earlierRefund($gateway, $id, $reference)
            ?? $this->call($gateway, fn (PendingRequest $h) => $h->asJson()->post($this->url('/v3/transactions/'.rawurlencode((string) $id).'/refund'), [
                'amount' => $amount,
                'comments' => $reference,
                'callbackurl' => app(Gateways::class)->notifyUrl($gateway),
            ]));
        if ($this->refundFailed($refund) || ! isset($refund['id'])) {
            throw new PaymentFailed(__('payments.gateway_refund_failed'));
        }

        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, (string) $id, (string) ($refund['amount_refunded'] ?? $amount), $payment->currency, (string) $refund['id']);
    }

    /** How a transaction stands: paid, failed, or null while it is still going. */
    private function ofTransaction(array $transaction): ?GatewayEvent
    {
        $kind = match ($transaction['status'] ?? null) {
            'successful' => GatewayEvent::PAID,
            'failed' => GatewayEvent::FAILED,
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $paid = $kind === GatewayEvent::PAID && isset($transaction['amount'], $transaction['currency']);

        return new GatewayEvent($kind, $transaction['tx_ref'] ?? null, isset($transaction['id']) ? (string) $transaction['id'] : null,
            $paid ? (string) $transaction['amount'] : null, $paid ? $transaction['currency'] : null);
    }

    /** @return list<GatewayEvent> a transaction of the kit's, as Flutterwave has it */
    private function completed(PaymentGateway $gateway, array $data): array
    {
        if (! isset($data['id']) || ! Str::isUuid((string) ($data['tx_ref'] ?? ''))) {
            return []; // not a payment of the kit's
        }

        return array_values(array_filter([$this->ofTransaction($this->byId($gateway, $data['id']))]));
    }

    /** @return list<GatewayEvent> a refund as Flutterwave has it, tied to the kit's payment by its transaction's `tx_ref` */
    private function refundNow(PaymentGateway $gateway, int|string $id): array
    {
        $refund = $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v3/refunds/'.rawurlencode((string) $id))));
        if (! isset($refund['id'], $refund['transaction_id'])) {
            return [];
        }
        $transaction = $this->byId($gateway, $refund['transaction_id']);

        return [new GatewayEvent($this->refundFailed($refund) ? GatewayEvent::REFUND_FAILED : GatewayEvent::REFUNDED, $transaction['tx_ref'] ?? null, null,
            isset($refund['amount_refunded']) ? (string) $refund['amount_refunded'] : null, $transaction['currency'] ?? null, (string) $refund['id'])];
    }

    /** @return list<GatewayEvent> a chargeback as Flutterwave has it: its event carries no `tx_ref`, and `declined` is the operator contesting it, still undecided */
    private function chargeback(PaymentGateway $gateway, array $data): array
    {
        if (! is_string($data['flw_ref'] ?? null)) {
            return [];
        }
        $found = $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v3/chargebacks'), ['flw_ref' => $data['flw_ref']]));
        $chargeback = collect($found)->firstWhere('id', $data['id'] ?? null);
        $kind = match ($chargeback['status'] ?? null) {
            'initiated', 'pending' => GatewayEvent::DISPUTE_OPENED,
            'accepted', 'lost' => GatewayEvent::DISPUTE_LOST,
            'won', 'reversed' => GatewayEvent::DISPUTE_WON,
            default => null,
        };
        if ($kind === null) {
            return [];
        }

        return [new GatewayEvent($kind, $chargeback['tx_ref'] ?? null, null, isset($chargeback['amount']) ? (string) $chargeback['amount'] : null, $chargeback['currency'] ?? null, (string) $chargeback['id'])];
    }

    /** Failed when it says so or, its status staying `completed`, when the money could not be sent on (its `meta`, a JSON string). */
    private function refundFailed(array $refund): bool
    {
        $meta = is_string($refund['meta'] ?? null) ? json_decode($refund['meta'], true) : ($refund['meta'] ?? null);

        return ($refund['status'] ?? null) === 'failed' || (is_array($meta) && ($meta['disburse_status'] ?? null) === 'failed');
    }

    /** A refund the kit already sent, its answer lost; Flutterwave's list answering with nothing or an error means none. */
    private function earlierRefund(PaymentGateway $gateway, int|string $transactionId, string $reference): ?array
    {
        try {
            return collect($this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v3/refunds'), ['id' => $transactionId])))->firstWhere('comment', $reference);
        } catch (PaymentFailed) {
            return null;
        }
    }

    private function byReference(PaymentGateway $gateway, Payment $payment): array
    {
        return $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v3/transactions/verify_by_reference'), ['tx_ref' => $payment->id]));
    }

    private function byId(PaymentGateway $gateway, int|string $id): array
    {
        return $this->call($gateway, fn (PendingRequest $h) => $h->get($this->url('/v3/transactions/'.rawurlencode((string) $id).'/verify')));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('kit.payments.flutterwave.api_url'), '/').$path;
    }

    /** One authenticated call, answering its `data` (the whole answer where it has none); a refusal or no answer is a PaymentFailed in Flutterwave's words. */
    private function call(PaymentGateway $gateway, \Closure $send): array
    {
        try {
            /** @var Response $r */
            $r = $send(Http::withToken((string) $gateway->config['secretKey'])->acceptJson()->timeout((int) config('kit.payments.flutterwave.timeout_seconds')));
        } catch (ConnectionException $e) {
            throw new PaymentFailed($e->getMessage(), 0, $e);
        }
        if ($r->failed() || $r->json('status') === 'error') {
            throw new PaymentFailed((string) ($r->json('message') ?? "HTTP {$r->status()}"));
        }
        $data = $r->json('data');

        return is_array($data) ? $data : ($r->json() ?? []);
    }
}
