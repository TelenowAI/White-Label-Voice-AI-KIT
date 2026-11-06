<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link emailed to sign a customer in (`sign_in_links`, the magic-link method): only its token's hash is kept; it is
 * pending until it is used or past `expires_at`. Written and read by App\Kit\Signup\SignInLinks.
 */
class SignInLink extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): void
    {
        $query->whereNull('used_at')->where('expires_at', '>', now());
    }
}
