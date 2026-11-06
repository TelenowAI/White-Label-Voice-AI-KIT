<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry of the audit log (the `audit_events` table): who (`user_id`, null for the kit itself), what (`action`),
 * to what (`subject_type`, `subject_id`) and the details. Written by App\Kit\Audit\Audit only; never changed.
 */
final class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'action', 'subject_type', 'subject_id', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
