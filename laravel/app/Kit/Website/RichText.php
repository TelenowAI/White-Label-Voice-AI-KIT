<?php

namespace App\Kit\Website;

use Illuminate\Support\HtmlString;

/**
 * The operator's longer texts on the website (the legal pages, the contact page) as HTML, with a little structure and
 * nothing else: a line starting `# ` is a heading, lines starting `- ` are a list, a blank line ends a paragraph.
 * Everything is escaped first, so a text can never add markup or script. `{brand}`, `{email}` and `{site}` are
 * filled in (escaped too). Used by the theme's templates through Website.
 */
final class RichText
{
    /** @param  array<string, string>  $vars */
    public static function html(string $text, array $vars): HtmlString
    {
        $text = self::fill($text, $vars);
        $html = [];
        foreach (preg_split("/\\R{2,}/", trim(str_replace("\r\n", "\n", $text))) as $block) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), fn (string $l) => $l !== ''));
            if ($lines === []) {
                continue;
            }
            if (str_starts_with($lines[0], '# ') && count($lines) === 1) {
                $html[] = '<h2>'.e(substr($lines[0], 2)).'</h2>';
            } elseif (array_filter($lines, fn (string $l) => ! str_starts_with($l, '- ')) === []) {
                $html[] = '<ul>'.implode('', array_map(fn (string $l) => '<li>'.e(substr($l, 2)).'</li>', $lines)).'</ul>';
            } else {
                $html[] = '<p>'.implode('<br>', array_map(fn (string $l) => e($l), $lines)).'</p>';
            }
        }

        return new HtmlString(implode("\n", $html));
    }

    /** A short text with its placeholders filled in (escape it where it is shown). */
    public static function fill(string $text, array $vars): string
    {
        return strtr($text, array_combine(array_map(fn (string $k) => '{'.$k.'}', array_keys($vars)), array_values($vars)));
    }
}
