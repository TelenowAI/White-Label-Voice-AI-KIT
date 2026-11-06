<?php

namespace App\Console\Commands;

use App\Kit\Email\Emails;
use App\Models\OutgoingEmail;
use Illuminate\Console\Command;

/**
 * `php artisan kit:emails`: try again each email no sender could send when it was made, once its wait
 * (config('kit.email.retry_minutes')) is over — the longest-waiting first, at most config('kit.email.per_run') a run
 * (App\Kit\Email\Emails::retry: sent, waiting again, or given up). Scheduled every minute.
 */
final class RunEmails extends Command
{
    protected $signature = 'kit:emails';

    protected $description = 'Send the emails that could not be sent at once';

    public function handle(Emails $emails): int
    {
        $due = OutgoingEmail::query()->where('status', 'queued')->whereNotNull('next_attempt_at')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit(config('kit.email.per_run'))->get();
        foreach ($due as $email) {
            $emails->retry($email);
        }
        $this->info("{$due->where('status', 'sent')->count()} of {$due->count()} email(s) sent");

        return self::SUCCESS;
    }
}
