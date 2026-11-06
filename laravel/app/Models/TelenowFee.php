<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fee Telenow charged for one of a customer's numbers (its ledger entry: rental, number_setup), and whether the kit
 * has charged it to the customer's wallet (App\Kit\Wallet\FeeCharges). Keyed by Telenow's ledger entry id.
 */
final class TelenowFee extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['entry' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
