<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Agents\AgentSettings;
use App\Kit\Agents\AgentTransfers;
use App\Kit\Agents\Telenow\AgentWrite;
use App\Kit\Audit\Audit;
use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\CatalogRefused;
use App\Kit\Catalog\CatalogVoices;
use App\Kit\Catalog\RealtimeCatalog;
use App\Kit\Consent\ConsentRule;
use App\Kit\Manifest\Manifest;
use App\Kit\Settings\Settings;
use App\Kit\Campaigns\CampaignSources;
use App\Kit\Chat\WebChats;
use App\Kit\Softphone\BrowserCalls;
use App\Kit\Voices\Cloning;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Catalog (contract/kit-api.yaml getCatalogCuration, saveCatalogCuration, refreshCatalog, listCatalogVoices): the
 * kit's copy of Telenow's catalog with the operator's choices — which providers (and language models) customers may pick,
 * under which names, and the new-agent defaults beside Telenow's own recommendation — saving them, refreshing the copy now,
 * and a page of one provider's voices to choose the default among (offered yet or not). Whether customers may set up transfers
 * is saved here too, as what customers may use (App\Kit\Agents\AgentTransfers), and so is whether they may place calls from the browser (App\Kit\Softphone\BrowserCalls, with the
 * price lists that have no price for one), whether every customer's calls need consent on file (App\Kit\Consent\ConsentRule), and whether customers may clone voices of their own
 * and how many clones an account may have (App\Kit\Voices\Cloning), and which realtime voices customers may pick (App\Kit\Catalog\RealtimeCatalog).
 * The operator's page: Telenow's ids and names appear here, and never on a customer's.
 */
final class CatalogController
{
    public function __construct(private readonly Catalog $catalog, private readonly CatalogVoices $voices, private readonly Settings $settings, private readonly Manifest $manifest, private readonly AgentSettings $agentSettings, private readonly AgentTransfers $transfers, private readonly Audit $audit, private readonly ConsentRule $consent, private readonly BrowserCalls $browserCalls, private readonly WebChats $webChats, private readonly Cloning $cloning, private readonly RealtimeCatalog $realtime, private readonly CampaignSources $campaignLinks) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function save(Request $request): JsonResponse
    {
        $request->validate(['kinds' => ['present', 'array'], 'defaults' => ['present', 'array'], 'transferAllowed' => ['sometimes', 'boolean'], 'browserCallsAllowed' => ['sometimes', 'boolean'], 'webChatAllowed' => ['sometimes', 'boolean'], 'campaignLinksAllowed' => ['sometimes', 'boolean'], 'consentRequired' => ['sometimes', 'boolean'], 'cloningAllowed' => ['sometimes', 'boolean'], 'clonesPerAccount' => ['sometimes', 'integer', 'min:1', 'max:'.Cloning::most()], 'realtime' => ['sometimes', 'array']]);

        return $this->refusals(function () use ($request) {
            // Realtime voices are checked first, so a refusal leaves everything as it was (the save is all or nothing).
            $realtime = $request->has('realtime') ? $this->realtime->checkedChoices($request->input('realtime')) : null;
            $this->catalog->save($request->input('kinds'), $request->input('defaults'));
            if ($realtime !== null) {
                $this->realtime->saveChoices($realtime);
            }
            if ($request->has('transferAllowed')) {
                $allowed = $request->boolean('transferAllowed');
                if ($allowed !== $this->agentSettings->transferAllowed()) {
                    $this->audit->record($request->user(), $allowed ? 'agents.transfers_allowed' : 'agents.transfers_stopped');
                }
                $this->transfers->save($allowed);
            }
            if ($request->has('browserCallsAllowed')) {
                $allowed = $request->boolean('browserCallsAllowed');
                if ($allowed !== $this->browserCalls->allowed()) {
                    $this->audit->record($request->user(), $allowed ? 'softphone.allowed' : 'softphone.stopped');
                }
                $this->browserCalls->allow($allowed);
            }
            if ($request->has('webChatAllowed')) {
                $allowed = $request->boolean('webChatAllowed');
                if ($allowed !== $this->webChats->allowed()) {
                    $this->audit->record($request->user(), $allowed ? 'chat.allowed' : 'chat.stopped');
                }
                $this->webChats->allow($allowed);
            }
            if ($request->has('campaignLinksAllowed')) {
                $allowed = $request->boolean('campaignLinksAllowed');
                if ($allowed !== $this->campaignLinks->allowed()) {
                    $this->audit->record($request->user(), $allowed ? 'campaign_links.allowed' : 'campaign_links.stopped');
                }
                $this->campaignLinks->allow($allowed);
            }
            if ($request->has('consentRequired')) {
                $required = $request->boolean('consentRequired');
                if ($required !== $this->consent->requiredForEveryone()) {
                    $this->audit->record($request->user(), $required ? 'consent.required_for_all' : 'consent.left_to_customers');
                }
                $this->consent->saveForEveryone($required);
            }
            if ($request->has('cloningAllowed')) {
                $allowed = $request->boolean('cloningAllowed');
                if ($allowed !== $this->cloning->allowed()) {
                    $this->audit->record($request->user(), $allowed ? 'own_voices.allowed' : 'own_voices.stopped');
                }
                $this->cloning->allow($allowed);
            }
            if ($request->has('clonesPerAccount')) {
                $clones = (int) $request->input('clonesPerAccount');
                if ($clones !== $this->cloning->perAccount()) {
                    $this->audit->record($request->user(), 'own_voices.limit_set', null, null, ['clonesPerAccount' => $clones]);
                }
                $this->cloning->setPerAccount($clones);
            }

            return response()->json($this->payload());
        });
    }

