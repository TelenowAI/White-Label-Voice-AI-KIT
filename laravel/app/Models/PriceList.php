<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One of the operator's price lists (the `price_lists` table): its `definition` in the pricing spec's format
 * (contract/pricing/price-list.schema.json). Written only by App\Kit\Pricing\Pricing, which checks it first.
 */
final class PriceList extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'name', 'definition'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }
}
