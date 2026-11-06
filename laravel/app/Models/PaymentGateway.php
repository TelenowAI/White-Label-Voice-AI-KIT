<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One way to pay the operator switched on (`payment_gateways`): a driver (config('kit.payments.drivers')), its config
 * — keys and secrets, encrypted — its mode (`test` or `live`), what customers see (its name, the payment methods
 * shown), who it is offered to (plans; null: everyone) and its place in the order. A removed one is kept, out of
 * every list, so a refund or dispute it sends later still lands. Written by App\Kit\Payments\Gateways.
 */
class PaymentGateway extends Model
{
    use HasUuids;

    public const TEST = 'test';

    public const LIVE = 'live';

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array', 'methods' => 'array', 'plans' => 'array', 'enabled' => 'boolean', 'position' => 'integer',
            'last_event_at' => 'datetime', 'removed_at' => 'datetime',
        ];
    }

    /** Those not removed, in the order customers see them. */
    public function scopeListed(Builder $query): void
    {
        $query->whereNull('removed_at')->orderBy('position');
    }
}
