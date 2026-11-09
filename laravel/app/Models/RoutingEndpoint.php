<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An account's routing endpoint at Telenow (the `routing_endpoints` table), written only by App\Kit\Routing\RoutingConnection: the token in its
 * address (which account a request is for), the secret Telenow signs requests with (encrypted; Telenow shows it only when it makes one) and the
 * address registered (what Telenow signs).
 */
final class RoutingEndpoint extends Model
{
    protected $primaryKey = 'workspace_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'registered_at' => 'datetime'];
    }
}
