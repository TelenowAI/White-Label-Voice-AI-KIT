<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\ProviderSettings;
use App\Kit\Catalog\RealtimeCatalog;
use App\Kit\Manifest\Manifest;
use Illuminate\Http\JsonResponse;

/**
 * Console → an agent's stack choices (contract/kit-api.yaml getStackChoices): the language models, speech recognition and
 * voice providers the operator offers, under the kit's own keys and the operator's names, and the new-agent defaults among
 * them, and each one's own settings customers may change (App\Kit\Catalog\ProviderSettings). Never Telenow's ids, names or prices. The voices themselves: VoicesController.
 */
final class CatalogController
{
    public function __construct(private readonly Catalog $catalog, private readonly Manifest $manifest, private readonly ProviderSettings $settings, private readonly RealtimeCatalog $realtime) {}

    public function show(): JsonResponse
    {
        $out = [];
        foreach (config('kit.catalog.kinds') as $kind => $parts) {
            $out[$kind] = array_map(fn (array $p) => ['key' => $p['key'], 'name' => $p['displayName'], 'settings' => $this->settings->forCustomers($p['settings'])]
                + (isset($parts['model']) ? ['models' => array_values(array_map(fn (array $m) => ['id' => $m['id'], 'name' => $m['displayName']],
                    array_filter($p['curatedModels'], fn (array $m) => $m['offered'])))] : []),
                $this->catalog->offered($kind));
        }
        // Realtime voices customers may pick (none while the platform runs none, App\Kit\Catalog\RealtimeCatalog).
        $out['realtime'] = $this->realtime->forCustomers();
        $defaults = $this->catalog->defaults();
        $out['defaults'] = [];
        foreach (config('kit.catalog.kinds') as $kind => $parts) {
            foreach ($parts as $part => $column) {
                $value = $defaults[$column];
                $out['defaults'][$this->manifest->writeName(AgentWrite::RESOURCE, $column)] = $part === 'provider' && $value !== null
                    ? Catalog::publicKey($kind, $value) : $value;
            }
        }

        return response()->json($out);
    }
}
