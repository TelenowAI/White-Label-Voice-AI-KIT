<?php

namespace App\Http\Controllers\Kit\Console;

use App\Kit\Catalog\Catalog;
use App\Kit\Catalog\CatalogRefused;
use App\Kit\Catalog\CatalogVoices;
use App\Kit\Catalog\ProviderSettings;
use App\Kit\Catalog\VoicePreview;
use App\Kit\Voices\VoiceStars;
use App\Models\WorkspaceMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Console → Voices (contract/kit-api.yaml listVoices, previewVoice): the voices of every voice provider the operator
 * offers — narrowed by provider, language, gender and words, a page at a time, with the languages and genders there are
 * to narrow by — and a short clip of one, made on demand with the account's own key and never stored. Read from the
 * kit's copy of Telenow's lists (App\Kit\Catalog\CatalogVoices), never live from Telenow, and of one provider by one of its models (as customers name it). Each says whether the member starred it, and a member stars and unstars voices
 * (starVoice, unstarVoice: App\Kit\Voices\VoiceStars, a member's own).
 */
final class VoicesController
{
    public function __construct(private readonly Catalog $catalog, private readonly CatalogVoices $voices, private readonly VoicePreview $preview, private readonly VoiceStars $stars, private readonly ProviderSettings $settings) {}

    public function index(Request $request): JsonResponse
    {
        $q = $request->validate(CatalogVoices::FILTERS);
        $providers = array_values(array_filter($this->catalog->offered('tts'), fn (array $p) => empty($q['provider']) || $p['key'] === $q['provider']));
        if (! empty($q['model'])) {
            // A model as customers name it (ProviderSettings::publicValue), among one provider's offered models.
            $field = count($providers) === 1 && ! empty($q['provider']) ? ProviderSettings::modelField($this->settings->offered($providers[0]['settings'])) : null;
            $q['model'] = $field === null ? null : ProviderSettings::realValue($field, $q['model']);
            if ($q['model'] === null) {
                throw ValidationException::withMessages(['model' => [__('catalog.not_offered')]]);
            }
        }
        $page = $this->voices->page($providers, $q, (int) ($q['page'] ?? 1), config('kit.catalog.voices_per_page'), $this->stars->of($this->membership($request)));

        return response()->json($page);
    }

    public function preview(Request $request): JsonResponse|Response
    {
        try {
            $clip = $this->preview->voice($this->membership($request)->workspace, $request->all());
        } catch (CatalogRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response($clip['bytes'], 200, ['Content-Type' => $clip['type'], 'Cache-Control' => 'no-store']);
    }

    /** Hear a realtime voice (App\Kit\Catalog\VoicePreview::realtime). */
    public function previewRealtime(Request $request): JsonResponse|Response
    {
        try {
            $clip = $this->preview->realtime($this->membership($request)->workspace, $request->all());
        } catch (CatalogRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response($clip['bytes'], 200, ['Content-Type' => $clip['type'], 'Cache-Control' => 'no-store']);
    }

    public function star(Request $request): Response
    {
        $this->stars->star($this->membership($request), $request->all());

        return response()->noContent();
    }

    public function unstar(Request $request): Response
    {
        $this->stars->unstar($this->membership($request), $request->all());

        return response()->noContent();
    }

    private function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('kit.membership');
    }
}
