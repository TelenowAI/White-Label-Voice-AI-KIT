<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One voice customers may hear and pick, copied from Telenow (App\Kit\Catalog\CatalogVoices, which writes these rows). Telenow's
 * ids stay on the server: a customer's answers name the provider by the kit's own key.
 */
final class CatalogVoice extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['tags' => 'array', 'recommended' => 'boolean'];
    }
}
