<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One text the kit sent or tried (`sms_messages`): its kind, the number, whether a sender took it, how many senders were
 * tried and why they failed — never its words, which can hold a one-time code. Written by App\Kit\Sms\Sms; listed by
 * Admin → SMS.
 */
class OutgoingSms extends Model
{
    use HasUuids;

    protected $table = 'sms_messages';

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }
}
