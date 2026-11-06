<?php

namespace App\Kit\Payments\Drivers;

use App\Kit\Payments\Checkout;
use App\Kit\Payments\GatewayEvent;
use App\Kit\Payments\PaymentDriver;
use App\Kit\Payments\PaymentFailed;
use App\Kit\Setup\Field;
use App\Models\Payment;
use App\Models\PaymentGateway;

/**
 * The built-in way to pay with no gateway: a bank transfer, or anything else the operator writes down. The customer
 * is shown the operator's instructions and a short reference to quote; staff confirm in Admin → Payments when the
 * money arrives (with the amount that arrived), and a refund is recorded there once staff have sent the money back.
 * There is nothing to check, no webhook and no test mode.
 *
 * Not here: crediting the wallet when staff confirm (App\Kit\Payments\Payments::confirm).
 */
final class ManualDriver implements PaymentDriver
{
    /** References avoid letters and digits that read alike (0/O, 1/I). */
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function fields(array $config): array
    {
        return [new Field('instructions', __('payments.drivers.manual.fields.instructions'), 'textarea', true,
            $config['instructions'] ?? null, __('payments.drivers.manual.help.instructions'))];
    }

    public function rules(): array
    {
        return ['instructions' => ['required', 'string', 'max:'.config('kit.payments.instructions_max_length')]];
    }

    public function secrets(): array
    {
        return [];
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
        return false;
    }

    public function partialRefunds(): bool
    {
        return true;
    }

    public function webhookEvents(): array
    {
        return [];
    }

    public function checkAfterMinutes(): int
    {
        return 0; // never checked: staff confirm
    }

    public function verify(PaymentGateway $gateway): void
    {
        // Nothing to check: no keys.
    }

    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout
    {
        return Checkout::instructions((string) $gateway->config['instructions'], $this->reference($gateway));
    }

    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent
    {
        return null;
    }

    public function webhook(PaymentGateway $gateway, string $body, array $headers): array
    {
        throw new PaymentFailed('A bank transfer sends no events.');
    }

    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent
    {
        return null;
    }

    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent
    {
        return new GatewayEvent(GatewayEvent::REFUNDED, $payment->id, $payment->gateway_ref, $amount, $payment->currency, "manual:{$reference}");
    }

    /** A short reference no other payment through this gateway has. */
    private function reference(PaymentGateway $gateway): string
    {
        $length = (int) config('kit.payments.manual.reference_length');
        do {
            $reference = '';
            for ($i = 0; $i < $length; $i++) {
                $reference .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
        } while (Payment::query()->where('gateway_id', $gateway->id)->where('gateway_ref', $reference)->exists());

        return $reference;
    }
}
