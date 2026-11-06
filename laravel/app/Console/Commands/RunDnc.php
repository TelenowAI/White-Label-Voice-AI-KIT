<?php

namespace App\Console\Commands;

use App\Kit\Dnc\Dnc;
use App\Kit\Dnc\DncRefused;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * `php artisan kit:dnc`: read the DNC feed (App\Kit\Dnc\Dnc::feed) of the accounts not read for
 * config('kit.dnc.feed_minutes'), the longest-waiting first, up to config('kit.dnc.feed_accounts_per_run') a run —
 * Telenow's own entries (a caller's opt-out) and removals reach the kit this way. Scheduled every minute.
 */
final class RunDnc extends Command
{
    protected $signature = 'kit:dnc';

    protected $description = "Keep the kit's copy of customers' do-not-call lists current";

    public function handle(Dnc $dnc): int
    {
        $seen = 0;
        $accounts = Workspace::query()->whereNotNull('telenow_sub_account_id')->whereNotNull('telenow_key')
            ->where(fn ($q) => $q->whereNull('dnc_synced_at')->orWhere('dnc_synced_at', '<=', now()->subMinutes(config('kit.dnc.feed_minutes'))))
            ->orderByRaw('dnc_synced_at IS NOT NULL')->orderBy('dnc_synced_at')
            ->limit(config('kit.dnc.feed_accounts_per_run'))->get();
        foreach ($accounts as $workspace) {
            try {
                $seen += $dnc->feed($workspace);
            } catch (TelenowError|DncRefused $e) {
                $this->warn("{$workspace->id}: {$e->getMessage()}");
            }
        }
        $this->info("{$seen} change(s) in {$accounts->count()} account feed(s)");

        return self::SUCCESS;
    }
}
