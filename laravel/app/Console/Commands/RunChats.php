<?php

namespace App\Console\Commands;

use App\Kit\Chat\Conversations;
use Illuminate\Console\Command;

/**
 * `php artisan kit:chats`: end the web chat conversations nobody has written in for config('kit.chat.idle_minutes'), and those of a chat box
 * that no longer answers, and tell the voice platform of each until it has it ended (App\Kit\Chat\Conversations::endDue) — an open one holds
 * one of the account's calls-at-once places there. Scheduled every minute.
 */
final class RunChats extends Command
{
    protected $signature = 'kit:chats';

    protected $description = 'End web chat conversations that are over';

    public function handle(Conversations $conversations): int
    {
        $this->info("{$conversations->endDue()} conversation(s) ended at the voice platform");

        return self::SUCCESS;
    }
}
