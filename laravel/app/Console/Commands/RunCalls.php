<?php

namespace App\Console\Commands;

use App\Kit\Calls\Calls;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * `php artisan kit:calls`: re-read the calls still waiting for parts (App\Kit\Calls\Calls::readDue), then read the calls
 * feed of the accounts not read for config('kit.calls.feed_minutes'), the longest-waiting first, up to
 * config('kit.calls.feed_accounts_per_run') — Telenow's rate limit is the whole install's (§5 C9). Scheduled every minute.
 */
final class RunCalls extends Command
{
    protected $signature = 'kit:calls';

    protected $description = "Keep the kit's copy of customers' calls current";

    public function handle(Calls $calls): int
    {
        $read = $calls->readDue();
        $seen = 0;
        $accounts = Workspace::query()->whereNotNull('telenow_sub_account_id')->whereNotNull('telenow_key')
            ->where(fn ($q) => $q->whereNull('calls_synced_at')->orWhere('calls_synced_at', '<=', now()->subMinutes(config('kit.calls.feed_minutes'))))
            ->orderByRaw('calls_synced_at IS NOT NULL')->orderBy('calls_synced_at')
            ->limit(config('kit.calls.feed_accounts_per_run'))->get();
        foreach ($accounts as $workspace) {
            try {
                $seen += $calls->feed($workspace);
            } catch (TelenowError $e) {
                $this->warn("{$workspace->id}: {$e->getMessage()}");
            }
        }
        $this->info("{$read} call(s) re-read, {$seen} listed in {$accounts->count()} account feed(s)");

        return self::SUCCESS;
    }
}
