<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\HomepageContent;
use App\Models\SiteIdentity;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Route as RouteFacade;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The registry of pages that are served but not ready to be shown (LUMN-104).
 *
 * The site goes live with its homepage alone. The other pages keep answering —
 * a deep link already shared must not break — but nothing may lead to them.
 *
 * UNFINISHED is the one place that says which pages those are. The ticket that
 * makes a page ready removes it from this list and restores its links (the hero
 * button, the navigation, the mobile menu); while a page is listed, a link to
 * it anywhere on the public layout turns this test red.
 */
class UnfinishedPagesTest extends TestCase
{
    use RefreshDatabase;

    /** Route names of the pages that are served but not ready. */
    private const UNFINISHED = ['cv', 'projects', 'blog', 'blog.show'];

    /**
     * Translation keys withdrawn from the pages with their links, and kept in
     * every locale because they come back with them.
     */
    private const KEPT_KEYS = ['home.cta_projects', 'home.cta_cv', 'nav.cv', 'nav.projects', 'nav.blog'];

    /**
     * The link check runs on the fullest page there is. On an empty database
     * the hero profiles and the about section are not rendered at all, and a
     * link restored inside one of them would go unseen.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $identity = SiteIdentity::firstOrNew([]);
        $identity->fill([
            'full_name' => 'Laurent Bernard-Cardascia',
            'job_title' => 'Tech Lead',
            'contact_email' => 'contact@example.test',
            'github_url' => 'https://github.com/example',
            'linkedin_url' => 'https://www.linkedin.com/in/example/',
            'mastodon_url' => 'https://mastodon.social/@example',
        ]);
        $identity->save();

        $content = new HomepageContent;
        $content->setTranslation('tagline', 'fr', 'Accroche');
        $content->setTranslation('subtitle', 'fr', 'Sous-titre');
        $content->setTranslation('bio', 'fr', "Accroche.\n\nPremier paragraphe.\n\nSecond paragraphe.");
        $content->setTranslation('meta_description', 'fr', 'Meta');
        $content->setTranslation('skills', 'fr', '[{"icon":"⬡","name":"Backend","techs":["PHP"]}]');
        $content->save();
    }

    private function route(string $name): Route
    {
        $route = RouteFacade::getRoutes()->getByName($name);
        $this->assertNotNull($route, "The registry names a route that does not exist: {$name}");

        return $route;
    }

    /** A concrete French path for a route: every parameter but the locale gets a sample value. */
    private function path(string $name): string
    {
        $uri = preg_replace('/\{(?!lang\})[^}]+\}/', 'sample-slug', $this->route($name)->uri());
        $this->assertIsString($uri);

        return '/'.str_replace('{lang}', 'fr', $uri);
    }

    /**
     * Whether an href leads to a registered page, in any locale, absolute or
     * relative, with or without a trailing slash, query or fragment.
     */
    private function leadsToUnfinishedPage(string $href): bool
    {
        $path = rtrim((string) parse_url($href, PHP_URL_PATH), '/');

        foreach (self::UNFINISHED as $name) {
            $pattern = preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote($this->route($name)->uri(), '#'));

            if (preg_match('#^/'.$pattern.'$#', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function hrefsOn(string $path): array
    {
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertIsString($html);

        $links = HTMLDocument::createFromString($html, LIBXML_NOERROR)->querySelectorAll('a[href]');

        return array_map(
            fn (Element $link): string => (string) $link->getAttribute('href'),
            iterator_to_array($links, false),
        );
    }

    public function test_every_registered_name_is_a_real_route(): void
    {
        // A typo in the registry would make the link check below pass vacuously.
        foreach (self::UNFINISHED as $name) {
            $this->assertInstanceOf(Route::class, $this->route($name));
        }
    }

    public function test_the_matcher_recognises_every_form_of_a_link_to_a_registered_page(): void
    {
        // The link check is only as good as this matcher: if it recognised
        // nothing, no page could ever fail it.
        foreach (['/fr/cv', '/de/cv/', 'http://localhost/en/projects', '/fr/blog?page=2', '/pt/blog/a-post', '/fr/blog/a-post#top'] as $href) {
            $this->assertTrue($this->leadsToUnfinishedPage($href), "Not recognised: {$href}");
        }

        foreach (['/fr', '/fr/', '/fr/cvs', '/fr/admin', 'https://www.linkedin.com/in/someone/', 'mailto:contact@example.test'] as $href) {
            $this->assertFalse($this->leadsToUnfinishedPage($href), "Wrongly recognised: {$href}");
        }
    }

    public function test_no_public_page_links_to_an_unfinished_page(): void
    {
        $pages = ['/fr/', ...array_map(fn (string $name): string => $this->path($name), self::UNFINISHED)];

        foreach ($pages as $page) {
            $hrefs = $this->hrefsOn($page);

            // Guard against a parser that finds nothing: the logo and the
            // navigation always link home.
            $this->assertNotEmpty($hrefs, "No link found at all on {$page}");

            $offending = array_values(array_filter($hrefs, fn (string $href): bool => $this->leadsToUnfinishedPage($href)));

            $this->assertSame([], $offending, "{$page} links to an unfinished page");
        }
    }

    public function test_no_page_links_to_an_unfinished_page_for_a_signed_in_admin_either(): void
    {
        // Signed in, the layout renders more: the user menu, its admin link,
        // the logout form — on desktop and in the mobile menu. A link added
        // there would be invisible to the visitor run above.
        Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create(['two_factor_confirmed_at' => now()]);
        $admin->assignRole('admin');

        $this->actingAs($admin)->withSession(['auth.two_factor_verified' => true]);

        $pages = ['/fr/', ...array_map(fn (string $name): string => $this->path($name), self::UNFINISHED)];

        foreach ($pages as $page) {
            $hrefs = $this->hrefsOn($page);

            // The admin link proves the signed-in layout was the one rendered.
            $this->assertContains(route('admin.dashboard', ['lang' => 'fr']), $hrefs, "Not the signed-in layout on {$page}");

            $offending = array_values(array_filter($hrefs, fn (string $href): bool => $this->leadsToUnfinishedPage($href)));

            $this->assertSame([], $offending, "{$page} links to an unfinished page for a signed-in admin");
        }
    }

    public function test_unfinished_pages_still_answer(): void
    {
        // Withdrawn from the links, not from the site: a deep link already
        // shared keeps working (LUMN-96, criterion 2).
        foreach (self::UNFINISHED as $name) {
            $this->get($this->path($name))->assertOk();
        }
    }

    public function test_the_withdrawn_labels_are_kept_in_every_locale(): void
    {
        /** @var list<string> $locales */
        $locales = config('i18n.supported_locales');
        $this->assertCount(24, $locales);

        foreach ($locales as $locale) {
            foreach (self::KEPT_KEYS as $key) {
                // No fallback: a key missing in German must not pass on the French one.
                $this->assertTrue(Lang::has($key, $locale, false), "{$key} is missing in {$locale}");
            }
        }
    }
}
