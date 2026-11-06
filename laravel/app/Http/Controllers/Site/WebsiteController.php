<?php

namespace App\Http\Controllers\Site;

use App\Kit\Brand\Brand;
use App\Kit\Setup\Installation;
use App\Kit\Signup\SignupSettings;
use App\Kit\Website\Website;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * The public website's pages (routes/site.php): home, features, pricing, contact and the legal pages, drawn by the
 * theme (`theme::` views, App\Kit\Website\Theme) with the operator's content and brand. No session or cookie: the pages
 * are the same for everyone. Before the install every page sends the visitor to the setup wizard.
 *
 * Not here: the content (App\Kit\Website\Website), the console's pages (ConsolePageController).
 */
final class WebsiteController
{
    public function __construct(private readonly Website $website, private readonly Brand $brand, private readonly SignupSettings $signup,
        private readonly Installation $installation) {}

    public function home(): Response|RedirectResponse
    {
        return $this->page('home');
    }

    public function features(): Response|RedirectResponse
    {
        return $this->page('features');
    }

    public function pricing(): Response|RedirectResponse
    {
        return $this->page('pricing');
    }

    public function contact(): Response|RedirectResponse
    {
        return $this->page('contact');
    }

    public function legal(string $page): Response|RedirectResponse
    {
        return $this->page('legal', ['page' => $page]);
    }

    private function page(string $view, array $data = []): Response|RedirectResponse
    {
        if (! $this->installation->installed()) {
            return redirect('/setup');
        }
        $brand = $this->brand->current();
        // Only colours that are colours reach the page's style (the wizard checks them too).
        $colors = array_filter($brand['colors'], fn ($v, $k) => is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $v)
            && preg_match('/^[a-z0-9-]+$/', (string) $k), ARRAY_FILTER_USE_BOTH);

        return response()->view("theme::{$view}", $data + [
            'brand' => $brand,
            'colors' => $colors,
            'content' => $this->website->content(),
            'vars' => $this->website->vars(),
            'plans' => in_array($view, ['home', 'pricing'], true) ? $this->website->plans() : [],
            'signUp' => $this->signup->open(),
            'legalPages' => config('kit.website.legal_pages'),
        ]);
    }
}
