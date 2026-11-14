<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * What every layout carries for the visitor's preferences (LUMN-11): the script
 * that restores them before the first paint — one copy,
 * layouts/partials/restore-preferences.blade.php — and the place of the motion
 * switch. The script accepts the themes of resources/js/themes.json, the list the
 * islands import: a seam between two languages, each side tested on its own, and
 * checked here to agree. Its nonce is SecurityHeadersTest's, which checks every
 * inline script of a page; what the script and the switch do in a browser is
 * tests/e2e/motion.spec.js.
 */
class RestorePreferencesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, bool}> */
    public static function layouts(): array
    {
        return [
            'public' => ['/fr/', false],
            'login' => ['/fr/login', false],
            'administration' => ['/fr/admin', true],
        ];
    }

    /** @return list<array{id: string, favicon: string}> */
    private function themesOfTheIslands(): array
    {
        /** @var list<array{id: string, favicon: string}> $themes */
        $themes = json_decode((string) file_get_contents(resource_path('js/themes.json')), true, flags: JSON_THROW_ON_ERROR);

        return $themes;
    }

    private function page(string $path, bool $asAdmin): string
    {
        if ($asAdmin) {
            Role::create(['name' => 'admin', 'guard_name' => 'web']);
            $admin = User::factory()->create([
                'password' => Hash::make('secret'),
                'two_factor_confirmed_at' => now(),
            ]);
            $admin->assignRole('admin');
            $this->actingAs($admin)->withSession(['auth.two_factor_verified' => true]);
        }

        return (string) $this->get($path)->assertOk()->getContent();
    }

    public function test_the_configuration_reads_the_list_the_islands_import(): void
    {
        $themes = $this->themesOfTheIslands();

        $this->assertSame(['sprawl', 'steampunk', 'neon-noir'], array_column($themes, 'id'));
        $this->assertSame('sprawl', config('themes.default'));
        $this->assertSame($themes[0]['favicon'], config('themes.default_favicon'));
        $this->assertSame(array_column($themes, 'favicon', 'id'), config('themes.favicons'));
    }

    #[DataProvider('layouts')]
    public function test_the_layout_is_served_in_the_default_theme(string $path, bool $asAdmin): void
    {
        $this->assertStringContainsString(
            'data-theme="'.$this->themesOfTheIslands()[0]['id'].'"',
            $this->page($path, $asAdmin),
        );
    }

    #[DataProvider('layouts')]
    public function test_the_layout_restores_the_preferences_once(string $path, bool $asAdmin): void
    {
        $page = $this->page($path, $asAdmin);

        $this->assertSame(1, substr_count($page, "localStorage.getItem('theme')"));
        $this->assertSame(1, substr_count($page, "localStorage.getItem('motion')"));
    }

    #[DataProvider('layouts')]
    public function test_the_layout_accepts_exactly_the_themes_of_the_islands_with_their_favicons(string $path, bool $asAdmin): void
    {
        $favicons = array_column($this->themesOfTheIslands(), 'favicon', 'id');

        $this->assertStringContainsString(
            'const favicons = '.json_encode($favicons).';',
            $this->page($path, $asAdmin),
        );
    }

    /**
     * The layouts that name a favicon; the login page names none.
     *
     * @return array<string, array{string, bool}>
     */
    public static function layoutsWithAFavicon(): array
    {
        return array_intersect_key(self::layouts(), ['public' => true, 'administration' => true]);
    }

    #[DataProvider('layoutsWithAFavicon')]
    public function test_the_layout_shows_the_favicon_of_the_default_theme(string $path, bool $asAdmin): void
    {
        $this->assertStringContainsString(
            'id="favicon" type="image/svg+xml" href="'.$this->themesOfTheIslands()[0]['favicon'].'"',
            $this->page($path, $asAdmin),
        );
    }

    #[DataProvider('layouts')]
    public function test_the_layout_carries_the_motion_switch_once(string $path, bool $asAdmin): void
    {
        $this->assertSame(1, substr_count($this->page($path, $asAdmin), 'id="motion-toggle"'));
    }

    public function test_the_motion_choice_is_carried_onto_the_page_as_stored(): void
    {
        // No validation here: 'on' and 'off' are the only choices, for the stylesheet and
        // utils/motion.js alike, and any other value is none. Validating it in this script would
        // write the rule a third time.
        $this->assertStringContainsString(
            "if (motion !== null) document.documentElement.setAttribute('data-motion', motion);",
            $this->page('/fr/', false),
        );
    }
}
