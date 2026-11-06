<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's account at one of the operator's OpenID Connect providers (`user_identities`): the provider's id in the
 * kit and the provider's `sub` for the person. Written by App\Kit\Oidc\OidcSignIn.
 */
class UserIdentity extends Model
{
    protected $fillable = ['user_id', 'provider_id', 'subject', 'email'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
