<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One one-time code emailed to sign a customer in (`email_codes`). Only its hash is kept; it works once, until it expires
 * or runs out of tries. Written by App\Kit\Auth\EmailCodes.
 */
class EmailCode extends Model
{
    protected $fillable = ['user_id', 'email', 'code_hash', 'expires_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Not used, not expired, tries left. */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now())->where('attempts', '<', config('kit.signup.email_code.attempts'));
    }
}
