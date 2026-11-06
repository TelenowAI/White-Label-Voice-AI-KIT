<?php

namespace App\Console\Commands;

use App\Kit\Auth\EmailCodes;
use App\Kit\Signup\Signups;
use Illuminate\Console\Command;

/**
 * `php artisan kit:signups`: delete sign-ups nobody verified, config('kit.signup.purge_days') after their link expired
 * (App\Kit\Signup\Signups::purge), so an abandoned form does not keep its answers, and emailed sign-in codes long run out
 * (App\Kit\Auth\EmailCodes::purge). Scheduled daily.
 */
final class PurgeSignups extends Command
{
    protected $signature = 'kit:signups';

    protected $description = 'Delete sign-ups nobody verified';

    public function handle(Signups $signups, EmailCodes $codes): int
    {
        $this->info("{$signups->purge()} unverified sign-up(s) deleted, {$codes->purge()} old sign-in code(s)");

        return self::SUCCESS;
    }
}
