<?php

namespace App\Http\Controllers\Site;

use Illuminate\Http\Response;

/**
 * The console's pages in a release (routes/site.php): every path under one of config('kit.console.paths') answers the
 * built console's index.html (public/<config('kit.console.build_dir')>/, put there by scripts/release.mjs); the
 * console routes in the browser and its scripts come straight from the web server. In development the console runs on
 * its own dev server and this answers 404 with how to build it.
 */
final class ConsolePageController
{
    public function __invoke(): Response
    {
        $index = public_path(config('kit.console.build_dir').'/index.html');
        if (! is_file($index)) {
            return response(__('website.console_not_built'), 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        return response((string) file_get_contents($index), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }
}
