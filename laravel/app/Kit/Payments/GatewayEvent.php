<?php

namespace App\Kit\Payments;

/**
 * What a gateway says happened, in the kit's words (a driver's answer to a return, a webhook, a check or a refund):
 * - `paid`, `failed`, `pending` (still open), about the payment;
 * - `refunded`: `amount` of it went back (`ref` is the refund's own id);
 * - `refund_failed`: that refund did not reach the payer after all, and the money came back to the operator;
 * - `dispute_opened`, `dispute_won` (the operator kept the money), `dispute_lost` — `ref` is the dispute's own id.
 * `paymentId` is the kit's payment id the gateway carried back, `gatewayRef` the gateway's own id for the payment;
 * either finds it. Amounts are exact decimal strings in `currency`. `eventId` is the gateway's id for this event.
 */
final class GatewayEvent
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const PENDING = 'pending';

    public const REFUNDED = 'refunded';

    public const REFUND_FAILED = 'refund_failed';

    public const DISPUTE_OPENED = 'dispute_opened';

    public const DISPUTE_WON = 'dispute_won';

    public const DISPUTE_LOST = 'dispute_lost';

    public function __construct(
        public readonly string $kind,
        public readonly ?string $paymentId,
        public readonly ?string $gatewayRef,
        public readonly ?string $amount = null,
        public readonly ?string $currency = null,
        public readonly ?string $ref = null,
        public readonly ?string $eventId = null,
    ) {}
}
