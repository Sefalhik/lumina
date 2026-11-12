<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * The header's navigation landmark and its mobile menu button are announced by
 * name, and that name follows the page's language. Both were written in French
 * in the layout, so a German screen reader heard « Navigation principale ».
 */
class NavigationAccessibleNamesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{nav: ?string, menu: ?string} */
    private function namesOn(string $path): array
    {
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertIsString($html);

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

        return [
            'nav' => $document->querySelector('header nav')?->getAttribute('aria-label'),
            'menu' => $document->querySelector('header .dropdown.md\:hidden > button')?->getAttribute('aria-label'),
        ];
    }

    public function test_the_names_are_those_of_the_page_language(): void
    {
        $this->assertSame(['nav' => 'Navigation principale', 'menu' => 'Menu'], $this->namesOn('/fr/'));

        $this->assertSame([
            'nav' => trans('nav.main_label', [], 'de'),
            'menu' => trans('nav.menu', [], 'de'),
        ], $this->namesOn('/de/'));
    }

    public function test_every_locale_has_both_names(): void
    {
        /** @var list<string> $locales */
        $locales = config('i18n.supported_locales');

        foreach ($locales as $locale) {
            foreach (['nav.main_label', 'nav.menu'] as $key) {
                // No fallback: a locale without the key would silently show the French one.
                $this->assertTrue(Lang::has($key, $locale, false), "{$key} is missing in {$locale}");
            }
        }
    }

    public function test_a_translated_name_is_not_the_french_one(): void
    {
        // Guards the test above against a translation file that merely copied
        // the French: Greek shares neither word with it.
        $greek = $this->namesOn('/el/');

        $this->assertNotSame('Navigation principale', $greek['nav']);
        $this->assertNotSame('Menu', $greek['menu']);
        $this->assertNotNull($greek['nav']);
        $this->assertNotNull($greek['menu']);
    }
}
