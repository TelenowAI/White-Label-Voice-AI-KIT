<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One top-up a customer started, or a staff member's test payment (no workspace) — `payments`. Its states:
 * - `open`: waiting for the payer, the gateway, or (a bank transfer) staff;
 * - `paid`: the money arrived and, for a customer, its wallet was credited once;
 * - `review`: the gateway says it was paid, but not the amount or currency asked for — nothing credited until staff
 *   confirm it;
 * - `failed`, `canceled`, `expired`: nothing arrived (a verified payment arriving later still credits).
 * `refunded` and `dispute` say what happened after. `details` keeps each refund and dispute by the gateway's own id,
 * so each counts once, and a bank transfer's instructions. Written by App\Kit\Payments\Payments.
 */
class Payment extends Model
{
    use HasUuids;

    public const OPEN = 'open';

    public const PAID = 'paid';

    public const REVIEW = 'review';

    public const FAILED = 'failed';

    public const CANCELED = 'canceled';

    public const EXPIRED = 'expired';

    protected function casts(): array
    {
        return ['details' => 'array', 'expires_at' => 'datetime', 'checked_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** What the payer gave for this payment alone (kept only until its checkout has begun), else what their account has. */
    public function payer(string $field): ?string
    {
        $value = $this->details['payer'][$field] ?? $this->user?->{$field};

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A staff member's test of a gateway: no customer, nothing credited. */
    public function isTest(): bool
    {
        return $this->workspace_id === null;
    }
}
