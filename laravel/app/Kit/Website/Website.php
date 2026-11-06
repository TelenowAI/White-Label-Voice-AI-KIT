<?php

namespace App\Kit\Website;

use App\Kit\Brand\Brand;
use App\Kit\Email\Senders;
use App\Kit\Pricing\Pricing;
use App\Kit\Settings\Settings;
use App\Models\PriceList;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * The public website's content (SELF_HOSTED_KIT_PLAN.md §4.3, content vs layout): the texts, the hero image, the home
 * page's sections in the operator's order (each on or off), the features, the plans the pricing page shows with the
 * operator's words for each, the contact text and the legal pages — stored in settings over the defaults in
 * lang/<locale>/website.php. Texts may hold `{brand}`, `{email}` and `{site}`, filled in when shown. Layout is theme
 * code (resources/views/theme, Theme).
 *
 * The pricing page shows only what a plan's price list fixes as a number — an all-in rate per minute for calls, a fixed
 * fee — and only where it holds for everything its words describe: the first rule that matches wins, as the pricing
 * engine does, so a rule an earlier one can match the same item as is left out. A markup on the platform's cost is not
 * a number a visitor can be told; the operator's words for the plan say the rest.
 *
 * Not here: the pages (App\Http\Controllers\Site\WebsiteController), the admin page (Admin\WebsiteController).
 */
final class Website
{
    public const CONTENT = 'website.content';

    public function __construct(private readonly Settings $settings, private readonly Brand $brand, private readonly Pricing $pricing,
        private readonly Senders $senders) {}

    /** The content: what the operator saved over the defaults. */
    public function content(): array
    {
        $defaults = $this->defaults();
        $saved = $this->settings->get(self::CONTENT, []);

        return array_replace($defaults, array_intersect_key(is_array($saved) ? $saved : [], $defaults));
    }

    /** Check and save the whole content. Throws a ValidationException naming what is wrong. */
    public function save(array $input): array
    {
        $c = config('kit.website');
        $short = 'max:'.$c['text_max_length'];
        $long = 'max:'.$c['long_text_max_length'];
        $values = Validator::make($input, [
            'tagline' => ['present', 'nullable', 'string', $short],
            'metaDescription' => ['present', 'nullable', 'string', $short],
            'heroTitle' => ['required', 'string', $short],
            'heroText' => ['present', 'nullable', 'string', $long],
            'heroImage' => ['present', 'nullable', 'string', 'max:2048', 'url:http,https'],
            'ctaTitle' => ['present', 'nullable', 'string', $short],
            'ctaText' => ['present', 'nullable', 'string', $long],
            'sections' => ['required', 'array', 'size:'.count($c['home_sections'])],
            'sections.*.id' => ['required', 'string', 'distinct', Rule::in($c['home_sections'])],
            'sections.*.on' => ['required', 'boolean'],
            'features' => ['present', 'array', 'max:'.$c['max_features']],
            'features.*.title' => ['required', 'string', $short],
            'features.*.text' => ['present', 'nullable', 'string', $long],
            'pricingIntro' => ['present', 'nullable', 'string', $long],
            'plans' => ['present', 'array', 'max:'.$c['max_plans']],
            'plans.*.id' => ['required', 'string', 'distinct', Rule::in(PriceList::query()->pluck('id')->all())],
            'plans.*.headline' => ['present', 'nullable', 'string', $short],
            'plans.*.note' => ['present', 'nullable', 'string', $long],
            'contactText' => ['present', 'nullable', 'string', $long],
            'legal' => ['required', 'array:'.implode(',', $c['legal_pages'])],
            'legal.*' => ['present', 'nullable', 'string', $long],
        ], [], ['heroTitle' => __('website.fields.heroTitle'), 'heroImage' => __('website.fields.heroImage')])->validate();

        $content = [
            'tagline' => $values['tagline'] ?? '',
            'metaDescription' => $values['metaDescription'] ?? '',
            'heroTitle' => $values['heroTitle'],
            'heroText' => $values['heroText'] ?? '',
            'heroImage' => ($values['heroImage'] ?? '') === '' ? null : $values['heroImage'],
            'ctaTitle' => $values['ctaTitle'] ?? '',
            'ctaText' => $values['ctaText'] ?? '',
            'sections' => array_map(fn (array $s) => ['id' => $s['id'], 'on' => (bool) $s['on']], array_values($values['sections'])),
            'features' => array_map(fn (array $f) => ['title' => $f['title'], 'text' => $f['text'] ?? ''], array_values($values['features'])),
            'pricingIntro' => $values['pricingIntro'] ?? '',
            'plans' => array_map(fn (array $p) => ['id' => $p['id'], 'headline' => $p['headline'] ?? '', 'note' => $p['note'] ?? ''], array_values($values['plans'])),
            'contactText' => $values['contactText'] ?? '',
            'legal' => array_map(fn ($t) => $t ?? '', array_replace(array_fill_keys($c['legal_pages'], ''), $values['legal'])),
        ];
        $this->settings->put(self::CONTENT, $content);

        return $this->content();
    }

    /** What `{brand}`, `{email}` and `{site}` stand for. */
    public function vars(): array
    {
        $brand = $this->brand->current();

        return ['brand' => $brand['name'], 'email' => (string) ($brand['supportEmail'] ?? ''), 'site' => $this->senders->siteUrl()];
    }

