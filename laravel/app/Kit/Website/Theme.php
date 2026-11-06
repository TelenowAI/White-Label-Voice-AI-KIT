<?php

namespace App\Kit\Website;

/**
 * Where the website's templates come from (SELF_HOSTED_KIT_PLAN.md §4.3, the WordPress override pattern): the
 * `theme::` view namespace looks in the child theme's `views/` folder first (config('kit.website.child_theme'), a
 * folder under config('kit.website.themes_path')), then in the default theme (resources/views/theme). An operator
 * changes a page by copying its template to the same path in their child theme; the copy wins, and an update of the
 * kit never touches it. A child theme name is a plain folder name; anything else is ignored.
 *
 * Registered by AppServiceProvider. Not here: the content the templates show (Website).
 */
final class Theme
{
    /** The child theme in use: its name when it is a plain folder name and the folder has views, else null. */
    public static function child(): ?string
    {
        $child = config('kit.website.child_theme');

        return is_string($child) && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $child) && is_dir(self::viewsOf($child)) ? $child : null;
    }

    /** @return list<string> the folders `theme::` views are looked up in, the child theme's first */
    public static function paths(): array
    {
        $child = self::child();

        return array_merge($child === null ? [] : [self::viewsOf($child)], [resource_path('views/theme')]);
    }

    private static function viewsOf(string $child): string
    {
        return rtrim(config('kit.website.themes_path'), '/')."/{$child}/views";
    }
}
