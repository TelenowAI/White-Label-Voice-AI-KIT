<?php

namespace App\Console\Commands;

use App\Kit\Knowledge\Knowledge;
use App\Kit\Telenow\TelenowError;
use App\Models\KnowledgeBase;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * `php artisan kit:knowledge`: each base's document changes from Telenow (App\Kit\Knowledge\Knowledge::feed) — every run
 * for a base with a document still being read, else once config('kit.knowledge.feed_minutes') have passed, the
 * longest-waiting first, at most config('kit.knowledge.feed_bases_per_run') a run — and, for the accounts read, the bases
 * Telenow has (one gone there goes here). Scheduled every minute.
 */
final class RunKnowledge extends Command
{
    protected $signature = 'kit:knowledge';

    protected $description = "Keep customers' knowledge current";

    public function handle(Knowledge $knowledge): int
    {
        $working = config('kit.knowledge.statuses.working');
        // A closed customer's key is forgotten: its bases cannot be read, and left in they would hold the first places.
        $bases = KnowledgeBase::query()->with('workspace')->whereNotNull('telenow_id')->whereNull('deleted_at')
            ->whereHas('workspace', fn ($w) => $w->whereNotNull('telenow_key'))
            ->where(fn ($q) => $q->whereNull('documents_read_at')
                ->orWhere('documents_read_at', '<=', now()->subMinutes(config('kit.knowledge.feed_minutes')))
                ->orWhereHas('documents', fn ($d) => $d->whereNull('deleted_at')->whereIn('status', $working)))
            ->orderByRaw('documents_read_at IS NOT NULL')->orderBy('documents_read_at')
            ->limit(config('kit.knowledge.feed_bases_per_run'))->get();
        [$seen, $accounts] = [0, []];
        foreach ($bases as $base) {
            try {
                $seen += $knowledge->feed($base);
                $accounts[$base->workspace_id] = $base->workspace;
            } catch (TelenowError $e) {
                $this->warn("{$base->id}: {$e->getMessage()}");
            }
        }
        foreach ($accounts as $workspace) {
            try {
                $knowledge->bases($workspace);
            } catch (TelenowError $e) {
                $this->warn("{$workspace->id}: {$e->getMessage()}");
            }
        }
        $this->info("{$seen} document change(s) in {$bases->count()} base(s)");

        return self::SUCCESS;
    }
}
