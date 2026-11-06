<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One credit or charge in a customer's wallet (the `wallet_entries` table). Written only by App\Kit\Wallet\Wallet.
 * A charge's `details` keep what Telenow charged the operator beside what the customer paid — for the admin only.
 * A payment's entries (App\Kit\Payments\Payments) keep its id in `details.payment`: its top-up (a credit), a
 * `refund`, a disputed amount held (`hold`) and given back (`release`).
 */
final class WalletEntry extends Model
{
    public const CREDIT = 'credit';

    public const CHARGE = 'charge';

    public const REFUND = 'refund';

    public const HOLD = 'hold';

    public const RELEASE = 'release';

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
