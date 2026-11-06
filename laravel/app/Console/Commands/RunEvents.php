<?php

namespace App\Console\Commands;

use App\Kit\Events\Endpoint;
use App\Kit\Events\Inbox;
use Illuminate\Console\Command;

/**
 * `php artisan kit:events`: keep the kit's events endpoint at Telenow alive (App\Kit\Events\Endpoint, every
 * config('kit.events.check_minutes')), apply the events that are due (Inbox — most are applied right after they
 * arrive), and forget old applied ones. Scheduled every minute (routes/console.php).
 */
final class RunEvents extends Command
{
    protected $signature = 'kit:events';

    protected $description = "Keep Telenow's events coming, and apply the ones that are due";

    public function handle(Endpoint $endpoint, Inbox $inbox): int
    {
        if ($endpoint->due()) {
            $why = $endpoint->ensure();
            $why === null ? $this->info('events endpoint ok') : $this->warn("events endpoint: {$why}");
        }
        $this->info("{$inbox->applyDue()} event(s) applied, {$inbox->prune()} old one(s) forgotten");

        return self::SUCCESS;
    }
}
