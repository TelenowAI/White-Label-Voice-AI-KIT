<?php

namespace App\Kit\Payments;

/**
 * Where a payer goes to pay (PaymentDriver::checkout), in one of three shapes the console knows:
 * - `redirect`: open `url` (a hosted checkout page);
 * - `post`: send `fields` to `url` as a form (gateways that take the order that way);
 * - `instructions`: no gateway page — `instructions` say how to pay (a bank transfer), quoting `reference`.
 * `gatewayRef` is the gateway's own id for the payment, when it gives one now.
 */
final class Checkout
{
    /** @param  array<string, string>  $fields */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $url,
        public readonly array $fields,
        public readonly ?string $instructions,
        public readonly ?string $reference,
        public readonly ?string $gatewayRef,
    ) {}

    public static function redirect(string $url, ?string $gatewayRef): self
    {
        return new self('redirect', $url, [], null, null, $gatewayRef);
    }

    /** @param  array<string, string>  $fields */
    public static function post(string $url, array $fields, ?string $gatewayRef): self
    {
        return new self('post', $url, $fields, null, null, $gatewayRef);
    }

    public static function instructions(string $instructions, string $reference): self
    {
        return new self('instructions', null, [], $instructions, $reference, $reference);
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind, 'url' => $this->url, 'fields' => (object) $this->fields, 'instructions' => $this->instructions, 'reference' => $this->reference];
    }
}
