<?php

namespace App\Kit\Payments;

use App\Kit\Setup\Field;
use App\Models\Payment;
use App\Models\PaymentGateway;

/**
 * One payment gateway (SELF_HOSTED_KIT_PLAN.md §4.6 a, the driver contract): it declares its own form, so Admin →
 * Payments and the wizard's Payments step draw it with no change, and it talks to its gateway with the config the
 * operator saved. Drivers are listed in config('kit.payments.drivers'); their texts live in
 * lang/<locale>/payments.php under `drivers.<id>`.
 *
 * Every answer about money comes from the gateway itself: a return only makes the driver ask the gateway, and a
 * webhook counts only once its signature checks out. A driver throws PaymentFailed, in the gateway's words (for
 * staff, never shown to a customer).
 *
 * Not here: the money rules — crediting once, review, refunds, disputes (Payments); which gateways a customer is
 * offered (Gateways).
 */
interface PaymentDriver
{
    /**
     * Its form, the fields named without a prefix. A secret's value is never filled in; `secretSet` says one is saved.
     *
     * @return list<Field>
     */
    public function fields(array $config): array;

    /** @return array<string, list<mixed>> Laravel validation rules for its fields */
    public function rules(): array;

    /** @return list<string> its secret fields: left empty on an edit, the saved value is kept */
    public function secrets(): array;

    /** @return list<string>|null the currencies it takes (ISO 4217 codes); null: any */
    public function currencies(): ?array;

    /** @return list<string> the payment methods its checkout can show (ids; their words in lang payments.methods) */
    public function methods(): array;

    /**
     * What it needs of the payer that a person may not have given the kit: `email`. The page asks for what is missing, for
     * that one payment (Payment::payer).
     *
     * @return list<string>
     */
    public function payerNeeds(): array;

    /**
     * Whether payers pay at the gateway (a checkout, events, a test mode) — or, false, staff confirm that the money
     * arrived (a bank transfer).
     */
    public function online(): bool;

    /** Whether it can refund part of a payment (every driver refunds the whole). */
    public function partialRefunds(): bool;

    /** @return list<string> the gateway's own names of the events to tick when registering the webhook */
    public function webhookEvents(): array;

    /** Minutes after which a payment still open is checked with the gateway (kit:payments). Online drivers only. */
    public function checkAfterMinutes(): int;

    /** One harmless authenticated call: are these keys good? Throws PaymentFailed. */
    public function verify(PaymentGateway $gateway): void;

    /** Start paying `$payment`: where the payer goes (or what they do). `$notifyUrl` is this gateway's webhook. */
    public function checkout(PaymentGateway $gateway, Payment $payment, string $returnUrl, string $notifyUrl): Checkout;

    /**
     * The payer is back from the gateway: ask the gateway how the payment went — never trust what the return itself
     * says (`$input` is only where to look). Null: the gateway does not know yet.
     */
    public function returned(PaymentGateway $gateway, Payment $payment, array $input): ?GatewayEvent;

    /**
     * A webhook's events, once its signature checks out — PaymentFailed when it does not.
     *
     * @param  array<string, string>  $headers  lower-cased names
     * @return list<GatewayEvent>
     */
    public function webhook(PaymentGateway $gateway, string $body, array $headers): array;

    /** How the payment stands at the gateway now (kit:payments). Null: not known yet. */
    public function fetch(PaymentGateway $gateway, Payment $payment): ?GatewayEvent;

    /** Refund `$amount` of a paid payment; `$reference` makes asking twice refund once. Answers the refund. */
    public function refund(PaymentGateway $gateway, Payment $payment, string $amount, string $reference): GatewayEvent;
}
