<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's place in a customer account (`workspace_members`): the workspace, the user, the role (config('kit.team.roles')). */
class WorkspaceMember extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'role'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
