<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One verified event a gateway sent (`payment_events`): what it said happened (`kind`, App\Kit\Payments\GatewayEvent),
 * the payment it was about when the kit knows it, and its body, encrypted (it can name the payer). Kept
 * config('kit.payments.events_keep_days'). Written by App\Kit\Payments\Payments.
 */
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted'];
    }
}