    /**
     * The plans the pricing page shows (the operator's list, else the default plan), each with the operator's words
     * and what its price list fixes as a number.
     *
     * @return list<array{id: string, name: string, headline: string, note: string, lines: list<array{label: string, amount: string, per: string}>, increment: ?int}>
     */
    public function plans(): array
    {
        $lists = PriceList::query()->get()->keyBy('id');
        $chosen = $this->content()['plans'];
        if ($chosen === []) {
            $default = $this->pricing->settings()['defaultPlan'];
            $chosen = $default !== null && $lists->has($default) ? [['id' => $default, 'headline' => '', 'note' => '']] : [];
        }

        return array_values(array_filter(array_map(function (array $p) use ($lists) {
            $list = $lists->get($p['id']);
            if ($list === null) {
                return null;
            }

            return ['id' => $list->id, 'name' => $list->name, 'headline' => $p['headline'], 'note' => $p['note'],
                'lines' => $this->facts($list->id, $lists->all()), 'increment' => $list->definition['billingIncrementSeconds'] ?? null];
        }, $chosen)));
    }

    /** @return list<array{label: string, amount: string, per: string}> a plan's fixed numbers, as the engine would reach them */
    private function facts(string $planId, array $lists): array
    {
        $lines = [];
        $earlier = ['call' => [], 'fee' => []];
        $currency = $lists[$planId]->definition['currency'] ?? '';
        // Pricing never saves a cycle; a list met twice ends the walk all the same.
        $seen = [];
        for ($id = $planId; is_string($id) && isset($lists[$id]) && ! isset($seen[$id]); $id = $definition['extends'] ?? null) {
            $seen[$id] = true;
            $definition = $lists[$id]->definition;
            foreach ($definition['rules'] ?? [] as $rule) {
                $item = $rule['match']['item'] ?? null;
                if (! isset($earlier[$item])) {
                    continue;
                }
                $attributes = array_diff_key($rule['match'], ['item' => true]);
                // An earlier rule that can match the same item wins it, so this rule's number would be wrong for some
                // of what its words describe: it is shown only when no earlier rule can.
                $overlapped = collect($earlier[$item])->contains(fn (array $before) => self::overlap($before, $attributes));
                $earlier[$item][] = $attributes;
                $amount = $item === 'call' ? ($rule['price']['perMinute'] ?? null) : ($rule['price']['fixed'] ?? null);
                if ($overlapped || ! is_string($amount)) {
                    continue;
                }
                $lines[] = ['label' => $item === 'call' ? $this->callLabel($attributes) : $this->feeLabel($attributes), 'amount' => $this->money($amount, $currency),
                    'per' => $item === 'call' ? __('website.per_minute') : ''];
            }
        }

        return $lines;
    }

    /**
     * Whether some item matches both rules: no attribute both name has values in common (a rule's value is one value
     * or a list of them; contract/pricing/README.md).
     */
    private static function overlap(array $a, array $b): bool
    {
        foreach (array_intersect_key($a, $b) as $name => $value) {
            if (array_intersect(array_map('json_encode', (array) $value), array_map('json_encode', (array) $b[$name])) === []) {
                return false;
            }
        }

        return true;
    }

    private function callLabel(array $attributes): string
    {
        $words = [];
        foreach ($attributes as $name => $value) {
            foreach ((array) $value as $one) {
                $key = "website.attributes.{$name}.{$one}";
                $words[] = __($key) === $key ? (string) $one : __($key);
            }
        }

        return $words === [] ? __('website.calls') : __('website.calls_with', ['which' => implode(', ', $words)]);
    }

    private function feeLabel(array $attributes): string
    {
        $kind = (string) ($attributes['refKind'] ?? '');
        $key = "website.fees.{$kind}";

        return __($key) === $key ? $kind : __($key);
    }

    private function money(string $amount, string $currency): string
    {
        if (class_exists(\NumberFormatter::class) && $currency !== '') {
            $f = new \NumberFormatter(app()->getLocale(), \NumberFormatter::CURRENCY);
            $places = BigDecimal::of($amount)->getScale();
            $f->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $places);
            $f->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $places);
            $text = $f->formatCurrency((float) $amount, $currency);
            if ($text !== false) {
                return $text;
            }
        }

        return trim("{$amount} {$currency}");
    }

    private function defaults(): array
    {
        $c = config('kit.website');

        return [
            'tagline' => __('website.defaults.tagline'),
            'metaDescription' => __('website.defaults.meta_description'),
            'heroTitle' => __('website.defaults.hero_title'),
            'heroText' => __('website.defaults.hero_text'),
            'heroImage' => null,
            'ctaTitle' => __('website.defaults.cta_title'),
            'ctaText' => __('website.defaults.cta_text'),
            'sections' => array_map(fn (string $id) => ['id' => $id, 'on' => true], $c['home_sections']),
            'features' => __('website.defaults.features'),
            'pricingIntro' => __('website.defaults.pricing_intro'),
            'plans' => [],
            'contactText' => __('website.defaults.contact_text'),
            'legal' => array_combine($c['legal_pages'], array_map(fn (string $p) => __("website.defaults.legal.{$p}"), $c['legal_pages'])),
        ];
    }
}
