<?php

namespace Tests\Feature\Admin;

use App\Models\HomepageContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HomepageContentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'admin', 'guard_name' => 'web']);

        $this->admin = User::factory()->create([
            'password' => Hash::make('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->admin->assignRole('admin');

        // Mark 2FA verified in the session so EnsureTwoFactorVerified passes.
        $this->withSession(['auth.two_factor_verified' => true]);
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_unauthenticated_user_is_redirected_from_edit(): void
    {
        $this->get('/fr/admin/homepage')->assertRedirect();
    }

    public function test_non_admin_user_cannot_access_edit(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)
            ->withSession(['auth.two_factor_verified' => true])
            ->get('/fr/admin/homepage')
            ->assertForbidden();
    }

    public function test_non_admin_user_cannot_update(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => now()]);

        $this->actingAs($user)
            ->withSession(['auth.two_factor_verified' => true])
            ->put('/fr/admin/homepage', $this->validPayload())
            ->assertForbidden();
    }

    // ── Edit form ─────────────────────────────────────────────────────────────

    public function test_admin_can_view_edit_form(): void
    {
        $this->actingAs($this->admin)
            ->get('/fr/admin/homepage')
            ->assertOk()
            ->assertViewIs('admin.homepage.edit');
    }

    public function test_edit_form_shows_existing_translations(): void
    {
        $content = HomepageContent::create([
            'tagline' => ['fr' => 'Accroche FR', 'en' => 'Tagline EN'],
            'subtitle' => ['fr' => 'Sous-titre FR', 'en' => 'Subtitle EN'],
            'bio' => ['fr' => 'Bio FR', 'en' => 'Bio EN'],
        ]);

        $this->actingAs($this->admin)
            ->get('/fr/admin/homepage')
            ->assertOk()
            ->assertSee('Accroche FR');
    }

    // ── Update ────────────────────────────────────────────────────────────────

    public function test_admin_can_update_homepage_content(): void
    {
        $this->actingAs($this->admin)
            ->put('/fr/admin/homepage', $this->validPayload())
            ->assertRedirect(route('admin.homepage.edit', ['lang' => 'fr']));

        $content = HomepageContent::first();
        $this->assertNotNull($content);
        $this->assertSame('Nouvelle accroche', $content->getTranslation('tagline', 'fr'));
    }

    public function test_update_persists_all_three_fields(): void
    {
        $this->actingAs($this->admin)->put('/fr/admin/homepage', $this->validPayload());

        $content = HomepageContent::first();
        $this->assertSame('Nouveau sous-titre', $content->getTranslation('subtitle', 'fr'));
        $this->assertSame('Nouvelle bio.', $content->getTranslation('bio', 'fr'));
    }

    public function test_update_overwrites_existing_record(): void
    {
        HomepageContent::create([
            'tagline' => ['fr' => 'Ancien', 'en' => 'Old'],
            'subtitle' => ['fr' => 'Ancien', 'en' => 'Old'],
            'bio' => ['fr' => 'Ancienne bio.', 'en' => 'Old bio.'],
        ]);

        $this->actingAs($this->admin)->put('/fr/admin/homepage', $this->validPayload());

        $this->assertSame(1, HomepageContent::count());
        $this->assertSame('Nouvelle accroche', HomepageContent::first()->getTranslation('tagline', 'fr'));
    }

    public function test_update_does_not_erase_other_locale_translations(): void
    {
        HomepageContent::create([
            'tagline' => ['fr' => 'Ancien', 'en' => 'Old EN', 'de' => 'Alt DE'],
            'subtitle' => ['fr' => 'Ancien', 'en' => 'Old EN'],
            'bio' => ['fr' => 'Ancienne bio.', 'en' => 'Old EN bio.'],
        ]);

        $this->actingAs($this->admin)->put('/fr/admin/homepage', $this->validPayload());

        $content = HomepageContent::first();
        $this->assertSame('Old EN', $content->getTranslation('tagline', 'en'));
        $this->assertSame('Alt DE', $content->getTranslation('tagline', 'de'));
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_tagline_fr_is_required(): void
    {
        $payload = $this->validPayload();
        $payload['tagline']['fr'] = '';

        $this->actingAs($this->admin)
            ->put('/fr/admin/homepage', $payload)
            ->assertSessionHasErrors('tagline.fr');
    }

    public function test_bio_fr_must_not_exceed_1000_characters(): void
    {
        $payload = $this->validPayload();
        $payload['bio']['fr'] = str_repeat('a', 1001);

        $this->actingAs($this->admin)
            ->put('/fr/admin/homepage', $payload)
            ->assertSessionHasErrors('bio.fr');
    }

    // ── Public homepage ───────────────────────────────────────────────────────

    public function test_homepage_displays_content_from_database(): void
    {
        HomepageContent::create([
            'tagline' => ['fr' => 'Accroche depuis la BDD', 'en' => 'DB tagline'],
            'subtitle' => ['fr' => 'Sous-titre BDD', 'en' => 'DB subtitle'],
            'bio' => ['fr' => 'Bio BDD.', 'en' => 'DB bio.'],
        ]);

        $this->get('/fr/')->assertSee('Accroche depuis la BDD');
    }

    public function test_homepage_renders_without_content_record(): void
    {
        // No seeded content — the view must fall back gracefully.
        $this->get('/fr/')->assertOk();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validPayload(): array
    {
        return [
            'tagline' => ['fr' => 'Nouvelle accroche'],
            'subtitle' => ['fr' => 'Nouveau sous-titre'],
            'bio' => ['fr' => 'Nouvelle bio.'],
        ];
    }
}
