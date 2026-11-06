<?php

namespace App\Kit\Brand;

use App\Kit\Settings\Settings;

/**
 * The operator's brand as everyone sees it: what the operator saved (the wizard's brand step, later admin) over
 * config/kit.php's defaults. A colour role the operator never set keeps its default, and roles they add are
 * kept beside the defaults.
 *
 * Not here: editing it (BrandStep), applying it (the console). Called by BrandController and BrandStep.
 */
final class Brand
{
    public function __construct(private readonly Settings $settings) {}

    /** @return array{name: string, logoUrl: ?string, supportEmail: ?string, colors: array<string, string>} */
    public function current(): array
    {
        $defaults = config('kit.brand');

        return [
            'name' => $this->settings->get('brand.name', $defaults['name']),
            'logoUrl' => $this->settings->get('brand.logo_url', $defaults['logo_url'] ?? null),
            'supportEmail' => $this->settings->get('brand.support_email', $defaults['support_email'] ?? null),
            'colors' => array_merge($defaults['colors'] ?? [], $this->settings->get('brand.colors', [])),
        ];
    }
}
