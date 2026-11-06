<?php

namespace App\Console\Commands;

use App\Kit\Campaigns\Campaigns;
use App\Kit\Telenow\TelenowError;
use App\Models\Campaign;
use Illuminate\Console\Command;

/**
 * `php artisan kit:campaigns`: Telenow sends no campaign events (T2.15), so each campaign still in play
 * (config('kit.campaigns.active_statuses')) — and one that finished with people whose results the kit has not had yet —
 * is read again once config('kit.campaigns.read_minutes') have passed, the longest-waiting first, at most
 * config('kit.campaigns.per_run') a run (App\Kit\Campaigns\Campaigns::refresh: its status and counts, and the results
 * feed). Scheduled every minute.
 */
final class RunCampaigns extends Command
{
    protected $signature = 'kit:campaigns';

    protected $description = "Keep customers' campaigns and their results current";

    public function handle(Campaigns $campaigns): int
    {
        // A closed customer's key is forgotten: its campaigns cannot be read, and left in they would hold the first places.
        $due = Campaign::query()->with('workspace')->whereNotNull('telenow_id')->whereNull('deleted_at')
            ->whereHas('workspace', fn ($w) => $w->whereNotNull('telenow_key'))
            ->where(fn ($q) => $q->whereIn('status', config('kit.campaigns.active_statuses'))
                ->orWhereHas('targets', fn ($t) => $t->whereNotNull('pushed_at')->where(fn ($w) => $w->whereNull('completed_at')->orWhereNotNull('retried_at'))))
            ->where(fn ($q) => $q->whereNull('read_at')->orWhere('read_at', '<=', now()->subMinutes(config('kit.campaigns.read_minutes'))))
            ->orderByRaw('read_at IS NOT NULL')->orderBy('read_at')->limit(config('kit.campaigns.per_run'))->get();
        $seen = 0;
        foreach ($due as $campaign) {
            try {
                $seen += $campaigns->refresh($campaign);
            } catch (TelenowError $e) {
                $this->warn("{$campaign->id}: {$e->getMessage()}");
            }
        }
        $this->info("{$seen} result(s) in {$due->count()} campaign(s)");

        return self::SUCCESS;
    }
}
