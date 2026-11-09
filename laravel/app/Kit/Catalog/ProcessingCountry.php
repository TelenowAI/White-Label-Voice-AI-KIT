<?php

namespace App\Kit\Catalog;

use App\Kit\Settings\Settings;
use App\Kit\Telenow\TelenowClient;
use App\Kit\Telenow\TelenowError;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * The processing country of the operator's account and of a customer's (contract/kit-api.yaml getPlatformProcessingCountry,
 * getCustomerProcessingCountry; owner's choice 11-09: Admin only). Telenow serves an agent's language model from a region that holds this country
 * when the agent leaves the region to it (`getProcessingCountry`): the account's declared country, else its sign-up time zone's. A customer's account
 * copies the operator's when it is made and keeps it after the operator's changes (recorded 11-08), so customers made at different times may differ.
 *
 * - operator() / of(): `{country, source, checkedAt}` — country two letters or null; source `country` (the account's), `time_zone` (its time
 *   zone's), `unknown` (neither, or a source Telenow names that the kit does not know) — read from Telenow at most every
 *   config('kit.catalog.processing_country_minutes') unless asked afresh.
 *
 * Not here: changing it (Telenow has no call for it; the operator's country is Telenow's account setting), the pages (Admin\ProcessingCountryController).
 */
final class ProcessingCountry
{
    private const SOURCES = ['OrgCountry' => 'country', 'OrgTimezone' => 'time_zone'];

    public function __construct(private readonly Settings $settings, private readonly TelenowClient $telenow) {}

    /** The operator's. */
    public function operator(bool $fresh = false): array
    {
        $key = $this->settings->get('telenow.api_key');
        if (! is_string($key) || $key === '') {
            throw new CatalogRefused(409, __('catalog.no_key'));
        }

        return $this->read('kit.processing-country.operator', $key, $fresh);
    }

    /** A customer's, read with their own key. */
    public function of(Workspace $workspace, bool $fresh = false): array
    {
        if (! is_string($workspace->telenow_key) || $workspace->telenow_key === '') {
            throw new CatalogRefused(409, __('catalog.processing_country_no_account'));
        }

        return $this->read("kit.processing-country.{$workspace->id}", $workspace->telenow_key, $fresh);
    }

    private function read(string $cache, string $key, bool $fresh): array
    {
        $kept = $fresh ? null : Cache::get($cache);
        if (is_array($kept)) {
            return $kept;
        }
        try {
            $answer = $this->telenow->processingCountry($key);
        } catch (TelenowError) {
            throw new CatalogRefused(502, __('catalog.processing_country_unread'));
        }
        $country = is_string($answer['country'] ?? null) && preg_match('/^[A-Z]{2}$/', $answer['country']) === 1 ? $answer['country'] : null;
        $read = ['country' => $country, 'source' => $country !== null && is_string($answer['source'] ?? null) ? self::SOURCES[$answer['source']] ?? 'unknown' : 'unknown', 'checkedAt' => now()->toIso8601String()];
        Cache::put($cache, $read, now()->addMinutes((int) config('kit.catalog.processing_country_minutes')));

        return $read;
    }
}
