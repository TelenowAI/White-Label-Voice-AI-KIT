<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One one-time code texted to a number (`phone_codes`): for signing a customer in, or for proving a number a person is
 * adding. Only its hash is kept; it works once, until it expires or runs out of tries. Written by
 * App\Kit\Auth\PhoneCodes.
 */
class PhoneCode extends Model
{
    public const SIGN_IN = 'sign_in';

    public const VERIFY = 'verify';

    protected $fillable = ['user_id', 'purpose', 'phone', 'code_hash', 'expires_at'];

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
        $query->whereNull('used_at')->where('expires_at', '>', now())->where('attempts', '<', config('kit.phone.code_attempts'));
    }
}