    public function refresh(): JsonResponse
    {
        return $this->refusals(function () {
            $this->catalog->refresh();

            return response()->json($this->payload());
        });
    }

    public function voices(Request $request): JsonResponse
    {
        $q = $request->validate(['provider' => ['required', 'string']] + CatalogVoices::FILTERS);
        $providers = array_values(array_filter($this->catalog->providers('tts'), fn (array $p) => $p['id'] === $q['provider']));

        return response()->json($this->voices->page($providers, $q, (int) ($q['page'] ?? 1), config('kit.catalog.voices_per_page')));
    }

    private function refusals(\Closure $act): JsonResponse
    {
        try {
            return $act();
        } catch (CatalogRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    private function payload(): array
    {
        $copy = $this->catalog->copy();
        $kinds = [];
        $counts = $this->voices->counts();
        foreach (array_keys(config('kit.catalog.kinds')) as $kind) {
            $kinds[$kind] = array_map(fn (array $p) => [
                'id' => $p['id'],
                'catalogName' => $p['name'] ?? $p['id'],
                'name' => $p['displayName'],
                'ownName' => $p['ownName'],
                'namesPlatform' => $p['namesPlatform'],
                'offered' => $p['offered'],
                'enabled' => ($p['enabled'] ?? true) !== false,
                'status' => $p['status'] ?? null,
                'recommended' => (bool) ($p['recommended'] ?? false),
                'models' => array_map(fn (array $m) => [
                    'id' => $m['id'], 'catalogName' => $m['name'] ?? $m['id'], 'name' => $m['displayName'], 'ownName' => $m['ownName'],
                    'namesPlatform' => $m['namesPlatform'], 'offered' => $m['offered'], 'added' => ($m['extra'] ?? false) === true,
                ], $p['curatedModels']),
                // Whether the operator may add models of the provider's own list (App\Kit\Catalog\OpenModels): Admin → Catalog → Add models.
                'openModels' => $p['openModels'],
                'voiceCount' => $kind === 'tts' ? ($counts[$p['id']] ?? 0) : 0,
                // Its own settings, each with whether customers may change it and, of one with options, which (App\Kit\Catalog\ProviderSettings): Telenow's own values here.
                'settings' => array_map(fn (array $f) => array_intersect_key($f, array_flip(['key', 'kind', 'label', 'help', 'options', 'min', 'max', 'step', 'defaultValue', 'advanced', 'model', 'offered'])), $p['settings']),
            ], $this->catalog->providers($kind));
        }
        $byName = fn (array $columns) => array_combine(
            array_map(fn (string $c) => $this->manifest->writeName(AgentWrite::RESOURCE, $c), config('kit.agents.stack')),
            array_map(fn (string $c) => $columns[$c] ?? null, config('kit.agents.stack')),
        );

        $defaults = $this->catalog->defaults();
        $recommended = $copy['recommended'] ?? [];
        $voiceNames = [];
        $tts = config('kit.catalog.kinds.tts');
        foreach ([$defaults, $recommended] as $stack) {
            $voice = is_string($stack[$tts['provider']] ?? null) ? $this->voices->find($stack[$tts['provider']], $stack[$tts['voice']] ?? null) : null;
            if ($voice !== null) {
                $voiceNames[$voice->voice_id] = $voice->name;
            }
        }

        return [
            'fetchedAt' => $copy['fetchedAt'] ?? null,
            'error' => $this->settings->get(Catalog::ERROR),
            'platformName' => config('kit.telenow.name'),
            'kinds' => $kinds,
            'defaults' => $byName($defaults),
            'recommended' => $byName($recommended),
            'voiceNames' => (object) $voiceNames,
            'transferAllowed' => $this->agentSettings->transferAllowed(),
            'transferStillOn' => $this->transfers->stillOn(),
            'browserCallsAllowed' => $this->browserCalls->allowed(),
            'browserCallsUnpriced' => $this->browserCalls->unpricedPlans(),
            'webChatAllowed' => $this->webChats->allowed(),
            'campaignLinksAllowed' => $this->campaignLinks->allowed(),
            'webChatUnpriced' => $this->webChats->unpricedPlans(),
            'consentRequired' => $this->consent->requiredForEveryone(),
            'consentStillToTell' => $this->consent->stillToTell(),
            'cloningAllowed' => $this->cloning->allowed(),
            'clonesPerAccount' => $this->cloning->perAccount(),
            'clonesMost' => Cloning::most(),
            // Realtime voices (App\Kit\Catalog\RealtimeCatalog): whether the platform runs them, and its providers with the operator's choices.
            'realtime' => [
                'enabled' => $this->realtime->enabled(),
                'providers' => array_map(fn (array $p) => [
                    'id' => $p['id'], 'catalogName' => $p['name'] ?? $p['id'], 'name' => $p['displayName'], 'ownName' => $p['ownName'], 'namesPlatform' => $p['namesPlatform'],
                    'offered' => $p['chosen'], 'recommended' => (bool) ($p['recommended'] ?? false), 'languages' => $p['languages'] ?? [],
                    'models' => array_map(fn (array $m) => ['id' => $m['id'], 'catalogName' => $m['name'] ?? $m['id'], 'name' => $m['displayName'], 'ownName' => $m['ownName'],
                        'namesPlatform' => $m['namesPlatform'], 'offered' => $m['offered'], 'voiceCount' => count($m['voices'])], $p['curatedModels']),
                ], $this->realtime->providers()),
            ],
        ];
    }
}
