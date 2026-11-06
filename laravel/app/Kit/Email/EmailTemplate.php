<?php

namespace App\Kit\Email;

use App\Kit\Brand\Brand;

/**
 * An email's subject, HTML and plain text, from its kind's words (EmailTexts: the operator's own, else
 * lang/<locale>/emails.php `kinds.<kind>`: `subject`, `lines`, then an optional `action` button for the link, then
 * optional `after` lines) in the operator's brand: its name, logo, colours (config('kit.brand.colors') roles; one that
 * is not a hex colour falls back to the default) and support address (resources/views/emails/layout.blade.php).
 * Values are escaped in the HTML; `:brand` is always the brand's name.
 *
 * Not here: sending (Emails), which emails exist (their callers).
 */
final class EmailTemplate
{
    private const COLOR = '/^#[0-9a-fA-F]{3,8}$/';

    public function __construct(private readonly Brand $brand, private readonly EmailTexts $texts) {}

    /** @return array{subject: string, html: string, text: string} */
    public function render(string $kind, array $vars = [], ?string $url = null): array
    {
        return $this->renderTexts($this->texts->current($kind), $vars, $url);
    }

    /**
     * An email from these words (EmailTexts' shape), as render() makes one: Admin → Email → Templates' preview.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function renderTexts(array $words, array $vars = [], ?string $url = null): array
    {
        $brand = $this->brand->current();
        $texts = $this->texts->filled($words, array_merge($vars, ['brand' => $brand['name']]));
        $lines = $texts['lines'];
        $after = $texts['after'];
        $action = $url !== null ? $texts['action'] : null;
        $support = is_string($brand['supportEmail']) && $brand['supportEmail'] !== '' ? __('emails.support', ['email' => $brand['supportEmail']]) : null;
        $defaults = config('kit.brand.colors');
        $colors = [];
        foreach (['primary', 'primary-contrast', 'background', 'surface', 'text', 'muted'] as $role) {
            $value = $brand['colors'][$role] ?? null;
            $colors[$role] = is_string($value) && preg_match(self::COLOR, $value) ? $value : $defaults[$role];
        }
        $logo = $brand['logoUrl'];

        $html = view('emails.layout', [
            'brand' => $brand['name'],
            'logo' => is_string($logo) && preg_match('#^https?://#i', $logo) ? $logo : null,
            'colors' => $colors,
            'subject' => $texts['subject'], 'lines' => $lines, 'action' => $action, 'url' => $url, 'after' => $after, 'support' => $support,
        ])->render();

        $text = implode("\n\n", array_filter([
            implode("\n\n", $lines),
            $action !== null ? "{$action}: {$url}" : null,
            implode("\n\n", $after),
            $support !== null ? "--\n{$brand['name']}\n{$support}" : "--\n{$brand['name']}",
        ], fn ($part) => $part !== null && $part !== ''));

        return ['subject' => $texts['subject'], 'html' => $html, 'text' => $text];
    }
}
