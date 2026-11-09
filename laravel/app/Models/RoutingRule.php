<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of an account's rules for who takes a call to a routed number (the `routing_rules` table), written only by App\Kit\Routing\RoutingRules and
 * read in order by App\Kit\Routing\RoutingMatch. `agent_id` is the kit's agent (null: the number's own); a list left empty matches any call.
 */
final class RoutingRule extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'position' => 'integer',
            'numbers' => 'array',
            'forwarded_from' => 'array',
            'caller_starts' => 'array',
            'days' => 'array',
            'max_minutes' => 'integer',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
