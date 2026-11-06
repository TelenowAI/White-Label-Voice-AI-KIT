<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of a customer's calls, as the kit keeps it (the `calls` table): Telenow's call, its fields mapped by the manifest
 * (contract/resources.yaml `calls`), written only by App\Kit\Calls (and, for a call placed from the browser, App\Kit\Softphone first).
 * `telenow_id` is Telenow's call id; it never leaves the kit's backend. Its two numbers are kept as digits too, on every save, so one person's
 * calls are found by number however each was written.
 */
final class Call extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        self::saving(function (Call $call) {
            $call->from_digits = self::digits($call->from_number);
            $call->to_digits = self::digits($call->to_number);
        });
    }

    /** A number as the kit looks it up: its digits only, or null for none. */
    public static function digits(?string $number): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $number);

        return $digits === '' ? null : $digits;
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'next_read_at' => 'datetime',
            'after_call_emailed_at' => 'datetime',
            'wrapup_at' => 'datetime',
            'duration_sec' => 'integer',
            'transcript' => 'array',
            'recordings' => 'array',
            'analysis' => 'array',
            'completeness' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** The member who placed it from the browser; none for an agent's call. */
    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    /** The member who asked an agent to make this call; none for any other. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function wrapupAuthor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'wrapup_by');
    }
}
