<?php

namespace App\Http\Controllers\Kit\Admin;

use App\Kit\Website\Theme;
use App\Kit\Website\Website;
use App\Models\PriceList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin → Website (contract/kit-api.yaml getWebsiteSettings, saveWebsiteSettings): the public website's content — the
 * texts, the hero image, the home page's sections in order (each on or off), the features, the plans the pricing page
 * shows, the contact text and the legal pages — with the choices for each, the site's address and the child theme in
 * use (config('kit.website.child_theme'), set on the server).
 *
 * Not here: the content's rules (App\Kit\Website\Website), the pages (Site\WebsiteController).
 */
final class WebsiteController
{
    public function __construct(private readonly Website $website) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload($this->website->content()));
    }

    public function save(Request $request): JsonResponse
    {
        return response()->json($this->payload($this->website->save($request->all())));
    }

    private function payload(array $content): array
    {
        $c = config('kit.website');

        return [
            'content' => $content,
            'siteUrl' => $this->website->vars()['site'],
            'childTheme' => Theme::child(),
            'maxFeatures' => $c['max_features'],
            'maxPlans' => $c['max_plans'],
            'textMaxLength' => $c['text_max_length'],
            'longTextMaxLength' => $c['long_text_max_length'],
            'sectionChoices' => array_map(fn (string $id) => ['value' => $id, 'label' => __("website.sections.{$id}")], $c['home_sections']),
            'legalPages' => array_map(fn (string $id) => ['value' => $id, 'label' => __("website.legal_pages.{$id}")], $c['legal_pages']),
            'planChoices' => PriceList::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (PriceList $l) => ['value' => $l->id, 'label' => $l->name])->values()->all(),
        ];
    }
}
