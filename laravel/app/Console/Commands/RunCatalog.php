<?php

namespace App\Console\Commands;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\CatalogRefused;
use Illuminate\Console\Command;

/**
 * `php artisan kit:catalog`: copy Telenow's catalog and voice lists again once the copy is older than
 * config('kit.catalog.refresh_hours'), there is none, or a list could not be read last time, with the operator's key
 * (App\Kit\Catalog\Catalog::refresh). Scheduled every hour; Admin → Catalog refreshes at once. Nothing to do before the
 * operator's key is set.
 */
final class RunCatalog extends Command
{
    protected $signature = 'kit:catalog';

    protected $description = "Keep the kit's copy of the voice platform's catalog current";

    public function handle(Catalog $catalog): int
    {
        if ($catalog->current()) {
            $this->info('The catalog copy is current');

            return self::SUCCESS;
        }
        try {
            $copy = $catalog->refresh();
        } catch (CatalogRefused $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }
        $this->info('Catalog copied: '.implode(', ', array_map(fn (string $k) => count($copy['kinds'][$k]).' '.$k, array_keys($copy['kinds']))));

        return self::SUCCESS;
    }
}
