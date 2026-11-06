<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One sign-up (`signups`), held until its address is verified and, in approval mode, staff approve it; then its user
 * and customer account are made. A sign-up has an email, or a phone instead (confirmed by a texted code); one made with
 * a sign-in provider carries the provider's id and the person's subject there. Its link's token or its code is kept
 * only as a hash; its password is already hashed. Written by App\Kit\Signup\Signups; listed by Admin → Sign-ups.
 */
class Signup extends Model
{
    use HasUuids;

    public const UNVERIFIED = 'unverified';

    public const WAITING = 'waiting';

    public const DONE = 'done';

    public const REJECTED = 'rejected';

    protected $hidden = ['password', 'token_hash'];

    protected function casts(): array
    {
        return [
            'answers' => 'array', 'terms_accepted_at' => 'datetime', 'expires_at' => 'datetime', 'verified_at' => 'datetime',
            'decided_at' => 'datetime', 'code_attempts' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
