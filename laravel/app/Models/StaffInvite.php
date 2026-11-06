<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An invite to the operator's staff (the `staff_invites` table): the address, the role it gives, and its link's token
 * as a sha256 only. Written and read by App\Kit\Staff\Staff.
 */
final class StaffInvite extends Model
{
    use HasUuids;

    protected $fillable = ['email', 'role', 'token_hash', 'invited_by', 'expires_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** Not accepted, not revoked, not expired. */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }
}
