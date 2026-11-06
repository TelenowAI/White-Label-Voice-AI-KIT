<?php

namespace App\Console\Commands;

use App\Kit\Console\ServicePause;
use Illuminate\Console\Command;

/**
 * `php artisan kit:telenow-status`: read from Telenow whether it lets the operator's account spend and accepts its key
 * (App\Kit\Console\ServicePause::refresh). Scheduled every config('kit.telenow.status_minutes') (routes/console.php).
 */
final class RefreshTelenowStatus extends Command
{
    protected $signature = 'kit:telenow-status';

    protected $description = "Read whether Telenow lets the operator's account spend";

    public function handle(ServicePause $pause): int
    {
        $pause->refresh();

        return self::SUCCESS;
    }
}
