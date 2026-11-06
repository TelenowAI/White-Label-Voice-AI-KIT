<?php

namespace Tests\Feature\Kit;

use App\Kit\Brand\Brand;
use App\Kit\Pricing\Pricing;
use App\Kit\Settings\Settings;
use App\Kit\Website\Theme;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The public website and the console's pages (routes/site.php): before the install every page sends the visitor to the
 * setup wizard; after it the theme draws the operator's content in their brand — the home page's sections in their
 * order, only those on — with no cookie on any page; everything the operator wrote is escaped, and only colours reach
 * the style; a child theme's template wins over the default's; the pricing page shows a plan's numbers only where the
 * pricing engine would reach them for everything the words describe; Admin → Website is the staff's alone and checks
 * what it saves; the console's paths answer the built console.
 */
class WebsiteTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = User::create(['name' => 'Op', 'email' => 'op@voice.example', 'password' => 'a long password 1', 'role' => User::ROLE_OWNER]);
        app(Settings::class)->put('brand.name', 'Acme Voice');
        app(Settings::class)->put('brand.support_email', 'help@acme.example');
    }

    private function install(): void
    {
        touch(config('kit.installed_marker'));
    }

    private function content(array $over = []): array
    {
        return array_replace([
            'tagline' => 'Calls, answered',
            'metaDescription' => '{brand} for clinics',
            'heroTitle' => 'Never miss a call at {brand}',
            'heroText' => 'Ask {email}.',
            'heroImage' => null,
            'ctaTitle' => 'Start today',
            'ctaText' => 'It takes a minute.',
            'sections' => [['id' => 'cta', 'on' => true], ['id' => 'features', 'on' => true], ['id' => 'pricing', 'on' => false]],
            'features' => [['title' => 'Answers', 'text' => 'Day and night.']],
            'pricingIntro' => 'Simple prices.',
            'plans' => [],
            'contactText' => 'Write any time.',
            'legal' => ['terms' => "Our terms.\n\n# Payment\n\nYou pay first.", 'privacy' => "- We keep your name\n- We keep your calls"],
        ], $over);
    }

    private function saveContent(array $over = []): TestResponse
    {
        return $this->actingAs($this->staff)->putJson('/api/kit/admin/website', $this->content($over));
    }

    /** @param array<string, mixed> $definition */
    private function plan(string $id, string $name, array $definition): void
    {
        app(Pricing::class)->saveList($id, $name, $definition + ['id' => $id, 'currency' => 'INR', 'decimals' => 2, 'rounding' => 'half-up', 'billingIncrementSeconds' => 60]);
    }

    private function assertNoCookie(TestResponse $response): void
    {
        $this->assertSame([], $response->headers->getCookies(), 'a site page sets no cookie');
    }

    public function test_before_the_install_every_page_sends_the_visitor_to_the_wizard(): void
    {
        foreach (['/', '/features', '/pricing', '/contact', '/legal/terms', '/legal/privacy'] as $page) {
            $response = $this->get($page)->assertRedirect('/setup');
            $this->assertNoCookie($response);
        }
        $this->get('/legal/nonsense')->assertNotFound();
    }

    public function test_the_home_page_draws_the_defaults_in_the_brand_with_no_cookie(): void
    {
        $this->install();
        $response = $this->get('/')->assertOk()->assertHeader('Content-Type', 'text/html; charset=UTF-8');
        $this->assertNoCookie($response);
        $response->assertSee('<title>Acme Voice — '.e(__('website.defaults.tagline')).'</title>', false)
            ->assertSee(__('website.defaults.hero_title'))
            ->assertSee(e(str_replace('{brand}', 'Acme Voice', __('website.defaults.hero_text'))), false)
            ->assertSee(__('website.defaults.features.0.title'))
            ->assertSee('--kit-primary:'.config('kit.brand.colors.primary').';', false)
            ->assertSee('href="'.url('legal/terms').'"', false)
            ->assertSee('mailto:help@acme.example', false)
            // Sign-ups are closed: no way to sign up is offered.
            ->assertDontSee('href="'.url('signup').'"', false);
        foreach (['/features', '/pricing', '/contact', '/legal/terms', '/legal/privacy'] as $page) {
            $this->assertNoCookie($this->get($page)->assertOk());
        }
        $this->get('/legal/privacy')->assertSee('<h1>'.__('website.legal_pages.privacy').'</h1>', false)
            ->assertSee('<ul><li>', false);
    }

    public function test_the_home_page_shows_the_operators_sections_in_their_order_and_only_those_on(): void
    {
        $this->install();
        $this->saveContent()->assertOk()->assertJsonPath('content.sections.0.id', 'cta');
        $home = $this->get('/')->assertOk()
            ->assertSee('Never miss a call at Acme Voice')
            ->assertSeeInOrder(['id="cta-title"', 'id="features-title"'], false)
            ->assertDontSee('id="pricing-title"', false);
        $this->assertStringContainsString('<p>Ask help@acme.example.</p>', $home->getContent());
        $this->assertStringContainsString('<meta name="description" content="Acme Voice for clinics">', $home->getContent());

        $this->saveContent(['sections' => [['id' => 'pricing', 'on' => true], ['id' => 'features', 'on' => false], ['id' => 'cta', 'on' => true]]])->assertOk();
        $this->get('/')->assertSeeInOrder(['id="pricing-title"', 'id="cta-title"'], false)->assertDontSee('id="features-title"', false);
    }

    public function test_what_the_operator_writes_is_escaped_and_only_colours_reach_the_style(): void
    {
        $this->install();
        $evil = '<script>alert(1)</script>';
        $this->saveContent([
            'heroTitle' => "{$evil} hi", 'heroText' => "# {$evil}\n\n- {$evil}", 'tagline' => $evil, 'metaDescription' => '"><script>x</script>',
            'features' => [['title' => $evil, 'text' => $evil]], 'ctaTitle' => $evil,
            'legal' => ['terms' => "# {$evil}\n\n{$evil}", 'privacy' => $evil],
        ])->assertOk();
        app(Settings::class)->put('brand.name', '</title><script>b()</script>');
        app(Settings::class)->put('brand.colors', ['primary' => 'red;}</style><script>c()</script>', 'surface' => '#abcdef', 'x;}body{' => '#000000']);
        foreach (['/', '/features', '/legal/terms', '/legal/privacy', '/contact'] as $page) {
            $html = $this->get($page)->assertOk()->getContent();
            $this->assertStringNotContainsString('<script>', $html, "{$page}: no markup the operator wrote");
            $this->assertStringNotContainsString('x;}body{', $html, "{$page}: a role that is not a name never reaches the style");
        }
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('--kit-surface:#abcdef;', $html);
        $this->assertStringNotContainsString('--kit-primary:red', $html, 'a value that is not a colour is dropped');
        $this->assertStringContainsString('<h2>&lt;script&gt;', $html, 'the rich text is escaped first, then given its structure');
        $this->assertStringContainsString('<li>&lt;script&gt;', $html);

        $this->saveContent(['heroImage' => 'javascript:alert(1)'])->assertStatus(422)->assertJsonValidationErrors(['heroImage']);
        $this->saveContent(['heroImage' => 'https://cdn.acme.example/hero.png'])->assertOk();
        $this->get('/')->assertSee('src="https://cdn.acme.example/hero.png"', false);
    }

    public function test_a_child_themes_template_wins_over_the_default(): void
    {
        $this->install();
        $this->assertSame(Theme::paths(), View::getFinder()->getHints()['theme'], 'the theme namespace is registered at boot');
        $this->assertSame([resource_path('views/theme')], Theme::paths());

        $themes = $this->scratch.'/themes';
        mkdir("{$themes}/acme/views/sections", 0700, true);
        file_put_contents("{$themes}/acme/views/sections/features.blade.php", '<section id="acme-features">{{ count($content[\'features\']) }} things</section>');
        // A name that is not a plain folder name is never followed, even to a folder that has views.
        mkdir("{$this->scratch}/outside/views/sections", 0700, true);
        file_put_contents("{$this->scratch}/outside/views/sections/features.blade.php", 'outside');
        foreach (['../outside', '../acme', 'Acme', 'missing', ''] as $ignored) {
            config(['kit.website.themes_path' => $themes, 'kit.website.child_theme' => $ignored]);
            $this->assertNull(Theme::child(), "{$ignored}: not a child theme");
            $this->assertSame([resource_path('views/theme')], Theme::paths());
        }
        config(['kit.website.child_theme' => 'acme']);
        $this->assertSame('acme', Theme::child());
        View::replaceNamespace('theme', Theme::paths());
        $this->get('/')->assertOk()->assertSee('<section id="acme-features">4 things</section>', false)->assertDontSee('id="features-title"', false)
            // The rest still comes from the default theme.
            ->assertSee('id="cta-title"', false);
        $this->actingAs($this->staff)->getJson('/api/kit/admin/website')->assertJsonPath('childTheme', 'acme');
    }

    public function test_the_pricing_page_shows_a_plans_numbers_only_where_the_engine_reaches_them(): void
    {
        $this->install();
        app(Pricing::class)->updateSettings(['currency' => 'INR', 'usdRate' => '83.25']);
        $this->plan('base', 'Base', ['rules' => [
            ['id' => 'web', 'match' => ['item' => 'call', 'channel' => 'web_call'], 'price' => ['perMinute' => '9.99']],
            ['id' => 'out', 'match' => ['item' => 'call', 'direction' => 'outbound'], 'price' => ['perMinute' => '2.50']],
            ['id' => 'ai', 'match' => ['item' => 'component'], 'price' => ['markupPercent' => '40']],
            ['id' => 'rent', 'match' => ['item' => 'fee', 'refKind' => 'rental'], 'price' => ['fixed' => '120.00']],
            ['id' => 'setup', 'match' => ['item' => 'fee', 'refKind' => 'number_setup'], 'price' => ['fixed' => '50.00']],
            ['id' => 'porting', 'match' => ['item' => 'fee', 'refKind' => 'porting'], 'price' => ['markupPercent' => '10']],
        ]]);
        $this->plan('starter', 'Starter', ['extends' => 'base', 'billingIncrementSeconds' => 30, 'rules' => [
            ['id' => 'both', 'match' => ['item' => 'call', 'channel' => ['web_call', 'telephony'], 'direction' => 'inbound'], 'price' => ['perMinute' => '1.20']],
            ['id' => 'rent', 'match' => ['item' => 'fee', 'refKind' => 'rental'], 'price' => ['fixed' => '99.00']],
        ]]);

        // No plan chosen and no default plan: no prices.
        $this->get('/pricing')->assertOk()->assertSee(__('website.pricing_none'));

        app(Pricing::class)->updateSettings(['currency' => 'INR', 'usdRate' => '83.25', 'defaultPlan' => 'base']);
        $this->get('/pricing')->assertOk()->assertSee('Base')->assertSee('₹9.99')->assertSee('₹120.00')->assertDontSee(__('website.pricing_none'))
            // `out` can match a web call `web` already wins: its number would be wrong for outgoing calls in the browser.
            ->assertDontSee('₹2.50')
            ->assertSee(__('website.calls_with', ['which' => __('website.attributes.channel.web_call')]))
            ->assertSee(__('website.fees.rental'))->assertSee(__('website.fees.number_setup'))->assertSee('₹50.00')
            // A markup on the platform's cost is not a number a visitor can be told.
            ->assertDontSee('porting')
            ->assertSee(__('website.increment', ['seconds' => 60]));

        $this->saveContent(['plans' => [['id' => 'starter', 'headline' => 'For {brand} starters', 'note' => 'No contract.'], ['id' => 'base', 'headline' => '', 'note' => '']]])->assertOk();
        $page = $this->get('/pricing')->assertOk()
            ->assertSeeInOrder(['Starter', 'For Acme Voice starters', '₹1.20', '₹99.00', '₹50.00', __('website.increment', ['seconds' => 30]), 'No contract.', 'Base', '₹9.99'])
            ->assertSee(__('website.calls_with', ['which' => implode(', ', [__('website.attributes.channel.web_call'), __('website.attributes.channel.telephony'), __('website.attributes.direction.inbound')])]));
        // In Starter, base's `web` can match an incoming web call `both` wins, and base's rent is Starter's rent's;
        // base's number setup fee is Starter's too (above).
        $starter = substr($page->getContent(), strpos($page->getContent(), 'Starter'), strpos($page->getContent(), '<h3>Base</h3>') - strpos($page->getContent(), 'Starter'));
        $this->assertStringNotContainsString('₹9.99', $starter);
        $this->assertStringNotContainsString('₹120.00', $starter);
        $this->assertStringNotContainsString('₹2.50', $starter);
        $this->get('/')->assertDontSee('₹1.20', false)->assertDontSee('id="pricing-title"', false);
    }

    public function test_admin_website_is_the_staffs_alone_and_checks_what_it_saves(): void
    {
        $this->install();
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@dental.example', 'password' => 'a long password 9']);
        $this->getJson('/api/kit/admin/website')->assertUnauthorized();
        $this->actingAs($ana)->getJson('/api/kit/admin/website')->assertForbidden();
        $this->actingAs($ana)->putJson('/api/kit/admin/website', $this->content())->assertForbidden();

        $shown = $this->actingAs($this->staff)->getJson('/api/kit/admin/website')->assertOk()
            ->assertJsonPath('content.heroTitle', __('website.defaults.hero_title'))
            ->assertJsonPath('childTheme', null)->assertJsonPath('maxFeatures', config('kit.website.max_features'));
        $this->assertSame(config('kit.website.home_sections'), array_column($shown->json('sectionChoices'), 'value'));
        $this->assertSame(config('kit.website.legal_pages'), array_column($shown->json('legalPages'), 'value'));
        $this->assertSame(array_keys($this->content()), array_keys($shown->json('content')));

        $this->saveContent(['heroTitle' => ''])->assertStatus(422)->assertJsonValidationErrors(['heroTitle']);
        $this->saveContent(['sections' => [['id' => 'cta', 'on' => true], ['id' => 'features', 'on' => true]]])->assertStatus(422)->assertJsonValidationErrors(['sections']);
        $this->saveContent(['sections' => [['id' => 'cta', 'on' => true], ['id' => 'cta', 'on' => true], ['id' => 'pricing', 'on' => true]]])->assertStatus(422)->assertJsonValidationErrors(['sections.0.id']);
        $this->saveContent(['sections' => [['id' => 'cta', 'on' => true], ['id' => 'features', 'on' => true], ['id' => 'blog', 'on' => true]]])->assertStatus(422)->assertJsonValidationErrors(['sections.2.id']);
        $this->saveContent(['features' => array_fill(0, config('kit.website.max_features') + 1, ['title' => 'x', 'text' => ''])])->assertStatus(422)->assertJsonValidationErrors(['features']);
        $this->saveContent(['plans' => [['id' => 'no-such', 'headline' => '', 'note' => '']]])->assertStatus(422)->assertJsonValidationErrors(['plans.0.id']);
        $this->saveContent(['legal' => ['terms' => 'x', 'privacy' => 'y', 'cookies' => 'z']])->assertStatus(422)->assertJsonValidationErrors(['legal']);
        $this->saveContent(['tagline' => str_repeat('x', config('kit.website.text_max_length') + 1)])->assertStatus(422)->assertJsonValidationErrors(['tagline']);
        $this->assertSame(__('website.defaults.hero_title'), $this->actingAs($this->staff)->getJson('/api/kit/admin/website')->json('content.heroTitle'), 'nothing refused was saved');

        $this->saveContent(['legal' => ['terms' => 'Only terms.']])->assertOk()->assertJsonPath('content.legal', ['terms' => 'Only terms.', 'privacy' => '']);
        $this->get('/legal/terms')->assertSee('<p>Only terms.</p>', false);
    }

    public function test_the_consoles_paths_answer_the_built_console(): void
    {
        $public = $this->scratch.'/public';
        $this->app->usePublicPath($public);
        $this->get('/setup')->assertNotFound()->assertSee(__('website.console_not_built'));

        mkdir("{$public}/".config('kit.console.build_dir'), 0700, true);
        file_put_contents("{$public}/".config('kit.console.build_dir').'/index.html', '<!doctype html><div id="root"></div>');
        // Before the install too: the wizard is the console's.
        foreach (['/setup', '/setup/database', '/console', '/console/agents/7', '/admin/website', '/signup', '/signup/verify/abc', '/signin/abc', '/reset/abc'] as $path) {
            $response = $this->get($path)->assertOk()->assertSee('<div id="root"></div>', false)->assertHeader('Cache-Control', 'no-cache, private');
            $this->assertNoCookie($response);
        }
        $this->get('/consoles')->assertNotFound();
        $this->getJson('/api/kit/health')->assertOk();
    }
}
