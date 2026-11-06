<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer account: one Telenow sub-account (the `workspaces` table). The kit writes this row first and keeps
 * Telenow in step through the outbox (App\Kit\Outbox); `sync_state` says how far that got. The customer's Telenow key
 * is an encrypted attribute that is never serialized.
 */
class Workspace extends Model
{
    use HasUuids;

    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    /** Closing asked for: its numbers are being released, then Telenow closes the sub-account (App\Kit\Customers). */
    public const CLOSING = 'closing';

    /** Closed at Telenow for good. */
    public const CLOSED = 'closed';

    /** Closing or closed: nothing is done for it any more but reading what it was. */
    public const ENDED = [self::CLOSING, self::CLOSED];

    public const SYNC_PENDING = 'pending';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_FAILED = 'failed';

    protected $fillable = ['name', 'status', 'sync_state'];

    protected $hidden = ['telenow_key'];

    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    protected function casts(): array
    {
        return [
            'consent_required' => 'boolean',
            'consent_locked' => 'boolean',
            'spend_limit_at' => 'datetime',
            'close_requested_at' => 'datetime',
            'closed_at' => 'datetime',
            'low_balance_warned_at' => 'datetime',
            'out_of_credit_warned_at' => 'datetime',
            'telenow_key' => 'encrypted',
            'telenow_snapshot' => 'array',
            'synced_at' => 'datetime',
        ];
    }
}
