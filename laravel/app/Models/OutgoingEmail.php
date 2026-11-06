<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One email the kit sends (`emails`): queued until a sender takes it, then sent, or failed once every retry is used
 * (config('kit.email.retry_minutes')). The body is encrypted (it may hold a link's secret) and wiped once the email is
 * sent or given up. Written by App\Kit\Email\Emails; listed by Admin → Email.
 */
class OutgoingEmail extends Model
{
    use HasUuids;

    protected $table = 'emails';

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return ['body' => 'encrypted:array', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
